<?php
// Don't leak PHP warnings/notices into JSON responses (they break the client-side
// dataType:'json' parser and surface as "تعذّر الاتصال بالخادم"). Log instead.
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);
require_once __DIR__ . '/auth.php';
require_role('admin');
include("db.php");
require_once __DIR__ . '/paste_import.php';

function pilgrims_json_ok(array $extra = []): void {
    while (ob_get_level() > 0) { ob_end_clean(); }
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['status' => 'ok'] + $extra, JSON_UNESCAPED_UNICODE);
    exit;
}

function pilgrims_json_err(string $message, array $extra = [], int $code = 400): void {
    while (ob_get_level() > 0) { ob_end_clean(); }
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['status' => 'error', 'message' => $message] + $extra, JSON_UNESCAPED_UNICODE);
    exit;
}

// ---------------------------------------------------------------------------
// CSV template download (GET) — Arabic headers, semicolon-separated, UTF-8 BOM.
// ---------------------------------------------------------------------------
if (isset($_GET['action']) && $_GET['action'] === 'download_template') {
    $filename = 'pilgrims_template_' . date('Ymd_His') . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-store, no-cache, must-revalidate');

    echo "\xEF\xBB\xBF";
    $out = fopen('php://output', 'w');

    $rows = [
        ['الرقم الوطني', 'الاسم', 'المجموعة', 'الباركود', 'الجوال', 'جواز السفر', 'الفيزا', 'App ID', 'رحلة الذهاب', 'رحلة الإياب'],
        ['1234567890', 'محمد أحمد علي',  'مجموعة 1', 'BC0001', '0500000000', 'P1234567', 'V1234567', 'APP-001', 'SV101', 'SV102'],
        ['2345678901', 'فاطمة سالم',     'مجموعة 1', 'BC0002', '0500000001', 'P2345678', 'V2345678', '',        '',      ''],
    ];
    foreach ($rows as $row) {
        fputcsv($out, $row, ';');
    }
    fclose($out);
    exit;
}

// ---------------------------------------------------------------------------
// Helpers shared by validate / insert
// ---------------------------------------------------------------------------
function pilgrims_parse_rows(string $raw): array {
    return parse_pasted_tsv(
        $raw,
        [
            'national'      => ['الرقم_الوطني', 'national'],
            'name'          => ['الاسم', 'name'],
            'group'         => ['المجموعة', 'group'],
            'barcode'       => ['الباركود', 'barcode'],
            'phone'         => ['الجوال', 'phone', 'mobile', 'الهاتف'],
            'passport'      => ['جواز_السفر', 'passport', 'الجواز'],
            'visa'          => ['الفيزا', 'visa'],
            'app_id'        => ['app_id', 'معرف_التطبيق'],
            'flight_id_out' => ['رحلة_الذهاب', 'flight_out', 'flight_id_out'],
            'flight_id_in'  => ['رحلة_الإياب', 'flight_in', 'flight_id_in'],
        ],
        ['national', 'name', 'group', 'barcode', 'phone', 'passport', 'visa', 'app_id', 'flight_id_out', 'flight_id_in']
    );
}

function pilgrims_load_known_groups(PDO $pdo): array {
    $set = [];
    foreach ($pdo->query("SELECT `group` FROM `group`")->fetchAll(PDO::FETCH_COLUMN) as $g) {
        $key = mb_strtolower(trim((string)$g), 'UTF-8');
        if ($key !== '') $set[$key] = true;
    }
    return $set;
}

function pilgrims_load_known_flights(PDO $pdo): array {
    $set = [];
    foreach ($pdo->query("SELECT flight_id FROM flight")->fetchAll(PDO::FETCH_COLUMN) as $f) {
        $key = mb_strtolower(trim((string)$f), 'UTF-8');
        if ($key !== '') $set[$key] = true;
    }
    return $set;
}

