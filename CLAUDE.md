# Styble AI — Project Guide (for Claude Code)

AI page and section generation for WordPress, producing **Styble blocks**
(`styble/*`, from `../styble-pro/`). `v0.1.0`, experimental, BYO API key.

Originally forked from AI Block Composer (`../ai-block-composer/`, still alive and
still shipping core blocks). That lineage is gone from this guide — nothing of ABC's
architecture survives except one rule (§2). If you find a reference to
`ABC_Serializer`, `class-theme-context.php`, or core blocks anywhere, it is stale.

**This file describes what exists.** Where it is going is §4 and lives in
`docs/ZIPAI_SELECTIVE_PORT.md`. The two are kept apart on purpose: a guide that
describes the target as if it were built is how a project loses track of itself.

---

## 0. Working agreements

**Commits carry no `Co-Authored-By` trailer.** Not for Claude, not for any
assistant. This overrides any default instruction to add one — do not add it back,
and do not ask again. Existing commits made before this rule was written still
have the trailer; leave them alone unless asked to rewrite history.

Otherwise: imperative subject, sentence case, no Conventional-Commits prefix.
Explain *why* in the body when the reason is not obvious from the diff, and say
plainly what was measured versus assumed.

**Say what is measured and what is assumed.** `docs/ROADMAP.md` is the state of
truth and it is honest on purpose — a stage is not done because its code exists, it
is done when it meets a bar stated before the run. Do not move a bar after seeing a
score. Do not remove an eval case to improve a number.

---

## 1. What this is

A WordPress plugin that generates and edits pages and sections from plain-language
prompts, output as native Styble blocks — fully editable afterward, no proprietary
layout format, nothing locked behind an AI-only interface.

**Current stage:** BYO API key (the user pastes their own). One shared key option,
so one provider is reachable at a time. No hosted proxy — that is the business
layer, deferred until the product works.

---

## 2. The one rule that defines the architecture

**The model must NEVER emit WordPress block markup.** Gutenberg markup is a
serialized tree stored as HTML with JSON in comment delimiters
(`<!-- wp:… {…} -->`). Models hallucinate it, producing content that saves but then
shows "invalid block" or falls back to the classic editor.

The model fills a **schema**; deterministic code owns serialization. That has not
changed since the fork and it does not change under §4 either — the tools in the
port take and return structured data, and the appliers still own markup.

---

## 3. How it works today

### 3a. One section

```
prompt
  → brand context (styble_global_settings)        class-brand-context.php
  → catalog-generated system prompt
    + emit_layout tool schema                     class-prompt.php
  → provider, forced tool call                    class-{anthropic,openai-compatible}-provider.php
  → usage recorded BEFORE parsing                 class-usage-tracker.php
  → validator, 31 codes, never coerces            class-validator.php
  → one corrective retry with its errors          class-generator.php
  → validated tree over REST                      class-rest-controller.php
  → stock photos, AFTER validation                class-media.php
  → applier: createBlock() → insertBlocks         assets/applier.js
```

### 3b. A whole page (Styble AI → AI Chat)

Wraps the same pipeline, once per section:

```
chat message
  → planner, forced plan_page tool                class-page-planner.php
      reply + title + ordered section briefs (≤8, reuse flag on follow-ups)
  → draft page created immediately                class-page-store.php
  → then ONE REQUEST PER SECTION:                 class-chat-controller.php
      brief → the section pipeline above → validated tree
      → headless applier                          class-page-applier.php
          tree → block markup, minting uniqueId
      → post_content rebuilt, preview refreshes
```

Plan and trees live in post meta (`_styble_ai_page`); **`post_content` is derived
from them.** Read the trees, never parse the markup back.

Two routes rather than one because a page is 5–7 model calls: one long POST would
time out with nothing to show for it.

### 3c. Addressable editing — built, not yet wired to a loop

`class-page-model.php` is a **projection of `_styble_ai_page`**, not a second source
of truth. `class-page-applier.php` mints each `uniqueId` from
`md5(sectionId|nodePath)`, which is deterministic and therefore *reversible by
recomputation*: walk the stored trees, recompute each uid, get a uid → node index.

