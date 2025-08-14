<?php
// app/Http/Controllers/ReplayController.php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Replay;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

class ReplayController extends Controller
{
    public function create()
    {
        return view('replays.upload');
    }

    public function store(Request $request)
    {
        $request->validate([
        'replay' => [
            'required',
            'file',
            'max:10240',
            function ($attribute, $value, $fail) {
                $ext = strtolower($value->getClientOriginalExtension());
                if ($ext !== 'sc2replay') {
                    $fail('The replay must be a .SC2Replay file.');
                }
            },
        ],
    ]);


        $file = $request->file('replay');
        $originalName = $file->getClientOriginalName();
        $filename = $file->store('replays');
        
        // Check if the file is a valid StarCraft II replay
        $path = $file->getRealPath();
        

        if (!$this->isSc2Replay($path)) {
            return back()->withErrors(['replay' => 'Invalid StarCraft II replay file.']);
        }


        $replay = Replay::create([
            'user_id' => Auth::id(),
            'filename' => $filename,
            'original_name' => $originalName,
            'status' => 'pending',
        ]);

        // Dispatch AI processing job here (later)

        return redirect()->route('replays.show', $replay)->with('success', 'Replay uploaded! AI review will be ready soon.');
    }

    public function show(Replay $replay)
    {
        return view('replays.show', compact('replay'));
    }

    
    private function isSc2Replay($filePath) {
        $handle = fopen($filePath, 'rb');
        $header = fread($handle, 4);
        $rest = fread($handle, 60); // read next bytes for more info if needed
        fclose($handle);

        // Check for MPQ header
        if ($header !== "MPQ\x1B") {
            return false;
        }

        // Optionally check that "StarCraft II replay" appears within first 60 bytes
        if (strpos($rest, "StarCraft II replay") === false) {
            return false;
        }

        return true;
    }


    private function dumpReplayHeader($filePath) {
        $handle = fopen($filePath, 'rb');
        $bytes = fread($handle, 64);
        fclose($handle);

        $hex = unpack('H*', $bytes)[1];
        $ascii = preg_replace('/[\x00-\x1F\x7F-\xFF]/', '.', $bytes); // Replace non-printables with dot

        dd([
            'hex' => strtoupper($hex),
            'ascii' => $ascii,
        ]);
    }



}
