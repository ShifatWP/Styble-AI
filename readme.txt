=== Styble AI (Experimental) ===
Requires at least: 6.4
Requires PHP: 7.4
License: GPLv2 or later

Generate Styble block sections from AI prompts, inside the editor.

Requires Styble Pro — the generated sections are Styble blocks.

== How it works ==
The model never writes block markup. It calls one tool, emit_layout, and returns
a JSON tree of sparse attributes naming real Styble blocks. That tree is checked
against a catalog generated from Styble Pro itself; if it fails, the model gets
its own errors back and one more attempt, and a second failure is reported
rather than patched. Only a tree that validated is applied, and the editor
builds it with createBlock() so every block fills its own defaults and mints its
own uniqueId. See docs/CONTRACT.md.

== What this experimental build does ==
* Adds a "Styble AI" sidebar to the block editor.
* You describe a section or layout; it is inserted as fully editable Styble blocks.
* Edit any existing block in place — select it, click "Edit with AI" in its
  toolbar, describe the change.
* Attach a design image (upload or paste) and the AI builds a matching layout —
  requires a vision-capable model (see below).
* Optional stock photos: point it at Pexels or Unsplash and generated images are
  filled from the description the AI wrote, downloaded into your Media Library as
  real attachments. Off by default; without it, images stay blank placeholders.
* Bring your own API key. Works with Anthropic (Claude) OR any OpenAI-compatible
  provider — including FREE ones like Groq and Gemini. No hosted proxy yet;
  that comes later for the credits/subscription business model.

== Building from a design image (vision) ==
Attach a screenshot or mockup in the sidebar (or paste an image into the prompt)
and the AI reconstructs it as native blocks. This needs a model that supports
BOTH vision and function calling:
  * Groq    meta-llama/llama-4-scout-17b-16e-instruct   (free)
  * Gemini  gemini-2.0-flash                            (free)
  * Claude  claude-opus-5
  * OpenAI  gpt-4o
Text-only models (e.g. llama-3.3-70b-versatile) will reject images. Images are
downscaled in the browser before upload; max 8 MB per image.

== Architecture (why it is reliable) ==
The AI never writes block markup directly (that format is fragile and models
hallucinate it). Instead:
  1. A catalog is generated from Styble Pro's own source — block.json for
     attributes, index.js for nesting rules, edit.jsx for uniqueId prefixes.
  2. The system prompt and the emit_layout tool schema are generated from that
     catalog, so neither can drift from the blocks.
  3. The AI is forced to call emit_layout and returns a JSON tree of sparse
     attributes — never markup.
  4. A validator checks the tree against the catalog. It never coerces: a bad
     tree is rejected with a code, a JSON path and a reason.
  5. On rejection the model gets its own errors back and one more attempt. A
     second rejection is reported, not patched.
  6. The editor builds the validated tree with createBlock(), so every block
     fills its own block.json defaults and assigns its own uniqueId.

There is no serializer. Every Styble block is dynamic — save() returns
InnerBlocks.Content or null and PHP renders the frontend from attributes — so
there is no markup to concatenate, and building blocks in the editor is what
makes defaults and scoped CSS work.

== Setup guide ==

Step 1 — Install & activate
  1. Install and activate Styble Pro first; Styble AI depends on it.
  2. Copy the styble-ai folder into wp-content/plugins/ (or upload a zip).
  3. Activate it under Plugins.

Step 2 — Pick a provider and get a key
  Function/tool calling is necessary but NOT sufficient. A section is a nested
  block tree, and smaller models emit malformed JSON for it — measured here,
  llama-3.3-70b-versatile on Groq produced a tool call Groq itself could not
  parse (a missing closing bracket), which fails before the tree ever reaches
  the validator. Prefer Claude; Gemini 2.0 Flash is the best free option and is
  also the free choice that handles image uploads.

    Provider    Cost        Suggested model              Get a key
    --------    ----        ---------------              ---------
    Groq        Free        llama-3.3-70b-versatile      https://console.groq.com/keys
    Cerebras    Free        llama-3.3-70b                https://cloud.cerebras.ai
    OpenRouter  Free tier*  meta-llama/llama-3.3-70b-instruct   https://openrouter.ai/keys
    DeepSeek    Cheap       deepseek-chat                https://platform.deepseek.com/api_keys
    Mistral     Free tier   mistral-large-latest         https://console.mistral.ai/api-keys
    Together    Paid        meta-llama/Llama-3.3-70B-Instruct-Turbo   https://api.together.xyz/settings/api-keys
    Anthropic   Paid        claude-opus-5              https://console.anthropic.com/settings/keys

  * OpenRouter has free model variants, but not every model there supports tools —
    stick to a Llama 3.3 70B instruct model.

Step 3 — Configure
  1. Go to Styble AI -> Settings in the admin menu.
  2. Choose your Provider from the dropdown.
  3. Paste that provider's API key (the "Get a key" link updates to match).
  4. Leave Model blank to use the provider's default (shown as the placeholder),
     or type a specific model id.
  5. (Custom provider only) enter the full chat/completions base URL.
  6. Save.

