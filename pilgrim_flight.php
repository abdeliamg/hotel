<?php
declare(strict_types=1);

require_once __DIR__ . '/check.php';
require_once __DIR__ . '/includes/root_nav.php';
require_once __DIR__ . '/includes/db.php';

$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

function pf_json(array $data, int $statusCode = 200): void
{
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function pf_e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function pf_normalize_date(?string $value): string
{
    $value = trim((string)$value);
    if ($value === '') {
        return '';
    }

    $time = strtotime($value);
    return $time === false ? '' : date('Y-m-d', $time);
}

function pf_pilgrim_text(array $row): string
{
    $parts = array_filter([
        (string)($row['barcode'] ?? ''),
        (string)($row['name'] ?? ''),
        (string)($row['app_id'] ?? $row['passport'] ?? ''),
        (string)($row['group_name'] ?? $row['group'] ?? ''),
    ], static fn($value) => trim($value) !== '');

    return implode(' | ', $parts);
}

function pf_parse_barcodes(string $text): array
{
    $parts = preg_split('/[\s,;،]+/u', $text);
    $barcodes = [];

    foreach ($parts ?: [] as $part) {
        $part = trim($part);
        if ($part !== '') {
            $barcodes[$part] = true;
        }
    }

    return array_keys($barcodes);
}

if (isset($_GET['action']) && $_GET['action'] === 'datatable') {
    $draw = (int)($_GET['draw'] ?? 0);
    $start = max(0, (int)($_GET['start'] ?? 0));
    $length = (int)($_GET['length'] ?? 10);
    $search = trim((string)($_GET['search']['value'] ?? ''));

    $columns = [
        0 => 'pf.id',
        1 => 'pf.barcode',
        2 => 'p.name',
        3 => 'p.app_id',
        4 => 'p.`group`',
        5 => 'g.master_group',
        6 => 'pf.departed',
    ];
    $orderColIdx = (int)($_GET['order'][0]['column'] ?? 0);
    $orderCol = $columns[$orderColIdx] ?? 'pf.id';
    $orderDir = strtolower((string)($_GET['order'][0]['dir'] ?? 'desc')) === 'asc' ? 'ASC' : 'DESC';

    $from = " FROM pilgrim_flight pf
              LEFT JOIN pilgrim p ON p.barcode = pf.barcode
              LEFT JOIN `group` g ON g.`group` = p.`group` ";
    $where = '';
    $params = [];
    if ($search !== '') {
        $where = " WHERE pf.barcode LIKE :search
                    OR p.name LIKE :search
                    OR p.app_id LIKE :search
                    OR p.`group` LIKE :search
                    OR g.master_group LIKE :search
                    OR pf.departed LIKE :search ";
        $params[':search'] = '%' . $search . '%';
    }

    $recordsTotal = (int)$pdo->query("SELECT COUNT(*) FROM pilgrim_flight")->fetchColumn();
    $stmtCount = $pdo->prepare("SELECT COUNT(*) " . $from . $where);
    $stmtCount->execute($params);
    $recordsFiltered = (int)$stmtCount->fetchColumn();

    $limitSql = $length > 0 ? " LIMIT :limit OFFSET :offset" : "";
    $stmt = $pdo->prepare("SELECT
            pf.id,
            pf.barcode,
            pf.departed,
            COALESCE(p.name, '') AS name,
            COALESCE(p.app_id, '') AS app_id,
            COALESCE(p.`group`, '') AS pilgrim_group,
            COALESCE(g.master_group, '') AS master_group
        " . $from . $where . " ORDER BY {$orderCol} {$orderDir}" . $limitSql);

    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value, PDO::PARAM_STR);
    }
    if ($length > 0) {
        $stmt->bindValue(':limit', $length, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $start, PDO::PARAM_INT);
    }
    $stmt->execute();

    $data = [];
    foreach ($stmt->fetchAll() as $row) {
        $id = (int)$row['id'];
        $data[] = [
            'id' => $id,
            'barcode' => pf_e((string)$row['barcode']),
            'name' => pf_e((string)$row['name']),
            'app_id' => pf_e((string)$row['app_id']),
            'pilgrim_group' => pf_e((string)$row['pilgrim_group']),
            'master_group' => pf_e((string)$row['master_group']),
            'departed' => pf_e((string)$row['departed']),
            'actions' => '<button class="btn btn-sm btn-danger delete-departure" data-id="' . $id . '"><i class="bi bi-trash"></i> حذف</button>',
        ];
    }

    pf_json([
        'draw' => $draw,
        'recordsTotal' => $recordsTotal,
        'recordsFiltered' => $recordsFiltered,
        'data' => $data,
    ]);
}

