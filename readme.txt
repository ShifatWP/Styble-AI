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
* Bring your own API key. Works with Anthropic (Claude) OR any OpenAI-compatible
  provider — including FREE ones like Groq and Gemini. No hosted proxy yet;
  that comes later for the credits/subscription business model.

== Building from a design image (vision) ==
Attach a screenshot or mockup in the sidebar (or paste an image into the prompt)
and the AI reconstructs it as native blocks. This needs a model that supports
BOTH vision and function calling:
  * Groq    meta-llama/llama-4-scout-17b-16e-instruct   (free)
  * Gemini  gemini-2.0-flash                            (free)
  * Claude  claude-sonnet-5
  * OpenAI  gpt-4o
Text-only models (e.g. llama-3.3-70b-versatile) will reject images. Images are
downscaled in the browser before upload; max 8 MB per image.

== Architecture (why it is reliable) ==
The AI never writes block markup directly (that format is fragile and models
hallucinate it). Instead:
  1. The AI is forced to fill a clean JSON schema via tool/function calling
     (structured output).
  2. A deterministic PHP serializer converts that JSON into valid core-block markup.
  3. WordPress's own parser validates the result before it reaches the editor.

== Setup guide ==

Step 1 — Install & activate
  1. Copy the ai-block-composer folder into wp-content/plugins/ (or upload a zip).
  2. Activate it under Plugins.

Step 2 — Pick a provider and get a key
  Any provider works as long as the chosen model supports function/tool calling.
  Free and recommended for experimenting:

    Provider    Cost        Suggested model              Get a key
    --------    ----        ---------------              ---------
    Groq        Free        llama-3.3-70b-versatile      https://console.groq.com/keys
    Cerebras    Free        llama-3.3-70b                https://cloud.cerebras.ai
    OpenRouter  Free tier*  meta-llama/llama-3.3-70b-instruct   https://openrouter.ai/keys
    DeepSeek    Cheap       deepseek-chat                https://platform.deepseek.com/api_keys
    Mistral     Free tier   mistral-large-latest         https://console.mistral.ai/api-keys
    Together    Paid        meta-llama/Llama-3.3-70B-Instruct-Turbo   https://api.together.xyz/settings/api-keys
    Anthropic   Paid        claude-sonnet-5              https://console.anthropic.com/settings/keys

  * OpenRouter has free model variants, but not every model there supports tools —
    stick to a Llama 3.3 70B instruct model.

Step 3 — Configure
  1. Go to Settings -> AI Block Composer.
  2. Choose your Provider from the dropdown.
  3. Paste that provider's API key (the "Get a key" link updates to match).
  4. Leave Model blank to use the provider's default (shown as the placeholder),
     or type a specific model id.
  5. (Custom provider only) enter the full chat/completions base URL.
  6. Save.

Step 4 — Generate
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
* "did not return structured layout data" — the model does not support function
  calling. Switch to Groq or Cerebras with a Llama 3.3 70B model.
* HTTP 429 / rate limit — free tiers throttle. Wait a moment and generate again.
  (An automatic retry loop is on the roadmap.)
* "invalid block" after insert — should not happen; markup is server-validated.
  If it does, regenerate and report the prompt.

== Files ==
* ai-block-composer.php                       Bootstrap + asset enqueue.
* includes/class-serializer.php               JSON IR -> core block markup (the core; tested).
* includes/class-anthropic-provider.php       Anthropic Messages API, forced tool_use.
* includes/class-openai-compatible-provider.php  Groq/Cerebras/OpenRouter/DeepSeek/
                                              Mistral/Together/custom, forced function call.
* includes/class-theme-context.php            Reads theme.json so output matches the site.
* includes/class-rest-controller.php          /ai-block-composer/v1/generate + validation.
* includes/class-settings.php                 Provider selector + key + model + base URL.
* assets/editor.js                            Build-free sidebar (global wp.*, no JSX/webpack).

== Extending ==
* Add another provider: implement the same generate($prompt,$context) contract as
  the existing providers and wire it into the settings dropdown + REST controller.
* OpenAI-compatible endpoints already work via the "Custom" provider — just paste
  the base URL, no code needed.
* Swap BYO key for a metered proxy later without touching the serializer.

== Security notes ==
* The API key is stored in wp_options as plaintext — fine for local/dev, resolved
  by the future proxy model. Do not commit your key; it never leaves the server
  (all LLM calls go through the REST endpoint, never the browser).