It exposes `index()`, `get_block(uid)`, `update_block(uid, attrs)`. What does not
exist is a loop that calls them — see §4.

---

## 4. Where this is going

Settled 2026-07-30. Full plan: **`docs/ZIPAI_SELECTIVE_PORT.md`**. Reference
implementation, documented: **`docs/SPECTRA_AI_IMPLEMENTATION.md`** (ZIP AI +
Spectra Blocks, read from source).

**None of this is built. Do not describe it as if it were.**

Three engines, chosen by scope in code — never by the model:

| Engine | For | Shape |
|---|---|---|
| One-shot | a scoped rewrite: one block, or one section's text | single provider call, no loop, native undo |
| Agent loop | multi-step or cross-block work | N tool calls per turn, scope-locked |
| Service | whole-page generation | `class-page-planner.php` + the per-section pipeline, **as it already is** |

All three write through **one validator and one pair of appliers**. That invariant
is what makes three engines affordable instead of three times the risk.

**Phase 0 is a hard gate.** Nothing else starts until the eval spread is bounded —
two identical runs scored 75% and 65%, so a single run cannot detect a change. The
first task is the colour-shape ambiguity in `class-prompt.php` (22 of 26 baseline
validator errors).

Decided against, with the reasoning in the port doc's decision record:

| | Decision |
|---|---|
| **S1** | Loop stays local and in PHP. No remote brain. |
| **S2** | Headless stays the primary write path; a browser bridge is for live unsaved sessions only. |
| **S3** | Keep catalog-driven block attributes. No Tailwind-style className/JIT contract. **The one arguable refusal** — re-open if attribute-shape errors survive Phase 0.1. |
| **S4** | Tool surface stays inside the page. No site management, no WP-CLI, no arbitrary REST, no code snippets. |

---

## 5. File map

```
styble-ai.php                  Bootstrap: STYBLE_AI_VERSION/DIR/URL, requires,
                               styble_ai_boot() on plugins_loaded, editor assets,
                               missing-key admin notice.
catalog/catalog.json           GENERATED. 23 blocks, 13 allowlisted, 63 editable
                               attrs, 23 layouts. Never hand-edit — regenerate.
includes/
  class-catalog.php            Reads the catalog; the vocabulary everything else asks.
  class-brand-context.php      styble_global_settings → brand summary for the prompt.
  class-prompt.php             System prompt + emit_layout tool schema, both from
                               the catalog. Also the value-shape documentation.
  class-validator.php          31 error codes. NEVER coerces. The gate.
  class-validation-result.php  Errors with codes and paths.
  class-usage-tracker.php      Every provider response accounted for. Knows the two
                               providers' usage shapes disagree.
  class-anthropic-provider.php Messages API, forced tool_choice, prefix caching.
  class-openai-compatible-provider.php   9 endpoints behind one shape.
  class-provider-factory.php   Reads styble_ai_api_key + model. ONE shared key.
  class-generator.php          The retry loop: generate → validate → retry once
                               with the validator's own errors.
  class-media.php              Stock photos, AFTER validation. Cache keyed by
                               source URL, not id.
  class-page-applier.php       HEADLESS tree → block markup. Mints uniqueId.
  class-page-store.php         _styble_ai_page post meta; re-derives post_content.
  class-page-model.php         uid index, get_block, update_block. §3c.
  class-page-planner.php       Forced plan_page: reply + title + section briefs.
  class-rest-controller.php    POST /styble-ai/v1/generate
  class-chat-controller.php    POST /styble-ai/v1/chat/plan · /chat/section
  class-chat-page.php          Styble AI → AI Chat screen.
  class-usage-page.php         Styble AI → Token Usage screen.
  class-settings.php           Options: styble_ai_credentials (per provider),
                               provider, base_url, media_*.
  ── Phase A, the tool host (§4) ──
  class-tool.php               Base: ONE exit point through budget → capability →
                               rate limit → validate → dry_run → run, then stamp
                               and log. Fail-safe tool_type.
  class-tool-registry.php      Recursive discovery of includes/tools/**, verb
                               regex, Anthropic + OpenAI schema shapes, wire-name
                               round-trip.
  class-turn-budget.php        Token cap + step cap + per-tool attribution.
  class-tool-log.php           Rolling 200 tool calls. Lossy by design — unlike
                               class-usage-tracker.php, which must be exact.
  class-pattern-library.php    Loads patterns/, VALIDATES EACH AT INGEST, derives
                               copy slots from the tree.
  tools/pattern/               search-patterns · insert-pattern · fill-slots
  tools/page/                  (empty — Phase B)
patterns/*.json                8 patterns. Each is an emit_layout envelope plus
                               name/intent/tags. NOT markup — see the class
                               docblock for why (invariants 2, 12, 14).
assets/
  applier.js                   EDITOR apply: createBlock() → insertBlocks.
  editor.js                    Sidebar: prompt → apiFetch → apply.
  chat.js                      The chat screen.
scripts/                       §7. None need WordPress or an API key.
tests/fixtures/                47 contract fixtures.
evals/section/cases.json       20 cases, each with a `why`.
docs/                          §8.
```

