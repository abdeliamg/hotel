<?php
require_once __DIR__ . '/../includes/auth.php';

$master_group = trim((string)($_COOKIE['master_group'] ?? ''));
$user = get_authenticated_user();

if ($master_group === '' && !$user) {
    http_response_code(403);
    exit();
}

// Get hotel_name and floor from GET request
$hotel_name = trim((string)($_GET['hotel_name'] ?? ''));
$floor = trim((string)($_GET['floor'] ?? ''));
$group_name = trim((string)($_GET['group_name'] ?? ''));

if ($hotel_name === '' || $floor === '') {
    header('Content-Type: application/json');
    echo json_encode(['results' => []]);
    exit();
}

if ($group_name === '' && $master_group !== '') {
    $group_name = $master_group;
}

// Prepare the SQL statement to fetch room_num based on hotel_name and floor
if ($group_name !== '') {
    $stmt = $pdo->prepare("SELECT DISTINCT room_num FROM res WHERE hotel_name = :hotel_name AND floor = :floor AND group_name = :group_name ORDER BY room_num");
    $stmt->bindValue(':hotel_name', $hotel_name);
    $stmt->bindValue(':floor', $floor);
    $stmt->bindValue(':group_name', $group_name);
} else {
    $stmt = $pdo->prepare("SELECT DISTINCT room_num FROM res WHERE hotel_name = :hotel_name AND floor = :floor ORDER BY room_num");
    $stmt->bindValue(':hotel_name', $hotel_name);
    $stmt->bindValue(':floor', $floor);
}
$stmt->execute();

// Fetch the room numbers
$rooms = $stmt->fetchAll(PDO::FETCH_COLUMN);

// Format the result for Select2
$response = array();
foreach ($rooms as $room) {
    $response[] = array('id' => $room, 'text' => $room);  // Format the room data for Select2
}

// Set Content-Type to application/json
header('Content-Type: application/json');

// Return the result in Select2's expected format
echo json_encode(array('results' => $response));  
?>
