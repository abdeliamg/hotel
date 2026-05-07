<?php
// Enable error reporting for all errors (including warnings and notices)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
require_once __DIR__ . '/../includes/paste_import.php';

session_start();
if (!isset($_COOKIE['master_group'])) {
    echo json_encode(['success' => false, 'message' => 'User not logged in.']);
    exit();
}

$pdo = new PDO('sqlite:../hajj_data.db');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Get master_group from cookie
$master_group = $_COOKIE['master_group'];

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
    $stmtRoom = $pdo->prepare("SELECT COUNT(*) FROM res WHERE hotel_name = :hotel_name AND floor = :floor AND room_num = :room_num AND group_name = :group_name");
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

        $stmtRoom->execute([
            ':hotel_name' => $item['hotel_name'],
            ':floor' => $item['floor'],
            ':room_num' => $item['room_num'],
            ':group_name' => $master_group
        ]);
        if ((int)$stmtRoom->fetchColumn() === 0) {
            $errors[] = ['row' => $idx + 1, 'message' => 'الغرفة غير متاحة لهذه المجموعة.'];
        }
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
            $stmt->execute([
                ':hotel_name' => trim((string)($row['hotel_name'] ?? '')),
                ':floor' => trim((string)($row['floor'] ?? '')),
                ':room_num' => trim((string)($row['room_num'] ?? '')),
                ':barcode' => trim((string)($row['barcode'] ?? '')),
                ':group_name' => $master_group,
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

    // Insert new row into hotel_pilgrim
    try {
        $stmt = $pdo->prepare("INSERT INTO hotel_pilgrim (hotel_name, floor, room_num, barcode, group_name, note)
                               VALUES (:hotel_name, :floor, :room_num, :barcode, :group_name, :note)");
        $stmt->bindParam(':hotel_name', $hotel_name);
        $stmt->bindParam(':floor', $floor);
        $stmt->bindParam(':room_num', $room_num);
        $stmt->bindParam(':barcode', $barcode);
        $stmt->bindParam(':group_name', $master_group);
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

    // Update the row
    try {
        $stmt = $pdo->prepare("UPDATE hotel_pilgrim SET hotel_name = :hotel_name, floor = :floor, room_num = :room_num, barcode = :barcode, note = :note
                               WHERE id = :id AND group_name = :group_name");
        $stmt->bindParam(':hotel_name', $hotel_name);
        $stmt->bindParam(':floor', $floor);
        $stmt->bindParam(':room_num', $room_num);
        $stmt->bindParam(':barcode', $barcode);
        $stmt->bindParam(':note', $note);
        $stmt->bindParam(':id', $id);
        $stmt->bindParam(':group_name', $master_group);
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
        $stmt = $pdo->prepare("DELETE FROM hotel_pilgrim WHERE id = :id AND group_name = :group_name");
        $stmt->bindParam(':id', $id);
        $stmt->bindParam(':group_name', $master_group);
        $stmt->execute();

        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
}
?>
