=== Zeko AI ===
Contributors: zeko
Tags: ai, chatbot, assistant, search, moderation
Requires at least: 5.8
Tested up to: 7.1.2
Requires PHP: 7.4
Stable tag: 0.5.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

AI layer for the Zeko ecosystem: assistant/chatbot, unified search, recommendations, content moderation, usage analytics, and a personal profile hub. Provider-agnostic.

== Description ==

Zeko AI brings an AI layer to the Zeko ecosystem. It provides an assistant/chatbot with memory, a unified search across every Zeko module, personalized recommendations, content moderation, and usage analytics — all through a provider-agnostic client that supports the built-in Zeko Local Engine, Mock, OpenAI, Anthropic, OpenRouter, Gemini, Groq, and DeepSeek.

= Features =

* **Assistant / Chatbot** — Context-aware conversation with the community agent and optional personal memory and recap
* **Personal RAG** — Retrieval over the user's own activity, courses, applications, and knowledge base
* **Unified Search** — One search endpoint that discovers jobs, QA, courses, freelance projects, mentors, love profiles, rewards, and shop products (`zeko_ai_search_sources`)
* **Recommendations** — Personalized content suggestions per module (`zeko_ai_recommendations`)
* **Content Moderation** — Automated moderation for QA answers, love photo reviews, and shop/product content with configurable thresholds
* **Profile Hub** — `[zeko_ai_profile]` renders a unified profile with checklists, activity, rewards, and module cards (`zeko_ai_profile_modules`)
* **Usage Analytics** — Per-user, per-provider usage and estimated cost tracking
* **Radar Digest** — Scheduled weekly digest of relevant ecosystem activity for each user
* **Admin Tools** — Provider health, teach/gaps, memory browser, and provider log
* **Provider Agnostic** — Zeko Local Engine (keyless, default) / Mock / OpenAI / Anthropic / OpenRouter / Gemini / Groq / DeepSeek, with automatic failover down the chain

= Shortcodes =

* `[zeko_ai_assistant]` — Chat with the community agent
* `[zeko_ai_search]` — Unified ecosystem search
* `[zeko_ai_recommendations]` — Personalized recommendations
* `[zeko_ai_writer]` — AI content generation
* `[zeko_ai_profile]` — Unified user profile hub
* `[zeko_public_profile]` — Public profile view

== Installation ==

1. Upload the `zeko-ai` folder to `/wp-content/plugins/`
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Optionally switch provider under Zeko AI > Settings (out of the box the plugin runs its keyless, offline Zeko Local Engine)
4. Create pages and add the shortcodes above

== External Services ==

Zeko AI is provider-agnostic. **No external service is contacted by default**:
with no API keys configured the plugin runs entirely on the built-in Zeko
Local Engine (keyless, offline). When you add a provider key, data is sent
only when a feature you use actually calls that provider.

* **AI model providers (OpenAI, Anthropic, OpenRouter, Gemini, Groq, DeepSeek)**
  — What is sent: your provider API key and the content of the request
  (assistant/chatbot prompts, your conversation history, and — where you have
  enabled them — your personal memory/recap context and personal-RAG context
  from your own activity). What is received: generated text output. When: when a
  user interacts with the assistant, search, writer, or moderation feature and a
  provider key is set for the configured provider. Why: to generate responses.
  Retention: local conversation and memory storage is described in the
  "Does the assistant remember the conversation?" FAQ; provider-side handling of
  the content you send is governed by each provider's privacy policy. The
  configured provider is always selectable per-site (Zeko AI > Settings).
* **Web search providers (keyless DuckDuckGo, or Google/Bing/Brave/Serper with
  a key)** — What is sent: your search query only, when the administrator
  enables web search and a user request triggers it. When: a "from the web"
  section is added to answers/site search when turned on. Retention: search
  results are cached briefly and never stored permanently; provider policies
  govern the request logs.
* **Wikipedia world knowledge (opt-in)** — When the agent's "world knowledge"
  setting is enabled, an extracted page-title guess from the question is sent
  to `https://en.wikipedia.org/api/rest_v1/page/summary/{title}` (and, on a
  miss, `en.wikipedia.org/w/api.php` opensearch) to ground factual answers.
  What is sent: the title string only, no personal data. When: only on
  enabled, factual agent queries. Retention: cached in a transient for 5
  minutes; Wikipedia's own request logs apply.
* **Zeko Cloud LLM (hosted, license-gated)** — If you choose the built-in
  "cloud" provider, prompts are posted to Zeko's license server
  (`https://ozconsultz.com/` by default, overridable via `ZEKO_LICENSE_SERVER`)
  together with your license key, site URL, and per-site token, and billed
  against your license credits. What is sent: the prompt/conversation content,
  license key, home URL, token, and model. When: only when the cloud provider
  is selected and a key is configured. Retention: governed by your license
  agreement with the Zeko license server operator.

All provider endpoints are HTTPS and filterable (`zeko_ai_*_endpoint`) so the
request destination is always visible and auditable. Endpoint/payload detail
with file:line citations is in `REMOTE-SERVICES.txt` in this plugin folder.

