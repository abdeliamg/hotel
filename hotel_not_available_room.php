<?php
require_once __DIR__ . '/check.php';
// Database connection
require_once __DIR__ . '/includes/db.php';

// Check if hotel_name is provided
if (isset($_GET['hotel_name'])) {
    $hotel_name = $_GET['hotel_name'];

    // Query to fetch available rooms
    $stmt = $pdo->prepare("SELECT * 
                           FROM res left join room on room.hotel_name = res.hotel_name and room.room_num = res.room_num
                           WHERE end_date > DATE('now') 
                           AND res.hotel_name = :hotel_name  order by room_num asc");
    $stmt->execute(['hotel_name' => $hotel_name]);

    // Fetch the results and return as JSON
    $rooms = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode($rooms);
} else {
    echo json_encode(['error' => 'Hotel name not provided']);
}
?>