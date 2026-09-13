<?php

declare(strict_types=1);

namespace Codenzia\FilamentSystemTools\Livewire;

use Codenzia\FilamentSystemTools\Support\SqlStatementSplitter;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Livewire\Component;

class SqlQueryRunner extends Component
{
    /**
     * SQLite pragmas that only report schema/state. Anything else — including
     * every assignment form — counts as a mutation.
     */
    private const READ_ONLY_PRAGMAS = [
        'COLLATION_LIST',
        'COMPILE_OPTIONS',
        'DATABASE_LIST',
        'ENCODING',
        'FOREIGN_KEY_CHECK',
        'FOREIGN_KEY_LIST',
        'FREELIST_COUNT',
        'INDEX_INFO',
        'INDEX_LIST',
        'INDEX_XINFO',
        'INTEGRITY_CHECK',
        'PAGE_COUNT',
        'PAGE_SIZE',
        'QUICK_CHECK',
        'SCHEMA_VERSION',
        'TABLE_INFO',
        'TABLE_LIST',
        'TABLE_XINFO',
        'USER_VERSION',
    ];

    public string $tableName = '';

    public string $sql = '';

    /**
     * When true (default) only a single read-only statement
     * (SELECT/SHOW/DESCRIBE/EXPLAIN/PRAGMA) may run. Writes and DDL require the
     * user to deliberately turn this off.
     */
    public bool $readOnly = true;

    /** @var array<int, array<string, mixed>> */
    public array $results = [];

    /** @var array<string> */
    public array $columns = [];

    public string $error = '';

    public string $message = '';

    public float $executionTime = 0;

    public int $affectedRows = 0;

    public function mount(string $tableName): void
    {
        $this->tableName = $tableName;
        $this->sql = "SELECT * FROM \"{$tableName}\" LIMIT 100";
    }

    public function canExecute(): bool
    {
        return filament()->auth()->user()?->can('execute_sql_queries') ?? false;
    }

    public function execute(): void
    {
        $this->reset(['results', 'columns', 'error', 'message', 'executionTime', 'affectedRows']);

        if (! $this->canExecute()) {
            $this->error = __('You are not authorised to run SQL queries.');

            return;
        }

        $sql = trim($this->sql);

        if (empty($sql)) {
            $this->error = __('Please enter a SQL query.');

            return;
        }

        // Reject multi-statement payloads: only one statement may run at a time
        // (a `;` inside a quoted literal is not a separator — see the splitter).
        if (count(SqlStatementSplitter::split($sql)) > 1) {
            $this->error = __('Only a single SQL statement may be executed at a time.');

            return;
        }

        $upper = strtoupper($sql);
        $isSelect = $this->isReadStatement($upper);

        // Read-only mode (default) forbids everything but a single read statement.
        if ($this->readOnly && ! $isSelect) {
            Log::warning('system-tools.sql.refused', [
                'user_id' => filament()->auth()->user()?->getAuthIdentifier(),
                'table' => $this->tableName,
                'statement' => $this->redact($sql),
            ]);

            $this->error = __('Read-only mode is on. Turn it off to run INSERT/UPDATE/DELETE or DDL statements.');

            return;
        }

        $startTime = microtime(true);

        try {
            if ($isSelect) {
                $rows = DB::select($sql);
                $this->executionTime = round((microtime(true) - $startTime) * 1000, 2);

                $maxRows = max(1, (int) config('filament-system-tools.sql.max_rows', 500));
                $truncated = count($rows) > $maxRows;

                if (! empty($rows)) {
                    $this->columns = array_keys((array) $rows[0]);
                    $this->results = array_map(fn ($row) => (array) $row, array_slice($rows, 0, $maxRows));
                }

                $this->message = $truncated
                    ? __('Showing the first :shown of :count row(s), returned in :time ms', [
                        'shown' => $maxRows,
                        'count' => count($rows),
                        'time' => $this->executionTime,
                    ])
                    : __(':count row(s) returned in :time ms', [
                        'count' => count($rows),
                        'time' => $this->executionTime,
                    ]);
            } else {
                $isDml = str_starts_with($upper, 'INSERT')
                    || str_starts_with($upper, 'UPDATE')
                    || str_starts_with($upper, 'DELETE');

                // Audit every write/DDL statement with the acting user id.
                // Literals are redacted: raw SQL routinely carries secrets.
                Log::warning('system-tools.sql', [
                    'user_id' => filament()->auth()->user()?->getAuthIdentifier(),
                    'table' => $this->tableName,
                    'statement' => $this->redact($sql),
                ]);

                if ($isDml) {
                    // DML statements return affected row count
                    $this->affectedRows = DB::affectingStatement($sql);
                } else {
                    // DDL (CREATE, ALTER, DROP) and other statements
                    DB::unprepared($sql);
                }

                $this->executionTime = round((microtime(true) - $startTime) * 1000, 2);

                $this->message = $isDml
                    ? __('Query executed. :count row(s) affected in :time ms.', [
                        'count' => $this->affectedRows,
                        'time' => $this->executionTime,
                    ])
                    : __('Query executed successfully in :time ms.', [
                        'time' => $this->executionTime,
                    ]);
            }
        } catch (\Throwable $e) {
            $this->executionTime = round((microtime(true) - $startTime) * 1000, 2);
            $this->error = $e->getMessage();
        }
    }

    /**
     * A statement counts as a read only when it opens with an introspection or
     * SELECT keyword. PRAGMA is accepted in its query form alone: `PRAGMA x = y`
     * writes connection state and is treated like any other mutation.
     */
    private function isReadStatement(string $upperSql): bool
    {
        $upperSql = ltrim($upperSql);

        foreach (['SELECT ', 'SELECT(', 'SHOW ', 'DESCRIBE ', 'DESC ', 'EXPLAIN '] as $prefix) {
            if (str_starts_with($upperSql, $prefix)) {
                return true;
            }
        }

        // A common table expression may still end in a write on PostgreSQL.
        if (str_starts_with($upperSql, 'WITH ')) {
            return preg_match('/\b(INSERT|UPDATE|DELETE|MERGE|CREATE|ALTER|DROP|TRUNCATE)\b/', $upperSql) === 0;
        }

        if (str_starts_with($upperSql, 'PRAGMA ')) {
            if (str_contains($upperSql, '=')) {
                return false;
            }

            $name = strtok(substr($upperSql, 7), " (\t\n");

            return in_array($name, self::READ_ONLY_PRAGMAS, true);
        }

        return false;
    }

    /**
     * Replace quoted literals so an audit entry keeps the statement shape
     * without carrying the values it contained.
     */
    private function redact(string $sql): string
    {
        return (string) preg_replace(["/'(?:[^']|'')*'/", '/"(?:[^"]|"")*"/'], ["'?'", '"?"'], $sql);
    }

    public function render(): View
    {
        return view('filament-system-tools::livewire.sql-query-runner');
    }
}
