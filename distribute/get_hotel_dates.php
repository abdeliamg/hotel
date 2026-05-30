<?php
require_once __DIR__ . '/../check.php';
require_once __DIR__ . '/../includes/db.php';

header('Content-Type: application/json; charset=utf-8');

$hotel = isset($_GET['hotel']) ? trim((string)$_GET['hotel']) : '';

if ($hotel === '') {
    echo json_encode([
        'status' => 'error',
        'message' => 'الفندق مطلوب.',
        'results' => [],
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $stmt = $pdo->prepare(
        'SELECT date_from, COUNT(*) AS room_count
         FROM room
         WHERE hotel_name = :h
           AND date_from IS NOT NULL
           AND TRIM(date_from) != ""
         GROUP BY date(date_from)
         ORDER BY date(date_from)'
    );
    $stmt->execute([':h' => $hotel]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'status' => 'ok',
        'count' => count($rows),
        'results' => $rows,
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => $e->getMessage(),
        'results' => [],
    ], JSON_UNESCAPED_UNICODE);
}
