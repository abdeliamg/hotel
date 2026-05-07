<?php
include('../check.php');
 include("../includes/db.php");
$id = $_GET['id'];
$row = $pdo->query("SELECT * FROM pilgrim WHERE id = $id")->fetch(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html dir="rtl" lang="ar">
<head>
    <title>Edit Pilgrim</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
</head>
<body class="container">
    <h2 class="my-4">تعديل بيانات الحاج</h2>
    <form method="post">
        <?php
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $stmt = $pdo->prepare("UPDATE pilgrim SET national=?, name=?, `group`=?, barcode=?, phone=?, passport=?, visa=?, app_id=? WHERE id=?");
            $stmt->execute([
                $_POST['national'], $_POST['name'], $_POST['group'], $_POST['barcode'],
                $_POST['phone'], $_POST['passport'], $_POST['visa'], $_POST['app_id'], $id
            ]);
            echo "<div class='alert alert-success'>تم التحديث بنجاح</div>";
            $row = $pdo->query("SELECT * FROM pilgrim WHERE id = $id")->fetch(PDO::FETCH_ASSOC);
        }
        ?>
        <div class="mb-3"><label class="form-label">الرقم الوطني</label><input name="national" value="<?= $row['national'] ?>" class="form-control"></div>
        <div class="mb-3"><label class="form-label">اسم الحاج</label><input name="name" value="<?= $row['name'] ?>" class="form-control"></div>
        <div class="mb-3"><label class="form-label">المجموعة</label><input name="group" value="<?= $row['group'] ?>" class="form-control"></div>
        <div class="mb-3"><label class="form-label">الباركود</label><input name="barcode" value="<?= $row['barcode'] ?>" class="form-control"></div>
        <div class="mb-3"><label class="form-label">رقم الجوال</label><input name="phone" value="<?= $row['phone'] ?>" class="form-control"></div>
        <div class="mb-3"><label class="form-label">جواز السفر</label><input name="passport" value="<?= $row['passport'] ?>" class="form-control"></div>
        <div class="mb-3"><label class="form-label">الفيزا</label><input name="visa" value="<?= $row['visa'] ?>" class="form-control"></div>
        <div class="mb-3"><label class="form-label">App ID</label><input name="app_id" value="<?= $row['app_id'] ?>" class="form-control"></div>
        <button type="submit" class="btn btn-primary">تحديث</button>
        <a href="pilgrims.php" class="btn btn-secondary">رجوع</a>
    </form>
</body>
</html>