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

// Fetch all hotels for the DataTable
$stmt = $pdo->query("WITH
-- Rooms that are active today (handles duplicated rooms across date ranges)
ActiveRooms AS (
    SELECT r.hotel_name, r.room_num, r.room_type
    FROM room r
    WHERE DATE('now') BETWEEN r.date_from AND r.date_to
),

-- Counts of rooms/beds available today (by hotel)
RoomCounts AS (
    SELECT hotel_name,
           COUNT(room_num) AS total_rooms,
           SUM(room_type) AS total_beds
    FROM ActiveRooms
    GROUP BY hotel_name
),

-- Reservations that are active today (by hotel/room)
ActiveReservations AS (
    SELECT res.hotel_name, res.room_num
    FROM res res
    WHERE DATE('now') BETWEEN res.start_date AND res.end_date
    GROUP BY res.hotel_name, res.room_num
),

-- Join active reservations to today's active rooms to count reserved rooms/beds today
ReservedRooms AS (
    SELECT ar.hotel_name,
           COUNT(ar.room_num) AS reserved_rooms,
           SUM(r.room_type) AS reserved_beds
    FROM ActiveReservations ar
    JOIN ActiveRooms r
      ON r.hotel_name = ar.hotel_name
     AND r.room_num   = ar.room_num
    GROUP BY ar.hotel_name
),

-- Pilgrim counts (unchanged)
PilgrimsCount AS (
    SELECT hotel_name, COUNT(*) AS pilgrims
    FROM hotel_pilgrim
    GROUP BY hotel_name
)

SELECT h.hotel_name, h.address, h.note, h.id,
       COALESCE(rc.total_rooms, 0)                 AS total_rooms,
       COALESCE(rc.total_beds, 0)                  AS total_beds,
       COALESCE(rr.reserved_rooms, 0)              AS reserved_rooms,
       COALESCE(rc.total_rooms, 0) - COALESCE(rr.reserved_rooms, 0) AS available_rooms,
       COALESCE(rc.total_beds, 0)   - COALESCE(rr.reserved_beds, 0) AS available_beds,
       COALESCE(pc.pilgrims, 0)                    AS pilgrims_count
FROM hotel h
LEFT JOIN RoomCounts    rc ON h.hotel_name = rc.hotel_name
LEFT JOIN ReservedRooms rr ON h.hotel_name = rr.hotel_name
LEFT JOIN PilgrimsCount pc ON h.hotel_name = pc.hotel_name;

");
$hotels = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="ar">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>إدار الفنادق</title>

    <!-- Bootstrap 5 -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- DataTables CSS -->
    <link href="https://cdn.datatables.net/1.10.24/css/jquery.dataTables.min.css" rel="stylesheet">

    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@400;500;700&family=Cairo:wght@400;700&display=swap"
        rel="stylesheet">

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
        <?php render_root_navbar('hotels'); ?>
    </div>

    <div class="container">
        <h2 class="text-center mb-4">إدارة الفادق</h2>

        <!-- Add Hotel Button -->
        <div class="text-center mb-3">
            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addHotelModal">ضافة فندق
                جديد</button>
        </div>

        <!-- Hotels DataTable -->
        <table id="hotelsTable" class="display table table-bordered">
            <thead>
                <tr>
                    <th>رقم الفندق</th>
                    <th>اسم الفندق</th>
                    <th>عدد الغرف</th>
                    <th>عدد الأسرة</th>
                    <th>الغرف المحجوزة</th>
                    <th>الغرف المتاحة</th>
                    <th>الأسرة المتاحة</th>
                    <th>إجمالي الحجاج</th>
                    <th>الإجراءت</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($hotels as $hotel): ?>
                    <tr>
                        <td><?= $hotel['id'] ?></td>
                        <td><a target="_blank" href="<?= $hotel['note'] ?>"><?= $hotel['hotel_name'] ?></a></td>
                        <td><?= $hotel['total_rooms'] ?></td>
                        <td><?= $hotel['total_beds'] ?></td>
                        <td><?= $hotel['reserved_rooms'] ?></td>
                        <td><?= $hotel['available_rooms'] ?></td>
                        <td><?= $hotel['available_beds'] ?></td>
                        <td><?= $hotel['pilgrims_count'] ?></td>
                        <td>
                            <button class="btn btn-success btn-sm edit-btn" data-bs-toggle="modal"
                                data-bs-target="#editHotelModal" data-id="<?= $hotel['id'] ?>"
                                data-name="<?= $hotel['hotel_name'] ?>" data-address="<?= $hotel['address'] ?>"
                                data-note="<?= $hotel['note'] ?>">تعديل</button>
                            <button class="btn btn-danger btn-sm delete-btn" data-id="<?= $hotel['id'] ?>"
                                data-bs-toggle="modal" data-bs-target="#deleteHotelModal">حذف</button>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
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

    <!-- Edit Hotel Modal -->
    <div class="modal fade" id="editHotelModal" tabindex="-1" aria-labelledby="editHotelModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="editHotelModalLabel">تعديل يانات الفندق</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <form id="editHotelForm">
                        <div class="mb-3">
                            <label for="editHotelName" class="form-label">اسم الفدق</label>
                            <input type="text" class="form-control" id="editHotelName" required>
                        </div>
                        <div class="mb-3">
                            <label for="editHotelAddress" class="form-label">العنوان</label>
                            <input type="text" class="form-control" id="editHotelAddress" required>
                        </div>
                        <div class="mb-3">
                            <label for="editHotelnote" class="form-label">ملاحظت</label>
                            <input type="text" class="form-control" id="editHotelnote" required>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إغلاق</button>
                    <button type="button" class="btn btn-primary" id="updateHotelBtn">تحديث</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Delete Hotel Modal -->
    <div class="modal fade" id="deleteHotelModal" tabindex="-1" aria-labelledby="deleteHotelModalLabel"
        aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="deleteHotelModalLabel">حذف الفندق</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    هل أنت متأكد أنك تريد حف هذا الفندق
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إغلاق</button>
                    <button type="button" class="btn btn-danger" id="confirmDeleteHotelBtn">حذف</button>
                </div>
            </div>
        </div>
    </div>
    <!-- Available Rooms Modal -->
    <div class="modal fade" id="availableRoomsModal" tabindex="-1" aria-labelledby="availableRoomsModalLabel"
        aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="availableRoomsModalLabel">التفاصيل</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <!-- Available rooms will be populated here -->
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إغلاق</button>
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
        $(document).ready(function () {
            // Initialize DataTable
            const table = $('#hotelsTable').DataTable();

            // Add Hotel
            $('#saveHotelBtn').click(function () {
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
                    success: function (response) {
                        const res = JSON.parse(response);
                        if (res.status == 'success') {
                            $('#addHotelModal').modal('hide');
                            location.reload();
                        }
                    }
                });
            });

            // Edit Hotel
            $('.edit-btn').click(function () {
                const id = $(this).data('id');
                const name = $(this).data('name');
                const address = $(this).data('address');
                const note = $(this).data('note');

                $('#editHotelName').val(name);
                $('#editHotelAddress').val(address);
                $('#editHotelnote').val(note);

                $('#updateHotelBtn').click(function () {
                    $.ajax({
                        url: 'hotel.php',
                        method: 'POST',
                        data: {
                            action: 'edit',
                            hotel_id: id,
                            hotel_name: $('#editHotelName').val(),
                            address: $('#editHotelAddress').val(),
                            note: $('#editHotelnote').val()
                        },
                        success: function (response) {
                            const res = JSON.parse(response);
                            if (res.status == 'success') {
                                $('#editHotelModal').modal('hide');
                                location.reload();
                            }
                        }
                    });
                });
            });

            // Delete Hotel


            $('#hotelsTable tbody').on('click', '.delete-btn', function () {
                const id = $(this).data('id');
                $.ajax({
                    url: 'hotel.php',
                    method: 'POST',
                    data: {
                        action: 'delete',
                        hotel_id: id
                    },
                    success: function (response) {
                        const res = JSON.parse(response);
                        if (res.status == 'success') {
                            location.reload();
                        }
                    }
                });
            });
            // Handle click on the available rooms column
            $('#hotelsTable tbody').on('click', 'td:nth-child(6)', function () {
                const hotelName = $(this).closest('tr').find('td:nth-child(2)').text(); // Get hotel_name from the second column

                $.ajax({
                    url: 'hotel_available_room.php',
                    method: 'GET',
                    data: { hotel_name: hotelName },
                    success: function (response) {
                        const rooms = JSON.parse(response);

                        const typeCounts = {};
                        rooms.forEach(room => {
                            typeCounts[room.room_type] = (typeCounts[room.room_type] || 0) + 1;
                        });

                        // Create summary line
                        const summaryLine = Object.entries(typeCounts)
                            .map(([type, count]) => `نوع الغرفة (${type}): ${count} غرفة || ${type*count} سرير`)
                            .join('<br>');

                        // Build modal content
                        let roomContent = `<div><strong>ملخص:</strong> ${summaryLine}</div><ul>`;
                        rooms.forEach(room => {
                            roomContent += `<li>رقم الغرفة: ${room.room_num}, نوع الغرفة: ${room.room_type}</li>`;
                        });
                        roomContent += '</ul>';

                        $('#availableRoomsModal .modal-body').html(roomContent);
                        $('#availableRoomsModal').modal('show');
                    }
                });


            });
            
            $('#hotelsTable tbody').on('click', 'td:nth-child(8)', function () {
                const hotelName = $(this).closest('tr').find('td:nth-child(2)').text(); // Get hotel_name from the second column

                $.ajax({
                    url: 'hotel_assigned_pilgrims.php',
                    method: 'GET',
                    data: { hotel_name: hotelName },
                    success: function (response) {
                        const rooms = JSON.parse(response);

                       let roomContent = "";

                       

                        // Build modal content
                        roomContent += "<ul>";
                        rooms.forEach(room => {
                            roomContent += `<li>الحجاج: ${room.pilgrims}, التكتل: ${room.group_name}, العدد الكلي: ${room.total_pilgrims}</li>`;
                        });
                        roomContent += '</ul>';

                        $('#availableRoomsModal .modal-body').html(roomContent);
                        $('#availableRoomsModal').modal('show');
                    }
                });


            });
            $('#hotelsTable tbody').on('click', 'td:nth-child(5)', function () {
                const hotelName = $(this).closest('tr').find('td:nth-child(2)').text(); // Get hotel_name from the second column

                $.ajax({
                    url: 'hotel_not_available_room.php',
                    method: 'GET', // or POST if you prefer
                    data: { hotel_name: hotelName },
                    success: function (response) {
                        const rooms = JSON.parse(response);

                        let roomContent = '';
                        const groupCounts = {};

                        rooms.forEach(room => {
                            // Count records by group_name
                            groupCounts[room.group_name] = (groupCounts[room.group_name] || 0) + 1;
                        });

                        // Create summary line
                        const summaryLine = Object.entries(groupCounts)
                            .map(([group, count]) => `${group}: ${count} سجل`)
                            .join('<br> ');

                        roomContent += `<div><strong>ملخص:</strong> ${summaryLine}</div><ul>`;

                        rooms.forEach(room => {
                            roomContent += `<li>رقم الغرفة: ${room.room_num}, نوع الغرفة: ${room.group_name}</li>`;
                        });
                        roomContent += '</ul>';

                        $('#availableRoomsModal .modal-body').html(roomContent);
                        $('#availableRoomsModal').modal('show');
                    }
                });


            });

        });
    </script>
</body>

</html>