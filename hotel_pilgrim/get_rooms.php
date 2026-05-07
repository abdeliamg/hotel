<?php
// Start session
session_start();
if (!isset($_COOKIE['master_group'])) {
    exit();
}

$pdo = new PDO('sqlite:../hajj_data.db');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Get hotel_name and floor from GET request
$hotel_name = $_GET['hotel_name'];
$floor = $_GET['floor'];

// Prepare the SQL statement to fetch room_num based on hotel_name and floor
$stmt = $pdo->prepare("SELECT room_num FROM res WHERE hotel_name = :hotel_name AND floor = :floor");
$stmt->bindParam(':hotel_name', $hotel_name);
$stmt->bindParam(':floor', $floor);
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
