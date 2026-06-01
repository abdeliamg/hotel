<?php
// Enable error reporting for all errors (including warnings and notices)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
require_once __DIR__ . '/../includes/paste_import.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/mg_cookie.php';
require_once __DIR__ . '/room_access.php';

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
        $groupName = $stmt->fetchColumn();
        if ($groupName !== false) {
            return (string)$groupName;
        }

        // "All rooms" mode: if enabled for this تكتل and it is reserved in this
        // hotel, allow assigning to any room that exists in res for the hotel —
        // the record is stored under the master_group itself.
        if (group_can_use_all_rooms($pdo, $masterGroup, $hotelName)) {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM res WHERE hotel_name = :hotel_name AND floor = :floor AND room_num = :room_num");
            $stmt->execute([
                ':hotel_name' => $hotelName,
                ':floor' => $floor,
                ':room_num' => $roomNum,
            ]);
            if ((int)$stmt->fetchColumn() > 0) {
                return $masterGroup;
            }
        }
        return null;
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

/**
 * Resolve the (floor, group_name) for a (hotel, room) tuple in the context of
 * the logged-in master_group. Used by the "bulk assign" flow where the user
 * pastes only the room number; the floor is inferred from `res` reservations.
 *
 * Returns one of:
 *   ['ok' => true,  'floor' => string, 'group_name' => string]
 *   ['ok' => false, 'reason' => 'not_reserved']
 *   ['ok' => false, 'reason' => 'ambiguous_floor']
 */
