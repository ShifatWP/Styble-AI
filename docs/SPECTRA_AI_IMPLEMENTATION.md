# How Spectra Implements Its AI Features

A read of two plugins as they exist on disk:

- `wp-content/plugins/zip-ai/` — **ZIP AI** v0.0.7, "conversational AI agent that
  builds, edits, and manages your WordPress site by chat". 61 PHP classes,
  ~6.5k lines of hand-written JS. Namespace `ZipAI\MCP`.
- `wp-content/plugins/spectra-blocks/` — **Spectra Blocks**, "AI Website Builder
  for the Block Editor". The block library + a separate, older generation of AI
  features (template library, stock images, business-details content fill).

Everything below is from the code, not the marketing. Where the code and the
readme disagree, the code wins and I say so.

---

## 0. The one-sentence version

**Spectra puts no model in the plugin.** The plugin is a *tool host* — it
publishes WordPress capabilities as MCP tools, hands a credential to a remote
agent, and then executes whatever that agent asks for, including inside the live
Gutenberg editor over a synchronous browser RPC channel. All prompting,
planning, and model calls happen on ZipWP servers.

That is the opposite of `styble-ai`'s design (prompt → schema → validator →
serializer, all local). §14 compares them properly.

---

## 1. Product surface — what a user actually touches

| Surface | Where | Loaded by |
|---|---|---|
| Floating chat panel (sidebar) | every wp-admin page | `React_Manager::render_container` |
| Full-page chat screen | Settings → ZIP AI Assistant | `React_Manager::render_fullpage_screen` |
| "Ask ZIP AI" block-toolbar popover | block editor, per block | `assets/js/editor/quickedit.js` |
| "+" pin button on block hover | block editor | `assets/js/core/block-context-picker.js` |
| Snippets admin screen | ZIP AI → Snippets | `Snippet_Admin` |
| Spectra dashboard → AI area | Spectra Blocks menu | installs/activates zip-ai over AJAX |
| Template library + AI content | Gutenberg "Design Library" | `lib/gutenberg-templates/` |
| ZipWP stock images | image blocks | `lib/zipwp-images/` |

Keyboard: `Cmd/Ctrl+E` toggles the panel (legacy `Cmd/Ctrl+Shift+Space` kept).
Both registered on the parent admin document, so they work with the panel closed.

Gating: everything is `manage_options` **and** admin-only. There is an explicit
comment that the assistant is never enqueued on the public frontend. Spectra
Blocks must be installed and active or the React app renders a setup-gate card
(`React_Manager::get_setup_gate`).

---

## 2. Topology — four tiers, three of them remote

```
┌─ BROWSER (wp-admin) ────────────────────────────────────────────┐
│  React chat app          assets/js/dist/chat-assistant.js       │
│  Bridge host             assets/js/core/wp-bridge-host.js       │
│  Tool-hook registry      assets/js/core/tool-hooks-registry.js  │
│  Editor tool handlers    assets/js/tools/editor/*/handler.js    │
└───────┬──────────────────────────────────▲──────────────────────┘
        │ SSE turn stream                  │ js_rpc dispatch
        │ POST /agent/rpc-reply            │
┌───────▼──────────────────────────────────┴──────────────────────┐
│  LARAVEL RELAY   credits.zipwp.com/api/                         │
│  Sanctum tokens, credit metering, TurnRequestBuilder,           │
│  wp-credentials/bind (holds the App Password), Redis RPUSH      │
└───────┬──────────────────────────────────▲──────────────────────┘
        │                                  │
┌───────▼──────────────────────────────────┴──────────────────────┐
│  NODE "BRAIN"   brain.zipwp.com                                 │
│  runTurn, AgentBrowserLoop (BRPOP per call_id), toolRouting.ts,  │
│  recoveryHints, memory/fact extraction, LLM calls               │
│  Models: Google Gemini + Anthropic Claude (per privacy notice)   │
└───────┬─────────────────────────────────────────────────────────┘
        │ JSON-RPC 2.0 over HTTPS, Authorization: Basic <App Password>
┌───────▼─────────────────────────────────────────────────────────┐
│  WORDPRESS   POST /wp-json/zip-ai/v1/mcp                        │
│  Abilities API → 18 registered abilities → WP core              │
└─────────────────────────────────────────────────────────────────┘
```

Constants (`loader.php:132-164`):

```php
ZIPAI_MCP_BASE_URL           = 'https://credits.zipwp.com'   // Laravel
ZIPAI_MCP_CREDIT_SERVER_API  = BASE_URL . '/api/'
ZIPAI_MCP_MIDDLEWARE         = 'https://app.zipwp.com/auth/' // OAuth
ZIPAI_API_BASE               = 'https://api.zipwp.com/api/'  // images, sites
ZIPAI_BRAIN_URL              = 'https://brain.zipwp.com'     // direct-to-brain
```

`ZIPAI_BRAIN_URL` exists so latency-sensitive calls (inline edit, `rpc-reply`)
skip the Laravel hop. Overridable in `wp-config.php` or the `zipai_brain_url`
filter.

**The brain calls back into WordPress.** This is the load-bearing inversion: the
model does not receive a description of your site and emit markup. It receives a
tool catalog and drives WordPress live, one call at a time, over MCP.

---

## 3. Bootstrap and DI

`zip-ai.php` → `loader.php` → `ZipAI\MCP\Plugin::get_instance()`.

A small hand-rolled DI container (`classes/core/container.php`, 105 lines) with
two service providers:

- `Core_Service_Provider` — Context_Detector, Tool_Registry, REST_API,
  AJAX_Handlers, React_Manager, Snippet_REST_API, Snippet_Admin,
  ImportTextureGate.
- `Abilities_Service_Provider` — registers ability *categories* on
  `wp_abilities_api_categories_init`, instantiates `Zipai_Abilities`.

`register()` at construct time, `boot()` on `plugins_loaded` priority 5.

Three things bypass the container and hook directly in the constructor:

```php
Snippet_Executor::init();            // must run early to load AI-authored snippets
Plugin_Abilities_Toggler::init();    // listens for activated_plugin
add_action( 'admin_init', 'add_privacy_policy_content' );
// Site_Scanner::register_hooks();   ← COMMENTED OUT (loader.php:86)
```

Vendored via Composer: `wordpress/mcp-adapter` and `wordpress/php-mcp-schema`
(both from the WordPress AI Team), plus the Abilities API. `WP_MCP_AUTOLOAD` is
forced to `false` before requiring the adapter, because the adapter
self-bootstraps and would otherwise print a false "Composer autoloader not
found" notice and bail (`load_vendor_dependencies`, with a five-line comment
explaining exactly that).

### Categories

`zipai`, `sureforms`, `surecart`, `woo`, `a11y`. Only `zipai` is populated by
this plugin — the rest are namespace reservations so sibling Brainstorm Force
plugins can register into the same MCP surface. SureForms ships its own
abilities natively as of v2.7.

---

## 4. Authentication: two credentials, deliberately split

### 4a. The Sanctum token (site → SaaS)

1. React opens a popup to `app.zipwp.com/auth/?type=token&redirect_url=…&state=…`.
   `state` is a 32-char value in a 10-minute transient keyed per user; it is
   *reused* while valid so the popup flow can't overwrite itself mid-auth.
2. Callback lands on `admin_init` → `AJAX_Handlers::verify_authorization`.
3. Token stored in `zip_mcp_settings['auth_token']`, sodium-encrypted.
4. `auth_token_server` records which credit server minted it. On read, a token
   whose server doesn't match the current `ZIPAI_MCP_CREDIT_SERVER_API` is
   re-validated against `POST /api/auth/validate` before being trusted
   (`Helper::get_decrypted_auth_token`, memoized per request).
5. A legacy path exchanges an older `zip_token` + email for a local token via
   `POST /api/token/exchange`.

Sent as `Authorization: Bearer <token>` on every SaaS call. **This one is exposed
to the browser** in `window.ZIPAI_CONFIG.token` — the React app needs it to open
the SSE stream.

### 4b. The Application Password (SaaS → site)

The brain needs to call *back* into WordPress. It authenticates with a real
WordPress Application Password, minted by
`Helper::ensure_app_password_provisioned()`:

- Gated on `manage_options` and `wp_is_application_passwords_available()`.
- Idempotent: a stored UUID that still resolves on the user record is left alone,
  so reconnecting doesn't litter Profile → Application Passwords.
