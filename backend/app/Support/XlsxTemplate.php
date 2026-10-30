<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Tiny .xlsx writer for upload templates: one data sheet plus a hidden "Lists" sheet whose columns feed dropdowns
 * (Excel data validation) on chosen columns. Plain OOXML in a zip: no spreadsheet library needed.
 */
final class XlsxTemplate
{
    /**
     * @param list<list<string>> $rows       first row = header
     * @param list<list<string>> $lists      one list per Lists-sheet column (A, B, C…)
     * @param array<string,int>  $dropdowns  data-sheet column letter => index into $lists
     * @return string path of a temp .xlsx file
     */
    public static function build(array $rows, array $lists, array $dropdowns, int $maxRow = 500): string
    {
        $col = fn (int $i) => chr(65 + $i);
        $cell = fn (string $ref, string $v) => '<c r="' . $ref . '" t="inlineStr"><is><t>' . htmlspecialchars($v, ENT_XML1) . '</t></is></c>';
        $sheet = function (array $rows, string $extra = '') use ($col, $cell) {
            $xml = '';
            foreach ($rows as $r => $row) {
                $xml .= '<row r="' . ($r + 1) . '">' . implode('', array_map(fn ($v, $c) => $cell($col($c) . ($r + 1), (string) $v), $row, array_keys($row))) . '</row>';
            }
            return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
                . '<sheetData>' . $xml . '</sheetData>' . $extra . '</worksheet>';
        };
        $listRows = [];
        foreach ($lists as $c => $values) {
            foreach (array_values($values) as $r => $v) {
                $listRows[$r][$c] = $v;
            }
        }
        $listRows = array_map(function ($row) use ($lists) {   // fill gaps so every row has every column
            $full = array_fill(0, count($lists), '');
            foreach ($row as $c => $v) $full[$c] = $v;
            return $full;
        }, $listRows);
        $dv = '';
        foreach ($dropdowns as $letter => $li) {
            $n = max(1, count($lists[$li]));
            $dv .= '<dataValidation type="list" allowBlank="1" showErrorMessage="1" errorStyle="warning" error="Not in the list — a new name will be created." sqref="'
                . $letter . '2:' . $letter . $maxRow . '"><formula1>Lists!$' . $col($li) . '$1:$' . $col($li) . '$' . $n . '</formula1></dataValidation>';
        }
        $dv = $dv ? '<dataValidations count="' . count($dropdowns) . '">' . $dv . '</dataValidations>' : '';

        $path = tempnam(sys_get_temp_dir(), 'tpl') . '.xlsx';
        $z = new \ZipArchive();
        $z->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $z->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . '<Override PartName="/xl/worksheets/sheet2.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/></Types>');
        $z->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
        $z->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets><sheet name="People" sheetId="1" r:id="rId1"/><sheet name="Lists" sheetId="2" state="hidden" r:id="rId2"/></sheets></workbook>');
        $z->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet2.xml"/>'
            . '<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>');
        $z->addFromString('xl/styles.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<fonts count="1"><font><sz val="11"/><name val="Calibri"/></font></fonts><fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills>'
            . '<borders count="1"><border/></borders><cellStyleXfs count="1"><xf/></cellStyleXfs><cellXfs count="1"><xf xfId="0"/></cellXfs></styleSheet>');
        $z->addFromString('xl/worksheets/sheet1.xml', $sheet($rows, $dv));
        $z->addFromString('xl/worksheets/sheet2.xml', $sheet($listRows));
        $z->close();
        return $path;
    }
}
