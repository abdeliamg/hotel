<?php
include('../check.php');
 include("../includes/db.php");
$group = $_GET['group'];
$stmt = $pdo->prepare("SELECT * FROM `group` WHERE `group` = ?");
$stmt->execute([$group]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);
echo json_encode($row);