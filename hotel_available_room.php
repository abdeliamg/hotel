<?php
require_once __DIR__ . '/check.php';
// Database connection
require_once __DIR__ . '/includes/db.php';

// Check if hotel_name is provided
if (isset($_GET['hotel_name'])) {
    $hotel_name = $_GET['hotel_name'];

    // Query to fetch available rooms
    $stmt = $pdo->prepare("SELECT *
                           FROM room
                           WHERE DATE('now', 'localtime') BETWEEN date_from AND date_to
                             AND room_num NOT IN (
                               SELECT room_num
                               FROM res
                               WHERE DATE('now', 'localtime') BETWEEN start_date AND end_date
                                 AND res.hotel_name = room.hotel_name
                             )
                             AND hotel_name = :hotel_name
                           ORDER BY room_num ASC");
    $stmt->execute(['hotel_name' => $hotel_name]);

    // Fetch the results and return as JSON
    $rooms = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode($rooms);
} else {
    echo json_encode(['error' => 'Hotel name not provided']);
}
?>