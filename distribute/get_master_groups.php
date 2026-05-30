<?php
require_once __DIR__ . '/../check.php';
require_once __DIR__ . '/../includes/db.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed'], JSON_UNESCAPED_UNICODE);
    exit;
}

$raw = file_get_contents('php://input');
$payload = json_decode($raw, true);

if (!is_array($payload) || !isset($payload['groups']) || !is_array($payload['groups'])) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'بيانات غير صالحة'], JSON_UNESCAPED_UNICODE);
    exit;
}

$groups = [];
foreach ($payload['groups'] as $g) {
    if (is_string($g) || is_numeric($g)) {
        $name = trim((string)$g);
        if ($name !== '') $groups[$name] = true;
    }
}
$groups = array_keys($groups);

$map = [];

if (count($groups) > 0) {
    try {
        // SQLite doesn't impose a hard cap on placeholders but chunk anyway to
        // avoid pathological cases on huge requests.
        $chunkSize = 500;
        for ($i = 0; $i < count($groups); $i += $chunkSize) {
            $chunk = array_slice($groups, $i, $chunkSize);
            $placeholders = implode(',', array_fill(0, count($chunk), '?'));
            $sql = "SELECT `group`, master_group FROM `group` WHERE `group` IN ($placeholders)";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($chunk);
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $map[$row['group']] = $row['master_group'] !== null ? (string)$row['master_group'] : '';
            }
        }
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode([
            'status' => 'error',
            'message' => 'فشل استعلام التكتلات: ' . $e->getMessage(),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

echo json_encode([
    'status' => 'ok',
    'map' => $map,
], JSON_UNESCAPED_UNICODE);
