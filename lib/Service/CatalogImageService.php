<?php

declare(strict_types=1);

namespace OCA\SnackCheck\Service;

use OCA\SnackCheck\Db\CatalogItem;
use OCA\SnackCheck\Db\CatalogItemMapper;
use OCA\SnackCheck\Exception\DomainException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\IAppData;
use OCP\Files\NotFoundException as FilesNotFoundException;
use OCP\Files\SimpleFS\ISimpleFolder;
use OCP\IDBConnection;

/**
 * One optional picture per catalog item via AppData.
 * ≤ 2 MB; JPEG / PNG / WebP only (re-encoded; SVG rejected).
 */
class CatalogImageService
{
	public const MAX_BYTES = 2_097_152;
	public const FOLDER = 'catalog_images';

	/** @var array<string, string> */
	public const ALLOWED = [
		'image/jpeg' => 'jpg',
		'image/png' => 'png',
		'image/webp' => 'webp',
	];

	public function __construct(
		private readonly IDBConnection $db,
		private readonly IAppData $appData,
		private readonly CatalogItemMapper $mapper,
		private readonly AuditService $audit,
		private readonly ITimeFactory $timeFactory,
	) {
	}

	/**
	 * @param array{name?:string,type?:string,tmp_name?:string,error?:int,size?:int} $file PHP upload array
	 */
	public function upload(int $itemId, array $file, string $actorUid): CatalogItem
	{
		if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK
			|| !is_string($file['tmp_name'] ?? null)
			|| !is_uploaded_file($file['tmp_name'])) {
			throw new DomainException('upload_failed', 'Upload failed', 422);
		}
		$size = (int)($file['size'] ?? 0);
		if ($size <= 0 || $size > self::MAX_BYTES) {
			throw new DomainException('photo_too_large', 'Photo too large (max 2 MB)', 422);
		}
		$raw = file_get_contents($file['tmp_name']);
		if ($raw === false || $raw === '') {
			throw new DomainException('upload_failed', 'Upload failed', 422);
		}
		$info = @getimagesizefromstring($raw);
		if ($info === false || !isset(self::ALLOWED[(string)($info['mime'] ?? '')])) {
			throw new DomainException('photo_type_invalid', 'Only JPEG, PNG, or WebP photos are allowed', 422);
		}
		$mime = (string)$info['mime'];
		$w = (int)$info[0];
		$h = (int)$info[1];
		if ($w < 16 || $h < 16 || $w > 8000 || $h > 8000) {
			throw new DomainException('photo_type_invalid', 'Only JPEG, PNG, or WebP photos are allowed', 422);
		}
		$clean = $this->reencode($raw, $mime);
		$ext = self::ALLOWED[$mime];
		$fileName = sprintf('item-%d-%s.%s', $itemId, bin2hex(random_bytes(8)), $ext);
		$folder = $this->folder();

		$this->db->beginTransaction();
		try {
			$item = $this->mapper->lockRow($itemId);
			if ($item === null) {
				throw new DomainException('not_found', 'Item not found', 404);
			}
			$old = $item->getImageName();
			$folder->newFile($fileName)->putContent($clean);
			$item->setImageName($fileName);
			$item->setImageMime($mime);
			$item->setUpdatedAt($this->timeFactory->getDateTime());
			$item = $this->mapper->update($item);
			$this->db->commit();
			if (is_string($old) && $old !== '') {
				$this->tryDeleteFile($old);
			}
			$this->audit->record($actorUid, 'catalog.image', 'catalog_item', (string)$itemId);
			return $item;
		} catch (\Throwable $e) {
			if ($this->db->inTransaction()) {
				$this->db->rollBack();
			}
			$this->tryDeleteFile($fileName);
			throw $e;
		}
	}

	public function delete(int $itemId, string $actorUid): CatalogItem
	{
		$this->db->beginTransaction();
		try {
			$item = $this->mapper->lockRow($itemId);
			if ($item === null) {
				throw new DomainException('not_found', 'Item not found', 404);
			}
			$old = $item->getImageName();
			$item->setImageName(null);
			$item->setImageMime(null);
			$item->setUpdatedAt($this->timeFactory->getDateTime());
			$item = $this->mapper->update($item);
			$this->db->commit();
			if (is_string($old) && $old !== '') {
				$this->tryDeleteFile($old);
			}
			$this->audit->record($actorUid, 'catalog.image_clear', 'catalog_item', (string)$itemId);
			return $item;
		} catch (\Throwable $e) {
			if ($this->db->inTransaction()) {
				$this->db->rollBack();
			}
			throw $e;
		}
	}

	/** @return array{content: string, mime: string, name: string} */
	public function read(int $itemId): array
	{
		$item = $this->mapper->find($itemId);
		if ($item === null) {
			throw new DomainException('not_found', 'Item not found', 404);
		}
		$name = $item->getImageName();
		$mime = $item->getImageMime();
		if (!is_string($name) || $name === '' || !is_string($mime) || $mime === '') {
			throw new DomainException('photo_not_found', 'No photo for this item', 404);
		}
		if (!preg_match('/^item-\d+-[a-f0-9]{16}\.(jpg|png|webp)$/', $name)) {
			throw new DomainException('photo_not_found', 'No photo for this item', 404);
		}
		try {
			$content = $this->folder()->getFile($name)->getContent();
		} catch (FilesNotFoundException) {
			throw new DomainException('photo_not_found', 'No photo for this item', 404);
		}
		return ['content' => $content, 'mime' => $mime, 'name' => $name];
	}

	public static function hasImage(CatalogItem $item): bool
	{
		$name = $item->getImageName();
		return is_string($name) && $name !== '';
	}

	private function folder(): ISimpleFolder
	{
		try {
			return $this->appData->getFolder(self::FOLDER);
		} catch (FilesNotFoundException) {
			return $this->appData->newFolder(self::FOLDER);
		}
	}

	private function tryDeleteFile(string $fileName): void
	{
		try {
			$this->folder()->getFile($fileName)->delete();
		} catch (\Throwable) {
		}
	}

	private function reencode(string $raw, string $mime): string
	{
		$img = @imagecreatefromstring($raw);
		if ($img === false) {
			throw new DomainException('photo_type_invalid', 'Only JPEG, PNG, or WebP photos are allowed', 422);
		}
		ob_start();
		$ok = match ($mime) {
			'image/jpeg' => imagejpeg($img, null, 85),
			'image/png' => imagepng($img, null, 6),
			'image/webp' => function_exists('imagewebp') ? imagewebp($img, null, 85) : false,
			default => false,
		};
		imagedestroy($img);
		$out = ob_get_clean();
		if ($ok === false || $out === false || $out === '') {
			throw new DomainException('photo_type_invalid', 'Only JPEG, PNG, or WebP photos are allowed', 422);
		}
		if (strlen($out) > self::MAX_BYTES) {
			throw new DomainException('photo_too_large', 'Photo too large (max 2 MB)', 422);
		}
		return $out;
	}
}