- Named `ZipWP MCP Connection`, `app_id = 'zip-ai-' . wp_generate_uuid4()`.
- App Password plaintext is one-time-visible. It is **never stored bare** — the
  code immediately builds `'Basic ' . base64_encode( $user_login . ':' . $plaintext )`
  and encrypts *that* string, which is the form needed on the wire anyway.

**The delivery mechanism is the interesting part.** An earlier version emitted
this header as `window.ZIPAI_CONFIG.wpAuthorizationHeader` and had React forward
it as `X-Wp-Authorization` on every chat call. The comment at
`react-manager.php:222-234` documents why that was removed: it put a raw Basic
credential into the admin page's inline JS where any other script could read it.

Now it goes **server-to-server**: `POST /api/wp-credentials/bind` with the
Sanctum token as bearer. The SaaS stores it in the issuing token's encrypted meta
column and reads it at turn time. Symmetric `unbind` on disconnect, called
*before* local meta is wiped so the bearer token is still available.

### 4c. Encryption at rest

`Utils::encrypt/decrypt` — `sodium_crypto_secretbox`, random nonce prepended,
base64, `sodium:` prefix. The key is **never stored**; it is derived on demand:

```php
hash_hmac( 'sha256',
    'zip-ai-enc-v1|' . $db_salt,      // message: version + per-site DB salt
    wp_salt( 'secure_auth' ),         // HMAC key: from wp-config.php
    true );
```

The point is stated explicitly in the docblock: the secret is split across the
**filesystem** (`SECURE_AUTH_KEY`/`SALT` in wp-config) and the **database**
(`zipwp_mcp_key_salt`, 32 random bytes). A DB-only compromise — SQL injection, a
leaked backup, a read replica — cannot reconstruct the key. Fails closed
(returns `''`, treated as "not stored") when sodium or `wp_salt()` are missing.

The DB half is minted with `add_option()`, which won't clobber, then re-read — so
a concurrent first-use race settles every racer on the same salt.

### 4d. Also: an HMAC shared secret

On activation, `register_hmac_secret()` generates 32 random bytes and POSTs them
to `/api/auth/register-secret` with site_id/name/admin_email. Used for signing
server-to-server calls. Activation also adds a `manage_zip_mcp_assistant`
capability to administrator (deactivation strips it from every role).

---

## 5. The tool layer: Abilities API → MCP

### 5a. Abstract_Ability

Every tool subclasses `Abstract_Ability` (462 lines) and implements three
methods: `configure()`, `get_input_schema()`, `execute($args)`.

Declarative properties the base class turns into behaviour:

| Property | Effect |
|---|---|
| `$id` | `zipai/install-plugin` — format-validated, see below |
| `$capability` | default `edit_posts`, becomes `permission_callback` |
| `$is_destructive` | auto-injects a `dry_run` boolean into the input schema |
| `$read_only_actions` | sub-action allowlist for multiplexed tools |
| `$required_plugin` + version | dependency declaration forwarded to the brain |
| `$boost_screens` | WP screen IDs where this tool ranks higher in retrieval |
| `$resource` | shared identifier for read-first-write pairing |
| `$version` | semver, bumped on schema/behaviour change |
| `$meta['visibility']` | `'internal'` hides from the LLM, keeps it callable server-side |

**ID naming is enforced with a regex.** `Ability_Loader::check_ability_format`
requires `{namespace}/{action}-{resource}` where action is one of 24 canonical
verbs (`list get create update delete activate deactivate restore install
uninstall upload import export flush replace check clean run edit read search
scan`). A violation `trigger_error`s under `WP_DEBUG`. The payoff is that a
model can guess a tool name correctly.

### 5b. handle_execute — the wrapper every call goes through

`Abstract_Ability::handle_execute` runs before `execute()`:

1. **Rate limit** — 100 requests/minute per user+ability, transient-backed.
   Skipped only under `ZIPAI_TESTING` (CLI batch imports). A comment notes the
   dev-time `ZIPAI_RATE_LIMIT_DISABLED` short-circuit was removed before ship.
2. **Validate + sanitize** input against the final schema (`Validator::validate`).
3. **Dry run** — if destructive and `dry_run` is set, call `dry_run()` and return.
4. `execute( $validated_args )`.
5. **Metrics** — wall time in ms, peak memory delta in KB.
6. **Log** via `Event_Logger::log( id, args, response, performance )`.
7. Catch `\Exception` *and* `\Error` separately; both still log.

So no ability author can forget validation, rate limiting, or telemetry.

### 5c. tool_type, and a fail-safe worth copying

```php
public function get_tool_type() {
    return $this->is_destructive ? Tool_Types::ACTION : Tool_Types::READ;
}
```

The docblock records that the default **used to be `READ`**, which meant a new
mutating ability that forgot to override `get_tool_type()` was classified
read-only and **bypassed the approval gate entirely**. The default is now
fail-safe. `Tool_Types::get_confirmation_required_types()` = `WRITE`, `DELETE`,
`ACTION`.

`$read_only_actions` solves the inverse problem for multiplexed tools. A single
ability like `zipai/run-snippet` routes `create|list|get|update|delete` through
one `action` enum, so `is_destructive = true` is correct overall but trips the
approval gate on `action: "list"`. The allowlist rides through meta →
`tools/list` → brain, which reads it generically — no per-tool hardcoding on the
brain side.

### 5d. Registration

`Zipai_Abilities` hooks `wp_abilities_api_init` and calls
`load_abilities_from_dir( __DIR__, __NAMESPACE__ )`, which walks
`classes/abilities/**` with `RecursiveDirectoryIterator`, derives the PSR-4
namespace from the path, skips `index.php`/`handler.php` and a
`$disabled_abilities` denylist, then calls `wp_register_ability()`.

Adding a tool = dropping one file in the right folder. No registry to edit.

### 5e. The catalog as shipped

18 abilities. Three generic, fifteen specific.

**`classes/abilities/core/` — the generic escape hatches**