// ---------------------------------------------------------------------------
// Bulk validate (POST)
// ---------------------------------------------------------------------------
if (isset($_POST['action']) && $_POST['action'] === 'bulk_validate') {
    @set_time_limit(0);
    @ini_set('memory_limit', '512M');
    $parsed = pilgrims_parse_rows((string)($_POST['rows_text'] ?? ''));
    if (!$parsed['ok']) {
        pilgrims_json_err($parsed['message'], ['missing_headers' => $parsed['missing_headers'] ?? []]);
    }

    $knownGroups  = pilgrims_load_known_groups($pdo);
    $knownFlights = pilgrims_load_known_flights($pdo);

    $errors    = [];
    $maxErrors = 500;
    $errorCount = 0;
    $count     = 0;

    foreach ($parsed['rows'] as $idx => $r) {
        $count++;
        $natl = trim((string)($r['national'] ?? ''));
        $name = trim((string)($r['name']     ?? ''));
        $grp  = trim((string)($r['group']    ?? ''));
        $fout = trim((string)($r['flight_id_out'] ?? ''));
        $fin  = trim((string)($r['flight_id_in']  ?? ''));

        $rowErrors = [];
        if ($natl === '' || $name === '' || $grp === '') {
            $rowErrors[] = 'حقول مطلوبة ناقصة (الرقم الوطني، الاسم، المجموعة).';
        } else {
            if (!isset($knownGroups[mb_strtolower($grp, 'UTF-8')])) {
                $rowErrors[] = 'المجموعة غير موجودة في جدول المجموعات: ' . $grp;
            }
            if ($fout !== '' && !isset($knownFlights[mb_strtolower($fout, 'UTF-8')])) {
                $rowErrors[] = 'رحلة الذهاب غير موجودة: ' . $fout;
            }
            if ($fin !== '' && !isset($knownFlights[mb_strtolower($fin, 'UTF-8')])) {
                $rowErrors[] = 'رحلة الإياب غير موجودة: ' . $fin;
            }
        }

        foreach ($rowErrors as $msg) {
            $errorCount++;
            if (count($errors) < $maxErrors) {
                $errors[] = ['index' => $idx, 'message' => $msg];
            }
        }
    }

    pilgrims_json_ok([
        'errors'      => $errors,
        'error_count' => $errorCount,
        'truncated'   => $errorCount > count($errors),
        'has_header'  => $parsed['has_header'] ?? false,
        'count'       => $count,
    ]);
}

// ---------------------------------------------------------------------------
// Bulk insert (POST) — defense-in-depth re-validation, then batched insert.
// ---------------------------------------------------------------------------
if (isset($_POST['action']) && $_POST['action'] === 'bulk_insert') {
    @set_time_limit(0);
    @ini_set('memory_limit', '512M');
    ignore_user_abort(true);

    $parsed = pilgrims_parse_rows((string)($_POST['rows_text'] ?? ''));
    if (!$parsed['ok']) {
        pilgrims_json_err($parsed['message'], ['missing_headers' => $parsed['missing_headers'] ?? []]);
    }

    $knownGroups  = pilgrims_load_known_groups($pdo);
    $knownFlights = pilgrims_load_known_flights($pdo);

    $insert = $pdo->prepare(
        "INSERT INTO pilgrim (national, name, `group`, barcode, phone, passport, visa, app_id, flight_id_out, flight_id_in)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
    );

    $inserted   = 0;
    $skipped    = 0;
    $total      = 0;
    $errors     = [];
    $maxErrors  = 500;

    try {
        $pdo->exec("PRAGMA synchronous = OFF");
        $pdo->exec("PRAGMA journal_mode = MEMORY");
        $pdo->beginTransaction();

        foreach ($parsed['rows'] as $idx => $r) {
            $total++;
            $natl = trim((string)($r['national']      ?? ''));
            $name = trim((string)($r['name']          ?? ''));
            $grp  = trim((string)($r['group']         ?? ''));
            $bc   = trim((string)($r['barcode']       ?? ''));
            $ph   = trim((string)($r['phone']         ?? ''));
            $pp   = trim((string)($r['passport']      ?? ''));
            $vz   = trim((string)($r['visa']          ?? ''));
            $aid  = trim((string)($r['app_id']        ?? ''));
            $fout = trim((string)($r['flight_id_out'] ?? ''));
            $fin  = trim((string)($r['flight_id_in']  ?? ''));

            $err = null;
            if ($natl === '' || $name === '' || $grp === '') {
                $err = 'حقول مطلوبة ناقصة.';
            } elseif (!isset($knownGroups[mb_strtolower($grp, 'UTF-8')])) {
                $err = 'المجموعة غير موجودة: ' . $grp;
            } elseif ($fout !== '' && !isset($knownFlights[mb_strtolower($fout, 'UTF-8')])) {
                $err = 'رحلة الذهاب غير موجودة: ' . $fout;
            } elseif ($fin !== '' && !isset($knownFlights[mb_strtolower($fin, 'UTF-8')])) {
                $err = 'رحلة الإياب غير موجودة: ' . $fin;
            }

            if ($err !== null) {
                $skipped++;
                if (count($errors) < $maxErrors) {
                    $errors[] = ['index' => $idx, 'message' => $err];
                }
                continue;
            }

            $insert->execute([$natl, $name, $grp, $bc, $ph, $pp, $vz, $aid, $fout, $fin]);
            $inserted++;

            if ($inserted % 2000 === 0) {
                $pdo->commit();
                $pdo->beginTransaction();
            }
        }

        $pdo->commit();
        $pdo->exec("PRAGMA synchronous = NORMAL");
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $pdo->exec("PRAGMA synchronous = NORMAL");
        error_log('[pilgrims_server::bulk_insert] ' . $e->getMessage());
        pilgrims_json_err('فشل أثناء الإدخال: ' . $e->getMessage(), [], 500);
    }

    pilgrims_json_ok([
        'inserted'    => $inserted,
        'skipped'     => $skipped,
        'total'       => $total,
        'errors'      => $errors,
        'error_count' => $skipped,
        'truncated'   => $skipped > count($errors),
    ]);
}

