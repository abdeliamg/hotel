<?php
require_once __DIR__ . '/check.php';
// Ensure database connection is set up
require_once __DIR__ . '/includes/db.php';

// Get the selected hotel name from the request
$hotelName = isset($_GET['hotel']) ? $_GET['hotel'] : '';

// If no hotel name is provided, return an empty result
if (empty($hotelName)) {
    echo json_encode(['results' => []]);
    exit;
}

// Prepare the SQL query to fetch floors based on hotel name
$query = "SELECT DISTINCT floor FROM room WHERE hotel_name = :hotel";
$stmt = $pdo->prepare($query);
$stmt->bindValue(':hotel', $hotelName);
$stmt->execute();

// Fetch the results
$floors = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Prepare the response in the format Select2 expects
$response = [
    'results' => []
];

// Format the response with floors data
foreach ($floors as $floor) {
    $response['results'][] = [
        'id' => $floor['floor'],  // ID for the floor option
        'text' => $floor['floor']  // Floor name or label to display
    ];
}

// Return the JSON response
header('Content-Type: application/json');
echo json_encode($response);
?>
