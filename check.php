<?php
require_once __DIR__ . '/includes/auth.php';

// Keep the separate hotel_pilgrim subsystem on its existing authenticated-only guard.
$script_path = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
if (preg_match('#(^|/)hotel_pilgrim/#', $script_path)) {
    $user = get_authenticated_user();

    if (!$user) {
        header('Location: /login.php');
        exit;
    }
} else {
    // Validate session and enforce path-based permissions.
    $user = require_current_path_permission();
}

// Make user data available globally
$GLOBALS['current_user'] = $user;
?>
