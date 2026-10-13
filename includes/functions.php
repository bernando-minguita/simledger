<?php
/**
 * SIMLedger - shared helper functions.
 */

declare(strict_types=1);

/**
 * Escape a value for safe HTML output.
 */
function e($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

/**
 * Cache-busting URL for a static asset.
 *
 * Appends ?v=<mtime> so a deployed JS/CSS change is fetched immediately.
 * Apache sends Last-Modified/ETag for static files, and browsers then treat
 * them as fresh for a heuristic period - without this a fixed
 * table-toolkit.js can keep running from cache long after deployment, which
 * looks exactly like "the fix did not work".
 */
function asset(string $rel): string
{
    $rel = ltrim($rel, '/');
    $abs = dirname(__DIR__) . '/' . $rel;

    return $rel . '?v=' . (is_file($abs) ? (string)filemtime($abs) : '1');
}

/**
 * Turn a caught Throwable into a safe, user-facing message (M1: never leak
 * SQL / schema details to the browser).
 *
 * - RuntimeException messages in this application are written for end users
 *   ("This driver is not eligible for assignment...") and pass through as-is.
 *   (PDOException extends RuntimeException, so it is checked FIRST.)
 * - PDOException: logged server-side; unique-key violations (SQLSTATE 23000)
 *   map to the page's $duplicateMessage, everything else to a generic text.
 * - Any other unexpected error: logged server-side + generic text.
 */
function friendlyError(\Throwable $e, string $duplicateMessage = 'A record with the same unique value already exists.'): string
{
    if ($e instanceof PDOException) {
        // Log the real reason server-side only - never print it.
        error_log('[simledger] database error: ' . $e->getMessage());

        if ((string)$e->getCode() === '23000') {
            return $duplicateMessage;
        }

        return 'The operation could not be completed because of a database error. '
            . 'The technical details were written to the PHP error log. '
            . 'Please try again or contact your administrator.';
    }

    if ($e instanceof RuntimeException) {
        return $e->getMessage();
    }

    error_log('[simledger] ' . get_class($e) . ': ' . $e->getMessage()
        . ' @ ' . $e->getFile() . ':' . $e->getLine());

    return 'The operation could not be completed. The technical details were '
        . 'written to the PHP error log. Please try again or contact your administrator.';
}

/**
 * One-shot session flash message.
 */
function setFlash(string $type, string $message): void
{
    $_SESSION['flash'] = ['type' => $type, 'msg' => $message];
}

/**
 * Read and clear the flash message.
 */
function takeFlash(): ?array
{
    if (empty($_SESSION['flash'])) {
        return null;
    }

    $flash = $_SESSION['flash'];
    unset($_SESSION['flash']);

    return $flash;
}

/**
 * Trim a string, optionally truncate to $max characters; blank -> null.
 */
function cleanStr($value, ?int $max = null): ?string
{
    if ($value === null) {
        return null;
    }

    $s = trim((string)$value);

    if ($s === '') {
        return null;
    }

    if ($max !== null && $max > 0 && function_exists('mb_strlen') && mb_strlen($s, 'UTF-8') > $max) {
        $s = mb_substr($s, 0, $max, 'UTF-8');
    }

    return $s;
}

// ---------------------------------------------------------------------------
// Phone number handling (Saudi formats)
// ---------------------------------------------------------------------------
/**
 * Normalize a phone number to the internal E.164 form +9665XXXXXXXX.
 *
 * Accepts 05XXXXXXXX, +9665XXXXXXXX, 9665XXXXXXXX, 5XXXXXXXX and the same
 * with spaces/dashes/dots/parentheses. Returns null when the value cannot be
 * interpreted as a Saudi mobile number.
 */
function normalizePhone(?string $raw): ?string
{
    if ($raw === null) {
        return null;
    }

    $digits = preg_replace('/\D+/', '', $raw);

    if ($digits === null || $digits === '') {
        return null;
    }

    if (strpos($digits, '05') === 0 && strlen($digits) === 10) {
        $digits = substr($digits, 1);            // 05XXXXXXXX -> 5XXXXXXXX
    } elseif (strpos($digits, '9665') === 0 && strlen($digits) === 12) {
        $digits = substr($digits, 3);            // 9665XXXXXXXX -> 5XXXXXXXX
    }

    // Expected: 5 + 8 digits = 9 digits total.
    if (strlen($digits) !== 9 || $digits[0] !== '5') {
        return null;
    }

    return '+966' . $digits;
}

/**
 * Display form of a normalized number: 05XXXXXXXX.
 */
function displayPhone(?string $normalized): string
{
    if ($normalized === null || $normalized === '') {
        return '';
    }

    if (strpos($normalized, '+966') === 0) {
        return '0' . substr($normalized, 4);
    }

    return $normalized;
}

/**
 * Mask a phone number for display: 055****567.
 */
function maskPhone(?string $normalized): string
{
    $national = displayPhone($normalized);

    if (strlen($national) < 7) {
        return $national === '' ? '' : str_repeat('*', strlen($national));
    }

    return substr($national, 0, 3)
        . str_repeat('*', strlen($national) - 6)
        . substr($national, -3);
}

/**
 * Full number for authorized users (admin, or dispatcher on their own
 * terminal), masked otherwise.
 */
function phoneFor(?string $normalized, ?int $terminalId = null): string
{
    if ($normalized === null || $normalized === '') {
        return '';
    }

    if (function_exists('canViewFullNumbers') && canViewFullNumbers($terminalId)) {
        return displayPhone($normalized);
    }

    return maskPhone($normalized);
}

// ---------------------------------------------------------------------------
// Settings (key/value table, cached per request)
// ---------------------------------------------------------------------------
function settingGet(string $key, string $default = ''): string
{
    if (!isset($GLOBALS['__settings_cache'])) {
        $GLOBALS['__settings_cache'] = [];

        try {
            $stmt = $GLOBALS['pdo']->query('SELECT `key`, `value` FROM app_settings');
            foreach ($stmt->fetchAll() as $row) {
                $GLOBALS['__settings_cache'][(string)$row['key']] = (string)$row['value'];
            }
        } catch (Throwable $e) {
            // Settings table missing (pre-install) - fall back to defaults.
        }
    }

    return $GLOBALS['__settings_cache'][$key] ?? $default;
}

function settingBool(string $key, bool $default = false): bool
{
    $v = strtolower(settingGet($key, $default ? '1' : '0'));

    return in_array($v, ['1', 'true', 'yes', 'on'], true);
}

// ---------------------------------------------------------------------------
// CSV export
// ---------------------------------------------------------------------------
/**
 * Stream a formatted .xlsx workbook: large bold title, generated-at subtitle,
 * bold bordered header row, bordered data cells, frozen header and sized
 * columns. Built with ZipArchive (ext-zip) - no external libraries. Falls
 * back to plain CSV when the zip extension is unavailable.
 *
 * @param string[] $headers
 * @param iterable<int, array<int, mixed>> $rows
 */
function exportExcel(string $filename, string $title, array $headers, iterable $rows): void
{
    if (headers_sent()) {
        return;
    }

    $cells = [];
    foreach ($rows as $row) {
        $cells[] = array_map(static function ($v): string {
            if ($v === null) {
                return '';
            }
            if (is_bool($v)) {
                return $v ? '1' : '0';
            }

            return (string)$v;
        }, array_values($row));
    }

    if (!class_exists('ZipArchive')) {
        // Emergency fallback so exports never hard-fail without ext-zip.
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . basename($filename) . '.csv"');
        $out = fopen('php://output', 'w');
        fputcsv($out, $headers);
        foreach ($cells as $row) {
            fputcsv($out, $row);
        }
        fclose($out);
        exit;
    }

    $esc = static function (string $v): string {
        $v = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $v) ?? '';

        return htmlspecialchars($v, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    };
    $colLetter = static function (int $i): string {
        $s = '';
        $i++; // 1-based column number
        while ($i > 0) {
            $m   = ($i - 1) % 26;
            $s   = chr(65 + $m) . $s;
            $i   = intdiv($i - 1, 26);
        }

        return $s;
    };
    // Numbers only when safe (no leading zeros / <=15 digits) so phone
    // numbers and ICCIDs keep their exact text form in Excel.
    $isNum = static fn (string $v): bool => preg_match('/^-?(0|[1-9]\d{0,14})(\.\d+)?$/', $v) === 1;

    $lastCol = $colLetter(count($headers) - 1);

    // --- worksheet XML ------------------------------------------------------
    $sheet = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<sheetViews><sheetView workbookViewId="0">'
        . '<pane ySplit="3" topLeftCell="A4" activePane="bottomLeft" state="frozen"/>'
        . '</sheetView></sheetViews>'
        . '<sheetFormatPr defaultRowHeight="15"/><cols>';
    foreach ($headers as $i => $h) {
        $len = strlen((string)$h);
        foreach ($cells as $row) {
            $len = max($len, isset($row[$i]) ? strlen((string)$row[$i]) : 0);
        }
        $sheet .= '<col min="' . ($i + 1) . '" max="' . ($i + 1) . '" width="'
            . max(10, min(48, $len + 2)) . '" customWidth="1"/>';
    }
    $sheet .= '</cols><sheetData>';

    // Row 1: large bold title (style 1), merged across the table.
    $sheet .= '<row r="1" ht="24" customHeight="1">'
        . '<c r="A1" s="1" t="inlineStr"><is><t>' . $esc($title) . '</t></is></c></row>';
    // Row 2: generated-at subtitle (style 2).
    $sheet .= '<row r="2">'
        . '<c r="A2" s="2" t="inlineStr"><is><t>'
        . $esc('Generated ' . date('Y-m-d H:i') . ' · ' . count($cells) . ' rows')
        . '</t></is></c></row>';
    // Row 3: bold bordered headers (style 3).
    $sheet .= '<row r="3">';
    foreach ($headers as $i => $h) {
        $sheet .= '<c r="' . $colLetter($i) . '3" s="3" t="inlineStr"><is><t>'
            . $esc((string)$h) . '</t></is></c>';
    }
    $sheet .= '</row>';
    // Data rows (style 4 = bordered).
    $r = 4;
    foreach ($cells as $row) {
        $sheet .= '<row r="' . $r . '">';
        foreach ($headers as $i => $_) {
            $ref = $colLetter($i) . $r;
            $v   = $row[$i] ?? '';
            if ($v !== '' && $isNum($v)) {
                $sheet .= '<c r="' . $ref . '" s="4"><v>' . $v . '</v></c>';
            } else {
                $sheet .= '<c r="' . $ref . '" s="4" t="inlineStr"><is><t>'
                    . $esc($v) . '</t></is></c>';
            }
        }
        $sheet .= '</row>';
        $r++;
    }
    $sheet .= '</sheetData><mergeCells count="2">'
        . '<mergeCell ref="A1:' . $lastCol . '1"/><mergeCell ref="A2:' . $lastCol . '2"/>'
        . '</mergeCells></worksheet>';

    // --- styles: 0 normal / 1 title (bold 16) / 2 subtitle / 3 header / 4 data
    $styles = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<fonts count="4">'
        . '<font><sz val="11"/><name val="Calibri"/></font>'
        . '<font><b/><sz val="11"/><name val="Calibri"/></font>'
        . '<font><b/><sz val="16"/><color rgb="FF1F3864"/><name val="Calibri"/></font>'
        . '<font><i/><sz val="10"/><color rgb="FF666666"/><name val="Calibri"/></font>'
        . '</fonts>'
        . '<fills count="3">'
        . '<fill><patternFill patternType="none"/></fill>'
        . '<fill><patternFill patternType="gray125"/></fill>'
        . '<fill><patternFill patternType="solid"><fgColor rgb="FFDCE6F1"/>'
        . '<bgColor indexed="64"/></patternFill></fill>'
        . '</fills>'
        . '<borders count="2">'
        . '<border><left/><right/><top/><bottom/><diagonal/></border>'
        . '<border>'
        . '<left style="thin"><color rgb="FFB7B7B7"/></left>'
        . '<right style="thin"><color rgb="FFB7B7B7"/></right>'
        . '<top style="thin"><color rgb="FFB7B7B7"/></top>'
        . '<bottom style="thin"><color rgb="FFB7B7B7"/></bottom>'
        . '<diagonal/></border>'
        . '</borders>'
        . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
        . '<cellXfs count="5">'
        . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
        . '<xf numFmtId="0" fontId="2" fillId="0" borderId="0" xfId="0" applyFont="1" applyAlignment="1"><alignment vertical="center"/></xf>'
        . '<xf numFmtId="0" fontId="3" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
        . '<xf numFmtId="0" fontId="1" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment vertical="center" wrapText="1"/></xf>'
        . '<xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyBorder="1" applyAlignment="1"><alignment vertical="top"/></xf>'
        . '</cellXfs>'
        . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
        . '</styleSheet>';

    // --- package ------------------------------------------------------------
    $contentTypes = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        . '<Default Extension="xml" ContentType="application/xml"/>'
        . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
        . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
        . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
        . '</Types>';
    $rootRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
        . '</Relationships>';
    $workbook = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
        . ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        . '<sheets><sheet name="Report" sheetId="1" r:id="rId1"/></sheets>'
        . '</workbook>';
    $wbRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
        . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
        . '</Relationships>';

    $tmp = tempnam(sys_get_temp_dir(), 'simledger_xlsx_');
    $zip = new ZipArchive();
    if ($zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        @unlink($tmp);

        return;
    }
    $zip->addFromString('[Content_Types].xml', $contentTypes);
    $zip->addFromString('_rels/.rels', $rootRels);
    $zip->addFromString('xl/workbook.xml', $workbook);
    $zip->addFromString('xl/_rels/workbook.xml.rels', $wbRels);
    $zip->addFromString('xl/styles.xml', $styles);
    $zip->addFromString('xl/worksheets/sheet1.xml', $sheet);
    $zip->close();

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . basename($filename) . '"');
    header('Content-Length: ' . filesize($tmp));
    header('Pragma: no-cache');
    header('Expires: 0');
    readfile($tmp);
    @unlink($tmp);
    exit;
}


