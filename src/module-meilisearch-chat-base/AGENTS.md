# AGENTS.md — Walkwizus_MeilisearchChatBase

Backend + transport for the Meilisearch conversational-search integration. Owns the **server-side
streaming proxy** that holds the chat key and bridges the browser to Meilisearch's chat-completions
endpoint. The Hyvä widget (sibling module) is presentation only and talks **only** to this proxy.

| Fact | Value |
|------|-------|
| Module name | `Walkwizus_MeilisearchChatBase` |
| Namespace | `Walkwizus\MeilisearchChatBase` |
| Part of package | `walkwizus/magento2-module-meilisearch-chat` (monorepo: this + Hyvä module) |
| Depends on | `Walkwizus_MeilisearchBase` (see [etc/module.xml](etc/module.xml)); `meilisearch/meilisearch-php` (`^2.0@beta`) |
| Areas | `frontend` (ajax controller) + `adminhtml` (config) |

## Request flow

```
browser ──POST──▶ Controller/Ajax/Completions ──▶ Service/ChatManager ──▶ Meilisearch /chats/{ws}/chat/completions
                  (CSRF, sanitize, rate-limit,        (SDK streamCompletion,        (OpenAI-compatible SSE)
                   SSE transform)                       chat key, tools[])
                        │                                      │
                        └── Service/StreamTransformer ◀─────────┘  (+ Service/CardExtractor)
                            emits to browser: text deltas, event: products / status / error / meta; [DONE]
```

## File map

| Path | Purpose |
|------|---------|
| [Controller/Ajax/Completions.php](Controller/Ajax/Completions.php) | Frontend SSE proxy endpoint (`meilisearchchat/ajax/completions`). CSRF, input sanitize, rate-limit, streaming headers, transform loop |
| [Service/ChatManager.php](Service/ChatManager.php) | Calls the SDK `chatWorkspace()->streamCompletion()` with the `tools[]` array; `const MODEL = 'gpt-5.2'`; maps SDK exceptions |
| [Service/ChatClientFactory.php](Service/ChatClientFactory.php) | Builds the `Meilisearch\Client` with the chat key + a Guzzle client `['stream'=>true]` (lazy body) |
| [Service/StreamTransformer.php](Service/StreamTransformer.php) | Rewrites upstream SSE: passes text, swallows `_meili*` tool calls, synthesizes `event: products`/`status`/`error` |
| [Service/CardExtractor.php](Service/CardExtractor.php) | `_meiliSearchSources` → normalized card objects; resolves image/PDP URLs, formats price; null-guards, caps, dedupes |
| [Service/MessageSanitizer.php](Service/MessageSanitizer.php) | Role whitelist (drops client `system`), length/turn caps |
| [Service/RateLimiter.php](Service/RateLimiter.php) | Per-session+IP fixed-window limiter (Magento cache) |
| [Service/ChatLogger.php](Service/ChatLogger.php) | Level-aware, redaction-safe logging (never logs the key) |
| [Model/Config/ChatSettings.php](Model/Config/ChatSettings.php) | Config accessors: `isEnabled`, `getChatApiKey` (decrypt), `getWorkspace`, `getLogLevel`, display getters |
| [Model/Config/Source/LogLevel.php](Model/Config/Source/LogLevel.php) | `off` / `errors` / `full` |
| [ViewModel/ChatConfig.php](ViewModel/ChatConfig.php) | Safe frontend config (proxy URL, add-to-cart URL, display text) — never the key |
| [Exception/ChatException.php](Exception/ChatException.php) | User-safe message + internal cause for logging |
| [etc/di.xml](etc/di.xml) | Dedicated `var/log/meilisearch_chat.log` logger (virtualTypes) wired into `ChatLogger` |
| [etc/adminhtml/system.xml](etc/adminhtml/system.xml) | Admin config: section `meilisearch_chat` |
| [etc/frontend/routes.xml](etc/frontend/routes.xml) | Route `meilisearchchat` |
| [Test/Unit/Service/](Test/Unit/Service/) | PHPUnit unit tests for `CardExtractor` + `StreamTransformer` |

## Hard rules (do not break)

- **Chat key is server-side only.** It carries the `chatCompletions` action (paid LLM calls). Never
  expose it to the browser, never put it in `ChatConfig`/logs. The browser only hits the same-origin proxy.
- **The `tools[]` array is required.** Meilisearch's compat endpoint only streams `_meiliSearchSources`
  (→ product cards) when the request declares the `_meili*` tools (see `ChatManager::meiliTools()`).
  Without it you get text only, no cards.
- **Lazy streaming.** Build the SDK client's Guzzle with `['stream'=>true]` (see `ChatClientFactory`) —
  the SDK's `postStream` uses PSR-18 `sendRequest`, which buffers by default otherwise.
- **Streaming mechanics in the controller** (real deployments): `X-Accel-Buffering: no`, no-cache/FPC
  bypass, no gzip, `set_time_limit(0)`, `session_write_close()` before the read loop, line-buffered reads.
- **MODEL is hardcoded** (`ChatManager::MODEL = 'gpt-5.2'`) — Meilisearch exposes one model; not admin config.
- **Input is untrusted.** Keep the role whitelist + caps (`MessageSanitizer`) and the rate limit.
- **Logging never includes the key/Authorization.** `full` level logs request/response (PII-adjacent) —
  troubleshooting only.

## Data contract (proxy → frontend, `event: products`)

`{ id, name, sku, brand, price, price_formatted, in_stock, image, url }` — keep stable; the Hyvä
widget renders these directly. Field mapping from `_meiliSearchSources` lives in `CardExtractor`.

## Config (admin: Stores → Configuration → Meilisearch → Chat)

Section `meilisearch_chat`: `enabled`, `chat_api_key` (obscure/encrypted), `workspace`, `log_level`,
plus display (`widget_title`, `welcome_message`). Server address/keys for the
host are reused from `Walkwizus\MeilisearchBase\Model\Config\ServerSettings`.

## Tests

PHPUnit unit tests under [Test/Unit/Service/](Test/Unit/Service/) (pure logic — `CardExtractor`
mapping incl. quote-escaping/missing-field edge cases; `StreamTransformer` SSE rewriting). They need
the Magento autoloader on the path (run via the application's PHPUnit / the package `phpunit.xml.dist`).
The app requires **PHP 8.4**.
