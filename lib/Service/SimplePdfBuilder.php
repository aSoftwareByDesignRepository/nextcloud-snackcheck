<?php

declare(strict_types=1);

namespace OCA\SnackCheck\Service;

/**
 * Minimal PDF 1.4 builder (no external deps) for My-month export.
 * Latin-1 Helvetica — EUR amounts formatted as ASCII-safe text.
 */
final class SimplePdfBuilder
{
	/**
	 * @param list<string> $lines
	 */
	public static function fromLines(string $title, array $lines): string
	{
		$content = "BT /F1 16 Tf 50 780 Td (" . self::esc($title) . ") Tj ET\n";
		$y = 750;
		$content .= "BT /F1 11 Tf 50 $y Td\n";
		foreach ($lines as $line) {
			$content .= '(' . self::esc($line) . ") Tj\n0 -16 Td\n";
			$y -= 16;
			if ($y < 60) {
				break;
			}
		}
		$content .= "ET\n";

		$objects = [];
		$objects[] = "<< /Type /Catalog /Pages 2 0 R >>";
		$objects[] = "<< /Type /Pages /Kids [3 0 R] /Count 1 >>";
		$objects[] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >>";
		$objects[] = "<< /Length " . strlen($content) . " >>\nstream\n" . $content . "endstream";
		$objects[] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>";

		$pdf = "%PDF-1.4\n";
		$offsets = [0];
		for ($i = 0; $i < count($objects); $i++) {
			$offsets[] = strlen($pdf);
			$pdf .= ($i + 1) . " 0 obj\n" . $objects[$i] . "\nendobj\n";
		}
		$xref = strlen($pdf);
		$pdf .= "xref\n0 " . (count($objects) + 1) . "\n";
		$pdf .= "0000000000 65535 f \n";
		for ($i = 1; $i <= count($objects); $i++) {
			$pdf .= sprintf("%010d 00000 n \n", $offsets[$i]);
		}
		$pdf .= "trailer\n<< /Size " . (count($objects) + 1) . " /Root 1 0 R >>\n";
		$pdf .= "startxref\n$xref\n%%EOF\n";
		return $pdf;
	}

	private static function esc(string $s): string
	{
		$s = iconv('UTF-8', 'ISO-8859-1//TRANSLIT//IGNORE', $s) ?: $s;
		return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $s);
	}
}
