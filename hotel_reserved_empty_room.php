<?php
require_once __DIR__ . '/check.php';
// Database connection
require_once __DIR__ . '/includes/db.php';

// Reserved (active) rooms that currently have NO pilgrim assigned in hotel_pilgrim.
if (isset($_GET['hotel_name'])) {
    $hotel_name = $_GET['hotel_name'];

    $stmt = $pdo->prepare("SELECT DISTINCT res.room_num, res.floor, res.group_name, room.room_type
                           FROM res
                           JOIN room
                             ON room.hotel_name = res.hotel_name
                            AND room.room_num   = res.room_num
                           WHERE res.hotel_name = :hotel_name
                             AND DATE('now', 'localtime') BETWEEN res.start_date AND res.end_date
                             AND DATE('now', 'localtime') BETWEEN room.date_from AND room.date_to
                             AND res.room_num NOT IN (
                                 SELECT hp.room_num
                                 FROM hotel_pilgrim hp
                                 WHERE hp.hotel_name = res.hotel_name
                             )
                           ORDER BY res.room_num ASC");
    $stmt->execute(['hotel_name' => $hotel_name]);

    $rooms = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode($rooms);
} else {
    echo json_encode(['error' => 'Hotel name not provided']);
}
?>
