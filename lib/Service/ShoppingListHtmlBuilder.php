<?php

declare(strict_types=1);

namespace OCA\SnackCheck\Service;

/**
 * Print-friendly shopping list HTML (AC-OPP-D4).
 * Standalone document (no NC shell) — uses system colour scheme + forced print B/W.
 */
final class ShoppingListHtmlBuilder
{
	/**
	 * @param list<array<string,mixed>> $rows
	 */
	public static function build(array $rows, string $title = 'Shopping list'): string
	{
		$titleEsc = self::esc($title);
		$buf = '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"/>';
		$buf .= '<meta name="viewport" content="width=device-width, initial-scale=1"/>';
		$buf .= '<meta name="color-scheme" content="light dark"/>';
		$buf .= '<title>' . $titleEsc . '</title>';
		$buf .= '<style>';
		$buf .= ':root{color-scheme:light dark;';
		$buf .= '--snk-print-fg:CanvasText;--snk-print-bg:Canvas;--snk-print-muted:GrayText;--snk-print-border:GrayText;}';
		$buf .= 'body{font-family:system-ui,-apple-system,Segoe UI,sans-serif;margin:1.5rem;';
		$buf .= 'color:var(--snk-print-fg);background:var(--snk-print-bg);line-height:1.45;}';
		$buf .= 'h1{font-size:1.75rem;margin:0 0 1rem;}';
		$buf .= 'table{width:100%;border-collapse:collapse;font-size:1.05rem;}';
		$buf .= 'th,td{border:1px solid var(--snk-print-border);padding:0.65rem 0.75rem;text-align:left;}';
		$buf .= 'th{font-weight:700;}';
		$buf .= 'caption{caption-side:bottom;text-align:left;color:var(--snk-print-muted);margin-top:0.75rem;font-size:0.95rem;}';
		$buf .= '.empty{font-size:1.15rem;color:var(--snk-print-muted);}';
		$buf .= 'button{min-height:44px;padding:0.5rem 1rem;font:inherit;cursor:pointer;}';
		$buf .= '@media print{body{margin:0.5rem;color:#000;background:#fff;} button{display:none;}';
		$buf .= 'th,td,caption{color:#000;border-color:#000;}}';
		$buf .= '</style></head><body>';
		$buf .= '<h1>' . $titleEsc . '</h1>';
		$buf .= '<p><button type="button" onclick="window.print()">Print</button></p>';
		if ($rows === []) {
			$buf .= '<p class="empty">Nothing to buy right now.</p></body></html>';
			return $buf;
		}
		$buf .= '<table><thead><tr>';
		$buf .= '<th scope="col">Item</th><th scope="col">Category</th>';
		$buf .= '<th scope="col">On hand</th><th scope="col">Par</th>';
		$buf .= '<th scope="col">Buy</th><th scope="col">Free</th>';
		$buf .= '</tr></thead><tbody>';
		foreach ($rows as $row) {
			$buf .= '<tr>';
			$buf .= '<td>' . self::esc((string)($row['name'] ?? '')) . '</td>';
			$buf .= '<td>' . self::esc((string)($row['category'] ?? '')) . '</td>';
			$buf .= '<td>' . self::esc((string)($row['onHand'] ?? '—')) . '</td>';
			$buf .= '<td>' . self::esc((string)($row['parLevel'] ?? '—')) . '</td>';
			$buf .= '<td>' . self::esc((string)($row['suggestedBuy'] ?? '0')) . '</td>';
			$buf .= '<td>' . (!empty($row['complimentary']) ? 'yes' : 'no') . '</td>';
			$buf .= '</tr>';
		}
		$buf .= '</tbody><caption>SnackCheck shopping list — print or save as PDF</caption></table>';
		$buf .= '</body></html>';
		return $buf;
	}

	private static function esc(string $value): string
	{
		return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
	}
}
