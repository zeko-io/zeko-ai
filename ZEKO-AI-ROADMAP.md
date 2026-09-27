# Zeko AI — Agent Parity Roadmap

Status: planning doc (2026-09). Companion to `ZEKO-AI-TODO.md`. Last reviewed with P1
SSE streaming shipped (v0.5.1, zeko-ai pushed 60/60).

## Why this roadmap exists

"Agent parity" means: the **Zeko Local Engine** (the keyless community agent) and any
**BYO local model** should deliver what a cloud-API assistant can — grounded answers,
real actions, memory continuity and moderation — without forcing users onto paid APIs.
The strategy is a **layered stack**: deterministic retrieval stays the always-on core,
and increasingly capable pieces slot in above it as optional layers. Every layer must
work out of the box keyless on shared hosting and degrade cleanly.

```
                     ┌─ optional LLM layer (local endpoint / cloud API)
        orchestrate │    tool calls · grounded RAG · generation
   ─────────────────┼──────────────────────────────────────────────
                     │  deterministic agent core (no model needed)
        always-on    │    knowledge · corpus · entity cards · web search
                     │    intent/actions · facts + recap memory · moderation
```

## What already exists (v0.5.1 baseline)

| Capability | Heuristic offline agent | Cloud providers | Notes |
|---|---|---|---|
| Chat + multi-turn | ✓ (knowledge/corpus/entity/personal/web) | ✓ | follow-up resolution in agent |
| SSE streaming | ✓ buffered single-chunk | ✓ real tokens (OpenAI/Compat/Anthropic) | P1 shipped; agent streams one chunk |
| Recommendation/entity cards | ✓ | via agent path | `zeko_ai_entity_resolvers` |
| Per-user memory | ✓ facts + recap | — (not injected into cloud turns) | `Zeko_AI_Memory` |
| RAG (corpus/personal) | ✓ keyword/token/FULLTEXT | — | no embeddings yet |
| Web search | ✓ (DDG keyless + 4 keyed) | — | `Zeko_AI_Web_Search` |
| Tool/function calling | ✗ (deterministic intents only) | ✗ | biggest gap |
| Moderation | ✓ keyword jury | ✓ provider jury | thresholds + admin queue |
| Multi-provider fallback | ✓ `Zeko_AI_Provider_Facet` chain | ✓ | no budgets/caps yet |
| REST API (`zeko-ai/v1`) | ✓ | ✓ | search/chat/conversations/recs/knowledge/feedback/memory |
| Rate limiting | ✓ | ✓ | `Zeko_AI_Rate_Limiter` |

## Landscape snapshot (2026)

- **WP 7.0 ships an AI Client** (announced 2026-03-24) with pluggable connectors; one
  published connector exposes any **OpenAI-compatible local endpoint** (Ollama / LM
  Studio / llama.cpp / vLLM) to it. WP AI Client has **no embeddings API yet** — vector
  plugins call embedding providers directly.
- **Browser-side ML is now viable on WordPress** (Transformers.js/WebGPU, e.g. all-MiniLM
  ~23 MB cached in IndexedDB): embeddings can be computed in the editor's browser without
  a server, API key or model download server-side.
- **Local tool calling is production-grade**: Qwen3.5 4B scored **97.5%** on an 8-tool
  eval at ~3.4 GB; GLM-4.7-Flash and Nemotron Nano 4B ~95%. All serve the **OpenAI
  `tools`/`tool_calls` schema** via Ollama/llama.cpp — the exact shape our
  `Zeko_AI_OpenAI_Compat_Provider` already speaks. Multi-step chains still favor bigger
  or cloud models.
- **Native vector search in MySQL/MariaDB is emerging** (MariaDB 11.7 vector columns),
  but remains opt-in; the existing `zeko_ai_knowledge` table works everywhere.
- Implication: we can reach strong parity by (1) adding a generic BYO-local-endpoint
  provider, (2) implementing the OpenAI-style tool loop against it, and (3) optionally
  embedding through the same endpoints — **all reusing existing code paths, all optional.**

## Phased work plan

Priorities: P0/P1 = unblocks everything; all phases optional behind settings, all fail-soft.

### Phase 1 — BYO Local Endpoint provider (`local`) — HIGH
Open the full cloud experience to self-hosted models with zero new transport code.
- New `Zeko_AI_Local_Provider extends Zeko_AI_OpenAI_Compat_Provider` (`slug() => 'local'`):
  default base URL `http://127.0.0.1:11434/v1` (Ollama), editable per install
  (`local_base_url` / `local_model` / `local_key` optional Bearer for tunneled endpoints),
  `streaming` + `supports_streaming()` true → SSE already works.
- Add `local` to provider settings dropdown (external group) + factory chain; missing/blank
  endpoint (unreachable) → falls through the facet exactly like a missing key today.
