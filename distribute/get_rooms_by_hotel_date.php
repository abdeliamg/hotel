<?php
require_once __DIR__ . '/../check.php';
require_once __DIR__ . '/../includes/db.php';

header('Content-Type: application/json; charset=utf-8');

$hotel    = isset($_GET['hotel'])     ? trim((string)$_GET['hotel'])     : '';
$dateFrom = isset($_GET['date_from']) ? trim((string)$_GET['date_from']) : '';

if ($hotel === '' || $dateFrom === '') {
    echo json_encode(['status' => 'error', 'message' => 'الفندق وتاريخ البداية مطلوبان.', 'results' => []]);
    exit;
}

try {
    $stmt = $pdo->prepare(
        'SELECT room_num, room_type, floor, date_from, date_to
         FROM room
         WHERE hotel_name = :h
           AND date(date_from) = date(:d)
         ORDER BY CAST(floor AS INTEGER), CAST(room_num AS INTEGER), room_num'
    );
    $stmt->execute([':h' => $hotel, ':d' => $dateFrom]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'status'  => 'ok',
        'count'   => count($rows),
        'results' => $rows,
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage(), 'results' => []]);
}
