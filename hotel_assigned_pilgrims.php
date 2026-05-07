<?php
require_once __DIR__ . '/check.php';
// Database connection
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_log("PHP error logging is enabled.");
require_once __DIR__ . '/includes/db.php';

// Check if hotel_name is provided
if (isset($_GET['hotel_name'])) {
    $hotel_name = $_GET['hotel_name'];

    // Query to fetch available rooms
    //$stmt = $pdo->prepare("select count(*) as pilgrims,hotel_name,group_name from hotel_pilgrim where hotel_name = :hotel_name group by hotel_name, group_name  order by group_name asc");
  $stmt = $pdo->prepare('WITH ress AS (
    SELECT 
        r.hotel_name, 
        r.group_name AS m_group, 
        SUM(room.room_type) AS res_pilgrims
    FROM res r
    JOIN room ON r.room_num = room.room_num and  r.hotel_name=room.hotel_name
    GROUP BY r.hotel_name, m_group
)
SELECT 
    COUNT(*) AS pilgrims,
    hp.hotel_name,
    hp.group_name,
    COALESCE(ress.res_pilgrims, 0) AS total_pilgrims
FROM hotel_pilgrim hp
LEFT JOIN ress 
    ON ress.hotel_name = hp.hotel_name 
    AND ress.m_group = hp.group_name
WHERE hp.hotel_name = :hotel_name
GROUP BY hp.hotel_name, hp.group_name, ress.res_pilgrims
ORDER BY hp.group_name ASC;
');
    $stmt->execute(['hotel_name' => $hotel_name]);

    // Fetch the results and return as JSON
    $rooms = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode($rooms);
} else {
    echo json_encode(['error' => 'Hotel name not provided']);
}
?>