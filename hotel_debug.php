<?php
// TEMP DEBUG: standalone runner for the hotel.php listing query and surrounding
// includes. Visit /hotel_debug.php directly; it prints everything as plain text
// with no styling. Delete this file once the 500 is fixed.

header('Content-Type: text/plain; charset=utf-8');
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

echo "=== hotel_debug.php ===\n";
echo "PHP version: " . PHP_VERSION . "\n";

try {
    echo "Including db.php...\n";
    require_once __DIR__ . '/includes/db.php';
    echo "db.php OK. PDO type: " . get_class($pdo) . "\n";

    echo "SQLite version: " . $pdo->query('SELECT sqlite_version()')->fetchColumn() . "\n";

    echo "PRAGMA table_info(group):\n";
    foreach ($pdo->query("PRAGMA table_info('group')") as $c) {
        echo "  - " . $c['name'] . ' ' . $c['type'] . "\n";
    }
    echo "PRAGMA table_info(room):\n";
    foreach ($pdo->query("PRAGMA table_info('room')") as $c) {
        echo "  - " . $c['name'] . ' ' . $c['type'] . "\n";
    }
    echo "PRAGMA table_info(res):\n";
    foreach ($pdo->query("PRAGMA table_info('res')") as $c) {
        echo "  - " . $c['name'] . ' ' . $c['type'] . "\n";
    }
    echo "PRAGMA table_info(hotel_pilgrim):\n";
    foreach ($pdo->query("PRAGMA table_info('hotel_pilgrim')") as $c) {
        echo "  - " . $c['name'] . ' ' . $c['type'] . "\n";
    }

    echo "\nRunning listing query...\n";
    $sql = "WITH
ActiveRooms AS (
    SELECT r.hotel_name, r.room_num, r.room_type
    FROM room r
    WHERE DATE('now', 'localtime') BETWEEN r.date_from AND r.date_to
),
RoomCounts AS (
    SELECT hotel_name,
           COUNT(room_num) AS total_rooms,
           SUM(room_type) AS total_beds
    FROM ActiveRooms
    GROUP BY hotel_name
),
ActiveReservations AS (
    SELECT res.hotel_name, res.room_num
    FROM res res
    WHERE DATE('now', 'localtime') BETWEEN res.start_date AND res.end_date
    GROUP BY res.hotel_name, res.room_num
),
ReservedRooms AS (
    SELECT ar.hotel_name,
           COUNT(ar.room_num) AS reserved_rooms,
           SUM(r.room_type) AS reserved_beds
    FROM ActiveReservations ar
    JOIN ActiveRooms r
      ON r.hotel_name = ar.hotel_name
     AND r.room_num   = ar.room_num
    GROUP BY ar.hotel_name
),
OccupiedRooms AS (
    SELECT DISTINCT hotel_name, room_num
    FROM hotel_pilgrim
),
ReservedEmptyRooms AS (
    SELECT ar.hotel_name,
           COUNT(ar.room_num) AS reserved_empty_rooms
    FROM ActiveReservations ar
    JOIN ActiveRooms r
      ON r.hotel_name = ar.hotel_name
     AND r.room_num   = ar.room_num
    LEFT JOIN OccupiedRooms o
      ON o.hotel_name = ar.hotel_name
     AND o.room_num   = ar.room_num
    WHERE o.room_num IS NULL
    GROUP BY ar.hotel_name
),
RoomPilgrimCounts AS (
    SELECT hotel_name, room_num, COUNT(*) AS pilgrim_count
    FROM hotel_pilgrim
    GROUP BY hotel_name, room_num
),
ReservedIncompleteRooms AS (
    SELECT ar.hotel_name,
           COUNT(ar.room_num) AS reserved_incomplete_rooms
    FROM ActiveReservations ar
    JOIN ActiveRooms r
      ON r.hotel_name = ar.hotel_name
     AND r.room_num   = ar.room_num
    JOIN RoomPilgrimCounts rpc
      ON rpc.hotel_name = ar.hotel_name
     AND rpc.room_num   = ar.room_num
    WHERE rpc.pilgrim_count > 0
      AND rpc.pilgrim_count < r.room_type
    GROUP BY ar.hotel_name
),
PilgrimsCount AS (
    SELECT hotel_name, COUNT(*) AS pilgrims
    FROM hotel_pilgrim
    GROUP BY hotel_name
)
SELECT h.hotel_name, h.address, h.note, h.id,
       COALESCE(rc.total_rooms, 0)                 AS total_rooms,
       COALESCE(rc.total_beds, 0)                  AS total_beds,
       COALESCE(rr.reserved_rooms, 0)              AS reserved_rooms,
       COALESCE(rc.total_rooms, 0) - COALESCE(rr.reserved_rooms, 0) AS available_rooms,
       COALESCE(rer.reserved_empty_rooms, 0)       AS reserved_empty_rooms,
       COALESCE(rir.reserved_incomplete_rooms, 0)  AS reserved_incomplete_rooms,
       COALESCE(rc.total_beds, 0)   - COALESCE(rr.reserved_beds, 0) AS available_beds,
       COALESCE(pc.pilgrims, 0)                    AS pilgrims_count
FROM hotel h
LEFT JOIN RoomCounts              rc  ON h.hotel_name = rc.hotel_name
LEFT JOIN ReservedRooms           rr  ON h.hotel_name = rr.hotel_name
LEFT JOIN ReservedEmptyRooms      rer ON h.hotel_name = rer.hotel_name
LEFT JOIN ReservedIncompleteRooms rir ON h.hotel_name = rir.hotel_name
LEFT JOIN PilgrimsCount           pc  ON h.hotel_name = pc.hotel_name";

    $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    echo "Query OK. Row count: " . count($rows) . "\n";
    if (!empty($rows)) {
        echo "First row keys: " . implode(', ', array_keys($rows[0])) . "\n";
    }
    echo "\nALL DIAGNOSTICS PASSED.\n";
} catch (Throwable $e) {
    echo "\nCAUGHT: " . get_class($e) . "\n";
    echo "Message: " . $e->getMessage() . "\n";
    echo "File: " . $e->getFile() . ":" . $e->getLine() . "\n";
    echo "Trace:\n" . $e->getTraceAsString() . "\n";
}
