<?php
include('../check.php');
 include("../includes/db.php"); ?>
<!DOCTYPE html>
<html dir="rtl" lang="ar">
<head>
    <title>Add Pilgrim</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
</head>
<body class="container">
    <h2 class="my-4">إضافة حاج جديد</h2>
    <form method="post">
        <?php
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $stmt = $pdo->prepare("INSERT INTO pilgrim (national, name, `group`, barcode, phone, passport, visa, app_id)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([
                $_POST['national'], $_POST['name'], $_POST['group'], $_POST['barcode'],
                $_POST['phone'], $_POST['passport'], $_POST['visa'], $_POST['app_id']
            ]);
            echo "<div class='alert alert-success'>تمت الإضافة بنجاح</div>";
        }
        ?>
        <div class="mb-3"><label class="form-label">الرقم الوطني</label><input name="national" class="form-control"></div>
        <div class="mb-3"><label class="form-label">اسم الحاج</label><input name="name" class="form-control"></div>
        <div class="mb-3"><label class="form-label">المجموعة</label><input name="group" class="form-control"></div>
        <div class="mb-3"><label class="form-label">الباركود</label><input name="barcode" class="form-control"></div>
        <div class="mb-3"><label class="form-label">رقم الجوال</label><input name="phone" class="form-control"></div>
        <div class="mb-3"><label class="form-label">جواز السفر</label><input name="passport" class="form-control"></div>
        <div class="mb-3"><label class="form-label">الفيزا</label><input name="visa" class="form-control"></div>
        <div class="mb-3"><label class="form-label">App ID</label><input name="app_id" class="form-control"></div>
        <button type="submit" class="btn btn-primary">إضافة</button>
        <a href="pilgrims.php" class="btn btn-secondary">رجوع</a>
    </form>
</body>
</html>