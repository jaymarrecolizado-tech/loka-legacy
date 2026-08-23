<?php
/**
 * LOKA - Migration System
 */

class Migration {
    private $db;
    private $migrationPath;

    public function __construct() {
        $this->db = db();
        $this->migrationPath = __DIR__ . '/../migrations';
    }

    public function run(string $migrationFile): bool {
        $migrationName = pathinfo($migrationFile, PATHINFO_FILENAME);

        if ($this->isExecuted($migrationName)) {
            echo "Migration {$migrationName} already executed.\n";
            return false;
        }

        echo "Running migration: {$migrationName}...\n";

        $sql = file_get_contents($this->migrationPath . '/' . $migrationFile);

        try {
            $pdo = $this->db->getConnection();
            $pdo->beginTransaction();

            $statements = array_filter(array_map('trim', explode(';', $sql)), 'strlen');
            foreach ($statements as $statement) {
                $pdo->exec($statement);
            }

            $this->recordMigration($migrationName);
            $pdo->commit();

            echo "Migration {$migrationName} completed successfully.\n";
            return true;

        } catch (PDOException $e) {
            $this->db->getConnection()->rollBack();
            echo "Migration {$migrationName} failed: " . $e->getMessage() . "\n";
            throw $e;
        }
    }

    public function runAll(): void {
        $files = glob($this->migrationPath . '/*.sql');
        sort($files);

        echo "Found " . count($files) . " migration files.\n";

        foreach ($files as $file) {
            $basename = basename($file);
            if ($basename !== '000_migration_tracker.sql') {
                $this->run($basename);
            }
        }
    }

    public function isExecuted(string $migrationName): bool {
        try {
            $result = $this->db->fetch(
                "SELECT * FROM schema_migrations WHERE migration = ?",
                [$migrationName]
            );
            return $result !== false;
        } catch (PDOException $e) {
            return false;
        }
    }

    private function recordMigration(string $migrationName): void {
        $this->db->insert('schema_migrations', [
            'migration' => $migrationName,
            'executed_at' => date('Y-m-d H:i:s')
        ]);
    }

    public function getStatus(): array {
        try {
            return $this->db->fetchAll(
                "SELECT * FROM schema_migrations ORDER BY executed_at DESC"
            );
        } catch (PDOException $e) {
            echo "No migrations table found. Run migration 000_migration_tracker.sql first.\n";
            return [];
        }
    }

    public function createTracker(): void {
        if ($this->isTrackerExists()) {
            echo "Migration tracker table already exists.\n";
            return;
        }

        echo "Creating migration tracker table...\n";
        $this->run('000_migration_tracker.sql');
    }

    private function isTrackerExists(): bool {
        $allowedTables = ['schema_migrations'];
        try {
            $pdo = $this->db->getConnection();
            $stmt = $pdo->query("SELECT 1 FROM schema_migrations LIMIT 1");
            return true;
        } catch (PDOException $e) {
            return false;
        }
    }
}
