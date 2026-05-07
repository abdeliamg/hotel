<?php
require_once __DIR__ . '/auth.php';
require_role('admin');
include("../includes/db.php");

header('Content-Type: application/json');

$out_id = $_GET['out'] ?? '';
$back_id = $_GET['back'] ?? '';

function getFlight($pdo, $fid) {
    if (!$fid) return null;
    $stmt = $pdo->prepare("SELECT * FROM flight WHERE flight_id = ?");
    $stmt->execute([$fid]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

echo json_encode([
    'out' => getFlight($pdo, $out_id),
    'back' => getFlight($pdo, $back_id)
], JSON_UNESCAPED_UNICODE);
