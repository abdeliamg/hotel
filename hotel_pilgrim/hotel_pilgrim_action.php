<?php
// Enable error reporting for all errors (including warnings and notices)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
require_once __DIR__ . '/../includes/paste_import.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/mg_cookie.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$master_group = mg_cookie_get();
$current_user = get_authenticated_user();

if ($master_group === '' && !$current_user) {
    echo json_encode(['success' => false, 'message' => 'User not logged in.']);
    exit();
}

function hotel_pilgrim_is_departed(PDO $pdo, string $barcode): bool
{
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM pilgrim_flight WHERE barcode = :barcode");
    $stmt->execute([':barcode' => $barcode]);
    return (int)$stmt->fetchColumn() > 0;
}

function hotel_pilgrim_is_assigned(PDO $pdo, string $barcode, int $excludeId = 0): bool
{
    $sql = "SELECT COUNT(*) FROM hotel_pilgrim WHERE barcode = :barcode";
    $params = [':barcode' => $barcode];

    if ($excludeId > 0) {
        $sql .= " AND id != :exclude_id";
        $params[':exclude_id'] = $excludeId;
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return (int)$stmt->fetchColumn() > 0;
}

function hotel_pilgrim_resolve_group(PDO $pdo, string $hotelName, string $floor, string $roomNum, string $masterGroup): ?string
{
    if ($masterGroup !== '') {
        $stmt = $pdo->prepare("SELECT group_name FROM res WHERE hotel_name = :hotel_name AND floor = :floor AND room_num = :room_num AND group_name = :group_name LIMIT 1");
        $stmt->execute([
            ':hotel_name' => $hotelName,
            ':floor' => $floor,
            ':room_num' => $roomNum,
            ':group_name' => $masterGroup,
        ]);
    } else {
        $stmt = $pdo->prepare("SELECT group_name FROM res WHERE hotel_name = :hotel_name AND floor = :floor AND room_num = :room_num ORDER BY date(end_date) DESC, id DESC LIMIT 1");
        $stmt->execute([
            ':hotel_name' => $hotelName,
            ':floor' => $floor,
            ':room_num' => $roomNum,
        ]);
    }

    $groupName = $stmt->fetchColumn();
    return $groupName === false ? null : (string)$groupName;
}

if ($_SERVER['REQUEST_METHOD'] == 'POST' && $_POST['action'] == 'import_validate') {
    $parsed = parse_pasted_tsv(
        $_POST['rows_text'] ?? '',
        [
            'hotel_name' => ['hotel', 'الفندق'],
            'floor' => ['الطابق'],
            'room_num' => ['room'],
            'barcode' => ['الباركود'],
            'note' => ['ملاحظات'],
        ],
        ['hotel_name', 'floor', 'room_num', 'barcode', 'note']
    );

    if (!$parsed['ok']) {
        echo json_encode(['success' => false, 'message' => $parsed['message']]);
        exit();
    }

    $errors = [];
    $rows = [];
    $stmtPilgrim = $pdo->prepare("SELECT COUNT(*) FROM pilgrim WHERE barcode = :barcode");
    $stmtDeparted = $pdo->prepare("SELECT COUNT(*) FROM pilgrim_flight WHERE barcode = :barcode");
    foreach ($parsed['rows'] as $idx => $row) {
        $item = [
            'hotel_name' => trim((string)($row['hotel_name'] ?? '')),
            'floor' => trim((string)($row['floor'] ?? '')),
            'room_num' => trim((string)($row['room_num'] ?? '')),
            'barcode' => trim((string)($row['barcode'] ?? '')),
            'note' => (string)($row['note'] ?? ''),
        ];
        $rows[] = $item;

        if ($item['hotel_name'] === '' || $item['floor'] === '' || $item['room_num'] === '' || $item['barcode'] === '') {
            $errors[] = ['row' => $idx + 1, 'message' => 'حقول مطلوبة ناقصة.'];
            continue;
        }

        $stmtPilgrim->execute([':barcode' => $item['barcode']]);
        if ((int)$stmtPilgrim->fetchColumn() === 0) {
            $errors[] = ['row' => $idx + 1, 'message' => 'الباركود غير موجود في جدول الحجاج.'];
            continue;
        }

        $stmtDeparted->execute([':barcode' => $item['barcode']]);
        if ((int)$stmtDeparted->fetchColumn() > 0) {
            $errors[] = ['row' => $idx + 1, 'message' => 'لا يمكن إضافة حاج تم ترحيله.'];
            continue;
        }
        if (hotel_pilgrim_is_assigned($pdo, $item['barcode'])) {
            $errors[] = ['row' => $idx + 1, 'message' => 'هذا الحاج مضاف إلى غرفة مسبقاً.'];
            continue;
        }

        $resolvedGroup = hotel_pilgrim_resolve_group($pdo, $item['hotel_name'], $item['floor'], $item['room_num'], $master_group);
        if ($resolvedGroup === null) {
            $errors[] = ['row' => $idx + 1, 'message' => 'الغرفة غير متاحة لهذه المجموعة.'];
            continue;
        }
        $rows[$idx]['group_name'] = $resolvedGroup;
    }

    echo json_encode(['success' => true, 'rows' => $rows, 'errors' => $errors]);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] == 'POST' && $_POST['action'] == 'import_batch') {
    $rows = json_decode($_POST['rows'] ?? '[]', true);
    if (!is_array($rows) || empty($rows)) {
        echo json_encode(['success' => false, 'message' => 'لا توجد بيانات للإدراج.']);
        exit();
    }

    $inserted = 0;
    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare("INSERT INTO hotel_pilgrim (hotel_name, floor, room_num, barcode, group_name, note)
                               VALUES (:hotel_name, :floor, :room_num, :barcode, :group_name, :note)");
        foreach ($rows as $row) {
            $hotelName = trim((string)($row['hotel_name'] ?? ''));
            $floor = trim((string)($row['floor'] ?? ''));
            $roomNum = trim((string)($row['room_num'] ?? ''));
            $barcode = trim((string)($row['barcode'] ?? ''));
            if (hotel_pilgrim_is_departed($pdo, $barcode)) {
                throw new RuntimeException('لا يمكن إضافة حاج تم ترحيله: ' . $barcode);
            }
            if (hotel_pilgrim_is_assigned($pdo, $barcode)) {
                throw new RuntimeException('هذا الحاج مضاف إلى غرفة مسبقاً: ' . $barcode);
            }
            $groupName = hotel_pilgrim_resolve_group($pdo, $hotelName, $floor, $roomNum, $master_group);
            if ($groupName === null) {
                throw new RuntimeException('الغرفة غير متاحة: ' . $hotelName . ' / ' . $floor . ' / ' . $roomNum);
            }
            $stmt->execute([
                ':hotel_name' => $hotelName,
                ':floor' => $floor,
                ':room_num' => $roomNum,
                ':barcode' => $barcode,
                ':group_name' => $groupName,
                ':note' => (string)($row['note'] ?? ''),
            ]);
            $inserted++;
        }
        $pdo->commit();
        echo json_encode(['success' => true, 'inserted' => $inserted]);
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit();
}

// Handle Create Action
if ($_SERVER['REQUEST_METHOD'] == 'POST' && $_POST['action'] == 'create') {
    $hotel_name = $_POST['hotel_name'];
    $floor = $_POST['floor'];
    $room_num = $_POST['room_num'];
    $barcode = $_POST['barcode'];
    $note = $_POST['note'];

    if (hotel_pilgrim_is_departed($pdo, (string)$barcode)) {
        echo json_encode(['success' => false, 'message' => 'لا يمكن إضافة حاج تم ترحيله.']);
        exit();
    }
    if (hotel_pilgrim_is_assigned($pdo, (string)$barcode)) {
        echo json_encode(['success' => false, 'message' => 'هذا الحاج مضاف إلى غرفة مسبقاً.']);
        exit();
    }

    $group_name = hotel_pilgrim_resolve_group($pdo, (string)$hotel_name, (string)$floor, (string)$room_num, $master_group);
    if ($group_name === null) {
        echo json_encode(['success' => false, 'message' => 'الغرفة غير متاحة أو لا توجد حجز مطابق.']);
        exit();
    }

    // Insert new row into hotel_pilgrim
    try {
        $stmt = $pdo->prepare("INSERT INTO hotel_pilgrim (hotel_name, floor, room_num, barcode, group_name, note)
                               VALUES (:hotel_name, :floor, :room_num, :barcode, :group_name, :note)");
        $stmt->bindParam(':hotel_name', $hotel_name);
        $stmt->bindParam(':floor', $floor);
        $stmt->bindParam(':room_num', $room_num);
        $stmt->bindParam(':barcode', $barcode);
        $stmt->bindParam(':group_name', $group_name);
        $stmt->bindParam(':note', $note);
        $stmt->execute();

        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
}

// Handle Edit Action
if ($_SERVER['REQUEST_METHOD'] == 'POST' && $_POST['action'] == 'edit') {
    $id = $_POST['id'];
    $hotel_name = $_POST['hotel_name'];
    $floor = $_POST['floor'];
    $room_num = $_POST['room_num'];
    $barcode = $_POST['barcode'];
    $note = $_POST['note'];

    if (hotel_pilgrim_is_departed($pdo, (string)$barcode)) {
        echo json_encode(['success' => false, 'message' => 'لا يمكن اختيار حاج تم ترحيله.']);
        exit();
    }
    if (hotel_pilgrim_is_assigned($pdo, (string)$barcode, (int)$id)) {
        echo json_encode(['success' => false, 'message' => 'هذا الحاج مضاف إلى غرفة أخرى مسبقاً.']);
        exit();
    }

    $group_name = hotel_pilgrim_resolve_group($pdo, (string)$hotel_name, (string)$floor, (string)$room_num, $master_group);
    if ($group_name === null) {
        echo json_encode(['success' => false, 'message' => 'الغرفة غير متاحة أو لا توجد حجز مطابق.']);
        exit();
    }

    // Update the row
    try {
        $where = $master_group !== '' ? "WHERE id = :id AND group_name = :current_group_name" : "WHERE id = :id";
        $stmt = $pdo->prepare("UPDATE hotel_pilgrim SET hotel_name = :hotel_name, floor = :floor, room_num = :room_num, barcode = :barcode, group_name = :group_name, note = :note
                               {$where}");
        $stmt->bindParam(':hotel_name', $hotel_name);
        $stmt->bindParam(':floor', $floor);
        $stmt->bindParam(':room_num', $room_num);
        $stmt->bindParam(':barcode', $barcode);
        $stmt->bindParam(':group_name', $group_name);
        $stmt->bindParam(':note', $note);
        $stmt->bindParam(':id', $id);
        if ($master_group !== '') {
            $stmt->bindParam(':current_group_name', $master_group);
        }
        $stmt->execute();

        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
}

// Handle Delete Action
if ($_SERVER['REQUEST_METHOD'] == 'POST' && $_POST['action'] == 'delete') {
    $id = $_POST['id'];

    // Delete the row
    try {
        $where = $master_group !== '' ? "WHERE id = :id AND group_name = :group_name" : "WHERE id = :id";
        $stmt = $pdo->prepare("DELETE FROM hotel_pilgrim {$where}");
        $stmt->bindParam(':id', $id);
        if ($master_group !== '') {
            $stmt->bindParam(':group_name', $master_group);
        }
        $stmt->execute();

        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
}
?>
