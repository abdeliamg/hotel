<?php
require_once __DIR__ . '/check.php';
require_once __DIR__ . '/includes/root_nav.php';
require_once __DIR__ . '/includes/paste_import.php';
// room.php

// ========================
// Database connection
// ========================
require_once __DIR__ . '/includes/db.php';

// ========================
// Helpers
// ========================
function json_out($arr) {
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode($arr, JSON_UNESCAPED_UNICODE);
    exit;
}

function h($v) { return htmlspecialchars($v ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

/**
 * Normalize common date formats to Y-m-d; allow null/empty.
 * Accepts Y-m-d, d-m-Y, d/m/Y, m-d-Y, m/d/Y, d-m-y, d/m/y
 */
function normalizeDate($value) {
    $value = trim((string)$value);
    if ($value === '' || strtolower($value) === 'null' || strtolower($value) === 'nil') {
        return null;
    }
    $value = str_replace('/', '-', $value);
    $formats = ['Y-m-d', 'd-m-Y', 'm-d-Y', 'd-m-y', 'm-d-y'];
    foreach ($formats as $fmt) {
        $dt = DateTime::createFromFormat($fmt, $value);
        if ($dt && $dt->format($fmt) === $value) {
            return $dt->format('Y-m-d');
        }
    }
    // Last chance parsing
    try {
        $dt = new DateTime($value);
        return $dt->format('Y-m-d');
    } catch (Exception $e) {
        return $value;
    }
}

/** Max date with NULL treated as "infinite" (so max(null, x) = null / open-ended) */
function dateMaxNullable($a, $b) {
    if ($a === null || $a === '') return null;
    if ($b === null || $b === '') return null;
    return strcmp($a, $b) >= 0 ? $a : $b;
}

/**
 * Find overlapping room row for (room_num, hotel_name) against new [date_from, date_to].
 * Treat NULLs as open-ended (from = 0001-01-01, to = 9999-12-31)
 * Returns the single best match (largest date_to).
 */
function findOverlappingRoom(PDO $pdo, $room_num, $hotel_name, $new_from, $new_to) {
    $stmt = $pdo->prepare("
        SELECT * FROM room
        WHERE room_num = :room_num
          AND hotel_name = :hotel_name
          AND COALESCE(date_from, '0001-01-01') <= COALESCE(:new_to, '9999-12-31')
          AND COALESCE(date_to,   '9999-12-31') >= COALESCE(:new_from, '0001-01-01')
        ORDER BY COALESCE(date_to, '9999-12-31') DESC
        LIMIT 1
    ");
    $stmt->execute([
        ':room_num'   => $room_num,
        ':hotel_name' => $hotel_name,
        ':new_from'   => $new_from,
        ':new_to'     => $new_to,
    ]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

// ========================
// DataTables server-side endpoint
// ========================
if (isset($_POST['action']) && $_POST['action'] === 'datatable') {
    // Columns we expose to the grid (in this order)
    $columns = ['id','room_num','room_type','hotel_name','floor','date_from','date_to','contract','note'];

    // draw/start/length
    $draw   = (int)($_POST['draw'] ?? 0);
    $start  = max(0, (int)($_POST['start'] ?? 0));
    $length = (int)($_POST['length'] ?? 10);
    if ($length === -1) { $length = 1000000; } // return "all" if requested

    // Global search
    $searchValue = trim((string)($_POST['search']['value'] ?? ''));

    // Ordering (default to id DESC)
    $orderCol = 'id';
    $orderDir = 'DESC';
    if (isset($_POST['order'][0]['column'])) {
        $idx = (int)$_POST['order'][0]['column'];
        // Bound index to our known columns list
        if ($idx >= 0 && $idx < count($columns)) {
            $orderCol = $columns[$idx];
        }
    }
    if (isset($_POST['order'][0]['dir'])) {
        $dir = strtolower($_POST['order'][0]['dir']);
        $orderDir = ($dir === 'asc') ? 'ASC' : 'DESC';
    }

    // recordsTotal
    $recordsTotal = (int)$pdo->query("SELECT COUNT(*) FROM room")->fetchColumn();

    // Filtering
    $whereSql = '';
    $params = [];
    if ($searchValue !== '') {
        $likes = [];
        foreach ($columns as $i => $col) {
            $ph = ":s{$i}";
            $likes[] = "$col LIKE $ph";
            $params[$ph] = '%' . $searchValue . '%';
        }
        $whereSql = 'WHERE ' . implode(' OR ', $likes);
    }

    // recordsFiltered
    if ($whereSql) {
        $stmtCnt = $pdo->prepare("SELECT COUNT(*) FROM room $whereSql");
        foreach ($params as $k => $v) { $stmtCnt->bindValue($k, $v, PDO::PARAM_STR); }
        $stmtCnt->execute();
        $recordsFiltered = (int)$stmtCnt->fetchColumn();
    } else {
        $recordsFiltered = $recordsTotal;
    }

    // Data query
    $sql = "SELECT " . implode(',', $columns) . " FROM room $whereSql ORDER BY $orderCol $orderDir LIMIT :limit OFFSET :offset";
    $stmt = $pdo->prepare($sql);
    foreach ($params as $k => $v) { $stmt->bindValue($k, $v, PDO::PARAM_STR); }
    $stmt->bindValue(':limit', $length, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $start, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Build rows with actions column
    $data = [];
    foreach ($rows as $r) {
        $actionsHtml = '
            <button class="btn btn-success btn-sm edit-btn"
                data-bs-toggle="modal" data-bs-target="#editRoomModal"
                data-id="'.h($r['id']).'"
                data-room_num="'.h($r['room_num']).'"
                data-room_type="'.h($r['room_type']).'"
                data-hotel="'.h($r['hotel_name']).'"
                data-floor="'.h($r['floor']).'"
                data-date_from="'.h($r['date_from']).'"
                data-date_to="'.h($r['date_to']).'"
                data-contract="'.h($r['contract']).'"
                data-note="'.h($r['note']).'">
                تعديل
            </button>
            <button class="btn btn-danger btn-sm delete-btn"
                data-bs-toggle="modal" data-bs-target="#deleteRoomModal"
                data-id="'.h($r['id']).'">
                حذف
            </button>
        ';

        $data[] = [
            'id'         => (int)$r['id'],
            'room_num'   => $r['room_num'],
            'room_type'  => $r['room_type'],
            'hotel_name' => $r['hotel_name'],
            'floor'      => $r['floor'],
            'date_from'  => $r['date_from'],
            'date_to'    => $r['date_to'],
            'contract'   => $r['contract'],
            'note'       => $r['note'],
            'actions'    => $actionsHtml
        ];
    }

    json_out([
        'draw'            => $draw,
        'recordsTotal'    => $recordsTotal,
        'recordsFiltered' => $recordsFiltered,
        'data'            => $data
    ]);
}

// ========================
// CSV template download (GET)
// ========================
if (isset($_GET['action']) && $_GET['action'] === 'download_template') {
    $filename = 'rooms_template_' . date('Ymd_His') . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-store, no-cache, must-revalidate');

    // UTF-8 BOM so Excel renders Arabic correctly
    echo "\xEF\xBB\xBF";

    $out = fopen('php://output', 'w');

    // Semicolon-separated so Excel (esp. Arabic locales) opens it correctly without an import wizard.
    // Headers in Arabic; parser normalizes spaces to underscores and recognizes these aliases.
    $rows = [
        ['رقم الغرفة', 'نوع الغرفة', 'الفندق', 'الطابق', 'من', 'إلى', 'العقد', 'ملاحظات'],
        ['101', '1', 'فندق المثال', '3', '2025-01-01', '2025-01-10', 'C-001', 'غرفة مطلة'],
        ['102', '2', 'فندق المثال', '4', '2025-02-01', '2025-02-15', '',      ''],
    ];
    foreach ($rows as $row) {
        fputcsv($out, $row, ';');
    }

    fclose($out);
    exit;
}

// ========================
// AJAX endpoints (CRUD / bulk)
// ========================

// Single add (UPDATED: same rules as bulk)
if (isset($_POST['action']) && $_POST['action'] === 'add') {
    $room_num  = trim((string)($_POST['room_num'] ?? ''));
    $room_type = trim((string)($_POST['room_type'] ?? ''));
    $hotel_id  = trim((string)($_POST['hotel_id'] ?? '')); // actually hotel_name
    $floor     = trim((string)($_POST['floor'] ?? ''));
    $note      = (string)($_POST['note'] ?? '');
    $date_from = normalizeDate($_POST['date_from'] ?? null);
    $date_to   = normalizeDate($_POST['date_to'] ?? null);
    $contract  = (string)($_POST['contract'] ?? '');
    $auto_update = isset($_POST['auto_update']) ? (int)$_POST['auto_update'] : 0;

    if ($room_num === '' || $room_type === '' || $hotel_id === '' || $floor === '') {
        json_out(['status' => 'error', 'message' => 'All fields are required']);
    }

    try {
        // Check overlap like bulk
        $overlap = findOverlappingRoom($pdo, $room_num, $hotel_id, $date_from, $date_to);
        if ($overlap) {
            if ($auto_update === 1) {
                $old_to = ($overlap['date_to'] === '' ? null : $overlap['date_to']);
                $target_to = dateMaxNullable($old_to, $date_to);
                if ($target_to !== $old_to) {
                    $stmt = $pdo->prepare("UPDATE room SET date_to = ? WHERE id = ?");
                    $stmt->execute([$target_to, $overlap['id']]);
                    json_out([
                        'status'       => 'update',
                        'message'      => 'Date range extended automatically.',
                        'room_id'      => (int)$overlap['id'],
                        'old_date_to'  => $old_to,
                        'new_date_to'  => $target_to
                    ]);
                } else {
                    json_out([
                        'status'       => 'update',
                        'message'      => 'Already covered; no change needed.',
                        'room_id'      => (int)$overlap['id'],
                        'old_date_to'  => $old_to,
                        'new_date_to'  => $old_to
                    ]);
                }
            } else {
                json_out([
                    'status'   => 'conflict',
                    'message'  => 'Date intersection found.',
                    'existing' => $overlap
                ]);
            }
        } else {
            $stmt = $pdo->prepare("INSERT INTO room (room_num, room_type, hotel_name, floor, note, date_from, date_to, contract) 
                                   VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$room_num, $room_type, $hotel_id, $floor, $note, $date_from, $date_to, $contract]);
            $new_id = (int)$pdo->lastInsertId();
            json_out(['status' => 'insert', 'message' => 'Room added successfully', 'room_id' => $new_id]);
        }
    } catch (PDOException $e) {
        json_out(['status' => 'error', 'message' => 'Failed to add room: ' . $e->getMessage()]);
    } catch (Throwable $e) {
        json_out(['status' => 'error', 'message' => 'Server error: ' . $e->getMessage()]);
    }
}

// Edit
if (isset($_POST['action']) && $_POST['action'] === 'edit') {
    $room_id   = $_POST['id'] ?? '';
    $room_num  = $_POST['room_num'] ?? '';
    $room_type = $_POST['room_type'] ?? '';
    $hotel_id  = $_POST['hotel_id'] ?? '';
    $floor     = $_POST['floor'] ?? '';
    $note      = $_POST['note'] ?? '';
    $date_from = normalizeDate($_POST['date_from'] ?? null);
    $date_to   = normalizeDate($_POST['date_to'] ?? null);
    $contract  = $_POST['contract'] ?? '';

    if ($room_id === '' || $room_num === '' || $room_type === '' || $hotel_id === '' || $floor === '') {
        json_out(['status' => 'error', 'message' => 'All fields are required']);
    }

    try {
        $stmt = $pdo->prepare("UPDATE room SET room_num = ?, room_type = ?, hotel_name = ?, 
                              floor = ?, note = ?, date_from = ?, date_to = ?, contract = ? 
                              WHERE id = ?");
        $stmt->execute([$room_num, $room_type, $hotel_id, $floor, $note, $date_from, $date_to, $contract, $room_id]);
        json_out(['status' => 'success', 'message' => 'Room updated successfully']);
    } catch (PDOException $e) {
        json_out(['status' => 'error', 'message' => 'Failed to update room: ' . $e->getMessage()]);
    }
}

// Delete
if (isset($_POST['action']) && $_POST['action'] === 'delete') {
    $room_id = $_POST['id'] ?? '';

    if ($room_id === '') {
        json_out(['status' => 'error', 'message' => 'Room ID is required']);
    }

    try {
        $stmt = $pdo->prepare("DELETE FROM room WHERE id = ?");
        $stmt->execute([$room_id]);
        json_out(['status' => 'success', 'message' => 'Room deleted successfully']);
    } catch (PDOException $e) {
        json_out(['status' => 'error', 'message' => 'Failed to delete room: ' . $e->getMessage()]);
    }
}

// Bulk add (one request for all rows)
if (isset($_POST['action']) && $_POST['action'] === 'bulk_validate') {
    $parsed = parse_pasted_tsv(
        $_POST['rows_text'] ?? '',
        [
            'room_num' => ['رقم_الغرفة'],
            'room_type' => ['نوع_الغرفة'],
            'hotel_name' => ['hotel', 'الفندق'],
            'floor' => ['الطابق'],
            'date_from' => ['من', 'start_date'],
            'date_to' => ['إلى', 'end_date'],
            'contract' => ['العقد'],
            'note' => ['ملاحظات', 'note'],
        ],
        ['room_num', 'room_type', 'hotel_name', 'floor', 'date_from', 'date_to', 'contract', 'note']
    );

    if (!$parsed['ok']) {
        json_out(['status' => 'error', 'message' => $parsed['message']]);
    }

    // Load known hotel names once for cross-checking
    $knownHotels = [];
    foreach ($pdo->query("SELECT hotel_name FROM hotel")->fetchAll(PDO::FETCH_COLUMN) as $hn) {
        $knownHotels[mb_strtolower(trim((string)$hn), 'UTF-8')] = true;
    }

    $rows = [];
    $errors = [];
    foreach ($parsed['rows'] as $idx => $r) {
        $item = [
            'room_num' => trim((string)($r['room_num'] ?? '')),
            'room_type' => trim((string)($r['room_type'] ?? '')),
            'hotel_name' => trim((string)($r['hotel_name'] ?? '')),
            'floor' => trim((string)($r['floor'] ?? '')),
            'date_from' => normalizeDate($r['date_from'] ?? null),
            'date_to' => normalizeDate($r['date_to'] ?? null),
            'contract' => (string)($r['contract'] ?? ''),
            'note' => (string)($r['note'] ?? ''),
        ];
        $rows[] = $item;

        if ($item['room_num'] === '' || $item['room_type'] === '' || $item['hotel_name'] === '' || $item['floor'] === '') {
            $errors[] = ['index' => $idx, 'message' => 'حقول مطلوبة ناقصة (رقم الغرفة، نوع الغرفة، الفندق، الطابق).'];
            continue;
        }

        $hotelKey = mb_strtolower($item['hotel_name'], 'UTF-8');
        if (!isset($knownHotels[$hotelKey])) {
            $errors[] = [
                'index' => $idx,
                'message' => 'اسم الفندق غير موجود في جدول الفنادق: ' . $item['hotel_name'],
            ];
        }
    }

    json_out([
        'status' => 'ok',
        'rows' => $rows,
        'errors' => $errors,
        'has_header' => $parsed['has_header'] ?? false,
    ]);
}

if (isset($_POST['action']) && $_POST['action'] === 'bulk_add') {
    $auto_update = isset($_POST['auto_update']) ? (int)$_POST['auto_update'] : 0;
    $rows_json   = $_POST['rows'] ?? '[]';

    if (is_array($rows_json)) {
        $rows = $rows_json;
    } else {
        $rows = json_decode($rows_json, true);
    }

    if (!is_array($rows)) {
        json_out(['status' => 'error', 'message' => 'Invalid rows payload']);
    }

    // Defense-in-depth: also enforce hotel existence here.
    $knownHotels = [];
    foreach ($pdo->query("SELECT hotel_name FROM hotel")->fetchAll(PDO::FETCH_COLUMN) as $hn) {
        $knownHotels[mb_strtolower(trim((string)$hn), 'UTF-8')] = true;
    }

    $results = [];
    foreach ($rows as $idx => $r) {
        try {
            $room_num  = trim((string)($r['room_num']  ?? ''));
            $room_type = trim((string)($r['room_type'] ?? ''));
            $hotel     = trim((string)($r['hotel']     ?? ($r['hotel_name'] ?? '')));
            $floor     = trim((string)($r['floor']     ?? ''));
            $note      = (string)($r['note'] ?? '');
            $date_from = normalizeDate($r['date_from'] ?? null);
            $date_to   = normalizeDate($r['date_to']   ?? null);
            $contract  = (string)($r['contract'] ?? '');

            if ($room_num === '' || $room_type === '' || $hotel === '' || $floor === '') {
                $results[] = [
                    'index'   => $idx,
                    'status'  => 'error',
                    'message' => 'Missing required fields (room_num, room_type, hotel_name, floor).'
                ];
                continue;
            }

            if (!isset($knownHotels[mb_strtolower($hotel, 'UTF-8')])) {
                $results[] = [
                    'index'   => $idx,
                    'status'  => 'error',
                    'message' => 'اسم الفندق غير موجود في جدول الفنادق: ' . $hotel,
                ];
                continue;
            }

            $overlap = findOverlappingRoom($pdo, $room_num, $hotel, $date_from, $date_to);
            if ($overlap) {
                if ($auto_update === 1) {
                    $old_to   = ($overlap['date_to'] === '' ? null : $overlap['date_to']);
                    $target_to = dateMaxNullable($old_to, $date_to);
                    if ($target_to !== $old_to) {
                        $stmt = $pdo->prepare("UPDATE room SET date_to = ? WHERE id = ?");
                        $stmt->execute([$target_to, $overlap['id']]);
                        $results[] = [
                            'index'      => $idx,
                            'status'     => 'update',
                            'message'    => 'Date range extended automatically.',
                            'room_id'    => (int)$overlap['id'],
                            'old_date_to'=> $old_to,
                            'new_date_to'=> $target_to
                        ];
                    } else {
                        $results[] = [
                            'index'      => $idx,
                            'status'     => 'update',
                            'message'    => 'Already covered; no change needed.',
                            'room_id'    => (int)$overlap['id'],
                            'old_date_to'=> $old_to,
                            'new_date_to'=> $old_to
                        ];
                    }
                } else {
                    $results[] = [
                        'index'    => $idx,
                        'status'   => 'conflict',
                        'message'  => 'Date intersection found.',
                        'existing' => $overlap
                    ];
                }
            } else {
                $stmt = $pdo->prepare("INSERT INTO room (room_num, room_type, hotel_name, floor, note, date_from, date_to, contract) 
                                       VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$room_num, $room_type, $hotel, $floor, $note, $date_from, $date_to, $contract]);
                $new_id = (int)$pdo->lastInsertId();
                $results[] = [
                    'index'   => $idx,
                    'status'  => 'insert',
                    'message' => 'Inserted successfully.',
                    'room_id' => $new_id
                ];
            }
        } catch (PDOException $e) {
            $results[] = [
                'index'   => $idx,
                'status'  => 'error',
                'message' => 'DB error: ' . $e->getMessage()
            ];
        } catch (Throwable $e) {
            $results[] = [
                'index'   => $idx,
                'status'  => 'error',
                'message' => 'Server error: ' . $e->getMessage()
            ];
        }
    }

    json_out(['status' => 'ok', 'results' => $results]);
}

// Increase date_to for an existing row (used from conflict button)
if (isset($_POST['action']) && $_POST['action'] === 'increase_date_to') {
    $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
    $new_date_to = normalizeDate($_POST['new_date_to'] ?? null);

    if ($id <= 0) {
        json_out(['status' => 'error', 'message' => 'Invalid room id.']);
    }

    try {
        $stmt = $pdo->prepare("SELECT * FROM room WHERE id = ?");
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            json_out(['status' => 'error', 'message' => 'Room not found.']);
        }
        $old_to = $row['date_to'] === '' ? null : $row['date_to'];
        $target_to = dateMaxNullable($old_to, $new_date_to);
        if ($target_to !== $old_to) {
            $upd = $pdo->prepare("UPDATE room SET date_to = ? WHERE id = ?");
            $upd->execute([$target_to, $id]);
            json_out([
                'status'      => 'success',
                'message'     => 'Date range extended.',
                'old_date_to' => $old_to,
                'new_date_to' => $target_to
            ]);
        } else {
            json_out([
                'status'      => 'success',
                'message'     => 'No change needed; already covered.',
                'old_date_to' => $old_to,
                'new_date_to' => $old_to
            ]);
        }
    } catch (PDOException $e) {
        json_out(['status' => 'error', 'message' => 'Failed to increase date_to: ' . $e->getMessage()]);
    }
}

// Get a room by id (for “Show old data”)
if (isset($_POST['action']) && $_POST['action'] === 'get_room') {
    $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
    if ($id <= 0) {
        json_out(['status' => 'error', 'message' => 'Invalid id.']);
    }
    try {
        $stmt = $pdo->prepare("SELECT * FROM room WHERE id = ?");
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) json_out(['status' => 'error', 'message' => 'Not found.']);
        json_out(['status' => 'success', 'room' => $row]);
    } catch (PDOException $e) {
        json_out(['status' => 'error', 'message' => 'Failed to fetch room: ' . $e->getMessage()]);
    }
}

// ========================
// Fetch data for page render (hotels only)
// ========================
try {
    $hotels_stmt = $pdo->query("SELECT id, hotel_name FROM hotel");
    $hotels = $hotels_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die("Failed to fetch hotels: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>إدارة الغرف</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://cdn.datatables.net/1.10.24/css/jquery.dataTables.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Cairo', sans-serif; }
        .modal-content { border-radius: 15px; }
        .modal-header { background-color: #007bff; color: white; }
        .modal-footer { background-color: #f8f9fa; }
        .container { margin-top: 30px; }
        .dataTables_wrapper { margin-top: 20px; }
        .small-muted { font-size: .9rem; color: #6c757d; }
        .nowrap { white-space: nowrap; }
        .spinner-inline {
            display: inline-block;
            width: 1rem; height: 1rem; border: .15em solid rgba(0,0,0,.15);
            border-right-color: transparent; border-radius: 50%; animation: spinner .75s linear infinite;
            vertical-align: middle; margin-inline-start: .25rem;
        }
        @keyframes spinner { to { transform: rotate(360deg);} }
        pre.format-sample {
            background: #f8f9fa; padding: .75rem; border-radius: .5rem; border: 1px solid #e9ecef;
            direction: ltr; text-align: left; white-space: pre-wrap;
        }
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
        .badge-status { font-size: .85rem; }
        .table-fixed-head thead th { position: sticky; top: 0; background: #fff; z-index: 1; }
        .alert-compact { padding: .5rem .75rem; }
    </style>
</head>
<body>
    <div class="container-fluid pt-2">
        <?php render_root_navbar('rooms'); ?>
    </div>
    <div class="container">
        <h2 class="text-center mb-4">إدارة الغرف</h2>

        <div class="text-center mb-3 d-flex justify-content-center gap-2">
            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addRoomModal">إضافة غرفة جديدة</button>
            <button class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#bulkAddModal">إضافة عدة غرف (Excel)</button>
        </div>

        <table id="roomsTable" class="display table table-bordered">
            <thead>
                <tr>
                    <th>المعرف</th>
                    <th>رقم الغرفة</th>
                    <th>نوع الغرفة</th>
                    <th>الفندق</th>
                    <th>الطابق</th>
                    <th>تاريخ من</th>
                    <th>تاريخ إلى</th>
                    <th>العقد</th>
                    <th>ملاحظات</th>
                    <th>الإجراءات</th>
                </tr>
            </thead>
            <tbody>
                <!-- Server-side processing: rows loaded via AJAX -->
            </tbody>
        </table>
    </div>

    <!-- Add Room Modal (UPDATED UI) -->
    <div class="modal fade" id="addRoomModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">إضافة غرفة جديدة</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="إغلاق"></button>
                </div>
                <div class="modal-body">
                    <form id="addRoomForm">
                        <div class="mb-3">
                            <label for="roomNum" class="form-label">رقم الغرفة</label>
                            <input type="number" class="form-control" id="roomNum" required>
                        </div>
                        <div class="mb-3">
                            <label for="roomType" class="form-label">نوع الغرفة</label>
                            <input type="number" class="form-control" id="roomType" required>
                        </div>
                        <div class="mb-3">
                            <label for="hotelId" class="form-label">الفندق</label>
                            <select class="form-select" id="hotelId" required>
                                <option value="">اختر فندق</option>
                                <?php foreach ($hotels as $hotel): ?>
                                    <option value="<?= h($hotel['hotel_name']) ?>"><?= h($hotel['hotel_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label for="floor" class="form-label">الطابق</label>
                            <input type="number" class="form-control" id="floor" required>
                        </div>
                        <div class="mb-3">
                            <label for="dateFrom" class="form-label">تاريخ من</label>
                            <input type="date" class="form-control" id="dateFrom">
                        </div>
                        <div class="mb-3">
                            <label for="dateTo" class="form-label">تاريخ إلى</label>
                            <input type="date" class="form-control" id="dateTo">
                        </div>
                        <div class="mb-3">
                            <label for="contract" class="form-label">العقد</label>
                            <textarea class="form-control" id="contract"></textarea>
                        </div>
                        <div class="mb-3">
                            <label for="note" class="form-label">ملاحظات</label>
                            <textarea class="form-control" id="note"></textarea>
                        </div>

                        <!-- NEW: Auto-update checkbox to mirror bulk behavior -->
                        <div class="form-check mt-2">
                            <input class="form-check-input" type="checkbox" id="autoUpdateSingleChk">
                            <label class="form-check-label" for="autoUpdateSingleChk">
                                التحديث التلقائي عند التطابق (زيادة تاريخ «إلى» تلقائيًا)
                            </label>
                        </div>
                    </form>

                    <!-- NEW: conflict/status zone -->
                    <div id="singleAddConflictZone" class="mt-3 d-none"></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إغلاق</button>
                    <button type="button" class="btn btn-primary" id="saveRoomBtn">حفظ</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Edit Room Modal -->
    <div class="modal fade" id="editRoomModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">تعديل بيانات الغرفة</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="إغلاق"></button>
                </div>
                <div class="modal-body">
                    <form id="editRoomForm">
                        <div class="mb-3">
                            <label for="editRoomNum" class="form-label">رقم الغرفة</label>
                            <input type="number" class="form-control" id="editRoomNum" required>
                        </div>
                        <div class="mb-3">
                            <label for="editRoomType" class="form-label">نوع الغرفة</label>
                            <input type="number" class="form-control" id="editRoomType" required>
                        </div>
                        <div class="mb-3">
                            <label for="editHotelId" class="form-label">الفندق</label>
                            <select class="form-select" id="editHotelId" required>
                                <option value="">اختر فندق</option>
                                <?php foreach ($hotels as $hotel): ?>
                                    <option value="<?= h($hotel['hotel_name']) ?>"><?= h($hotel['hotel_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label for="editFloor" class="form-label">الطابق</label>
                            <input type="number" class="form-control" id="editFloor" required>
                        </div>
                        <div class="mb-3">
                            <label for="editDateFrom" class="form-label">تاريخ من</label>
                            <input type="date" class="form-control" id="editDateFrom">
                        </div>
                        <div class="mb-3">
                            <label for="editDateTo" class="form-label">تاريخ إلى</label>
                            <input type="date" class="form-control" id="editDateTo">
                        </div>
                        <div class="mb-3">
                            <label for="editContract" class="form-label">العقد</label>
                            <textarea class="form-control" id="editContract"></textarea>
                        </div>
                        <div class="mb-3">
                            <label for="editNote" class="form-label">ملاحظات</label>
                            <textarea class="form-control" id="editNote"></textarea>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إغلاق</button>
                    <button type="button" class="btn btn-primary" id="updateRoomBtn">تحديث</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Delete Room Modal -->
    <div class="modal fade" id="deleteRoomModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">حذف الغرفة</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="إغلاق"></button>
                </div>
                <div class="modal-body">
                    هل أنت متأكد أنك تريد حذف هذه الغرفة؟
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إغلاق</button>
                    <button type="button" class="btn btn-danger" id="confirmDeleteRoomBtn">حذف</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Bulk Add Modal -->
    <div class="modal fade" id="bulkAddModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">إضافة عدة غرف (لصق من Excel)</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="إغلاق"></button>
                </div>
                <div class="modal-body">
                    <div class="format-card">
                        <div class="format-title d-flex flex-wrap align-items-center">
                            <i class="bi bi-table"></i>
                            <span>صيغة البيانات المتوقعة</span>
                            <div class="format-actions">
                                <a class="btn btn-success btn-sm" href="room.php?action=download_template" download>
                                    <i class="bi bi-download"></i>
                                    تنزيل ملف CSV نموذجي
                                </a>
                            </div>
                        </div>

                        <div class="format-cols">
                            <div class="format-col required">
                                <div class="col-label"><i class="bi bi-hash"></i> رقم الغرفة</div>
                                <div class="col-hint">رقم صحيح</div>
                            </div>
                            <div class="format-col required">
                                <div class="col-label"><i class="bi bi-people"></i> نوع الغرفة</div>
                                <div class="col-hint">عدد الأسرّة (1, 2, 3 ...)</div>
                            </div>
                            <div class="format-col required">
                                <div class="col-label"><i class="bi bi-building"></i> الفندق</div>
                                <div class="col-hint">يجب أن يطابق اسم فندق موجود</div>
                            </div>
                            <div class="format-col required">
                                <div class="col-label"><i class="bi bi-layers"></i> الطابق</div>
                                <div class="col-hint">رقم الطابق</div>
                            </div>
                            <div class="format-col">
                                <div class="col-label"><i class="bi bi-calendar-event"></i> من</div>
                                <div class="col-hint">YYYY-MM-DD أو DD/MM/YYYY</div>
                            </div>
                            <div class="format-col">
                                <div class="col-label"><i class="bi bi-calendar-check"></i> إلى</div>
                                <div class="col-hint">YYYY-MM-DD أو DD/MM/YYYY</div>
                            </div>
                            <div class="format-col">
                                <div class="col-label"><i class="bi bi-file-text"></i> العقد</div>
                                <div class="col-hint">رقم العقد (اختياري)</div>
                            </div>
                            <div class="format-col">
                                <div class="col-label"><i class="bi bi-sticky"></i> ملاحظات</div>
                                <div class="col-hint">نص حر (اختياري)</div>
                            </div>
                        </div>

                        <div class="format-example">
                            <code>101 &nbsp; 1 &nbsp; فندق المثال &nbsp; 3 &nbsp; 2025-01-01 &nbsp; 2025-01-10 &nbsp; C-001 &nbsp; غرفة مطلة</code>
                        </div>

                        <div class="format-tip">
                            <i class="bi bi-info-circle"></i>
                            <span>
                                نزِّل الملف النموذجي، عبّئه في Excel، ثم انسخ الصفوف (بما فيها صف العناوين) والصقها في الحقل أدناه.
                                الأعمدة المطلوبة موسومة بـ <span class="text-danger">*</span>.
                            </span>
                        </div>
                    </div>

                    <textarea id="bulkTextarea" class="form-control" rows="8" placeholder="الصق من Excel هنا (أعمدة مفصولة بعلامة تبويب)"></textarea>

                    <div class="form-check mt-3">
                        <input class="form-check-input" type="checkbox" id="autoUpdateChk">
                        <label class="form-check-label" for="autoUpdateChk">
                            التحديث التلقائي عند التطابق (زيادة تاريخ &laquo;إلى&raquo; تلقائيًا)
                        </label>
                    </div>

                    <div class="d-flex align-items-center gap-2 mt-3">
                        <button type="button" class="btn btn-outline-primary" id="bulkValidateBtn">تحقق من البيانات</button>
                        <button type="button" class="btn btn-primary" id="bulkProcessBtn" disabled>تنفيذ الإدخال</button>
                        <div id="bulkSummary" class="small-muted"></div>
                    </div>

                    <hr class="my-3">

                    <div class="table-responsive">
                        <table class="table table-sm align-middle table-fixed-head" id="bulkResultsTable">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>الغرفة</th>
                                    <th>الفندق</th>
                                    <th>الطابق</th>
                                    <th>من</th>
                                    <th>إلى</th>
                                    <th>الإجراء / الحالة</th>
                                </tr>
                            </thead>
                            <tbody id="bulkResultsBody"></tbody>
                        </table>
                    </div>
                </div>
                <div class="modal-footer">
                    <button class="btn btn-outline-secondary" type="button" id="bulkRefreshPageBtn" style="display:none;">تحديث الجدول</button>
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إغلاق</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Old Room Data Modal -->
    <div class="modal fade" id="oldRoomModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">البيانات القديمة</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="إغلاق"></button>
                </div>
                <div class="modal-body" id="oldRoomBody">
                    <!-- filled dynamically -->
                </div>
                <div class="modal-footer">
                    <button class="btn btn-secondary" data-bs-dismiss="modal">إغلاق</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Scripts -->
    <script src="https://code.jquery.com/jquery-3.5.1.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.10.2/dist/umd/popper.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.datatables.net/1.10.24/js/jquery.dataTables.min.js"></script>

    <script>
        $(document).ready(function() {
            // =========================
            // DataTable (server-side)
            // =========================
            const table = $('#roomsTable').DataTable({
                serverSide: true,
                processing: true,
                ajax: {
                    url: 'room.php',
                    type: 'POST',
                    data: function (d) {
                        d.action = 'datatable';
                    }
                },
                searchDelay: 400,
                pageLength: 10,
                order: [[0, 'desc']], // default: latest ID first
                columns: [
                    { data: 'id' },
                    { data: 'room_num' },
                    { data: 'room_type' },
                    { data: 'hotel_name' },
                    { data: 'floor' },
                    { data: 'date_from' },
                    { data: 'date_to' },
                    { data: 'contract' },
                    { data: 'note' },
                    { data: 'actions', orderable: false, searchable: false }
                ]
            });

            $('#roomsTable_filter input')
    .unbind() // remove default keyup binding
    .bind('keypress', function (e) {
        if (e.which === 13) { // Enter key
            table.search(this.value).draw();
        }
    });

            // Utility (shared with bulk)
            function fillOldModal(room) {
                const esc = (s) => (s ?? '').toString()
                    .replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
                const html = `
                    <div class="mb-2"><strong>الفندق:</strong> ${esc(room.hotel_name)}</div>
                    <div class="mb-2"><strong>رقم الغرفة:</strong> ${esc(room.room_num)}</div>
                    <div class="mb-2"><strong>نوع الغرفة:</strong> ${esc(room.room_type)}</div>
                    <div class="mb-2"><strong>الطابق:</strong> ${esc(room.floor)}</div>
                    <div class="mb-2"><strong>من:</strong> ${esc(room.date_from || '')}</div>
                    <div class="mb-2"><strong>إلى:</strong> ${esc(room.date_to || '')}</div>
                    <div class="mb-2"><strong>العقد:</strong> ${esc(room.contract || '')}</div>
                    <div class="mb-2"><strong>ملاحظات:</strong> ${esc(room.note || '')}</div>
                `;
                $('#oldRoomBody').html(html);
                const oldModal = new bootstrap.Modal(document.getElementById('oldRoomModal'));
                oldModal.show();
            }

            // =========================
            // Single add / edit / delete
            // =========================

            // Reset conflict zone on open/close
            $('#addRoomModal').on('show.bs.modal hidden.bs.modal', function() {
                $('#singleAddConflictZone').addClass('d-none').empty();
                $('#addRoomForm')[0].reset();
                $('#saveRoomBtn').prop('disabled', false).text('حفظ');
            });

            function renderSingleAddConflict(existingObj, newDateTo) {
                const $zone = $('#singleAddConflictZone');
                const btnOldId = 'single-old-btn';
                const btnIncId = 'single-inc-btn';
                $zone
                  .removeClass('d-none')
                  .html(`
                    <div class="alert alert-warning alert-compact d-flex flex-wrap align-items-center gap-2 mb-0">
                        <span class="badge bg-warning text-dark badge-status">تعارض تواريخ</span>
                        <span>تم العثور على حجز سابق لنفس الغرفة/الفندق ضمن نفس النطاق الزمني.</span>
                        <div class="ms-auto d-flex flex-wrap gap-2">
                            <button type="button" class="btn btn-sm btn-outline-secondary" id="${btnOldId}">عرض البيانات القديمة</button>
                            <button type="button" class="btn btn-sm btn-outline-primary" id="${btnIncId}">زيادة تاريخ إلى</button>
                        </div>
                    </div>
                `);

                $('#' + btnOldId).off('click').on('click', function() {
                    if (existingObj) fillOldModal(existingObj);
                });
                $('#' + btnIncId).off('click').on('click', function() {
                    const $btn = $(this);
                    $btn.prop('disabled', true).append('<span class="spinner-inline"></span>');
                    $.ajax({
                        url: 'room.php',
                        method: 'POST',
                        dataType: 'json',
                        data: {
                            action: 'increase_date_to',
                            id: existingObj.id,
                            new_date_to: newDateTo || ''
                        },
                        success: function(res) {
                            if (res.status === 'success') {
                                // Success: close modal and refresh
                                $('#addRoomModal').modal('hide');
                                table.ajax.reload(null, false);
                            } else {
                                // Show error inline
                                $zone.append(`<div class="text-danger mt-2">${(res && res.message) ? res.message : 'فشل التحديث'}</div>`);
                                $btn.prop('disabled', false).find('.spinner-inline').remove();
                            }
                        },
                        error: function() {
                            $zone.append('<div class="text-danger mt-2">فشل الاتصال بالخادم</div>');
                            $btn.prop('disabled', false).find('.spinner-inline').remove();
                        }
                    });
                });
            }

            $('#saveRoomBtn').click(function() {
                const $btn = $(this);
                $('#singleAddConflictZone').addClass('d-none').empty();

                $btn.prop('disabled', true).text('جارٍ الحفظ...');

                $.ajax({
                    url: 'room.php',
                    method: 'POST',
                    dataType: 'json',
                    data: {
                        action: 'add',
                        room_num: $('#roomNum').val(),
                        room_type: $('#roomType').val(),
                        hotel_id: $('#hotelId').val(),
                        floor: $('#floor').val(),
                        date_from: $('#dateFrom').val(),
                        date_to: $('#dateTo').val(),
                        contract: $('#contract').val(),
                        note: $('#note').val(),
                        auto_update: $('#autoUpdateSingleChk').is(':checked') ? 1 : 0
                    },
                    success: function(res) {
                        // Handle statuses consistent with bulk
                        if (res.status === 'insert' || res.status === 'update') {
                            $('#addRoomModal').modal('hide');
                            table.ajax.reload(null, false);
                        } else if (res.status === 'conflict') {
                            // Render inline conflict UI
                            renderSingleAddConflict(res.existing, $('#dateTo').val());
                            $btn.prop('disabled', false).text('حفظ');
                        } else {
                            alert(res.message || 'فشل العملية.');
                            $btn.prop('disabled', false).text('حفظ');
                        }
                    },
                    error: function() {
                        alert('Failed to add room.');
                        $btn.prop('disabled', false).text('حفظ');
                    }
                });
            });

            $("#roomsTable").on("click", '.edit-btn', function() {
                const id = $(this).data('id');
                $('#editRoomNum').val($(this).data('room_num'));
                $('#editRoomType').val($(this).data('room_type'));
                $('#editHotelId').val($(this).data('hotel'));
                $('#editFloor').val($(this).data('floor'));
                $('#editDateFrom').val($(this).data('date_from'));
                $('#editDateTo').val($(this).data('date_to'));
                $('#editContract').val($(this).data('contract'));
                $('#editNote').val($(this).data('note'));

                $('#updateRoomBtn').off('click').on('click', function() {
                    $.ajax({
                        url: 'room.php',
                        method: 'POST',
                        dataType: 'json',
                        data: {
                            action: 'edit',
                            id: id,
                            room_num: $('#editRoomNum').val(),
                            room_type: $('#editRoomType').val(),
                            hotel_id: $('#editHotelId').val(),
                            floor: $('#editFloor').val(),
                            date_from: $('#editDateFrom').val(),
                            date_to: $('#editDateTo').val(),
                            contract: $('#editContract').val(),
                            note: $('#editNote').val()
                        },
                        success: function(res) {
                            if (res.status === 'success') {
                                $('#editRoomModal').modal('hide');
                                table.ajax.reload(null, false);
                            } else {
                                alert(res.message || 'Failed.');
                            }
                        },
                        error: function() { alert('Failed to update room.'); }
                    });
                });
            });

            $("#roomsTable").on("click", '.delete-btn', function() {
                const id = $(this).data('id');
                $('#confirmDeleteRoomBtn').off('click').on('click', function() {
                    $.ajax({
                        url: 'room.php',
                        method: 'POST',
                        dataType: 'json',
                        data: { action: 'delete', id: id },
                        success: function(res) {
                            if (res.status === 'success') {
                                $('#deleteRoomModal').modal('hide');
                                table.ajax.reload(null, false);
                            } else {
                                alert(res.message || 'Failed.');
                            }
                        },
                        error: function() { alert('Failed to delete room.'); }
                    });
                });
            });

            // =========================
            // Bulk add (ONE request)
            // =========================
            const $bulkBody = $('#bulkResultsBody');
            const $bulkSummary = $('#bulkSummary');
            const $bulkRefresh = $('#bulkRefreshPageBtn');

            function rowHtml(idx, r) {
                const esc = (s) => (s ?? '').toString()
                    .replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
                return `
                    <tr id="bulk-row-${idx}">
                        <td class="text-muted">${idx + 1}</td>
                        <td>${esc(r.room_num)}</td>
                        <td>${esc(r.hotel || r.hotel_name)}</td>
                        <td>${esc(r.floor)}</td>
                        <td>${esc(r.date_from || '')}</td>
                        <td>${esc(r.date_to || '')}</td>
                        <td id="bulk-status-${idx}">
                            <span class="text-muted">قيد المعالجة</span><span class="spinner-inline"></span>
                        </td>
                    </tr>
                `;
            }

            function showSuccessBadge(idx, typeText) {
                $(`#bulk-status-${idx}`).html(`<span class="badge bg-success badge-status">${typeText}</span>`);
            }

            function showErrorBadge(idx, text) {
                $(`#bulk-status-${idx}`).html(`<span class="badge bg-danger badge-status">${text}</span>`);
            }

            function showConflictButtons(idx, existingObj, newDateTo) {
                const $cell = $(`#bulk-status-${idx}`);
                const btnOldId = `btn-old-${idx}`;
                const btnIncId = `btn-inc-${idx}`;
                $cell.html(`
                    <div class="d-flex flex-wrap gap-2">
                        <span class="badge bg-warning text-dark badge-status">تعارض تواريخ</span>
                        <button type="button" class="btn btn-sm btn-outline-secondary" id="${btnOldId}">عرض البيانات القديمة</button>
                        <button type="button" class="btn btn-sm btn-outline-primary" id="${btnIncId}">زيادة تاريخ إلى</button>
                    </div>
                `);
                // Handlers
                $(`#${btnOldId}`).off('click').on('click', function() {
                    if (existingObj) fillOldModal(existingObj);
                });
                $(`#${btnIncId}`).off('click').on('click', function() {
                    $.ajax({
                        url: 'room.php',
                        method: 'POST',
                        dataType: 'json',
                        data: { action: 'increase_date_to', id: existingObj.id, new_date_to: newDateTo || '' },
                        success: function(res) {
                            if (res.status === 'success') {
                                showSuccessBadge(idx, 'تم التحديث');
                            } else {
                                showErrorBadge(idx, res.message || 'فشل التحديث');
                            }
                        },
                        error: function(){ showErrorBadge(idx, 'فشل الاتصال'); }
                    });
                });
            }

            let validatedRows = [];

            $('#bulkValidateBtn').on('click', function() {
                $bulkSummary.text('');
                $bulkBody.empty();
                $bulkRefresh.hide();
                validatedRows = [];
                $('#bulkProcessBtn').prop('disabled', true);
                const raw = $('#bulkTextarea').val().trim();
                if (!raw) {
                    $bulkSummary.text('لا توجد بيانات للمعالجة.');
                    return;
                }

                $.ajax({
                    url: 'room.php',
                    method: 'POST',
                    dataType: 'json',
                    data: { action: 'bulk_validate', rows_text: raw },
                    success: function(res) {
                        if (res.status !== 'ok' || !Array.isArray(res.rows)) {
                            $bulkSummary.text(res.message || 'فشل التحقق.');
                            return;
                        }
                        validatedRows = res.rows;
                        validatedRows.forEach((r, idx) => $bulkBody.append(rowHtml(idx, r)));

                        if (Array.isArray(res.errors) && res.errors.length > 0) {
                            res.errors.forEach(item => showErrorBadge(item.index, item.message || 'خطأ تحقق'));
                            $bulkSummary.text(`التحقق اكتمل: ${res.errors.length} صفوف بها أخطاء.`);
                            return;
                        }

                        $('#bulkProcessBtn').prop('disabled', false);
                        $bulkSummary.text(`التحقق اكتمل: ${validatedRows.length} صفوف جاهزة للإدخال.`);
                    },
                    error: function() {
                        $bulkSummary.text('فشل الاتصال بالخادم أثناء التحقق.');
                    }
                });
            });

            $('#bulkProcessBtn').on('click', function() {
                if (validatedRows.length === 0) {
                    $bulkSummary.text('قم بالتحقق أولاً.');
                    return;
                }
                // ONE request with all rows
                $.ajax({
                    url: 'room.php',
                    method: 'POST',
                    dataType: 'json',
                    data: {
                        action: 'bulk_add',
                        rows: JSON.stringify(validatedRows),
                        auto_update: $('#autoUpdateChk').is(':checked') ? 1 : 0
                    },
                    success: function(res) {
                        if (res.status !== 'ok' || !Array.isArray(res.results)) {
                            $bulkSummary.text('فشل: رد غير متوقع.');
                            return;
                        }
                        let inserted = 0, updated = 0, conflicts = 0, errors = 0;

                        res.results.forEach(item => {
                            const i = item.index;
                            if (!Number.isInteger(i)) return;
                            if (item.status === 'insert') {
                                showSuccessBadge(i, 'تم الإدخال');
                                inserted++;
                            } else if (item.status === 'update') {
                                showSuccessBadge(i, 'تم التحديث');
                                updated++;
                            } else if (item.status === 'conflict') {
                                conflicts++;
                                showConflictButtons(i, item.existing, validatedRows[i]?.date_to || '');
                            } else {
                                showErrorBadge(i, 'خطأ');
                                errors++;
                            }
                        });

                        $bulkSummary.text(`اكتمل: ${inserted} تمت إضافتها، ${updated} تم تحديثها، ${conflicts} بها تعارض، ${errors} أخطاء.`);
                        $bulkRefresh.show().off('click').on('click', () => table.ajax.reload(null, false));
                    },
                    error: function() {
                        $bulkSummary.text('فشل الاتصال بالخادم.');
                    }
                });
            });

            $('#bulkAddModal').on('hidden.bs.modal', function() {
                validatedRows = [];
                $('#bulkProcessBtn').prop('disabled', true);
                $('#bulkSummary').text('');
                $('#bulkResultsBody').empty();
            });
        });
    </script>
</body>
</html>
