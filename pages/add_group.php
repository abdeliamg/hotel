<?php
include('../check.php');
include("../includes/db.php"); ?>
<!DOCTYPE html>
<html dir="rtl" lang="ar">
<head>
    <meta charset="UTF-8">
    <title>إضافة مجموعة</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.rtl.min.css">
</head>
<body class="container">
    <h2 class="my-4">إضافة مجموعة جديدة</h2>
    <form method="post">
        <?php
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $stmt = $pdo->prepare("INSERT INTO `group` (
                `group`, master_group, group_phone, mecca_hotel, mecca_location,
                medina_hotel, medina_location, mutawwef, mutawwef_location,
                mina, mina_location, arafa, arafa_location)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([
                $_POST['group'], $_POST['master_group'], $_POST['group_phone'], $_POST['mecca_hotel'], $_POST['mecca_location'],
                $_POST['medina_hotel'], $_POST['medina_location'], $_POST['mutawwef'], $_POST['mutawwef_location'],
                $_POST['mina'], $_POST['mina_location'], $_POST['arafa'], $_POST['arafa_location']
            ]);
            echo "<div class='alert alert-success'>تمت الإضافة بنجاح</div>";
        }
        ?>
        <?php
        $fields = [
            "group" => "اسم المجموعة", "master_group" => "التكتل", "group_phone" => "رقم المجموعة",
            "mecca_hotel" => "فندق مكة", "mecca_location" => "موقع فندق مكة",
            "medina_hotel" => "فندق المدينة", "medina_location" => "موقع فندق المدينة",
            "mutawwef" => "رقم المطوف", "mutawwef_location" => "موقع المطوف",
            "mina" => "رقم مخيم منى", "mina_location" => "موقع مخيم منى",
            "arafa" => "رقم مخيم عرفات", "arafa_location" => "موقع مخيم عرفات"
        ];
        foreach ($fields as $name => $label) {
            echo "<div class='mb-3'><label class='form-label'>{$label}</label><input name='{$name}' class='form-control'></div>";
        }
        ?>
        <button type="submit" class="btn btn-primary">إضافة</button>
        <a href="groups.php" class="btn btn-secondary">رجوع</a>
    </form>
</body>
</html>