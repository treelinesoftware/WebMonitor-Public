<?php
// Applies any migrations/NNN_*.sql files that haven't been applied yet, in
// order, and records each in schema_migrations. Safe to run repeatedly:
//   php cli/migrate.php
require_once __DIR__ . '/../includes/db.php';

if (PHP_SAPI !== 'cli') exit(1);

$pdo = db();
$pdo->exec('CREATE TABLE IF NOT EXISTS schema_migrations (
  version VARCHAR(100) NOT NULL PRIMARY KEY,
  applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB');

$applied = array_flip($pdo->query('SELECT version FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN));
$files = glob(__DIR__ . '/../migrations/[0-9]*.sql');
sort($files, SORT_STRING);

$count = 0;
foreach ($files as $file) {
    $version = basename($file, '.sql');
    if (isset($applied[$version])) continue;

    echo "Applying $version... ";
    // MariaDB DDL auto-commits, so run statement by statement and only
    // record the migration once every statement has succeeded.
    $sql = preg_replace('/^\s*--.*$/m', '', file_get_contents($file));
    foreach (preg_split('/;\s*$/m', $sql) as $stmt) {
        if (trim($stmt) !== '') $pdo->exec($stmt);
    }
    $pdo->prepare('INSERT INTO schema_migrations (version) VALUES (?)')->execute([$version]);
    echo "done\n";
    $count++;
}

echo $count ? "Applied $count migration(s).\n" : "Database is up to date.\n";
