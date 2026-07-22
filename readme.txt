=== AI Block Composer (Experimental) ===
Requires at least: 6.4
Requires PHP: 7.4
License: GPLv2 or later

Generate WordPress native (core) block sections from AI prompts, inside the editor.

== What this experimental build does ==
* Adds an "AI Block Composer" sidebar to the block editor.
* You describe a section or layout; it is inserted as fully editable CORE blocks.
* Uses your own Anthropic API key (BYO key). No proxy — that comes later for the
  hosted/credits business model.

== Architecture (why it is reliable) ==
The AI never writes block markup directly (that format is fragile and models
hallucinate it). Instead:
  1. The AI is forced to fill a clean JSON schema via tool_use (structured output).
  2. A deterministic PHP serializer converts that JSON into valid core-block markup.
  3. WordPress's own parser validates the result before it reaches the editor.

== Files ==
* ai-block-composer.php            Bootstrap + asset enqueue.
* includes/class-serializer.php    JSON IR -> core block markup (the core; tested).
* includes/class-anthropic-provider.php  Messages API call, forced tool_use.
* includes/class-theme-context.php Reads theme.json so output matches the site.
* includes/class-rest-controller.php  /ai-block-composer/v1/generate + validation.
* includes/class-settings.php      BYO key + model picker.
* assets/editor.js                 Build-free sidebar (global wp.*, no JSX/webpack).

== Install ==
1. Zip the ai-block-composer folder (or upload it to wp-content/plugins/).
2. Activate it.
3. Settings -> AI Block Composer -> paste your Anthropic API key, pick a model.
4. Edit a page, open the sidebar (star menu, top right), describe a section, Generate.

== Extending ==
* Add OpenAI/Gemini: implement the same generate($prompt,$context) contract as
  class-anthropic-provider.php and switch on a setting.
* Swap BYO key for a metered proxy later without touching the serializer.
