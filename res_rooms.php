<?php
require_once __DIR__ . '/check.php';
// Ensure database connection is set up
require_once __DIR__ . '/includes/db.php';

// Get hotelName and floor from the request
$hotelName = isset($_GET['hotel']) ? $_GET['hotel'] : '';
$floor = isset($_GET['floor']) ? $_GET['floor'] : '';

// If no hotel or floor is provided, return an empty result
if (empty($hotelName) || empty($floor)) {
    echo json_encode(['results' => []]);
    exit;
}

// Prepare the SQL query to fetch room numbers based on hotel name and floor
$query = "SELECT 
    room.room_num AS id, 
    (room.room_num || ' | ' || room.room_type || 
     CASE 
        WHEN MAX(res.end_date) IS NOT NULL THEN ' | ' || MAX(res.end_date)
        ELSE ''
     END) AS text
FROM 
    room
LEFT JOIN 
    res ON res.hotel_name = :hotel AND res.room_num = room.room_num
WHERE 
    room.hotel_name = :hotel 
    AND room.floor = :floor
GROUP BY 
    room.room_num, room.room_type;";
$stmt = $pdo->prepare($query);
$stmt->bindValue(':hotel', $hotelName);
$stmt->bindValue(':floor', $floor);
$stmt->execute();

// Fetch the results
$rooms = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Prepare the response in the format Select2 expects
$response = [
    'results' => []
];

// Format the response with room numbers
foreach ($rooms as $room) {
    $response['results'][] = [
        'id' => $room['id'],  // Room number ID
        'text' => $room['text']  // Room number and type to display in dropdown
    ];
}

// Return the JSON response
header('Content-Type: application/json');
echo json_encode($response);
?>
