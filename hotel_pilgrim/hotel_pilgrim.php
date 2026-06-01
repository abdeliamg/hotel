<?php
session_start();
require_once __DIR__ . '/mg_cookie.php';
$master_group = mg_cookie_get();
if ($master_group === '') {
    header('Location: /hotel_pilgrim/login.php');
    exit();
}

require_once __DIR__ . '/nav.php';

$pdo = new PDO('sqlite:../hajj_data.db');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

function e(string $value): string {
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

if (isset($_GET['action']) && $_GET['action'] === 'datatable') {
    $draw = isset($_GET['draw']) ? (int)$_GET['draw'] : 0;
    $start = isset($_GET['start']) ? max((int)$_GET['start'], 0) : 0;
    $length = isset($_GET['length']) ? (int)$_GET['length'] : 10;
    if ($length <= 0) {
        $length = 10;
    }

    $search = trim($_GET['search']['value'] ?? '');
    $columns = [0 => 'hp.id', 1 => 'hp.hotel_name', 2 => 'hp.floor', 3 => 'hp.room_num', 4 => 'hp.barcode', 5 => 'p.name', 6 => 'p.app_id', 7 => 'hp.group_name', 8 => 'hp.note'];
    $orderColIdx = isset($_GET['order'][0]['column']) ? (int)$_GET['order'][0]['column'] : 0;
    $orderCol = $columns[$orderColIdx] ?? 'hp.id';
    $orderDir = (isset($_GET['order'][0]['dir']) && strtolower($_GET['order'][0]['dir']) === 'asc') ? 'ASC' : 'DESC';

    $baseFrom = " FROM hotel_pilgrim hp LEFT JOIN pilgrim p ON hp.barcode = p.barcode WHERE hp.group_name = :group_name ";
    $params = [':group_name' => $master_group];
    $where = '';
    if ($search !== '') {
        // Smart fuzzy search: replace whitespace inside the query with SQL
        // wildcards so "عبد المنعم" becomes "%عبد%المنعم%" and matches
        // values that contain those tokens in order in the same column.
        $fuzzy = preg_replace('/\s+/u', '%', $search);
        $where = " AND (hp.hotel_name LIKE :search OR hp.floor LIKE :search OR hp.room_num LIKE :search OR hp.barcode LIKE :search OR p.name LIKE :search OR p.app_id LIKE :search OR hp.note LIKE :search)";
        $params[':search'] = '%' . $fuzzy . '%';
    }

    $stmtTotal = $pdo->prepare("SELECT COUNT(*) FROM hotel_pilgrim WHERE group_name = :group_name");
    $stmtTotal->execute([':group_name' => $master_group]);
    $recordsTotal = (int)$stmtTotal->fetchColumn();

    $stmtFiltered = $pdo->prepare("SELECT COUNT(*)" . $baseFrom . $where);
    foreach ($params as $k => $v) {
        $stmtFiltered->bindValue($k, $v);
    }
    $stmtFiltered->execute();
    $recordsFiltered = (int)$stmtFiltered->fetchColumn();

    $sqlData = "SELECT hp.id AS h_id, hp.hotel_name, hp.floor, hp.room_num, hp.barcode, hp.group_name, hp.note, p.name, p.app_id"
        . $baseFrom . $where . " ORDER BY $orderCol $orderDir LIMIT :limit OFFSET :offset";
    $stmtData = $pdo->prepare($sqlData);
    foreach ($params as $k => $v) {
        $stmtData->bindValue($k, $v);
    }
    $stmtData->bindValue(':limit', $length, PDO::PARAM_INT);
    $stmtData->bindValue(':offset', $start, PDO::PARAM_INT);
    $stmtData->execute();
    $rows = $stmtData->fetchAll(PDO::FETCH_ASSOC);

    $data = [];
    foreach ($rows as $row) {
        $data[] = [
            'h_id' => (int)$row['h_id'],
            'hotel_name' => e((string)$row['hotel_name']),
            'floor' => e((string)$row['floor']),
            'room_num' => e((string)$row['room_num']),
            'barcode' => e((string)$row['barcode']),
            'name' => e((string)($row['name'] ?? '')),
            'app_id' => e((string)($row['app_id'] ?? '')),
            'group_name' => e((string)($row['group_name'] ?? '')),
            'note' => e((string)($row['note'] ?? '')),
            'actions' => '<button class="btn btn-warning btn-sm editBtn" data-id="' . (int)$row['h_id'] . '" data-hotel_name="' . e((string)$row['hotel_name']) . '" data-floor="' . e((string)$row['floor']) . '" data-room_num="' . e((string)$row['room_num']) . '" data-barcode="' . e((string)$row['barcode']) . '" data-note="' . e((string)$row['note']) . '"><i class="fas fa-edit"></i> تعديل</button>'
                . ' <button class="btn btn-danger btn-sm deleteBtn" data-id="' . (int)$row['h_id'] . '"><i class="fas fa-trash"></i> حذف</button>',
        ];
    }

    header('Content-Type: application/json');
    echo json_encode([
        'draw' => $draw,
        'recordsTotal' => $recordsTotal,
        'recordsFiltered' => $recordsFiltered,
        'data' => $data,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// Fetch hotel names from hotel table for the hotel_name select2
$stmt_hotels = $pdo->prepare("SELECT hotel_name FROM hotel");
$stmt_hotels->execute();
$hotels = $stmt_hotels->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>إدارة إسكان الحجاج</title>

    <!-- Bootstrap 4 RTL -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.6.1/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.rtlcss.com/bootstrap/v4.6.1/css/bootstrap.min.css">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/css/select2.min.css" rel="stylesheet">
    <link href="https://cdn.datatables.net/1.10.21/css/jquery.dataTables.min.css" rel="stylesheet">
    
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">

    <style>
        body {
            font-family: 'Segoe UI', Tahoma, Arial, sans-serif;
            background: #f5f7fa;
            direction: rtl;
        }

        .container {
            background: white;
            border-radius: 15px;
            box-shadow: 0 5px 30px rgba(0,0,0,0.1);
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
            border: none;
            color: white;
            font-weight: 600;
            transition: all 0.3s;
        }

        .btn-danger {
            background: linear-gradient(135deg, #f5576c 0%, #ff4458 100%);
            border: none;
            font-weight: 600;
            transition: all 0.3s;
        }

        table {
            margin-top: 20px;
        }

        .table thead th {
            background: #f8f9fa;
            color: #2c3e50;
            font-weight: 600;
            border: none;
            padding: 15px;
            text-align: right;
        }

        .table td {
            padding: 12px 15px;
            vertical-align: middle;
            text-align: right;
        }

        .table-striped tbody tr:nth-of-type(odd) {
            background-color: rgba(0,0,0,0.02);
        }

        .table-striped tbody tr:hover {
            background-color: rgba(102, 126, 234, 0.08);
            transition: background-color 0.3s;
        }

        /* DataTables RTL adjustments */
        .dataTables_wrapper {
            direction: rtl;
        }

        .dataTables_filter {
            text-align: left !important;
        }

        .dataTables_filter input {
            margin-right: 0.5em;
            margin-left: 0;
            padding: 8px 15px;
            border-radius: 8px;
            border: 1px solid #ddd;
        }

        .dataTables_length {
            text-align: right !important;
        }

        /* Modal Styling */
        .modal-content {
            border-radius: 15px;
            border: none;
        }

        .modal-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 15px 15px 0 0;
            border: none;
        }

        .modal-title {
            font-weight: 600;
        }

        .close {
            color: white;
            opacity: 1;
            text-shadow: none;
        }

        .close:hover {
            color: #f0f0f0;
        }

        .form-group label {
            color: #495057;
            font-weight: 600;
            margin-bottom: 8px;
        }

        .form-control {
            border-radius: 10px;
            border: 2px solid #e1e4e8;
            padding: 10px 15px;
            transition: all 0.3s;
            direction: rtl;
        }

        .form-control:focus {
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }

        textarea.form-control {
            min-height: 100px;
            resize: vertical;
        }

        /* Select2 RTL */
        .select2-container--default .select2-selection--single,
        .select2-container--default .select2-selection--multiple {
            border-radius: 10px;
            border: 2px solid #e1e4e8;
            height: auto;
            min-height: 45px;
            padding: 5px 15px;
            direction: rtl;
        }

        .select2-container--default.select2-container--focus .select2-selection--single,
        .select2-container--default.select2-container--focus .select2-selection--multiple {
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }

        .select2-dropdown {
            border-radius: 10px;
            border-color: #e1e4e8;
            direction: rtl;
        }

        .select2-search__field {
            direction: rtl;
        }

        .select2-container--default .select2-selection--single .select2-selection__arrow {
            left: 10px;
            right: auto;
        }

        /* Action buttons */
        .btn-sm {
            padding: 5px 15px;
            margin: 0 3px;
            border-radius: 8px;
            font-size: 14px;
        }

        /* Create button icon */
        #createBtn i {
            margin-left: 8px;
        }

        /* Pagination styling */
        .dataTables_paginate .paginate_button {
            padding: 8px 15px;
            margin: 0 3px;
            border-radius: 8px;
            border: 1px solid #ddd;
            background: white;
            color: #495057;
            cursor: pointer;
            transition: all 0.3s;
        }

        .dataTables_paginate .paginate_button:hover {
            background: #667eea;
            color: white;
            border-color: #667eea;
        }

        .dataTables_paginate .paginate_button.current {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-color: transparent;
        }

        /* Loading animation */
        .select2-container--default.select2-container--open .select2-selection--single,
        .select2-container--default.select2-container--open .select2-selection--multiple {
            border-color: #667eea;
        }

        /* Responsive adjustments */
        @media (max-width: 768px) {
            .container {
                padding: 15px;
                margin-top: 15px;
            }

            h1 {
                font-size: 24px;
            }

            .table {
                font-size: 14px;
            }

            .btn-sm {
                padding: 3px 10px;
                font-size: 12px;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <?php render_hotel_pilgrim_navbar('group', $master_group); ?>
        <h1 class="text-center">إدارة إسكان الحجاج</h1>
        <button class="btn btn-primary" id="createBtn">
            <i class="fas fa-plus"></i>
            إضافة سجل جديد
        </button>
        <button class="btn btn-info" id="bulkImportBtn">
            <i class="fas fa-file-import"></i>
            استيراد لصق Excel
        </button>

        <table id="hotel_pilgrim_table" class="table table-striped">
            <thead>
                <tr>
                    <th>الرقم</th>
                    <th>اسم الفندق</th>
                    <th>الطابق</th>
                    <th>رقم الغرفة</th>
                    <th>الباركود</th>
                    <th>الاسم</th>
                    <th>جواز السفر</th>
                    <th>المجموعة</th>
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
                        <form id="form" method="POST">
                            <input type="hidden" id="recordId">
                            
                            <!-- Hotel Name Select2 -->
                            <div class="form-group">
                                <label for="hotel_name">اسم الفندق:</label>
                                <select id="hotel_name" class="form-control select2" name="hotel_name" required>
                                    <option value="">اختر الفندق</option>
                                    <?php foreach ($hotels as $hotel): ?>
                                        <option value="<?= $hotel['hotel_name'] ?>"><?= $hotel['hotel_name'] ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <!-- Floor Select2 -->
                            <div class="form-group">
                                <label for="floor">الطابق:</label>
                                <select id="floor" class="form-control select2" name="floor" required disabled>
                                    <option value="">اختر الطابق</option>
                                </select>
                            </div>

                            <!-- Room Number Select2 -->
                            <div class="form-group">
                                <label for="room_num">رقم الغرفة:</label>
                                <select id="room_num" class="form-control select2" name="room_num" required disabled>
                                    <option value="">اختر الغرفة</option>
                                </select>
                            </div>

                            <div class="form-group">
                                <label for="barcode">الباركود:</label>
                                <select id="barcode" class="form-control select2" name="barcode" required multiple>
                                    <!-- Options will be loaded via AJAX -->
                                </select>
                            </div>
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
        </div>

        <div id="bulkImportModal" class="modal fade" tabindex="-1" role="dialog" aria-hidden="true">
            <div class="modal-dialog modal-lg">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">استيراد بيانات الإسكان من Excel</h5>
                        <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                            <span aria-hidden="true">&times;</span>
                        </button>
                    </div>
                    <div class="modal-body">
                        <p class="text-muted">الأعمدة المتوقعة: <code>hotel_name[TAB]floor[TAB]room_num[TAB]barcode[TAB]note</code></p>
                        <textarea id="bulkImportTextarea" class="form-control" rows="8" placeholder="الصق البيانات هنا..."></textarea>
                        <div id="bulkImportSummary" class="mt-3 text-muted"></div>
                    </div>
                    <div class="modal-footer">
                        <button class="btn btn-outline-primary" id="validateBulkImportBtn">تحقق</button>
                        <button class="btn btn-primary" id="runBulkImportBtn" disabled>إدراج</button>
                        <button class="btn btn-secondary" data-dismiss="modal">إغلاق</button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Scripts -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@4.6.1/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/js/select2.min.js"></script>
    <script src="https://cdn.datatables.net/1.10.21/js/jquery.dataTables.min.js"></script>

    <script>
        $(document).ready(function() {
            let bulkImportRows = [];
            const table = $('#hotel_pilgrim_table').DataTable({
                processing: true,
                serverSide: true,
                ajax: { url: '?action=datatable', type: 'GET' },
                columns: [
                    { data: 'h_id' },
                    { data: 'hotel_name' },
                    { data: 'floor' },
                    { data: 'room_num' },
                    { data: 'barcode' },
                    { data: 'name' },
                    { data: 'app_id' },
                    { data: 'group_name' },
                    { data: 'note' },
                    { data: 'actions', orderable: false, searchable: false }
                ],
                "language": {
                    "search": "بحث:",
                    "lengthMenu": "عرض _MENU_ سجلات",
                    "info": "عرض _START_ إلى _END_ من _TOTAL_ سجل",
                    "infoEmpty": "عرض 0 إلى 0 من 0 سجلات",
                    "infoFiltered": "(تمت التصفية من _MAX_ سجل)",
                    "paginate": {
                        "first": "الأول",
                        "last": "الأخير",
                        "next": "التالي",
                        "previous": "السابق"
                    },
                    "zeroRecords": "لم يتم العثور على سجلات مطابقة",
                    "emptyTable": "لا توجد بيانات متاحة في الجدول"
                }
            });

            // Initialize Select2 with RTL
            $('.select2').select2({
                dir: "rtl"
            });

            // When hotel_name changes, update the floor select2
            $('#hotel_name').change(function() {
                const hotelName = $(this).val();
                const masterGroup = '<?php echo $master_group; ?>';

                if (hotelName) {
                    $.ajax({
                        url: '/hotel_pilgrim/get_floors.php',
                        type: 'GET',
                        data: {
                            hotel_name: hotelName,
                            group_name: masterGroup
                        },
                        success: function(response) {
                            if (response && response.results) {
                                $('#floor').prop('disabled', false);
                                $('#floor').empty().append('<option value="">اختر الطابق</option>');
                                response.results.forEach(function(floor) {
                                    $('#floor').append('<option value="'+ floor.id +'">'+ floor.text +'</option>');
                                });
                            } else {
                                $('#floor').prop('disabled', true).empty().append('<option value="">لا توجد طوابق متاحة</option>');
                            }
                        },
                        error: function(xhr, status, error) {
                            alert('خطأ في جلب بيانات الطوابق.');
                        }
                    });
                } else {
                    $('#floor').prop('disabled', true).empty().append('<option value="">اختر الطابق</option>');
                    $('#room_num').prop('disabled', true).empty().append('<option value="">اختر الغرفة</option>');
                }
            });

            // When floor changes, update room number select2
            $('#floor').change(function() {
                const hotelName = $('#hotel_name').val();
                const selectedFloor = $(this).val();

                if (selectedFloor) {
                    $.ajax({
                        url: '/hotel_pilgrim/get_rooms.php',
                        type: 'GET',
                        data: {
                            hotel_name: hotelName,
                            floor: selectedFloor
                        },
                        dataType: 'json',
                        success: function(response) {
                            if (response && response.results) {
                                $('#room_num').prop('disabled', false);
                                $('#room_num').empty().append('<option value="">اختر الغرفة</option>');
                                response.results.forEach(function(room) {
                                    $('#room_num').append('<option value="'+ room.id +'">'+ room.text +'</option>');
                                });
                            } else {
                                $('#room_num').prop('disabled', true).empty().append('<option value="">لا توجد غرف متاحة</option>');
                            }
                        },
                        error: function(xhr, status, error) {
                            alert('خطأ في جلب بيانات الغرف.');
                        }
                    });
                } else {
                    $('#room_num').prop('disabled', true).empty().append('<option value="">اختر الغرفة</option>');
                }
            });

            // Barcode Select2 with AJAX (allow multiple selections)
            $('#barcode').select2({
                width:"100%",
                placeholder: 'ابحث بالباركود',
                multiple: true,
                dir: "rtl",
                ajax: {
                    url: '/hotel_pilgrim/pilgrims_data.php',
                    dataType: 'json',
                    delay: 700,
                    data: function(params) {
                        return {
                            q: params.term,
                            page: params.page || 1
                        };
                    },
                    processResults: function(data, params) {
                        params.page = params.page || 1;
                        return {
                            results: data.map(function(item) {
                                return {
                                    id: item.barcode,
                                    text: item.passport + ' | ' + item.name + ' | ' + item.group
                                };
                            }),
                            pagination: {
                                more: data.length === 10
                            }
                        };
                    },
                    cache: true
                },
                minimumInputLength: 3,
                language: {
                    inputTooShort: function() {
                        return "الرجاء إدخال 3 أحرف أو أكثر";
                    },
                    searching: function() {
                        return "جاري البحث...";
                    },
                    noResults: function() {
                        return "لم يتم العثور على نتائج";
                    }
                }
            });

            // Trigger modal for Create New Record
            $('#createBtn').click(function() {
                $('#modalTitle').text("إضافة سجل جديد");
                $('#form')[0].reset();
                $('#recordId').val('');
                $('#barcode').val(null).trigger('change');
                $('#modal').modal('show');
            });

            // Edit Button Click: Show Modal and Populate Data
            $("#hotel_pilgrim_table").on("click", ".editBtn", function() {
                $('#modalTitle').text("تعديل السجل");

                // Get record data from the clicked row
                const id = $(this).data('id');
                const hotelName = $(this).data('hotel_name');
                const floor = $(this).data('floor');
                const roomNum = $(this).data('room_num');
                const barcode = $(this).data('barcode');
                const note = $(this).data('note');

                // Set form data
                $('#recordId').val(id);
                $('#hotel_name').val(hotelName).trigger('change');
                
                // Wait for hotel change to load floors, then set floor
                setTimeout(function() {
                    $('#floor').val(floor).trigger('change');
                    
                    // Wait for floor change to load rooms, then set room
                    setTimeout(function() {
                        $('#room_num').val(roomNum).trigger('change');
                    }, 500);
                }, 500);
                
                // For barcode, we need to create the option first
                if (barcode) {
                    var newOption = new Option(barcode, barcode, true, true);
                    $('#barcode').append(newOption).trigger('change');
                }
                
                $('#note').val(note);

                // Show the modal
                $('#modal').modal('show');
            });

            // Handle form submission with AJAX (Create or Edit)
            $('#form').submit(function(e) {
                e.preventDefault();

                const barcodes = $('#barcode').val();  // Get selected barcodes (multiple)

                if (!(barcodes && barcodes.length > 0)) {
                    alert('الرجاء اختيار باركود واحد على الأقل');
                    return;
                }

                const isEdit = !!$('#recordId').val();
                if (isEdit) {
                    const formData = {
                        action: 'edit',
                        id: $('#recordId').val(),
                        hotel_name: $('#hotel_name').val(),
                        floor: $('#floor').val(),
                        room_num: $('#room_num').val(),
                        barcode: barcodes[0],
                        note: $('#note').val()
                    };
                    $.ajax({
                        url: '/hotel_pilgrim/hotel_pilgrim_action.php',
                        type: 'POST',
                        data: formData,
                        success: function(response) {
                            if (JSON.parse(response).success) {
                                $('#modal').modal('hide');
                                table.ajax.reload(null, false);
                            } else {
                                alert('خطأ: ' + response.message);
                            }
                        },
                        error: function() {
                            alert('حدث خطأ ما!');
                        }
                    });
                    return;
                }

                const rows = barcodes.map(function(barcode) {
                    return {
                        hotel_name: $('#hotel_name').val(),
                        floor: $('#floor').val(),
                        room_num: $('#room_num').val(),
                        barcode: barcode,
                        note: $('#note').val()
                    };
                });

                $.ajax({
                    url: '/hotel_pilgrim/hotel_pilgrim_action.php',
                    type: 'POST',
                    dataType: 'json',
                    data: { action: 'import_batch', rows: JSON.stringify(rows) },
                    success: function(response) {
                        if (response.success) {
                            $('#modal').modal('hide');
                            table.ajax.reload(null, false);
                        } else {
                            alert('خطأ: ' + (response.message || 'فشل الحفظ.'));
                        }
                    },
                    error: function() {
                        alert('حدث خطأ ما!');
                    }
                });
            });

            // Handle delete with AJAX
            $("#hotel_pilgrim_table").on("click",'.deleteBtn',function() {
                const id = $(this).data('id');
                if (confirm('هل أنت متأكد من حذف هذا السجل؟')) {
                    $.ajax({
                        url: '/hotel_pilgrim/hotel_pilgrim_action.php',
                        type: 'POST',
                        data: { action: 'delete', id: id },
                        success: function(response) {
                            console.log(response)
                            if (JSON.parse(response).success) {
                                table.ajax.reload(null, false);
                            } else {
                                alert('خطأ: ' + response.message);
                            }
                        },
                        error: function() {
                            alert('حدث خطأ ما!');
                        }
                    });
                }
            });

            $('#bulkImportBtn').on('click', function() {
                $('#bulkImportTextarea').val('');
                $('#bulkImportSummary').text('');
                bulkImportRows = [];
                $('#runBulkImportBtn').prop('disabled', true);
                $('#bulkImportModal').modal('show');
            });

            $('#validateBulkImportBtn').on('click', function() {
                const rowsText = $('#bulkImportTextarea').val().trim();
                if (!rowsText) {
                    $('#bulkImportSummary').text('لا توجد بيانات.');
                    return;
                }
                $.post('/hotel_pilgrim/hotel_pilgrim_action.php', { action: 'import_validate', rows_text: rowsText }, function(resp) {
                    let parsed = resp;
                    if (typeof parsed !== 'object') parsed = JSON.parse(resp);
                    if (!parsed.success) {
                        $('#bulkImportSummary').text(parsed.message || 'فشل التحقق.');
                        return;
                    }
                    bulkImportRows = parsed.rows || [];
                    if ((parsed.errors || []).length > 0) {
                        $('#bulkImportSummary').text(`تم التحقق مع أخطاء: ${parsed.errors.length} صف غير صالح.`);
                        $('#runBulkImportBtn').prop('disabled', true);
                        return;
                    }
                    $('#bulkImportSummary').text(`التحقق ناجح: ${bulkImportRows.length} صف.`);
                    $('#runBulkImportBtn').prop('disabled', false);
                }, 'json');
            });

            $('#runBulkImportBtn').on('click', function() {
                if (!bulkImportRows.length) {
                    $('#bulkImportSummary').text('قم بالتحقق أولاً.');
                    return;
                }
                $.post('/hotel_pilgrim/hotel_pilgrim_action.php', { action: 'import_batch', rows: JSON.stringify(bulkImportRows) }, function(resp) {
                    let parsed = resp;
                    if (typeof parsed !== 'object') parsed = JSON.parse(resp);
                    if (!parsed.success) {
                        $('#bulkImportSummary').text(parsed.message || 'فشل الإدراج.');
                        return;
                    }
                    $('#bulkImportSummary').text(`تم الإدراج: ${parsed.inserted || 0}`);
                    table.ajax.reload(null, false);
                }, 'json');
            });
        });
    </script>
</body>
</html>