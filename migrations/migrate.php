<?php
// Migration runner for user management system
require_once __DIR__ . '/../includes/db.php';

echo "Starting migration...\n";

try {
    // Read and execute migration SQL
    $sql = file_get_contents(__DIR__ . '/001_add_user_management.sql');
    $pdo->exec($sql);
    echo "✓ Tables created successfully\n";

    // Check if default admin already exists
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE username = 'admin'");
    $stmt->execute();
    $count = $stmt->fetchColumn();

    if ($count == 0) {
        // Create default admin user
        $password_hash = password_hash('123456', PASSWORD_BCRYPT);
        $stmt = $pdo->prepare("
            INSERT INTO users (username, email, password_hash, full_name, role, status)
            VALUES ('admin', 'admin@hajj.local', :password_hash, 'System Administrator', 'admin', 'active')
        ");
        $stmt->execute(['password_hash' => $password_hash]);
        echo "✓ Default admin user created (username: admin, password: 123456)\n";
    } else {
        echo "✓ Default admin user already exists\n";
    }

    echo "\nMigration completed successfully!\n";
    echo "You can now login with: admin / 123456\n";

} catch (PDOException $e) {
    echo "✗ Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
?>
