<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';

// Require admin role
$current_user = require_role('admin');

// Handle delete user
if (isset($_POST['delete_id'])) {
    $id = $_POST['delete_id'];

    // Prevent deleting own account
    if ($id == $current_user['id']) {
        echo json_encode(['success' => false, 'message' => 'لا يمكنك حذف حسابك الخاص']);
        exit;
    }

    // Check if last admin
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE role = 'admin' AND status = 'active'");
    $stmt->execute();
    $admin_count = $stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT role FROM users WHERE id = ?");
    $stmt->execute([$id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($user['role'] === 'admin' && $admin_count <= 1) {
        echo json_encode(['success' => false, 'message' => 'لا يمكن حذف آخر مدير في النظام']);
        exit;
    }

    $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
    $stmt->execute([$id]);

    audit_log($current_user['id'], 'user_deleted', 'user', $id, "Deleted user ID: $id");

    echo json_encode(['success' => true]);
    exit;
}

// Handle edit user
if (isset($_POST['edit_id'])) {
    $id = $_POST['edit_id'];
    $username = $_POST['edit_username'];
    $full_name = $_POST['edit_full_name'];
    $email = $_POST['edit_email'] ?: null;
    $role = $_POST['edit_role'];
    $status = $_POST['edit_status'];
    $password = $_POST['edit_password'] ?? '';

    // Prevent editing own role/status
    if ($id == $current_user['id']) {
        $role = $current_user['role'];
        $status = $current_user['status'];
    }

    // Check if last admin being demoted or deactivated
    if ($id != $current_user['id']) {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE role = 'admin' AND status = 'active' AND id != ?");
        $stmt->execute([$id]);
        $other_admin_count = $stmt->fetchColumn();

        $stmt = $pdo->prepare("SELECT role, status FROM users WHERE id = ?");
        $stmt->execute([$id]);
        $old_user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($old_user['role'] === 'admin' && $old_user['status'] === 'active') {
            if (($role !== 'admin' || $status !== 'active') && $other_admin_count == 0) {
                echo json_encode(['success' => false, 'message' => 'لا يمكن تعطيل أو تغيير دور آخر مدير نشط']);
                exit;
            }
        }
    }

    try {
        if (!empty($password)) {
            $password_hash = password_hash($password, PASSWORD_BCRYPT);
            $stmt = $pdo->prepare("
                UPDATE users
                SET username = ?, email = ?, password_hash = ?, full_name = ?, role = ?, status = ?, updated_at = datetime('now')
                WHERE id = ?
            ");
            $stmt->execute([$username, $email, $password_hash, $full_name, $role, $status, $id]);
        } else {
            $stmt = $pdo->prepare("
                UPDATE users
                SET username = ?, email = ?, full_name = ?, role = ?, status = ?, updated_at = datetime('now')
                WHERE id = ?
            ");
            $stmt->execute([$username, $email, $full_name, $role, $status, $id]);
        }

        audit_log($current_user['id'], 'user_updated', 'user', $id, "Updated user: $username");

        echo json_encode(['success' => true]);
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'خطأ: اسم المستخدم أو البريد الإلكتروني مستخدم بالفعل']);
    }
    exit;
}

// Handle add user
if (isset($_POST['add_user'])) {
    $username = $_POST['username'];
    $email = $_POST['email'] ?: null;
    $password = $_POST['password'];
    $full_name = $_POST['full_name'];
    $role = $_POST['role'];
    $status = $_POST['status'];

    $password_hash = password_hash($password, PASSWORD_BCRYPT);

    try {
        $stmt = $pdo->prepare("
            INSERT INTO users (username, email, password_hash, full_name, role, status)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$username, $email, $password_hash, $full_name, $role, $status]);

        $user_id = $pdo->lastInsertId();
        audit_log($current_user['id'], 'user_created', 'user', $user_id, "Created user: $username");

        echo json_encode(['success' => true]);
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'خطأ: اسم المستخدم أو البريد الإلكتروني مستخدم بالفعل']);
    }
    exit;
}

// Handle reset password
if (isset($_POST['reset_password'])) {
    $id = $_POST['reset_id'];
    $password = $_POST['new_password'];

    $password_hash = password_hash($password, PASSWORD_BCRYPT);

    $stmt = $pdo->prepare("UPDATE users SET password_hash = ?, updated_at = datetime('now') WHERE id = ?");
    $stmt->execute([$password_hash, $id]);

    audit_log($current_user['id'], 'password_reset', 'user', $id, "Reset password for user ID: $id");

    echo json_encode(['success' => true]);
    exit;
}

// DataTables server-side processing
$draw = $_POST['draw'] ?? 1;
$start = $_POST['start'] ?? 0;
$length = $_POST['length'] ?? 10;
$search = $_POST['search']['value'] ?? '';
$order_column_index = $_POST['order'][0]['column'] ?? 0;
$order_dir = $_POST['order'][0]['dir'] ?? 'asc';

$columns = ['username', 'full_name', 'email', 'role', 'status', 'last_login'];
$order_column = $columns[$order_column_index] ?? 'username';

// Build WHERE clause
$where = "1=1";
$params = [];
if (!empty($search)) {
    $where .= " AND (username LIKE ? OR full_name LIKE ? OR email LIKE ?)";
    $search_param = "%$search%";
    $params = [$search_param, $search_param, $search_param];
}

// Get total count
$stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE $where");
$stmt->execute($params);
$total = $stmt->fetchColumn();

// Get filtered data
$stmt = $pdo->prepare("
    SELECT * FROM users
    WHERE $where
    ORDER BY $order_column $order_dir
    LIMIT $length OFFSET $start
");
$stmt->execute($params);
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Format data
$data = [];
foreach ($users as $user) {
    $role_badges = [
        'admin' => '<span class="badge bg-primary">مدير</span>',
        'user' => '<span class="badge bg-secondary">مستخدم</span>',
        'viewer' => '<span class="badge bg-info">مشاهد</span>'
    ];

    $status_badges = [
        'active' => '<span class="badge bg-success">نشط</span>',
        'inactive' => '<span class="badge bg-danger">غير نشط</span>'
    ];

    $is_self = ($user['id'] == $current_user['id']);

    $actions = '<button class="btn btn-warning btn-sm edit-btn" data-id="' . $user['id'] . '">تعديل</button> ';
    $actions .= '<button class="btn btn-info btn-sm reset-password-btn" data-id="' . $user['id'] . '">إعادة تعيين كلمة المرور</button> ';

    if (!$is_self) {
        $actions .= '<button class="btn btn-danger btn-sm delete-btn" data-id="' . $user['id'] . '">حذف</button>';
    }

    $user['role_badge'] = $role_badges[$user['role']] ?? $user['role'];
    $user['status_badge'] = $status_badges[$user['status']] ?? $user['status'];
    $user['actions'] = $actions;
    $user['last_login'] = $user['last_login'] ? date('Y-m-d H:i', strtotime($user['last_login'])) : '-';

    $data[] = $user;
}

echo json_encode([
    'draw' => intval($draw),
    'recordsTotal' => $total,
    'recordsFiltered' => $total,
    'data' => $data
]);
?>
