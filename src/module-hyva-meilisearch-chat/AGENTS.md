# AGENTS.md — Hyva_WalkwizusMeilisearchChat

Hyvä storefront frontend for the Meilisearch conversational-search widget. **Presentation only**:
a floating chat launcher + panel, a CSP-compliant Alpine component, and self-contained CSS. All
backend logic (the streaming proxy, chat key, card extraction) lives in the sibling base module.

| Fact | Value |
|------|-------|
| Module name | `Hyva_WalkwizusMeilisearchChat` |
| Namespace | `Hyva\WalkwizusMeilisearchChat` (no PHP classes today — view layer only) |
| Part of package | `walkwizus/magento2-module-meilisearch-chat` (monorepo: this + base module) |
| Depends on | `Walkwizus_MeilisearchChatBase`, `Hyva_Theme` (see [etc/module.xml](etc/module.xml)) |
| Area | `frontend` only |

## Layout / where things are

| Path | Purpose |
|------|---------|
| [view/frontend/templates/chat.phtml](view/frontend/templates/chat.phtml) | The widget: markup + inline CSP Alpine component `initMeilisearchChat` |
| [view/frontend/layout/default.xml](view/frontend/layout/default.xml) | Injects the block into `before.body.end` |
| [i18n/en_US.csv](i18n/en_US.csv) | UI strings |
| [registration.php](registration.php) | Module registration |

The block is wired with two ViewModels (see [default.xml](view/frontend/layout/default.xml)):
`Walkwizus\MeilisearchChatBase\ViewModel\ChatConfig` (proxy URL, add-to-cart URL, display text) and
`Hyva\Theme\ViewModel\HyvaCsp`.

## Hard rules (do not break)

- **CSP-compliant Alpine is mandatory.** Hyvä runs the Alpine **CSP build** (no `unsafe-eval`).
  Every directive binds to a method/property — **no inline expressions, mutations, negation, `x-model`,
  or method args**. The inline `<script>` must end with `<?php $hyvaCsp->registerInlineScript(); ?>`
  and register via `alpine:init` + `{once:true}`. See the `hyva-alpine-component` skill.
- **Styling must be self-contained, not Tailwind.** The host theme purges unused Tailwind utilities,
  so the widget shipped broken when it used them — hence the namespaced `.ms-chat*` classes. Define
  them in a self-contained stylesheet the module loads itself; do **not** rely on the theme's Tailwind build.
- **No secrets, no Meilisearch host here.** The browser talks **only** to the same-origin Magento
  proxy URL from `ChatConfig` (`meilisearchchat/ajax/completions`). Never embed the chat API key or
  the Meilisearch address in this module.
- **Render LLM output as plain text** (`x-text`), never `x-html` (XSS).

## Data contract (from the proxy — defined by the base module)

The widget reads the proxy SSE: text deltas (`choices[].delta.content`), `event: products` (card
objects `{id,name,sku,brand,price,price_formatted,in_stock,image,url}`), `event: status`,
`event: error`, `event: meta`. Cards are buffered during streaming and **revealed only after the
message finishes** (`[DONE]`). Conversation persists in `sessionStorage`. The request body resends
prior turns as `{role, content}` only — never cards or tool messages.
