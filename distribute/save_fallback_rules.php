<?php
require_once __DIR__ . '/../check.php';
require_once __DIR__ . '/../includes/db.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed'], JSON_UNESCAPED_UNICODE);
    exit;
}

// Only admins are allowed to modify the shared fallback rules.
$__current_user = $GLOBALS['current_user'] ?? null;
if (!$__current_user || !role_meets_requirement($__current_user['role'] ?? '', 'admin')) {
    http_response_code(403);
    echo json_encode([
        'status' => 'error',
        'message' => 'صلاحيات غير كافية: يجب أن تكون مديراً لتعديل قواعد البدائل.',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$raw = file_get_contents('php://input');
$payload = json_decode($raw, true);

if (!is_array($payload) || !isset($payload['rules']) || !is_array($payload['rules'])) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'بيانات غير صالحة'], JSON_UNESCAPED_UNICODE);
    exit;
}

// Normalize incoming rules: array of { from:int, to:[{type:int,count:int},...] }.
$normalized = [];
$seenFrom = [];
foreach ($payload['rules'] as $rule) {
    if (!is_array($rule)) continue;
    $from = isset($rule['from']) ? (int)$rule['from'] : 0;
    if ($from <= 0) continue;
    if (isset($seenFrom[$from])) continue;
    $seenFrom[$from] = true;

    $to = [];
    $seenBundle = [];
    if (isset($rule['to']) && is_array($rule['to'])) {
        foreach ($rule['to'] as $bundle) {
            $type = 0;
            $count = 1;
            if (is_array($bundle)) {
                $type = isset($bundle['type']) ? (int)$bundle['type'] : 0;
                $count = isset($bundle['count']) ? (int)$bundle['count'] : 1;
            } else {
                $type = (int)$bundle;
                $count = 1;
            }
            if ($type <= 0 || $count <= 0) continue;
            if ($type === $from && $count === 1) continue;
            $key = $type . ':' . $count;
            if (isset($seenBundle[$key])) continue;
            $seenBundle[$key] = true;
            $to[] = ['type' => $type, 'count' => $count];
        }
    }
    $normalized[] = ['from' => $from, 'to' => $to];
}

$json = json_encode($normalized, JSON_UNESCAPED_UNICODE);

try {
    $stmt = $pdo->prepare("
        INSERT INTO app_settings (setting_key, setting_value, updated_at)
        VALUES (?, ?, CURRENT_TIMESTAMP)
        ON CONFLICT(setting_key) DO UPDATE SET
            setting_value = excluded.setting_value,
            updated_at = CURRENT_TIMESTAMP
    ");
    $stmt->execute(['distribute_fallback_rules', $json]);

    $now = $pdo->query("SELECT updated_at FROM app_settings WHERE setting_key = 'distribute_fallback_rules'")
        ->fetchColumn();

    echo json_encode([
        'status' => 'ok',
        'message' => 'تم الحفظ بنجاح',
        'rules' => $normalized,
        'updated_at' => $now,
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'فشل الحفظ: ' . $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
}
