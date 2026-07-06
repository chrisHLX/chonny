<?php

namespace App\Http\Services;

use App\Models\Concept;
use App\Models\Module;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class DiagnosticProfileService
{
    protected AiService $aiService;

    public function __construct(AiService $aiService)
    {
        $this->aiService = $aiService;
    }

    public function generateProfile(
        array $traitScores,
        array $axisScores,
        array $conceptScores,
        int $userId,
        bool $isGuest = false,
        array $surveyAnswers = [],
        ?Module $module = null
    ): array {
        // Build concept map for validation grounding
        $subject = $module?->subject ?? $module?->load('subject')->subject;
        $conceptMap = $subject
            ? Concept::where('subject_id', $subject->id)->pluck('id', 'name')
            : collect();

        // Pass valid concept names to the prompt builder
        $validConceptNames = $conceptMap->keys()->implode(', ');
        $prompt = $this->buildPrompt($traitScores, $axisScores, $conceptScores, $surveyAnswers, $module, $validConceptNames);

        // Guests get a free direct Gemini call — no credits deducted.
        // The diagnostic is the conversion moment for guests so we absorb the cost.
        $response = $isGuest
            ? $this->generateForGuest($prompt)
            : $this->aiService->sendPromptToAi($prompt, 'gpt-4o-mini', $userId, 'diagnostic_profile_generation');

        // Ground concepts in the raw response before normalising
        $response = $this->groundConcepts($response, $conceptMap);

        return $this->normaliseResponse($response);
    }

    private function buildPrompt(
        array $traitScores,
        array $axisScores,
        array $conceptScores,
        array $surveyAnswers,
        ?Module $module,
        ?string $validConceptNames = null
    ): string {
        $gameContext      = $this->buildGameContext($module);
        $archetypeList    = $this->buildArchetypeList($module);
        $availableModules = $this->buildAvailableModules($module);

        $surveyBlock = collect($surveyAnswers)
            ->map(fn($ans, $key) => "  {$key}: {$ans['text']}")
            ->implode("\n") ?: '  (none collected)';

        arsort($traitScores);
        $dominant   = collect($traitScores)->filter(fn($s) => $s > 0);
        $suppressed = collect($traitScores)->filter(fn($s) => $s <= 0);

        if ($dominant->isEmpty()) {
            $traitBlock = '  (no trait signals recorded)';
        } else {
            $traitBlock = $dominant->map(fn($s, $k) => "  {$k}: +{$s}")->implode("\n");
            if ($suppressed->isNotEmpty()) {
                $traitBlock .= "\n  --- suppressed / absent ---\n";
                $traitBlock .= $suppressed->map(fn($s, $k) => "  {$k}: {$s}")->implode("\n");
            }
        }

        $axisBlock = collect($axisScores)
            ->map(fn($score, $name) => "  {$name}: {$score}%")
            ->implode("\n") ?: '  (no axis mastery data — likely a new user)';

        $conceptBlock = collect($conceptScores)
            ->map(fn($score, $name) => "  {$name}: {$score}%")
            ->implode("\n") ?: '  (no concept mastery data — likely a new user)';

        $validConceptsBlock = !empty($validConceptNames)
            ? "  {$validConceptNames}"
            : '  (no concepts configured for this subject yet)';

        return <<<PROMPT
You are a diagnostic profile generator for a competitive learning platform.

The platform supports multiple disciplines. Use ONLY the information provided below — do not assume any game, sport, or domain-specific details unless they appear explicitly in GAME CONTEXT.

---

GAME CONTEXT
{$gameContext}

---

AVAILABLE ARCHETYPES
Choose the single best-fitting archetype from this list. Return its key in archetype_key.
{$archetypeList}

---

AVAILABLE MODULES
Choose the single best recommended module from this list. Use its exact id and title.
{$availableModules}

---

SELF-REPORTED SURVEY (treat as context, not objective truth)
{$surveyBlock}

---

TRAIT SCORES (accumulated signal from diagnostic answers — higher = more strongly expressed, negative = opposite tendency)
{$traitBlock}

---

AXIS MASTERY (% mastery per skill dimension — may be empty for new users)
{$axisBlock}

---

TOP STRUGGLED CONCEPTS (lowest mastery first — may be empty for new users)
{$conceptBlock}

---

RULES
1. Do not mention any rating, role, class, champion, rank, or mode unless it appears in the provided input.
2. Treat survey answers as self-reported context — they may not match the diagnostic evidence.
3. Treat trait scores and concept/axis scores as stronger evidence than survey answers.
4. If survey answers and diagnostic evidence conflict, surface that tension — it is valuable signal.
5. Every claim in evidence[] must trace to a specific input field (trait score, axis score, concept score, or survey answer).
6. The summary must start with "Your answers suggest" and be 3–5 sentences. Be specific — reference their survey context where relevant. Never be generic.
7. The recommended_module must be chosen from AVAILABLE MODULES only. If none fits well, choose the closest and note the uncertainty in the reason field.
8. Use the game's own terminology only when it appears in GAME CONTEXT or AVAILABLE MODULES.
9. Return JSON ONLY — no markdown fences, no extra fields, no commentary.
10. In evidence[].signal use plain English Title Case — never raw field names or numbers. Prefix with a strength word: "Dominant", "Strong", "Moderate", or "Low" for trait/axis evidence (e.g. "Strong Reactivity", "Moderate Control Orientation"). For survey evidence use "Self-reported: Label" (e.g. "Self-reported: Awareness"). For evidence[].score include the raw numeric value as a short string (e.g. "+8", "+4") for trait/axis evidence, or null for survey evidence.
11. When populating primary_strength.concepts and primary_growth_area.concepts, use ONLY exact names from VALID CONCEPTS below. Do not invent concept names. If none fit well, return fewer concepts or an empty array — do not guess.

---

VALID CONCEPTS FOR THIS SUBJECT
{$validConceptsBlock}

---

OUTPUT SHAPE
{
  "profile_title": "Human-readable archetype label",
  "archetype_key": "snake_case key from AVAILABLE ARCHETYPES",
  "confidence_level": "low | medium | high",
  "summary": "Paragraph starting with 'Your answers suggest...' (3-5 sentences)",
  "evidence": [
    {
      "signal": "Plain English title e.g. 'Strong Reactivity' or 'Self-reported: Awareness'",
      "source": "Field name e.g. trait_scores.patience or axis_scores.Mechanics",
      "interpretation": "One sentence — what this signal means for this specific player",
      "score": "+8"
    }
  ],
  "self_report_check": {
    "alignment": "aligned | partially_aligned | conflicting | insufficient_data",
    "comment": "One sentence comparing self-report to diagnostic evidence"
  },
  "likely_in_game_pattern": "One sentence describing how this probably shows up during actual play",
  "primary_strength": {
    "name": "Strength label",
    "concepts": ["concept1", "concept2"]
  },
  "primary_growth_area": {
    "name": "Growth area label",
    "concepts": ["concept1", "concept2"]
  },
  "recommended_module": {
    "module_id": 0,
    "title": "Exact module title from AVAILABLE MODULES",
    "reason": "One sentence explaining why this module follows from the profile"
  },
  "next_practice_goal": "One concrete, actionable thing to try in their next session"
}
PROMPT;
    }

    private function buildGameContext(?Module $module): string
    {
        if (!$module) {
            return '  (no game context available)';
        }

        $subject  = $module->subject ?? $module->load('subject')->subject;
        $category = $subject?->category ?? $subject?->load('category')->category;

        $lines = [];
        if ($category) {
            $lines[] = "  category: {$category->name}";
        }
        if ($subject) {
            $lines[] = "  subject: {$subject->name}";
        }
        $lines[] = "  module: {$module->name}";

        return implode("\n", $lines);
    }

    private function buildArchetypeList(?Module $module): string
    {
        if (!$module) {
            return '  (no archetypes configured)';
        }

        $subject  = $module->subject ?? $module->load('subject')->subject;
        $category = $subject?->category ?? $subject?->load('category')->category;

        if (!$category) {
            return '  (no archetypes configured)';
        }

        $archetypes = $category->archetypes()->get();

        if ($archetypes->isEmpty()) {
            return '  (no archetypes configured for this category)';
        }

        return $archetypes->map(fn($a) => "  {$a->key}: {$a->label} — {$a->description}")->implode("\n");
    }

    private function buildAvailableModules(?Module $module): string
    {
        if (!$module) {
            return '  (no module list available)';
        }

        $subject = $module->subject ?? $module->load('subject')->subject;
        if (!$subject) {
            return '  (no module list available)';
        }

        $modules = $subject->modules()
            ->where('published', true)
            ->where('status', 'ready')
            ->where('type', '!=', 'diagnostic')
            ->get(['id', 'name', 'description']);

        if ($modules->isEmpty()) {
            return '  (no published modules available in this subject yet)';
        }

        return $modules->map(fn($m) => "  id={$m->id}: {$m->name}" . ($m->description ? " — {$m->description}" : ''))->implode("\n");
    }

    private function normaliseResponse(array $response): array
    {
        return [
            // New structured fields
            'profile_title'          => $response['profile_title'] ?? ($response['player_type'] ?? 'Unclassified'),
            'archetype_key'          => $response['archetype_key'] ?? null,
            'confidence_level'       => $response['confidence_level'] ?? 'medium',
            'summary'                => $response['summary'] ?? ($response['narrative'] ?? ''),
            'evidence'               => $response['evidence'] ?? [],
            'self_report_check'      => $response['self_report_check'] ?? null,
            'likely_in_game_pattern' => $response['likely_in_game_pattern'] ?? '',
            'primary_strength'       => $response['primary_strength'] ?? null,
            'primary_growth_area'    => $response['primary_growth_area'] ?? null,
            'recommended_module'     => $response['recommended_module'] ?? null,
            'next_practice_goal'     => $response['next_practice_goal'] ?? '',

            // Backward-compat aliases so existing stored profiles keep rendering
            'player_type'            => $response['profile_title'] ?? ($response['player_type'] ?? 'Unclassified'),
            'narrative'              => $response['summary'] ?? ($response['narrative'] ?? ''),
            'top_traits'             => $response['top_traits'] ?? [],
            'growth_area'            => $response['primary_growth_area']['name'] ?? ($response['growth_area'] ?? ''),
            'next_module_suggestion' => $response['recommended_module']['title'] ?? ($response['next_module_suggestion'] ?? ''),
        ];
    }

    private function groundConcepts(array $response, $conceptMap): array
    {
        // Filter primary_strength.concepts to valid concept names only
        if (
            isset($response['primary_strength'])
            && is_array($response['primary_strength'])
            && isset($response['primary_strength']['concepts'])
            && is_array($response['primary_strength']['concepts'])
        ) {
            $original = $response['primary_strength']['concepts'];
            $filtered = array_values(array_filter(
                $original,
                fn($name) => $conceptMap->has($name)
            ));

            if (count($filtered) !== count($original)) {
                Log::warning('DiagnosticProfileService: Filtered invalid concept names from primary_strength', [
                    'original' => $original,
                    'filtered' => $filtered,
                ]);
            }

            $response['primary_strength']['concepts'] = $filtered;
        }

        // Filter primary_growth_area.concepts to valid concept names only
        if (
            isset($response['primary_growth_area'])
            && is_array($response['primary_growth_area'])
            && isset($response['primary_growth_area']['concepts'])
            && is_array($response['primary_growth_area']['concepts'])
        ) {
            $original = $response['primary_growth_area']['concepts'];
            $filtered = array_values(array_filter(
                $original,
                fn($name) => $conceptMap->has($name)
            ));

            if (count($filtered) !== count($original)) {
                Log::warning('DiagnosticProfileService: Filtered invalid concept names from primary_growth_area', [
                    'original' => $original,
                    'filtered' => $filtered,
                ]);
            }

            $response['primary_growth_area']['concepts'] = $filtered;
        }

        return $response;
    }

    private function generateForGuest(string $prompt): array
    {
        $key = config('services.gemini.key');
        if (empty($key)) {
            Log::error('Guest diagnostic profile generation skipped: GEMINI_API_KEY is not configured.');
            return [];
        }

        $modelId  = 'gemini-2.5-flash-lite';
        $endpoint = "https://generativelanguage.googleapis.com/v1beta/models/{$modelId}:generateContent";

        $payload  = ['contents' => [['parts' => [['text' => $prompt]]]]];
        $response = null;

        // Retry once on 503 (Gemini demand spikes are transient)
        foreach ([0, 3] as $delaySecs) {
            if ($delaySecs > 0) sleep($delaySecs);
            try {
                $response = Http::withHeaders(['Content-Type' => 'application/json'])
                    ->timeout(30)
                    ->post("{$endpoint}?key={$key}", $payload);
            } catch (\Throwable $e) {
                Log::error('Guest diagnostic profile Gemini call failed', ['error' => $e->getMessage()]);
                return [];
            }
            if ($response->status() !== 503) break;
            Log::warning('Guest diagnostic profile Gemini 503 — retrying', ['attempt' => $delaySecs === 0 ? 1 : 2]);
        }

        if ($response->failed()) {
            Log::error('Guest diagnostic profile Gemini API error', [
                'status' => $response->status(),
                'body'   => $response->body(),
            ]);
            return [];
        }

        $content = $response->json('candidates.0.content.parts.0.text', '');
        $content = trim(preg_replace('/^```json|```$/im', '', trim($content)));

        $decoded = json_decode($content, true);

        return is_array($decoded) ? $decoded : [];
    }
}