if (isset($_GET['action']) && $_GET['action'] === 'search_pilgrims') {
    $term = trim((string)($_GET['q'] ?? ''));
    $page = max(1, (int)($_GET['page'] ?? 1));
    $limit = 20;
    $offset = ($page - 1) * $limit;
    $like = '%' . $term . '%';

    $stmt = $pdo->prepare("SELECT p.barcode, p.name, p.app_id, p.`group`, g.master_group
        FROM pilgrim p
        LEFT JOIN `group` g ON g.`group` = p.`group`
        WHERE (p.barcode LIKE :term OR p.name LIKE :term OR p.app_id LIKE :term OR p.`group` LIKE :term OR g.master_group LIKE :term)
          AND NOT EXISTS (SELECT 1 FROM pilgrim_flight pf WHERE pf.barcode = p.barcode)
        ORDER BY p.name COLLATE NOCASE ASC
        LIMIT :limit OFFSET :offset");
    $stmt->bindValue(':term', $like, PDO::PARAM_STR);
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();

    $results = [];
    foreach ($stmt->fetchAll() as $row) {
        $row['group_name'] = $row['master_group'] ?: $row['group'];
        $results[] = [
            'id' => (string)$row['barcode'],
            'text' => pf_pilgrim_text($row),
            'barcode' => (string)$row['barcode'],
            'name' => (string)$row['name'],
            'group_name' => (string)$row['group_name'],
        ];
    }

    pf_json(['results' => $results, 'pagination' => ['more' => count($results) === $limit]]);
}

if (isset($_GET['action']) && $_GET['action'] === 'search_groups') {
    $term = trim((string)($_GET['q'] ?? ''));
    $page = max(1, (int)($_GET['page'] ?? 1));
    $limit = 20;
    $offset = ($page - 1) * $limit;
    $like = '%' . $term . '%';

    $stmt = $pdo->prepare("SELECT DISTINCT `group`, master_group
        FROM `group`
        WHERE `group` LIKE :term OR master_group LIKE :term
        ORDER BY `group` COLLATE NOCASE ASC
        LIMIT :limit OFFSET :offset");
    $stmt->bindValue(':term', $like, PDO::PARAM_STR);
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();

    $results = [];
    foreach ($stmt->fetchAll() as $row) {
        $group = (string)$row['group'];
        $master = (string)$row['master_group'];
        $results[] = [
            'id' => $group,
            'text' => $master !== '' ? $group . ' | ' . $master : $group,
        ];
    }

    pf_json(['results' => $results, 'pagination' => ['more' => count($results) === $limit]]);
}

if (isset($_GET['action']) && $_GET['action'] === 'group_pilgrims') {
    $group = trim((string)($_GET['group'] ?? ''));
    if ($group === '') {
        pf_json(['success' => false, 'message' => 'يرجى اختيار المجموعة.'], 400);
    }

    $stmt = $pdo->prepare("SELECT p.barcode, p.name, p.app_id, p.`group`, g.master_group
        FROM pilgrim p
        LEFT JOIN `group` g ON g.`group` = p.`group`
        WHERE p.`group` = :group
          AND NOT EXISTS (SELECT 1 FROM pilgrim_flight pf WHERE pf.barcode = p.barcode)
        ORDER BY p.name COLLATE NOCASE ASC");
    $stmt->execute([':group' => $group]);

    $pilgrims = [];
    foreach ($stmt->fetchAll() as $row) {
        $row['group_name'] = $row['master_group'] ?: $row['group'];
        $pilgrims[] = [
            'id' => (string)$row['barcode'],
            'text' => pf_pilgrim_text($row),
            'barcode' => (string)$row['barcode'],
            'name' => (string)$row['name'],
            'group_name' => (string)$row['group_name'],
        ];
    }

    pf_json(['success' => true, 'pilgrims' => $pilgrims]);
}

if (isset($_GET['action']) && $_GET['action'] === 'hotel_end_date_pilgrims') {
    $hotel = trim((string)($_GET['hotel_name'] ?? ''));
    $endDate = pf_normalize_date($_GET['end_date'] ?? '');
    $departed = pf_normalize_date($_GET['departed'] ?? '');

    if ($hotel === '' || $endDate === '' || $departed === '') {
        pf_json(['success' => false, 'message' => 'يرجى اختيار الفندق وتاريخ نهاية الحجز وتاريخ الترحيل.'], 400);
    }

    $stmt = $pdo->prepare("SELECT
            p.barcode,
            p.name,
            p.app_id,
            p.`group`,
            COALESCE(g.master_group, hp.group_name, p.`group`) AS group_name,
            hp.hotel_name,
            hp.floor,
            hp.room_num,
            r.end_date
        FROM hotel_pilgrim hp
        JOIN pilgrim p ON p.barcode = hp.barcode
        LEFT JOIN `group` g ON g.`group` = p.`group`
        JOIN res r
          ON r.hotel_name = hp.hotel_name
         AND r.floor = hp.floor
         AND r.room_num = hp.room_num
         AND r.group_name = hp.group_name
        WHERE hp.hotel_name = :hotel
          AND date(r.end_date) = date(:end_date)
          AND NOT EXISTS (SELECT 1 FROM pilgrim_flight pf WHERE pf.barcode = hp.barcode)
        ORDER BY group_name COLLATE NOCASE ASC, hp.floor, hp.room_num, p.name COLLATE NOCASE ASC");
    $stmt->execute([':hotel' => $hotel, ':end_date' => $endDate]);

    $seen = [];
    $pilgrims = [];
    foreach ($stmt->fetchAll() as $row) {
        $barcode = (string)$row['barcode'];
        if (isset($seen[$barcode])) {
            continue;
        }
        $seen[$barcode] = true;
        $pilgrims[] = [
            'id' => $barcode,
            'text' => pf_pilgrim_text($row),
            'barcode' => $barcode,
            'name' => (string)$row['name'],
            'app_id' => (string)$row['app_id'],
            'group_name' => (string)$row['group_name'],
            'hotel_name' => (string)$row['hotel_name'],
            'floor' => (string)$row['floor'],
            'room_num' => (string)$row['room_num'],
            'end_date' => (string)$row['end_date'],
            'departed' => $departed,
        ];
    }

    pf_json(['success' => true, 'pilgrims' => $pilgrims]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save') {
    $departed = pf_normalize_date($_POST['departed'] ?? '');
    if ($departed === '') {
        pf_json(['success' => false, 'message' => 'تاريخ الترحيل غير صحيح.'], 400);
    }

    $barcodes = [];
    foreach ((array)($_POST['barcodes'] ?? []) as $barcode) {
        $barcode = trim((string)$barcode);
        if ($barcode !== '') {
            $barcodes[$barcode] = true;
        }
    }
    foreach (pf_parse_barcodes((string)($_POST['pasted_barcodes'] ?? '')) as $barcode) {
        $barcodes[$barcode] = true;
    }

    if (empty($barcodes)) {
        pf_json(['success' => false, 'message' => 'يرجى اختيار أو لصق باركود واحد على الأقل.'], 400);
    }

    $stmtPilgrim = $pdo->prepare("SELECT COUNT(*) FROM pilgrim WHERE barcode = ?");
    $stmtDeparted = $pdo->prepare("SELECT COUNT(*) FROM pilgrim_flight WHERE barcode = ?");
    $stmtInsert = $pdo->prepare("INSERT OR IGNORE INTO pilgrim_flight (barcode, departed) VALUES (?, ?)");

    $inserted = 0;
    $invalid = [];
    $alreadyDeparted = [];

    $pdo->beginTransaction();
    try {
        foreach (array_keys($barcodes) as $barcode) {
            $stmtPilgrim->execute([$barcode]);
            if ((int)$stmtPilgrim->fetchColumn() === 0) {
                $invalid[] = $barcode;
                continue;
            }

            $stmtDeparted->execute([$barcode]);
            if ((int)$stmtDeparted->fetchColumn() > 0) {
                $alreadyDeparted[] = $barcode;
                continue;
            }

            $stmtInsert->execute([$barcode, $departed]);
            $inserted += $stmtInsert->rowCount();
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        pf_json(['success' => false, 'message' => 'فشل حفظ الترحيل: ' . $e->getMessage()], 500);
    }

    pf_json([
        'success' => true,
        'inserted' => $inserted,
        'invalid' => $invalid,
        'already_departed' => $alreadyDeparted,
        'message' => 'تم حفظ الترحيل.',
    ]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0) {
        pf_json(['success' => false, 'message' => 'رقم السجل غير صحيح.'], 400);
    }

    $stmt = $pdo->prepare("DELETE FROM pilgrim_flight WHERE id = ?");
    $stmt->execute([$id]);
    pf_json(['success' => true]);
}

$today = date('Y-m-d');
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ترحيل الحجاج</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.rtl.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css">
    <style>
        body {
            background: #f5f7fb;
            font-family: "Segoe UI", Tahoma, Arial, sans-serif;
        }
        .page-card {
            background: #fff;
            border-radius: 16px;
            box-shadow: 0 8px 24px rgba(15, 23, 42, 0.08);
            padding: 24px;
            margin-bottom: 24px;
        }
        .section-title {
            font-weight: 700;
            color: #1e293b;
            margin-bottom: 18px;
        }
        .select2-container {
            width: 100% !important;
        }
        .preview-table-wrapper {
            max-height: 360px;
            overflow: auto;
        }
    </style>
</head>
<body>
    <div class="container-fluid pt-2">
        <?php render_root_navbar('departures'); ?>
    </div>

    <main class="container py-4">
        <div class="d-flex align-items-center justify-content-between mb-4">
            <div>
                <h1 class="mb-1"><i class="bi bi-send-check"></i> ترحيل الحجاج</h1>
                <p class="text-muted mb-0">اختر الحجاج فردياً، بالصق الباركود، بالمجموعة، أو حسب فندق وتاريخ نهاية الحجز.</p>
            </div>
        </div>

        <div class="page-card">
            <div class="d-flex align-items-center justify-content-between gap-3">
                <h4 class="section-title mb-0">إضافة ترحيل</h4>
                <button class="btn btn-outline-primary" type="button" data-bs-toggle="collapse" data-bs-target="#departureForm" aria-expanded="false" aria-controls="departureForm">
                    <i class="bi bi-chevron-down"></i> إظهار / إخفاء النموذج
                </button>
            </div>
            <form id="departureForm" class="collapse mt-4">
                <div class="row g-3">
                    <div class="col-md-4">
                        <label for="departedDate" class="form-label">تاريخ الترحيل</label>
                        <input type="date" class="form-control" id="departedDate" value="<?= pf_e($today) ?>" required>
                    </div>
                    <div class="col-md-8">
                        <label for="pilgrimSelect" class="form-label">اختيار الحجاج</label>
                        <select id="pilgrimSelect" class="form-control" multiple></select>
                    </div>
                    <div class="col-12">
                        <label for="pastedBarcodes" class="form-label">لصق باركودات الحجاج</label>
                        <textarea id="pastedBarcodes" class="form-control" rows="3" placeholder="يمكن لصق الباركودات مفصولة بسطر جديد أو مسافة أو فاصلة"></textarea>
                    </div>
                </div>

                <hr>

                <div class="row g-3 align-items-end">
                    <div class="col-md-8">
                        <label for="groupSelect" class="form-label">اختيار مجموعة كاملة</label>
                        <select id="groupSelect" class="form-control"></select>
                    </div>
                    <div class="col-md-4">
                        <button type="button" class="btn btn-outline-primary w-100" id="checkGroupBtn">
                            <i class="bi bi-people"></i> فحص وإضافة المجموعة
                        </button>
                    </div>
                </div>

                <hr>

                <div class="row g-3 align-items-end">
                    <div class="col-md-4">
                        <label for="hotelSelect" class="form-label">الفندق</label>
                        <select id="hotelSelect" class="form-control"></select>
                    </div>
                    <div class="col-md-3">
                        <label for="reservationEndDate" class="form-label">تاريخ نهاية الحجز</label>
                        <input type="date" class="form-control" id="reservationEndDate">
                    </div>
                    <div class="col-md-3">
                        <label for="hotelDepartedDate" class="form-label">تاريخ الترحيل لهذه الدفعة</label>
                        <input type="date" class="form-control" id="hotelDepartedDate" value="<?= pf_e($today) ?>">
                    </div>
                    <div class="col-md-2">
                        <button type="button" class="btn btn-outline-success w-100" id="checkHotelBtn">
                            <i class="bi bi-search"></i> فحص
                        </button>
                    </div>
                </div>

                <div id="hotelPreviewBox" class="mt-4 d-none">
                    <div class="alert alert-info">
                        راجع الحجاج أدناه. يمكنك حذف أي حاج من الاختيار عبر خانة اختيار الحجاج قبل الحفظ.
                    </div>
                    <div class="preview-table-wrapper">
                        <table class="table table-sm table-striped align-middle">
                            <thead>
                                <tr>
                                    <th>الباركود</th>
                                    <th>الاسم</th>
                                    <th>المجموعة</th>
                                    <th>الطابق</th>
                                    <th>الغرفة</th>
                                    <th>نهاية الحجز</th>
                                    <th>تاريخ الترحيل</th>
                                </tr>
                            </thead>
                            <tbody id="hotelPreviewBody"></tbody>
                        </table>
                    </div>
                </div>

                <div class="mt-4 d-flex gap-2">
                    <button type="submit" class="btn btn-primary" id="saveDepartureBtn">
                        <i class="bi bi-check-circle"></i> حفظ الترحيل
                    </button>
                    <button type="button" class="btn btn-outline-secondary" id="clearSelectionBtn">
                        <i class="bi bi-x-circle"></i> مسح الاختيارات
                    </button>
                </div>
            </form>
        </div>

        <div class="page-card">
            <h4 class="section-title">سجل الحجاج المرحلين</h4>
            <div class="table-responsive">
                <table id="departureTable" class="table table-striped table-bordered w-100">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>الباركود</th>
                            <th>الاسم</th>
                            <th>الجواز / التطبيق</th>
                            <th>المجموعة</th>
                            <th>التكتل</th>
                            <th>تاريخ الترحيل</th>
                            <th>الإجراءات</th>
                        </tr>
                    </thead>
                    <tbody></tbody>
                </table>
            </div>
        </div>
    </main>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script>
        $(function () {
            const table = $('#departureTable').DataTable({
                processing: true,
                serverSide: true,
                ajax: {
                    url: 'pilgrim_flight.php?action=datatable',
                    type: 'GET'
                },
                order: [[0, 'desc']],
                columns: [
                    { data: 'id' },
                    { data: 'barcode' },
                    { data: 'name' },
                    { data: 'app_id' },
                    { data: 'pilgrim_group' },
                    { data: 'master_group' },
                    { data: 'departed' },
                    { data: 'actions', orderable: false, searchable: false }
                ],
                language: { url: 'https://cdn.datatables.net/plug-ins/1.13.6/i18n/ar.json' }
            });

            $('#departureTable_filter input').off().on('keypress', function (e) {
                if (e.which === 13) {
                    table.search(this.value).draw();
                }
            });

            $('#pilgrimSelect').select2({
                dir: 'rtl',
                width: '100%',
                placeholder: 'ابحث بالباركود أو الاسم أو الجواز أو المجموعة',
                ajax: {
                    url: 'pilgrim_flight.php?action=search_pilgrims',
                    dataType: 'json',
                    delay: 250,
                    data: params => ({ q: params.term || '', page: params.page || 1 }),
                    processResults: data => data
                }
            });

            $('#groupSelect').select2({
                dir: 'rtl',
                width: '100%',
                placeholder: 'اختر المجموعة',
                ajax: {
                    url: 'pilgrim_flight.php?action=search_groups',
                    dataType: 'json',
                    delay: 250,
                    data: params => ({ q: params.term || '', page: params.page || 1 }),
                    processResults: data => data
                }
            });

            $('#hotelSelect').select2({
                dir: 'rtl',
                width: '100%',
                placeholder: 'اختر الفندق',
                ajax: {
                    url: 'res_hotels.php',
                    dataType: 'json',
                    delay: 250,
                    data: params => ({ q: params.term || '', page: params.page || 1 }),
                    processResults: data => data
                }
            });

            function esc(value) {
                return String(value ?? '').replace(/[&<>"']/g, function (ch) {
                    return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' })[ch];
                });
            }

            function addPilgrimOption(item) {
                const id = String(item.id || item.barcode || '').trim();
                if (!id) return;
                const text = item.text || [item.barcode, item.name, item.group_name].filter(Boolean).join(' | ');
                const $select = $('#pilgrimSelect');
                if ($select.find('option[value="' + $.escapeSelector(id) + '"]').length === 0) {
                    $select.append(new Option(text, id, true, true));
                } else {
                    $select.find('option[value="' + $.escapeSelector(id) + '"]').prop('selected', true);
                }
                $select.trigger('change');
            }

            function renderHotelPreview(rows) {
                const $body = $('#hotelPreviewBody').empty();
                rows.forEach(function (row) {
                    $body.append(`
                        <tr data-barcode="${esc(row.barcode)}">
                            <td>${esc(row.barcode)}</td>
                            <td>${esc(row.name)}</td>
                            <td>${esc(row.group_name)}</td>
                            <td>${esc(row.floor)}</td>
                            <td>${esc(row.room_num)}</td>
                            <td>${esc(row.end_date)}</td>
                            <td>${esc(row.departed)}</td>
                        </tr>
                    `);
                });
                $('#hotelPreviewBox').toggleClass('d-none', rows.length === 0);
            }

            $('#checkGroupBtn').on('click', function () {
                const group = $('#groupSelect').val();
                if (!group) {
                    alert('يرجى اختيار المجموعة.');
                    return;
                }

                $.getJSON('pilgrim_flight.php', { action: 'group_pilgrims', group: group })
                    .done(function (resp) {
                        if (!resp.success) {
                            alert(resp.message || 'تعذر جلب حجاج المجموعة.');
                            return;
                        }
                        (resp.pilgrims || []).forEach(addPilgrimOption);
                        alert('تمت إضافة ' + (resp.pilgrims || []).length + ' حاج إلى الاختيار.');
                    })
                    .fail(function () {
                        alert('تعذر الاتصال بالخادم.');
                    });
            });

            $('#checkHotelBtn').on('click', function () {
                const hotelName = $('#hotelSelect').val();
                const endDate = $('#reservationEndDate').val();
                const departed = $('#hotelDepartedDate').val();

                if (!hotelName || !endDate || !departed) {
                    alert('يرجى اختيار الفندق وتاريخ نهاية الحجز وتاريخ الترحيل.');
                    return;
                }

                $('#departedDate').val(departed);
                $.getJSON('pilgrim_flight.php', {
                    action: 'hotel_end_date_pilgrims',
                    hotel_name: hotelName,
                    end_date: endDate,
                    departed: departed
                }).done(function (resp) {
                    if (!resp.success) {
                        alert(resp.message || 'تعذر جلب الحجاج.');
                        return;
                    }
                    const rows = resp.pilgrims || [];
                    rows.forEach(addPilgrimOption);
                    renderHotelPreview(rows);
                    alert('تم العثور على ' + rows.length + ' حاج غير مرحل.');
                }).fail(function () {
                    alert('تعذر الاتصال بالخادم.');
                });
            });

            $('#pilgrimSelect').on('change', function () {
                const selected = new Set(($(this).val() || []).map(String));
                $('#hotelPreviewBody tr').each(function () {
                    const barcode = String($(this).data('barcode'));
                    $(this).toggle(selected.has(barcode));
                });
            });

            $('#clearSelectionBtn').on('click', function () {
                $('#pilgrimSelect').val(null).trigger('change');
                $('#pastedBarcodes').val('');
                renderHotelPreview([]);
            });

            $('#departureForm').on('submit', function (e) {
                e.preventDefault();
                const barcodes = $('#pilgrimSelect').val() || [];
                const pasted = $('#pastedBarcodes').val();
                const departed = $('#departedDate').val();

                if (!departed) {
                    alert('يرجى اختيار تاريخ الترحيل.');
                    return;
                }
                if (barcodes.length === 0 && !pasted.trim()) {
                    alert('يرجى اختيار أو لصق باركود واحد على الأقل.');
                    return;
                }

                $('#saveDepartureBtn').prop('disabled', true).text('جاري الحفظ...');
                $.ajax({
                    url: 'pilgrim_flight.php',
                    type: 'POST',
                    dataType: 'json',
                    data: {
                        action: 'save',
                        departed: departed,
                        barcodes: barcodes,
                        pasted_barcodes: pasted
                    }
                }).done(function (resp) {
                    if (!resp.success) {
                        alert(resp.message || 'فشل حفظ الترحيل.');
                        return;
                    }
                    const invalid = (resp.invalid || []).length;
                    const already = (resp.already_departed || []).length;
                    alert('تم إدراج ' + resp.inserted + ' حاج. غير موجود: ' + invalid + '. مرحل مسبقاً: ' + already + '.');
                    $('#pilgrimSelect').val(null).trigger('change').empty();
                    $('#pastedBarcodes').val('');
                    renderHotelPreview([]);
                    table.ajax.reload(null, false);
                }).fail(function (xhr) {
                    const resp = xhr.responseJSON || {};
                    alert(resp.message || 'تعذر الاتصال بالخادم.');
                }).always(function () {
                    $('#saveDepartureBtn').prop('disabled', false).html('<i class="bi bi-check-circle"></i> حفظ الترحيل');
                });
            });

            $('#departureTable').on('click', '.delete-departure', function () {
                const id = $(this).data('id');
                if (!id || !confirm('هل تريد حذف سجل الترحيل؟')) {
                    return;
                }

                $.post('pilgrim_flight.php', { action: 'delete', id: id }, function (resp) {
                    if (resp && resp.success) {
                        table.ajax.reload(null, false);
                    } else {
                        alert((resp && resp.message) || 'فشل الحذف.');
                    }
                }, 'json').fail(function () {
                    alert('تعذر الاتصال بالخادم.');
                });
            });
        });
    </script>
</body>
</html>
