<?php

declare(strict_types=1);

/**
 * Check if a database connection is available.
 */
function canConnectToDatabase(string $driver, string $host, int $port, string $database = 'prunekeeper', string $username = 'prunekeeper', string $password = 'secret'): bool
{
    try {
        $dsn = match ($driver) {
            'mysql' => "mysql:host={$host};port={$port};dbname={$database}",
            'pgsql' => "pgsql:host={$host};port={$port};dbname={$database}",
            default => null,
        };

        if ($dsn === null) {
            return false;
        }

        $pdo = new PDO($dsn, $username, $password, [
            PDO::ATTR_TIMEOUT => 2,
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);

        return true;
    } catch (PDOException) {
        return false;
    }
}

/**
 * Database configurations for integration testing.
 *
 * Each entry: [driver, host, port, label]
 * - driver: Laravel database driver name
 * - host: Database host (null for SQLite)
 * - port: Database port (null for SQLite)
 * - label: Human-readable label for test output
 *
 * All databases are always included in the dataset.
 * Individual tests use skipIfUnavailable() to handle missing databases:
 * - In CI: Fails (databases should be available)
 * - Locally: Skips with clear message
 */
dataset('databases', function () {
    return [
        'sqlite' => ['sqlite', null, null, 'SQLite (in-memory)'],
        'mysql-8.0' => ['mysql', '127.0.0.1', 3310, 'MySQL 8.0'],
        'mysql-8.4' => ['mysql', '127.0.0.1', 3308, 'MySQL 8.4'],
        'postgres-16' => ['pgsql', '127.0.0.1', 5432, 'PostgreSQL 16'],
        'postgres-15' => ['pgsql', '127.0.0.1', 5434, 'PostgreSQL 15'],
        'postgres-14' => ['pgsql', '127.0.0.1', 5433, 'PostgreSQL 14'],
        'mariadb-11' => ['mysql', '127.0.0.1', 3307, 'MariaDB 11'],
        'mariadb-10' => ['mysql', '127.0.0.1', 3309, 'MariaDB 10.11'],
    ];
});

/**
 * Available databases - returns only database names that are currently available.
 * Useful for tests that just need to know which databases can be tested.
 */
dataset('available-databases', function () {
    $available = ['sqlite'];

    $checks = [
        'mysql-8.0' => ['mysql', '127.0.0.1', 3310],
        'mysql-8.4' => ['mysql', '127.0.0.1', 3308],
        'postgres-16' => ['pgsql', '127.0.0.1', 5432],
        'postgres-15' => ['pgsql', '127.0.0.1', 5434],
        'postgres-14' => ['pgsql', '127.0.0.1', 5433],
        'mariadb-11' => ['mysql', '127.0.0.1', 3307],
        'mariadb-10' => ['mysql', '127.0.0.1', 3309],
    ];

    foreach ($checks as $name => [$driver, $host, $port]) {
        if (canConnectToDatabase($driver, $host, $port)) {
            $available[] = $name;
        }
    }

    return $available;
});