Step 4a — Generate a whole page (AI Chat)
  1. Go to Styble AI -> AI Chat.
  2. Describe the page: "Create a pricing page for a WordPress plugin with three
     plans."
  3. It plans the sections, then builds them one at a time. The checklist ticks
     over as each lands and the preview on the right refreshes with it.
  4. Keep chatting to revise the same page ("make the hero shorter", "add an
     FAQ"). Only the sections that change are rebuilt.
  5. The page is saved as a DRAFT. Open it in the block editor from the preview
     toolbar. Nothing publishes automatically.

  Note: one model call per section, so a 5-section page is 6 calls and takes a
  couple of minutes on a slower provider. A section that fails gets a Retry
  button rather than taking the page down with it.

Step 4b — Generate one section (editor sidebar)
  1. Edit any page or post.
  2. Click the green wand icon at the top-right of the editor to open the sidebar.
  3. Describe a section (e.g. "a hero for a coffee roaster: headline, one line of
     copy, two buttons, dark tone, full width"), pick a Tone, click
     "Generate & insert". Or click one of the "Try one of these" example cards.
  4. Blocks are inserted as real, fully editable Styble blocks. Nothing publishes
     automatically — you review every block.

Step 5 — Test prompt (full small page)
  Paste this to exercise most block types at once:
    Build a landing page for "Northwind Coffee Roasters" (small-batch Portland
    roaster): a dark full-width hero with headline + subcopy + two buttons; a
    3-column features section (Roasted Weekly, Direct Trade, Free Local Delivery);
    a light "Our Story" section with two paragraphs and an image placeholder; a
    testimonial with a quote and citation; and a dark full-width CTA band with one
    button. Real, specific copy — no placeholders.

== Troubleshooting ==
* "produced a malformed layout that <host> rejected" — the model emitted invalid
  JSON for the tool call, so the provider refused it before our validator saw it
  and the corrective retry could not help. Use a larger model: Claude, or Gemini
  2.0 Flash on the free tier.
* "did not call emit_layout" — the model does not support function calling.
  Switch to Gemini or Claude.
* "Request too large ... tokens per minute" — a free tier counting max_tokens as
  reserved. Groq allows 12000 TPM, which fits one request but not a request plus
  its corrective retry inside the same minute.
* "did not satisfy the Styble block contract, twice" — the sidebar lists the
  validator's reasons underneath. An attr_unknown or block_not_allowlisted means
  the model wanted something outside the v1 allowlist; widen it in
  scripts/generate-catalog.php and regenerate.
* "cannot read its block catalog" — run: php scripts/generate-catalog.php
* "ran out of output budget" — the tree was truncated mid-emit. Ask for a
  smaller section.
* HTTP 429 / rate limit — free tiers throttle. Wait a moment and generate again.

== Checks (no WordPress, no API key) ==
  php scripts/generate-catalog.php    Regenerate the catalog from Styble Pro.
  php scripts/validate.php            Contract fixtures; asserts every error code.
  php scripts/test-generator.php      The retry loop, against a stub provider.
  php scripts/test-page-applier.php   Headless layout maths, uniqueId, markup.
  php scripts/test-prompt.php         Prompt + tool schema invariants.
  php scripts/dump-prompt.php         Exactly what the model is told.

== Files ==
* styble-ai.php                               Bootstrap + asset enqueue.
* scripts/generate-catalog.php                Styble Pro source -> catalog/catalog.json.
* includes/class-catalog.php                  Read-only accessor over the catalog.
* includes/class-prompt.php                   System prompt + emit_layout tool schema.
* includes/class-validator.php                29 contract rules; never coerces.
* includes/class-generator.php                Provider -> validate -> corrective retry.
* includes/class-brand-context.php            Styble global settings -> prompt context.
* includes/class-anthropic-provider.php       Anthropic Messages API, forced tool_use.
* includes/class-openai-compatible-provider.php  Groq/Cerebras/OpenRouter/DeepSeek/
                                              Mistral/Together/Gemini/custom.
* includes/class-provider-factory.php         Settings -> the configured provider.
* includes/class-media.php                    Alt text -> stock photo -> attachment id.
* includes/class-rest-controller.php          /styble-ai/v1/generate; returns a tree.
* includes/class-page-planner.php             Chat message -> ordered section briefs.
* includes/class-page-applier.php             Headless: tree -> block markup + uniqueId.
* includes/class-page-store.php               Plan/trees in post meta; rebuilds post_content.
* includes/class-chat-controller.php          /chat/plan and /chat/section.
* includes/class-chat-page.php                The AI Chat admin screen (top-level menu).
* includes/class-settings.php                 Provider selector + key + model + base URL.
* assets/applier.js                           Validated tree -> createBlock() blocks.
* assets/editor.js                            Build-free sidebar (global wp.*, no JSX/webpack).
* assets/chat.js, assets/chat.css             The chat screen: transcript + live preview.
* docs/CONTRACT.md                            The emit_layout contract and its error codes.

== Extending ==
* Widen what the AI can build: add the block or attribute to the allowlist in
  scripts/generate-catalog.php and regenerate. The prompt, the tool schema and
  the validator all follow automatically — none of them are hand-maintained.
* Add another provider: implement complete( array $spec ) as the existing
  providers do and wire it into the settings dropdown + REST controller. A
  provider handles transport only; it never sees a Styble block.
* OpenAI-compatible endpoints already work via the "Custom" provider — just paste
  the base URL, no code needed.
* Swap BYO key for a metered proxy later without touching the contract.

== Security notes ==
* The API key is stored in wp_options as plaintext — fine for local/dev, resolved
  by the future proxy model. Do not commit your key; it never leaves the server
  (all LLM calls go through the REST endpoint, never the browser).
