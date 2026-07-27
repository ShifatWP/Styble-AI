# Migrating AI Block Composer to Styble blocks

**Status:** Plan — nothing below is built yet
**Date:** 2026-07-27
**North star:** [`styble-ai-architecture.html`](file:///Users/shifat/styble-ai-architecture/styble-ai-architecture.html)
**Current state:** ABC v0.1.0 generates **WordPress core blocks** (`core/group`, `core/heading`, `core/columns`…) via `ABC_Serializer`

> This document plans one change: **stop emitting core blocks, start emitting Styble
> blocks.** Everything else about ABC — the providers, the settings screen, the
> editor sidebar, the forced-tool-call pipeline — is sound and stays.

---

## 0. Decision first: one plugin, or two?

**This is the gate. Nothing else in this document matters until it is answered.**

A sibling plugin, `styble-ai`, already exists in this install and has built most
of the layer this migration needs:

| Capability | `styble-ai` today | ABC today |
|---|---|---|
| Styble block catalog (attrs, nesting, layouts), generated from source | ✅ `catalog/catalog.json` + `scripts/generate-catalog.php` | ❌ |
| `emit_layout` JSON contract + spec | ✅ `docs/CONTRACT.md` | ❌ (has its own core-block IR) |
| Validator, PHP **and** JS, verified identical | ✅ 29 error codes, 16 fixtures | ❌ |
| JS applier — tree → real Styble blocks via `createBlock` | ✅ `src/applier/` | ❌ |
| PHP `serialize_blocks()` applier | ✅ stub, byte-compare tooling | ✅ but core-blocks only |
| Anthropic + OpenAI-compatible providers | ✅ | ✅ **richer** (vision/image input) |
| Settings screen (provider/key/model/base URL) | ✅ | ✅ |
| Editor sidebar | ✅ basic | ✅ **richer** (image upload, edit mode) |
| Brand context from `styble_global_settings` | ✅ | ❌ (reads `theme.json` instead) |
| **Contextual editing of a selection** | ❌ | ✅ `edit()` path |

The two plugins are converging on the same product from opposite ends. Pick one:

| Option | What it means | Cost |
|---|---|---|
| **A — Consolidate into `styble-ai` (recommended)** | Port ABC's better UI (image upload, edit mode) and its vision-capable providers into `styble-ai`. Retire ABC. | Port the sidebar + `edit()`; delete `ABC_Serializer`. |
| **B — Consolidate into ABC** | Move `styble-ai`'s catalog, contract, validators and applier into ABC. Retire `styble-ai`. | Move ~2,500 verified lines; ABC inherits a Styble Pro dependency it does not currently have. |
| **C — Keep both** | ABC stays core-blocks, `styble-ai` does Styble. Two products. | No migration — this document is moot. |
| **D — ABC depends on `styble-ai`** | ABC keeps its UI, calls `styble-ai` for catalog/validation/apply. | Cross-plugin coupling and load-order risk, for no real gain over A. |

**Recommendation: A.** The hard, verified, hard-to-rebuild work (catalog
generation from two sources, dual-language validator parity, applier that matches
the container's own layout maths) lives in `styble-ai`. The easy-to-move work
(sidebar UI, an extra provider feature) lives in ABC. Move the easy part.

**The rest of this document assumes the migration happens — it is written to apply
under A or B.** Under A, "ABC" below means "the ported ABC feature inside
`styble-ai`". Under B, file paths move into `ai-block-composer/`.

---

## 1. The one rule (unchanged, and it already holds)

From the deck:

> **The LLM never writes WordPress block HTML.** It emits a validated JSON tree;
> PHP/JS serializes it to real blocks.

ABC already obeys this — `CLAUDE.md` §2 states it and the code follows it. **The
rule is not what changes.** What changes is what sits on the far side of the
serializer: Styble blocks instead of core blocks.

---

## 2. Why the serializer cannot simply be retargeted

`ABC_Serializer` builds markup by string concatenation — `heading()`,
`paragraph()`, `buttons()` each emit a `<!-- wp:core/… -->` string. That works for
core blocks because they are **static**: their markup *is* their content, and the
attribute set is small.

Styble blocks are different in four ways, each of which breaks that approach:

| Fact | Consequence |
|---|---|
| **Every Styble block is dynamic.** `save()` returns `<InnerBlocks.Content />` or `null`; PHP renders the frontend from attributes. | There is no markup to concatenate. Serialization is a comment delimiter plus children — nothing else. |
| **block.json carries 100–180+ attributes per block**, mostly shared `CommonAttributes`. | A hand-written mapper per block is not maintainable. The attribute set must be *generated* from source. |
| **Nesting rules live only in JS** (`src/blocks/<slug>/index.js` `parent:` + parent-side `allowedBlocks`), not in block.json. | Legal structure has to be extracted separately, or the model will produce trees Gutenberg rejects. |
| **Blocks assign their own `uniqueId`** in a mount effect, and scoped CSS keys off it. | Headless PHP serialization must generate `uniqueId` itself or every generated page loses its styling. |

So the migration is not "swap the block names in the serializer". It is "replace
the serializer with a catalog-driven applier". That is precisely what `styble-ai`
already built.

---

## 3. The contract: keep ABC's semantic IR, or adopt `emit_layout`?

ABC's IR is **semantic** — `{type: "heading", level: 1, text: "…"}`. `styble-ai`'s
`emit_layout` is **structural** — `{block: "styble/advanced-text", attrs: {advancedTextContent: "…", textHTMLTag: "h1"}}`.

| | Semantic IR (ABC today) | `emit_layout` (styble-ai today) |
|---|---|---|
| Model's job | Easy — 8 familiar concepts | Harder — real block names and attribute keys |
| Coupling to Styble | Low — a mapping layer absorbs churn | Direct — prompt regenerates from the catalog |
| Expressive ceiling | Capped at what the mapper implements | Anything in the allowlist |
| Reaching the deck's rich blocks (motion, masking, scroll) | Needs new IR verbs per feature | Widen the allowlist; prompt regenerates itself |
| Extra layer to build and maintain | **Yes** — IR → Styble mapper | No |

**Recommendation: adopt `emit_layout`, drop the semantic IR.** The deck's
differentiator is conversational editing of *Styble's rich blocks* — motion,
masking, scroll effects. A semantic IR is a permanent bottleneck on exactly that:
every new capability needs a new IR verb and mapper branch, where `emit_layout`
needs one line added to a generated allowlist.

The usual argument for a semantic IR — "it shields the model from attribute
churn" — is already answered: the system prompt and tool schema are **generated
from the catalog**, so they cannot drift from the blocks. Churn is absorbed by
regenerating, not by a hand-maintained mapper.

**Consequence:** `ABC_Serializer` is deleted, not ported. Its ~345 lines have no
equivalent in the new pipeline.

---

## 4. Target pipeline

```
prompt
  → brand context (styble_global_settings: colour slugs + type scale)
  → catalog-generated system prompt + tool schema
  → LLM with FORCED tool call → emit_layout JSON tree
  → validator (catalog-driven, PHP + JS, identical rules)
  → [invalid] one corrective retry seeded with the validator's own errors
  → applier:
       in-editor  → JS createBlock tree → insertBlocks
       headless   → resolve_attrs → serialize_blocks() → wp_insert_post()
  → real, editable Styble blocks
```

Two appliers, one contract — only the applier swaps.

---

## 5. What ABC keeps, replaces, gains

| ABC component | Fate |
|---|---|
| `class-anthropic-provider.php` | **Keep**, with fixes (§7). Contract is already right. |
| `class-openai-compatible-provider.php` | **Keep**, with fixes. Preset list is good. |
| `class-settings.php` | **Keep** as-is. Four options, provider dropdown — already the right shape. |
| `assets/editor.js` | **Keep** the UX (image upload, edit mode); rewire its calls to the new REST contract. |
| `class-rest-controller.php` | **Rewrite** — validation + corrective retry replace direct serialization. |
| `class-theme-context.php` | **Replace** with brand context from `styble_global_settings` — Styble's own palette and type scale, not `theme.json`. |
| `class-serializer.php` | **Delete.** Superseded by catalog + validator + applier. |
| — | **Gain:** block catalog, `emit_layout` contract, dual validators, JS applier, PHP applier. |

---

## 6. Phases

Each phase has a question, a build, and a done-when. No phase starts before the
previous one's done-when passes.

### Phase A — Consolidate

**Question:** is there one codebase with one contract?

| Build | Detail |
|---|---|
| Execute Decision 0 | Move code in the chosen direction. Delete the losing copy — do not leave two catalogs or two validators. |
| Regenerate the catalog | `php scripts/generate-catalog.php` against the current Styble Pro. |
| Verify parity | Validator fixtures pass; PHP and JS agree on every verdict. |

**Done when:** one plugin, one `catalog.json`, one contract doc, one validator pair
that agrees; `ABC_Serializer` is deleted and nothing references it.

---

### Phase B — Port ABC's sidebar

**Question:** does the better UI drive the Styble pipeline?

| Build | Detail |
|---|---|
| Port the panel | ABC's `editor.js` panel — prompt, examples, states — onto the `emit_layout` REST route. |
| Port image input | ABC's design-reference upload. Anthropic wants a base64 `image` block; OpenAI-compatible wants `image_url`. Both provider classes already implement this. |
| Surface validation errors | On rejection, list the validator's per-error `path` + `message`. Never fail silently. |

**Done when:** a prompt typed in the ported sidebar produces a Styble section; a
deliberately impossible prompt shows the validator's reasons instead of a blank
failure.

---

### Phase C — Contextual editing (ABC's head start, the deck's ⭐ moat)

**Question:** can a selected Styble section be edited conversationally?

ABC already has the *shape* of this — `edit($prompt, $context, $selection)` on both
providers. What it lacks is a Styble-aware target. This is the deck's Phase 4 and
the stated differentiator.

| Build | Detail |
|---|---|
| Page model | uid-keyed tree for the current selection. |
| Tools | `get_page` · `get_block` · `update_block(uid, attrs)` · `insert_block` · `move_block` · `delete_block`. Each validates against the catalog and respects the nesting map. |
| Loop | Tool-calling loop (`tool_choice: auto`); apply diffs via `updateBlockAttributes` / `replaceInnerBlocks`. |
| Confirm | Destructive ops (delete, replace non-empty inners) prompt first. |

**Done when:** "make the hero dark", "add a third feature column", "move the
testimonials above the CTA" each work on the selection without touching the rest
of the page.

---

### Phase D — Pattern library + fill (the Spectra lesson)

**Question:** does designer-pattern + AI-fill beat freeform on quality and cost?

The deck is explicit that the pattern library **is** the quality, and that freeform
is the fallback — not the other way round.

| Build | Detail |
|---|---|
| Pattern store | 3–5 hand-built Styble section trees (hero, features, CTA, pricing, testimonial) with labelled content slots. |
| Tools | `search_patterns(brief)` → pick; `fill_slots(patternId, slots)` → cheap content-only call. |
| Routing | Plan picks a pattern; freeform generation becomes the fallback when none fits. |

**Done when:** the same brief via pattern-fill is visibly better and cheaper than
freeform, and freeform still covers off-library briefs.

---

### Phase E — Headless pages

**Question:** can a full page be generated with no editor open?

| Build | Detail |
|---|---|
| PHP applier | `resolve_attrs` (reuse `CommonAttributes::get()` + `Att_Utils`) → `serialize_blocks()` → `wp_insert_post()`. |
| **`uniqueId` generation** | The known gap (§2). Per-block prefixes live at each `useUniqueId()` call site, not in block.json — the catalog generator must extract them, and uniqueness must be guaranteed without the editor's dedupe hook. |
| REST | `POST styble-ai/v1/pages` — validated tree in, `page_id` + URL out. |

**Done when:** a brief produces a full multi-section Styble page as a draft via
REST, with scoped CSS intact, and its markup byte-compares against the JS
applier's output modulo `uniqueId`.

---

### Phase F — MCP (distribution)

**Question:** can external agents build Styble sites?

Wrap the one executor (Phase C tools + Phase E connector) as a Streamable-HTTP MCP
server. Gate destructive tools.

**Done when:** Claude Code or Cursor can connect and build a Styble section.

---

## 7. Bugs to fix during the port

Found while reading the current provider code. **These are live defects in ABC
today**, not migration artifacts:

1. **`temperature` will 400 on current Claude models.**
   `class-anthropic-provider.php` sends `'temperature' => 0.7` on both `generate()`
   and `edit()`. Sampling parameters were removed on Claude Opus 4.7 and later and
   now return a 400. Delete the parameter on the Anthropic path; keep it on the
   OpenAI-compatible path, where it is still valid.

2. **Default model is behind.** `claude-sonnet-5` is the current default; prefer
   `claude-opus-5` for a generation task of this complexity.

3. **`max_tokens: 4096` is tight.** Thinking is on by default on current Claude
   models and shares the `max_tokens` budget with the response. A full section tree
   can truncate. Raise to ~16000.

4. **Do not disable thinking to work around (3).** On Claude Opus 5, disabling
   thinking has a documented failure mode where the model writes a tool call into
   its visible text instead of emitting a `tool_use` block — the turn succeeds and
   the call silently never happens. For a forced-tool pipeline that is the worst
   possible failure. Leave thinking on.

5. **`strict: true` is not available for this schema.** A block tree is recursive
   and structured outputs do not support recursive schemas. The validator is the
   enforcement layer — which is why the corrective retry matters.

---

## 8. Guardrails (inherited from the deck and the styble-ai experiment)

- **Explicit errors, never silent fallback.** An invalid tree surfaces its reasons; it is never coerced into something plausible.
- **Start with a small allowlist**: container, column, advanced-text, advanced-buttons, advanced-button, advanced-image, info-box, separator, icon-picker, icon-list(+item). Grow per phase. Motion/masking/scroll attributes come after Phase C.
- **Images are placeholders.** The model writes `imgAltText`; the user picks the media. Never invent a URL or attachment id.
- **Real copy, never lorem ipsum.**
- **Caps:** ≤8 sections, ≤6 columns per container, ≤8 blocks per column, prompt ≤2k chars.
- **The key never reaches the browser.** All model calls go through REST.
- **The applier owns correctness.** Anything the block's own editor logic would compute — layout, `columns`, per-column widths, `direction`, `flexWrap`, `uniqueId` — is computed by the applier and never trusted from the model.

---

## 9. Open decisions

| Decision | Default | Revisit at |
|---|---|---|
| Consolidation direction (Decision 0) | **Unanswered — blocks everything** | Now |
| Semantic IR vs `emit_layout` | `emit_layout` | Phase A |
| Python/LangGraph vs stay in-WP | Stay in-WP | Phase F |
| Provider matrix | Keep ABC's existing preset list | — |
| Managed proxy + credits | Out of scope | After the product works |
| Core-block output | Dropped once Decision 0 lands on A or B | — |

---

## 10. Decision log

| Decision | Choice | Why |
|---|---|---|
| Guiding doc | `styble-ai-architecture.html` | User directive |
| Core rule | Model → JSON, code → blocks | Unchanged from ABC's existing architecture; already correct |
| Serializer | Delete `ABC_Serializer` | Styble blocks are all dynamic with 100–180+ generated attributes; string concatenation cannot express them |
| Contract | `emit_layout` structural tree | A semantic IR caps the rich-block differentiator the deck is built around |
| Catalog | Generated from block.json + JS nesting extraction | Only complete source; block.json alone lacks nesting |
| Consolidation | Recommend folding ABC into `styble-ai` | The hard verified work is there; the portable work is here |
