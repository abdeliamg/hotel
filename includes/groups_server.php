<?php
require_once __DIR__ . '/auth.php';
require_role('admin');
include("db.php");
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
