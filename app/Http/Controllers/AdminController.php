<?php

namespace App\Http\Controllers;

use App\Models\User;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;


class AdminController extends Controller {


    public function index()
    {
        // Path to Laravel's main log file
        $logFile = storage_path('/logs/laravel.log');

        // You can also show the latest log (most common)
        // or use glob() to list multiple daily logs if you use daily logging

        $logs = [];

        if (File::exists($logFile)) {
            // Read last N lines (much faster & safer than reading huge files)
            $lines = $this->tail($logFile, 1000); // change number of lines as needed

            // Optional: reverse so newest entries are at the top
            // $lines = array_reverse($lines);

            $logs = array_map(function ($line) {
                // You can add some simple coloring/highlighting logic here later
                return $line;
            }, $lines);
        } else {
            $logs[] = "Log file not found: " . $logFile;
        }

        return view('admindash', compact('logs', 'logFile'));
    }

    /**
     * Efficiently read the last N lines of a file
     */
    private function tail($filepath, $lines = 300)
    {
        if (!File::exists($filepath)) {
            return [];
        }

        $content = File::get($filepath);
        $linesArray = explode("\n", $content);

        // Take last N lines
        return array_slice($linesArray, -$lines);
    }
}