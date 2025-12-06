<?php

declare(strict_types=1);

/**
 * Check if running inside Docker container.
 */
function isRunningInDocker(): bool
{
    return getenv('DOCKER_ENV') === 'true';
}

/**
 * Database connection configurations.
 * Returns [host, port] based on environment (Docker vs local).
 */
function getDbConfig(string $service): array
{
    if (isRunningInDocker()) {
        // Inside Docker: use service names and internal ports
        return match ($service) {
            'mysql' => ['mysql', 3306],
            'mysql84' => ['mysql84', 3306],
            'postgres' => ['postgres', 5432],
            'postgres15' => ['postgres15', 5432],
            'postgres14' => ['postgres14', 5432],
            'mariadb' => ['mariadb', 3306],
            'mariadb10' => ['mariadb10', 3306],
            default => ['127.0.0.1', 3306],
        };
    }

    // Local development: use localhost with mapped ports
    return match ($service) {
        'mysql' => ['127.0.0.1', 3310],
        'mysql84' => ['127.0.0.1', 3308],
        'postgres' => ['127.0.0.1', 5432],
        'postgres15' => ['127.0.0.1', 5434],
        'postgres14' => ['127.0.0.1', 5433],
        'mariadb' => ['127.0.0.1', 3307],
        'mariadb10' => ['127.0.0.1', 3309],
        default => ['127.0.0.1', 3306],
    };
}

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
    [$mysqlHost, $mysqlPort] = getDbConfig('mysql');
    [$mysql84Host, $mysql84Port] = getDbConfig('mysql84');
    [$postgresHost, $postgresPort] = getDbConfig('postgres');
    [$postgres15Host, $postgres15Port] = getDbConfig('postgres15');
    [$postgres14Host, $postgres14Port] = getDbConfig('postgres14');
    [$mariadbHost, $mariadbPort] = getDbConfig('mariadb');
    [$mariadb10Host, $mariadb10Port] = getDbConfig('mariadb10');

    return [
        'sqlite' => ['sqlite', null, null, 'SQLite (in-memory)'],
        'mysql-8.0' => ['mysql', $mysqlHost, $mysqlPort, 'MySQL 8.0'],
        'mysql-8.4' => ['mysql', $mysql84Host, $mysql84Port, 'MySQL 8.4'],
        'postgres-16' => ['pgsql', $postgresHost, $postgresPort, 'PostgreSQL 16'],
        'postgres-15' => ['pgsql', $postgres15Host, $postgres15Port, 'PostgreSQL 15'],
        'postgres-14' => ['pgsql', $postgres14Host, $postgres14Port, 'PostgreSQL 14'],
        'mariadb-11' => ['mysql', $mariadbHost, $mariadbPort, 'MariaDB 11'],
        'mariadb-10' => ['mysql', $mariadb10Host, $mariadb10Port, 'MariaDB 10.11'],
    ];
});

/**
 * Available databases - returns only database names that are currently available.
 * Useful for tests that just need to know which databases can be tested.
 */
dataset('available-databases', function () {
    $available = ['sqlite'];

    $checks = [
        'mysql-8.0' => ['mysql', 'mysql'],
        'mysql-8.4' => ['mysql', 'mysql84'],
        'postgres-16' => ['pgsql', 'postgres'],
        'postgres-15' => ['pgsql', 'postgres15'],
        'postgres-14' => ['pgsql', 'postgres14'],
        'mariadb-11' => ['mysql', 'mariadb'],
        'mariadb-10' => ['mysql', 'mariadb10'],
    ];

    foreach ($checks as $name => [$driver, $service]) {
        [$host, $port] = getDbConfig($service);
        if (canConnectToDatabase($driver, $host, $port)) {
            $available[] = $name;
        }
    }

    return $available;
});
