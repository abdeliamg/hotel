<?php
require_once __DIR__ . '/check.php';
require_once __DIR__ . '/includes/root_nav.php';
require_once __DIR__ . '/includes/paste_import.php';
// res.php  (UPDATED)
// ---------------------------------
// Database connection
// ---------------------------------
require_once __DIR__ . '/includes/db.php';

// ---------------------------------
// Helpers
// ---------------------------------
function json_response(array $data, int $statusCode = 200): void {
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function normalize_date(?string $s): string {
    $normalized = normalize_import_date($s);
    return $normalized ?? '';
}

function date_less_equal(string $a, string $b): bool {
    return strtotime($a) <= strtotime($b);
}

/**
 * Check room availability window exists that fully covers the desired reservation range.
 * Match by hotel_name + floor + room_num.
 */
function is_in_availability(PDO $pdo, string $hotel, string $floor, string $room_num, string $start, string $end): bool {
    $sql = 'SELECT COUNT(*) FROM room
            WHERE hotel_name = :h AND floor = :f AND room_num = :r
              AND date(date_from) <= date(:start)
              AND date(date_to)   >= date(:end)';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':h' => $hotel, ':f' => $floor, ':r' => $room_num,
        ':start' => $start, ':end' => $end
    ]);
    return (int)$stmt->fetchColumn() > 0;
}

/**
 * Find overlapping reservation in res for the same hotel+floor+room.
 * Overlap condition: NOT (new_end < existing_start OR new_start > existing_end)
 */
function find_conflict(PDO $pdo, string $hotel, string $floor, string $room_num, string $start, string $end): ?array {
    $sql = 'SELECT id, hotel_name, floor, room_num, group_name, start_date, end_date, note
            FROM res
            WHERE hotel_name = :h AND floor = :f AND room_num = :r
              AND NOT (date(end_date) < date(:start) OR date(start_date) > date(:end))
            ORDER BY id DESC
            LIMIT 1';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':h'=>$hotel, ':f'=>$floor, ':r'=>$room_num,
        ':start'=>$start, ':end'=>$end
    ]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/**
 * Like find_conflict but excludes a specific reservation id (used by edit).
 */
function find_conflict_excluding_id(PDO $pdo, string $hotel, string $floor, string $room_num, string $start, string $end, int $excludeId): ?array {
    $sql = 'SELECT id, hotel_name, floor, room_num, group_name, start_date, end_date, note
            FROM res
            WHERE hotel_name = :h AND floor = :f AND room_num = :r
              AND id != :id
              AND NOT (date(end_date) < date(:start) OR date(start_date) > date(:end))
            ORDER BY id DESC
            LIMIT 1';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':h'=>$hotel, ':f'=>$floor, ':r'=>$room_num,
        ':start'=>$start, ':end'=>$end, ':id'=>$excludeId
    ]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

