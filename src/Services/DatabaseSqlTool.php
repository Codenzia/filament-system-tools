<?php

declare(strict_types=1);

namespace Codenzia\FilamentSystemTools\Services;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Driver-aware database export/import service.
 *
 * Supports SQLite, MySQL/MariaDB, and PostgreSQL via their respective CLI tools
 * (sqlite3, mysqldump/mysql, pg_dump/psql). Passwords are passed via environment
 * variables (MYSQL_PWD, PGPASSWORD) to avoid leaking them on the command line or
 * into process listings. Output may optionally be gzipped (.sql.gz).
 */
class DatabaseSqlTool
{
    /**
     * Export the given connection to a SQL file. Returns the resolved output path.
     *
     * @param  list<string>  $tables  Optional table allow-list (SQLite/MySQL only).
     */
    public function export(
        ?string $connection = null,
        ?string $path = null,
        bool $gzip = false,
        array $tables = [],
        int $timeoutSeconds = 600,
    ): string {
        $connection ??= (string) config('database.default');

        $config = $this->getConnectionConfig($connection);
        $driver = (string) $config['driver'];

        $path ??= $this->defaultExportPath(connection: $connection, gzip: $gzip);

        File::ensureDirectoryExists(dirname($path));

        $command = $this->buildExportCommand(
            driver: $driver,
            config: $config,
            path: $path,
            gzip: $gzip,
            tables: $tables,
        );

        $process = Process::timeout($timeoutSeconds)->env($this->buildEnvForDriver($driver, $config));
        $result = $process->run($command);

        if (! $result->successful()) {
            throw new RuntimeException(
                $this->formatProcessFailureMessage(
                    driver: $driver,
                    command: $command,
                    errorOutput: $result->errorOutput() ?: '',
                    fallbackMessage: 'Database export failed.',
                ),
            );
        }

        return $path;
    }

