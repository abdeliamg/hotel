<?php
// Authentication library for user management system

require_once __DIR__ . '/db.php';

// Generate cryptographically secure session token
function generate_session_token() {
    return bin2hex(random_bytes(32));
}

// Get client IP address
function get_client_ip() {
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        return $_SERVER['HTTP_X_FORWARDED_FOR'];
    }
    return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
}

// Get user agent
function get_user_agent() {
    return $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
}

// Authenticate user with username and password
function authenticate_user($username, $password) {
    global $pdo;

    $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ? AND status = 'active'");
    $stmt->execute([$username]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($user && password_verify($password, $user['password_hash'])) {
        // Update last login
        $stmt = $pdo->prepare("UPDATE users SET last_login = datetime('now') WHERE id = ?");
        $stmt->execute([$user['id']]);

        // Log successful login
        audit_log($user['id'], 'login_success', 'user', $user['id'], 'User logged in successfully');

        return $user;
    }

    // Log failed login attempt
    if ($user) {
        audit_log($user['id'], 'login_failed', 'user', $user['id'], 'Failed login attempt');
    }

    return false;
}

// Create session for user
function create_session($user_id, $remember_me = false) {
    global $pdo;

    $token = generate_session_token();
    $expires_days = $remember_me ? 30 : 7;
    $expires_at = date('Y-m-d H:i:s', strtotime("+{$expires_days} days"));

    $stmt = $pdo->prepare("
        INSERT INTO user_sessions (user_id, session_token, ip_address, user_agent, expires_at)
        VALUES (?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        $user_id,
        $token,
        get_client_ip(),
        get_user_agent(),
        $expires_at
    ]);

    // Set cookie
    setcookie('session_token', $token, strtotime("+{$expires_days} days"), '/');

    return $token;
}

// Validate session token
function validate_session($token) {
    global $pdo;

    $stmt = $pdo->prepare("
        SELECT s.*, u.*
        FROM user_sessions s
        JOIN users u ON s.user_id = u.id
        WHERE s.session_token = ?
        AND s.expires_at > datetime('now')
        AND u.status = 'active'
    ");
    $stmt->execute([$token]);
    $session = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($session) {
        // Update last activity
        $stmt = $pdo->prepare("UPDATE user_sessions SET last_activity = datetime('now') WHERE session_token = ?");
        $stmt->execute([$token]);

        return $session;
    }

    return false;
}

// Get current authenticated user from session
function get_authenticated_user() {
    if (!isset($_COOKIE['session_token'])) {
        return false;
    }

    return validate_session($_COOKIE['session_token']);
}

// Logout user
function logout_user($token) {
    global $pdo;

    // Get user_id before deleting
    $stmt = $pdo->prepare("SELECT user_id FROM user_sessions WHERE session_token = ?");
    $stmt->execute([$token]);
    $session = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($session) {
        audit_log($session['user_id'], 'logout', 'user', $session['user_id'], 'User logged out');
    }

    // Delete session
    $stmt = $pdo->prepare("DELETE FROM user_sessions WHERE session_token = ?");
    $stmt->execute([$token]);

    // Clear cookie
    setcookie('session_token', '', time() - 3600, '/');
}

// Compare the current role against the minimum role required for a page.
function role_meets_requirement($current_role, $required_role) {
    $role_hierarchy = ['viewer' => 1, 'user' => 2, 'admin' => 3];

    return ($role_hierarchy[$current_role] ?? 0) >= ($role_hierarchy[$required_role] ?? PHP_INT_MAX);
}

// Require specific role
function require_role($required_role) {
    $user = get_authenticated_user();

    if (!$user) {
        header('Location: /login.php');
        exit;
    }

    if (!role_meets_requirement($user['role'] ?? '', $required_role)) {
        http_response_code(403);
        die('Access denied. Insufficient permissions.');
    }

    return $user;
}

function current_user_has_role($required_role) {
    $user = get_authenticated_user();

    return $user && role_meets_requirement($user['role'] ?? '', $required_role);
}

// Pages under /pages are admin-only; other protected pages allow normal users.
function require_current_path_permission() {
    $script_path = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
    $required_role = preg_match('#(^|/)pages/#', $script_path) ? 'admin' : 'user';

    return require_role($required_role);
}

// Check if user has permission
function has_permission($permission) {
    $user = get_authenticated_user();
    if (!$user) return false;

    $permissions = [
        'admin' => ['view', 'create', 'edit', 'delete', 'manage_users'],
        'user' => ['view', 'create', 'edit', 'delete'],
        'viewer' => ['view']
    ];

    return in_array($permission, $permissions[$user['role']] ?? []);
}

// Audit log function
function audit_log($user_id, $action, $entity_type = null, $entity_id = null, $details = null) {
    global $pdo;

    $stmt = $pdo->prepare("
        INSERT INTO audit_log (user_id, action, entity_type, entity_id, details, ip_address)
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        $user_id,
        $action,
        $entity_type,
        $entity_id,
        $details,
        get_client_ip()
    ]);
}

// Clean up expired sessions
function cleanup_expired_sessions() {
    global $pdo;
    $stmt = $pdo->prepare("DELETE FROM user_sessions WHERE expires_at < datetime('now')");
    $stmt->execute();
}

// Generate CSRF token
function generate_csrf_token() {
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

// Validate CSRF token
function validate_csrf_token($token) {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}
?>
