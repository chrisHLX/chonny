Research Feature — Complete Reference
Purpose
Research is the factual foundation for all AI-generated content in Chonny. Before GPT-4 writes module pages or questions, Gemini fetches current, source-specific information on the topic and hands it to GPT as a grounding block. Without research, GPT falls back to its training knowledge — which may be outdated or wrong for patch-specific games content. With research, GPT is explicitly instructed to use only what Gemini returned and to not generalise.

The Two Entry Points
1. Module Edit Panel (ModuleController::research())
A creator triggers this manually from the edit view. They can optionally provide a custom research prompt, a source URL, and context attachments (existing research or module pages). The result is shown in the panel and can be appended to the module content editor. The panel auto-reloads after 2 seconds to persist the new SubjectContent row.

2. Explore Flow (ExploreModuleJob::handle())
When a user explores a topic from the modules index, research runs automatically as the first step of the async job. No human prompt is passed — the topic is derived from the user's intent + subject name. The research result is passed directly into generateExploreContent() in the same job without any human review.

APIs Used and Why
Step	API	Model	Why
Research / web fetch	Gemini	gemini-2.5-flash-lite	Has native Google Search grounding and url_context tool — can fetch live web pages and YouTube transcripts
Module content generation	OpenAI	gpt-4o-mini	Strong instruction-following for structured JSON output (title + pages)
Question generation — MCQ / true_false / open	OpenAI	gpt-4o-mini	Cost-efficient for simpler question types
Question generation — ordering / matching_pairs	OpenAI	gpt-4.1-mini	Complex structured output needs the stronger model; gpt-4o-mini is silently upgraded if passed
How Research Feeds GPT-4 Question Generation — The Chain

ResearchService::fetchLatestMaterial()
    → returns ['summary' => '...', 'sources' => [...]]
    → writes SubjectContent row (content = summary)

                    ↓ (explore flow)
AiService::generateExploreContent($intent, $subject, $proficiency, ..., $researchContext)
    → injects research into prompt as:
      "LATEST RESEARCH — YOU MUST USE THIS AS YOUR ONLY FACTUAL SOURCE: ..."
    → GPT writes 2–3 ModulePages (300–500 words each, patch-specific terminology)
    → pages saved to module_pages table

                    ↓
GenerateQuestions job (dispatched per question type)
    → reads module_pages content as the knowledge base
    → AiService::generateQuestions() receives that content string
    → questions must be derived from that content, tagged to subject concepts
    → questions saved with skill_type, difficulty, concept links
In the edit flow, research doesn't automatically chain — the creator pastes it into the content editor manually, then triggers question generation separately. In the explore flow, the chain is fully automated inside ExploreModuleJob.

The research summary is the single source of truth GPT sees. The prompt in generateExploreContent() is explicit: "Do NOT use prior training knowledge about this topic. Every specific ability name, talent name, cooldown value, or strategic detail you include MUST appear in the research below."

Source URL Behaviour
fetchLatestMaterial() accepts an optional $sourceUrl (param 9) that changes what Gemini does:

URL type	Gemini tools enabled	What happens
None	google_search only	Gemini searches freely
Non-YouTube URL	url_context + google_search	Gemini fetches the page, treats it as authoritative source
YouTube + transcript mode	google_search only	Transcript extracted via mrmysql/youtube-transcript, appended to prompt as YOUTUBE TRANSCRIPT: block; 30s timeout
YouTube + full video mode	google_search only	YouTube URL sent as file_data multimodal part; Gemini analyses on-screen action; 90s timeout
YouTube transcript fails	google_search only	Falls back to full video file_data silently, logs a warning
What Gets Stored
subject_content table (one row per module — updateOrCreate on module_id):

content — Gemini's summary text (this is what GPT reads)
source_urls — JSON: { primary: "url or null", discovered: [{uri, title}, ...] }
ai_request_id — links to the cost log row
title — auto-generated: "Research: {topic} — {date}"
ai_requests table (one row per Gemini call):

prompt — the full prompt sent to Gemini, including any injected transcript (transcripts live here only)
response — Gemini's raw summary text
purpose — "research"
metadata — model, input_tokens, output_tokens, cost_usd, credits_charged, sources count, duration_ms
The transcript itself is not stored in subject_content — only in ai_requests.prompt as a logging side effect.

Core Files
File	Role
app/Http/Services/ResearchService.php	Entire Gemini integration — builds prompt, calls API, writes both records, deducts credits
app/Http/Controllers/ModuleController.php → research()	Edit panel handler — extracts request params, calls ResearchService, returns JSON to Alpine
app/Http/Controllers/ModuleController.php → explore()	Explore handler — validates form, creates module + pipeline, dispatches ExploreModuleJob
app/Jobs/ExploreModuleJob.php	Async job — calls ResearchService, passes summary to generateExploreContent, chains to GenerateQuestions
app/Http/Services/AiService.php → generateExploreContent()	Consumes research summary as a mandatory factual block in the GPT prompt
app/Http/Services/AiService.php → generateQuestions()	Reads ModulePage content (GPT-written from research) to generate questions
app/Models/SubjectContent.php	Eloquent model for subject_content table — cast source_urls as array
resources/views/components/modules/research-panel.blade.php	Edit panel UI — Alpine component with URL input, YouTube toggle, result display
resources/views/livewire/modules/index.blade.php	Explore form — includes source URL + YouTube mode toggle (Alpine-wrapped hidden input)
Token Tracking and Credits
TokenService estimates tokens as strlen(text) / 4 (character-based approximation, not a real tokeniser). For gemini-2.5-flash-lite the pricing is $0.10/M input and $0.40/M output. Credits are charged at 1 credit = $0.01 USD, always rounded up with ceil().

ResearchService calls TokenService::calculateCreditCost() after the Gemini response and then calls CreditService::spendAiCredits(). This is logged in ai_requests.metadata with cost_usd, input_usd, output_usd, and credits_charged. The Admin\ApiUsage Livewire component reads this table to build the spend dashboard — the purpose column ("research") is the label shown in the purpose breakdown.

Important: token counting is based on $promptText length only. In YouTube full-video mode the video is sent as a file_data part, not text — so the token estimate will significantly undercount the true cost for those requests.