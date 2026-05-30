<?php
require_once __DIR__ . '/auth.php';
require_role('admin');
include("db.php");
require_once __DIR__ . '/paste_import.php';

function groups_json_ok(array $extra = []): void {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['status' => 'ok'] + $extra, JSON_UNESCAPED_UNICODE);
    exit;
}
function groups_json_err(string $message, array $extra = [], int $code = 400): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['status' => 'error', 'message' => $message] + $extra, JSON_UNESCAPED_UNICODE);
    exit;
}

// ---------------------------------------------------------------------------
// CSV template download (GET) — Arabic headers, semicolon-separated, UTF-8 BOM.
// ---------------------------------------------------------------------------
if (isset($_GET['action']) && $_GET['action'] === 'download_template') {
    $filename = 'groups_template_' . date('Ymd_His') . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-store, no-cache, must-revalidate');

    echo "\xEF\xBB\xBF";
    $out = fopen('php://output', 'w');

    $rows = [
        ['اسم المجموعة','التكتل','رقم المجموعة','فندق مكة','موقع فندق مكة','فندق المدينة','موقع فندق المدينة','رقم المطوف','موقع المطوف','رقم مخيم منى','موقع مخيم منى','رقم مخيم عرفات','موقع مخيم عرفات'],
        ['مجموعة 1','تكتل أ','0500000000','فندق هيلتون مكة','الشارع 1','فندق دار التقوى','الشارع 2','المطوف 1','موقع المطوف','مخيم منى 1','موقع منى','مخيم عرفات 1','موقع عرفات'],
        ['مجموعة 2','تكتل ب','0500000001','فندق سويسوتيل','الشارع 3','فندق المدينة موفنبيك','الشارع 4','المطوف 2','','','','',''],
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
function groups_parse_rows(string $raw): array {
    return parse_pasted_tsv(
        $raw,
        [
            'group'             => ['اسم_المجموعة','group','المجموعة'],
            'master_group'      => ['التكتل','master_group'],
            'group_phone'       => ['رقم_المجموعة','group_phone','هاتف_المجموعة'],
            'mecca_hotel'       => ['فندق_مكة','mecca_hotel'],
            'mecca_location'    => ['موقع_فندق_مكة','mecca_location'],
            'medina_hotel'      => ['فندق_المدينة','medina_hotel'],
            'medina_location'   => ['موقع_فندق_المدينة','medina_location'],
            'mutawwef'          => ['رقم_المطوف','mutawwef','المطوف'],
            'mutawwef_location' => ['موقع_المطوف','mutawwef_location'],
            'mina'              => ['رقم_مخيم_منى','mina','منى'],
            'mina_location'     => ['موقع_مخيم_منى','mina_location'],
            'arafa'             => ['رقم_مخيم_عرفات','arafa','عرفات'],
            'arafa_location'    => ['موقع_مخيم_عرفات','arafa_location'],
        ],
        ['group','master_group','group_phone','mecca_hotel','mecca_location','medina_hotel','medina_location','mutawwef','mutawwef_location','mina','mina_location','arafa','arafa_location']
    );
}

function groups_load_known_hotels(PDO $pdo): array {
    $set = [];
    foreach ($pdo->query("SELECT hotel_name FROM hotel")->fetchAll(PDO::FETCH_COLUMN) as $h) {
        $k = mb_strtolower(trim((string)$h), 'UTF-8');
        if ($k !== '') $set[$k] = true;
    }
    return $set;
}

function groups_normalize_row(array $r): array {
    return [
        'group'             => trim((string)($r['group']             ?? '')),
        'master_group'      => trim((string)($r['master_group']      ?? '')),
        'group_phone'       => trim((string)($r['group_phone']       ?? '')),
        'mecca_hotel'       => trim((string)($r['mecca_hotel']       ?? '')),
        'mecca_location'    => trim((string)($r['mecca_location']    ?? '')),
        'medina_hotel'      => trim((string)($r['medina_hotel']      ?? '')),
        'medina_location'   => trim((string)($r['medina_location']   ?? '')),
        'mutawwef'          => trim((string)($r['mutawwef']          ?? '')),
        'mutawwef_location' => trim((string)($r['mutawwef_location'] ?? '')),
        'mina'              => trim((string)($r['mina']              ?? '')),
        'mina_location'     => trim((string)($r['mina_location']     ?? '')),
        'arafa'             => trim((string)($r['arafa']             ?? '')),
        'arafa_location'    => trim((string)($r['arafa_location']    ?? '')),
    ];
}

function groups_validate_row(array $row, array $knownHotels): array {
    $errors = [];
    if ($row['group'] === '' || $row['master_group'] === '') {
        $errors[] = 'حقول مطلوبة ناقصة (اسم المجموعة، التكتل).';
    }
    if ($row['mecca_hotel'] !== '' && !isset($knownHotels[mb_strtolower($row['mecca_hotel'], 'UTF-8')])) {
        $errors[] = 'فندق مكة غير موجود في جدول الفنادق: ' . $row['mecca_hotel'];
    }
    if ($row['medina_hotel'] !== '' && !isset($knownHotels[mb_strtolower($row['medina_hotel'], 'UTF-8')])) {
        $errors[] = 'فندق المدينة غير موجود في جدول الفنادق: ' . $row['medina_hotel'];
    }
    return $errors;
}

// ---------------------------------------------------------------------------
// Bulk validate (POST)
// ---------------------------------------------------------------------------
if (isset($_POST['action']) && $_POST['action'] === 'bulk_validate') {
    $parsed = groups_parse_rows((string)($_POST['rows_text'] ?? ''));
    if (!$parsed['ok']) {
        groups_json_err($parsed['message'], ['missing_headers' => $parsed['missing_headers'] ?? []]);
    }
    $known = groups_load_known_hotels($pdo);
    $rows = [];
    $errors = [];
    foreach ($parsed['rows'] as $idx => $r) {
        $row = groups_normalize_row($r);
        $rows[] = $row;
        foreach (groups_validate_row($row, $known) as $msg) {
            $errors[] = ['index' => $idx, 'message' => $msg];
        }
    }
    groups_json_ok([
        'rows'       => $rows,
        'errors'     => $errors,
        'has_header' => $parsed['has_header'] ?? false,
        'count'      => count($rows),
    ]);
}

// ---------------------------------------------------------------------------
// Bulk insert (POST)
// ---------------------------------------------------------------------------
if (isset($_POST['action']) && $_POST['action'] === 'bulk_insert') {
    $rowsRaw = $_POST['rows'] ?? '[]';
    $rows = is_array($rowsRaw) ? $rowsRaw : json_decode((string)$rowsRaw, true);
    if (!is_array($rows)) groups_json_err('بيانات الإدخال غير صالحة.');

    $known = groups_load_known_hotels($pdo);
    $insert = $pdo->prepare("INSERT INTO `group`
        (`group`, master_group, group_phone, mecca_hotel, mecca_location, medina_hotel, medina_location,
         mutawwef, mutawwef_location, mina, mina_location, arafa, arafa_location)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)");

    $inserted = 0;
    $results = [];
    try {
        $pdo->exec("PRAGMA synchronous = OFF");
        $pdo->exec("PRAGMA journal_mode = MEMORY");
        $pdo->beginTransaction();
        foreach ($rows as $idx => $r) {
            $row = groups_normalize_row(is_array($r) ? $r : []);
            $errs = groups_validate_row($row, $known);
            if ($errs) {
                $results[] = ['index' => $idx, 'status' => 'error', 'message' => implode(' | ', $errs)];
                continue;
            }
            $insert->execute([
                $row['group'], $row['master_group'], $row['group_phone'],
                $row['mecca_hotel'], $row['mecca_location'],
                $row['medina_hotel'], $row['medina_location'],
                $row['mutawwef'], $row['mutawwef_location'],
                $row['mina'], $row['mina_location'],
                $row['arafa'], $row['arafa_location'],
            ]);
            $inserted++;
            $results[] = ['index' => $idx, 'status' => 'inserted'];
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
        groups_json_err('فشل أثناء الإدخال: ' . $e->getMessage(), [], 500);
    }

    groups_json_ok(['inserted' => $inserted, 'total' => count($rows), 'results' => $results]);
}

// ---------------------------------------------------------------------------
// Credentials lookup for a master_group (mirrors /hotel_pilgrim/login.php).
// Returns { username: master_group, password: id*50 } for the first matching
// group row — same row login.php would authenticate against.
// ---------------------------------------------------------------------------
if (isset($_POST['action']) && $_POST['action'] === 'mg_credentials') {
    $mg = trim((string)($_POST['master_group'] ?? ''));
    if ($mg === '') {
        groups_json_err('master_group مطلوب.');
    }
    $stmt = $pdo->prepare('SELECT id, master_group FROM "group" WHERE master_group = :mg ORDER BY id ASC LIMIT 1');
    $stmt->execute([':mg' => $mg]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        groups_json_err('لم يتم العثور على التكتل.', [], 404);
    }
    groups_json_ok([
        'username' => (string)$row['master_group'],
        'password' => (string)((int)$row['id'] * 50),
    ]);
}

// ---------------------------------------------------------------------------
// Toggle the "all rooms" flag (POST). Applies to EVERY group row that shares
// the given master_group so the setting is consistent for the whole تكتل.
// ---------------------------------------------------------------------------
if (isset($_POST['action']) && $_POST['action'] === 'toggle_all_rooms') {
    $mg = trim((string)($_POST['master_group'] ?? ''));
    $val = ((int)($_POST['value'] ?? 0) === 1) ? 1 : 0;
    if ($mg === '') {
        groups_json_err('التكتل مطلوب.');
    }
    $stmt = $pdo->prepare('UPDATE "group" SET all_rooms = :v WHERE master_group = :mg');
    $stmt->execute([':v' => $val, ':mg' => $mg]);
    groups_json_ok(['updated' => $stmt->rowCount(), 'value' => $val]);
}

/* حذف جميع المجموعات */
if (isset($_POST['delete_all_groups'])) {
  $pdo->exec("DELETE FROM `group`");
  // في SQLite يمكنك أيضًا إعادة عدّاد الـ AUTOINCREMENT
  $pdo->exec("DELETE FROM sqlite_sequence WHERE name='group'");
  exit;
}

if(isset($_POST['delete_id'])){
  $pdo->prepare("DELETE FROM `group` WHERE id=?")->execute([$_POST['delete_id']]);
  exit;
}

if(isset($_POST['edit_id'])){
  $fields=["group","master_group","group_phone","mecca_hotel","mecca_location","medina_hotel","medina_location",
           "mutawwef","mutawwef_location","mina","mina_location","arafa","arafa_location"];
  $set = implode(",", array_map(fn($f)=>"`$f`=?", $fields));
  $vals = array_map(fn($f)=>$_POST["edit_$f"], $fields);
  $vals[] = $_POST['edit_id'];
  $pdo->prepare("UPDATE `group` SET $set WHERE id=?")->execute($vals);
  exit;
}

/* DataTables parameters */
$draw   = intval($_POST['draw']);
$start  = intval($_POST['start']);
$length = intval($_POST['length']);

$columnsSql = ["`group`","`master_group`","group_phone","mecca_hotel"];
$orderIndex = intval($_POST['order'][0]['column']);
$orderDir   = $_POST['order'][0]['dir'] === 'asc' ? 'ASC' : 'DESC';
$orderCol   = $columnsSql[$orderIndex] ?? "`group`";

$search = $_POST['search']['value'] ?? '';

$where = "";
$params=[];
if($search !== ''){
  $where = "WHERE `group` LIKE ? OR master_group LIKE ? OR group_phone LIKE ? OR mecca_hotel LIKE ?";
  for($i=0;$i<4;$i++){ $params[] = "%$search%"; }
}

/* Total */
$total = $pdo->query("SELECT COUNT(*) FROM `group`")->fetchColumn();

/* Filtered count */
$stmt = $pdo->prepare("SELECT COUNT(*) FROM `group` $where");
$stmt->execute($params);
$filtered = $stmt->fetchColumn();

/* Fetch data */
$paramsLimit = $params;
$paramsLimit[] = $length;
$paramsLimit[] = $start;
$stmt = $pdo->prepare("SELECT * FROM `group` $where ORDER BY $orderCol $orderDir LIMIT ? OFFSET ?");
$stmt->execute($paramsLimit);

$data=[];
while($r=$stmt->fetch(PDO::FETCH_ASSOC)){
  $r['actions']='<button class="btn btn-warning btn-sm edit-btn" data-id="'.$r['id'].'">تعديل</button>
                 <button class="btn btn-danger btn-sm delete-btn" data-id="'.$r['id'].'">حذف</button>';
  $data[]=$r;
}

echo json_encode([
  "draw"=>$draw,
  "recordsTotal"=>$total,
  "recordsFiltered"=>$filtered,
  "data"=>$data
], JSON_UNESCAPED_UNICODE);
?>
