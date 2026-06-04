<?php

namespace App\Livewire\Admin;

use Livewire\Component;

class LogViewer extends Component
{
    public bool $showDebug = false;

    public function refresh(): void
    {
        // triggers re-render; getEntriesProperty re-reads the file
    }

    public function getEntriesProperty(): array
    {
        $path = storage_path('logs/laravel.log');

        if (! file_exists($path)) {
            return [];
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES);
        $lines = array_slice($lines, -800);

        $entries = [];
        $current = null;

        foreach ($lines as $line) {
            if (preg_match('/^\[\d{4}-\d{2}-\d{2}/', $line)) {
                if ($current !== null) {
                    $entries[] = $this->parseEntry($current);
                }
                $current = $line;
            } elseif ($current !== null) {
                $current .= "\n" . $line;
            }
        }

        if ($current !== null) {
            $entries[] = $this->parseEntry($current);
        }

        return array_reverse($entries);
    }

    private function parseEntry(string $raw): array
    {
        $level = 'INFO';

        if (preg_match('/\]\s+\w+\.(EMERGENCY|ALERT|CRITICAL|ERROR|WARNING|NOTICE|INFO|DEBUG):/i', $raw, $m)) {
            $level = strtoupper($m[1]);
        }

        $bucket = match ($level) {
            'EMERGENCY', 'ALERT', 'CRITICAL', 'ERROR' => 'ERROR',
            'WARNING', 'NOTICE'                        => 'WARNING',
            'DEBUG'                                    => 'DEBUG',
            default                                    => 'INFO',
        };

        // Extract timestamp from first line for the header
        preg_match('/^\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\]/', $raw, $ts);

        return [
            'level'     => $bucket,
            'timestamp' => $ts[1] ?? '',
            'raw'       => $raw,
        ];
    }

    public function render()
    {
        return view('livewire.admin.log-viewer', [
            'entries' => $this->entries,
        ])->layout('layouts.app');
    }
}