    /**
     * Import a .sql or .sql.gz file into the given connection.
     */
    public function import(
        string $path,
        ?string $connection = null,
        int $timeoutSeconds = 600,
    ): void {
        $connection ??= (string) config('database.default');

        if (! File::exists($path)) {
            throw new RuntimeException("SQL file not found: {$path}");
        }

        $config = $this->getConnectionConfig($connection);
        $driver = (string) $config['driver'];

        $command = $this->buildImportCommand(
            driver: $driver,
            config: $config,
            path: $path,
        );

        $process = Process::timeout($timeoutSeconds)->env($this->buildEnvForDriver($driver, $config));
        $result = $process->run($command);

        if (! $result->successful()) {
            throw new RuntimeException(
                $this->formatProcessFailureMessage(
                    driver: $driver,
                    command: $command,
                    errorOutput: $result->errorOutput() ?: '',
                    fallbackMessage: 'Database import failed.',
                ),
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function getConnectionConfig(string $connection): array
    {
        $config = config("database.connections.{$connection}");

        if (! is_array($config) || empty($config['driver'])) {
            throw new RuntimeException("Unknown database connection [{$connection}].");
        }

        return $config;
    }

    protected function defaultExportPath(string $connection, bool $gzip): string
    {
        $timestamp = now()->format('Ymd_His');
        $name = "db-{$connection}-{$timestamp}.sql";

        if ($gzip) {
            $name .= '.gz';
        }

        $directory = config('filament-system-tools.backup_path', storage_path('app/backups'));

        return rtrim((string) $directory, DIRECTORY_SEPARATOR.'/').DIRECTORY_SEPARATOR.$name;
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, string>
     */
    protected function buildEnvForDriver(string $driver, array $config): array
    {
        return match ($driver) {
            'mysql', 'mariadb' => $this->buildMySqlEnv($config),
            'pgsql' => $this->buildPgSqlEnv($config),
            default => [],
        };
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, string>
     */
    protected function buildMySqlEnv(array $config): array
    {
        $password = (string) ($config['password'] ?? '');

        if ($password === '') {
            return [];
        }

        return ['MYSQL_PWD' => $password];
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, string>
     */
    protected function buildPgSqlEnv(array $config): array
    {
        $password = (string) ($config['password'] ?? '');

        if ($password === '') {
            return [];
        }

        return ['PGPASSWORD' => $password];
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  list<string>  $tables
     */
    protected function buildExportCommand(
        string $driver,
        array $config,
        string $path,
        bool $gzip,
        array $tables,
    ): string {
        $tables = array_values(array_filter(array_map('strval', $tables)));

        return match ($driver) {
            'sqlite' => $this->buildSqliteExportCommand($config, $path, $gzip, $tables),
            'mysql', 'mariadb' => $this->buildMySqlExportCommand($config, $path, $gzip, $tables),
            'pgsql' => $this->buildPgSqlExportCommand($config, $path, $gzip, $tables),
            default => throw new RuntimeException("Database export not supported for driver [{$driver}]."),
        };
    }

    /**
     * @param  array<string, mixed>  $config
     */
    protected function buildImportCommand(string $driver, array $config, string $path): string
    {
        return match ($driver) {
            'sqlite' => $this->buildSqliteImportCommand($config, $path),
            'mysql', 'mariadb' => $this->buildMySqlImportCommand($config, $path),
            'pgsql' => $this->buildPgSqlImportCommand($config, $path),
            default => throw new RuntimeException("Database import not supported for driver [{$driver}]."),
        };
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  list<string>  $tables
     */
    protected function buildSqliteExportCommand(array $config, string $path, bool $gzip, array $tables): string
    {
        $database = $this->sqliteDatabasePath($config);

        $dumpDirective = '.dump';
        if ($tables !== []) {
            $dumpDirective .= ' '.implode(' ', $tables);
        }

        $sqlite3 = (string) config('filament-system-tools.dump.sqlite.sqlite3', 'sqlite3');
        $databaseArg = $this->shellArg($database);
        $dumpArg = $this->shellArg($dumpDirective);
        $pathArg = $this->shellArg($path);

        if ($gzip) {
            $gzipBin = (string) config('filament-system-tools.dump.compression.gzip', 'gzip');

            return "{$sqlite3} {$databaseArg} {$dumpArg} | {$gzipBin} -c > {$pathArg}";
        }

        return "{$sqlite3} {$databaseArg} {$dumpArg} > {$pathArg}";
    }

    /**
     * @param  array<string, mixed>  $config
     */
    protected function buildSqliteImportCommand(array $config, string $path): string
    {
        $database = $this->sqliteDatabasePath($config);

        $sqlite3 = (string) config('filament-system-tools.dump.sqlite.sqlite3', 'sqlite3');
        $databaseArg = $this->shellArg($database);
        $pathArg = $this->shellArg($path);

        if (Str::endsWith($path, '.gz')) {
            $gunzipBin = (string) config('filament-system-tools.dump.compression.gunzip', 'gunzip');

            return "{$gunzipBin} -c {$pathArg} | {$sqlite3} {$databaseArg}";
        }

        return "{$sqlite3} {$databaseArg} < {$pathArg}";
    }

    /**
     * @param  array<string, mixed>  $config
     */
    protected function sqliteDatabasePath(array $config): string
    {
        $database = (string) ($config['database'] ?? '');

        if ($database === '' || $database === ':memory:') {
            throw new RuntimeException('SQLite exports/imports require a file-backed database path.');
        }

        return $database;
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  list<string>  $tables
     */
    protected function buildMySqlExportCommand(array $config, string $path, bool $gzip, array $tables): string
    {
        $host = (string) ($config['host'] ?? '127.0.0.1');
        $port = (string) ($config['port'] ?? '3306');
        $username = (string) ($config['username'] ?? '');
        $database = (string) ($config['database'] ?? '');

        if ($username === '' || $database === '') {
            throw new RuntimeException('MySQL export requires username and database.');
        }

        $mysqldumpBin = (string) config('filament-system-tools.dump.mysql.mysqldump', 'mysqldump');

        $parts = [
            $mysqldumpBin,
            '--single-transaction',
            '--quick',
            '--skip-lock-tables',
            '--host='.$this->shellArg($host),
            '--port='.$this->shellArg($port),
            '--user='.$this->shellArg($username),
            $this->shellArg($database),
        ];

        foreach ($tables as $table) {
            $parts[] = $this->shellArg($table);
        }

        $cmd = implode(' ', $parts);
        $pathArg = $this->shellArg($path);

        if ($gzip) {
            $gzipBin = (string) config('filament-system-tools.dump.compression.gzip', 'gzip');

            return "{$cmd} | {$gzipBin} -c > {$pathArg}";
        }

        return "{$cmd} > {$pathArg}";
    }

    /**
     * @param  array<string, mixed>  $config
     */
    protected function buildMySqlImportCommand(array $config, string $path): string
    {
        $host = (string) ($config['host'] ?? '127.0.0.1');
        $port = (string) ($config['port'] ?? '3306');
        $username = (string) ($config['username'] ?? '');
        $database = (string) ($config['database'] ?? '');

        if ($username === '' || $database === '') {
            throw new RuntimeException('MySQL import requires username and database.');
        }

        $mysqlBin = (string) config('filament-system-tools.dump.mysql.mysql', 'mysql');

        $mysql = implode(' ', [
            $mysqlBin,
            '--host='.$this->shellArg($host),
            '--port='.$this->shellArg($port),
            '--user='.$this->shellArg($username),
            $this->shellArg($database),
        ]);

        $pathArg = $this->shellArg($path);

        if (Str::endsWith($path, '.gz')) {
            $gunzipBin = (string) config('filament-system-tools.dump.compression.gunzip', 'gunzip');

            return "{$gunzipBin} -c {$pathArg} | {$mysql}";
        }

        return "{$mysql} < {$pathArg}";
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  list<string>  $tables
     */
    protected function buildPgSqlExportCommand(array $config, string $path, bool $gzip, array $tables): string
    {
        if ($tables !== []) {
            throw new RuntimeException('PostgreSQL export does not support per-table filtering in this tool.');
        }

        $host = (string) ($config['host'] ?? '127.0.0.1');
        $port = (string) ($config['port'] ?? '5432');
        $username = (string) ($config['username'] ?? '');
        $database = (string) ($config['database'] ?? '');

        if ($username === '' || $database === '') {
            throw new RuntimeException('PostgreSQL export requires username and database.');
        }

        $pgDumpBin = (string) config('filament-system-tools.dump.pgsql.pg_dump', 'pg_dump');

        $cmd = implode(' ', [
            $pgDumpBin,
            '--host='.$this->shellArg($host),
            '--port='.$this->shellArg($port),
            '--username='.$this->shellArg($username),
            $this->shellArg($database),
        ]);

        $pathArg = $this->shellArg($path);

        if ($gzip) {
            $gzipBin = (string) config('filament-system-tools.dump.compression.gzip', 'gzip');

            return "{$cmd} | {$gzipBin} -c > {$pathArg}";
        }

        return "{$cmd} > {$pathArg}";
    }

    /**
     * @param  array<string, mixed>  $config
     */
    protected function buildPgSqlImportCommand(array $config, string $path): string
    {
        $host = (string) ($config['host'] ?? '127.0.0.1');
        $port = (string) ($config['port'] ?? '5432');
        $username = (string) ($config['username'] ?? '');
        $database = (string) ($config['database'] ?? '');

        if ($username === '' || $database === '') {
            throw new RuntimeException('PostgreSQL import requires username and database.');
        }

        $psqlBin = (string) config('filament-system-tools.dump.pgsql.psql', 'psql');

        $psql = implode(' ', [
            $psqlBin,
            '--host='.$this->shellArg($host),
            '--port='.$this->shellArg($port),
            '--username='.$this->shellArg($username),
            $this->shellArg($database),
        ]);

        $pathArg = $this->shellArg($path);

        if (Str::endsWith($path, '.gz')) {
            $gunzipBin = (string) config('filament-system-tools.dump.compression.gunzip', 'gunzip');

            return "{$gunzipBin} -c {$pathArg} | {$psql}";
        }

        return "{$psql} < {$pathArg}";
    }

    protected function formatProcessFailureMessage(
        string $driver,
        string $command,
        string $errorOutput,
        string $fallbackMessage,
    ): string {
        $errorOutput = trim($errorOutput);

        if ($errorOutput === '') {
            return $fallbackMessage;
        }

        $commandNotFound = Str::contains($errorOutput, ['command not found', 'is not recognized']);

        if ($commandNotFound && in_array($driver, ['mysql', 'mariadb'], true) && Str::contains($command, 'mysqldump')) {
            return implode(PHP_EOL, [
                'mysqldump was not found on this machine.',
                '',
                'Fix:',
                '- macOS:    brew install mysql-client',
                '- Ubuntu:   apt install mysql-client',
                '- Windows:  install MySQL Server (mysqldump.exe ships with it)',
                '',
                'Then either ensure it is on PATH, or set absolute paths in .env:',
                '  DB_DUMP_MYSQLDUMP="/full/path/to/mysqldump"',
                '  DB_DUMP_MYSQL="/full/path/to/mysql"',
                '',
                "Original error: {$errorOutput}",
            ]);
        }

        if ($commandNotFound && $driver === 'pgsql' && Str::contains($command, 'pg_dump')) {
            return implode(PHP_EOL, [
                'pg_dump was not found on this machine.',
                '',
                'Fix:',
                '- macOS:    brew install postgresql@16',
                '- Ubuntu:   apt install postgresql-client',
                '- Windows:  install Postgres (pg_dump.exe ships with it)',
                '',
                'Then either ensure it is on PATH, or set absolute paths in .env:',
                '  DB_DUMP_PG_DUMP="/full/path/to/pg_dump"',
                '  DB_DUMP_PSQL="/full/path/to/psql"',
                '',
                "Original error: {$errorOutput}",
            ]);
        }

        if ($commandNotFound && $driver === 'sqlite') {
            return implode(PHP_EOL, [
                'sqlite3 was not found on this machine.',
                '',
                'Fix:',
                '- macOS:    sqlite3 ships with macOS by default',
                '- Ubuntu:   apt install sqlite3',
                '- Windows:  download from https://sqlite.org/download.html',
                '',
                'Then either ensure it is on PATH, or set an absolute path in .env:',
                '  DB_DUMP_SQLITE3="/full/path/to/sqlite3"',
                '',
                "Original error: {$errorOutput}",
            ]);
        }

        return $errorOutput;
    }

    protected function shellArg(string $value): string
    {
        return escapeshellarg($value);
    }
}