/* حذف كل السجلات */
if (isset($_POST['delete_all'])) {
    $pdo->exec("DELETE FROM pilgrim");
    // أعد ضبط الأوتوإنكريمنت (اختياري)
    $pdo->exec("DELETE FROM sqlite_sequence WHERE name='pilgrim'");
    pilgrims_json_ok();
}

if (isset($_POST['delete_id'])) {
    $stmt = $pdo->prepare("DELETE FROM pilgrim WHERE id = ?");
    $stmt->execute([(int)$_POST['delete_id']]);
    pilgrims_json_ok(['deleted' => $stmt->rowCount()]);
}

if (isset($_POST['edit_id'])) {
    $pdo->prepare("UPDATE pilgrim SET national=?,name=?,`group`=?,barcode=?,phone=?,passport=?,visa=?,app_id=?, flight_id_out=?, flight_id_in=? WHERE id=?")
        ->execute([
            $_POST['edit_national'], $_POST['edit_name'], $_POST['edit_group'], $_POST['edit_barcode'],
            $_POST['edit_phone'], $_POST['edit_passport'], $_POST['edit_visa'], $_POST['edit_app_id'],
            $_POST['edit_flight_id_out'], $_POST['edit_flight_id_in'], (int)$_POST['edit_id'],
        ]);
    pilgrims_json_ok();
}

$draw   = intval($_POST['draw']   ?? 0);
$start  = intval($_POST['start']  ?? 0);
$length = intval($_POST['length'] ?? 10);

// Map DataTables column index -> SQL expression (qualified to avoid id/master_group ambiguity)
$columns = [
    'pilgrim.national',
    'pilgrim.name',
    'pilgrim.`group`',
    '`group`.master_group',
    'pilgrim.barcode',
];
$orderIdx = (int)($_POST['order'][0]['column'] ?? 0);
$orderCol = $columns[$orderIdx] ?? 'pilgrim.id';
$orderDir = (strtolower($_POST['order'][0]['dir'] ?? 'asc') === 'desc') ? 'DESC' : 'ASC';
$search   = $_POST['search']['value'] ?? '';

$where  = '';
$params = [];

if ($search !== '') {
    // Replace spaces with % for flexible matching
    $search = '%' . str_replace(' ', '%', $search) . '%';

    $where = "WHERE pilgrim.national LIKE ? OR pilgrim.name LIKE ? OR pilgrim.`group` LIKE ? OR pilgrim.barcode LIKE ?";
    for ($i = 0; $i < 4; $i++) {
        $params[] = $search;
    }
}

$total = (int)$pdo->query("SELECT COUNT(*) FROM pilgrim")->fetchColumn();

$stm = $pdo->prepare("SELECT COUNT(*) FROM pilgrim $where");
$stm->execute($params);
$filtered = (int)$stm->fetchColumn();

$params[] = $length;
$params[] = $start;

// Explicit column list — never SELECT * across the join, or the joined `group`.id overwrites pilgrim.id.
$stm = $pdo->prepare(
    "SELECT
        pilgrim.id           AS id,
        pilgrim.national     AS national,
        pilgrim.name         AS name,
        pilgrim.`group`      AS `group`,
        pilgrim.barcode      AS barcode,
        pilgrim.phone        AS phone,
        pilgrim.passport     AS passport,
        pilgrim.visa         AS visa,
        pilgrim.app_id       AS app_id,
        pilgrim.flight_id_out AS flight_id_out,
        pilgrim.flight_id_in  AS flight_id_in,
        COALESCE(`group`.master_group, pilgrim.master_group) AS master_group
     FROM pilgrim
     LEFT JOIN `group` ON pilgrim.`group` = `group`.`group`
     $where
     ORDER BY $orderCol $orderDir
     LIMIT ? OFFSET ?"
);
$stm->execute($params);

$data = [];
while ($r = $stm->fetch(PDO::FETCH_ASSOC)) {
    $barcode = htmlspecialchars((string)$r['barcode'], ENT_QUOTES, 'UTF-8');
    $rid     = (int)$r['id'];
    $r['actions'] =
        '<a href="../pages/preview.php?barcode=' . $barcode . '" class="btn btn-info btn-sm">عرض</a> ' .
        '<button class="btn btn-warning btn-sm edit-btn" data-id="' . $rid . '">تعديل</button> ' .
        '<button class="btn btn-danger btn-sm delete-btn" data-id="' . $rid . '">حذف</button>';
    $data[] = $r;
}

echo json_encode([
    'draw'            => $draw,
    'recordsTotal'    => $total,
    'recordsFiltered' => $filtered,
    'data'            => $data,
], JSON_UNESCAPED_UNICODE);
?>