// ---------------------------------
// Add reservation (single)  -- NOW MIRRORS BULK RULES
// ---------------------------------
if (isset($_POST['action']) && $_POST['action'] === 'add') {
    $hotel_name = $_POST['hotel_name'] ?? '';
    $floor      = $_POST['floor'] ?? '';
    $room_num   = $_POST['room_num'] ?? '';
    $group_name = $_POST['group_name'] ?? '';
    $start_date = normalize_date($_POST['start_date'] ?? '');
    $end_date   = normalize_date($_POST['end_date'] ?? '');
    $note       = $_POST['note'] ?? '';

    $rowData = [
        'hotel_name' => $hotel_name,
        'floor'      => $floor,
        'room_num'   => $room_num,
        'group_name' => $group_name,
        'start_date' => $start_date,
        'end_date'   => $end_date,
        'note'       => $note
    ];

    // Validation (like bulk)
    if ($hotel_name==='' || $floor==='' || $room_num==='' || $group_name==='' || $start_date==='' || $end_date==='') {
        json_response([
            'status'  => 'invalid',
            'message' => 'بيانات ناقصة أو تنسيق التاريخ غير صحيح.',
            'data'    => $rowData
        ]);
    }
    if (!date_less_equal($start_date, $end_date)) {
        json_response([
            'status'  => 'invalid',
            'message' => 'تاريخ البدء أكبر من تاريخ الانتهاء.',
            'data'    => $rowData
        ]);
    }
    if (!is_in_availability($pdo, $hotel_name, $floor, $room_num, $start_date, $end_date)) {
        json_response([
            'status'  => 'out_of_range',
            'message' => 'خارج نطاق التوفّر لهذه الغرفة في هذا الفندق/الطابق.',
            'data'    => $rowData
        ]);
    }
    $conflict = find_conflict($pdo, $hotel_name, $floor, $room_num, $start_date, $end_date);
    if ($conflict) {
        json_response([
            'status'   => 'already_reserved',
            'message'  => 'الغرفة محجوزة مسبقًا ضمن هذه الفترة.',
            'existing' => $conflict,
            'data'     => $rowData
        ]);
    }

    // Insert
    try {
        $stmt = $pdo->prepare('INSERT INTO res (hotel_name, floor, room_num, group_name, start_date, end_date, note)
                               VALUES (?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([$hotel_name, $floor, $room_num, $group_name, $start_date, $end_date, $note]);
        json_response([
            'status'      => 'success',
            'message'     => 'تم الحجز بنجاح.',
            'inserted_id' => $pdo->lastInsertId(),
            'data'        => $rowData
        ]);
    } catch (PDOException $e) {
        json_response(['status' => 'error', 'message' => 'فشل في إضافة الحجز: ' . $e->getMessage()], 500);
    }
}

// ---------------------------------
// Edit reservation  -- safer (normalize + range + conflict excluding self)
// ---------------------------------
if (isset($_POST['action']) && $_POST['action'] === 'edit') {
    $res_id     = (int)($_POST['id'] ?? 0);
    $hotel_name = $_POST['hotel_name'] ?? '';
    $floor      = $_POST['floor'] ?? '';
    $room_num   = $_POST['room_num'] ?? '';
    $group_name = $_POST['group_name'] ?? '';
    $start_date = normalize_date($_POST['start_date'] ?? '');
    $end_date   = normalize_date($_POST['end_date'] ?? '');
    $note       = $_POST['note'] ?? '';

    if ($res_id<=0 || $hotel_name==='' || $floor==='' || $room_num==='' || $group_name==='' || $start_date==='' || $end_date==='') {
        json_response(['status' => 'error', 'message' => 'جميع الحقول مطلوبة.']);
    }
    if (!date_less_equal($start_date, $end_date)) {
        json_response(['status'=>'error','message'=>'تاريخ البدء أكبر من تاريخ الانتهاء.']);
    }
    if (!is_in_availability($pdo, $hotel_name, $floor, $room_num, $start_date, $end_date)) {
        json_response(['status'=>'error','message'=>'الفترة المطلوبة خارج نطاق التوفّر لهذه الغرفة.']);
    }
    $conflict = find_conflict_excluding_id($pdo, $hotel_name, $floor, $room_num, $start_date, $end_date, $res_id);
    if ($conflict) {
        json_response([
            'status'   => 'already_reserved',
            'message'  => 'هناك حجز آخر متعارض مع التعديلات.',
            'existing' => $conflict
        ]);
    }

    try {
        $stmt = $pdo->prepare('UPDATE res
                               SET hotel_name = ?, floor = ?, room_num = ?, group_name = ?, start_date = ?, end_date = ?, note = ?
                               WHERE id = ?');
        $stmt->execute([$hotel_name, $floor, $room_num, $group_name, $start_date, $end_date, $note, $res_id]);
        json_response(['status' => 'success', 'message' => 'تم تحديث الحجز بنجاح.']);
    } catch (PDOException $e) {
        json_response(['status' => 'error', 'message' => 'فشل في تحديث الحجز: ' . $e->getMessage()]);
    }
}

// ---------------------------------
// Delete reservation
// ---------------------------------
// Preview the cascade impact of deleting a single reservation. Returns the
// reservation info and any hotel_pilgrim rows that reference the same room
// so the user can decide whether to also wipe them.
if (isset($_POST['action']) && $_POST['action'] === 'delete_preview') {
    $res_id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
    if ($res_id <= 0) {
        json_response(['status' => 'error', 'message' => 'رقم الحجز مطلوب.']);
    }
    try {
        $resStmt = $pdo->prepare("SELECT id, hotel_name, floor, room_num, group_name, start_date, end_date FROM res WHERE id = ?");
        $resStmt->execute([$res_id]);
        $row = $resStmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            json_response(['status' => 'error', 'message' => 'لم يتم العثور على الحجز.']);
        }

        $hpStmt = $pdo->prepare(
            "SELECT hp.id AS hp_id, hp.barcode, hp.group_name, p.name
               FROM hotel_pilgrim hp
          LEFT JOIN pilgrim p ON p.barcode = hp.barcode
              WHERE hp.hotel_name = :h AND hp.floor = :f AND hp.room_num = :r
              ORDER BY p.name ASC, hp.id ASC"
        );
        $hpStmt->execute([':h' => $row['hotel_name'], ':f' => $row['floor'], ':r' => $row['room_num']]);
        $pilgrims = $hpStmt->fetchAll(PDO::FETCH_ASSOC);

        json_response([
            'status'      => 'ok',
            'reservation' => $row,
            'hp_count'    => count($pilgrims),
            'pilgrims'    => $pilgrims,
        ]);
    } catch (PDOException $e) {
        json_response(['status' => 'error', 'message' => 'فشل التحقق: ' . $e->getMessage()]);
    }
}

if (isset($_POST['action']) && $_POST['action'] === 'delete') {
    $res_id      = $_POST['id'] ?? '';
    $wipeRelated = !empty($_POST['wipe_related']) && $_POST['wipe_related'] !== '0';
    if ($res_id === '') {
        json_response(['status' => 'error', 'message' => 'رقم الحجز مطلوب.']);
    }

    try {
        $pdo->beginTransaction();

        $hpDeleted = 0;

        if ($wipeRelated) {
            $idStmt = $pdo->prepare("SELECT hotel_name, floor, room_num FROM res WHERE id = ?");
            $idStmt->execute([$res_id]);
            $ident = $idStmt->fetch(PDO::FETCH_ASSOC);
            if ($ident) {
                $delHp = $pdo->prepare(
                    "DELETE FROM hotel_pilgrim WHERE hotel_name = :h AND floor = :f AND room_num = :r"
                );
                $delHp->execute([':h' => $ident['hotel_name'], ':f' => $ident['floor'], ':r' => $ident['room_num']]);
                $hpDeleted = $delHp->rowCount();
            }
        }

        $stmt = $pdo->prepare('DELETE FROM res WHERE id = ?');
        $stmt->execute([$res_id]);
        $deleted = $stmt->rowCount();

        $pdo->commit();
        json_response([
            'status'       => 'success',
            'message'      => 'تم حذف الحجز بنجاح.',
            'deleted'      => $deleted,
            'hp_deleted'   => $hpDeleted,
            'wipe_related' => $wipeRelated,
        ]);
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        json_response(['status' => 'error', 'message' => 'فشل في حذف الحجز: ' . $e->getMessage()]);
    }
}

// ---------------------------------
// CSV template download (GET)
// ---------------------------------
if (isset($_GET['action']) && $_GET['action'] === 'download_template') {
    $filename = 'reservations_template_' . date('Ymd_His') . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-store, no-cache, must-revalidate');

    // UTF-8 BOM so Excel renders Arabic correctly
    echo "\xEF\xBB\xBF";

    $out = fopen('php://output', 'w');

    // Semicolon-separated; headers in Arabic (parser normalizes spaces to underscores and recognizes these aliases).
    $rows = [
        ['الفندق', 'الطابق', 'رقم الغرفة', 'التكتل', 'من', 'إلى', 'ملاحظات'],
        ['فندق المثال', '3', '305', 'تكتل النور',  '2025-01-10', '2025-01-15', 'قريب من المصعد'],
        ['فندق المثال', '4', '402', 'تكتل الفجر',  '2025-02-01', '2025-02-08', ''],
    ];
    foreach ($rows as $row) {
        fputcsv($out, $row, ';');
    }

    fclose($out);
    exit;
}

// ---------------------------------
// BULK Add reservations (Paste from Excel)  -- unchanged logic
// ---------------------------------
if (isset($_POST['action']) && $_POST['action'] === 'bulk_validate') {
    $parsed = parse_pasted_tsv(
        $_POST['rows'] ?? '',
        [
            'hotel_name' => ['hotel', 'الفندق'],
            'floor' => ['الطابق'],
            'room_num' => ['room'],
            'group_name' => ['التكتل', 'master_group', 'group', 'المجموعة'],
            'start_date' => ['date_from', 'من'],
            'end_date' => ['date_to', 'إلى'],
            'note' => ['ملاحظات'],
        ],
        ['hotel_name', 'floor', 'room_num', 'group_name', 'start_date', 'end_date', 'note']
    );

    if (!$parsed['ok']) {
        json_response(['status' => 'error', 'message' => $parsed['message']]);
    }

    // Load known hotel names once for cross-checking (case-insensitive)
    $knownHotels = [];
    foreach ($pdo->query("SELECT hotel_name FROM hotel")->fetchAll(PDO::FETCH_COLUMN) as $hn) {
        $knownHotels[mb_strtolower(trim((string)$hn), 'UTF-8')] = true;
    }

    // Load known master_group values once for cross-checking (case-insensitive).
    // The pasted "المجموعة/التكتل" cell is validated against group.master_group;
    // the matched value is stored as-is in res.group_name (res.group_name = group.master_group).
    $knownMasterGroups = [];
    foreach ($pdo->query('SELECT DISTINCT master_group FROM "group" WHERE master_group IS NOT NULL AND TRIM(master_group) <> ""')->fetchAll(PDO::FETCH_COLUMN) as $mg) {
        $knownMasterGroups[mb_strtolower(trim((string)$mg), 'UTF-8')] = true;
    }

    $errors = [];
    $normalizedRows = [];
    foreach ($parsed['rows'] as $idx => $row) {
        $normalized = [
            'hotel_name' => trim((string)($row['hotel_name'] ?? '')),
            'floor' => trim((string)($row['floor'] ?? '')),
            'room_num' => trim((string)($row['room_num'] ?? '')),
            'group_name' => trim((string)($row['group_name'] ?? '')),
            'start_date' => normalize_date($row['start_date'] ?? ''),
            'end_date' => normalize_date($row['end_date'] ?? ''),
            'note' => (string)($row['note'] ?? ''),
        ];
        $normalizedRows[] = $normalized;

        if ($normalized['hotel_name'] === '' || $normalized['floor'] === '' || $normalized['room_num'] === '' || $normalized['group_name'] === '' || $normalized['start_date'] === '' || $normalized['end_date'] === '') {
            $errors[] = ['row' => $idx + 1, 'message' => 'بيانات ناقصة أو تاريخ غير صالح.'];
            continue;
        }
        if (!date_less_equal($normalized['start_date'], $normalized['end_date'])) {
            $errors[] = ['row' => $idx + 1, 'message' => 'تاريخ البدء أكبر من تاريخ الانتهاء.'];
            continue;
        }
        if (!isset($knownHotels[mb_strtolower($normalized['hotel_name'], 'UTF-8')])) {
            $errors[] = [
                'row' => $idx + 1,
                'message' => 'اسم الفندق غير موجود في جدول الفنادق: ' . $normalized['hotel_name'],
            ];
        }
        if (!isset($knownMasterGroups[mb_strtolower($normalized['group_name'], 'UTF-8')])) {
            $errors[] = [
                'row' => $idx + 1,
                'message' => 'التكتل غير موجود في جدول المجموعات: ' . $normalized['group_name'],
            ];
        }
    }

    $rowsText = implode("\n", array_map(static function($r) {
        return implode("\t", [
            $r['hotel_name'], $r['floor'], $r['room_num'], $r['group_name'], $r['start_date'], $r['end_date'], $r['note']
        ]);
    }, $normalizedRows));

    json_response([
        'status' => 'ok',
        'errors' => $errors,
        'normalized_rows_text' => $rowsText,
        'count' => count($normalizedRows),
    ]);
}

if (isset($_POST['action']) && $_POST['action'] === 'bulk_add') {
    $rowsText = $_POST['rows'] ?? '';
    if (trim($rowsText) === '') {
        json_response(['status'=>'error','message'=>'لا توجد بيانات للمعالجة.']);
    }

    $lines = preg_split('/\r\n|\n|\r/', trim($rowsText));
    $results = [];
    $inserted = 0;

    // Defense-in-depth: enforce hotel existence here too.
    $knownHotels = [];
    foreach ($pdo->query("SELECT hotel_name FROM hotel")->fetchAll(PDO::FETCH_COLUMN) as $hn) {
        $knownHotels[mb_strtolower(trim((string)$hn), 'UTF-8')] = true;
    }
    // Defense-in-depth: enforce master_group existence (res.group_name = group.master_group).
    $knownMasterGroups = [];
    foreach ($pdo->query('SELECT DISTINCT master_group FROM "group" WHERE master_group IS NOT NULL AND TRIM(master_group) <> ""')->fetchAll(PDO::FETCH_COLUMN) as $mg) {
        $knownMasterGroups[mb_strtolower(trim((string)$mg), 'UTF-8')] = true;
    }

    // Prepare reusable statements
    $stmtInsert = $pdo->prepare('INSERT INTO res (hotel_name, floor, room_num, group_name, start_date, end_date, note)
                                 VALUES (:hotel, :floor, :room, :group, :start, :end, :note)');

    try {
        $pdo->beginTransaction();

        foreach ($lines as $idx => $line) {
            $lineTrim = trim($line);
            if ($lineTrim === '') continue;

            $cols = explode("\t", $lineTrim);
            // Expected: hotel_name, floor, room_num, group_name, start_date, end_date, note (optional)
            $cols = array_map('trim', $cols);
            $hotel_name = $cols[0] ?? '';
            $floor      = $cols[1] ?? '';
            $room_num   = $cols[2] ?? '';
            $group_name = $cols[3] ?? '';
            $start_date = normalize_date($cols[4] ?? '');
            $end_date   = normalize_date($cols[5] ?? '');
            $note       = $cols[6] ?? '';

            $rowData = [
                'hotel_name' => $hotel_name,
                'floor'      => $floor,
                'room_num'   => $room_num,
                'group_name' => $group_name,
                'start_date' => $start_date,
                'end_date'   => $end_date,
                'note'       => $note
            ];

            // Basic validation
            if ($hotel_name==='' || $floor==='' || $room_num==='' || $group_name==='' || $start_date==='' || $end_date==='') {
                $results[] = [
                    'row' => $idx+1,
                    'data'=> $rowData,
                    'status' => 'invalid',
                    'message'=> 'بيانات ناقصة أو تنسيق التاريخ غير صحيح.'
                ];
                continue;
            }
            if (!date_less_equal($start_date, $end_date)) {
                $results[] = [
                    'row' => $idx+1,
                    'data'=> $rowData,
                    'status' => 'invalid',
                    'message'=> 'تاريخ البدء أكبر من تاريخ الانتهاء.'
                ];
                continue;
            }

            if (!isset($knownHotels[mb_strtolower($hotel_name, 'UTF-8')])) {
                $results[] = [
                    'row' => $idx+1,
                    'data'=> $rowData,
                    'status' => 'invalid',
                    'message'=> 'اسم الفندق غير موجود في جدول الفنادق: ' . $hotel_name,
                ];
                continue;
            }

            if (!isset($knownMasterGroups[mb_strtolower($group_name, 'UTF-8')])) {
                $results[] = [
                    'row' => $idx+1,
                    'data'=> $rowData,
                    'status' => 'invalid',
                    'message'=> 'التكتل غير موجود في جدول المجموعات: ' . $group_name,
                ];
                continue;
            }

            // Availability check (must be fully inside one availability window)
            if (!is_in_availability($pdo, $hotel_name, $floor, $room_num, $start_date, $end_date)) {
                $results[] = [
                    'row' => $idx+1,
                    'data'=> $rowData,
                    'status' => 'out_of_range',
                    'message'=> 'خارج نطاق التوفّر لهذه الغرفة في هذا الفندق/الطابق.'
                ];
                continue;
            }

            // Conflict check
            $conflict = find_conflict($pdo, $hotel_name, $floor, $room_num, $start_date, $end_date);
            if ($conflict) {
                $results[] = [
                    'row' => $idx+1,
                    'data'=> $rowData,
                    'status' => 'already_reserved',
                    'message'=> 'الغرفة محجوزة مسبقًا ضمن هذه الفترة.',
                    'existing'=> $conflict
                ];
                continue;
            }

            // Insert
            $stmtInsert->execute([
                ':hotel'=>$hotel_name, ':floor'=>$floor, ':room'=>$room_num, ':group'=>$group_name,
                ':start'=>$start_date, ':end'=>$end_date, ':note'=>$note
            ]);
            $inserted++;
            $results[] = [
                'row' => $idx+1,
                'data'=> $rowData,
                'status' => 'success',
                'message'=> 'تم الحجز بنجاح.',
                'inserted_id' => $pdo->lastInsertId()
            ];
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        json_response([
            'status'=>'error',
            'message'=>'حدث خطأ أثناء المعالجة: ' . $e->getMessage()
        ], 500);
    }

    json_response([
        'status'   => 'ok',
        'inserted' => $inserted,
        'total'    => count($results),
        'results'  => $results
    ]);
}

// ---------------------------------
// Force replace (delete old and insert mine)
// ---------------------------------
if (isset($_POST['action']) && $_POST['action'] === 'force_replace') {
    $old_id    = $_POST['old_id'] ?? '';
    $hotel_name= $_POST['hotel_name'] ?? '';
    $floor     = $_POST['floor'] ?? '';
    $room_num  = $_POST['room_num'] ?? '';
    $group_name= $_POST['group_name'] ?? '';
    $start_date= normalize_date($_POST['start_date'] ?? '');
    $end_date  = normalize_date($_POST['end_date'] ?? '');
    $note      = $_POST['note'] ?? '';

    if ($old_id==='' || $hotel_name==='' || $floor==='' || $room_num==='' || $group_name==='' || $start_date==='' || $end_date==='') {
        json_response(['status'=>'error','message'=>'بيانات غير مكتملة لاستبدال الحجز.']);
    }
    if (!date_less_equal($start_date, $end_date)) {
        json_response(['status'=>'error','message'=>'تاريخ البدء أكبر من تاريخ الانتهاء.']);
    }
    // Validate availability again for safety
    if (!is_in_availability($pdo, $hotel_name, $floor, $room_num, $start_date, $end_date)) {
        json_response(['status'=>'error','message'=>'الفترة المطلوبة خارج نطاق التوفّر لهذه الغرفة.']);
    }

    try {
        $pdo->beginTransaction();
        // Delete old
        $del = $pdo->prepare('DELETE FROM res WHERE id = ?');
        $del->execute([$old_id]);

        // Ensure no new conflicts remain (another reservation could have been inserted in between)
        $conflict = find_conflict($pdo, $hotel_name, $floor, $room_num, $start_date, $end_date);
        if ($conflict) {
            $pdo->rollBack();
            json_response(['status'=>'error','message'=>'تعذر الاستبدال لأن هناك حجزاً آخراً متعارضاً الآن.']);
        }

        // Insert new
        $ins = $pdo->prepare('INSERT INTO res (hotel_name, floor, room_num, group_name, start_date, end_date, note)
                              VALUES (?, ?, ?, ?, ?, ?, ?)');
        $ins->execute([$hotel_name, $floor, $room_num, $group_name, $start_date, $end_date, $note]);
        $new_id = $pdo->lastInsertId();

        $pdo->commit();
        json_response(['status'=>'success','message'=>'تم الاستبدال بنجاح.','new_id'=>$new_id]);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        json_response(['status'=>'error','message'=>'فشل الاستبدال: '.$e->getMessage()]);
    }
}

// ---------------------------------
// BULK DELETE reservations by (hotel_name, end_date)
// ---------------------------------

// List distinct end_date values for a hotel, with counts. Powers the date
// dropdown in the bulk delete modal.
if (isset($_POST['action']) && $_POST['action'] === 'bulk_delete_dates') {
    $hotel = trim((string)($_POST['hotel'] ?? ''));
    if ($hotel === '') {
        json_response(['status' => 'error', 'message' => 'الفندق مطلوب.']);
    }
    try {
        $stmt = $pdo->prepare(
            "SELECT end_date, COUNT(*) AS cnt
               FROM res
              WHERE hotel_name = :h
                AND end_date IS NOT NULL
                AND TRIM(end_date) <> ''
              GROUP BY end_date
              ORDER BY date(end_date) ASC"
        );
        $stmt->execute([':h' => $hotel]);
        json_response([
            'status' => 'ok',
            'dates'  => $stmt->fetchAll(PDO::FETCH_ASSOC),
        ]);
    } catch (PDOException $e) {
        json_response(['status' => 'error', 'message' => 'فشل جلب التواريخ: ' . $e->getMessage()]);
    }
}

// Preview what bulk delete will touch. Reports reservations that will be
// removed and how many pilgrim assignments (hotel_pilgrim) reference the same
// rooms — those assignments are NOT deleted here, but the user must be aware.
if (isset($_POST['action']) && $_POST['action'] === 'bulk_delete_preview') {
    $hotel    = trim((string)($_POST['hotel']    ?? ''));
    $end_date = trim((string)($_POST['end_date'] ?? ''));
    if ($hotel === '' || $end_date === '') {
        json_response(['status' => 'error', 'message' => 'الفندق وتاريخ الانتهاء مطلوبان.']);
    }
    try {
        $resStmt = $pdo->prepare(
            "SELECT id, hotel_name, floor, room_num, group_name, start_date, end_date
               FROM res
              WHERE hotel_name = :h AND end_date = :d
              ORDER BY floor ASC, room_num ASC, id ASC"
        );
        $resStmt->execute([':h' => $hotel, ':d' => $end_date]);
        $reservations = $resStmt->fetchAll(PDO::FETCH_ASSOC);

        $hpCount = 0;
        if (!empty($reservations)) {
            // Match (hotel, floor, room_num) tuples in hotel_pilgrim. De-dup
            // tuples first so a hotel with many reservations on the same room
            // doesn't generate redundant OR clauses.
            $seen = [];
            $conds  = [];
            $params = [':h' => $hotel];
            $i = 0;
            foreach ($reservations as $r) {
                $key = $r['floor'] . '|' . $r['room_num'];
                if (isset($seen[$key])) continue;
                $seen[$key] = true;
                $conds[] = "(floor = :f{$i} AND room_num = :r{$i})";
                $params[":f{$i}"] = $r['floor'];
                $params[":r{$i}"] = $r['room_num'];
                $i++;
            }
            $orBlock = '(' . implode(' OR ', $conds) . ')';

            $hpStmt = $pdo->prepare(
                "SELECT COUNT(*) FROM hotel_pilgrim WHERE hotel_name = :h AND {$orBlock}"
            );
            $hpStmt->execute($params);
            $hpCount = (int)$hpStmt->fetchColumn();
        }

        json_response([
            'status'       => 'ok',
            'res_count'    => count($reservations),
            'hp_count'     => $hpCount,
            'reservations' => $reservations,
        ]);
    } catch (PDOException $e) {
        json_response(['status' => 'error', 'message' => 'فشل التحقق: ' . $e->getMessage()]);
    }
}

// Perform the deletion. By default only `res` rows are removed; pass
// wipe_related=1 to also drop matching hotel_pilgrim assignments so the
// rooms in the deleted reservations are vacated as well.
if (isset($_POST['action']) && $_POST['action'] === 'bulk_delete') {
    $hotel       = trim((string)($_POST['hotel']    ?? ''));
    $end_date    = trim((string)($_POST['end_date'] ?? ''));
    $wipeRelated = !empty($_POST['wipe_related']) && $_POST['wipe_related'] !== '0';
    if ($hotel === '' || $end_date === '') {
        json_response(['status' => 'error', 'message' => 'الفندق وتاريخ الانتهاء مطلوبان.']);
    }
    try {
        $pdo->beginTransaction();

        $hpDeleted = 0;

        if ($wipeRelated) {
            // Collect (floor, room_num) tuples of reservations about to be
            // removed, then delete the matching pilgrim assignments.
            $tupleStmt = $pdo->prepare(
                "SELECT DISTINCT floor, room_num FROM res
                  WHERE hotel_name = :h AND end_date = :d"
            );
            $tupleStmt->execute([':h' => $hotel, ':d' => $end_date]);
            $tuples = $tupleStmt->fetchAll(PDO::FETCH_ASSOC);

            if (!empty($tuples)) {
                $conds  = [];
                $params = [':h' => $hotel];
                foreach ($tuples as $i => $t) {
                    $conds[] = "(floor = :f{$i} AND room_num = :r{$i})";
                    $params[":f{$i}"] = $t['floor'];
                    $params[":r{$i}"] = $t['room_num'];
                }
                $orBlock = '(' . implode(' OR ', $conds) . ')';

                $delHp = $pdo->prepare(
                    "DELETE FROM hotel_pilgrim WHERE hotel_name = :h AND {$orBlock}"
                );
                $delHp->execute($params);
                $hpDeleted = $delHp->rowCount();
            }
        }

        $stmt = $pdo->prepare("DELETE FROM res WHERE hotel_name = :h AND end_date = :d");
        $stmt->execute([':h' => $hotel, ':d' => $end_date]);
        $deleted = $stmt->rowCount();

        $pdo->commit();
        json_response([
            'status'       => 'success',
            'message'      => 'تم حذف الحجوزات بنجاح.',
            'deleted'      => $deleted,
            'hp_deleted'   => $hpDeleted,
            'wipe_related' => $wipeRelated,
        ]);
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        json_response(['status' => 'error', 'message' => 'فشل الحذف: ' . $e->getMessage()]);
    }
}

// ---------------------------------
// DataTables server-side fetch
// ---------------------------------
if (isset($_GET['action']) && $_GET['action'] === 'fetch') {
    // DataTables parameters
    $draw   = isset($_POST['draw'])   ? (int)$_POST['draw']   : 0;
    $start  = isset($_POST['start'])  ? (int)$_POST['start']  : 0;
    $length = isset($_POST['length']) ? (int)$_POST['length'] : 10;
    $searchValue = $_POST['search']['value'] ?? '';
    $hotelFilter = trim((string)($_POST['hotel_filter'] ?? ''));

    // Map DataTables columns indices to safe SQL columns
    $columns = [
        0 => 'res.id',
        1 => 'res.hotel_name',
        2 => 'res.floor',
        3 => 'res.room_num',
        4 => 'r.room_type',
        5 => 'res.group_name',
        6 => 'g.master_group',
        7 => 'res.start_date',
        8 => 'res.end_date',
        9 => 'res.note'
    ];

    // Base FROM/JOIN
    // NOTE: room is keyed by (hotel_name, floor, room_num); joining without floor
    // duplicates res rows when the same room_num exists on multiple floors or has
    // multiple availability windows. Same care for the group join — pick one row.
    $baseFrom = ' FROM res
        LEFT JOIN "group" AS g
               ON g.master_group = res.group_name
        LEFT JOIN room AS r
               ON r.hotel_name = res.hotel_name
              AND r.floor      = res.floor
              AND r.room_num   = res.room_num ';

    // Total records (without filtering)
    $totalSql = 'SELECT COUNT(*) AS cnt FROM res';
    $totalRecords = (int)$pdo->query($totalSql)->fetchColumn();

    // Filtering
    $whereParts = [];
    $params = [];
    if ($hotelFilter !== '') {
        $whereParts[] = 'res.hotel_name = :hotel_filter';
        $params[':hotel_filter'] = $hotelFilter;
    }
    if ($searchValue !== '') {
        $whereParts[] = '(
            res.id LIKE :q OR
            res.hotel_name LIKE :q OR
            res.floor LIKE :q OR
            res.room_num LIKE :q OR
            r.room_type LIKE :q OR
            res.group_name LIKE :q OR
            g.master_group LIKE :q OR
            res.start_date LIKE :q OR
            res.end_date LIKE :q OR
            res.note LIKE :q
        )';
        $params[':q'] = '%' . $searchValue . '%';
    }
    $where = $whereParts ? ' WHERE ' . implode(' AND ', $whereParts) : '';

    // Records after filtering
    $filteredSql = 'SELECT COUNT(DISTINCT res.id) ' . $baseFrom . $where;
    $stmtFiltered = $pdo->prepare($filteredSql);
    foreach ($params as $k => $v) {
        $stmtFiltered->bindValue($k, $v, PDO::PARAM_STR);
    }
    $stmtFiltered->execute();
    $recordsFiltered = (int)$stmtFiltered->fetchColumn();

    // Ordering
    $orderClause = ' ORDER BY res.id DESC '; // default
    if (isset($_POST['order'][0]['column'], $_POST['order'][0]['dir'])) {
        $colIdx = (int)$_POST['order'][0]['column'];
        $dir    = strtolower($_POST['order'][0]['dir']) === 'asc' ? 'ASC' : 'DESC';
        if (array_key_exists($colIdx, $columns)) {
            $orderClause = ' ORDER BY ' . $columns[$colIdx] . ' ' . $dir . ' ';
        }
    }

    // Paging
    $limitClause = '';
    if ($length !== -1) {
        $limitClause = ' LIMIT :len OFFSET :st ';
    }

    // Data query
    // GROUP BY res.id is a defensive de-duplication in case any LEFT JOIN still
    // matches multiple rows (e.g. multiple availability windows for the same room).
    $dataSql = 'SELECT
            res.id    AS res_id,
            res.hotel_name,
            res.floor,
            res.room_num,
            MIN(r.room_type)    AS room_type,
            res.group_name,
            MIN(g.master_group) AS master_group,
            res.start_date,
            res.end_date,
            res.note ' .
        $baseFrom . $where . ' GROUP BY res.id ' . $orderClause . $limitClause;

    $stmt = $pdo->prepare($dataSql);
    foreach ($params as $k => $v) {
        $stmt->bindValue($k, $v, PDO::PARAM_STR);
    }
    if ($length !== -1) {
        $stmt->bindValue(':len', $length, PDO::PARAM_INT);
        $stmt->bindValue(':st',  $start,  PDO::PARAM_INT);
    }
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Build DataTables data rows
    $data = [];
    foreach ($rows as $row) {
        $actions = '<button class="btn btn-danger btn-sm delete-btn" data-id="' . htmlspecialchars($row['res_id']) . '">
                        <i class="bi bi-trash"></i> حذف
                    </button>';
        $data[] = [
            $row['res_id'],
            $row['hotel_name'],
            $row['floor'],
            $row['room_num'],
            $row['room_type'],
            $row['group_name'],
            $row['master_group'],
            $row['start_date'],
            $row['end_date'],
            $row['note'],
            $actions
        ];
    }

    json_response([
        'draw'            => $draw,
        'recordsTotal'    => $totalRecords,
        'recordsFiltered' => $recordsFiltered,
        'data'            => $data
    ]);
}

// Load hotels for page filter buttons and form dropdowns
try {
    $hotels = $pdo->query("SELECT id, hotel_name FROM hotel ORDER BY hotel_name ASC")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $hotels = [];
}

function h($v) { return htmlspecialchars($v ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
    <title>إدارة الحجوزات - نظام إدارة الفنادق</title>

    <!-- Bootstrap 5 -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <!-- DataTables CSS -->
    <link href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css" rel="stylesheet">
    <!-- Select2 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet">
    <!-- SweetAlert2 -->
    <link href="https://cdn.jsdelivr.net/npm/sweetalert2@11.0.20/dist/sweetalert2.min.css" rel="stylesheet">
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@300;400;500;700&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">

    <style>
        :root{
            --primary-color:#2563eb;
            --success-color:#10b981;
            --warning-color:#f59e0b;
            --danger-color:#ef4444;
            --dark-color:#1e293b;
            --light-bg:#f8fafc;
            --card-shadow:0 10px 30px rgba(0,0,0,.08);
            --border-radius:15px;
        }
        *{margin:0;padding:0;box-sizing:border-box}
        html,body{width:100%}
        body{
            font-family:'Tajawal','Inter',sans-serif;
            background: linear-gradient(135deg,#667eea 0%,#764ba2 100%);
            min-height:100vh;
            overflow-x:hidden;
        }

        /* Navbar */
        .navbar{
            background: rgba(30,41,59,.95);
            box-shadow: 0 4px 20px rgba(0,0,0,.15);
            padding: 16px 0;
            margin-bottom: 24px;
        }
        .navbar-brand{
            font-size:22px;font-weight:700;color:#fff!important;display:flex;align-items:center;gap:10px;text-decoration:none
        }
        .navbar-brand i{font-size:26px;color:#fbbf24}
        .back-btn{
            background: rgba(255,255,255,.08);
            border:1px solid rgba(255,255,255,.2);
            color:#fff;padding:8px 16px;border-radius:50px;text-decoration:none;display:inline-flex;align-items:center;gap:8px;
        }

        /* Layout */
        .main-container{max-width:1200px;margin:0 auto;padding:0 16px 40px}
        .page-header{
            background: rgba(255,255,255,.96);
            border-radius:20px;padding:28px;box-shadow:var(--card-shadow);text-align:center;margin-bottom:20px
        }
        .page-title{
            font-size:32px;font-weight:700;
            background: linear-gradient(135deg,#f59e0b 0%,#d97706 100%);
            -webkit-background-clip:text;background-clip:text;-webkit-text-fill-color:transparent;margin-bottom:8px
        }
        .page-subtitle{font-size:16px;color:#64748b}

        /* Add Buttons */
        .add-btn{
            background: linear-gradient(135deg,#10b981 0%,#059669 100%);
            color:#fff;border:none;padding:10px 22px;border-radius:50px;font-size:15px;font-weight:500;
            display:inline-flex;align-items:center;gap:10px;
            box-shadow:0 4px 12px rgba(16,185,129,.3);
        }
        .bulk-btn{
            background: linear-gradient(135deg,#3b82f6 0%,#2563eb 100%);
            color:#fff;border:none;padding:10px 22px;border-radius:50px;font-size:15px;font-weight:500;
            display:inline-flex;align-items:center;gap:10px;
            box-shadow:0 4px 12px rgba(37,99,235,.3);
        }

        /* Table Container */
        .table-container{
            background: rgba(255,255,255,.96);
            border-radius:20px;padding:20px;box-shadow:var(--card-shadow);
            overflow-x:auto;
        }

        /* DataTable */
        #reservationsTable{width:100%!important}
        .table{font-size:14px;table-layout:auto}
        .table thead th{
            background: #f1f5f9;color:#1e293b;font-weight:600;padding:14px 10px;border:none;text-align:center;
            white-space: normal;
        }
        .table tbody td{
            padding:12px 10px;vertical-align:middle;border-color:#e2e8f0;text-align:center;
            white-space: normal;word-break: break-word;
        }
        .table tbody tr:hover{background-color:#f8fafc}

        /* Action Buttons */
        .btn-sm{padding:6px 12px;font-size:13px;border-radius:20px;font-weight:500}
        .delete-btn{background: linear-gradient(135deg,#ef4444 0%,#dc2626 100%);border:none;color:#fff}
        .edit-btn{background: linear-gradient(135deg,#3b82f6 0%,#2563eb 100%);border:none;color:#fff}

        /* Modal */
        .modal-content{border:none;border-radius:20px;overflow:hidden}
        .modal-header{background: linear-gradient(135deg,#f59e0b 0%,#d97706 100%);color:#fff;border:none;padding:16px 22px}
        .modal-title{font-size:22px;font-weight:600}
        .modal-body{padding:22px}
        .modal-footer{background:#f8fafc;border:none;padding:16px 22px}

        /* Forms */
        .form-label{font-weight:600;color:#1e293b;margin-bottom:6px;font-size:14px}
        .form-control,.form-select{border:2px solid #e2e8f0;border-radius:10px;padding:10px 12px;font-size:14px}
        .form-control:focus,.form-select:focus{border-color:#f59e0b;box-shadow:0 0 0 .2rem rgba(245,158,11,.12)}
        textarea.form-control{min-height:100px;resize:vertical}

        /* Select2 */
        .select2-container{width:100%!important}
        .select2-container--default .select2-selection--single,
        .select2-container--default .select2-selection--multiple{
            border:2px solid #e2e8f0;border-radius:10px;min-height:42px;padding:4px 10px
        }
        .select2-dropdown{border:2px solid #e2e8f0;border-radius:10px;overflow:hidden}
        .select2-container--open{z-index:9999!important}

        /* DataTables chrome */
        .dataTables_wrapper .dataTables_paginate .paginate_button.current{
            background: linear-gradient(135deg,#f59e0b 0%,#d97706 100%);
            border:none;color:#fff!important;border-radius:5px
        }
        .dataTables_wrapper .dataTables_filter input{
            border:2px solid #e2e8f0;border-radius:10px;padding:5px 12px;margin-left:10px;margin-right:10px
        }
        .dataTables_wrapper .dataTables_length select{
            border:2px solid #e2e8f0;border-radius:10px;padding:5px 10px
        }

        .badge-status{
            font-size:12px;padding:6px 10px;border-radius:999px
        }
        .badge-success{background:#10b981;color:#fff}
        .badge-warning{background:#f59e0b;color:#fff}
        .badge-danger{background:#ef4444;color:#fff}
        .badge-info{background:#3b82f6;color:#fff}

        /* Responsive tweaks */
        @media (max-width:768px){
            .page-title{font-size:26px}
            .table{font-size:12px}
            .btn-sm{padding:5px 10px;font-size:12px}
            .modal-body{padding:16px}
        }

        /* Bulk-add format card */
        .format-card {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 14px 16px;
            margin-bottom: 14px;
        }
        .format-card .format-title {
            font-weight: 700;
            color: #0f172a;
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 10px;
            font-size: 14px;
        }
        .format-card .format-title i { color: #2563eb; }
        .format-card .format-actions { margin-inline-start: auto; }
        .format-cols {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
            gap: 8px;
            margin-bottom: 10px;
        }
        .format-col {
            background: #fff;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 8px 10px;
            font-size: 13px;
        }
        .format-col .col-label {
            font-weight: 600;
            color: #1e293b;
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .format-col .col-hint {
            font-size: 11px;
            color: #64748b;
            margin-top: 2px;
        }
        .format-col.required .col-label::after {
            content: '*';
            color: #dc2626;
            font-weight: 700;
        }
        .format-example {
            background: #ffffff;
            border: 1px dashed #cbd5e1;
            border-radius: 8px;
            padding: 8px 10px;
            font-size: 12px;
            color: #475569;
            direction: ltr;
            text-align: left;
            font-family: 'Consolas', 'Courier New', monospace;
            overflow-x: auto;
        }
        .format-example code { color: #0f172a; }
        .format-tip {
            margin-top: 8px;
            font-size: 12px;
            color: #64748b;
            display: flex;
            align-items: flex-start;
            gap: 6px;
        }
        .format-tip i { color: #f59e0b; margin-top: 2px; }

        .hotel-filter-bar {
            background: rgba(255,255,255,.96);
            border-radius: 16px;
            padding: 16px 18px;
            box-shadow: var(--card-shadow);
            margin-bottom: 16px;
        }
        .hotel-filter-bar .filter-label {
            font-weight: 700;
            color: #1e293b;
            margin-bottom: 10px;
            font-size: 15px;
            text-align: center;
        }
        .hotel-filter-buttons {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            justify-content: center;
        }
        .hotel-filter-btn {
            border-radius: 999px;
            padding: 7px 16px;
            font-size: 13px;
            white-space: normal;
            text-align: center;
            max-width: 100%;
            border: 2px solid #e2e8f0;
            background: #fff;
            color: #1e293b;
        }
        .hotel-filter-btn:hover {
            border-color: #f59e0b;
            color: #d97706;
        }
        .hotel-filter-btn.active {
            background: linear-gradient(135deg,#f59e0b 0%,#d97706 100%);
            border-color: transparent;
            color: #fff;
            box-shadow: 0 4px 12px rgba(245,158,11,.25);
        }
    </style>
</head>
<body>
    <div class="container-fluid pt-2">
        <?php render_root_navbar('reservations'); ?>
    </div>

  

    <div class="main-container">
        <!-- Page Header -->
        <div class="page-header">
            <h1 class="page-title">إدارة الحجوزات</h1>
            <p class="page-subtitle">عرض وإدارة جميع حجوزات الفنادق والغرف</p>
        </div>

        <!-- Add Buttons -->
        <div class="text-center mb-4 d-flex gap-2 justify-content-center flex-wrap">
            <button class="add-btn" data-bs-toggle="modal" data-bs-target="#addReservationModal">
                <i class="bi bi-plus-circle"></i>
                إضافة حجز جديد
            </button>
            <button class="bulk-btn" data-bs-toggle="modal" data-bs-target="#bulkAddModal">
                <i class="bi bi-clipboard-plus"></i>
                إضافة حجوزات بالجملة (من إكسل)
            </button>
            <button type="button" class="btn btn-danger" data-bs-toggle="modal" data-bs-target="#bulkDeleteResModal"
                style="border-radius:50px;padding:10px 22px;font-size:15px;font-weight:500;display:inline-flex;align-items:center;gap:10px;box-shadow:0 4px 12px rgba(239,68,68,.3);">
                <i class="bi bi-trash"></i>
                حذف حجوزات بالجملة
            </button>
        </div>

        <div class="hotel-filter-bar">
            <div class="filter-label">تصفية حسب الفندق</div>
            <div class="hotel-filter-buttons" id="hotelFilterButtons" role="group" aria-label="تصفية حسب الفندق">
                <button type="button" class="hotel-filter-btn active" data-hotel="">الكل</button>
                <?php foreach ($hotels as $hotel): ?>
                    <button type="button" class="hotel-filter-btn" data-hotel="<?= h($hotel['hotel_name']) ?>">
                        <?= h($hotel['hotel_name']) ?>
                    </button>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Table Container -->
        <div class="table-container">
            <table id="reservationsTable" class="table table-hover">
                <thead>
                    <tr>
                        <th>رقم الحجز</th>
                        <th>اسم الفندق</th>
                        <th>الطابق</th>
                        <th>رقم الغرفة</th>
                        <th>نوع الغرفة</th>
                        <th>اسم المجموعة</th>
                        <th>التكتل</th>
                        <th>تاريخ البدء</th>
                        <th>تاريخ الانتهاء</th>
                        <th>ملاحظات</th>
                        <th>الإجراءات</th>
                    </tr>
                </thead>
                <tbody></tbody>
            </table>
        </div>
    </div>

    <!-- Add Reservation Modal (single / existing) -->
    <div class="modal fade" id="addReservationModal" tabindex="-1" aria-labelledby="addReservationModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="addReservationModalLabel">
                        <i class="bi bi-plus-circle"></i>
                        إضافة حجز جديد
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="إغلاق"></button>
                </div>
                <div class="modal-body">
                    <form id="addReservationForm">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="hotelName" class="form-label">
                                    <i class="bi bi-building"></i> اسم الفندق
                                </label>
                                <select class="form-control" id="hotelName" required>
                                    <option value="">اختر فندق</option>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="floor" class="form-label">
                                    <i class="bi bi-layers"></i> الطابق
                                </label>
                                <select class="form-control" id="floor" required>
                                    <option value="">اختر طابق</option>
                                </select>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="roomNum" class="form-label">
                                    <i class="bi bi-door-closed"></i> رقم الغرفة
                                </label>
                                <select class="form-control" id="roomNum" multiple="multiple" required></select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="groupName" class="form-label">
                                    <i class="bi bi-people"></i> اسم المجموعة
                                </label>
                                <select class="form-control" id="groupName" required>
                                    <option value="">اختر مجموعة</option>
                                </select>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="startDate" class="form-label">
                                    <i class="bi bi-calendar-plus"></i> تاريخ البدء
                                </label>
                                <input type="date" class="form-control" id="startDate" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="endDate" class="form-label">
                                    <i class="bi bi-calendar-minus"></i> تاريخ الانتهاء
                                </label>
                                <input type="date" class="form-control" id="endDate" required>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label for="note" class="form-label">
                                <i class="bi bi-chat-text"></i> ملاحظات
                            </label>
                            <textarea class="form-control" id="note" rows="3" placeholder="أضف أي ملاحظات إضافية هنا..."></textarea>
                        </div>
                    </form>
                    <div class="alert alert-info mt-2">
                        يمكنك تحديد أكثر من غرفة لإدراج عدة حجوزات بنفس البيانات.<br>
                        عند وجود تعارضات أو فترات خارج التوفّر سيتم عرض <strong>نتائج مفصلة</strong> مع إمكانية <strong>الاستبدال</strong> للحجوزات المتعارضة، تمامًا كالمعالجة بالجملة.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="bi bi-x-circle"></i> إغلاق
                    </button>
                    <button type="button" class="btn btn-primary" id="saveReservationBtn">
                        <i class="bi bi-check-circle"></i> حفظ الحجز
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Bulk Delete Reservations Modal -->
    <div class="modal fade" id="bulkDeleteResModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header" style="background: linear-gradient(135deg,#ef4444 0%,#dc2626 100%);">
                    <h5 class="modal-title"><i class="bi bi-trash"></i> حذف حجوزات بالجملة</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="إغلاق"></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-warning small">
                        اختر الفندق ثم تاريخ الانتهاء. سيتم عرض عدد الحجوزات المتأثرة وأي إسكان حجاج مرتبط قبل تأكيد الحذف.
                    </div>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label for="bdrHotel" class="form-label">الفندق</label>
                            <select id="bdrHotel" class="form-select">
                                <option value="">اختر فندق...</option>
                                <?php foreach ($hotels as $hotel): ?>
                                    <option value="<?= h($hotel['hotel_name']) ?>"><?= h($hotel['hotel_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label for="bdrEndDate" class="form-label">تاريخ الانتهاء</label>
                            <select id="bdrEndDate" class="form-select" disabled>
                                <option value="">اختر الفندق أولاً</option>
                            </select>
                        </div>
                    </div>
                    <div id="bdrPreview" class="mt-3 d-none">
                        <hr>
                        <div id="bdrPreviewBody"></div>
                    </div>
                    <div class="form-check mt-3">
                        <input class="form-check-input" type="checkbox" id="bdrWipeRelated">
                        <label class="form-check-label" for="bdrWipeRelated">
                            حذف إسكان الحجاج المرتبط أيضاً (لتفادي البيانات المعلَّقة)
                        </label>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إغلاق</button>
                    <button type="button" class="btn btn-outline-primary" id="bdrCheckBtn" disabled>تحقق من التأثير</button>
                    <button type="button" class="btn btn-danger" id="bdrConfirmBtn" disabled>تأكيد الحذف</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Bulk Add Modal -->
    <div class="modal fade" id="bulkAddModal" tabindex="-1" aria-labelledby="bulkAddModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-xl">
            <div class="modal-content">
                <div class="modal-header" style="background: linear-gradient(135deg,#3b82f6 0%,#2563eb 100%);">
                    <h5 class="modal-title" id="bulkAddModalLabel">
                        <i class="bi bi-clipboard-plus"></i>
                        إضافة حجوزات بالجملة (لصق من إكسل)
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="إغلاق"></button>
                </div>
                <div class="modal-body">
                    <div class="format-card">
                        <div class="format-title d-flex flex-wrap align-items-center">
                            <i class="bi bi-table"></i>
                            <span>صيغة البيانات المتوقعة</span>
                            <div class="format-actions">
                                <a class="btn btn-success btn-sm" href="res.php?action=download_template" download>
                                    <i class="bi bi-download"></i>
                                    تنزيل ملف CSV نموذجي
                                </a>
                            </div>
                        </div>

                        <div class="format-cols">
                            <div class="format-col required">
                                <div class="col-label"><i class="bi bi-building"></i> الفندق</div>
                                <div class="col-hint">يجب أن يطابق اسم فندق موجود</div>
                            </div>
                            <div class="format-col required">
                                <div class="col-label"><i class="bi bi-layers"></i> الطابق</div>
                                <div class="col-hint">رقم الطابق</div>
                            </div>
                            <div class="format-col required">
                                <div class="col-label"><i class="bi bi-door-closed"></i> رقم الغرفة</div>
                                <div class="col-hint">رقم صحيح</div>
                            </div>
                            <div class="format-col required">
                                <div class="col-label"><i class="bi bi-diagram-3"></i> التكتل</div>
                                <div class="col-hint">يجب أن يطابق <code>master_group</code> في جدول المجموعات</div>
                            </div>
                            <div class="format-col required">
                                <div class="col-label"><i class="bi bi-calendar-event"></i> من</div>
                                <div class="col-hint">YYYY-MM-DD أو DD/MM/YYYY</div>
                            </div>
                            <div class="format-col required">
                                <div class="col-label"><i class="bi bi-calendar-check"></i> إلى</div>
                                <div class="col-hint">YYYY-MM-DD أو DD/MM/YYYY</div>
                            </div>
                            <div class="format-col">
                                <div class="col-label"><i class="bi bi-sticky"></i> ملاحظات</div>
                                <div class="col-hint">نص حر (اختياري)</div>
                            </div>
                        </div>

                        <div class="format-example">
                            <code>فندق المثال &nbsp; 3 &nbsp; 305 &nbsp; مجموعة النور &nbsp; 2025-01-10 &nbsp; 2025-01-15 &nbsp; قريب من المصعد</code>
                        </div>

                        <div class="format-tip">
                            <i class="bi bi-info-circle"></i>
                            <span>
                                نزِّل الملف النموذجي، عبّئه في Excel، ثم انسخ الصفوف (بما فيها صف العناوين) والصقها في الحقل أدناه.
                                الأعمدة المطلوبة موسومة بـ <span class="text-danger">*</span>.
                            </span>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label for="bulkRows" class="form-label"><i class="bi bi-clipboard"></i> الصق البيانات هنا:</label>
                        <textarea class="form-control" id="bulkRows" rows="10" placeholder="الصق الصفوف هنا..."></textarea>
                    </div>
                    <div class="text-end">
                        <button class="btn btn-secondary" data-bs-dismiss="modal"><i class="bi bi-x-circle"></i> إغلاق</button>
                        <button class="btn btn-outline-primary" id="validateBulkBtn"><i class="bi bi-check2-square"></i> تحقق</button>
                        <button class="btn btn-primary" id="processBulkBtn" disabled><i class="bi bi-cloud-upload"></i> إدراج بعد التحقق</button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Results Modal (used for both bulk & single) -->
    <div class="modal fade" id="bulkResultsModal" tabindex="-1" aria-labelledby="bulkResultsModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-xl">
            <div class="modal-content">
                <div class="modal-header" style="background: linear-gradient(135deg,#059669 0%,#10b981 100%);">
                    <h5 class="modal-title" id="bulkResultsModalLabel">
                        <i class="bi bi-list-check"></i>
                        نتائج المعالجة
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="إغلاق"></button>
                </div>
                <div class="modal-body">
                    <div class="table-responsive">
                        <table class="table table-sm align-middle">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>الفندق</th>
                                    <th>الطابق</th>
                                    <th>الغرفة</th>
                                    <th>المجموعة</th>
                                    <th>من</th>
                                    <th>إلى</th>
                                    <th>الحالة</th>
                                    <th>الإجراء</th>
                                </tr>
                            </thead>
                            <tbody id="bulkResultsBody"></tbody>
                        </table>
                    </div>
                    <div id="bulkSummary" class="mt-3"></div>
                </div>
                <div class="modal-footer">
                    <button class="btn btn-secondary" data-bs-dismiss="modal"><i class="bi bi-x-circle"></i> إغلاق</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Conflict Details Modal -->
    <div class="modal fade" id="conflictDetailsModal" tabindex="-1" aria-labelledby="conflictDetailsLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header" style="background: linear-gradient(135deg,#ef4444 0%,#dc2626 100%);">
                    <h5 class="modal-title" id="conflictDetailsLabel"><i class="bi bi-exclamation-triangle"></i> تفاصيل الحجز المتعارض</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="إغلاق"></button>
                </div>
                <div class="modal-body">
                    <div id="conflictDetailsBox" class="small"></div>
                </div>
                <div class="modal-footer">
                    <button class="btn btn-secondary" data-bs-dismiss="modal">إغلاق</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Scripts -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

    <script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>

    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11.0.20/dist/sweetalert2.min.js"></script>

    <script>
        $(function () {
            // Shared results cache (used by BOTH single & bulk flows)
            let resultsCache = [];
            let selectedHotelFilter = '';

            // DataTable (server-side)
            const table = $('#reservationsTable').DataTable({
                language: { url: 'https://cdn.datatables.net/plug-ins/1.13.6/i18n/ar.json' },
                serverSide: true,
                processing: true,
                ajax: {
                    url: 'res.php?action=fetch',
                    type: 'POST',
                    data: function (d) {
                        d.hotel_filter = selectedHotelFilter;
                    }
                },
                order: [[0, 'desc']], // default: latest first
                lengthMenu: [
                    [10, 25, 50, 100, 5000, -1],
                    [10, 25, 50, 100, '5000', 'الكل']
                ],
                pageLength: 10,
                scrollX: true,
                columnDefs: [
                    { targets: -1, orderable: false, searchable: false } // actions
                ]
            });
            $('#reservationsTable_filter input')
    .off() // remove default keyup binding
    .on('keypress', function (e) {
        if (e.which === 13) { // Enter key
            table.search(this.value).draw();
        }
    });

            $('#hotelFilterButtons').on('click', '.hotel-filter-btn', function() {
                const $btn = $(this);
                if ($btn.hasClass('active')) {
                    return;
                }
                $('#hotelFilterButtons .hotel-filter-btn').removeClass('active');
                $btn.addClass('active');
                selectedHotelFilter = String($btn.data('hotel') || '');
                table.ajax.reload();
            });

            // =========================
            // Bulk delete reservations by (hotel, end_date)
            // =========================
            const bdrEsc = (s) => (s ?? '').toString()
                .replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');

            function bdrReset(opts) {
                opts = opts || {};
                $('#bdrPreview').addClass('d-none');
                $('#bdrPreviewBody').empty();
                $('#bdrConfirmBtn').prop('disabled', true);
                if (opts.resetDates) {
                    $('#bdrEndDate')
                        .empty()
                        .append('<option value="">اختر الفندق أولاً</option>')
                        .prop('disabled', true);
                }
                $('#bdrCheckBtn').prop('disabled', !($('#bdrHotel').val() && $('#bdrEndDate').val()));
            }

            $('#bulkDeleteResModal').on('show.bs.modal', function() {
                $('#bdrHotel').val('');
                $('#bdrWipeRelated').prop('checked', false);
                bdrReset({ resetDates: true });
            });

            $('#bdrHotel').on('change', function() {
                const hotel = $(this).val();
                bdrReset({ resetDates: true });
                if (!hotel) return;
                $.ajax({
                    url: 'res.php',
                    method: 'POST',
                    dataType: 'json',
                    data: { action: 'bulk_delete_dates', hotel: hotel },
                    success: function(res) {
                        if (res.status !== 'ok') {
                            Swal.fire({ icon: 'error', title: 'خطأ', text: res.message || 'فشل جلب التواريخ.' });
                            return;
                        }
                        const $sel = $('#bdrEndDate').empty();
                        if (!res.dates || res.dates.length === 0) {
                            $sel.append('<option value="">لا توجد تواريخ متاحة لهذا الفندق</option>')
                                .prop('disabled', true);
                            return;
                        }
                        $sel.append('<option value="">اختر تاريخ الانتهاء...</option>');
                        res.dates.forEach(function(d) {
                            $sel.append(
                                '<option value="' + bdrEsc(d.end_date) + '">'
                                + bdrEsc(d.end_date) + ' (' + d.cnt + ' حجز)'
                                + '</option>'
                            );
                        });
                        $sel.prop('disabled', false);
                    },
                    error: function() {
                        Swal.fire({ icon: 'error', title: 'خطأ', text: 'تعذّر الاتصال بالخادم.' });
                    }
                });
            });

            $('#bdrEndDate').on('change', function() {
                bdrReset({ resetDates: false });
            });

            $('#bdrCheckBtn').on('click', function() {
                const hotel    = $('#bdrHotel').val();
                const end_date = $('#bdrEndDate').val();
                if (!hotel || !end_date) return;
                const $btn = $(this).prop('disabled', true).text('...جاري التحقق');
                $.ajax({
                    url: 'res.php',
                    method: 'POST',
                    dataType: 'json',
                    data: { action: 'bulk_delete_preview', hotel: hotel, end_date: end_date },
                    success: function(res) {
                        $btn.prop('disabled', false).text('تحقق من التأثير');
                        if (res.status !== 'ok') {
                            Swal.fire({ icon: 'error', title: 'خطأ', text: res.message || 'فشل التحقق.' });
                            return;
                        }
                        let html = ''
                            + '<div class="d-flex flex-wrap gap-2 mb-2">'
                            + '<span class="badge bg-danger">عدد الحجوزات للحذف: ' + res.res_count + '</span>'
                            + '<span class="badge bg-info text-dark">إسكان حجاج مرتبط: ' + res.hp_count + '</span>'
                            + '</div>';

                        if (res.res_count === 0) {
                            html += '<div class="alert alert-light border">لا توجد حجوزات مطابقة.</div>';
                        } else {
                            if (res.hp_count > 0) {
                                html += '<div class="alert alert-warning small">'
                                     + 'تنبيه: هناك حجاج مُسكَّنون في هذه الغرف. لن يتم حذف بيانات الإسكان تلقائياً.'
                                     + '</div>';
                            }
                            html += '<div class="small mb-1"><strong>الحجوزات المتأثرة:</strong></div>'
                                 + '<div style="max-height:200px;overflow:auto;border:1px solid #dee2e6;border-radius:6px;">'
                                 + '<table class="table table-sm table-striped mb-0" style="font-size:13px;">'
                                 + '<thead><tr><th>الطابق</th><th>رقم الغرفة</th><th>التكتل</th><th>من</th><th>إلى</th></tr></thead><tbody>';
                            (res.reservations || []).forEach(function(r) {
                                html += '<tr>'
                                      + '<td>' + bdrEsc(r.floor) + '</td>'
                                      + '<td>' + bdrEsc(r.room_num) + '</td>'
                                      + '<td>' + bdrEsc(r.group_name) + '</td>'
                                      + '<td>' + bdrEsc(r.start_date) + '</td>'
                                      + '<td>' + bdrEsc(r.end_date) + '</td>'
                                      + '</tr>';
                            });
                            html += '</tbody></table></div>';
                        }
                        $('#bdrPreviewBody').html(html);
                        $('#bdrPreview').removeClass('d-none');
                        $('#bdrConfirmBtn').prop('disabled', res.res_count === 0);
                    },
                    error: function() {
                        $btn.prop('disabled', false).text('تحقق من التأثير');
                        Swal.fire({ icon: 'error', title: 'خطأ', text: 'تعذّر الاتصال بالخادم.' });
                    }
                });
            });

            $('#bdrConfirmBtn').on('click', function() {
                const hotel    = $('#bdrHotel').val();
                const end_date = $('#bdrEndDate').val();
                if (!hotel || !end_date) return;
                const wipeRelated = $('#bdrWipeRelated').is(':checked');
                const $btn = $(this);
                const cascadeNote = wipeRelated
                    ? '<br><span class="text-danger"><b>تنبيه:</b> سيتم أيضاً حذف إسكان الحجاج المرتبط بهذه الغرف.</span>'
                    : '';
                Swal.fire({
                    icon: 'warning',
                    title: 'تأكيد الحذف',
                    html: 'سيتم حذف جميع الحجوزات في فندق <b>' + bdrEsc(hotel)
                        + '</b> التي تنتهي بتاريخ <b>' + bdrEsc(end_date) + '</b>.' + cascadeNote
                        + '<br>هل أنت متأكد؟',
                    showCancelButton: true,
                    confirmButtonText: 'نعم، احذف',
                    cancelButtonText: 'إلغاء',
                    confirmButtonColor: '#dc3545'
                }).then(function(result) {
                    if (!result.isConfirmed) return;
                    $btn.prop('disabled', true).text('...جاري الحذف');
                    $.ajax({
                        url: 'res.php',
                        method: 'POST',
                        dataType: 'json',
                        data: {
                            action: 'bulk_delete',
                            hotel: hotel,
                            end_date: end_date,
                            wipe_related: wipeRelated ? 1 : 0
                        },
                        success: function(res) {
                            $btn.prop('disabled', false).text('تأكيد الحذف');
                            if (res.status !== 'success') {
                                Swal.fire({ icon: 'error', title: 'خطأ', text: res.message || 'فشل الحذف.' });
                                return;
                            }
                            $('#bulkDeleteResModal').modal('hide');
                            table.ajax.reload(null, false);
                            let summary = 'تم حذف ' + (res.deleted || 0) + ' حجز.';
                            if (res.wipe_related) {
                                summary += '\nإسكان الحجاج المحذوف: ' + (res.hp_deleted || 0);
                            }
                            Swal.fire({ icon: 'success', title: 'تم الحذف', text: summary });
                        },
                        error: function() {
                            $btn.prop('disabled', false).text('تأكيد الحذف');
                            Swal.fire({ icon: 'error', title: 'خطأ', text: 'تعذّر الاتصال بالخادم.' });
                        }
                    });
                });
            });

            function renderResultsTable() {
                const tbody = $('#bulkResultsBody').empty();
                let successCount = 0, conflictCount = 0, oooCount = 0, invalidCount = 0;

                resultsCache.forEach((item, i) => {
                    let badgeClass = 'badge-info', badgeText = 'نتيجة';
                    let actionsHtml = '';

                    if (item.status === 'success') { badgeClass='badge-success'; badgeText='تم الحجز بنجاح'; successCount++; }
                    else if (item.status === 'already_reserved') {
                        badgeClass='badge-danger'; badgeText='محجوز مسبقًا'; conflictCount++;
                        actionsHtml =
                            `<div class="d-flex gap-2 justify-content-center">
                                <button class="btn btn-sm btn-outline-danger btn-show-conflict" data-idx="${i}">
                                    <i class="bi bi-eye"></i> عرض الحجز الحالي
                                </button>
                                <button class="btn btn-sm btn-danger btn-replace" data-idx="${i}">
                                    <i class="bi bi-arrow-repeat"></i> استبدال (حذف القديم وإدراج الجديد)
                                </button>
                            </div>`;
                    } else if (item.status === 'out_of_range') { badgeClass='badge-warning'; badgeText='خارج نطاق التوفّر'; oooCount++; }
                    else { badgeClass='badge-info'; badgeText='غير صالح'; invalidCount++; }

                    const d = item.data || {};
                    const tr = `<tr id="bulk-row-${i}">
                        <td>${item.row ?? (i+1)}</td>
                        <td>${d.hotel_name || ''}</td>
                        <td>${d.floor || ''}</td>
                        <td>${d.room_num || ''}</td>
                        <td>${d.group_name || ''}</td>
                        <td>${d.start_date || ''}</td>
                        <td>${d.end_date || ''}</td>
                        <td><span class="badge badge-status ${badgeClass}">${badgeText}</span><div class="small text-muted">${item.message || ''}</div></td>
                        <td>${actionsHtml}</td>
                    </tr>`;
                    tbody.append(tr);
                });

                $('#bulkSummary').html(
                    `<div class="alert alert-light border">
                        <strong>ملخص:</strong>
                        <span class="ms-2 badge badge-success">ناجح: ${successCount}</span>
                        <span class="ms-2 badge badge-danger">محجوز مسبقًا: ${conflictCount}</span>
                        <span class="ms-2 badge badge-warning">خارج التوفّر: ${oooCount}</span>
                        <span class="ms-2 badge badge-info">غير صالح: ${invalidCount}</span>
                    </div>`
                );

                $('#bulkResultsModal').modal('show');
                table.ajax.reload(null, false);
            }

            function destroySelect2($select) {
                if ($select.hasClass('select2-hidden-accessible')) {
                    $select.select2('destroy');
                }
            }

            function initFloorSelect(hotelName) {
                const $floor = $('#floor');
                destroySelect2($floor);
                $floor.val(null).empty().append('<option value="">اختر الطابق</option>');

                const config = {
                    placeholder: 'اختر الطابق',
                    width: '100%',
                    dir: 'rtl',
                    dropdownParent: $('#addReservationModal')
                };

                if (hotelName) {
                    config.ajax = {
                        url: 'res_floors.php',
                        dataType: 'json',
                        delay: 250,
                        data: () => ({ hotel: hotelName }),
                        processResults: data => ({ results: data.results })
                    };
                }

                $floor.select2(config);
            }

            function initRoomSelect(hotelName, floor) {
                const $room = $('#roomNum');
                destroySelect2($room);
                $room.val(null).empty();

                const config = {
                    placeholder: 'اختر رقم الغرفة',
                    width: '100%',
                    dir: 'rtl',
                    closeOnSelect: false,
                    dropdownParent: $('#addReservationModal')
                };

                if (hotelName && floor) {
                    config.ajax = {
                        url: 'res_rooms.php',
                        dataType: 'json',
                        delay: 250,
                        data: () => ({ hotel: hotelName, floor }),
                        processResults: data => ({ results: data.results })
                    };
                }

                $room.select2(config);
            }

            // Select2 initializations
            $('#hotelName').select2({
                placeholder: 'اختر فندق',
                width: '100%',
                dir: 'rtl',
                ajax: {
                    url: 'res_hotels.php',
                    dataType: 'json',
                    delay: 250,
                    data: params => ({ q: params.term || '', page: params.page || 1 }),
                    processResults: (data, params) => {
                        params.page = params.page || 1;
                        return { results: data.results, pagination: { more: data.pagination.more } };
                    }
                },
                dropdownParent: $('#addReservationModal')
            });

            initFloorSelect();
            initRoomSelect();

            $('#groupName').select2({
                placeholder: 'اختر المجموعة',
                width: '100%',
                dir: 'rtl',
                ajax: {
                    url: 'res_groups.php',
                    dataType: 'json',
                    delay: 250,
                    data: params => ({ q: params.term || '', page: params.page || 1 }),
                    processResults: (data, params) => {
                        params.page = params.page || 1;
                        return { results: data.results, pagination: { more: data.pagination.more } };
                    }
                },
                minimumInputLength: 1,
                dropdownParent: $('#addReservationModal')
            });

            // Dependent selects
            $('#hotelName').on('change', function () {
                const hotelName = $(this).val();
                initFloorSelect(hotelName);
                initRoomSelect();
            });

            $('#floor').on('change', function () {
                const hotelName = $('#hotelName').val();
                const floor = $(this).val();
                initRoomSelect(hotelName, floor);
            });

            // ------------------------------------
            // Add Reservation (multiple rooms) - now rule-matched with bulk
            // ------------------------------------
            $('#saveReservationBtn').on('click', function () {
                const hotelName = $('#hotelName').val();
                const floor     = $('#floor').val();
                const roomNums  = $('#roomNum').val(); // array
                const groupName = $('#groupName').val();
                const startDate = $('#startDate').val();
                const endDate   = $('#endDate').val();
                const note      = $('#note').val();

                if (!hotelName || !floor || !roomNums || !roomNums.length || !groupName || !startDate || !endDate) {
                    Swal.fire({ icon:'error', title:'خطأ', text:'يرجى ملء جميع الحقول المطلوبة', confirmButtonText:'حسناً' });
                    return;
                }

                let completed = 0, total = roomNums.length;
                resultsCache = []; // reset

                Swal.fire({ title:'جاري إضافة الحجوزات...', allowOutsideClick:false, didOpen:()=>Swal.showLoading() });

                roomNums.forEach((roomNum, idx) => {
                    $.post('res.php', {
                        action: 'add',
                        hotel_name: hotelName,
                        floor: floor,
                        room_num: roomNum,
                        group_name: groupName,
                        start_date: startDate,
                        end_date: endDate,
                        note: note
                    }).done(resp => {
                        const r = (typeof resp === 'string') ? JSON.parse(resp) : resp;
                        // Normalize into bulk-like item
                        const item = {
                            row: idx + 1,
                            data: {
                                hotel_name: hotelName,
                                floor: floor,
                                room_num: roomNum,
                                group_name: groupName,
                                start_date: startDate,
                                end_date: endDate,
                                note: note
                            },
                            status: r.status,
                            message: r.message || ''
                        };
                        if (r.existing) item.existing = r.existing;
                        if (r.inserted_id) item.inserted_id = r.inserted_id;
                        resultsCache.push(item);
                    }).always(() => {
                        completed++;
                        if (completed === total) {
                            Swal.close();
                            $('#addReservationModal').modal('hide');
                            renderResultsTable();
                        }
                    });
                });
            });

            // ------------------------------------
            // BULK ADD (Paste from Excel)
            // ------------------------------------
            let normalizedBulkRows = '';
            $('#validateBulkBtn').on('click', function(){
                const rows = $('#bulkRows').val().trim();
                if (!rows) {
                    Swal.fire({icon:'error', title:'لا توجد بيانات', text:'يرجى لصق الصفوف من إكسل أولاً.'});
                    return;
                }
                normalizedBulkRows = '';
                $('#processBulkBtn').prop('disabled', true);
                Swal.fire({ title:'جارٍ التحقق...', allowOutsideClick:false, didOpen:()=>Swal.showLoading() });
                $.post('res.php', { action:'bulk_validate', rows })
                    .done(function(resp){
                        Swal.close();
                        const r = (typeof resp === 'string') ? JSON.parse(resp) : resp;
                        if (r.status !== 'ok') {
                            Swal.fire({icon:'error', title:'خطأ', text: r.message || 'فشل التحقق.'});
                            return;
                        }
                        normalizedBulkRows = r.normalized_rows_text || '';
                        if (Array.isArray(r.errors) && r.errors.length > 0) {
                            const escapeHtml = (s) => $('<div>').text(s == null ? '' : String(s)).html();
                            const rowsHtml = r.errors.map(function(er){
                                return `<tr>
                                    <td style="white-space:nowrap;font-weight:600">سطر ${escapeHtml(er.row)}</td>
                                    <td style="text-align:right">${escapeHtml(er.message)}</td>
                                </tr>`;
                            }).join('');
                            Swal.fire({
                                icon: 'warning',
                                title: 'التحقق اكتمل مع وجود أخطاء',
                                html: `<div class="mb-2 text-muted">عدد الصفوف المتأثرة: <strong>${r.errors.length}</strong> من ${r.count || 0}. صحّح الأخطاء التالية ثم أعد التحقق:</div>
                                       <div style="max-height:320px;overflow:auto">
                                         <table class="table table-sm table-bordered mb-0" style="direction:rtl">
                                           <thead><tr><th style="white-space:nowrap">الصف</th><th>الخطأ</th></tr></thead>
                                           <tbody>${rowsHtml}</tbody>
                                         </table>
                                       </div>`,
                                width: 640,
                                confirmButtonText: 'حسناً'
                            });
                            return;
                        }
                        $('#processBulkBtn').prop('disabled', false);
                        Swal.fire({icon:'success', title:'تم التحقق', text:`${r.count || 0} صف جاهز للإدراج.`});
                    })
                    .fail(function(){
                        Swal.close();
                        Swal.fire({icon:'error', title:'خطأ', text:'تعذر الاتصال بالخادم.'});
                    });
            });

            $('#processBulkBtn').on('click', function(){
                const rows = normalizedBulkRows;
                if (!rows) {
                    Swal.fire({icon:'warning', title:'يلزم التحقق أولاً', text:'قم بالتحقق من البيانات قبل الإدراج.'});
                    return;
                }

                Swal.fire({ title:'جاري المعالجة...', allowOutsideClick:false, didOpen:()=>Swal.showLoading() });

                $.post('res.php', { action:'bulk_add', rows })
                    .done(function(resp){
                        Swal.close();
                        const r = (typeof resp === 'string') ? JSON.parse(resp) : resp;
                        if (r.status !== 'ok') {
                            Swal.fire({icon:'error', title:'خطأ', text: r.message || 'فشل المعالجة.'});
                            return;
                        }

                        resultsCache = r.results || [];
                        $('#bulkAddModal').modal('hide');
                        renderResultsTable();
                    })
                    .fail(function(){
                        Swal.close();
                        Swal.fire({icon:'error', title:'خطأ', text:'تعذر الاتصال بالخادم.'});
                    });
            });

            // Show conflict details (works for both single & bulk results)
            $(document).on('click', '.btn-show-conflict', function(){
                const idx = $(this).data('idx');
                const item = resultsCache[idx] || {};
                const ex = item.existing || {};
                const html =
                    `<ul class="list-group">
                        <li class="list-group-item d-flex justify-content-between"><strong>رقم الحجز</strong><span>${ex.id || '-'}</span></li>
                        <li class="list-group-item d-flex justify-content-between"><strong>الفندق</strong><span>${ex.hotel_name || ''}</span></li>
                        <li class="list-group-item d-flex justify-content-between"><strong>الطابق</strong><span>${ex.floor || ''}</span></li>
                        <li class="list-group-item d-flex justify-content-between"><strong>الغرفة</strong><span>${ex.room_num || ''}</span></li>
                        <li class="list-group-item d-flex justify-content-between"><strong>المجموعة</strong><span>${ex.group_name || ''}</span></li>
                        <li class="list-group-item d-flex justify-content-between"><strong>من</strong><span>${ex.start_date || ''}</span></li>
                        <li class="list-group-item d-flex justify-content-between"><strong>إلى</strong><span>${ex.end_date || ''}</span></li>
                        <li class="list-group-item"><strong>ملاحظات:</strong><br>${(ex.note || '').toString().replace(/\n/g,'<br>')}</li>
                    </ul>`;
                $('#conflictDetailsBox').html(html);
                $('#conflictDetailsModal').modal('show');
            });

            // Replace reservation (delete old & insert new)  -- both single & bulk results
            $(document).on('click', '.btn-replace', function(){
                const idx = $(this).data('idx');
                const item = resultsCache[idx] || {};
                const ex = item.existing || {};
                const d  = item.data || {};

                if (!ex || !ex.id) {
                    Swal.fire({icon:'error', title:'خطأ', text:'لا توجد بيانات استبدال متاحة.'});
                    return;
                }

                Swal.fire({
                    title: 'تأكيد الاستبدال',
                    html: 'سيتم حذف الحجز الحالي وإدراج الحجز الجديد مكانه.<br>هل تريد المتابعة؟',
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonText: 'نعم، استبدال',
                    cancelButtonText: 'إلغاء',
                    reverseButtons: true
                }).then(res => {
                    if (!res.isConfirmed) return;

                    Swal.fire({ title:'جارٍ الاستبدال...', allowOutsideClick:false, didOpen:()=>Swal.showLoading() });

                    $.post('res.php', {
                        action: 'force_replace',
                        old_id: ex.id,
                        hotel_name: d.hotel_name,
                        floor: d.floor,
                        room_num: d.room_num,
                        group_name: d.group_name,
                        start_date: d.start_date,
                        end_date: d.end_date,
                        note: d.note || ''
                    }).done(function(resp){
                        Swal.close();
                        const r = (typeof resp === 'string') ? JSON.parse(resp) : resp;
                        if (r.status === 'success') {
                            // Update UI row
                            const row = $(`#bulk-row-${idx}`);
                            row.find('td').eq(7).html(`<span class="badge badge-status badge-success">تم الحجز بعد الاستبدال</span><div class="small text-muted">تم حذف الحجز القديم وإدراج الجديد.</div>`);
                            row.find('td').eq(8).empty();
                            Swal.fire({icon:'success', title:'تم', text:'تم الاستبدال بنجاح.'});
                            table.ajax.reload(null, false);
                        } else {
                            Swal.fire({icon:'error', title:'خطأ', text: r.message || 'تعذر تنفيذ الاستبدال.'});
                        }
                    }).fail(function(){
                        Swal.close();
                        Swal.fire({icon:'error', title:'خطأ', text:'تعذر الاتصال بالخادم.'});
                    });
                });
            });

            // Delete Reservation (from table)
            $('#reservationsTable tbody').on('click', '.delete-btn', function () {
                const id = $(this).data('id');
                $.ajax({
                    url: 'res.php',
                    method: 'POST',
                    dataType: 'json',
                    data: { action: 'delete_preview', id: id }
                }).done(function(pv) {
                    if (pv.status !== 'ok') {
                        Swal.fire({ icon: 'error', title: 'خطأ', text: pv.message || 'تعذّر فحص التأثير.' });
                        return;
                    }
                    const esc = (s) => (s ?? '').toString()
                        .replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
                    const r = pv.reservation || {};
                    const hasLinked = pv.hp_count > 0;

                    let html = ''
                        + '<div style="text-align:right;direction:rtl;font-size:14px;">'
                        + '<p><b>الحجز:</b> ' + esc(r.hotel_name) + ' • طابق ' + esc(r.floor) + ' • غرفة ' + esc(r.room_num) + '<br>'
                        + '<b>التكتل:</b> ' + esc(r.group_name) + ' &nbsp; <b>من:</b> ' + esc(r.start_date) + ' &nbsp; <b>إلى:</b> ' + esc(r.end_date)
                        + '</p>'
                        + '<div class="d-flex flex-wrap gap-2 mb-2">'
                        + '<span class="badge bg-info text-dark">إسكان حجاج مرتبط: ' + pv.hp_count + '</span>'
                        + '</div>';

                    if (hasLinked) {
                        html += '<div class="small mb-1"><b>الحجاج المُسكَّنون:</b></div>'
                             + '<div style="max-height:160px;overflow:auto;border:1px solid #dee2e6;border-radius:6px;">'
                             + '<table class="table table-sm table-striped mb-0" style="font-size:13px;">'
                             + '<thead><tr><th>الاسم</th><th>الباركود</th></tr></thead><tbody>';
                        (pv.pilgrims || []).forEach(function(x) {
                            html += '<tr><td>' + esc(x.name || '—') + '</td><td>' + esc(x.barcode) + '</td></tr>';
                        });
                        html += '</tbody></table></div>'
                             + '<div class="form-check mt-3 text-end">'
                             + '<input class="form-check-input" type="checkbox" id="singleResDeleteWipe">'
                             + '<label class="form-check-label" for="singleResDeleteWipe">'
                             + ' حذف إسكان الحجاج المرتبط أيضاً'
                             + '</label></div>';
                    } else {
                        html += '<div class="alert alert-light border small mt-2">لا يوجد إسكان حجاج مرتبط.</div>';
                    }
                    html += '</div>';

                    Swal.fire({
                        title: 'تأكيد حذف الحجز',
                        html: html,
                        icon: 'warning',
                        showCancelButton: true,
                        confirmButtonText: 'نعم، حذف!',
                        cancelButtonText: 'إلغاء',
                        reverseButtons: true,
                        confirmButtonColor: '#ef4444',
                        cancelButtonColor: '#6c757d',
                        width: '720px',
                        focusCancel: true
                    }).then(function(result) {
                        if (!result.isConfirmed) return;
                        const wipe = hasLinked && $('#singleResDeleteWipe').is(':checked');
                        $.post('res.php', { action: 'delete', id: id, wipe_related: wipe ? 1 : 0 })
                            .done(function(resp) {
                                const r2 = (typeof resp === 'string') ? JSON.parse(resp) : resp;
                                if (r2.status === 'success') {
                                    let txt = 'تم حذف الحجز بنجاح.';
                                    if (r2.wipe_related) {
                                        txt += '\nإسكان الحجاج المحذوف: ' + (r2.hp_deleted || 0);
                                    }
                                    Swal.fire({ icon: 'success', title: 'تم الحذف!', text: txt })
                                        .then(function() { table.ajax.reload(null, false); });
                                } else {
                                    Swal.fire({ icon: 'error', title: 'خطأ!', text: r2.message || 'حدث خطأ أثناء الحذف.' });
                                }
                            })
                            .fail(function() { Swal.fire({ icon: 'error', title: 'خطأ!', text: 'تعذر الاتصال بالخادم.' }); });
                    });
                }).fail(function() {
                    Swal.fire({ icon: 'error', title: 'خطأ', text: 'تعذر الاتصال بالخادم.' });
                });
            });

            // Reset single-add form on close
            $('#addReservationModal').on('hidden.bs.modal', function () {
                $('#addReservationForm')[0].reset();
                $('#hotelName').val(null).trigger('change');
                $('#groupName').val(null).trigger('change');
            });

            // Clear bulk textarea on open/close
            $('#bulkAddModal').on('shown.bs.modal', function(){
                $('#bulkRows').trigger('focus');
            });
            $('#bulkAddModal').on('hidden.bs.modal', function(){
                $('#bulkRows').val('');
                normalizedBulkRows = '';
                $('#processBulkBtn').prop('disabled', true);
            });
        });
    </script>
</body>
</html>
