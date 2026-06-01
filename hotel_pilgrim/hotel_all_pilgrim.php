<?php
declare(strict_types=1);

include('../check.php');
require_once __DIR__ . '/nav.php';
require_once __DIR__ . '/mg_cookie.php';

// ---------- DB SETUP ----------
$pdo = new PDO('sqlite:../hajj_data.db');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

// Optional cookie read (kept in case other back-end endpoints rely on it)
$master_group = mg_cookie_get();

// Small helper for safe HTML
function e(string $v): string { return htmlspecialchars($v, ENT_QUOTES, 'UTF-8'); }

// ---------- DATATABLES SERVER-SIDE ENDPOINT ----------
if (isset($_GET['action']) && $_GET['action'] === 'datatable') {
    // DataTables parameters
    $draw   = isset($_GET['draw']) ? (int)$_GET['draw'] : 0;
    $start  = isset($_GET['start']) ? max(0, (int)$_GET['start']) : 0;
    $length = isset($_GET['length']) ? (int)$_GET['length'] : 10;
    $searchValue = trim($_GET['search']['value'] ?? '');

    // Map DataTables columns (index -> SQL column)
    $columns = [
        0 => 'hp.id',           // h_id
        1 => 'hp.hotel_name',   // hotel_name
        2 => 'hp.floor',        // floor
        3 => 'hp.room_num',     // room_num
        4 => 'hp.barcode',      // barcode
        5 => 'p.name',          // pilgrim name
        6 => 'p.app_id',        // passport
        7 => 'hp.note',         // note
    ];

    $orderColIdx = isset($_GET['order'][0]['column']) ? (int)$_GET['order'][0]['column'] : 0;
    $orderCol    = $columns[$orderColIdx] ?? 'hp.id';
    $orderDir    = (isset($_GET['order'][0]['dir']) && strtolower($_GET['order'][0]['dir']) === 'desc') ? 'DESC' : 'ASC';

    // Base FROM/JOIN
    $fromJoin = " FROM hotel_pilgrim hp
                  LEFT JOIN pilgrim p ON hp.barcode = p.barcode ";

    // Total records (no filter)
    $recordsTotal = (int)$pdo->query("SELECT COUNT(*) FROM hotel_pilgrim")->fetchColumn();

    // Filtering
    $where = '';
    $params = [];
    if ($searchValue !== '') {
        // Smart fuzzy search: turn whitespace into SQL wildcards so
        // "عبد المنعم" matches a name like "عبد الرحمن المنعم".
        $fuzzy = preg_replace('/\s+/u', '%', $searchValue);
        // Use LIKE across useful columns
        $where = " WHERE
            hp.hotel_name LIKE :s OR
            hp.floor      LIKE :s OR
            hp.room_num   LIKE :s OR
            hp.barcode    LIKE :s OR
            p.name        LIKE :s OR
            p.app_id      LIKE :s OR
            hp.note       LIKE :s
        ";
        $params[':s'] = '%'.$fuzzy.'%';
    }

    // Filtered count
    $countSql = "SELECT COUNT(*) ".$fromJoin.$where;
    $stmtCount = $pdo->prepare($countSql);
    $stmtCount->execute($params);
    $recordsFiltered = (int)$stmtCount->fetchColumn();

    // Data query
    $select = "SELECT
        hp.id         AS h_id,
        hp.hotel_name AS hotel_name,
        hp.floor      AS floor,
        hp.room_num   AS room_num,
        hp.barcode    AS barcode,
        COALESCE(p.name, '')   AS pilgrim_name,
        COALESCE(p.app_id, '') AS passport,
        COALESCE(hp.note, '')  AS note
    ";
    $orderLimit = " ORDER BY {$orderCol} {$orderDir} ".
                  ($length > 0 ? " LIMIT :limit OFFSET :offset" : "");

    $stmtData = $pdo->prepare($select.$fromJoin.$where.$orderLimit);

    // Bind search
    foreach ($params as $k => $v) {
        $stmtData->bindValue($k, $v, PDO::PARAM_STR);
    }
    // Bind paging
    if ($length > 0) {
        $stmtData->bindValue(':limit', $length, PDO::PARAM_INT);
        $stmtData->bindValue(':offset', $start, PDO::PARAM_INT);
    }

    $stmtData->execute();
    $rows = $stmtData->fetchAll();

    // Build DataTables rows
    $data = [];
    foreach ($rows as $r) {
        // Escape before embedding in DOM
        $h_id        = (int)$r['h_id'];
        $hotel_name  = e((string)$r['hotel_name']);
        $floor       = e((string)$r['floor']);
        $room_num    = e((string)$r['room_num']);
        $barcode     = e((string)$r['barcode']);
        $name        = e((string)$r['pilgrim_name']);
        $passport    = e((string)$r['passport']);
        $note        = e((string)$r['note']);

        // Action buttons (data- attributes hold raw text already escaped)
        $actions = '
            <button class="btn btn-warning btn-sm editBtn"
                data-id="'.$h_id.'"
                data-hotel_name="'.$hotel_name.'"
                data-floor="'.$floor.'"
                data-room_num="'.$room_num.'"
                data-barcode="'.$barcode.'"
                data-note="'.$note.'">
                <i class="fas fa-edit"></i> تعديل
            </button>
            <button class="btn btn-danger btn-sm deleteBtn" data-id="'.$h_id.'">
                <i class="fas fa-trash"></i> حذف
            </button>';

        $data[] = [
            'h_id'       => $h_id,
            'hotel_name' => $hotel_name,
            'floor'      => $floor,
            'room_num'   => $room_num,
            'barcode'    => $barcode,
            'name'       => $name,
            'passport'   => $passport,
            'note'       => $note,
            'actions'    => $actions
        ];
    }

    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode([
        'draw'            => $draw,
        'recordsTotal'    => $recordsTotal,
        'recordsFiltered' => $recordsFiltered,
        'data'            => $data,
    ]);
    exit;
}

