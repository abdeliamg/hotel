<?php
require_once __DIR__ . '/check.php';
require_once __DIR__ . '/includes/root_nav.php';
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Database connection
require_once __DIR__ . '/includes/db.php';

// Handle adding a hotel
if (isset($_POST['action']) && $_POST['action'] == 'add') {
    $hotel_name = $_POST['hotel_name'];
    $address = $_POST['address'];
    $note = $_POST['note'];

    $stmt = $pdo->prepare("INSERT INTO hotel (hotel_name, address, note) VALUES (?, ?, ?)");
    $stmt->execute([$hotel_name, $address, $note]);

    echo json_encode(['status' => 'success', 'message' => 'Hotel added successfully']);
    exit;
}

// Handle editing a hotel
if (isset($_POST['action']) && $_POST['action'] == 'edit') {
    $hotel_id = $_POST['hotel_id'];
    $hotel_name = $_POST['hotel_name'];
    $address = $_POST['address'];
    $note = $_POST['note'];

    $stmt = $pdo->prepare("UPDATE hotel SET hotel_name = ?, address = ?, note = ? WHERE id = ?");
    $stmt->execute([$hotel_name, $address, $note, $hotel_id]);

    echo json_encode(['status' => 'success', 'message' => 'Hotel updated successfully']);
    exit;
}

// Handle deleting a hotel
if (isset($_POST['action']) && $_POST['action'] == 'delete') {
    $hotel_id = $_POST['hotel_id'];

    $stmt = $pdo->prepare("DELETE FROM hotel WHERE id = ?");
    $stmt->execute([$hotel_id]);

    echo json_encode(['status' => 'success', 'message' => 'Hotel deleted successfully']);
    exit;
}

// Fetch all rooms data for the DataTable, grouped by floor
$stmt = $pdo->query("WITH RoomCounts AS (
    SELECT hotel_name, floor, COUNT(room_num) AS total_rooms, SUM(room_type) AS total_beds
    FROM room
    GROUP BY hotel_name, floor
),
ReservedRooms AS (
    SELECT r.hotel_name, r.floor,
           COUNT(r.room_num) AS reserved_rooms,
           SUM(r.room_type) AS reserved_beds
    FROM room r
    LEFT JOIN res res_table 
        ON res_table.room_num = r.room_num 
        AND res_table.hotel_name = r.hotel_name
    WHERE res_table.end_date > DATE('now')
    GROUP BY r.hotel_name, r.floor
)
SELECT r.hotel_name, r.floor,
       COALESCE(rc.total_rooms, 0) AS total_rooms,
       COALESCE(rc.total_beds, 0) AS total_beds,
       COALESCE(rr.reserved_rooms, 0) AS reserved_rooms,
       COALESCE(rc.total_rooms, 0) - COALESCE(rr.reserved_rooms, 0) AS available_rooms,
       -- Here we calculate the available beds correctly by subtracting the reserved beds from total beds
       COALESCE(rc.total_beds, 0) - COALESCE(rr.reserved_beds, 0) AS available_beds
FROM room r
LEFT JOIN RoomCounts rc 
    ON r.hotel_name = rc.hotel_name AND r.floor = rc.floor
LEFT JOIN ReservedRooms rr 
    ON r.hotel_name = rr.hotel_name AND r.floor = rr.floor
GROUP BY r.hotel_name, r.floor;

");
$rooms = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="ar">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>تقرير الطوابق</title>

    <!-- Bootstrap 5 -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- DataTables CSS -->
    <link href="https://cdn.datatables.net/1.10.24/css/jquery.dataTables.min.css" rel="stylesheet">
    
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@400;500;700&family=Cairo:wght@400;700&display=swap" rel="stylesheet">

    <style>
        /* Custom styles */
        body {
            font-family: 'Cairo', sans-serif;
        }

        .modal-content {
            border-radius: 15px;
        }

        .modal-header {
            background-color: #007bff;
            color: white;
        }

        .modal-footer {
            background-color: #f8f9fa;
        }

        .container {
            margin-top: 30px;
        }

        .dataTables_wrapper {
            margin-top: 20px;
        }
    </style>