| ID | Destructive | Note |
|---|---|---|
| `zipai/run-rest-request` | ✔ | Generic REST proxy via `rest_do_request()`. Batches ≤25 (matches core's `rest_get_max_batch_size`), caps responses at 100 items to protect the context window. **`visibility: internal`** — hidden from the LLM, kept for server-side callers (PageDeliveryService, ParallelPageBuilderService, DesignTokenService, BuildPageFromPatternsTool). |
| `zipai/run-wp-cli` | ✔ | Native WP-CLI dispatcher. Three traits: command parser, search-replace engine, **security verifier**. |
| `zipai/search-grep` | ✘ | Content grep. |

`run-rest-request` is a design decision with real weight: rather than write 50
CRUD abilities, expose the REST API and let each route's own
`permission_callback` do the authorization. WordPress already has the
permission model; don't rebuild it.

**`classes/abilities/zipai/system/`**

`install-plugin`, `activate-plugin`, `deactivate-plugin`, `delete-plugin`,
`update-plugin`, `install-theme`, `delete-theme`, `install-fonts`,
`upload-media`, `list-snippets`, `run-snippet` (multiplexed CRUD over code
snippets). All destructive except `install-fonts`, `upload-media`,
`list-snippets`. `PluginResolver.php` is a shared helper, not an ability.

**`classes/abilities/zipai/builder/`**

`import-media`, `install-bundled-plugin`, `install-bundled-theme` — the
page-build pipeline's own tools.

Plugin lifecycle abilities carry `meta['preflight_resource']` so the brain can
refuse an LLM-invented slug against `agent_context.site.<list>` **before** the
call leaves the server.

### 5f. Third-party registration

`Tool_Registry` fires `do_action( 'zip_ai_register_tools', $this )` on
`wp_abilities_api_init` priority 999. `register_tool( $name, $args )` accepts
`execution_mode` of `rest_api` or `js_hook`, plus `preview_mode` of
`none|client|server`. It then mirrors everything into `window.zipwpMcpTools` as
inline JS so the browser knows which tools it is responsible for executing.

---

## 6. The MCP endpoint

`POST /wp-json/zip-ai/v1/mcp` — one route, strict JSON-RPC 2.0.
`protocolVersion: 2024-11-05`, `serverInfo.name: "ZipWP WordPress MCP"`.

Methods: `initialize`, `tools/list`, `tools/call`, `notifications/initialized`.

Permission: App Password Basic auth, else a logged-in `manage_options` session.
`is_basic_authenticated()` decodes the header and delegates to core's
`wp_authenticate_application_password()`, which sets the current user as a side
effect — so downstream capability checks and third-party hooks resolve against
the App Password's owner natively. A comment notes the older
`auth_token_wp_user_id` binding and `x_wp_user_id` header gate were retired:
**identity is now bound to the credential, not asserted by the caller.**

### What `tools/list` forwards

Per tool: `name`, `description`, `inputSchema`, optional `outputSchema`, `title`,
`tool_type`, `read_only_actions`, and a **whitelisted** meta subset:

```php
tool_type, visibility, execution_mode, js_handler, resource, examples,
api_endpoint, boost_screens, required_plugin, required_plugin_version,
version, preflight_resource
```

Filterable via `zip_ai_tools_list_allowed_meta_keys`. The stated reason for an
allowlist rather than a dump: keep the payload bounded and stop new internal
ability fields from leaking by accident.

`mcp-adapter/get-ability-info` is explicitly **excluded** from the catalog. The
comment is a good failure post-mortem: the brain already has every
`inputSchema` in the same payload, so runtime introspection is redundant — and
that tool resolves names by canonical `namespace/name` while the brain knows
tools as `namespace__name`, so the argument never resolved and the call returned
a misleading "invalid permissions", dead-ending arg-correction recovery.
`discover-abilities` and `execute-ability` are kept as the surface-switch escape
hatch for `wp-cli-not-exposed` errors.

### Response shape

Results are wrapped MCP-style: `content: [{ type: 'text', text: <json> }]`, with
`isError: true` on failure. Ability responses that already carry a `success` key
(from the `Response` helper) pass through; `WP_Error` is converted.

---

## 7. Three execution modes

This is the core architectural idea in the plugin.

| Mode | Runs where | Reply path | Used for |
|---|---|---|---|
| `rest_api` | PHP, server-side | JSON-RPC response | plugins, themes, media, REST, CLI |
| `js_hook` | browser | **next turn**, via context injection | legacy browser tools |
| `js_rpc` | browser | **same turn**, `POST /agent/rpc-reply` | all editor tools |

Why browser modes exist at all: the live Gutenberg block tree only exists in the
browser's `wp.data` store, including unsaved edits. A server-side REST write
would race the open editor and clobber the user's session. `get-scripts`'
docblock says it flatly: *"A REST write here would race the open editor's copy
and mutate before save — never do that."*

### The js_rpc round trip

```
brain: AgentBrowserLoop emits a js_rpc envelope, then blocks on Redis BRPOP <call_id>
  → Laravel → SSE → React
  → bridgeHost.executeTools( results, sessionId )
  → toolHooks.executeToolHook( 'editor/apply-change', args )
  → handler mutates wp.data
  → bridgeHost.postRpcReply( call_id, ok, data, error, sessionId )
  → POST {brainUrl}/agent/rpc-reply
  → Laravel RPUSH → brain's BRPOP resolves → folded into the SAME turn
```

**Capability handshake.** Every editor context snapshot stamps
`rpc: RPC_PROTOCOL_VERSION` (currently `1`). The brain refuses to route a turn
to its `AgentBrowserLoop` unless it sees `rpc >= 1` — an older plugin bundle
degrades to the dashboard flow instead of timing out on every editor tool. Bump
only on an incompatible dispatch/reply change.

**Timing invariant, stated in the code.** Total reply time (3 attempts × 6s
timeout + backoff ≈ 19s) must stay **below** the brain's
`BRAIN_EDITOR_RPC_TIMEOUT_MS` (default 20s). The reason is precise: the plugin
applies the change *before* replying, so if the brain gives up first it reads a
false "nothing applied" and may nudge a retry → duplicate content.

Retries are safe by construction: Laravel RPUSHes at most one consumable copy
per POST, the brain releases the owner key after consuming, so a duplicate late
POST is 409-rejected. Retryable statuses are 409 (claim race) and 5xx only.

`session_id` rides along so Laravel can verify the reply against the
brain-claimed owner of that `call_id` — without it, any authenticated tenant
holding a `call_id` could inject a reply into a foreign turn.

### Idempotency (`core/rpc-dedup.js` + the `_rpcSeen` map)

A `js_rpc` apply mutates the tree **before** it replies. A second dispatch of the
same `call_id` — an SSE replay, a brain turn-replay after restart, or
`hydrateSession`'s `replay_events` after a page reload — would apply the mutation
twice and silently duplicate content.

The fix, and its shape matters:

- Before running the handler, look up the prior entry.
- **Any** prior entry (in-flight *or* completed) → never re-dispatch; re-POST the
  stored reply so a still-waiting BRPOP resolves.
- No prior entry → record an `IN_FLIGHT_MARKER` **first**:
  `{ ok: false, error: 'apply_pending_no_confirmation' }`. So a crash between
  the mutation and the completion record still leaves a marker; a replay reposts
  an uncertain `ok:false`, and the brain verifies with `get_context` before any
  retry — never a blind re-apply.
- Bounded FIFO, 64 entries, backed by **sessionStorage** — because the worst-case
  hazard is exactly a mid-turn reload, which an in-memory map wouldn't survive.
  Degrades to memory-only on quota/privacy failure; never throws into dispatch.

The pure decision function is extracted into its own dual-mode module
(browser global + CommonJS) purely so it can be unit-tested under jest. The
header explains why: *"the silent-until-it-bites duplicate-content path."*

---

## 8. Context assembly

`WPBridgeHost.getContext()` builds the per-turn payload. Only a few fields come
from the browser; the rest are localized from PHP at page load.

```js
{
  wp_user_id,
  editor_context: { … },          // live, from wp.data
  page_context:   { … },
  website_context: { current_url, site_url, site_title, site_tagline,
                     language, timezone, date_format, time_format, is_multisite },
  theme_context:  { color_palette },   // Astra palette → --ast-global-color-N
  installed_plugins: { slug: version },
  admin_screen:   { … }
}
```

### editor_context — the parts that took iteration

**`is_block_editor` is resolved twice.** PHP is authoritative
(`get_current_screen()->is_block_editor()` narrowed to `base === 'post'`), and
the JS heuristic is a fallback for when `wp.data` hasn't initialized at boot —
without it the brain sees `is_block_editor: false` on the first turn and offers
dashboard-only tools, e.g. spawning a new page when one is already open.

The `base === 'post'` narrowing is deliberate: SureCart's page editor and the
Site Editor both report `is_block_editor() === true`, but editor tools only
operate on a real WP post. The JS side re-gates on
`/\/(post|post-new)\.php$/`.

**`page_outline`** — a compact list of top-level sections, built by
`buildPageOutline`. Block detail is *not* dumped; the brain pulls it on demand
via `editor/get-context`. Two heuristics live here:

- `dominantRepeatedChildType` — the direct-child block name occurring 3+ times.
  Signals a card grid.
- `nestedGrid` — when direct children aren't a repeater but *exactly one* child
  is a container whose children are, that inner container is the grid (the
  Spectra `section > content > [card × N]` shape). "Exactly one" keeps it
  unambiguous.

Section labels come from **live TEXT_KEYS attributes only** — never
`block.originalContent`, which is parse-time HTML and goes stale after an
in-editor edit. A stale label steers the model to the wrong section → wrong
clientId → select-X-operate-Y. A container with no own text descends to a child
that has one, so a section is always labelled by its real heading.

**`page_wide` + `selected_block`** — scope intent driven by *real editor state*,
not the user's words:

```js
editorContext.page_wide = !hasLiveSelection;
```

A live selection means the user is acting on that element → confine the edit.
Deselecting is the explicit affordance to widen scope. The comment notes this
replaced a page-wide TEXT regex on the brain side, and calls out the win: a
coincidental phrase like "all sections" can no longer silently disable the
selection lock.

`selected_block` is built **directly in snake_case** (`client_id`, `block_name`)
with no camelCase intermediate. The reason given is a real bug: the brain's zod
schema reads snake_case and strips everything else, and a camelCase
`serializeBlockLight` produced a `"client_id: Required"` turn-drop. Removing the
second spelling removed the drift surface. Repeater signals ride along
(`repeated_children`, `repeated_child_count`, `last_child_client_id`) so "add one
more" routes to a **child clone** instead of authoring a new section.

An `L-8` note records two attribute bundles (`parent_*` and `config_attrs`) that
were computed every turn and silently dropped by the wire schema — deleted as
wasted compute and a boundary smear.

**`theme_tokens`** — `getSettings().colors/fontSizes` mapped into the brain's
exact wire shape `{ colors: [{slug, hex}], font_sizes: [{slug, size}] }`, so
Laravel relays it verbatim with no reshaping. Malformed entries dropped at the
source; returns `null` (field omitted) rather than an empty object.

**`snapshot_id` / `selection_revision`** — monotonic counters for staleness
detection.

**`last_tool_results`** — the `js_hook` (not `js_rpc`) next-turn channel. Results
buffer on `window.__zipwpLastToolResults` and are injected into the *next*
context, then cleared. Each carries the Anthropic `tool_use_id` as `call_id`, so
the brain's `jsHookReconciler` attributes results deterministically against
`todo.dispatched_call_ids`, with original arguments as a subset-match fallback.

---

## 9. The editor tool family

Six `js_rpc` tools, three symmetric read/write pairs, one per storage substrate:

| Substrate | Read | Write |
|---|---|---|
| Block tree (HTML) | `editor/get-context` | `editor/apply-change` |
| GBS CSS store | `editor/get-styles` | `editor/set-styles` |
| Per-block JS | `editor/get-scripts` | `editor/set-scripts` |

Registered names, all confirmed in source: `editor/get-context`,
`editor/apply-change`, `editor/get-styles`, `editor/set-styles`,
`editor/get-scripts`, `editor/set-scripts`.

All self-register with `window.zipwpMcp.registerTool`, retrying every 100ms until
the bridge exists. `React_Manager` **globs** `js/tools/editor/*/handler.js`, so a
new editor tool needs zero PHP changes. Shared helpers live in one module
(`editor/shared/editor-shared-utils.js`) resolved at call time — window in the
browser, `require()` under jest — so `blockFingerprint` and `currentPostId` can't
drift between handlers.

### editor/apply-change — the write path

Input: `{ version, post_id, operations: [ { function, ...namedArgs } ] }`.

Eight allowed `wp.data.dispatch('core/block-editor')` functions:
`updateBlockAttributes`, `insertBlocks`, `removeBlocks`, `moveBlocksToPosition`,
`replaceBlocks`, `replaceInnerBlocks`, `duplicateBlocks`, `selectBlock`.

**The brain drives native Gutenberg APIs directly.** There is no intermediate IR.
The contract is: `updateBlockAttributes` preserves clientId (read-merge-dispatch);
`insertBlocks`/`replace*` mint clientIds, returned in `applied[].new_client_ids`
so the brain can target them next turn; `moveBlocksToPosition` is a splice and
preserves ids. Insert/move accept a `before`/`after` anchor resolved to
root+index locally.

Seven guards, each with a documented failure it prevents:

1. **`post_id` two-tab guard** — mismatch aborts *all* operations and returns a
   `refused` reply with `ok: true`, so the LLM sees the real reason rather than
   "browser unreachable".
2. **Liveness** — `sel.getBlock(id)` must resolve or it throws
   `stale_client_id:<id>`. A stale id must never default to "top".
3. **`assertMutable`** — Gutenberg's own `canEditBlock`/`canRemoveBlock`/
   `canMoveBlock` are authoritative; they already fold in template lock, content
   lock, and synced-pattern instance locks. Prevents editing a `core/block`
   instance's inner content and escaping this page into shared content. Degrades
   to ALLOW on older Gutenberg — never a false block.
4. **`assertInsertable`** (GBR-2) — `insertBlocks`/`duplicateBlocks` *add* to a
   container, a case the canEdit/Remove/Move family doesn't cover, so those two
   previously bypassed the lock check. Checks `getTemplateLock(root)` for `all`
   or `insert`.
5. **`assertMoveDestination`** (M2) — `canMoveBlock` folds in the *source*
   parent's lock only; moving *into* a fully-locked container bypassed every
   check. Only `'all'` blocks a move-in (`'insert'` permits reordering existing
   children).
6. **`scope_lock`** — when the turn bound a subtree scope, the brain stamps the
   selected container's clientId. Every mutating op must then target that
   container or a descendant, checked via `getBlockParents`. `selectBlock` is
   exempt (navigation, not mutation). A mutating op with *no* resolved target is
   treated as a page-root op and refused. This is the airtight tree-aware half of
   "stay inside the selected element"; the brain's outline-only gate is the soft
   half. The error messages are written **for the model**, telling it what to do
   instead:

   > `out_of_scope: target <id> is outside the selected container <lock> (and its children). You are scoped to that section — edit inside it, or tell the user if a different section is intended.`

7. **Banned visual attributes** — eight flat visual attrs are never allowed on a
   block, no matter what the block's own registry declares:

   ```js
   style, styleAttributes,
   backgroundColor, backgroundColorHover,
   boxShadow,       boxShadowHover,
   textColor,       textColorHover
   ```

   Styling lives in `className` only (§10). The brain's zod denylist owns the
   contract and Laravel's `StrictAttrValidator` mirrors it; this is defence in
   depth so a stale or forked brain bundle can't paint banned attrs into the live
   tree. If the shared module isn't resolved it **doesn't strip** — degrading
   consistently rather than becoming a third copy of the list.

   Framing matters here: the eight are described as *"a sanctioned constant, not
   an allowlist — every OTHER attr is decided by the registry."* One short
   hand-maintained denylist, everything else derived from `getBlockType()`.

**Partial apply is the model, not an error.** A per-op failure records into
`failed[]` by index and continues; earlier ops stay applied.

**`partitionAttrs` — teaching the model instead of failing silently.** Incoming
attributes are partitioned against `getBlockType(name).attributes`, the exact set
Gutenberg validates against. Unknown keys go into `unknown_attrs` in the reply
rather than being silently ignored. The comment names the exact loop this
closes: passing `tagName` to a `spectra/container` (which uses `htmlTag`) gets
dropped by the registry, the model never sees the edit complete, and it retries
forever. An unregistered block type returns `null` → degrade open, apply
everything.

Content attributes are discovered from the **registry**, not a hardcoded table:
`source === 'rich-text' || 'html'` covers core blocks; a declared `content`/`text`
attribute covers the Spectra convention. Blocks with neither (image, spacer) are
left untouched.

### editor/get-context — design-faithful reads

Returns rows of `{ clientId, blockName, text, className, path, html?, computed? }`.

- `text` — stripped, ≤120 chars, for identification.
- `html` — rich content **with inline markup intact**, ≤600 chars, surfaced only
  when the content actually contains `<`. This is the carrier of the page's design
  *pattern* (per-word colour spans, emphasis). Without it, a content rewrite
  flattens a styled headline to a plain string — the docblock calls it "the
  headline-flattening defect".
- `computed` — `getComputedStyle` digest (fontSize, color, backgroundColor,
  padding, fontWeight) read from the **canvas iframe** document. So the brain
  edits relative to rendered reality rather than authored class tokens, and can
  see when a class did *not* move a property because a `gs-*` `!important` rule
  owns it.
- **Content-ownership detection.** For *leaf* blocks with *text* attrs only, if
  the rendered DOM text differs from the authored attribute, the block's own attr
  is a dead lever — the value is computed upstream by a parent composite (a
  countdown unit's label comes from the parent's `{unit}sLabel`). Editing the
  child silently no-ops. The handler surfaces the rendered value and lets the
  *brain* judge, rather than returning a server-computed boolean — no per-block
  knowledge required, the render is the source of truth.

  Scoped hard against false positives: leaves only (a container's `textContent`
  is its whole subtree), text attrs only (`number` self-formats: 1000→"1,000",
  count-up mid-flight), both sides normalized (entities decoded via a cached
  detached element, whitespace collapsed), and mid-typewriter prefixes skipped.

### editor/get-styles + set-styles — the GBS bridge

One store key, `spectra_blocks_pro_gs_user_css`, two scopes:

- `scope: 'page'` → post meta, written via Spectra's
  `/spectra-blocks/v1/global-styles/save` route. Immediate.
- `scope: 'global'` → the same-named WP **option** (header/footer/site chrome),
  read via `GET /global-styles/user-css`, written via the sitewide merge route.
  Immediate, site-wide, **not reversible by discard**.

Always READ → merge only touched buckets → WRITE. Never full-replace, so importer
chrome and user classes survive. Object buckets merge per entry (`null` deletes
the entry; a `null` bucket deletes the bucket); array buckets replace. The merged
payload is then rendered through the SSOT `GenCssRenderer`
(`REST /global-styles/render` — explicitly "no string hacks") and injected into
the canvas iframe for live paint.

`styleContext` resolves **ownership**: a visual property is set by exactly one of
three layers, in descending specificity — a block **attribute**, a GBS **class
body**, or the block **default**. To change a property you edit its current
owner (update the existing class; don't stack a new one; clear a pinned attr).
The resolver answers "who owns this" so the agent never hand-resolves
specificity. It does not compute the exact frontend winner — the editor canvas
inverts utility-vs-gsClass specificity and utilities are JIT-compiled — so
`effective` (the rendered value) is the truth and the verify-iterate loop
corrects mis-guesses.

### editor/get-scripts + set-scripts

Reads/writes a block's `spectraCustomJS` attribute — the per-block JS store.
Spectra Pro's `BlockJsCompiler` renders it once at `wp_footer`, superseding a
removed per-page meta key. Default target is the page root container.

Session-scoped **by design**: reads from `getBlockAttributes` (live session,
including unsaved edits), writes through `updateBlockAttributes`. Discarding the
session discards the JS; it persists only on Save. `<script>` tags are rejected
with a typed error — the store wraps raw JS itself, and the renderer resolves a
`_current_block_` token to the block's scope class.

---

## 10. The styling contract: why the model writes class names

`includes/GlobalStyles/class-jit-compiler.php` (4,140 lines) is a **Tailwind-style
JIT compiler** that parses `className` tokens "emitted by SpectraGen (ERA)" and
resolves them to CSS. Three token families:

1. Known utilities, looked up in `ClassRegistry` (5,665 lines).
2. Per-utility arbitrary brackets — `max-h-[80vh]`, `px-[71px]`, `text-[#ff0000]`,
   `rounded-[12px]`. Prefix → CSS property via `PREFIX_MAP`; value strictly
   sanitized; `var(...)` references rejected.
3. Variant-prefixed — `responsive:state:pseudo:class` in canonical order, e.g.
   `md:hover:scale-105`, `md:hover:translate-y-[-4px]`.

Breakpoints are Tailwind-parity mobile-first (`sm` 640 … `2xl` 1536).
`before:`/`after:` auto-inject `content:''`. Full-property brackets
(`[property:value]`) compile only through an explicit
`ARBITRARY_PROPERTY_ALLOWLIST` — `content`, `cursor`, `background-image`, `--*`
are rejected to prevent URL injection and variable bleed. Everything passes
through `Sanitizer` in strict mode.

**This is why `apply-change` bans flat visual attributes.** Per-block styling
lives in `className` only, and the JIT grammar is something an LLM already knows
how to write — Tailwind is in every model's training data. Cascade tier: GBS
classes sit below block attributes and above block defaults.

`Engine` yields entirely to `spectra-blocks-pro` when Pro is active (Pro owns
post-meta filtering, block defaults, preview builder, editor inspector) and
provides only the `ClassRegistry` for Pro to consume — no duplicate CSS. On
Pro-less "ERA sites" the Engine renders the utility CSS itself. There is also a
`GenCssOrphanStripper` (467 lines) for classes no longer referenced.

---

## 11. Guardrails, enumerated

Layered, and each layer's comment names the gap the layer above left open.

**1. Protected options** (`classes/security/protected-options-filter.php`).
`pre_update_option_<key>` filters on eight keys, refusing mutation by returning
`$old_value` (WP's documented cancel semantics) while an MCP request is in
flight. `Rest_Api::handle_mcp_request` sets the flag on entry and clears it in
`finally`, with `register_shutdown_function` as the fatal-path net.

Keys: `siteurl`, `home`, `admin_email`, `db_version`, `blog_charset`,
`users_can_register`, `default_role`.

The docblock explains why this exists *in addition to* the brain's registry and
the CLI verifier: neither covers custom-plugin REST/AJAX endpoints that call
`update_option('siteurl', …)` internally, `options.php` posts wrapped by a plugin
namespace, or future abilities shaped differently than the brain's extractor
recognizes. **This is the one place WordPress core itself observes every option
write.** Adding a key covers every write surface at once.

`template` and `stylesheet` are deliberately **not** protected, with the reason
recorded: `wp theme activate` is explicitly allowed and approval-gated, and it
calls `switch_theme()` which writes both — protecting them would silently no-op
the switch and the handler would report false success. No MCP tool exposes raw
option writes for those keys anyway.

Mirrors the brain's `runtime/protectedResources.ts::PROTECTED_OPTION_KEYS` and
`RunWpCli::$protected_options`. The lockstep note: adding a key here without the
brain is fine (defence-in-depth strengthens); *removing* one leaves a gap.

**2. WP-CLI denylist** (`trait-security-verifier.php`). Refuses:
- Shell operators `&&`, `||`, `;` — the native dispatcher's tokeniser treats them
  as ordinary tokens, so a chained command would silently run only the first
  sub-command and discard the rest. Rejected upfront "so the model learns to
  issue one command per call."
- `eval`, `eval-file`, `shell`, `package`, `server` — arbitrary code execution.
- `db query|import|drop|reset` — SQLi prevention.
- `option add|patch` — `update` is idempotent and creates when missing; `patch`
  does array-key surgery on serialized options, "a sharp edge that's almost never
  the right path for an AI agent."
- `user create|update|delete`, `user meta add|update|set|delete` — account
  management has reauth, email-confirmation, password, and security-plugin hooks
  that generic automation must not bypass. Read-only user discovery stays.
- `user add-role|remove-role|set-role` for `administrator`/`super-admin`, and
  `add-cap`/`remove-cap` for 16 admin-class capabilities — privilege escalation
  and lockout. Non-admin role changes pass through to dispatch.

  ```
  manage_options  install_plugins  activate_plugins  delete_plugins  edit_plugins
  install_themes  switch_themes    edit_themes       delete_themes   unfiltered_html
  create_users    delete_users     edit_users        promote_users
  manage_network  manage_sites
  ```

  `unfiltered_html` is the non-obvious inclusion and the right call — it is the
  capability that turns "edit a post" into "inject arbitrary script".

**3. Rate limiting** — 100/min per user+ability.

**4. Approval gating** — `tool_type` × `read_only_actions`, evaluated on the brain.

**5. dry_run** — auto-injected into every destructive schema.

**6. Editor lock guards** — the seven in §9.

**7. Snippet lint** — §12.

**8. JIT sanitizer** — property allowlist, `var()` rejection, URL hardening.

**9. `ImportTextureGate`** — disables `wptexturize` on imported pages so authored
characters render verbatim (no `'`→`’`, `--`→`—` rewrites that shift text wrap and
break pixel fidelity).

---

## 12. Code snippets — how the agent adds persistent behaviour

`wp-content/zip-ai-snippets/<slug>/` holding `snippet.php`, `snippet.js`,
`snippet.css` (filterable via `zip_ai_snippets_base_dir`). Six classes,
~4,800 lines total.

Per file type, configurable: **hook** (which action), **priority** (1–99),
**scope** (`frontend|admin|everywhere|login`), **conditions** (structured
targeting).

Executor guardrails (`snippet-executor.php`): path containment before
`include`/`file_get_contents`, SHA-256 integrity verification, **auto-disable on
fatal error** (shutdown handler + a file→slug map for deferred callbacks), safe
mode via URL param + secret key, per-snippet error isolation. Plus a trace mode
(`?zip_ai_snippet_trace=1`, admin only, per-user meta) that records why each
snippet did or didn't fire into a 50-entry per-slug ring buffer and emits HTML
comments into the page source.

### Snippet_Lint — a guardrail against a specific model failure

715 lines whose only job is to stop the agent embedding targeting logic in
snippet *code* instead of the structured `conditions[]` field.

The failure mode, quoted from the header: when the agent slips up — or a schema
rejection elsewhere makes it think `conditions[]` "doesn't work" — it falls back
to wrapping the body in `if ( ! is_home() ) return;` or
`if ( get_the_ID() !== 2805 )`. Once that lands in HEAD it survives migrations,
leaks into versions, and is **invisible at the manifest layer**: the executor
sees a "global" snippet and runs it everywhere while the body silently filters.

So it's blocked at write time, with a token-based scan (comments and strings skip
automatically) over a map of 17 forbidden functions → the equivalent
`conditions[]` shape:

```
is_home       → conditions:[{type:'page',operator:'is',value:'home'}]
get_the_ID    → conditions:[{type:'post',operator:'is',value:'<id>'}]
wp_is_mobile  → conditions:[{type:'device',operator:'is',value:'mobile'}]
is_admin      → execution.<type>.scope = 'admin' | 'frontend'
```

The stated author intent: *"if a snippet wants targeting, it MUST come through
`conditions[]` — code is for what to do, not where."* Kill switch and extension
filters provided.

Also present: `Snippet_Versions` (710 lines, version history), `Snippet_Store`
(1,344), `Snippet_Conditions` (492, with `evaluate_with_trace`),
`Snippet_REST_API` (1,446).

`Plugin_Abilities_Toggler` listens for `activated_plugin` and enables MCP
abilities for mapped slugs regardless of activation path — server-side ability,
browser-proxied REST, admin UI, or WP-CLI. Idempotent and slug-map gated.

---

## 13. Two paths the chat loop doesn't own

### Quick Edit (`assets/js/editor/quickedit.js`, 981 lines)

Explicitly **separate from the agent loop**: streams from
`/api/agent/inline-edit/*`, where the brain runs a *single* LLM call with no
`runTurn`, and applies straight to the block via `setAttributes` — so it lands in
native Gutenberg undo.

Registers itself via `addFilter('editor.BlockEdit', …)`; nothing calls into it.

Block-aware: each block type exposes only the operations that fit it. Ten text
intents (rewrite, improve, shorten, expand, punchier, simplify, professional,
friendly, grammar, humanize), five primary and five behind "More tones & styles".

Field discovery is mostly automatic — attrs with `source: html|rich-text|text`
covers core, `uagb/*`, Kadence, most builders; a plain string attr named `text`
covers Spectra's convention. `TEXT_MAP` is a last-resort override for the three
blocks neither rule finds. Image blocks are auto-detected by an attr with
`source: attribute, attribute: 'src'` and an `img` selector — which safely
excludes video/audio/embed, whose `src` selector isn't `img` — with `IMAGE_MAP`
covering CSS-background blocks (`core/cover`, `uagb/image`).

### Site Scanner (`classes/core/site-scanner.php`, 721 lines)

A deliberately **dumb data pipe**: collect raw WordPress data, POST it to
`{brain}/site-scan`, and let the brain own extraction, LLM analysis, and fact
storage. The header's reasoning: "The plugin is a dumb data pipe — easy to
maintain, no intelligence to update."

Intended triggers: `transition_post_status` → publish (skipping revisions and
autosaves), `activated_plugin`, `deactivated_plugin`, `switch_theme`. Debounced
to once per 5 minutes via transient. Fires a non-blocking self-request
(`timeout: 0.01, blocking: false`) at `POST /zip-ai/v1/site-scan`, no WP-cron
dependency.

**⚠️ Finding: the event hooks are not actually registered.** `loader.php:86`:

```php
// \ZipAI\MCP\Classes\Core\Site_Scanner::register_hooks(); // TODO: class not yet committed.
```

The class *is* committed (721 lines, and `plugin_deactivated()` calls
`Site_Scanner::unschedule()` unguarded). So in v0.0.7 the automatic
memory-enrichment scan never fires on publish, plugin change, or theme switch —
only a manual `POST /zip-ai/v1/site-scan` works. Either the comment is stale and
the line should be uncommented, or the feature is intentionally dark. Worth
flagging if you're using this as a reference implementation.

The privacy policy text (`Plugin::add_privacy_policy_content`, registered via
`wp_add_privacy_policy_content`) is unusually specific about the boundary, and
matches what `collect()` actually gathers:

- **Sent:** chat messages + responses; page titles, post counts, active plugin
  names, active theme, content categories; site title/tagline/language/domain;
  e-commerce *counts* and categories, currency, whether reviews are enabled.
- **Not sent:** page/post body text, customer or visitor PII, passwords or
  payment details, visitor emails, analytics/traffic.

Named subprocessors: Google Gemini and Anthropic Claude. "Clear Site Memory" is
offered as the user-facing deletion control.

---

## 13b. Prompt → result: every flow, and how many there are

**Boundary first.** Prompt assembly, planning, and model selection happen on
`brain.zipwp.com` and are not readable from here. What follows is the *plugin's*
side: the discriminators it sends, the endpoints it calls, and the executors it
provides. Not speculation about brain internals.

### The headline: there is no per-situation routing

"Create a page", "add a section", "edit this heading" are **not** separate flows.
The chat surface is a single endpoint. What varies is two booleans in the context
payload.

Endpoints extracted from `assets/js/dist/chat-assistant.js` + chunks:

```
/agent/chat/stream                 the turn
/agent/turn/…                      turn ops
/agent/rpc-reply                   browser tool replies (from the bridge)
/agent/plan/status                 credits / plan
/agent/tool-permission             approval gate
/agent/session/{latest,list,history,clear,clear-all}
/agent/llm-models  /agent/llm-settings
/agent/connected-sites/disconnect
```

SSE event names, same source:

```
turn_snapshot   turn_in_progress   turn_complete
tool_call_start tool_call_update   tool_call_result
assistant       done               error
session_change  session_id         plan_limit_exceeded
```

### Three engines

| Engine | Endpoint | Model calls | Applies via |
|---|---|---|---|
| **One-shot** | `/inline-edit/stream`, `/inline-edit/image` | **1**, explicitly no `runTurn` | `setAttributes` — native undo |
| **Agent loop** | `/agent/chat/stream` | N per turn, `tool_choice: auto` | MCP abilities + `js_rpc` editor tools |
| **Service** | none — server-side | brain-internal | `zipai/run-rest-request`, hidden from the model |

### Engine 1 — agent loop. 3 modes.

Discriminated entirely by `editor_context`:

| Mode | `is_block_editor` | `page_wide` | Reachable tools |
|---|---|---|---|
| Dashboard | `false` | — | 18 server abilities |
| Editor, page-wide | `true` | `true` | + 6 `editor/*` js_rpc tools |
| Editor, scoped | `true` | `false` | same 6, **`scope_lock` stamped** |

`page_wide` is derived, never asked for — `!hasLiveSelection` (§8). Selection scopes;
deselection widens.

Sub-signal, not a mode: `repeated_children` + `last_child_client_id` route "add one
more" to a child clone rather than a new section.

Execution modes (`rest_api` / `js_hook` / `js_rpc` / `hybrid`) are **orthogonal** —
they describe *how* a tool runs, not which situation is in play. All four appear in
the shipped bundle.

### Engine 2 — Quick Edit. 3 shapes.

Shape decided by block structure, not by the prompt
(`quickedit.js:808-833`):

| Shape | Condition | Behaviour |
|---|---|---|
| Leaf text | own text fields, no descendant text | 1 target, 10 intents |
| Section | has editable text descendants | fan out to all descendants, batched |
| Leaf image | `imagePrimary` | AI generate (`/inline-edit/image`, base64) + stock search |

The mutual exclusion is deliberate:

```js
const imagePrimary = !!imageDesc && !hasDescendantText;
```

A container carrying both an image and descendant text → text rewrite wins, image
panel suppressed, because "there the section text-rewrite is the point."

Section fan-out is bin-packed so each call fits:

```
MAX_SECTION_TARGETS  = 80      total cap — a whole-page selection stays per-block
MAX_BATCH_TARGETS    = 40      count cap per call
SECTION_COST_CEILING = 24000   est-token cap per call
BATCH_GAP_MS         = 300     eases provider rate limits
est = ceil(chars/2) + count*64 + 4096
```

That estimator **must equal** the brain's `EST_CEILING` in
`workers/brain/src/http/routes/inlineEdit.ts` — "same value + same formula means a
batch the client builds is exactly one the server accepts (no drift)." A cross-repo
invariant held by convention, which is the fragile part.

Targets are collected by `collectTargets`, which walks the block's own text fields
*plus* every descendant's, keyed `clientId + '::' + field` — so a leaf (1 field), a
multi-field block (`uagb/info-box` → prefix + title + desc) and a container (all
descendants) are one uniform list.

### Whole-page creation is not a tool loop

There is **no page-creation ability** among the 18. Page building runs as brain-side
services — `PageDeliveryService`, `ParallelPageBuilderService`,
`BuildPageFromPatternsTool`, `DesignTokenService` — writing into WordPress through
one ability that the model never sees:

```php
$this->id = 'zipai/run-rest-request';
$this->meta['visibility'] = 'internal';   // Laravel filters it before tools/list
```

**The LLM edits; a service builds.** `Parallel` in that service name implies
concurrent section generation, which a browser-mediated tool loop could not do.

### Engine 3 — Spectra legacy. 2 flows.

Different plugin, no agent loop (§14).

| Flow | Path | Model role |
|---|---|---|
| Design Library import | `wp_ajax_ast-block-templates-regenerate` → `ai/v1/content` | copy only; layout human-designed. Cached per category, shared across "club" |
| Stock images | `zipwp-images/v1/images` → `api.zipwp.com` | none — search proxy, `pexels` default |

### The count

| Engine | Flows |
|---|---|
| Agent loop | **3** — dashboard · editor page-wide · editor scoped |
| Quick Edit | **3** — leaf text · section fan-out · leaf image |
| Spectra legacy | **2** — template + AI copy · stock images |
| **Prompt → result total** | **8** |
| Server-side page build | 1 — not prompt-driven from the plugin |
| Background | 1 — site scan (hooks commented out, §13) |

**8 prompt→result flows across 3 engines** — and the agent loop's 3 are one codepath
varying by two booleans. The apparent breadth is context fields, not branches.

---

## 14. Spectra Blocks' own AI features (the older generation)

Bundled under `lib/`, versioned independently, and architecturally unrelated to
zip-ai's agent loop. `class-spectra-blocks-loader.php` does global version
negotiation (`load_versioned_lib`) so whichever copy of a shared library is
newest wins across Brainstorm Force plugins — including zip-ai itself, which can
ship *inside* Spectra as `lib/zip-ai/`.

### 14a. Design Library + AI content fill (`lib/gutenberg-templates/`)

Not generative layout. **Human-designed templates with AI-written copy.**

1. Onboarding collects business details → `zipwp_user_business_details`:
   name, description, category, address, phone, email, social profiles,
   language, plus 20 chosen stock images (`AST_BLOCK_TEMPLATES_IMAGE_COUNT`).
2. `wp_ajax_ast-block-templates-regenerate` → `Ai_Content::get_ai_content()`
   POSTs those details to `{library}/wp-json/ai/v1/content` and caches the
   response per template category in `ast-templates-ai-content`.
3. Categories are grouped into "clubs"; generated content is shared across every
   category in the same club, so one generation serves several.
4. On import, `block-editor.php` walks the parsed block tree per block type —
   `parse_spectra_container`, `parse_spectra_infobox`, `parse_spectra_image`,
   `parse_spectra_gallery`, `parse_spectra_google_map`, `parse_spectra_form`,
   `parse_spectra_social_icons`, `parse_spectra_v3_container`,
   `parse_core_image`, `parse_featured_image` — and swaps placeholders for real
   values: images by a walking `Images::$image_index`, the map address, the form
   recipient email, social icons. `replace_contact_details()` substitutes
   `#address`/`#phone`/`#email`/social tokens.
5. Images download in the background via
   `wp_schedule_single_event → ast_templates_download_selected_images`, with the
   id map stored in `ast_block_downloaded_images`.

Supporting REST routes under `ai/v1`: `description`, `page-description`,
`keywords`, `images`, `blocks`, `category`, `sites`, `favorite`, `settings`,
`license`, `revoke-access`, `initialize-setup`. Auth is
`Bearer <decrypt(zip_token)>`; every handler independently re-verifies the
`X-WP-Nonce` and requires `manage_ast_block_templates`.

`spectra-ai-block.php` registers a `gutenberg-templates/spectra-ai` wrapper
block and, via `block_editor_rest_api_preload_paths`, wraps the content of any
new post that contains no block delimiters — normalizing legacy/classic content
into one block first (stripping `<p>` tags, converting smart quotes, balancing
tags).

### 14b. Stock images (`lib/zipwp-images/`)

`POST /wp-json/zipwp-images/v1/images` proxies `api.zipwp.com/api/images/`.
Params: `keywords`, `per_page` (default 20), `page`, `orientation`, `color`,
`filter` (`newest|popular`), `engine` (default `pexels`, per-engine filter
normalization). `edit_posts` + explicit `X-WP-Nonce` verification.
`wp_ajax_zipwp_images_insert_image` sideloads a chosen image into the media
library.

### 14c. The handoff to zip-ai

Spectra's dashboard has an AI area that installs and activates zip-ai over AJAX
(`install_zip_ai`, `activate_zip_ai`, each with its own nonce and the matching
capability — `install_plugins` / `activate_plugins`), checks authorization
(`zip_ai_verify_authenticity`), reads module status, and deep-links into the
assistant via `admin_url('index.php?zipwp_open_assistant=1')` — which the bridge
consumes, strips from the URL with `history.replaceState`, and uses to
auto-open the panel.

Split cleanly on purpose, per the readme: core plugin, all blocks, and all
extensions are free with no account; AI requires a ZipWP account and runs on
credits *including for Pro users*.

---

## 14b. What is not in the artifact

Two observations about the shipped plugin, both relevant to using it as a reference.

**It ships no tests.** No jest config, no `*.test.js` / `*.spec.js`, no `phpunit.xml`,
no `package.json` at all — verified across the whole plugin. That is notable because
the code repeatedly refers to testing as a design driver:

- `core/rpc-dedup.js` exists as a separate 49-line dual-mode module (browser global +
  CommonJS) for the stated reason that the idempotency decision should be *"unit-tested
  instead of living only inline in the browser IIFE."*
- `apply-change/handler.js`, `get-context/handler.js`, `scripts/handler.js` and
  `editor-shared-utils.js` all end with `if (typeof module !== 'undefined' && module.exports)`
  blocks described as a "test-only surface".
- Several comments reference behaviour "under jest".

So the harness exists somewhere — a monorepo, CI, a private package — but not in the
distributed artifact. The consequence for a reader: the reasoning in those files is
well-earned and specific, and **none of it is verified by anything you can run.** The
most safety-critical code in the plugin (1,383 lines of `apply-change` with seven
guards, and the duplicate-apply decision) is in that category.

**One documented feature is wired off.** `Site_Scanner` is 721 lines, is described in
the plugin's own privacy-policy text, and its `register_hooks()` call is commented out
in `loader.php:86` with `// TODO: class not yet committed` — while the class is
committed and `plugin_deactivated()` calls `Site_Scanner::unschedule()` unguarded. §13
covers it. Whatever the intent, it is a documented capability that does not fire, and
nothing in the plugin would have caught that.

Neither observation undermines the architecture, which is the thing worth reading it
for. Both bear on how much of it to trust without re-deriving.

---

## 15. Reading this against `styble-ai`

The two designs answer the same question and disagree about where correctness
lives.

| | **styble-ai** | **ZIP AI** |
|---|---|---|
| Model call | in-plugin, BYO key | remote SaaS, credits |
| Contract | `emit_layout` tool schema, generated from `catalog.json` | MCP `tools/list`, generated from registered abilities |
| Correctness owner | `class-validator.php`, 31 codes, never coerces | brain zod + 7 browser-side guards + WP's own lock selectors |
| Failure handling | one corrective retry with validator errors | partial apply, `failed[]` by index, `unknown_attrs`, verify-iterate |
| Write path | validated tree → REST → applier mints `uniqueId` | live `wp.data` dispatch, Gutenberg mints ids |
| Styling | block attributes from brand context | `className` tokens → Tailwind-parity JIT |
| Whole page | plan → one request per section | agent loop, N tools per turn |
| Editing existing content | not yet (phases C/D) | the primary mode |
| Offline testable | fully — 7 scripts, no WP, no key | not at all |

**What's worth stealing, in rough order of value:**

1. **`unknown_attrs` in the reply.** Partitioning attributes against
   `getBlockType().attributes` and *telling the model which keys were dropped*
   converts a silent no-op into a learnable error. The catalog already knows
   every valid attribute per block — the validator could return this instead of
   just rejecting. This is the single highest-leverage idea in the codebase.
2. **Fail-safe `tool_type`.** Any classification that gates approval should
   default to the *restrictive* value, so a forgotten override can't open a hole.
   Their comment documents exactly that bug.
3. **Error messages addressed to the model.** The `out_of_scope` text says what
   to do instead. Validator codes could carry the same remediation.
4. **Scope lock from real editor state, not the prompt.** `page_wide =
   !hasLiveSelection` can't be defeated by a coincidental phrase.
5. **Live attributes over serialized content for labels.** Their note about
   stale `originalContent` → wrong section → wrong id applies directly to
   `class-page-planner.php`'s reuse flag and any uid-addressing scheme.
6. **The idempotency marker recorded *before* the mutation.** If styble-ai's
   section pipeline ever gets retried or replayed, `IN_FLIGHT_MARKER` is the
   right shape: an uncertain reply that forces verification, not a blind re-apply.
7. **Ability naming enforced by regex.** 24 canonical verbs and a `WP_DEBUG`
   `trigger_error` makes tool names guessable — cheap, and it compounds.
8. **`get_output_schema()`.** Declaring the response contract as JSON Schema, not
   prose, and forwarding it as `outputSchema`.
9. **Snippet_Lint's shape.** When a model repeatedly does the wrong thing, block
   it at write time *and* hand it the correct construct in the error. Generalizes
   to any "the model keeps hand-rolling what the schema already offers" problem.
10. **The credential split.** If styble-ai ever gets a hosted proxy, the
    filesystem+DB key derivation and the server-to-server credential push are
    both directly reusable — and the removed `wpAuthorizationHeader` is a
    documented mistake worth not repeating.

**What not to copy:** the browser is a required participant in the write path.
Their editor tools cannot run headless — which is why there's a 20ms-precision
timing invariant, a sessionStorage dedup map, a capability handshake, and a
bounded-retry reply POST, all to make a network round-trip through a browser tab
behave transactionally. styble-ai's `class-page-applier.php` does the same
mutations headless in PHP. That is a real advantage; keep it.

Also note what carries no comments in their codebase and heavy comments in the
risky parts — the density of "here is the bug this prevents" annotations around
`apply-change`, `rpc-dedup`, and the protected-options filter is a decent proxy
for where an agentic write path actually hurts.

---

## Appendix A — file map

**zip-ai** (61 PHP files, ~12.3k lines PHP + ~6.5k lines JS)

```
zip-ai.php                    guard, composer autoload, zip_ai_load_library filter
loader.php                    Plugin: constants, DI, activation, privacy, CLI
classes/core/
  container.php               DI container (105)
  service-provider.php        abstract register/boot (51)
  helper.php                  settings, auth tokens, App Password lifecycle (804)
  utils.php                   sodium encrypt/decrypt, split key derivation (207)
  tool-registry.php           third-party tool registration, js metadata (278)
  tool-types.php              READ/WRITE/LIST/SEARCH/ACTION/DELETE (125)
  context-detector.php        screen/post context → window.zipwpMcpContext (214)
  product-context.php         screen → product slug for welcome suggestions (123)
  site-scanner.php            raw data → brain /site-scan (721) ⚠ hooks not wired
  validator.php               schema validation (87)
  response.php                success/error envelope (102)
  event-logger.php            per-call telemetry (74)
  RouteSchemaBuilder.php      REST route → JSON Schema (208)
  snippet-store.php           CRUD + manifest (1344)
  snippet-executor.php        load/run + integrity + auto-disable + trace (736)
  snippet-lint.php            forbids inline targeting (715)
  snippet-versions.php        version history (710)
  snippet-conditions.php      structured targeting + trace (492)
  plugin-abilities-toggler.php  activated_plugin → enable abilities (451)
classes/abilities/
  abstract-ability.php        base: rate limit, validate, dry_run, metrics (462)
  Ability_Loader.php          recursive autoload + ID format regex (233)
  zipwp-abilities.php         hooks wp_abilities_api_init (43)
  core/                       ExecuteRestRequest, RunWpCli (+3 traits), GrepSearch
  zipai/system/               12 files: plugins, themes, media, fonts, snippets
  zipai/builder/              ImportMedia, InstallBundledPlugin/Theme
classes/api/
  rest-api.php                JSON-RPC MCP endpoint + site-scan (551)
  snippet-rest-api.php        snippet CRUD (1446)
  ajax-handlers.php           OAuth callback, disconnect, media, setup gate (402)
classes/react/react-manager.php   enqueue + localize + containers (698)
classes/security/protected-options-filter.php   pre_update_option guard (190)
classes/services/media-service.php              (162)
classes/admin/snippet-admin.php                 (254)
classes/cli/cli-commands.php                    wp zip-ai … (170)
classes/imports/ImportTextureGate.php           disables wptexturize (106)
assets/js/core/
  wp-bridge-host.js           context, executeTools, rpc reply, loaders (1617)
  block-context-picker.js     hover "+" pin → chat context (539)
  popover-drag.js             (323)
  tool-hooks-registry.js      registerTool / executeToolHook (144)
  rpc-dedup.js                pure dedup decision, jest-testable (49)
assets/js/tools/editor/
  apply-change/handler.js     8 wp.data fns + 7 guards + partitionAttrs (1383)
  get-context/handler.js      live tree read, computed styles, ownership (414)
  styles/handler.js           get/set-styles over GBS (327)
  scripts/handler.js          get/set-scripts over spectraCustomJS (142)
  shared/editor-shared-utils.js   blockFingerprint, currentPostId (130)
assets/js/tools/spectra/utils.js    text extraction, DFS labelling (400)
assets/js/editor/
  editor-plugin.js            pinned sidebar button (84)
  quickedit.js                block-toolbar inline edit, separate path (981)
vendor/wordpress/             mcp-adapter, php-mcp-schema (WordPress AI Team)
```

**spectra-blocks** (AI-relevant only)

```
lib/gutenberg-templates/
  inc/content/ai-content.php            business details → ai/v1/content (434)
  inc/importer/plugin.php               import orchestration (2352)
  inc/importer/block-editor.php         per-block-type placeholder swap (581)
  inc/importer/images.php               image resolution (160)
  inc/importer/image-importer.php       (257)
  inc/importer/sync-library.php         (1069)
  inc/api/                              description, page-description, keywords,
                                        images, blocks, category, sites, favorite,
                                        settings, license, revoke-access
  inc/classes/ast-block-templates-zipwp-api.php   business search, languages (237)
  inc/block/spectra-ai-block.php        wrapper block for new/legacy posts (173)
lib/zipwp-images/
  classes/zipwp-images-api.php          image proxy + sideload (501)
  classes/zipwp-images-script.php       (155)
includes/GlobalStyles/
  class-jit-compiler.php                Tailwind-parity JIT (4140)
  class-class-registry.php              utility class table (5665)
  class-engine.php                      CSS output, yields to Pro (1094)
  class-rest-controller.php             /global-styles/* — zip-ai writes here (1402)
  class-sanitizer.php                   strict property allowlist (486)
  class-gen-css-renderer.php            SSOT renderer (422)
  class-gen-css-orphan-stripper.php     (467)
admin/ajax/class-common-settings.php    install_zip_ai / activate_zip_ai (677+)
classes/class-spectra-blocks-loader.php global versioned lib negotiation
```

## Appendix B — extension points

**zip-ai filters/actions**

```
zip_ai_load_library                     bool, skip the whole plugin
zip_ai_sslverify                        bool (also ZIPAI_MCP_DISABLE_SSL_VERIFY)
zip_ai_register_tools                   action, $tool_registry — third-party tools
zip_ai_tools_list_allowed_meta_keys     array — meta forwarded to the brain
zipai_brain_url                         string — override the brain endpoint
zip_ai_snippets_base_dir                string — snippet storage root
zip_ai_snippets_lint_enabled            bool — kill switch
zip_ai_snippets_lint_targeting_functions  array — extend the forbidden map
zip_ai_snippets_trace_active            bool — force trace mode
zip_ai_library_textdomain               string — Spectra syncs this
wp_abilities_api_init                   core hook — where abilities register
wp_abilities_api_categories_init        core hook — where categories register
```

**Constants**

```
ZIPAI_MCP_VERSION  ZIPAI_MCP_DIR  ZIPAI_MCP_URL  ZIPAI_MCP_FILE  ZIPAI_MCP_MENU_SLUG
ZIPAI_MCP_BASE_URL  ZIPAI_MCP_CREDIT_SERVER_API  ZIPAI_MCP_MIDDLEWARE
ZIPAI_API_BASE  ZIPAI_BRAIN_URL
ZIPAI_MCP_DEBUG  ZIPAI_MCP_DISABLE_SSL_VERIFY  ZIPAI_TESTING
ZIPWP_API                       (spectra-blocks)
AST_BLOCK_TEMPLATES_LIBRARY_URL AST_BLOCK_TEMPLATES_IMAGE_COUNT (=20)
```

**Options**

```
zip_mcp_settings                  auth_token, auth_token_server, zip_token,
                                  user_email, user_name, shared_secret,
                                  hmac_registered, app_password_authorization,
                                  app_password_uuid, app_password_user_id
                                  (all values sodium-encrypted)
zipwp_mcp_key_salt                DB half of the encryption key
zipwp_user_business_details       Spectra onboarding payload
ast-templates-ai-content          per-category generated copy cache
ast_block_ai_content_log          debug log
ast_block_downloaded_images       stock image id map
spectra_blocks_pro_gs_user_css    GBS payload (WP option = global scope;
                                  same-named post meta = page scope)
```

**Capabilities** — `manage_zip_mcp_assistant` (added to administrator on
activation), `manage_options` (all admin surfaces), `edit_posts` (default ability
capability), `manage_ast_block_templates` (Spectra template library).

---

*Written from source at `zip-ai` v0.0.7. Line numbers and counts are as read; the
Site_Scanner finding in §13 is the one place the code contradicts itself.*