== Third-party resources ==

Zeko AI bundles no third-party runtime assets — all CSS, JS, icons (inline
SVG), and images (the screenshot only) are original Zeko work under
GPL-2.0-or-later. Its interactions with external services are outbound API
calls only, documented in `REMOTE-SERVICES.txt`.

== Frequently Asked Questions ==

= Do I need an AI provider API key? =

No. Without keys the plugin runs its own offline Zeko Local Engine, and a deterministic Mock provider is included so every flow can be tested. Add an OpenAI, Anthropic, or other provider key under Zeko AI > Settings to enable hosted generation.

= Which modules does search cover? =

Search is extensible via `zeko_ai_search_sources`. Out of the box it discovers jobs, QA, learn, freelance, mentor, love, rewards, and shop content.

= Does the assistant remember the conversation? =

Yes. Each conversation is persisted, and users can opt into personal memory so future sessions recall prior context.

== Dependencies ==

Zeko AI runs standalone (Mock provider needs no plugins and no keys). Zeko Core (when active) provides shared helpers and page lookups; content moderation and search read module content only from the modules that are installed.

== Privacy ==

Data stored includes conversation transcripts, assistant memory (opt-in), personal-RAG context drawn from the user's own activity, search results cache, usage analytics (per-user, per-provider), and moderation records. AI requests send the content and any enabled personal memory/context to the configured provider — see External Services. Moderation thresholds are configurable in Zeko AI > Settings.

== Uninstall ==

Deleting the plugin drops all Zeko AI tables, clears its crons (maintenance, radar digest), removes the pages it created, and deletes its options.

== Troubleshooting ==

* No answer? Out of the box the offline Zeko Local Engine answers; check the provider under Zeko AI > Settings, and add a provider key for hosted generation.
* Provider errors? Check the provider log and health screen in the admin.
* Search misses content? Search uses `zeko_ai_search_sources`; confirm the target module is active and indexed.

== Screenshots ==

1. AI dashboard - cloud composer with personal memory sources


== Changelog ==

= 0.5.1 =
* Greetings fixed: "Hello" / "hey there" / "how are you?" are answered as small talk instead of being treated as a search term, so the assistant no longer answers a greeting with unrelated listings and outbound web-search results; the reply is personalized with the member's first name and never reaches the corpus or the web
* The offline Zeko Local Engine is now the default provider on a fresh install (Mock stays available as a deterministic test/offline stub, and the failover chain is unchanged); the settings defaults have a single source of truth so the runtime default can no longer drift from the documented one
* Community agent keeps full command of every module: the knowledge paraphrase recall now always runs a strict token-AND net (>= 2 meaningful words), so a stale/missing InnoDB FULLTEXT index can no longer silently wipe "learned answer" retrieval
* Corpus paraphrase ranking matches MySQL's accent-insensitive behaviour (`Zeko_AI_Search::fold` folds Latin diacritics: "cafe" surfaces a business named "Café"), and an item whose title carries at least half of a query's distinctive terms (e.g. "Zeko Central Café & Bistro" for "find me a cafe or restaurant") beats loose rows that only mention the words
* Business Directory agent intent broadened (barber, plumber, hotel, clinic, dentist, mechanic, spa, bakery, near me, opening hours, booking/appointment, …), with the business fallback message listing concrete service categories
* Web-search answers now render clickable `[url](url)` source links in the chat UI and expose the raw results via `raw['web_results']`
* Community-agent seed knowledge grown to 168 FAQ rows (version-gated reseed): business listing management, business-account on-ramp, services and bookings, shop cancellations, job re-open/delete, quiz retakes, freelance cancellations/disputes, mentoring, dating privacy, wallets/payouts, rewards tiers, scam protection and more
* Direct "how do I create a business account" answer added to the seeds; business intent fallback now also links the real Add-your-business form (`/add-business/`)
* Intent detection broadened for most modules (salary/applications, tutorials/study, forums, pharmacies/bistros/open-now, portfolios/invoices, career advice, deposits/refunds/payouts, achievements/cashback, flagging) and made fully overridable via the `zeko_ai_agent_intents` filter
* Auto-learning hardened: bare "good"/"nice"/"help" no longer counts as affirmation, greetings are never learned, one-word questions are skipped, and logged-in users are capped at a few learned rows per day (`zeko_ai_agent_auto_learn_daily_cap`) so junk never floods the knowledge base
* Tests: 331 tests / 1042 assertions — all green (the suite now starts from the plugin defaults instead of the host site's saved settings, so a saved API key or an enabled outbound feature on a dev machine can no longer fail unrelated tests)

= 0.5.0 =
* Profile hub, personal RAG, recap memory, provider health, memory browser, radar digest
* Provider-agnostic client (Mock / OpenAI / Anthropic / OpenRouter / Gemini / Groq / DeepSeek)
* Unified search + recommendations across all modules
* Content moderation with configurable thresholds
* Usage analytics with estimated cost

== Upgrade Notice ==

= 0.5.0 =
Initial release of Zeko AI.
