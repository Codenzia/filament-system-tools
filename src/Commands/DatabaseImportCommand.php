<?php

declare(strict_types=1);

namespace Codenzia\FilamentSystemTools\Commands;

use Codenzia\FilamentSystemTools\Services\DatabaseSqlTool;
use Illuminate\Console\Command;
use RuntimeException;

class DatabaseImportCommand extends Command
{
    protected $signature = 'db:import
                            {path : Path to the SQL file (.sql or .sql.gz)}
                            {--connection= : Database connection name (defaults to DB_CONNECTION)}
                            {--force : Required — confirms you intend to modify the database}';

    protected $description = 'Import a SQL file into the configured database';

    public function __construct(public DatabaseSqlTool $sqlTool)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        if (! $this->option('force')) {
            $this->error('Refusing to import without --force (this will modify your database).');

            return self::FAILURE;
        }

        $path = (string) $this->argument('path');
        $connection = $this->option('connection') ? (string) $this->option('connection') : null;

        try {
            $this->sqlTool->import(
                path: $path,
                connection: $connection,
            );

            $this->info("Database imported from: {$path}");

            return self::SUCCESS;
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }
}
