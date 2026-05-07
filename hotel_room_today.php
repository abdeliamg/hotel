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
$stmt = $pdo->query("WITH ResWithId AS (
  SELECT hotel_name, room_num, start_date, end_date, COALESCE(id, rowid) AS _rid
  FROM res
),
OverlapPairs AS (
  SELECT
    r1.hotel_name,
    r1.room_num
  FROM ResWithId r1
  JOIN ResWithId r2
    ON r1.hotel_name = r2.hotel_name
   AND r1.room_num   = r2.room_num
   AND r1._rid       < r2._rid             -- avoid self-join & double count
   AND r1.start_date <= r2.end_date        -- intervals overlap
   AND r2.start_date <= r1.end_date
)
SELECT hotel_name,
       room_num,
       COUNT(*) AS reservations
FROM OverlapPairs
GROUP BY hotel_name, room_num
ORDER BY reservations DESC;

");
$rooms = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="ar">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>تقرير الغرف</title>

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
        <h2 class="text-center mb-4">تقرير حجز الغرف</h2>

        <!-- Rooms DataTable -->
        <table id="roomsTable" class="display table table-bordered">
            <thead>
                <tr>
                    <th>اسم الفندق</th>
                    <th>رقم الغرفة</th>
                    <th>مرات الحجز بنفس الفترة</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rooms as $room): ?>
                    <tr>
                        <td><?= $room['hotel_name'] ?></td>
                        <td><?= $room['room_num'] ?></td>
                        <td><?= $room['reservations'] ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr>
                    <th colspan="2">الإجمالي</th>
                    <th>0</th>
                </tr>
            </tfoot>
        </table>
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


                    // Update the footer with totals for the visible rows
                    $(api.column(2).footer()).html(totalRooms);
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