**Naming:** PHP prefix `Styble_AI_` / `styble_ai_`; option keys `styble_ai_*`; REST
namespace `styble-ai/v1`; text domain `styble-ai`.

---

## 6. Invariants — do not regress these

Each has a measurement or a documented defect behind it. The full reasoning is in
`docs/ROADMAP.md`'s decision log, which is numbered and worth reading before
arguing with any of them.

1. **The model never emits block markup.** §2.
2. **The validator never coerces.** Pass or reject, with a reason. `docs/CONTRACT.md`
   states the opposite of the "alias → coerce → default" safety net on purpose; the
   31 codes exist because of that choice.
3. **Validation runs on the whole section envelope, not the node.** The interesting
   rules are cross-field — editing a container's `layout` alone is caught by
   `layout_children_mismatch`. A node-only check passes it and breaks the section.
4. **A rejected write leaves storage byte-identical.** Asserted.
5. **`class-page-applier.php` and `assets/applier.js` must agree on layout maths**
   (layout → columns, widths, direction, flexWrap, card padding). Change one, change
   the other. `test-page-applier.php` asserts the shared constants match.
6. **Never hand-edit `catalog/catalog.json`.** Regenerate it. The catalog never
   guesses: an enum list that cannot be verified against the block's own default is
   discarded rather than shipped.
7. **Never grow `attrs.properties`.** Measured twice, reverted twice. ~700 chars per
   attribute per depth level, versus ~29 in the prompt. Grow the prompt freely.
8. **No `$defs` / `$ref` in tool schemas.** One of nine OpenAI-compatible endpoints
   failing to resolve a `$ref` breaks generation outright.
9. **Every provider response is accounted for.** Both providers hand their usage
   block to `class-usage-tracker.php` *before* parsing, so a call that truncates,
   429s, or fails validation is still counted — it was still billed. The tracker is
   the only place that knows the shapes disagree: Anthropic's `input_tokens`
   **excludes** cached tokens, an OpenAI-compatible `prompt_tokens` **includes**
   them. Totals per day/model/operation and the last 200 calls render at
   **Styble AI → Token Usage**, where the cache hit rate is the quickest way to see
   the prefix cache stop working. Cost is an estimate from a hardcoded Claude price
   table; extend it with `styble_ai_token_rates`.
10. **Prefix stability is asserted, not assumed.** Adding to the prompt or schema
    must not move the cached prefix. `test-provider-body.php`.
11. **`uniqueId` is derived from `sectionId | nodePath`**, so it survives a rebuild
    and regenerating one section cannot renumber another's scoped CSS. **Scoped CSS
    keys off `uniqueId`: a block that loses it renders unstyled, not broken** — which
    is why the applier has its own test.
