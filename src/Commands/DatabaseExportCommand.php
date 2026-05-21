<?php

declare(strict_types=1);

namespace Codenzia\FilamentSystemTools\Commands;

use Codenzia\FilamentSystemTools\Services\DatabaseSqlTool;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use RuntimeException;

class DatabaseExportCommand extends Command
{
    protected $signature = 'db:export
                            {--connection= : Database connection name (defaults to DB_CONNECTION)}
                            {--path= : Output path (defaults to filament-system-tools.backup_path)}
                            {--gzip : Gzip the output (writes .sql.gz)}
                            {--table=* : Limit export to specific tables (repeatable; SQLite/MySQL only)}';

    protected $description = 'Export the configured database to a SQL (or .sql.gz) file';

    public function __construct(public DatabaseSqlTool $sqlTool)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $connection = $this->option('connection') ? (string) $this->option('connection') : null;
        $path = $this->option('path') ? (string) $this->option('path') : null;
        $gzip = (bool) $this->option('gzip');

        if (is_string($path) && $path !== '' && Str::endsWith($path, '.gz')) {
            $gzip = true;
        }

        if ($gzip && is_string($path) && $path !== '' && ! Str::endsWith($path, '.gz')) {
            $path .= '.gz';
        }

        /** @var list<string> $tables */
        $tables = (array) ($this->option('table') ?? []);

        try {
            $exportedPath = $this->sqlTool->export(
                connection: $connection,
                path: $path,
                gzip: $gzip,
                tables: $tables,
            );

            $this->info("Database exported to: {$exportedPath}");

            return self::SUCCESS;
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }
}
