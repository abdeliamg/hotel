<?php
require_once __DIR__ . '/auth.php';
require_role('admin');
include("db.php");

/* حذف جميع الرحلات */
if (isset($_POST['delete_all_flights'])) {
  $pdo->exec("DELETE FROM `flight`");
  $pdo->exec("DELETE FROM sqlite_sequence WHERE name='flight'");
  exit;
}

if(isset($_POST['delete_id'])){
  $pdo->prepare("DELETE FROM `flight` WHERE id=?")->execute([$_POST['delete_id']]);
  exit;
}

if(isset($_POST['edit_id'])){
  $fields=["num","flight_id","date","time","type"];
  $set = implode(",", array_map(fn($f)=>"`$f`=?", $fields));
  $vals = array_map(fn($f)=>$_POST["edit_$f"], $fields);
  $vals[] = $_POST['edit_id'];
  $pdo->prepare("UPDATE `flight` SET $set WHERE id=?")->execute($vals);
  exit;
}

/* DataTables parameters */
$draw   = intval($_POST['draw']);
$start  = intval($_POST['start']);
$length = intval($_POST['length']);

$columnsSql = ["num", "flight_id", "date", "time", "type"];
$orderIndex = intval($_POST['order'][0]['column'] ?? 0);
$orderDir   = ($_POST['order'][0]['dir'] ?? 'asc') === 'asc' ? 'ASC' : 'DESC';
$orderCol   = $columnsSql[$orderIndex] ?? "id";

$search = $_POST['search']['value'] ?? '';

$where = "";
$params = [];
if($search !== ''){
  $where = "WHERE num LIKE ? OR flight_id LIKE ? OR date LIKE ? OR time LIKE ? OR type LIKE ?";
  for($i=0; $i<5; $i++) { $params[] = "%$search%"; }
}

/* Total count */
$total = $pdo->query("SELECT COUNT(*) FROM `flight`")->fetchColumn();

/* Filtered count */
$stmt = $pdo->prepare("SELECT COUNT(*) FROM `flight` $where");
$stmt->execute($params);
$filtered = $stmt->fetchColumn();

/* Fetch data */
$paramsLimit = $params;
$paramsLimit[] = $length;
$paramsLimit[] = $start;
$stmt = $pdo->prepare("SELECT * FROM `flight` $where ORDER BY $orderCol $orderDir LIMIT ? OFFSET ?");
$stmt->execute($paramsLimit);

$data = [];
while($r = $stmt->fetch(PDO::FETCH_ASSOC)){
  $r['actions'] = '<button class="btn btn-warning btn-sm edit-btn" data-id="'.$r['id'].'">تعديل</button>
                   <button class="btn btn-danger btn-sm delete-btn" data-id="'.$r['id'].'">حذف</button>';
  $data[] = $r;
}

echo json_encode([
  "draw" => $draw,
  "recordsTotal" => $total,
  "recordsFiltered" => $filtered,
  "data" => $data
], JSON_UNESCAPED_UNICODE);
?>