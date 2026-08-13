<?php

declare(strict_types=1);

namespace OCA\SnackCheck\Service;

/**
 * Minimal OOXML XLSX writer (no external dependency).
 */
final class XlsxWriter
{
	/**
	 * @param array<string, array{cols: list<string>, rows: list<array<string,mixed>>}> $sheets
	 */
	public static function fromSheets(array $sheets): string
	{
		$tmp = tempnam(sys_get_temp_dir(), 'snkxlsx');
		if ($tmp === false) {
			throw new \RuntimeException('tempnam failed');
		}
		$zipPath = $tmp . '.xlsx';
		@unlink($tmp);
		$zip = new \ZipArchive();
		if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
			throw new \RuntimeException('zip open failed');
		}

		$sheetNames = array_keys($sheets);
		$zip->addFromString('[Content_Types].xml', self::contentTypes($sheetNames));
		$zip->addFromString('_rels/.rels', self::rootRels());
		$zip->addFromString('xl/workbook.xml', self::workbook($sheetNames));
		$zip->addFromString('xl/_rels/workbook.xml.rels', self::workbookRels($sheetNames));
		$zip->addFromString('xl/styles.xml', self::styles());

		$i = 1;
		foreach ($sheets as $name => $sheet) {
			$zip->addFromString('xl/worksheets/sheet' . $i . '.xml', self::sheetXml($sheet['cols'], $sheet['rows']));
			$i++;
		}
		$zip->close();
		$data = file_get_contents($zipPath);
		@unlink($zipPath);
		if ($data === false) {
			throw new \RuntimeException('read xlsx failed');
		}
		return $data;
	}

	/** @param list<string> $names */
	private static function contentTypes(array $names): string
	{
		$overrides = '';
		for ($i = 1; $i <= count($names); $i++) {
			$overrides .= '<Override PartName="/xl/worksheets/sheet' . $i . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
		}
		return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
			. '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
			. '<Default Extension="xml" ContentType="application/xml"/>'
			. '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
			. '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
			. $overrides
			. '</Types>';
	}

	private static function rootRels(): string
	{
		return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
			. '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
			. '</Relationships>';
	}

	/** @param list<string> $names */
	private static function workbook(array $names): string
	{
		$sheets = '';
		$i = 1;
		foreach ($names as $name) {
			$safe = htmlspecialchars($name, ENT_XML1);
			$sheets .= '<sheet name="' . $safe . '" sheetId="' . $i . '" r:id="rId' . $i . '"/>';
			$i++;
		}
		return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
			. 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
			. '<sheets>' . $sheets . '</sheets></workbook>';
	}

	/** @param list<string> $names */
	private static function workbookRels(array $names): string
	{
		$rels = '';
		for ($i = 1; $i <= count($names); $i++) {
			$rels .= '<Relationship Id="rId' . $i . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $i . '.xml"/>';
		}
		$rels .= '<Relationship Id="rIdStyles" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>';
		return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
			. $rels . '</Relationships>';
	}

	private static function styles(): string
	{
		return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
			. '<fonts count="1"><font><sz val="11"/><name val="Calibri"/></font></fonts>'
			. '<fills count="1"><fill><patternFill patternType="none"/></fill></fills>'
			. '<borders count="1"><border/></borders>'
			. '<cellStyleXfs count="1"><xf/></cellStyleXfs>'
			. '<cellXfs count="1"><xf/></cellXfs>'
			. '</styleSheet>';
	}

	/**
	 * @param list<string> $cols
	 * @param list<array<string,mixed>> $rows
	 */
	private static function sheetXml(array $cols, array $rows): string
	{
		$xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';
		$r = 1;
		$xml .= '<row r="' . $r . '">';
		$c = 0;
		foreach ($cols as $col) {
			$xml .= self::inlineCell(self::colName($c) . $r, $col);
			$c++;
		}
		$xml .= '</row>';
		$r++;
		foreach ($rows as $row) {
			$xml .= '<row r="' . $r . '">';
			$c = 0;
			foreach ($cols as $col) {
				$val = $row[$col] ?? '';
				$xml .= self::inlineCell(self::colName($c) . $r, is_scalar($val) ? (string)$val : '');
				$c++;
			}
			$xml .= '</row>';
			$r++;
		}
		$xml .= '</sheetData></worksheet>';
		return $xml;
	}

	private static function inlineCell(string $ref, string $value): string
	{
		// AC-19: neutralize formula-leading cells the same way as CSV (Excel/LibreOffice).
		if ($value !== '' && in_array($value[0], ['=', '+', '-', '@'], true)) {
			$value = "'" . $value;
		}
		$esc = htmlspecialchars($value, ENT_XML1);
		return '<c r="' . $ref . '" t="inlineStr"><is><t>' . $esc . '</t></is></c>';
	}

	private static function colName(int $index): string
	{
		$index++;
		$name = '';
		while ($index > 0) {
			$index--;
			$name = chr(65 + ($index % 26)) . $name;
			$index = intdiv($index, 26);
		}
		return $name;
	}
}
