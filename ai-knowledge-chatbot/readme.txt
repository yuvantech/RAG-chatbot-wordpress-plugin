=== AI Knowledge Chatbot ===
Contributors: yuvantech
Tags: chatbot, ai, knowledge base, support, openai
Requires at least: 6.0
Tested up to: 6.6
Requires PHP: 8.2
Stable tag: 0.6.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

An AI chatbot that answers visitor questions strictly from your approved WordPress knowledge base — never from general AI knowledge.

== Description ==

AI Knowledge Chatbot indexes the content you choose — posts, pages, custom post types, WooCommerce products, and uploaded PDF/DOCX/TXT/CSV files — into a vector knowledge base, and answers visitor questions using only that content. If an answer isn't in the knowledge base, the chatbot says so instead of guessing.

Supports OpenAI, Google Gemini, Anthropic Claude, Azure OpenAI, and OpenRouter as chat providers, with an independent choice of embedding provider (OpenAI, Gemini, or local).

== Changelog ==

= 0.6.0 =
* Chat logging & Analytics admin screen: every answered question is logged (question, answer, whether it was answered, provider/model, response time, salted IP hash), feeding a new "Analytics" page with usage summary cards, a Popular Questions table, and a Questions Without Answers table — the clearest signal for what content is missing from the knowledge base.
* Source citations: the widget now renders a "Sources" list of the knowledge-base pages/documents an answer was grounded in, for both streaming and non-streaming responses. The backend already carried this data since Phase 5; this phase adds the front-end presentation.
* Response caching: repeated first-turn questions (no prior conversation history) are served from a short-lived cache instead of re-calling the embedding and chat AI providers, cutting cost and latency for common/FAQ-style questions. Configurable (on/off, TTL) under Settings > Caching; clearable from the Analytics screen.
* Complete rate limiting: configurable request-count/time-window limits (previously hardcoded), a manual IP denylist, and an opt-in proxy-aware IP resolution mode (X-Forwarded-For) for sites behind a trusted reverse proxy/CDN — off by default since that header is spoofable otherwise. Blocked/throttled request counts surface on the Analytics screen.
* Security hardening: a new `aikc_chat_allow_request` filter lets a site owner plug in third-party bot/CAPTCHA verification without modifying plugin code; visitor IP addresses are never stored raw, only as a salted SHA-256 hash; chat log text is length-capped on insert; added defensive `X-Content-Type-Options` header to the streaming response; uninstall.php now fully cleans up the chat-log table, cache transients, and all scheduled cron events.
* New "Log Retention" setting (days) with a daily cron that purges old chat logs automatically, so the log table doesn't grow unbounded on a busy site.

= 0.5.0 =
* Front-end chat widget: a floating launcher button (auto-injected in the footer) plus an `[aikc_chatbot]` shortcode for inline embedding, both backed by the same vanilla-JS client (no build step, no external JS dependency).
* New public REST endpoint `POST /wp-json/aikc/v1/chat` with real Server-Sent Events streaming — each provider's chatStream() relays incremental tokens over a raw curl connection (wp_remote_* cannot stream), which the endpoint forwards to the browser as SSE and the widget renders as they arrive.
* ChatService + PromptBuilder: retrieval-gated answering — if no indexed chunk meets the relevance threshold, the canned "couldn't find it" message is returned and the AI provider is never even called, enforcing "never guess" structurally rather than by prompt instruction alone.
* Widget features: typing indicator, safe markdown-lite rendering (code blocks, inline code, bold/italic, links), copy-answer button, per-tab conversation history (sessionStorage), and a clear-chat control.
* New Chat Widget settings section: enable/disable, floating vs. shortcode-only, title, welcome message, chunks-per-question, and minimum relevance score.
* Basic IP-based rate limiting on the chat endpoint as a stopgap; a complete abuse-prevention solution is planned for the security-hardening phase, along with source-citation UI (the backend already returns source metadata) and full chat logging/analytics.

= 0.4.0 =
* Real chat completions for all five providers: OpenAI, Google Gemini, Anthropic Claude, Azure OpenAI, and OpenRouter.
* Each provider bridges its own wire format (Gemini's systemInstruction/model role, Claude's system field + x-api-key/anthropic-version headers, Azure's api-key header + deployment-based URL) behind the same AIProviderInterface contract.
* validateApiKey() now makes a real, cheap live test call for every chat provider (previously a Phase 1 stub).
* New Azure OpenAI Options settings fields (resource endpoint, API version), needed alongside the existing API key and deployment-name model field.
* Low default temperature (0.0) to favor literal, grounded completions over creative ones, consistent with "answer only from the knowledge base."

= 0.3.0 =
* Embedding providers (OpenAI, Google Gemini, and a Local/Self-Hosted HTTP client) with real API calls and batch embedding support, independent from the chat provider choice.
* Qdrant vector database integration (plain REST API, no extra dependency): collection auto-creation sized to the embedding model's dimensions, upsert, delete, and similarity search.
* Background embedding queue: newly indexed chunks are embedded and upserted automatically (near-immediate nudge plus a five-minute cron safety net), with per-chunk status/error tracking.
* Stale vectors are cleaned up automatically whenever content is re-indexed, excluded, removed, or the entire index is deleted.
* RetrievalService: turns a natural-language question into the top-K most relevant indexed chunks, ready for the chat-handling phase.
* Settings page: Vector Database section (Qdrant URL, collection, API key, Test Connection), Local Embedding Endpoint field, and embedding provider/model dropdowns now driven by the same registry pattern as chat providers.

= 0.2.0 =
* Knowledge indexing pipeline: extractors for posts/pages/custom post types, WooCommerce products, FAQ entries, and uploaded PDF/DOCX/TXT/CSV files.
* HTML cleaning, prompt-injection sanitization, and word-based chunking prior to storage.
* New "Knowledge Manager" admin screen: source/category selection, chunking settings, document upload, sync/re-index/delete controls, and an index status table.
* Custom database tables (aikc_index_items, aikc_chunks) with automatic re-index on publish/update/unpublish/delete, plus a daily safety-net sync.

= 0.1.0 =
* Initial scaffold: plugin architecture, PSR-4 autoloading, DI container, settings page, AI provider interface and skeleton providers.
