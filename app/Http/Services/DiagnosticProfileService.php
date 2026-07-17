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

SELF-REPORTED SURVEY (this is a HYPOTHESIS the user holds about themselves, not diagnostic evidence. Its only permitted use is the self_report_check comparison below — never as an ingredient in summary, evidence[], primary_strength, primary_growth_area, growth_area_pattern, or likely_in_game_pattern.)
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
2. The self-reported survey is a hypothesis the user holds about themselves — not diagnostic evidence. Do NOT use it to write summary, evidence[], primary_strength, primary_growth_area, growth_area_pattern, or likely_in_game_pattern. Those fields must be derived ONLY from trait scores, axis scores, and concept scores, and must read as a complete, standalone diagnosis even if the survey were empty.
3. Treat trait scores and concept/axis scores as the only evidence for the fields listed in rule 2.
4. self_report_check is the ONLY field allowed to reference the survey. There: state the hypothesis the user reported (e.g. "You identified X as your primary weakness"), then say whether the behavioural evidence supports, contradicts, or is insufficient to judge it. A contradiction is valuable signal — state it plainly, don't soften it.
5. Every claim in evidence[] must trace to a specific trait, axis, or concept score — never to a survey answer.
6. The summary must start with "Your answers suggest" and be 3–5 sentences. Be specific and behavioural — describe what the evidence shows the user actually does, not what they said about themselves. Never be generic.
7. Use the game's own terminology only when it appears in GAME CONTEXT.
8. Return JSON ONLY — no markdown fences, no extra fields, no commentary.
9. In evidence[].signal use plain English Title Case — never raw field names or numbers. Prefix with a strength word: "Dominant", "Strong", "Moderate", or "Low" (e.g. "Strong Reactivity", "Moderate Control Orientation"). evidence[] is trait/axis/concept only per rule 5 — never a survey item. For evidence[].score include the raw numeric value as a short string (e.g. "+8", "+4").
10. TRAIT SCORES, AXIS MASTERY, and VALID CONCEPTS are three separate vocabularies — never interchange them. Trait names (e.g. patience, kill_instinct, pressure_orientation, reactivity, aggression) and axis names are NOT concepts, even when reworded into the Title Case style used for evidence[].signal in Rule 9 (e.g. "Kill Instinct", "Low Pressure Orientation"). When populating primary_strength.concepts and primary_growth_area.concepts, use ONLY exact names from VALID CONCEPTS below — never a trait or axis name in any casing. Do not invent concept names. If no VALID CONCEPTS name fits well, return fewer concepts or an empty array — do not guess or substitute a trait/axis name to fill the slot.
11. growth_area_pattern must describe a concrete manifestation of primary_growth_area specifically — how THIS growth area shows up in practice. Do not reuse or restate likely_in_game_pattern, which is general archetype flavor unrelated to the growth area.

---

VALID CONCEPTS FOR THIS SUBJECT
{$validConceptsBlock}

Reminder: the list above is the ONLY valid source for primary_strength.concepts / primary_growth_area.concepts. Trait and axis names from earlier in this prompt (TRAIT SCORES, AXIS MASTERY) must never appear there, in any casing.

---

OUTPUT SHAPE
{
  "profile_title": "Human-readable archetype label",
  "archetype_key": "snake_case key from AVAILABLE ARCHETYPES",
  "confidence_level": "low | medium | high",
  "summary": "Paragraph starting with 'Your answers suggest...' (3-5 sentences)",
  "evidence": [
    {
      "signal": "Plain English title e.g. 'Strong Reactivity' or 'Low Pressure Orientation'",
      "source": "Field name e.g. trait_scores.patience or axis_scores.Mechanics",
      "interpretation": "One sentence — what this signal means for this specific player",
      "score": "+8"
    }
  ],
  "self_report_check": {
    "alignment": "aligned | partially_aligned | conflicting | insufficient_data",
    "comment": "State what the user self-reported, then whether the behavioural evidence supports, contradicts, or can't yet confirm it. This is the ONLY field allowed to mention the self-reported survey."
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
  "growth_area_pattern": "One sentence describing how this specific growth area shows up in practice",
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
            'growth_area_pattern'    => $response['growth_area_pattern'] ?? '',
            'next_practice_goal'     => $response['next_practice_goal'] ?? '',

            // Backward-compat aliases so existing stored profiles keep rendering
            'player_type'            => $response['profile_title'] ?? ($response['player_type'] ?? 'Unclassified'),
            'narrative'              => $response['summary'] ?? ($response['narrative'] ?? ''),
            'top_traits'             => $response['top_traits'] ?? [],
            'growth_area'            => $response['primary_growth_area']['name'] ?? ($response['growth_area'] ?? ''),
            'next_module_suggestion' => $response['next_module_suggestion'] ?? '',
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
