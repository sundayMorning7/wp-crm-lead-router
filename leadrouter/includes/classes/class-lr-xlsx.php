<?php
/**
 * LR_Xlsx — мінімальний генератор .xlsx без сторонніх бібліотек (ZipArchive).
 *
 * Один аркуш: рядок заголовків + дані. PHP-числа (int/float) пишуться як
 * числові комірки, все інше — inline-рядки (телефони/зіпи не втрачають
 * ведучі нулі). Якщо ZipArchive недоступний — фолбек: CSV з BOM, який
 * Excel відкриває подвійним кліком.
 */

defined('ABSPATH') || exit;

class LR_Xlsx
{
    /** Віддати файл у браузер і завершити виконання. */
    public static function stream(string $basename, array $headers, array $rows): void
    {
        $basename = preg_replace('/[^\w.-]+/u', '_', $basename) ?: 'report';

        nocache_headers();

        if (class_exists('ZipArchive')) {
            $file = self::build_xlsx($headers, $rows);
            if ($file !== null) {
                header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
                header('Content-Disposition: attachment; filename="' . $basename . '.xlsx"');
                header('Content-Length: ' . (string) filesize($file));
                readfile($file);
                @unlink($file);
                exit;
            }
        }

        // Фолбек: CSV з BOM (кирилиця в Excel читається коректно)
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $basename . '.csv"');
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, $headers);
        foreach ($rows as $r) {
            fputcsv($out, array_values((array) $r));
        }
        fclose($out);
        exit;
    }

    /** Зібрати тимчасовий .xlsx; null — якщо не вдалося. */
    private static function build_xlsx(array $headers, array $rows): ?string
    {
        $tmp = tempnam(get_temp_dir(), 'lrx');
        if (!$tmp) {
            return null;
        }

        $zip = new ZipArchive();
        if ($zip->open($tmp, ZipArchive::OVERWRITE) !== true) {
            @unlink($tmp);
            return null;
        }

        $zip->addFromString(
            '[Content_Types].xml',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . '</Types>'
        );
        $zip->addFromString(
            '_rels/.rels',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>'
        );
        $zip->addFromString(
            'xl/workbook.xml',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets><sheet name="Report" sheetId="1" r:id="rId1"/></sheets>'
            . '</workbook>'
        );
        $zip->addFromString(
            'xl/_rels/workbook.xml.rels',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            . '</Relationships>'
        );
        $zip->addFromString('xl/worksheets/sheet1.xml', self::sheet_xml($headers, $rows));

        if (!$zip->close()) {
            @unlink($tmp);
            return null;
        }

        return $tmp;
    }

    private static function sheet_xml(array $headers, array $rows): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';

        $all = array_merge([$headers], $rows);
        $rix = 0;
        foreach ($all as $row) {
            $rix++;
            $xml .= '<row r="' . $rix . '">';
            foreach (array_values((array) $row) as $v) {
                // Числами вважаємо лише справжні PHP int/float — рядки типу
                // "05401" (zip) чи телефони лишаються текстом як є
                if (is_int($v) || is_float($v)) {
                    $xml .= '<c t="n"><v>' . $v . '</v></c>';
                } else {
                    $xml .= '<c t="inlineStr"><is><t xml:space="preserve">'
                        . htmlspecialchars((string) $v, ENT_XML1 | ENT_QUOTES, 'UTF-8')
                        . '</t></is></c>';
                }
            }
            $xml .= '</row>';
        }

        return $xml . '</sheetData></worksheet>';
    }
}