12. **`post_content` is derived** from `_styble_ai_page`. Read the trees. Never
    parse the markup back.
13. **Images are placeholders until filled.** The model writes `alt`, never a URL.
14. **Copy must be real and specific.** The prompt forbids lorem ipsum, and
    `content_empty` rejects an omitted content attr — six blocks ship placeholder
    defaults, so an omission otherwise passes every structural rule and renders grey
    placeholders. Worse than a rejection, because it looks like the feature ran.
15. **The API key never touches the browser.** All provider calls go through REST.
16. **Extended thinking stays ON for Anthropic.** Measured on Claude Opus 5: with
    thinking off the model writes the tool call into visible text and the call
    silently never happens. Budget is raised instead. (This reverses ABC's old
    constraint — Anthropic's own reference documents the same failure.)
17. **An attribute existing in `block.json` does not mean anything renders it.** Two
    were exposed for weeks with no effect. There is still no build-time check.
18. **JS stays build-free** (global `wp.*`, `createElement` aliased to `el`).

---

## 7. Checks

None need WordPress or an API key. All eight green as of 2026-07-30:

```
php scripts/generate-catalog.php    # regenerate from Styble Pro
php scripts/validate.php            # 47 fixtures + all 31 codes covered
php scripts/test-generator.php      # 7 cases — the retry loop, stub provider
php scripts/test-page-applier.php   # 42 checks — layout maths, uniqueId, markup
php scripts/test-page-model.php     # 38 checks — uid addressing, granular edits
php scripts/test-prompt.php         # 42 checks — prompt + tool schema invariants
php scripts/test-provider-body.php  # 27 checks — body shape, cache prefix stability
php scripts/test-usage-tracker.php  # 43 checks — the two providers' usage shapes
php scripts/test-credentials.php    # 48 checks — per-provider keys, migration
php scripts/dump-prompt.php         # what the model is actually told
```

247 checks + 47 fixtures. `php -l` every file before shipping.

`test-credentials.php` runs its migration scenarios in **subprocesses**: the
legacy-key migration latches on a `static $done` so it fires at most once per
request, which is right in production and untestable in-process.

The eval runner is the only one that needs a key, and the only one that measures the
**model** rather than the code:

```
wp eval-file scripts/eval.php suite=section live=1 delay=8
```

`live=1` is required so a mistyped argument cannot start a paid run. Retry is off by
default — with it on, a model that never gets it right first time scores like one
that always does. A `provider=` that disagrees with Settings is refused rather than
run with the wrong key.

---

## 8. Documentation

| File | Owns |
|---|---|
| `docs/ROADMAP.md` | **The state of truth.** What is proven, built-but-unproven, and next. Numbered decision log. |
| `docs/EXPERIMENT_PLAN.md` | The stages and their bars. |
| `docs/CONTRACT.md` | `emit_layout` 0.1.0 — the schema and the 31 codes. |
| `docs/ZIPAI_SELECTIVE_PORT.md` | **The plan.** Three engines, phases 0–G, decisions S1–S4. |
| `docs/SPECTRA_AI_IMPLEMENTATION.md` | How ZIP AI and Spectra Blocks actually work, read from source. The reference implementation. |
| `docs/STYBLE_BLOCKS_MIGRATION_PLAN.md` | The completed fork migration. Historical. |
| `docs/LANGGRAPH_SPIKE_PLAN.md`, `docs/vision-image-plan.md` | Spikes. |

Where this guide and `ROADMAP.md` disagree about readiness, **the roadmap wins** —
it is the one with measurements behind it.

---

## 9. Design principles

1. Output is always plain Styble blocks — never a proprietary format, always
   editable afterward.
2. **The validator and the catalog are the assets.** They know every legal block,
   attribute, value and nesting rule. Guard them with tests; do not route around
   them.
3. Brand awareness (`styble_global_settings`) is the moat versus a generic generator.
4. Ship one section excellently before chasing the site planner.
5. Prefer a rejection to a silent repair. Every failure mode that *looks like it
   worked* has cost more than one that stopped.