</head>
<body>
    <div class="container-fluid pt-2">
        <?php render_root_navbar('reports'); ?>
    </div>

    <div class="container">
        <h2 class="text-center mb-4">تقرير الطوابق</h2>

        <!-- Rooms DataTable -->
        <table id="roomsTable" class="display table table-bordered">
            <thead>
                <tr>
                    <th>اسم الفندق</th>
                    <th>الطابق</th>
                    <th>عدد الغرف</th>
                    <th>عدد الأسرة</th>
                    <th>الغرف المحجوزة</th>
                    <th>الغرف المتاحة</th>
                    <th>الأسرة المتاحة</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rooms as $room): ?>
                    <tr>
                        <td><?= $room['hotel_name'] ?></td>
                        <td><?= $room['floor'] ?></td>
                        <td><?= $room['total_rooms'] ?></td>
                        <td><?= $room['total_beds'] ?></td>
                        <td><?= $room['reserved_rooms'] ?></td>
                        <td><?= $room['available_rooms'] ?></td>
                        <td><?= $room['available_beds'] ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr>
                    <th colspan="2">الإجمالي</th>
                    <th>0</th>
                    <th>0</th>
                    <th>0</th>
                    <th>0</th>
                    <th>0</th>
                </tr>
            </tfoot>
        </table>
    </div>

    <!-- Add Hotel Modal -->
    <div class="modal fade" id="addHotelModal" tabindex="-1" aria-labelledby="addHotelModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="addHotelModalLabel">إضافة فندق جديد</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <form id="addHotelForm">
                        <div class="mb-3">
                            <label for="hotelName" class="form-label">اسم الفندق</label>
                            <input type="text" class="form-control" id="hotelName" required>
                        </div>
                        <div class="mb-3">
                            <label for="hotelAddress" class="form-label">العنوان</label>
                            <input type="text" class="form-control" id="hotelAddress" required>
                        </div>
                        <div class="mb-3">
                            <label for="hotelnote" class="form-label">ملاظات</label>
                            <input type="text" class="form-control" id="hotelnote" required>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إغلاق</button>
                    <button type="button" class="btn btn-primary" id="saveHotelBtn">حفظ</button>
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
            // Initialize DataTable
            const table = $('#roomsTable').DataTable({
                "footerCallback": function(row, data, start, end, display) {
                    var api = this.api();

                    // Calculate total for visible rows (current page)
                    var totalRooms = api.rows({ page: 'current' }).data().reduce(function(a, b) { 
                        return parseInt(a, 10) + parseInt(b[2], 10); 
                    }, 0);

                    var totalBeds = api.rows({ page: 'current' }).data().reduce(function(a, b) { 
                        return parseInt(a, 10) + parseInt(b[3], 10); 
                    }, 0);

                    var totalReserved = api.rows({ page: 'current' }).data().reduce(function(a, b) { 
                        return parseInt(a, 10) + parseInt(b[4], 10); 
                    }, 0);

                    var totalAvailableRooms = api.rows({ page: 'current' }).data().reduce(function(a, b) { 
                        return parseInt(a, 10) + parseInt(b[5], 10); 
                    }, 0);

                    var totalAvailableBeds = api.rows({ page: 'current' }).data().reduce(function(a, b) { 
                        return parseInt(a, 10) + parseInt(b[6], 10); 
                    }, 0);

                    // Update the footer with totals for the visible rows
                    $(api.column(2).footer()).html(totalRooms);
                    $(api.column(3).footer()).html(totalBeds);
                    $(api.column(4).footer()).html(totalReserved);
                    $(api.column(5).footer()).html(totalAvailableRooms);
                    $(api.column(6).footer()).html(totalAvailableBeds);
                }
            });

            // Add Hotel
            $('#saveHotelBtn').click(function() {
                const hotelName = $('#hotelName').val();
                const hotelAddress = $('#hotelAddress').val();
                const hotelnote = $('#hotelnote').val();

                $.ajax({
                    url: 'hotel.php',
                    method: 'POST',
                    data: { 
                        action: 'add',
                        hotel_name: hotelName,
                        address: hotelAddress,
                        note: hotelnote 
                    },
                    success: function(response) {
                        const res = JSON.parse(response);
                        if(res.status == 'success') {
                            $('#addHotelModal').modal('hide');
                            location.reload();
                        }
                    }
                });
            });
        });
    </script>
</body>
</html>
