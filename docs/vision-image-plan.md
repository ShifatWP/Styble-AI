# Plan — Vision: build a layout from an uploaded/pasted design image

## Context
Users want to paste or upload a photo (a design mockup, screenshot, or reference)
and have the AI "see" it and build matching WordPress blocks, guided by their
prompt. Today the pipeline is text-only: prompt → provider → IR → serializer.
This adds an image as a second input to the generation call.

**Hard requirement:** interpreting an image needs a *vision* model that also
supports forced tool/function calling. Groq `llama-3.3-70b-versatile` is
text-only and will error on image input. Vision + tools models that work: Groq
`meta-llama/llama-4-scout-17b-16e-instruct` (free), Google `gemini-2.0-flash`
(free), Anthropic Claude, OpenAI GPT-4o. Provider-agnostic; a clear error is
surfaced when a text-only model is used.

Scope: image attach on the **sidebar "Compose with AI" generate flow** only. The
per-block toolbar edit stays text-only for v1.

## Approach

### 1. Client — `assets/editor.js`
- Module-scope `loadDownscaled(file, cb)`: FileReader → `Image` → `<canvas>`
  capped at 1024px longest side → `toDataURL('image/jpeg', 0.85)`.
- `Panel()`: state `image` / `imageName`; hidden `<input type=file accept=image/*>`
  via `useRef`. Attach UI after the textarea: "Add a design image" button, or a
  thumbnail row with filename + `×` remove when set. Persistent vision-model hint.
- `onPaste` on the textarea grabs a clipboard image.
- **Oversized guard (client):** reject an original file > 8 MB immediately with a
  notice; never send it.
- `onGenerate` includes `image` in the POST body when set. `Clear` also resets it.
- On error while an image is attached, append a "switch to a vision model" hint.
- New `image` icon + `.abc-attach*` / `.abc-thumb*` CSS in the global `STYLE`.

### 2. REST — `includes/class-rest-controller.php`
- Optional `image` arg (light string passthrough sanitize). Validate in
  `generate()` and return explicit errors — never silently drop:
  - not `data:image/...` → 400 `abc_bad_image`.
  - over ~8 MB → 413 `abc_image_too_large`.
- Routing: `selection` → `edit()` (text-only); else `generate($prompt,$context,$image)`.

### 3. Providers — optional `$image` on `generate()` only
- Anthropic: multimodal `content` array `[ {type:text}, image_block() ]`; new
  `image_block()` parses the data URL → `{type:image,source:{base64,media_type,data}}`.
- OpenAI-compatible: `$user` array `[ {type:text}, {type:image_url,image_url:{url}} ]`;
  `send()` already accepts array content. `edit()` unchanged.

### 4. Settings + presets
- Add a `gemini` preset (OpenAI-compat endpoint, `gemini-2.0-flash`, AI Studio key).
- Settings model description gains vision-model examples + a one-line note.

### 5. Docs + version
- `readme.txt` feature + vision requirement; bump `ABC_VERSION` → 0.4.0.

## Verification
- `php -l` all changed PHP; `node --check assets/editor.js`.
- Manual: Groq `meta-llama/llama-4-scout-17b-16e-instruct` (or Gemini
  `gemini-2.0-flash`) → attach a landing-page screenshot → "build this" → blocks
  resembling the mockup insert. Paste path works. Text-only model → clear error.
  > 8 MB file → instant "too large" notice.

## Out of scope (v1)
- Image attach in the per-block edit popover.
- Placing the uploaded photo as real media (separate "photo as content" feature).
