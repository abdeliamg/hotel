<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
require_once __DIR__ . '/auth.php';
require_role('admin');
include("db.php");
/* حذف كل السجلات */
if (isset($_POST['delete_all'])) {
  $pdo->exec("DELETE FROM pilgrim");
  // أعد ضبط الأوتوإنكريمنت (اختياري)
  $pdo->exec("DELETE FROM sqlite_sequence WHERE name='pilgrim'");
  exit;
}

if(isset($_POST['delete_id'])){
    $pdo->prepare("DELETE FROM pilgrim WHERE id=?")->execute([$_POST['delete_id']]);
    exit;
}
if(isset($_POST['edit_id'])){
    $pdo->prepare("UPDATE pilgrim SET national=?,name=?,`group`=?,barcode=?,phone=?,passport=?,visa=?,app_id=?, flight_id_out=?, flight_id_in=? WHERE id=?")
        ->execute([$_POST['edit_national'],$_POST['edit_name'],$_POST['edit_group'],$_POST['edit_barcode'],
                   $_POST['edit_phone'],$_POST['edit_passport'],$_POST['edit_visa'],$_POST['edit_app_id'],$_POST['edit_flight_id_out'],$_POST['edit_flight_id_in'],$_POST['edit_id']]);
    exit;
}

$draw=intval($_POST['draw']);
$start=intval($_POST['start']);
$length=intval($_POST['length']);
$columns=["national","name","`group`","master_group","barcode"];
$orderCol=$columns[$_POST['order'][0]['column']];
$orderDir=$_POST['order'][0]['dir'];
$search=$_POST['search']['value'];

$where = "";
$params = [];

if ($search) {
    // Replace spaces with % for flexible matching
    $search = '%' . str_replace(' ', '%', $search) . '%';

    $where = "WHERE national LIKE ? OR name LIKE ? OR pilgrim.`group` LIKE ? OR barcode LIKE ?";
    for ($i = 0; $i < 4; $i++) {
        $params[] = $search;
    }
}

$total=$pdo->query("SELECT COUNT(*) FROM pilgrim")->fetchColumn();
$stm=$pdo->prepare("SELECT COUNT(*) FROM pilgrim $where");$stm->execute($params);
$filtered=$stm->fetchColumn();

$params[]= $length; $params[]=$start;
$stm=$pdo->prepare("SELECT * FROM pilgrim left join `group` on pilgrim.`group` = `group`.`group` $where ORDER BY $orderCol $orderDir LIMIT ? OFFSET ?");
$stm->execute($params);

$data=[];
while($r=$stm->fetch(PDO::FETCH_ASSOC)){
  $r['actions']='<a href="../pages/preview.php?barcode='.$r['barcode'].'" class="btn btn-info btn-sm">عرض</a>
                 <button class="btn btn-warning btn-sm edit-btn" data-id="'.$r['id'].'">تعديل</button>
                 <button class="btn btn-danger btn-sm delete-btn" data-id="'.$r['id'].'">حذف</button>';
  $data[]=$r;
}

echo json_encode(["draw"=>$draw,"recordsTotal"=>$total,"recordsFiltered"=>$filtered,"data"=>$data],JSON_UNESCAPED_UNICODE);
?>