function hotel_pilgrim_resolve_floor_and_group(PDO $pdo, string $hotelName, string $roomNum, string $masterGroup): array
{
    if ($masterGroup !== '') {
        $stmt = $pdo->prepare(
            'SELECT DISTINCT floor, group_name FROM res
              WHERE hotel_name = :h AND room_num = :r AND group_name = :g'
        );
        $stmt->execute([':h' => $hotelName, ':r' => $roomNum, ':g' => $masterGroup]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (count($rows) === 1) {
            return ['ok' => true, 'floor' => (string)$rows[0]['floor'], 'group_name' => (string)$rows[0]['group_name']];
        }
        if (count($rows) > 1) {
            return ['ok' => false, 'reason' => 'ambiguous_floor'];
        }

        if (group_can_use_all_rooms($pdo, $masterGroup, $hotelName)) {
            $stmt = $pdo->prepare(
                'SELECT DISTINCT floor FROM res WHERE hotel_name = :h AND room_num = :r'
            );
            $stmt->execute([':h' => $hotelName, ':r' => $roomNum]);
            $floors = $stmt->fetchAll(PDO::FETCH_COLUMN);
            if (count($floors) === 1) {
                return ['ok' => true, 'floor' => (string)$floors[0], 'group_name' => $masterGroup];
            }
            if (count($floors) > 1) {
                return ['ok' => false, 'reason' => 'ambiguous_floor'];
            }
        }
        return ['ok' => false, 'reason' => 'not_reserved'];
    }

    $stmt = $pdo->prepare(
        'SELECT DISTINCT floor, group_name FROM res WHERE hotel_name = :h AND room_num = :r'
    );
    $stmt->execute([':h' => $hotelName, ':r' => $roomNum]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (count($rows) === 1) {
        return ['ok' => true, 'floor' => (string)$rows[0]['floor'], 'group_name' => (string)$rows[0]['group_name']];
    }
    if (count($rows) > 1) {
        return ['ok' => false, 'reason' => 'ambiguous_floor'];
    }
    return ['ok' => false, 'reason' => 'not_reserved'];
}

// =====================================================================
// BULK ASSIGN (paste 3 columns: barcode, hotel_name, room_num)
// =====================================================================
// The single-add flow lets the user pick hotel → floor → room → barcode
// using cascading Select2 dropdowns. This flow accepts a pasted spreadsheet
// where the floor is intentionally omitted: it is resolved from `res` based
// on the logged-in master_group (or, for admins, any matching reservation).
// All four single-add checks are mirrored here:
//   - pilgrim exists
//   - pilgrim not departed (no pilgrim_flight row)
//   - pilgrim not already assigned in hotel_pilgrim
//   - the room is reserved (or "all_rooms" enabled) for this master_group
// Duplicate barcodes pasted within the same batch are also rejected.

if ($_SERVER['REQUEST_METHOD'] == 'POST' && $_POST['action'] == 'bulk_assign_validate') {
    $parsed = parse_pasted_tsv(
        $_POST['rows_text'] ?? '',
        [
            'barcode'    => ['الباركود', 'باركود'],
            'hotel_name' => ['hotel', 'الفندق'],
            'room_num'   => ['room', 'رقم الغرفة', 'رقم_الغرفة'],
        ],
        ['barcode', 'hotel_name', 'room_num']
    );

    if (!$parsed['ok']) {
        echo json_encode(['success' => false, 'message' => $parsed['message']]);
        exit();
    }

    $stmtPilgrim  = $pdo->prepare("SELECT COUNT(*) FROM pilgrim WHERE barcode = :barcode");
    $stmtDeparted = $pdo->prepare("SELECT COUNT(*) FROM pilgrim_flight WHERE barcode = :barcode");

    $rows         = [];
    $errors       = [];
    $seenBarcodes = [];
    $validCount   = 0;

    foreach ($parsed['rows'] as $idx => $row) {
        $rowNum = $idx + 1;
        $item = [
            'row'        => $rowNum,
            'barcode'    => trim((string)($row['barcode']    ?? '')),
            'hotel_name' => trim((string)($row['hotel_name'] ?? '')),
            'room_num'   => trim((string)($row['room_num']   ?? '')),
            'floor'      => '',
            'group_name' => '',
            'status'     => 'invalid',
            'message'    => '',
        ];

        if ($item['barcode'] === '' || $item['hotel_name'] === '' || $item['room_num'] === '') {
            $item['message'] = 'حقول مطلوبة ناقصة (الباركود، الفندق، رقم الغرفة).';
            $errors[] = ['row' => $rowNum, 'message' => $item['message']];
            $rows[] = $item;
            continue;
        }

        $bcKey = mb_strtolower($item['barcode'], 'UTF-8');
        if (isset($seenBarcodes[$bcKey])) {
            $item['message'] = 'تم تكرار هذا الباركود في صف سابق ضمن نفس اللصق.';
            $errors[] = ['row' => $rowNum, 'message' => $item['message']];
            $rows[] = $item;
            continue;
        }
        $seenBarcodes[$bcKey] = true;

        $stmtPilgrim->execute([':barcode' => $item['barcode']]);
        if ((int)$stmtPilgrim->fetchColumn() === 0) {
            $item['message'] = 'الباركود غير موجود في جدول الحجاج.';
            $errors[] = ['row' => $rowNum, 'message' => $item['message']];
            $rows[] = $item;
            continue;
        }

        $stmtDeparted->execute([':barcode' => $item['barcode']]);
        if ((int)$stmtDeparted->fetchColumn() > 0) {
            $item['message'] = 'لا يمكن إضافة حاج تم ترحيله.';
            $errors[] = ['row' => $rowNum, 'message' => $item['message']];
            $rows[] = $item;
            continue;
        }

        if (hotel_pilgrim_is_assigned($pdo, $item['barcode'])) {
            $item['message'] = 'هذا الحاج مضاف إلى غرفة مسبقاً.';
            $errors[] = ['row' => $rowNum, 'message' => $item['message']];
            $rows[] = $item;
            continue;
        }

        $resolved = hotel_pilgrim_resolve_floor_and_group($pdo, $item['hotel_name'], $item['room_num'], $master_group);
        if (!$resolved['ok']) {
            if (($resolved['reason'] ?? '') === 'ambiguous_floor') {
                $item['message'] = 'رقم الغرفة موجود في أكثر من طابق لهذا الفندق — يتعذر تحديد الطابق تلقائياً.';
            } else {
                $item['message'] = 'الغرفة غير محجوزة لهذه المجموعة في هذا الفندق.';
            }
            $errors[] = ['row' => $rowNum, 'message' => $item['message']];
            $rows[] = $item;
            continue;
        }

        $item['floor']      = $resolved['floor'];
        $item['group_name'] = $resolved['group_name'];
        $item['status']     = 'ok';
        $item['message']    = 'صالح';
        $item['capacity_warning'] = null;
        $rows[] = $item;
        $validCount++;
    }

    // ---------------------------------------------------------------
    // Capacity hints (non-blocking). For each (hotel, floor, room) that has at
    // least one OK row, compare the projected pilgrim count (already-assigned
    // + this batch) against the room's capacity (room.room_type). Rows in the
    // same room receive a friendly note explaining the mismatch — they remain
    // status='ok' so the user can still proceed.
    // ---------------------------------------------------------------
    $roomKeys       = [];
    $incomingByRoom = [];
    foreach ($rows as $r) {
        if (($r['status'] ?? '') !== 'ok') continue;
        $key = $r['hotel_name'] . '|' . $r['floor'] . '|' . $r['room_num'];
        $roomKeys[$key]        = ['h' => $r['hotel_name'], 'f' => $r['floor'], 'r' => $r['room_num']];
        $incomingByRoom[$key]  = ($incomingByRoom[$key] ?? 0) + 1;
    }

    $capacityByRoom = [];
    $existingByRoom = [];
    if (!empty($roomKeys)) {
        $capStmt = $pdo->prepare(
            "SELECT MIN(room_type) FROM room
              WHERE hotel_name = :h AND floor = :f AND room_num = :r"
        );
        $existStmt = $pdo->prepare(
            "SELECT COUNT(*) FROM hotel_pilgrim
              WHERE hotel_name = :h AND floor = :f AND room_num = :r"
        );
        foreach ($roomKeys as $key => $t) {
            $capStmt->execute([':h' => $t['h'], ':f' => $t['f'], ':r' => $t['r']]);
            $cap = $capStmt->fetchColumn();
            $capacityByRoom[$key] = ($cap === false || $cap === null) ? null : (int)$cap;

            $existStmt->execute([':h' => $t['h'], ':f' => $t['f'], ':r' => $t['r']]);
            $existingByRoom[$key] = (int)$existStmt->fetchColumn();
        }
    }

    $warningsCount = 0;
    foreach ($rows as &$r) {
        if (($r['status'] ?? '') !== 'ok') continue;
        $key       = $r['hotel_name'] . '|' . $r['floor'] . '|' . $r['room_num'];
        $cap       = $capacityByRoom[$key] ?? null;
        $existing  = $existingByRoom[$key] ?? 0;
        $incoming  = $incomingByRoom[$key] ?? 0;
        $projected = $existing + $incoming;

        if ($cap === null || $cap <= 0) {
            continue; // No capacity info — skip the hint.
        }
        if ($projected > $cap) {
            $r['capacity_warning'] = [
                'level'   => 'over',
                'message' => "تجاوز السعة: المتوقع {$projected} حاج لغرفة سعتها {$cap}.",
            ];
            $warningsCount++;
        } elseif ($projected < $cap) {
            $r['capacity_warning'] = [
                'level'   => 'under',
                'message' => "أقل من السعة: المتوقع {$projected} حاج لغرفة سعتها {$cap}.",
            ];
            $warningsCount++;
        }
    }
    unset($r);

    echo json_encode([
        'success'         => true,
        'rows'            => $rows,
        'errors'          => $errors,
        'valid_count'     => $validCount,
        'total'           => count($rows),
        'all_valid'       => empty($errors) && !empty($rows),
        'warnings_count'  => $warningsCount,
    ], JSON_UNESCAPED_UNICODE);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] == 'POST' && $_POST['action'] == 'bulk_assign_commit') {
    $rows = json_decode($_POST['rows'] ?? '[]', true);
    if (!is_array($rows) || empty($rows)) {
        echo json_encode(['success' => false, 'message' => 'لا توجد بيانات للإدراج.']);
        exit();
    }

    $inserted = 0;
    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare(
            "INSERT INTO hotel_pilgrim (hotel_name, floor, room_num, barcode, group_name, note)
             VALUES (:hotel_name, :floor, :room_num, :barcode, :group_name, :note)"
        );

        $seenBarcodes = [];
        foreach ($rows as $row) {
            $barcode    = trim((string)($row['barcode']    ?? ''));
            $hotelName  = trim((string)($row['hotel_name'] ?? ''));
            $roomNum    = trim((string)($row['room_num']   ?? ''));

            if ($barcode === '' || $hotelName === '' || $roomNum === '') {
                throw new RuntimeException('حقول مطلوبة ناقصة في الصفوف المرسلة.');
            }
            $bcKey = mb_strtolower($barcode, 'UTF-8');
            if (isset($seenBarcodes[$bcKey])) {
                throw new RuntimeException('باركود مكرر: ' . $barcode);
            }
            $seenBarcodes[$bcKey] = true;

            // Re-validate each row at commit time (defensive: data could have
            // changed between the validate call and the commit click).
            if (hotel_pilgrim_is_departed($pdo, $barcode)) {
                throw new RuntimeException('لا يمكن إضافة حاج تم ترحيله: ' . $barcode);
            }
            if (hotel_pilgrim_is_assigned($pdo, $barcode)) {
                throw new RuntimeException('هذا الحاج مضاف إلى غرفة مسبقاً: ' . $barcode);
            }
            $resolved = hotel_pilgrim_resolve_floor_and_group($pdo, $hotelName, $roomNum, $master_group);
            if (!$resolved['ok']) {
                throw new RuntimeException('الغرفة غير متاحة: ' . $hotelName . ' / ' . $roomNum);
            }

            $stmt->execute([
                ':hotel_name' => $hotelName,
                ':floor'      => $resolved['floor'],
                ':room_num'   => $roomNum,
                ':barcode'    => $barcode,
                ':group_name' => $resolved['group_name'],
                ':note'       => (string)($row['note'] ?? ''),
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
