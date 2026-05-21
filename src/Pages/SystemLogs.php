<?php

namespace Codenzia\FilamentSystemTools\Pages;

use Codenzia\FilamentSystemTools\FilamentSystemToolsPlugin;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SystemLogs extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-document-text';

    protected static ?int $navigationSort = 103;

    protected static ?string $slug = 'system/logs';

    protected string $view = 'filament-system-tools::pages.system-logs';

    public int $lines = 100;

    public string $level = 'all';

    public bool $autoRefresh = false;

    /**
     * Available log levels for filtering.
     */
    private const LOG_LEVELS = [
        'emergency',
        'alert',
        'critical',
        'error',
        'warning',
        'notice',
        'info',
        'debug',
    ];

    public static function getNavigationGroup(): ?string
    {
        return FilamentSystemToolsPlugin::make()->getNavigationGroup();
    }

    public static function getNavigationLabel(): string
    {
        return __('System Logs');
    }

    public function getTitle(): string
    {
        return __('System Logs');
    }

    /**
     * Get parsed log entries from the current log file.
     *
     * @return array<int, array{timestamp: string, level: string, message: string, context: string}>
     */
    public function getLogEntries(): array
    {
        $logFile = $this->getCurrentLogFile();

        if (! $logFile || ! File::exists($logFile)) {
            return [];
        }

        $content = File::get($logFile);
        $entries = $this->parseLogContent($content);

        // Filter by level
        if ($this->level !== 'all') {
            $entries = array_filter(
                $entries,
                fn (array $entry): bool => strtolower($entry['level']) === $this->level
            );
            $entries = array_values($entries);
        }

        // Return last N entries
        return array_slice($entries, -$this->lines);
    }

    /**
     * Get the list of available log files (daily rotation).
     *
     * @return array<int, array{name: string, path: string, size: string, date: string}>
     */
    public function getLogFiles(): array
    {
        $logPath = storage_path('logs');
        $files = [];

        if (! File::isDirectory($logPath)) {
            return [];
        }

        foreach (File::files($logPath) as $file) {
            if ($file->getExtension() !== 'log') {
                continue;
            }

            $files[] = [
                'name' => $file->getFilename(),
                'path' => $file->getPathname(),
                'size' => $this->formatBytes($file->getSize()),
                'date' => date('Y-m-d H:i:s', $file->getMTime()),
            ];
        }

        usort($files, fn (array $a, array $b): int => strtotime($b['date']) - strtotime($a['date']));

        return $files;
    }

    /**
     * Get the path to the current (most recent) log file.
     */
    public function getCurrentLogFile(): ?string
    {
        $files = $this->getLogFiles();

        return $files[0]['path'] ?? null;
    }

    /**
     * Whether the current user can clear log files.
     */
    public function canClearLog(): bool
    {
        return filament()->auth()->user()?->can('clear_system_logs') ?? false;
    }

    /**
     * Clear the current log file. Requires the clear_system_logs permission.
     */
    public function clearLog(): void
    {
        if (! $this->canClearLog()) {
            Notification::make()
                ->title(__('Unauthorized'))
                ->danger()
                ->send();

            return;
        }

        $logFile = $this->getCurrentLogFile();

        if ($logFile && File::exists($logFile)) {
            File::put($logFile, '');

            Notification::make()
                ->title(__('Log file cleared'))
                ->success()
                ->send();
        }
    }

    /**
     * Whether the current user can download log files.
     */
    public function canDownloadLog(): bool
    {
        return filament()->auth()->user()?->can('download_system_logs') ?? false;
    }

    /**
     * Whether the current user can flip the runtime log level.
     */
    public function canSetLogLevel(): bool
    {
        return filament()->auth()->user()?->can('set_log_level') ?? false;
    }

    /**
     * Download the current log file. Requires the download_system_logs permission.
     */
    public function downloadLog(): StreamedResponse
    {
        abort_unless($this->canDownloadLog(), 403);

        $logFile = $this->getCurrentLogFile();

        if (! $logFile || ! File::exists($logFile)) {
            Notification::make()
                ->title(__('No log file found'))
                ->danger()
                ->send();

            return response()->streamDownload(fn () => null, 'empty.log');
        }

        return response()->download($logFile);
    }

    public function enableDebugLoggingAction(): Actions\Action
    {
        return Actions\Action::make('enableDebugLogging')
            ->label(__('Debug logging (30 min)'))
            ->icon('heroicon-o-bug-ant')
            ->color('warning')
            ->visible(fn (): bool => $this->canSetLogLevel() && ! Cache::has('system_tools.log_level_override'))
            ->requiresConfirmation()
            ->modalDescription(__('Temporarily lifts the application log level to "debug" for 30 minutes. Verbose log content may include sensitive data — disable as soon as you are finished troubleshooting.'))
            ->action(function (): void {
                if (! $this->canSetLogLevel()) {
                    Notification::make()->title(__('Unauthorized'))->danger()->send();

                    return;
                }

                Cache::put('system_tools.log_level_override', 'debug', now()->addMinutes(30));
                Log::forgetChannel(config('logging.default'));

                Notification::make()
                    ->title(__('Debug logging enabled'))
                    ->body(__('Reverts automatically in 30 minutes.'))
                    ->success()
                    ->send();
            });
    }

    public function disableDebugLoggingAction(): Actions\Action
    {
        return Actions\Action::make('disableDebugLogging')
            ->label(__('Disable debug logging'))
            ->icon('heroicon-o-shield-check')
            ->color('gray')
            ->visible(fn (): bool => $this->canSetLogLevel() && Cache::has('system_tools.log_level_override'))
            ->action(function (): void {
                if (! $this->canSetLogLevel()) {
                    Notification::make()->title(__('Unauthorized'))->danger()->send();

                    return;
                }

                Cache::forget('system_tools.log_level_override');
                Log::forgetChannel(config('logging.default'));

                Notification::make()
                    ->title(__('Debug logging disabled'))
                    ->success()
                    ->send();
            });
    }

    protected function getHeaderActions(): array
    {
        return [
            $this->enableDebugLoggingAction(),
            $this->disableDebugLoggingAction(),
        ];
    }

    /**
     * Get available log levels for the filter dropdown.
     *
     * @return array<string, string>
     */
    public function getLogLevels(): array
    {
        $levels = ['all' => __('All Levels')];

        foreach (self::LOG_LEVELS as $level) {
            $levels[$level] = __(ucfirst($level));
        }

        return $levels;
    }

    /**
     * Get the CSS color class for a log level.
     */
    public static function getLevelColor(string $level): string
    {
        return match (strtolower($level)) {
            'emergency', 'alert', 'critical' => 'red',
            'error' => 'orange',
            'warning' => 'yellow',
            'notice' => 'blue',
            'info' => 'green',
            'debug' => 'gray',
            default => 'gray',
        };
    }

    /**
     * Parse raw log file content into structured entries.
     *
     * @return array<int, array{timestamp: string, level: string, message: string, context: string}>
     */
    private function parseLogContent(string $content): array
    {
        $entries = [];
        $pattern = '/\[(\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}:\d{2}\.?\d*[+-]?\d*:?\d*)\]\s+\w+\.(\w+):\s+(.*)/';

        $lines = explode("\n", $content);
        $currentEntry = null;

        foreach ($lines as $line) {
            if (preg_match($pattern, $line, $matches)) {
                // Save previous entry
                if ($currentEntry !== null) {
                    $entries[] = $currentEntry;
                }

                $currentEntry = [
                    'timestamp' => $matches[1],
                    'level' => $matches[2],
                    'message' => trim($matches[3]),
                    'context' => '',
                ];
            } elseif ($currentEntry !== null && trim($line) !== '') {
                // Append to current entry's context (stack traces, etc.)
                $currentEntry['context'] .= ($currentEntry['context'] ? "\n" : '').rtrim($line);
            }
        }

        // Don't forget the last entry
        if ($currentEntry !== null) {
            $entries[] = $currentEntry;
        }

        return $entries;
    }

    private function formatBytes(int $bytes, int $precision = 2): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];

        for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
            $bytes /= 1024;
        }

        return round($bytes, $precision).' '.$units[$i];
    }
}
