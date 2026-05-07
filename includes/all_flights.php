<?php
require_once __DIR__ . '/auth.php';
require_role('admin');
include("db.php");
header('Content-Type: application/json');

$flights = $pdo->query("SELECT flight_id, num, date FROM flight ORDER BY date ASC")->fetchAll(PDO::FETCH_ASSOC);
echo json_encode($flights, JSON_UNESCAPED_UNICODE);