<?php
require_once __DIR__ . '/auth.php';
require_role('admin');
include("db.php");
$rows = $pdo->query("SELECT `group`, master_group FROM `group`")->fetchAll(PDO::FETCH_ASSOC);
echo json_encode($rows, JSON_UNESCAPED_UNICODE);