// ---------- HOTELS FOR SELECT2 (page load) ----------
$stmt_hotels = $pdo->query("SELECT DISTINCT hotel_name FROM hotel ORDER BY hotel_name COLLATE NOCASE ASC");
$hotels = $stmt_hotels->fetchAll();
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="x-ua-compatible" content="ie=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>إدارة إسكان الحجاج</title>

    <!-- Preconnect for performance -->
    <link rel="preconnect" href="https://cdn.jsdelivr.net" crossorigin>
    <link rel="preconnect" href="https://cdnjs.cloudflare.com" crossorigin>
    <link rel="preconnect" href="https://code.jquery.com" crossorigin>

    <!-- Bootstrap 4 RTL -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.6.1/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.rtlcss.com/bootstrap/v4.6.1/css/bootstrap.min.css">

    <!-- Select2 + DataTables -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/css/select2.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.8/css/jquery.dataTables.min.css">

    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">

    <style>
        body {
            font-family: 'Segoe UI', Tahoma, Arial, sans-serif;
            background: #f5f7fa;
            direction: rtl;
        }
        .container {
            background: #fff;
            border-radius: 15px;
            box-shadow: 0 5px 30px rgba(0, 0, 0, 0.08);
            padding: 30px;
            margin-top: 30px;
        }
        h1 {
            color: #2c3e50;
            font-weight: 600;
            margin-bottom: 30px;
            position: relative;
            padding-bottom: 15px;
        }
        h1::after {
            content: '';
            position: absolute;
            bottom: 0;
            right: 50%;
            transform: translateX(50%);
            width: 100px;
            height: 3px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border-radius: 3px;
        }
        .btn-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border: none;
            padding: 10px 25px;
            font-weight: 600;
            border-radius: 10px;
            transition: all 0.3s;
            box-shadow: 0 4px 15px rgba(102, 126, 234, 0.3);
        }
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(102, 126, 234, 0.4);
        }
        .btn-warning {
            background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
            border: none; color: #fff; font-weight: 600; transition: all 0.3s;
        }
        .btn-danger {
            background: linear-gradient(135deg, #f5576c 0%, #ff4458 100%);
            border: none; color: #fff; font-weight: 600; transition: all 0.3s;
        }

        table { margin-top: 20px; width: 100%; }
        .table thead th {
            background: #f8f9fa;
            color: #2c3e50;
            font-weight: 600;
            border: none;
            padding: 15px;
            text-align: right;
            white-space: nowrap;
        }
        .table td {
            padding: 12px 15px;
            vertical-align: middle;
            text-align: right;
        }
        .table-striped tbody tr:nth-of-type(odd) { background-color: rgba(0,0,0,0.02); }
        .table-striped tbody tr:hover { background-color: rgba(102,126,234,0.08); transition: background-color 0.3s; }

        /* DataTables RTL adjustments */
        .dataTables_wrapper { direction: rtl; }
        .dataTables_filter { text-align: left !important; }
        .dataTables_filter input {
            margin-right: 0.5em;
            margin-left: 0;
            padding: 8px 15px;
            border-radius: 8px;
            border: 1px solid #ddd;
        }
        .dataTables_length { text-align: right !important; }

        /* Pagination styling */
        .dataTables_paginate .paginate_button {
            padding: 8px 15px; margin: 0 3px; border-radius: 8px;
            border: 1px solid #ddd; background: #fff; color: #495057; cursor: pointer; transition: all 0.3s;
        }
        .dataTables_paginate .paginate_button:hover {
            background: #667eea; color: #fff; border-color: #667eea;
        }
        .dataTables_paginate .paginate_button.current {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: #fff; border-color: transparent;
        }

        /* Modal */
        .modal-content { border-radius: 15px; border: none; }
        .modal-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: #fff; border-radius: 15px 15px 0 0; border: none;
        }
        .modal-title { font-weight: 600; }
        .close { color: #fff; opacity: 1; text-shadow: none; }
        .close:hover { color: #f0f0f0; }

        /* Forms */
        .form-group label { color: #495057; font-weight: 600; margin-bottom: 8px; }
        .form-control {
            border-radius: 10px; border: 2px solid #e1e4e8; padding: 10px 15px; transition: all 0.3s; direction: rtl;
        }
        .form-control:focus {
            border-color: #667eea; box-shadow: 0 0 0 3px rgba(102,126,234,0.1);
        }
        textarea.form-control { min-height: 100px; resize: vertical; }

        /* Select2 RTL */
        .select2-container--default .select2-selection--single,
        .select2-container--default .select2-selection--multiple {
            border-radius: 10px; border: 2px solid #e1e4e8; min-height: 45px; padding: 5px 15px; direction: rtl;
        }
        .select2-container--default.select2-container--focus .select2-selection--single,
        .select2-container--default.select2-container--focus .select2-selection--multiple {
            border-color: #667eea; box-shadow: 0 0 0 3px rgba(102,126,234,0.1);
        }
        .select2-dropdown { border-radius: 10px; border-color: #e1e4e8; direction: rtl; }
        .select2-search__field { direction: rtl; }
        .select2-container--default .select2-selection--single .select2-selection__arrow { left: 10px; right: auto; }

        .btn-sm { padding: 5px 15px; margin: 0 3px; border-radius: 8px; font-size: 14px; }

        @media (max-width: 768px) {
            .container { padding: 15px; margin-top: 15px; }
            h1 { font-size: 24px; }
            .table { font-size: 14px; }
            .btn-sm { padding: 3px 10px; font-size: 12px; }
        }
    </style>
</head>
<body>
    <div class="container">
        <?php render_hotel_pilgrim_navbar('all', $master_group); ?>
        <h1 class="text-center">إدارة إسكان الحجاج</h1>

        <button class="btn btn-primary" id="createBtn">
            <i class="fas fa-plus"></i>
            إضافة سجل جديد
        </button>
        <button class="btn btn-success" id="bulkAssignBtn">
            <i class="fas fa-people-arrows"></i>
            تعيين حجاج بالجملة
        </button>

        <table id="hotel_pilgrim_table" class="table table-striped" style="width:100%">
            <thead>
                <tr>
                    <th>الرقم</th>
                    <th>اسم الفندق</th>
                    <th>الطابق</th>
                    <th>رقم الغرفة</th>
                    <th>الباركود</th>
                    <th>الاسم</th>
                    <th>جواز السفر</th>
                    <th>ملاحظات</th>
                    <th>الإجراءات</th>
                </tr>
            </thead>
            <tbody></tbody>
        </table>

        <!-- Modal for Create/Edit -->
        <div id="modal" class="modal fade" tabindex="-1" role="dialog" aria-hidden="true">
            <div class="modal-dialog modal-lg">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title" id="modalTitle">إضافة سجل جديد</h5>
                        <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                            <span aria-hidden="true">&times;</span>
                        </button>
                    </div>
                    <div class="modal-body">
                        <form id="form" method="POST" autocomplete="off">
                            <input type="hidden" id="recordId">

                            <!-- Hotel Name -->
                            <div class="form-group">
                                <label for="hotel_name">اسم الفندق:</label>
                                <select id="hotel_name" class="form-control select2" name="hotel_name" required>
                                    <option value="">اختر الفندق</option>
                                    <?php foreach ($hotels as $hotel): ?>
                                        <option value="<?= e((string)$hotel['hotel_name']) ?>"><?= e((string)$hotel['hotel_name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <!-- Floor -->
                            <div class="form-group">
                                <label for="floor">الطابق:</label>
                                <select id="floor" class="form-control select2" name="floor" required disabled>
                                    <option value="">اختر الطابق</option>
                                </select>
                            </div>

                            <!-- Room -->
                            <div class="form-group">
                                <label for="room_num">رقم الغرفة:</label>
                                <select id="room_num" class="form-control select2" name="room_num" required disabled>
                                    <option value="">اختر الغرفة</option>
                                </select>
                            </div>

                            <!-- Barcode (multiple on create) -->
                            <div class="form-group">
                                <label for="barcode">الباركود:</label>
                                <select id="barcode" class="form-control select2" name="barcode" required multiple>
                                    <!-- loaded via AJAX -->
                                </select>
                                <small class="form-text text-muted" id="barcodeHelp">
                                    يمكنك اختيار أكثر من باركود عند إضافة سجلات جديدة. عند تعديل سجل يجب اختيار باركود واحد فقط.
                                </small>
                            </div>

                            <!-- Note -->
                            <div class="form-group">
                                <label for="note">ملاحظات:</label>
                                <textarea class="form-control" id="note" name="note" placeholder="أدخل أي ملاحظات إضافية"></textarea>
                            </div>

                            <button type="submit" class="btn btn-primary" id="submitBtn">
                                <i class="fas fa-save"></i> حفظ
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div><!-- /modal -->

        <!-- Bulk Assign (3 columns: barcode, hotel, room — floor auto-derived) -->
        <div id="bulkAssignModal" class="modal fade" tabindex="-1" role="dialog" aria-hidden="true">
            <div class="modal-dialog modal-xl">
                <div class="modal-content">
                    <div class="modal-header bg-success text-white">
                        <h5 class="modal-title"><i class="fas fa-people-arrows"></i> تعيين حجاج بالجملة</h5>
                        <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close">
                            <span aria-hidden="true">&times;</span>
                        </button>
                    </div>
                    <div class="modal-body">
                        <div class="alert alert-info small mb-2">
                            الصق ثلاثة أعمدة من Excel مفصولة بـ <code>TAB</code> بالترتيب:
                            <b>الباركود</b> &nbsp;|&nbsp; <b>الفندق</b> &nbsp;|&nbsp; <b>رقم الغرفة</b><br>
                            سيقوم النظام بالتحقق أن كل غرفة محجوزة لهذا التكتل واستخراج رقم الطابق تلقائياً من الحجوزات.
                        </div>
                        <pre class="bg-light p-2 rounded small mb-2" style="direction:ltr;text-align:left;">1744&#9;فندق المثال&#9;305
1745&#9;فندق المثال&#9;306</pre>
                        <textarea id="bulkAssignTextarea" class="form-control" rows="8" placeholder="الصق البيانات هنا..." style="font-family:Consolas,'Courier New',monospace;"></textarea>
                        <div id="bulkAssignSummary" class="mt-3"></div>
                        <div id="bulkAssignResults" class="mt-2"></div>
                    </div>
                    <div class="modal-footer">
                        <button class="btn btn-outline-primary" id="validateBulkAssignBtn">
                            <i class="fas fa-check-circle"></i> تحقق
                        </button>
                        <button class="btn btn-success" id="runBulkAssignBtn" disabled>
                            <i class="fas fa-save"></i> تأكيد التعيين
                        </button>
                        <button class="btn btn-secondary" data-dismiss="modal">إغلاق</button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Scripts (deferred for performance) -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js" defer></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@4.6.1/dist/js/bootstrap.bundle.min.js" defer></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/js/select2.min.js" defer></script>
    <script src="https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js" defer></script>

    <script defer>
    // Ensure scripts run after they load with defer
    document.addEventListener('DOMContentLoaded', function () {
        // --- Select2 defaults ---
        function initSelect2($el) {
            $el.select2({
                dir: "rtl",
                width: "100%",
                dropdownParent: $('#modal'),
                language: {
                    inputTooShort: function () { return "الرجاء إدخال 3 أحرف أو أكثر"; },
                    searching: function () { return "جاري البحث..."; },
                    noResults: function () { return "لم يتم العثور على نتائج"; }
                }
            });
        }

        // Initialize Select2 static selects
        initSelect2($('#hotel_name'));
        initSelect2($('#floor'));
        initSelect2($('#room_num'));

        // Barcode Select2 (AJAX)
        $('#barcode').select2({
            dir: "rtl",
            width: "100%",
            dropdownParent: $('#modal'),
            placeholder: 'ابحث بالباركود أو الاسم أو الجواز',
            multiple: true,
            ajax: {
                url: '/hotel_pilgrim/pilgrims_data.php',
                dataType: 'json',
                delay: 700,
                data: function (params) {
                    return { q: params.term, page: params.page || 1 };
                },
                processResults: function (data, params) {
                    params.page = params.page || 1;
                    // Expect each item to have: barcode, name, passport, group
                    return {
                        results: (data || []).map(function (item) {
                            var bc = item.barcode || '';
                            var nm = item.name || '';
                            var pp = item.passport || item.app_id || '';
                            var group = item.group || '';
                            return {
                                id: bc,
                                text: [pp, nm, group, bc].filter(Boolean).join(' | ')
                            };
                        }),
                        pagination: { more: (data || []).length === 10 }
                    };
                },
                cache: true
            },
            minimumInputLength: 3
        });

        // --- Cascading selects (Hotel -> Floor -> Room) ---
        $('#hotel_name').on('change', function () {
            const hotelName = $(this).val();
            // Reset floor & room
            $('#floor').prop('disabled', true).empty().append('<option value="">اختر الطابق</option>').trigger('change');
            $('#room_num').prop('disabled', true).empty().append('<option value="">اختر الغرفة</option>').trigger('change');

            if (!hotelName) return;

            $.ajax({
                url: '/hotel_pilgrim/get_floors.php',
                type: 'GET',
                dataType: 'json',
                data: { hotel_name: hotelName }, // no group_name
                success: function (response) {
                    if (response && response.results && response.results.length) {
                        $('#floor').prop('disabled', false);
                        response.results.forEach(function (f) {
                            $('#floor').append('<option value="' + String(f.id).replace(/"/g,'&quot;') + '">' + String(f.text) + '</option>');
                        });
                    } else {
                        $('#floor').prop('disabled', true).empty().append('<option value="">لا توجد طوابق متاحة</option>');
                    }
                    $('#floor').trigger('change');
                },
                error: function () {
                    alert('خطأ في جلب بيانات الطوابق.');
                }
            });
        });

        $('#floor').on('change', function () {
            const hotelName = $('#hotel_name').val();
            const selectedFloor = $(this).val();

            $('#room_num').prop('disabled', true).empty().append('<option value="">اختر الغرفة</option>');

            if (!selectedFloor || !hotelName) return;

            $.ajax({
                url: '/hotel_pilgrim/get_rooms.php',
                type: 'GET',
                dataType: 'json',
                data: { hotel_name: hotelName, floor: selectedFloor },
                success: function (response) {
                    if (response && response.results && response.results.length) {
                        $('#room_num').prop('disabled', false);
                        response.results.forEach(function (room) {
                            $('#room_num').append('<option value="' + String(room.id).replace(/"/g,'&quot;') + '">' + String(room.text) + '</option>');
                        });
                    } else {
                        $('#room_num').prop('disabled', true).empty().append('<option value="">لا توجد غرف متاحة</option>');
                    }
                    $('#room_num').trigger('change');
                },
                error: function () {
                    alert('خطأ في جلب بيانات الغرف.');
                }
            });
        });

        // --- DataTable (server-side) ---
        var table = $('#hotel_pilgrim_table').DataTable({
            processing: true,
            serverSide: true,
            deferRender: true,
            searchDelay: 350,
            stateSave: true,
            ajax: {
                url: '?action=datatable',
                type: 'GET'
            },
            columns: [
                { data: 'h_id',       width: '70px' },
                { data: 'hotel_name' },
                { data: 'floor',      width: '90px' },
                { data: 'room_num',   width: '110px' },
                { data: 'barcode' },
                { data: 'name' },
                { data: 'passport' },
                { data: 'note' },
                { data: 'actions', orderable: false, searchable: false, width: '150px' }
            ],
            order: [[0, 'desc']],
            lengthMenu: [
                [10, 25, 50, 100, 500, -1],
                [10, 25, 50, 100, "500", "الكل"]
            ],
            pageLength: 10,
            language: {
                processing:   "جاري المعالجة...",
                search:       "بحث:",
                lengthMenu:   "عرض _MENU_ سجلات",
                info:         "عرض _START_ إلى _END_ من _TOTAL_ سجل",
                infoEmpty:    "عرض 0 إلى 0 من 0 سجلات",
                infoFiltered: "(تمت التصفية من _MAX_ سجل)",
                loadingRecords: "جاري التحميل...",
                zeroRecords:  "لم يتم العثور على سجلات مطابقة",
                emptyTable:   "لا توجد بيانات متاحة في الجدول",
                paginate: {
                    first:    "الأول",
                    previous: "السابق",
                    next:     "التالي",
                    last:     "الأخير"
                }
            }
        });

        $('#hotel_pilgrim_table_filter input')
    .unbind() // remove default keyup binding
    .bind('keypress', function (e) {
        if (e.which === 13) { // Enter key
            table.search(this.value).draw();
        }
    });

        // --- Create new record ---
        $('#createBtn').on('click', function () {
            $('#modalTitle').text("إضافة سجل جديد");
            $('#form')[0].reset();
            $('#recordId').val('');
            // reset selects
            $('#hotel_name').val('').trigger('change');
            $('#floor').empty().append('<option value="">اختر الطابق</option>').prop('disabled', true).trigger('change');
            $('#room_num').empty().append('<option value="">اختر الغرفة</option>').prop('disabled', true).trigger('change');
            $('#barcode').val(null).trigger('change');
            $('#note').val('');
            $('#barcodeHelp').text('يمكنك اختيار أكثر من باركود عند إضافة سجلات جديدة. عند تعديل سجل يجب اختيار باركود واحد فقط.');
            $('#modal').modal('show');
        });

        // --- Edit record (delegated) ---
        $('#hotel_pilgrim_table').on('click', '.editBtn', function () {
            $('#modalTitle').text("تعديل السجل");

            const id        = $(this).data('id');
            const hotelName = $(this).data('hotel_name');
            const floor     = $(this).data('floor');
            const roomNum   = $(this).data('room_num');
            const barcode   = $(this).data('barcode');
            const note      = $(this).data('note');

            $('#recordId').val(id);
            $('#note').val(note || '');

            // Set hotel -> load floors -> set floor -> load rooms -> set room
            $('#hotel_name').val(hotelName).trigger('change');

            // Wait for floors to load, then set
            var setFloorInterval = setInterval(function () {
                if (!$('#floor').is(':disabled')) {
                    $('#floor').val(floor).trigger('change');
                    clearInterval(setFloorInterval);

                    // Wait for rooms to load, then set
                    var setRoomInterval = setInterval(function () {
                        if (!$('#room_num').is(':disabled')) {
                            $('#room_num').val(roomNum).trigger('change');
                            clearInterval(setRoomInterval);
                        }
                    }, 150);
                }
            }, 150);

            // For barcode, ensure exactly one selected on edit
            $('#barcode').empty();
            if (barcode) {
                var opt = new Option(barcode, barcode, true, true);
                $('#barcode').append(opt).trigger('change');
            }
            $('#barcodeHelp').text('عند تعديل سجل يجب اختيار باركود واحد فقط.');

            $('#modal').modal('show');
        });

        // --- Delete record (delegated) ---
        $('#hotel_pilgrim_table').on('click', '.deleteBtn', function () {
            const id = $(this).data('id');
            if (!id) return;

            if (confirm('هل أنت متأكد من حذف هذا السجل؟')) {
                $.ajax({
                    url: '/hotel_pilgrim/hotel_pilgrim_action.php',
                    type: 'POST',
                    dataType: 'json',
                    data: { action: 'delete', id: id },
                    success: function (resp) {
                        try {
                            if (typeof resp !== 'object') resp = JSON.parse(resp);
                        } catch (e) {}
                        if (resp && resp.success) {
                            table.ajax.reload(null, false); // keep page
                        } else {
                            alert('خطأ: ' + (resp && resp.message ? resp.message : 'تعذر إتمام العملية'));
                        }
                    },
                    error: function () { alert('حدث خطأ ما!'); }
                });
            }
        });

        // --- Submit (create / edit) ---
        $('#form').on('submit', function (e) {
            e.preventDefault();

            const recordId  = $('#recordId').val();
            const isEdit    = !!recordId;
            const hotelName = $('#hotel_name').val();
            const floor     = $('#floor').val();
            const roomNum   = $('#room_num').val();
            const barcodes  = $('#barcode').val(); // array (or null)
            const note      = $('#note').val();

            if (!hotelName || !floor || !roomNum) {
                alert('الرجاء اختيار الفندق والطابق والغرفة.');
                return;
            }
            if (!barcodes || barcodes.length === 0) {
                alert('الرجاء اختيار باركود واحد على الأقل.');
                return;
            }
            if (isEdit && barcodes.length !== 1) {
                alert('في وضع التعديل يجب اختيار باركود واحد فقط.');
                return;
            }

            $('#submitBtn').prop('disabled', true).text('جاري الحفظ...');

            // If creating, allow multiple barcodes (one request per barcode)
            const tasks = [];
            if (!isEdit) {
                barcodes.forEach(function (bc) {
                    tasks.push($.ajax({
                        url: '/hotel_pilgrim/hotel_pilgrim_action.php',
                        type: 'POST',
                        dataType: 'json',
                        data: {
                            action: 'create',
                            hotel_name: hotelName,
                            floor: floor,
                            room_num: roomNum,
                            barcode: bc,
                            note: note
                        }
                    }));
                });
            } else {
                tasks.push($.ajax({
                    url: '/hotel_pilgrim/hotel_pilgrim_action.php',
                    type: 'POST',
                    dataType: 'json',
                    data: {
                        action: 'edit',
                        id: recordId,
                        hotel_name: hotelName,
                        floor: floor,
                        room_num: roomNum,
                        barcode: barcodes[0],
                        note: note
                    }
                }));
            }

            Promise.all(tasks).then(function (responses) {
                let ok = true, msg = '';
                responses.forEach(function (resp) {
                    try { if (typeof resp !== 'object') resp = JSON.parse(resp); } catch (e) {}
                    if (!resp || !resp.success) {
                        ok = false;
                        msg = resp && resp.message ? resp.message : 'تعذر إتمام العملية';
                    }
                });

                if (ok) {
                    $('#modal').modal('hide');
                    table.ajax.reload(null, false); // refresh but keep paging/filter
                } else {
                    alert('خطأ: ' + msg);
                }
            }).catch(function () {
                alert('حدث خطأ ما!');
            }).finally(function () {
                $('#submitBtn').prop('disabled', false).html('<i class="fas fa-save"></i> حفظ');
            });
        });

        // =========================
        // Bulk Assign (3 cols: barcode, hotel, room — floor auto-derived)
        // =========================
        let bulkAssignRows = [];
        const baEsc = (s) => (s ?? '').toString()
            .replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');

        function bulkAssignReset() {
            bulkAssignRows = [];
            $('#bulkAssignSummary').empty();
            $('#bulkAssignResults').empty();
            $('#runBulkAssignBtn').prop('disabled', true);
        }

        $('#bulkAssignBtn').on('click', function() {
            $('#bulkAssignTextarea').val('');
            bulkAssignReset();
            $('#bulkAssignModal').modal('show');
        });

        function renderBulkAssignResults(rows) {
            if (!rows || !rows.length) {
                $('#bulkAssignResults').empty();
                return;
            }
            let html = '<div style="max-height:320px;overflow:auto;border:1px solid #dee2e6;border-radius:6px;">'
                     + '<table class="table table-sm table-striped mb-0" style="font-size:13px;">'
                     + '<thead><tr>'
                     + '<th>#</th><th>الباركود</th><th>الفندق</th><th>الطابق</th><th>رقم الغرفة</th>'
                     + '<th>التكتل</th><th>الحالة</th><th>الرسالة</th>'
                     + '</tr></thead><tbody>';
            rows.forEach(function(r) {
                const ok = r.status === 'ok';
                let badge = ok
                    ? '<span class="badge badge-success">صالح</span>'
                    : '<span class="badge badge-danger">غير صالح</span>';
                let rowClass = ok ? '' : 'table-danger';
                let messageHtml = baEsc(r.message || '');
                if (ok && r.capacity_warning) {
                    const lvl = r.capacity_warning.level;
                    if (lvl === 'over') {
                        badge += ' <span class="badge badge-warning" title="تجاوز السعة">⚠ تنبيه سعة</span>';
                        rowClass = 'table-warning';
                        messageHtml += '<div class="text-warning small mt-1"><strong>⚠</strong> ' + baEsc(r.capacity_warning.message) + '</div>';
                    } else {
                        badge += ' <span class="badge badge-info" title="أقل من السعة">ℹ ملاحظة سعة</span>';
                        messageHtml += '<div class="text-info small mt-1"><strong>ℹ</strong> ' + baEsc(r.capacity_warning.message) + '</div>';
                    }
                }
                html += '<tr class="' + rowClass + '">'
                     + '<td>' + baEsc(r.row) + '</td>'
                     + '<td>' + baEsc(r.barcode) + '</td>'
                     + '<td>' + baEsc(r.hotel_name) + '</td>'
                     + '<td>' + baEsc(r.floor || '—') + '</td>'
                     + '<td>' + baEsc(r.room_num) + '</td>'
                     + '<td>' + baEsc(r.group_name || '—') + '</td>'
                     + '<td>' + badge + '</td>'
                     + '<td>' + messageHtml + '</td>'
                     + '</tr>';
            });
            html += '</tbody></table></div>';
            $('#bulkAssignResults').html(html);
        }

        $('#validateBulkAssignBtn').on('click', function() {
            const text = $('#bulkAssignTextarea').val().trim();
            if (!text) {
                $('#bulkAssignSummary').html('<div class="alert alert-warning py-2 mb-0">لا توجد بيانات للصق.</div>');
                return;
            }
            bulkAssignReset();
            const $btn = $(this).prop('disabled', true).text('جاري التحقق...');
            $.post('/hotel_pilgrim/hotel_pilgrim_action.php',
                { action: 'bulk_assign_validate', rows_text: text },
                function(resp) {
                    $btn.prop('disabled', false).html('<i class="fas fa-check-circle"></i> تحقق');
                    let parsed = resp;
                    if (typeof parsed !== 'object') parsed = JSON.parse(resp);
                    if (!parsed.success) {
                        $('#bulkAssignSummary').html('<div class="alert alert-danger py-2 mb-0">' + baEsc(parsed.message || 'فشل التحقق.') + '</div>');
                        return;
                    }
                    renderBulkAssignResults(parsed.rows || []);
                    const total    = parsed.total          || 0;
                    const valid    = parsed.valid_count    || 0;
                    const warnings = parsed.warnings_count || 0;
                    const invalid  = total - valid;
                    const allOk    = parsed.all_valid && total > 0;
                    const cls      = allOk
                        ? (warnings > 0 ? 'alert-warning' : 'alert-success')
                        : (valid > 0 ? 'alert-warning' : 'alert-danger');
                    const txt      = allOk
                        ? (warnings > 0
                            ? `جميع الصفوف صالحة (${valid} من ${total}) — مع ${warnings} تنبيه/ملاحظة سعة. التعيين مسموح به.`
                            : `جميع الصفوف صالحة (${valid} من ${total}). يمكنك المتابعة.`)
                        : `النتيجة: ${valid} صالح / ${invalid} غير صالح من إجمالي ${total} — الرجاء إصلاح الأخطاء قبل المتابعة.`;
                    $('#bulkAssignSummary').html('<div class="alert ' + cls + ' py-2 mb-0">' + txt + '</div>');

                    if (allOk) {
                        bulkAssignRows = (parsed.rows || []).filter(function(r){ return r.status === 'ok'; });
                        $('#runBulkAssignBtn').prop('disabled', false);
                    }
                }
            ).fail(function() {
                $btn.prop('disabled', false).html('<i class="fas fa-check-circle"></i> تحقق');
                $('#bulkAssignSummary').html('<div class="alert alert-danger py-2 mb-0">تعذّر الاتصال بالخادم.</div>');
            });
        });

        $('#runBulkAssignBtn').on('click', function() {
            if (!bulkAssignRows.length) {
                $('#bulkAssignSummary').html('<div class="alert alert-warning py-2 mb-0">قم بالتحقق أولاً.</div>');
                return;
            }
            if (!confirm('سيتم تعيين ' + bulkAssignRows.length + ' حاجاً إلى الغرف. هل تريد المتابعة؟')) return;
            const $btn = $(this).prop('disabled', true).text('جاري الحفظ...');
            $.post('/hotel_pilgrim/hotel_pilgrim_action.php',
                { action: 'bulk_assign_commit', rows: JSON.stringify(bulkAssignRows) },
                function(resp) {
                    let parsed = resp;
                    if (typeof parsed !== 'object') parsed = JSON.parse(resp);
                    if (!parsed.success) {
                        $btn.prop('disabled', false).html('<i class="fas fa-save"></i> تأكيد التعيين');
                        $('#bulkAssignSummary').html('<div class="alert alert-danger py-2 mb-0">' + baEsc(parsed.message || 'فشل التعيين.') + '</div>');
                        return;
                    }
                    $('#bulkAssignSummary').html('<div class="alert alert-success py-2 mb-0">تم تعيين ' + (parsed.inserted || 0) + ' حاج بنجاح.</div>');
                    $('#bulkAssignTextarea').val('');
                    $('#bulkAssignResults').empty();
                    bulkAssignRows = [];
                    $btn.prop('disabled', true).html('<i class="fas fa-save"></i> تأكيد التعيين');
                    table.ajax.reload(null, false);
                }
            ).fail(function() {
                $btn.prop('disabled', false).html('<i class="fas fa-save"></i> تأكيد التعيين');
                $('#bulkAssignSummary').html('<div class="alert alert-danger py-2 mb-0">تعذّر الاتصال بالخادم.</div>');
            });
        });
    });
    </script>
</body>
</html>
