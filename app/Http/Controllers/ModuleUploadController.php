<?php

namespace App\Http\Controllers;

use App\Http\Services\ModuleUploadService;
use Illuminate\Http\Request;

/**
 * "Upload Module" — creates a Module + quiz question bank from two pasted/uploaded files instead
 * of the manual dashboard forms. See module-upload-format.md for the exact file format expected.
 */
class ModuleUploadController extends Controller
{
    public function create()
    {
        return view('modules.upload');
    }

    public function store(Request $request, ModuleUploadService $uploadService)
    {
        $request->validate([
            'content_file' => 'required|file|max:2048',
            'questions_file' => 'nullable|file|max:2048',
        ]);

        $contentExt = strtolower($request->file('content_file')->getClientOriginalExtension());
        if ($contentExt !== 'md') {
            return back()->withErrors(['content_file' => 'Content file must be a .md file.'])->withInput();
        }

        $questionsRaw = null;
        if ($request->hasFile('questions_file')) {
            $questionsExt = strtolower($request->file('questions_file')->getClientOriginalExtension());
            if (!in_array($questionsExt, ['yaml', 'yml'], true)) {
                return back()->withErrors(['questions_file' => 'Questions file must be a .yaml or .yml file.'])->withInput();
            }
            $questionsRaw = file_get_contents($request->file('questions_file')->getRealPath());
        }

        $contentRaw = file_get_contents($request->file('content_file')->getRealPath());

        // The form always posts this field (a hidden "0" input backs the checkbox, same pattern
        // as the Published checkbox on the module edit form), so boolean()'s own default never
        // matters here — the "checked by default" behavior lives in the view, not here.
        $createdBy = $request->boolean('attribute_as_mindcollector')
            ? \App\Models\User::where('email', \App\Models\User::SYSTEM_ENGINE_EMAIL)->value('id')
            : auth()->id();

        $result = $uploadService->import($contentRaw, $questionsRaw, $createdBy);

        if (!empty($result['errors'])) {
            return back()->withErrors(['upload' => $result['errors']])->withInput();
        }

        return redirect()->route('modules.edit', $result['module'])
            ->with('success', 'Module imported. Try it out before assigning it to anyone.');
    }
}
