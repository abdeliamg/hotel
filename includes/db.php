<?php
$dsn = "sqlite:" . __DIR__ . "/../hajj_data.db";
try {
    $pdo = new PDO($dsn);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    require_once __DIR__ . "/migrations.php";
    run_database_migrations($pdo);
} catch (Throwable $e) {
    echo "Connection failed: " . $e->getMessage();
    exit();
}
?>