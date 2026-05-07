<?php
function normalize_import_header(string $value): string
{
    $value = trim(mb_strtolower($value, 'UTF-8'));
    $value = str_replace([' ', '-', '/', '\\'], '_', $value);
    return preg_replace('/_+/', '_', $value);
}

function build_import_alias_map(array $columns): array
{
    $map = [];
    foreach ($columns as $canonical => $aliases) {
        $map[normalize_import_header($canonical)] = $canonical;
        foreach ($aliases as $alias) {
            $map[normalize_import_header((string)$alias)] = $canonical;
        }
    }
    return $map;
}

function parse_pasted_tsv(string $rawText, array $columnAliases, array $fixedOrder): array
{
    $rawText = trim($rawText);
    if ($rawText === '') {
        return ['ok' => false, 'message' => 'لا توجد بيانات للصق.'];
    }

    $lines = preg_split('/\r\n|\n|\r/', $rawText);
    $lines = array_values(array_filter(array_map('trim', $lines), static fn($line) => $line !== ''));
    if (empty($lines)) {
        return ['ok' => false, 'message' => 'لا توجد صفوف صالحة للمعالجة.'];
    }

    $aliasMap = build_import_alias_map($columnAliases);
    $firstCols = array_map('trim', explode("\t", $lines[0]));
    $headerMap = [];
    foreach ($firstCols as $idx => $col) {
        $normalized = normalize_import_header($col);
        if (isset($aliasMap[$normalized])) {
            $headerMap[$idx] = $aliasMap[$normalized];
        }
    }

    $hasHeader = !empty($headerMap);
    if ($hasHeader) {
        $requiredHeaders = array_fill_keys($fixedOrder, true);
        foreach ($headerMap as $canonical) {
            unset($requiredHeaders[$canonical]);
        }
        if (!empty($requiredHeaders)) {
            return [
                'ok' => false,
                'message' => 'أعمدة الرأس غير مكتملة.',
                'missing_headers' => array_keys($requiredHeaders)
            ];
        }
        array_shift($lines);
    } else {
        $headerMap = [];
        foreach ($fixedOrder as $idx => $canonical) {
            $headerMap[$idx] = $canonical;
        }
    }

    $rows = [];
    foreach ($lines as $lineNo => $line) {
        $cols = array_map('trim', explode("\t", $line));
        $row = [];
        foreach ($headerMap as $idx => $canonical) {
            $row[$canonical] = $cols[$idx] ?? '';
        }
        $rows[] = $row + ['_line' => $lineNo + 1];
    }

    return ['ok' => true, 'rows' => $rows, 'has_header' => $hasHeader];
}
?>