- Admin "Local endpoint" health check on Provider Health (reachable/auth/model/latency),
  reusing the cURL `stream_request()` pattern or a light `wp_remote_get` probe.
- Filter seams: `zeko_ai_local_endpoint` / `zeko_ai_local_remote_request` (existing compat
  pattern). Tests stub `zeko_ai_local_remote_request` — no network, like the six compat tests.
- Out of scope here: model download/management (that's Ollama/llama.cpp's job).

### Phase 2 — Tool / function calling (provider + registry + loop) — HIGH
Turn the chat into an agent for every provider that can emit `tool_calls`.
- **Contract**: `Zeko_AI_Provider::supports_tools(): bool` (default false) +
  `with_tools( array $tools, array $opts ): array` optional param to `chat()`. Providers
  pass `$opts['tools']` through to the upstream body (OpenAI shape); native-tool providers
  (OpenAI / Anthropic / Compat / Local) opt in; Mock + agent keep the deterministic path.
- **Tool registry**: `Zeko_AI_Tools` with a filterable list (`zeko_ai_tools`), each entry =
  `{ name, description, parameters (JSON schema), call }`. Zeko tools v1 (all read-only):
  `zoeke_corpus_search`, `zoeke_entity_card`, `zoeke_knowledge`, `zoeke_member_profile`,
  `zoeke_memory`, `zoeke_wallet_balance`, `zoeke_recommend` — each maps onto methods that
  already exist (corpus search, resolvers, `Zeko_AI_Memory`, pay/wallet public API).
