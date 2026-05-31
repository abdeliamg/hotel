<?php
session_start();
require_once __DIR__ . '/check.php';
require_once __DIR__ . '/includes/root_nav.php';
require_once __DIR__ . '/includes/db.php';

// S3: log errors instead of displaying them
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

// Helper for JSON responses
function json_respond(array $payload, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

// Validate CSRF on every state-changing request
function require_csrf(): void {
    $token = $_POST['csrf_token'] ?? '';
    if (!validate_csrf_token($token)) {
        json_respond(['status' => 'error', 'message' => 'CSRF token mismatch'], 403);
    }
}

// Only admins can add/edit/delete hotels. Plain `user` role has read-only access.
$current_user = $GLOBALS['current_user'] ?? get_authenticated_user();
$isHotelAdmin = $current_user && role_meets_requirement($current_user['role'] ?? '', 'admin');

function require_hotel_admin(): void {
    global $isHotelAdmin;
    if (!$isHotelAdmin) {
        json_respond(['status' => 'error', 'message' => 'صلاحيات غير كافية لإجراء هذه العملية.'], 403);
    }
}

$action = $_POST['action'] ?? null;

if ($action === 'add') {
    require_csrf();
    require_hotel_admin();
    $hotel_name = trim($_POST['hotel_name'] ?? '');
    $address    = trim($_POST['address'] ?? '');
    $note       = trim($_POST['note'] ?? '');

    if ($hotel_name === '' || $address === '') {
        json_respond(['status' => 'error', 'message' => 'الاسم والعنوان مطلوبان'], 400);
    }
    try {
        $stmt = $pdo->prepare("INSERT INTO hotel (hotel_name, address, note) VALUES (?, ?, ?)");
        $stmt->execute([$hotel_name, $address, $note]);
        json_respond(['status' => 'success', 'message' => 'تمت إضافة الفندق', 'id' => (int)$pdo->lastInsertId()]);
    } catch (PDOException $e) {
        // SQLite UNIQUE violation on hotel_name
        $msg = (strpos($e->getMessage(), 'UNIQUE') !== false)
            ? 'يوجد فندق بنفس الاسم بالفعل'
            : 'تعذر حفظ الفندق';
        error_log('hotel add failed: ' . $e->getMessage());
        json_respond(['status' => 'error', 'message' => $msg], 400);
    }
}

if ($action === 'edit') {
    require_csrf();
    require_hotel_admin();
    $hotel_id   = (int)($_POST['hotel_id'] ?? 0);
    $hotel_name = trim($_POST['hotel_name'] ?? '');
    $address    = trim($_POST['address'] ?? '');
    $note       = trim($_POST['note'] ?? '');

    if ($hotel_id <= 0 || $hotel_name === '' || $address === '') {
        json_respond(['status' => 'error', 'message' => 'بيانات غير مكتملة'], 400);
    }

    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare("SELECT hotel_name FROM hotel WHERE id = ?");
        $stmt->execute([$hotel_id]);
        $current = $stmt->fetchColumn();
        if ($current === false) {
            $pdo->rollBack();
            json_respond(['status' => 'error', 'message' => 'الفندق غير موجود'], 404);
        }

        // Q4: cascade rename across related tables when hotel_name changes
        if ($current !== $hotel_name) {
            $pdo->prepare("UPDATE hotel_pilgrim SET hotel_name = ? WHERE hotel_name = ?")
                ->execute([$hotel_name, $current]);
            $pdo->prepare("UPDATE res SET hotel_name = ? WHERE hotel_name = ?")
                ->execute([$hotel_name, $current]);
            $pdo->prepare("UPDATE room SET hotel_name = ? WHERE hotel_name = ?")
                ->execute([$hotel_name, $current]);
        }

        $pdo->prepare("UPDATE hotel SET hotel_name = ?, address = ?, note = ? WHERE id = ?")
            ->execute([$hotel_name, $address, $note, $hotel_id]);

        $pdo->commit();
        json_respond(['status' => 'success', 'message' => 'تم تحديث الفندق']);
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $msg = (strpos($e->getMessage(), 'UNIQUE') !== false)
            ? 'يوجد فندق آخر بنفس الاسم'
            : 'تعذر تحديث الفندق';
        error_log('hotel edit failed: ' . $e->getMessage());
        json_respond(['status' => 'error', 'message' => $msg], 400);
    }
}

