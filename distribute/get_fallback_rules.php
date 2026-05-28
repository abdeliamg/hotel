<?php
require_once __DIR__ . '/../check.php';
require_once __DIR__ . '/../includes/db.php';

header('Content-Type: application/json; charset=utf-8');

try {
    $stmt = $pdo->prepare("SELECT setting_value, updated_at FROM app_settings WHERE setting_key = ?");
    $stmt->execute(['distribute_fallback_rules']);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($row === false) {
        echo json_encode([
            'status' => 'ok',
            'rules' => null,
            'updated_at' => null,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $decoded = json_decode($row['setting_value'], true);
    if (!is_array($decoded)) {
        $decoded = null;
    }

    echo json_encode([
        'status' => 'ok',
        'rules' => $decoded,
        'updated_at' => $row['updated_at'],
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'فشل تحميل القواعد: ' . $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
}
