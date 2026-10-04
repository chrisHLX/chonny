I have everything I need. Here's the complete audit — no code changes.

Per-Method Audit
Token cost shown per 1,000 tokens from TokenService::$pricing. "Credits deducted" is estimated from realistic prompt + output sizes for each method using the chars / 4 estimator the app uses.

Method	Route to API	CreditService called?	Model	Input $/1k tokens	Output $/1k tokens	Estimated credits/call	Issue
generateQuestions()	→ callOpenAi()	✅ Yes	gpt-4o-mini (mcq/tf/open) or gpt-4.1-mini (ordering/matching)	$0.00015 / $0.00040	$0.00060 / $0.00160	1 credit	None
generateExploreContent()	→ callOpenAi()	✅ Yes	gpt-4o-mini	$0.00015	$0.00060	1 credit	None
generateContentForQuestion()	→ callOpenAiString()	✅ Yes	gpt-4o-mini (hardcoded in helper)	$0.00015	$0.00060	1 credit	Model hardcoded — ignores $modelOverride
generateModuleContent()	→ callOpenAiString()	✅ Yes	gpt-4o-mini (hardcoded in helper)	$0.00015	$0.00060	1 credit	Model hardcoded in helper
generateTags()	→ callOpenAi()	⚠️ Partially	gpt-4.1-mini	$0.00040	$0.00160	1 credit	userID hardcoded as 2 — deducts from user 2, not the calling user
tagConcepts()	→ callOpenAi()	❌ No	gpt-4o-mini (default)	$0.00015	$0.00060	0	Missing $userID — call throws ArgumentCountError at runtime. Also has a hardcoded StarCraft 2 system prompt. Dead/broken.
createLandingPage()	→ callOpenAiHTML()	❌ No	gpt-4.1-nano	$0.00010	$0.00040	0	callOpenAiHTML() has no credit deduction logic, no userID param
generateLandingPage()	→ callOpenAiHTML()	❌ No	gpt-4.1-nano	$0.00010	$0.00040	0	Same as above — also has dd($prompt) midway so it never actually calls the API either
getKeywords()	→ direct Http:: call	❌ No	gpt-4o-mini	$0.00015	$0.00060	0	Bypasses both helper methods; no credit tracking at all
generateArtSpec()	→ callOpenAi()	❌ No	gpt-4o-mini	$0.00015	$0.00060	0	Missing $userID — throws ArgumentCountError. Unused.
generateModuleArtSpec()	→ callOpenAi()	❌ No	n/a	n/a	n/a	0	Missing $userID — throws ArgumentCountError.
followUpQuestions() (private)	→ direct Http:: call	❌ No	gpt-4.1-nano	$0.00010	$0.00040	0	Direct HTTP call bypasses all helpers. No credits.
generateIdeas()	→ callOpenAiString()	❌ No	n/a	n/a	n/a	0	Missing $userID + references undefined $userModuleList. Broken.
generateModule()	→ callOpenAi()	❌ No	n/a	n/a	n/a	0	Missing $userID — throws ArgumentCountError. Broken.
Credit Economics
Conversion rate

TokenService: $usdPerCredit = 0.01
→ 1 credit = $0.01 charged to user
→ 100 credits = $1.00
Real dollar cost per credit spent
All active production calls use cheap models and produce small token counts. The ceil() in convertToCredits() means any call costing less than $0.01 rounds up to 1 credit minimum — which is essentially every call except gpt-4o.

Scenario	Estimated real cost	Credits charged	User pays	Real margin
generateContentForQuestion (~400 in / 180 out, gpt-4o-mini)	~$0.000168	1	$0.010	98.3%
generateModuleContent (~1,400 in / 900 out, gpt-4o-mini)	~$0.00075	1	$0.010	92.5%
generateQuestions mcq/tf (~4,000 in / 2,000 out, gpt-4o-mini)	~$0.0018	1	$0.010	82%
generateQuestions ordering/matching (~4,000 in / 2,000 out, gpt-4.1-mini)	~$0.0048	1	$0.010	52%
generateQuestions full run (4 types: 2× gpt-4o-mini + 2× gpt-4.1-mini)	~$0.013	4	$0.040	67%
generateQuestions with gpt-4o (~4,000 in / 2,000 out)	~$0.030	3	$0.030	≈0% (breakeven)
generateQuestions gpt-4o full run (4 types)	~$0.120	12	$0.120	≈0% (breakeven)
Key observations
Structural risk with ceil(): Any call costing under $0.01 is rounded up to 1 credit. This creates huge margin on cheap/short calls and near-zero margin on gpt-4o. If a gpt-4o prompt runs longer than estimated (the app uses chars/4 which underestimates real tokens), you can go into loss — user pays 3 credits ($0.030) but the actual API cost is $0.035.

Untracked spend: getKeywords(), callOpenAiHTML() (used in landing page flows), and followUpQuestions() all make real API calls with zero credit deduction. These are currently subsidised entirely by you. Based on the nano/mini models involved, the individual costs are small but they accumulate untracked.

gpt-4o margin is too thin at current credit pricing. If you plan to expose that model selector to users, you either need to price credits higher, or build per-model minimum charges rather than relying solely on the token estimator.