if ($action === 'delete') {
    require_csrf();
    require_hotel_admin();
    $hotel_id = (int)($_POST['hotel_id'] ?? 0);
    if ($hotel_id <= 0) {
        json_respond(['status' => 'error', 'message' => 'معرّف غير صالح'], 400);
    }
    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare("SELECT hotel_name FROM hotel WHERE id = ?");
        $stmt->execute([$hotel_id]);
        $hotel_name = $stmt->fetchColumn();
        if ($hotel_name === false) {
            $pdo->rollBack();
            json_respond(['status' => 'error', 'message' => 'الفندق غير موجود'], 404);
        }

        // Q3: cascade delete (FKs are not enforced project-wide; do it manually)
        $pdo->prepare("DELETE FROM hotel_pilgrim WHERE hotel_name = ?")->execute([$hotel_name]);
        $pdo->prepare("DELETE FROM res WHERE hotel_name = ?")->execute([$hotel_name]);
        $pdo->prepare("DELETE FROM room WHERE hotel_name = ?")->execute([$hotel_name]);
        $pdo->prepare("DELETE FROM hotel WHERE id = ?")->execute([$hotel_id]);

        $pdo->commit();
        json_respond(['status' => 'success', 'message' => 'تم حذف الفندق']);
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('hotel delete failed: ' . $e->getMessage());
        json_respond(['status' => 'error', 'message' => 'تعذر حذف الفندق'], 500);
    }
}

