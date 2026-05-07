<?php
require_once __DIR__ . '/includes/auth.php';

if (isset($_COOKIE['session_token'])) {
    logout_user($_COOKIE['session_token']);
}

header('Location: /login.php');
exit;
?>
