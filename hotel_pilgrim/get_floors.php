<?php
require_once __DIR__ . '/../includes/auth.php';

$master_group = trim((string)($_COOKIE['master_group'] ?? ''));
$user = get_authenticated_user();

if ($master_group === '' && !$user) {
    http_response_code(403);
    exit();
}

// Get hotel_name and optional group_name from GET request.
$hotel_name = trim((string)($_GET['hotel_name'] ?? ''));
$group_name = trim((string)($_GET['group_name'] ?? ''));

if ($hotel_name === '') {
    header('Content-Type: application/json');
    echo json_encode(['results' => []]);
    exit();
}

if ($group_name === '' && $master_group !== '') {
    $group_name = $master_group;
}

if ($group_name !== '') {
    $stmt = $pdo->prepare("SELECT DISTINCT floor FROM res WHERE hotel_name = :hotel_name AND group_name = :group_name ORDER BY floor");
    $stmt->bindValue(':hotel_name', $hotel_name);
    $stmt->bindValue(':group_name', $group_name);
} else {
    $stmt = $pdo->prepare("SELECT DISTINCT floor FROM res WHERE hotel_name = :hotel_name ORDER BY floor");
    $stmt->bindValue(':hotel_name', $hotel_name);
}
$stmt->execute();

$floors = $stmt->fetchAll(PDO::FETCH_COLUMN);

// Format the result for Select2
$response = array();
foreach ($floors as $floor) {
    $response[] = array('id' => $floor, 'text' => $floor);  // Format the floor data for Select2
}

// Set Content-Type to application/json
header('Content-Type: application/json');
echo json_encode(array('results' => $response));  // Return the result in Select2's expected format
?>