// Listing query (Q1: DATE('now', 'localtime') everywhere)
$stmt = $pdo->query("WITH
ActiveRooms AS (
    SELECT r.hotel_name, r.room_num, r.room_type
    FROM room r
    WHERE DATE('now', 'localtime') BETWEEN r.date_from AND r.date_to
),
RoomCounts AS (
    SELECT hotel_name,
           COUNT(room_num) AS total_rooms,
           SUM(room_type) AS total_beds
    FROM ActiveRooms
    GROUP BY hotel_name
),
ActiveReservations AS (
    SELECT res.hotel_name, res.room_num
    FROM res res
    WHERE DATE('now', 'localtime') BETWEEN res.start_date AND res.end_date
    GROUP BY res.hotel_name, res.room_num
),
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
OccupiedRooms AS (
    SELECT DISTINCT hotel_name, room_num
    FROM hotel_pilgrim
),
ReservedEmptyRooms AS (
    -- Reserved (active) rooms that have no pilgrim assigned in hotel_pilgrim.
    SELECT ar.hotel_name,
           COUNT(ar.room_num) AS reserved_empty_rooms
    FROM ActiveReservations ar
    JOIN ActiveRooms r
      ON r.hotel_name = ar.hotel_name
     AND r.room_num   = ar.room_num
    LEFT JOIN OccupiedRooms o
      ON o.hotel_name = ar.hotel_name
     AND o.room_num   = ar.room_num
    WHERE o.room_num IS NULL
    GROUP BY ar.hotel_name
),
RoomPilgrimCounts AS (
    SELECT hotel_name, room_num, COUNT(*) AS pilgrim_count
    FROM hotel_pilgrim
    GROUP BY hotel_name, room_num
),
ReservedIncompleteRooms AS (
    -- Reserved (active) rooms that are PARTIALLY filled: they have at least one
    -- pilgrim but fewer than the room capacity (room_type). Fully empty rooms are
    -- excluded (those are counted separately as reserved-available).
    SELECT ar.hotel_name,
           COUNT(ar.room_num) AS reserved_incomplete_rooms
    FROM ActiveReservations ar
    JOIN ActiveRooms r
      ON r.hotel_name = ar.hotel_name
     AND r.room_num   = ar.room_num
    JOIN RoomPilgrimCounts rpc
      ON rpc.hotel_name = ar.hotel_name
     AND rpc.room_num   = ar.room_num
    WHERE rpc.pilgrim_count > 0
      AND rpc.pilgrim_count < r.room_type
    GROUP BY ar.hotel_name
),
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
       COALESCE(rer.reserved_empty_rooms, 0)       AS reserved_empty_rooms,
       COALESCE(rir.reserved_incomplete_rooms, 0)  AS reserved_incomplete_rooms,
       COALESCE(rc.total_beds, 0)   - COALESCE(rr.reserved_beds, 0) AS available_beds,
       COALESCE(pc.pilgrims, 0)                    AS pilgrims_count
FROM hotel h
LEFT JOIN RoomCounts              rc  ON h.hotel_name = rc.hotel_name
LEFT JOIN ReservedRooms           rr  ON h.hotel_name = rr.hotel_name
LEFT JOIN ReservedEmptyRooms      rer ON h.hotel_name = rer.hotel_name
LEFT JOIN ReservedIncompleteRooms rir ON h.hotel_name = rir.hotel_name
LEFT JOIN PilgrimsCount           pc  ON h.hotel_name = pc.hotel_name;
");
$hotels = $stmt->fetchAll(PDO::FETCH_ASSOC);

$csrf = generate_csrf_token();

function e(string $v): string {
    return htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
}

/**
 * Accept a URL in the note field; only allow http/https hrefs (blocks javascript:, data:, etc.).
 * If the note has no scheme but looks like host/path, try https://...
 */
function note_as_safe_href(string $note): ?string {
    $note = trim($note);
    if ($note === '') {
        return null;
    }

    $validated = filter_var($note, FILTER_VALIDATE_URL);
    if ($validated !== false) {
        $scheme = strtolower((string)parse_url($validated, PHP_URL_SCHEME));
        if ($scheme === 'http' || $scheme === 'https') {
            return $validated;
        }

        return null;
    }

    if (preg_match('/^[a-z][a-z0-9+.-]*:/i', $note)) {
        return null;
    }

    $candidate = 'https://' . ltrim($note, '/');
    $validated = filter_var($candidate, FILTER_VALIDATE_URL);
    if ($validated !== false) {
        $scheme = strtolower((string)parse_url($validated, PHP_URL_SCHEME));
        if ($scheme === 'https') {
            return $validated;
        }
    }

    return null;
}
?>

<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>إدارة الفنادق</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.rtl.min.css" rel="stylesheet">
    <link href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700&display=swap" rel="stylesheet">

    <style>
        body { font-family: 'Cairo', 'Segoe UI', Tahoma, sans-serif; }
        .modal-content { border-radius: 14px; }
        .modal-header { background-color: #1e3a8a; color: #fff; border-radius: 14px 14px 0 0; }
        .modal-header .btn-close { filter: invert(1) grayscale(100%) brightness(200%); }
        .container { margin-top: 20px; }

        .detail-clickable {
            cursor: pointer;
            position: relative;
            transition: background-color 0.15s;
        }
        .detail-clickable:hover { background-color: #eff6ff; }
        .detail-clickable::after {
            content: '\F4FA'; /* bi-info-circle */
            font-family: 'bootstrap-icons';
            position: absolute;
            top: 4px;
            inset-inline-start: 6px;
            font-size: 12px;
            color: #94a3b8;
            opacity: 0.5;
        }
        .detail-clickable:hover::after { opacity: 1; color: #2563eb; }

        .actions-cell { white-space: nowrap; }

        .hotel-name-cell a {
            font-weight: 600;
        }

        /* DataTables alignment tweaks for RTL */
        table.dataTable thead th { text-align: right; }
    </style>
</head>

<body>
    <div class="container-fluid pt-2">
        <?php render_root_navbar('hotels'); ?>
    </div>

    <div class="container">
        <h2 class="text-center mb-4">إدارة الفنادق</h2>

        <?php if ($isHotelAdmin): ?>
        <div class="text-center mb-3">
            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addHotelModal">
                <i class="bi bi-plus-lg"></i> إضافة فندق جديد
            </button>
        </div>
        <?php endif; ?>

        <table id="hotelsTable" class="table table-bordered table-striped w-100">
            <thead class="table-light">
                <tr>
                    <th>رقم الفندق</th>
                    <th>اسم الفندق</th>
                    <th>عدد الغرف</th>
                    <th>عدد الأسرّة</th>
                    <th>الغرف المحجوزة</th>
                    <th>الغرف المتاحة</th>
                    <th>الغرف المحجوزة المتاحة</th>
                    <th>غرف محجوزة غير مكتملة</th>
                    <th>الأسرّة المتاحة</th>
                    <th>إجمالي الحجاج</th>
                    <?php if ($isHotelAdmin): ?><th>الإجراءات</th><?php endif; ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($hotels as $hotel): ?>
                    <tr>
                        <td><?= e((string)$hotel['id']) ?></td>
                        <td class="hotel-name-cell" title="<?= e($hotel['note'] ?? '') ?>"><?php
                            $linkHref = note_as_safe_href((string)($hotel['note'] ?? ''));
                            if ($linkHref !== null) {
                                ?><a href="<?= e($linkHref) ?>" target="_blank" rel="noopener noreferrer"><?= e($hotel['hotel_name']) ?></a><?php
                            } else {
                                echo e($hotel['hotel_name']);
                            }
                        ?></td>
                        <td><?= e((string)$hotel['total_rooms']) ?></td>
                        <td><?= e((string)$hotel['total_beds']) ?></td>
                        <td class="detail-clickable" data-detail="reserved" title="عرض الغرف المحجوزة"><?= e((string)$hotel['reserved_rooms']) ?></td>
                        <td class="detail-clickable" data-detail="available" title="عرض الغرف المتاحة"><?= e((string)$hotel['available_rooms']) ?></td>
                        <td class="detail-clickable" data-detail="reserved_empty" title="عرض الغرف المحجوزة المتاحة"><?= e((string)$hotel['reserved_empty_rooms']) ?></td>
                        <td class="detail-clickable" data-detail="reserved_incomplete" title="عرض الغرف المحجوزة غير المكتملة"><?= e((string)$hotel['reserved_incomplete_rooms']) ?></td>
                        <td><?= e((string)$hotel['available_beds']) ?></td>
                        <td class="detail-clickable" data-detail="pilgrims" title="عرض الحجاج"><?= e((string)$hotel['pilgrims_count']) ?></td>
                        <?php if ($isHotelAdmin): ?>
                        <td class="actions-cell">
                            <button class="btn btn-success btn-sm edit-btn"
                                data-id="<?= e((string)$hotel['id']) ?>"
                                data-name="<?= e($hotel['hotel_name']) ?>"
                                data-address="<?= e($hotel['address'] ?? '') ?>"
                                data-note="<?= e($hotel['note'] ?? '') ?>">
                                <i class="bi bi-pencil"></i> تعديل
                            </button>
                            <button class="btn btn-danger btn-sm delete-btn"
                                data-id="<?= e((string)$hotel['id']) ?>"
                                data-name="<?= e($hotel['hotel_name']) ?>">
                                <i class="bi bi-trash"></i> حذف
                            </button>
                        </td>
                        <?php endif; ?>
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
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="إغلاق"></button>
                </div>
                <div class="modal-body">
                    <form id="addHotelForm" autocomplete="off">
                        <div class="mb-3">
                            <label for="hotelName" class="form-label">اسم الفندق</label>
                            <input type="text" class="form-control" id="hotelName" required>
                        </div>
                        <div class="mb-3">
                            <label for="hotelAddress" class="form-label">العنوان</label>
                            <input type="text" class="form-control" id="hotelAddress" required>
                        </div>
                        <div class="mb-3">
                            <label for="hotelnote" class="form-label">ملاحظات / رابط</label>
                            <input type="text" class="form-control" id="hotelnote" placeholder="https://...">
                            <div class="form-text">إذا وضعت رابطًا يصبح اسم الفندق في الجدول نقرة عليه.</div>
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
                    <h5 class="modal-title" id="editHotelModalLabel">تعديل بيانات الفندق</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="إغلاق"></button>
                </div>
                <div class="modal-body">
                    <form id="editHotelForm" autocomplete="off">
                        <input type="hidden" id="editHotelId">
                        <div class="mb-3">
                            <label for="editHotelName" class="form-label">اسم الفندق</label>
                            <input type="text" class="form-control" id="editHotelName" required>
                        </div>
                        <div class="mb-3">
                            <label for="editHotelAddress" class="form-label">العنوان</label>
                            <input type="text" class="form-control" id="editHotelAddress" required>
                        </div>
                        <div class="mb-3">
                            <label for="editHotelnote" class="form-label">ملاحظات / رابط</label>
                            <input type="text" class="form-control" id="editHotelnote" placeholder="https://...">
                            <div class="form-text">إذا وضعت رابطًا يصبح اسم الفندق في الجدول نقرة عليه.</div>
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

    <!-- Detail Modal -->
    <div class="modal fade" id="availableRoomsModal" tabindex="-1" aria-labelledby="availableRoomsModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="availableRoomsModalLabel">التفاصيل</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="إغلاق"></button>
                </div>
                <div class="modal-body">
                    <div class="text-center text-muted py-3" id="detailLoading">
                        <span class="spinner-border spinner-border-sm"></span> جارٍ التحميل...
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إغلاق</button>
                </div>
            </div>
        </div>
    </div>

    <script>
        // PHP-injected CSRF token (escaped, single-quoted to be safe)
        window.CSRF_TOKEN = '<?= e($csrf) ?>';
    </script>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

    <script>
        $(function () {
            const table = $('#hotelsTable').DataTable({
                language: { url: 'https://cdn.datatables.net/plug-ins/1.13.6/i18n/ar.json' },
                order: [[0, 'desc']],
                columnDefs: [
                    { targets: -1, orderable: false, searchable: false }
                ]
            });

            function loadingHtml(btn, busyText) {
                btn.data('original-html', btn.html()).prop('disabled', true)
                   .html('<span class="spinner-border spinner-border-sm"></span> ' + busyText);
            }
            function restoreBtn(btn) {
                if (btn.data('original-html')) btn.html(btn.data('original-html'));
                btn.prop('disabled', false);
            }
            function showError(msg) {
                Swal.fire({ icon: 'error', title: 'خطأ', text: msg || 'حدث خطأ غير متوقع' });
            }
            function showSuccess(title) {
                return Swal.fire({ icon: 'success', title: title, timer: 1300, showConfirmButton: false });
            }

            function esc(s) { return $('<span>').text(s == null ? '' : String(s)).html(); }

            /**
             * Match server note_as_safe_href: only http(s), block other schemes.
             */
            function noteAsSafeHref(note) {
                note = String(note || '').trim();
                if (!note) return null;
                try {
                    const u = new URL(note);
                    if (u.protocol === 'http:' || u.protocol === 'https:') return u.href;
                } catch (ignore) { /* not absolute */ }
                if (/^[a-z][a-z0-9+.-]*:/i.test(note)) return null;
                try {
                    const u = new URL('https://' + note.replace(/^\/+/, ''));
                    if (u.hostname && u.hostname.includes('.')) return u.href;
                } catch (ignore2) { /* invalid */ }
                return null;
            }

            function renderHotelNameCellHtml(name, note) {
                const href = noteAsSafeHref(note);
                const nameEsc = esc(name);
                const hrefEsc = esc(href || '');
                if (href) {
                    return '<a href="' + hrefEsc + '" target="_blank" rel="noopener noreferrer">' + nameEsc + '</a>';
                }
                return nameEsc;
            }

            // Add hotel
            $('#saveHotelBtn').on('click', function () {
                const $btn = $(this);
                const name = $('#hotelName').val().trim();
                const address = $('#hotelAddress').val().trim();
                const note = $('#hotelnote').val().trim();
                if (!name || !address) {
                    Swal.fire({ icon: 'warning', title: 'تنبيه', text: 'الرجاء تعبئة الاسم والعنوان' });
                    return;
                }
                loadingHtml($btn, 'جارٍ الحفظ...');
                $.ajax({
                    url: 'hotel.php',
                    method: 'POST',
                    dataType: 'json',
                    data: { action: 'add', hotel_name: name, address: address, note: note, csrf_token: window.CSRF_TOKEN }
                }).done(function (res) {
                    if (res && res.status === 'success') {
                        $('#addHotelModal').modal('hide');
                        $('#addHotelForm')[0].reset();
                        showSuccess('تم الحفظ').then(() => location.reload());
                    } else {
                        showError(res && res.message);
                    }
                }).fail(function (xhr) {
                    showError((xhr.responseJSON && xhr.responseJSON.message) || 'فشل الاتصال بالخادم');
                }).always(function () {
                    restoreBtn($btn);
                });
            });

            // Open edit modal — fill fields including hidden id
            $(document).on('click', '.edit-btn', function () {
                $('#editHotelId').val($(this).data('id'));
                $('#editHotelName').val($(this).data('name'));
                $('#editHotelAddress').val($(this).data('address'));
                $('#editHotelnote').val($(this).data('note'));
                bootstrap.Modal.getOrCreateInstance(document.getElementById('editHotelModal')).show();
            });

            // Update — single handler bound once
            $('#updateHotelBtn').on('click', function () {
                const $btn = $(this);
                const id = $('#editHotelId').val();
                const name = $('#editHotelName').val().trim();
                const address = $('#editHotelAddress').val().trim();
                const note = $('#editHotelnote').val().trim();
                if (!id || !name || !address) {
                    Swal.fire({ icon: 'warning', title: 'تنبيه', text: 'الرجاء تعبئة الاسم والعنوان' });
                    return;
                }
                loadingHtml($btn, 'جارٍ التحديث...');
                $.ajax({
                    url: 'hotel.php',
                    method: 'POST',
                    dataType: 'json',
                    data: { action: 'edit', hotel_id: id, hotel_name: name, address: address, note: note, csrf_token: window.CSRF_TOKEN }
                }).done(function (res) {
                    if (res && res.status === 'success') {
                        $('#editHotelModal').modal('hide');
                        // U7: in-place update
                        const $row = $(`.edit-btn[data-id="${id}"]`).closest('tr');
                        $row.find('.hotel-name-cell').attr('title', note || '').html(renderHotelNameCellHtml(name, note));
                        $row.find('.edit-btn')
                            .attr('data-name', name).data('name', name)
                            .attr('data-address', address).data('address', address)
                            .attr('data-note', note).data('note', note);
                        $row.find('.delete-btn')
                            .attr('data-name', name).data('name', name);
                        table.row($row).invalidate('dom').draw(false);
                        showSuccess('تم التحديث');
                    } else {
                        showError(res && res.message);
                    }
                }).fail(function (xhr) {
                    showError((xhr.responseJSON && xhr.responseJSON.message) || 'فشل الاتصال بالخادم');
                }).always(function () {
                    restoreBtn($btn);
                });
            });

            // Delete — confirmation now happens via SweetAlert BEFORE the AJAX
            $(document).on('click', '.delete-btn', function () {
                const $btn = $(this);
                const id = $btn.data('id');
                const name = $btn.data('name');
                const $row = $btn.closest('tr');
                Swal.fire({
                    title: 'حذف الفندق؟',
                    html: `سيتم حذف <strong>${$('<span>').text(name).html()}</strong> وجميع غرفه وحجوزاته وحجاجه. هل أنت متأكد؟`,
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonColor: '#dc2626',
                    confirmButtonText: 'نعم، احذف',
                    cancelButtonText: 'إلغاء'
                }).then(r => {
                    if (!r.isConfirmed) return;
                    $.ajax({
                        url: 'hotel.php',
                        method: 'POST',
                        dataType: 'json',
                        data: { action: 'delete', hotel_id: id, csrf_token: window.CSRF_TOKEN }
                    }).done(function (res) {
                        if (res && res.status === 'success') {
                            table.row($row).remove().draw(false);
                            showSuccess('تم الحذف');
                        } else {
                            showError(res && res.message);
                        }
                    }).fail(function (xhr) {
                        showError((xhr.responseJSON && xhr.responseJSON.message) || 'فشل الاتصال بالخادم');
                    });
                });
            });

            // Detail cells — open relevant detail endpoint based on data-detail
            $(document).on('click', '.detail-clickable', function () {
                const kind = $(this).data('detail');
                const hotelName = $(this).closest('tr').find('.hotel-name-cell').text().trim();
                const $modal = $('#availableRoomsModal');
                const $body = $modal.find('.modal-body');

                let url, title, render;
                if (kind === 'available') {
                    url = 'hotel_available_room.php';
                    title = 'الغرف المتاحة - ' + hotelName;
                    render = renderAvailableRooms;
                } else if (kind === 'reserved') {
                    url = 'hotel_not_available_room.php';
                    title = 'الغرف المحجوزة - ' + hotelName;
                    render = renderReservedRooms;
                } else if (kind === 'reserved_empty') {
                    url = 'hotel_reserved_empty_room.php';
                    title = 'الغرف المحجوزة المتاحة - ' + hotelName;
                    render = renderReservedEmptyRooms;
                } else if (kind === 'reserved_incomplete') {
                    url = 'hotel_reserved_incomplete_room.php';
                    title = 'غرف محجوزة غير مكتملة - ' + hotelName;
                    render = renderReservedIncompleteRooms;
                } else if (kind === 'pilgrims') {
                    url = 'hotel_assigned_pilgrims.php';
                    title = 'الحجاج - ' + hotelName;
                    render = renderPilgrims;
                } else {
                    return;
                }

                $modal.find('.modal-title').text(title);
                $body.html('<div class="text-center text-muted py-3"><span class="spinner-border spinner-border-sm"></span> جارٍ التحميل...</div>');
                bootstrap.Modal.getOrCreateInstance($modal[0]).show();

                $.ajax({ url: url, method: 'GET', dataType: 'json', data: { hotel_name: hotelName } })
                    .done(function (data) {
                        if (data && data.error) {
                            $body.html('<div class="alert alert-warning">' + $('<span>').text(data.error).html() + '</div>');
                            return;
                        }
                        $body.html(render(data || []));
                    })
                    .fail(function () {
                        $body.html('<div class="alert alert-danger">تعذر تحميل البيانات</div>');
                    });
            });

            // Renderers — they receive arrays and return HTML strings.
            // All variable text is escaped via esc() above.
            function renderAvailableRooms(rooms) {
                if (!rooms.length) return '<div class="text-center text-muted py-3">لا توجد غرف متاحة</div>';
                const typeCounts = {};
                rooms.forEach(r => { typeCounts[r.room_type] = (typeCounts[r.room_type] || 0) + 1; });
                let summary = Object.entries(typeCounts)
                    .map(([t, c]) => `نوع الغرفة (${esc(t)}): ${c} غرفة · ${t * c} سرير`)
                    .join('<br>');
                let html = `<div class="mb-3"><strong>الملخص:</strong><br>${summary}</div>`;
                html += '<ul class="list-group">';
                rooms.forEach(r => {
                    html += `<li class="list-group-item d-flex justify-content-between">
                                <span>رقم الغرفة: <strong>${esc(r.room_num)}</strong></span>
                                <span>النوع: ${esc(r.room_type)}</span>
                             </li>`;
                });
                html += '</ul>';
                return html;
            }

            function renderReservedRooms(rooms) {
                if (!rooms.length) return '<div class="text-center text-muted py-3">لا توجد غرف محجوزة</div>';
                const groupCounts = {};
                rooms.forEach(r => { groupCounts[r.group_name] = (groupCounts[r.group_name] || 0) + 1; });
                let summary = Object.entries(groupCounts)
                    .map(([g, c]) => `${esc(g)}: ${c} حجز`)
                    .join('<br>');
                let html = `<div class="mb-3"><strong>الملخص:</strong><br>${summary}</div>`;
                html += '<ul class="list-group">';
                rooms.forEach(r => {
                    html += `<li class="list-group-item d-flex justify-content-between">
                                <span>رقم الغرفة: <strong>${esc(r.room_num)}</strong></span>
                                <span>المجموعة: ${esc(r.group_name)}</span>
                             </li>`;
                });
                html += '</ul>';
                return html;
            }

            function renderReservedEmptyRooms(rooms) {
                if (!rooms.length) return '<div class="text-center text-muted py-3">لا توجد غرف محجوزة بدون حجاج</div>';
                const roomNumbers = rooms.map(r => esc(r.room_num)).join('، ');
                let html = `<div class="mb-3"><strong>أرقام الغرف (${rooms.length}):</strong><br>${roomNumbers}</div>`;
                html += '<ul class="list-group">';
                rooms.forEach(r => {
                    html += `<li class="list-group-item d-flex justify-content-between">
                                <span>رقم الغرفة: <strong>${esc(r.room_num)}</strong></span>
                                <span>المجموعة: ${esc(r.group_name)} | النوع: ${esc(r.room_type)}</span>
                             </li>`;
                });
                html += '</ul>';
                return html;
            }

            function renderReservedIncompleteRooms(rooms) {
                if (!rooms.length) return '<div class="text-center text-muted py-3">لا توجد غرف محجوزة غير مكتملة</div>';
                const roomNumbers = rooms.map(r => esc(r.room_num)).join('، ');
                let html = `<div class="mb-3"><strong>أرقام الغرف (${rooms.length}):</strong><br>${roomNumbers}</div>`;
                html += '<ul class="list-group">';
                rooms.forEach(r => {
                    const count = (r.pilgrim_count == null) ? 0 : r.pilgrim_count;
                    html += `<li class="list-group-item d-flex justify-content-between">
                                <span>رقم الغرفة: <strong>${esc(r.room_num)}</strong> <span class="text-muted">(${esc(r.group_name)})</span></span>
                                <span>المُسكَّنون: <strong>${esc(count)}</strong> / ${esc(r.room_type)}</span>
                             </li>`;
                });
                html += '</ul>';
                return html;
            }

            function renderPilgrims(rows) {
                if (!rows.length) return '<div class="text-center text-muted py-3">لا يوجد حجاج</div>';
                let html = '<ul class="list-group">';
                rows.forEach(r => {
                    html += `<li class="list-group-item">
                                <div class="d-flex justify-content-between">
                                    <span>المجموعة: <strong>${esc(r.group_name)}</strong></span>
                                    <span>الحجاج: ${esc(r.pilgrims)} | الإجمالي: ${esc(r.total_pilgrims)}</span>
                                </div>
                             </li>`;
                });
                html += '</ul>';
                return html;
            }

            // Reset the add form whenever the modal is hidden
            $('#addHotelModal').on('hidden.bs.modal', function () {
                $('#addHotelForm')[0].reset();
            });
        });
    </script>
</body>
</html>