- **Agent loop** (`Zeko_AI_Tools::run`): request with tools → if `tool_calls`, execute each,
  append `role:tool` results, loop (max 4 steps, filterable) → return final text + a
  `tools_used[]` list for the raw payload / sources panel. Errors and bad JSON args are
  returned to the model as tool results (recover, don't crash).
- **Deterministic fallback**: heuristic agent stays orchestrator-free — when
  `supports_tools()` is false, `chat()` just keeps its current deterministic intent →
  action path. Result: `supports_tools()` does not regress offline behaviour.
- Wire `tools_used` + citations into the `raw` payload from both the facet and assistant;
  surface as the backlog's "Citations / sources panel" (front-end render of source chips).
- Tests: canned `tool_calls` responses through the compat request seam (loop, recovery,
  step limit, `tool_call_id` chaining), registry filter, offline agent unchanged.

### Phase 3 — Embedding RAG upgrade (optional, hybrid) — MEDIUM
Keep the keyless keyword engine as the default; add semantic retrieval when an embedder
is reachable (local endpoint from Phase 1, or cloud `text-embedding-3-small` etc.).
- **Contract**: `Zeko_AI_Provider::embed( array $texts ): array` (default throws/returns [],
  `supports_embeddings(): bool` false unless the configured endpoint is reachable) —
  OpenAI and Compat providers get a real implementation; agent returns [] unless an
  embedder is configured.
- **Storage**: new table `zeko_ai_embeddings` (content_hash PK, object_type,
  object_id, context/chunk, model, vector BLOB) + `Zeko_AI_Embeddings` indexer:
  chunk published posts/jobs/courses/businesses/QA (configurable, chunk ~500 chars),
  index **async via cron** and on-save; **skip** implementation entirely when no
  embedder configured (option `agent_embeddings_enabled` default off).
- **Retrieval**: hybrid — cosine (PHP over BLOB, batch top-N candidate filter via the
  existing corpus queries) **merged with** the existing keyword/token scoring so vector hits
  can't drop a good literal match; strong-hit threshold gates so weak vectors keep falling
  to corpus.
- **Personal RAG completion**: index the member's own module rows through the same chunker
  to close the backlog item "Personal RAG per module" for content the keyword path can't
  token-match yet.
- Browser-side embedder (Transformers.js, ~23 MB) as a no-server option is a **stretch**:
  admin-visible only, editor-triggered, falls back to endpoint embedding. Defer unless
  backend embedder proves too heavy for target hosts.
- Tests: hash dedupe/reindex, chunking determinism, hybrid merge ordering, disabled-by-default,
  no-network when off.

### Phase 4 — Memory depth (parity for cloud providers too) — MEDIUM
- **Summary memory**: nightly cron rolls finished conversations into a per-user
  `recap`/`interests` summary (rule/LLM-less: reuse fact extractor + keyword stats, so it
  stays keyless); store in `memory` with `source=summary`; fold into queries like today.
- **Context injection for cloud providers**: when provider is an LLM provider, `run_turn`
  prepends a compact system block from `Zeko_AI_Memory` (facts + recap + conversation
  title) so OpenAI/Anthropic/Local turns get the same personalization the agent has.
- **Explicit memory UI**: member-facing "What Zeko remembers about me" in the profile hub
  (already has memory admin/delete) — read + clear, expanding what `Zeko_AI_Profile` has.
- Tests: summary generation determinism, system-block injection only for LLM providers,
  memory-UI render guard, filters unchanged.

### Phase 5 — Moderation pipeline hardening — MEDIUM
- **Jury stack**: heuristic keyword jury (baseline, always) → optional LLM jury when
  provider `supports_moderation()` and configured (exists today for LLM providers) → human
  queue (exists). Add a **confidence/score column** consistently + reasons from every layer.
- **Local-jury mode**: Phase 1's `local` provider drives `moderate()` via the compat
  JSON-prompt classifier — matches existing OpenAI-compat behaviour; no new code path.
- **Batch re-scan**: admin "re-moderate last N" per source with async wp-cron batches
  (today's on-demand review is synchronous; add `wp_async_task`-style buffering if batches
  exceed ~25 items).
- Tests: jury ordering + skip semantics, local-jury verdict from stubbed response,
  batch splitter.

### Phase 6 — Budgets, caps & local-first chains — MEDIUM
- **Provider budgets** (backlog): optional hard monthly cap per provider/feature
  (`provider_budgets`) + alert + **auto-degrade** to Mock/agent (facet short-circuits before
  calling an exhausted provider). Uses existing `usage` table aggregation.
- **Chain presets**: one-click "Local-first", "Ecosystem (no keys)", "Cost-optimised
  cloud" chain builders on the settings page; still filterable.
- Tests: cap counting against recorded usage, degrade ordering, preset chain ordering/dedupe.

### Phase 7 — WordPress.org submission readiness — LOW effort, gated on registration
Registration is still pending; nothing blocks the phases above from landing first.
When approved: final dot-org pass (readme.txt/changelog, i18n POT freshness — already
regenerated, admin keeps provider-agnostic wording, remove test hooks), then submit.

## Priorities & sequencing

| Phase | Work | Priority | Order | Unlocks |
|---|---|---|---|---|
| 1 | BYO Local Endpoint provider | High | 1 | Real local LLM + streaming + moderation + embeddings source |
| 2 | Tool calling (contract + registry + loop) | High | 2 (can pair with 1) | True agent behaviour on any LLM provider |
| 3 | Optional embeddings RAG | Medium | 3 | Semantic grounding, personal RAG completion |
| 4 | Memory depth + cloud context | Medium | 2–4 | Personalization parity for cloud/local LLMs |
| 5 | Moderation jury hardening | Medium | 2–5 | Trust layer for LLM-generated content |
| 6 | Budgets + chain presets | Medium | 3–6 | Cost safety, one-click deployment |
| 7 | WP.org readiness | Low | Last | Submission |

Shortest useful slice (recommended next batch): **Phase 1 + Phase 2** — a self-hosted
model becomes a first-class streaming, tool-using agent member, while the heuristic
engine and every existing test stay untouched. Phase 3 can follow once embeddings are
confirmed on real hosting constraints.

## Recurring patterns (apply to every phase)

- **Fail-soft**: unusable credentials/endpoint → facet falls through; never a hard error on
  the chat UI. Matches the existing missing-key → Mock/agent behaviour.
- **Optional**: every new capability defaults off or auto-detects; the keyless out-of-the-box
  experience never regresses.
- **Test seam**: all outbound traffic routes through the existing per-provider request
  filter (`zeko_ai_{slug}_remote_request`, `zeko_ai_stream_raw`), so functional harness +
  PHPUnit stay network-free and deterministic.
- **Versioning**: bump `ZEKO_AI_VERSION` + `ZEKO_AI_DB_VERSION` together with any schema
  change, and use a `SEED_VERSION`-style gate for one-time reseeds.
- **Gates**: `php -l`, `phpcs -s --standard=WordPress-Extra`, PHPUnit suite, and the live
  `zeko-ai-verify.php` harness after each phase; sync via `sync-zeko.ps1`.

## Decision points (owner: Zeko)

1. **Embedding strategy** (Phase 3): backend-only endpoints (Ollama/LM Studio or cloud) vs.
   adding the browser-side Transformers.js indexer as a stretch goal. Backend-only is
   simpler and matches target hosts; browser keeps everything keyless.
2. **`local` provider on WordPress.org**: confirm shipping an `http://127.0.0.1` default
   base URL is acceptable to reviewers (it is a well-understood BYO connector pattern;
   the plugin makes no call until the endpoint is saved — mirror the published local-model
   connector's behaviour).
3. **Tool surface v1**: read-only Zeko tools only (search/entity/knowledge/profile/memory/
   wallet/recommend). Should any **write** tools (e.g. "post a QA question", "book a
   session") be added? Recommend no in v1; keep the write path for the dedicated module
   UIs and revisit after real feedback.
4. **Version target**: bundle Phases 1–2 as v0.6.0 (new provider + tooling), Phases 3–6 as
   v0.7.0, or align releases to the WP.org approval timeline once registration lands.