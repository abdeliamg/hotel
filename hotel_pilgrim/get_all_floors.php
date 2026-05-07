<?php
session_start();
if (!isset($_COOKIE['master_group'])) {
    exit();
}

$pdo = new PDO('sqlite:../hajj_data.db');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Get hotel_name and group_name from GET request
$hotel_name = $_GET['hotel_name'];
$group_name = $_GET['group_name'] || '';

$stmt = $pdo->prepare("SELECT DISTINCT floor FROM res WHERE hotel_name = :hotel_name");
//$stmt = $pdo->prepare("SELECT DISTINCT floor FROM res WHERE hotel_name = :hotel_name");
$stmt->bindParam(':hotel_name', $hotel_name);
$stmt->bindParam(':group_name', $group_name);
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