/**
 * Preserve current GET filters in a link (drops export/page).
 */
function filtersUrl(array $overrides = []): string
{
    $params = $_GET;
    unset($params['export'], $params['page']);
    $params = array_merge($params, $overrides);

    return http_build_query($params);
}

/**
 * Neutralize spreadsheet formula injection in exported cells.
 */
function csvSafe(string $value): string
{
    if ($value !== '' && in_array($value[0], ['=', '+', '-', '@'], true)) {
        return "'" . $value;
    }

    return $value;
}

/**
 * Render a Bootstrap pagination control.
 */
function renderPagination(int $page, int $pages, string $baseUrl, string $param = 'page'): void
{
    if ($pages <= 1) {
        return;
    }

    $sep = strpos($baseUrl, '?') === false ? '?' : '&';
    $link = static function (int $p, string $label, bool $disabled = false, bool $active = false) use ($baseUrl, $sep, $param): string {
        if ($disabled) {
            return '<li class="page-item disabled"><span class="page-link">' . e($label) . '</span></li>';
        }
        if ($active) {
            return '<li class="page-item active"><span class="page-link">' . e($label) . '</span></li>';
        }

        return '<li class="page-item"><a class="page-link" href="' . e($baseUrl . $sep . $param . '=' . $p) . '">' . e($label) . '</a></li>';
    };

    echo '<nav><ul class="pagination pagination-sm mb-0">';
    // UTF-8 chars, not HTML entities: e($label) would escape &laquo; into
    // literal "&laquo;" text in the browser.
    echo $link(max(1, $page - 1), '«', $page <= 1);

    $start = max(1, $page - 2);
    $end   = min($pages, $page + 2);

    if ($start > 1) {
        echo $link(1, '1');
        if ($start > 2) {
            echo '<li class="page-item disabled"><span class="page-link">&hellip;</span></li>';
        }
    }

    for ($p = $start; $p <= $end; $p++) {
        echo $link($p, (string)$p, false, $p === $page);
    }

    if ($end < $pages) {
        if ($end < $pages - 1) {
            echo '<li class="page-item disabled"><span class="page-link">&hellip;</span></li>';
        }
        echo $link($pages, (string)$pages);
    }

    echo $link(min($pages, $page + 1), '»', $page >= $pages);
    echo '</ul></nav>';
}

/**
 * Clamp an integer request parameter.
 */
function reqInt(string $key, int $default, int $min, int $max): int
{
    $v = $_GET[$key] ?? $_POST[$key] ?? null;

    if (!is_scalar($v) || !is_numeric($v)) {
        return $default;
    }

    return max($min, min($max, (int)$v));
}

/**
 * Sanitize a whitelist-based sort key.
 */
function reqSort(string $key, array $allowed, string $default): string
{
    $v = $_GET[$key] ?? null;

    if (is_string($v) && in_array($v, $allowed, true)) {
        return $v;
    }

    return $default;
}

/**
 * Sanitize sort direction.
 */
function reqDir(string $default = 'asc'): string
{
    return strtolower((string)($_GET['dir'] ?? '')) === 'desc' ? 'desc' : $default;
}
