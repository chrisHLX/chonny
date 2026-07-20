<?php

namespace App\Http\Services;

use App\Models\Concept;
use App\Models\Module;
use App\Models\ModulePage;
use App\Models\Proficiency;
use App\Models\Question;
use App\Models\Subject;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Yaml\Yaml;

/**
 * Backs the "Upload Module" tool (see module-upload-format.md for the exact file format this
 * expects). Deliberately reuses the existing Module/ModulePage/Question schema and upsert
 * conventions verbatim — no new columns, no slug ontology, no module-level concept pivot. All
 * matching (subject/proficiency/concept) is by exact existing name, never invented on the fly.
 */
class ModuleUploadService
{
    private const RECOGNISED_QUESTION_TYPES = ['mcq', 'true_false', 'matching_pairs', 'ordering', 'open'];
    private const VALID_DIFFICULTIES = ['easy', 'medium', 'hard'];
    private const VALID_SKILL_TYPES = ['recall', 'analysis', 'application'];

    /**
     * @return array{errors: string[], module: ?Module}
     */
    public function import(string $contentRaw, ?string $questionsRaw, int $userId): array
    {
        [$frontMatter, $body] = $this->parseFrontMatter($contentRaw);

        $errors = [];

        foreach (['title', 'subject', 'proficiency'] as $required) {
            if (empty($frontMatter[$required])) {
                $errors[] = "Content file: missing required field '{$required}'.";
            }
        }

        $subject = null;
        $proficiency = null;

        if (!empty($frontMatter['subject'])) {
            $subject = Subject::where('name', $frontMatter['subject'])->first();
            if (!$subject) {
                $errors[] = "Content file: no Subject named '{$frontMatter['subject']}' exists.";
            }
        }

        if ($subject && !empty($frontMatter['proficiency'])) {
            $proficiency = Proficiency::where('subject_id', $subject->id)
                ->where('name', $frontMatter['proficiency'])
                ->first();
            if (!$proficiency) {
                $errors[] = "Content file: no Proficiency named '{$frontMatter['proficiency']}' exists for subject '{$subject->name}'.";
            }
        }

        $conceptsBySlug = collect();
        if ($subject) {
            $conceptsBySlug = Concept::where('subject_id', $subject->id)->pluck('id', 'name');
        }

        $questions = [];
        if ($questionsRaw !== null && trim($questionsRaw) !== '') {
            $parsed = Yaml::parse($questionsRaw) ?? [];
            $questions = $parsed['questions'] ?? [];

            foreach ($questions as $i => $q) {
                $label = "Question #".($i + 1);

                if (empty($q['type']) || !in_array($q['type'], self::RECOGNISED_QUESTION_TYPES, true)) {
                    $errors[] = "{$label}: unrecognised or missing 'type' (must be one of: ".implode(', ', self::RECOGNISED_QUESTION_TYPES).').';
                }

                if (empty($q['question'])) {
                    $errors[] = "{$label}: missing 'question' text.";
                }

                if (!isset($q['answer']) || !is_array($q['answer']) || empty($q['answer'])) {
                    $errors[] = "{$label}: missing or empty 'answer'.";
                }

                if (!empty($q['difficulty']) && !in_array($q['difficulty'], self::VALID_DIFFICULTIES, true)) {
                    $errors[] = "{$label}: invalid difficulty '{$q['difficulty']}'.";
                }

                if (!empty($q['skill_type']) && !in_array($q['skill_type'], self::VALID_SKILL_TYPES, true)) {
                    $errors[] = "{$label}: invalid skill_type '{$q['skill_type']}'.";
                }

                foreach ($q['concepts'] ?? [] as $conceptName) {
                    if (!$conceptsBySlug->has($conceptName)) {
                        $errors[] = "{$label}: no Concept named '{$conceptName}' exists for subject '".($subject->name ?? '?')."'.";
                    }
                }
            }
        }

        if (!empty($errors)) {
            return ['errors' => $errors, 'module' => null];
        }

        $module = DB::transaction(function () use ($frontMatter, $body, $subject, $proficiency, $questions, $conceptsBySlug, $userId) {
            $module = Module::updateOrCreate(
                ['name' => $frontMatter['title'], 'subject_id' => $subject->id],
                ['created_by' => $userId]
            );

            // Only set on first creation — re-uploading an existing module must not reset its
            // status/published state, both of which may have moved on since (published toggled,
            // status already 'ready'). Publishing is a separate decision made on the module's
            // edit page once it's been tried and trusted.
            if ($module->wasRecentlyCreated) {
                $module->update(['published' => false, 'status' => 'need questions']);
            }

            $module->proficiencies()->sync([$proficiency->id]);

            ModulePage::updateOrCreate(
                ['module_id' => $module->id, 'page_number' => 1],
                [
                    'title' => $frontMatter['title'],
                    'content' => trim($body),
                    'created_by' => $userId,
                    'updated_by' => $userId,
                ]
            );

            if (!empty($questions)) {
                $questionIds = [];

                foreach ($questions as $q) {
                    $question = Question::updateOrCreate(
                        ['question' => $q['question']],
                        [
                            'type' => $q['type'],
                            'difficulty' => $q['difficulty'] ?? 'easy',
                            'skill_type' => $q['skill_type'] ?? 'recall',
                            'answer' => $q['answer'],
                        ]
                    );

                    $question->concepts()->sync(
                        collect($q['concepts'] ?? [])->map(fn ($name) => $conceptsBySlug[$name])->all()
                    );

                    $questionIds[] = $question->id;
                }

                // Full replace, not additive — a re-upload reflects exactly what's in the new
                // file, matching the "safe to fix a typo and re-upload" contract.
                $module->questions()->sync($questionIds);
            }

            if ($module->status !== 'ready' && $module->questions()->count() > 0) {
                $module->update(['status' => 'ready']);
            }

            return $module;
        });

        return ['errors' => [], 'module' => $module];
    }

    /**
     * @return array{0: array<string, mixed>, 1: string} [frontMatter, body]
     */
    private function parseFrontMatter(string $raw): array
    {
        if (!preg_match('/^---\s*\n(.*?)\n---\s*\n?(.*)$/s', $raw, $matches)) {
            return [[], $raw];
        }

        $frontMatter = Yaml::parse($matches[1]) ?? [];

        return [$frontMatter, $matches[2]];
    }
}
