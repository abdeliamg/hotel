<?php

function run_database_migrations(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS schema_migrations (
            migration TEXT PRIMARY KEY,
            applied_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )
    ");

    $migrations = [
        '2026_05_10_create_pilgrim_flight' => static function (PDO $pdo): void {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS pilgrim_flight (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    barcode TEXT NOT NULL UNIQUE,
                    departed DATE NOT NULL,
                    FOREIGN KEY (barcode) REFERENCES pilgrim(barcode)
                )
            ");
            $pdo->exec("CREATE INDEX IF NOT EXISTS idx_pilgrim_flight_departed ON pilgrim_flight(departed)");
            $pdo->exec("CREATE INDEX IF NOT EXISTS idx_pilgrim_flight_barcode ON pilgrim_flight(barcode)");
        },
        '2026_05_28_create_app_settings' => static function (PDO $pdo): void {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS app_settings (
                    setting_key TEXT PRIMARY KEY,
                    setting_value TEXT NOT NULL,
                    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
                )
            ");
        },
    ];

    $checkStmt = $pdo->prepare("SELECT COUNT(*) FROM schema_migrations WHERE migration = ?");
    $insertStmt = $pdo->prepare("INSERT INTO schema_migrations (migration) VALUES (?)");

    foreach ($migrations as $migration => $callback) {
        $checkStmt->execute([$migration]);
        if ((int)$checkStmt->fetchColumn() > 0) {
            continue;
        }

        $pdo->beginTransaction();
        try {
            $callback($pdo);
            $insertStmt->execute([$migration]);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }
}

?>
