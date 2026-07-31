# styble-ai vs styble-ai-demo — a comparison, and a verdict

Two implementations of the same goal exist in the org at once. This one
(`styble-ai`, this repo) and `styble-ai-demo`, which a teammate is actively building
on `origin/styble-ai-demo` in the `styble-pro` repo
(`https://github.com/ShapedPlugin/styble-pro.git`).

They are not small variations on each other. They disagree on the question this
whole plugin is built around — what happens when the model sends something wrong —
and they can't both be the org's answer going into Phase B (`docs/
ZIPAI_SELECTIVE_PORT.md`), the agent loop that both are racing toward.

This file exists so that disagreement is argued from evidence once, in one place,
rather than re-derived per conversation. Read alongside `docs/ROADMAP.md` (this
plugin's measured state) and `docs/ZIPAI_SELECTIVE_PORT.md` (this plugin's plan,
which a coordination decision here would revise).

**Scope note:** `styble-ai-demo` was read from a fetched branch, not run. Everything
attributed to it below is a direct quote or a grep hit against its source, not a
description of behaviour observed live. Line numbers are as read 2026-07-30 on a
single-commit orphan branch — treat them as approximate if the branch has moved.

---

## 1. Scale, as it stands today

| | `styble-ai` (this repo) | `styble-ai-demo` |
|---|---|---|
| PHP | 14,156 lines | 7,743 lines |
| — of which tests | 2,612 lines | 0 |
| JS | 1,756 lines | 0 |
| Blocks covered | 13, generated from Styble Pro | 8, hand-curated |
| Patterns | 8 | 2 |
| Test suites | 8 (247 checks + 47 fixtures) | none found |
| Agent loop | not built (Phase B) | built (`EditSession`) |
| MCP | not built (Phase F) | built (`McpServer`, 10 tools) |
| Git state | 6 commits on `ai-evals`, pushed | 1 commit, orphan branch, no merge base with `main` |

Read this table both ways. `styble-ai-demo` has the thing `styble-ai` doesn't yet
have — a working loop and MCP. `styble-ai` has the thing `styble-ai-demo` doesn't
have — verification and a measured baseline. Neither fact settles which core is
right; §2 is what settles it.

---

## 2. Four places they disagree

### 2a. Reject vs repair — the one that matters most

`styble-ai`: the validator never coerces. 31 error codes. A bad value is refused,
with a code and a path, never silently repaired. `docs/CONTRACT.md` states this as
the opposite of an "alias → coerce → default" design, on purpose. Design principle 5
in `CLAUDE.md`: *"Prefer a rejection to a silent repair. Every failure mode that
looks like it worked has cost more than one that stopped."*

`styble-ai-demo`: the opposite, stated as the design in `AttrResolver.php`'s own
docblock —

> "Runs on every node's attrs: maps editable aliases to real Styble keys, **drops
> unknown keys, coerces** to the declared type (uncoercible -> drop, render fills
> the default), and always mints a fresh page-unique `uniqueId`. **Never throws** —
> a bad value degrades to the block's default, never fails the page."
> — `src/Builder/AttrResolver.php:5-8`

Concretely, on the exact ambiguity `styble-ai`'s Phase 0.1 fix exists for — a colour
attribute that might be a plain string or a `{color:{style,...}}` object — the demo
resolver accepts either and normalises silently:

```php
// src/Builder/AttrResolver.php:410
$hex = is_array( $val ) ? ( $val['color']['solidColor'] ?? '' ) : $val;
return '' !== $hex ? array( 'color' => array( 'style' => 'solid', 'solidColor' => $hex ) ) : null;
```

I checked whether the `'solid'` style value it emits actually renders:
`Css_Helpers::color_controls()` in Styble Pro maps `'solid' => $normal_color`
alongside `'bgColor'`, so it does. Not a bug — a genuinely different, and in this
one instance workable, way of erasing the same ambiguity `styble-ai` spent a whole
prompt-tagging pass on.

I grepped the entire builder for a rejection path (`throw`, `WP_Error`, `reject`,
`invalid`) and found exactly **one**: a null-root check in `TreeNormalizer.php`.
Everything else in the write path degrades rather than refuses.

**Why this is not a stylistic preference.** `styble-ai`'s own decision log has three
entries that are this exact failure category, paid for once already:

| # | What silently didn't work | Cost |
|---|---|---|
| 13 | `advancedTextColor` / `iconListTextcolor` declared in `block.json`, defaulted sensibly, rendered by **nothing** | The AI set `advancedTextColor` for weeks with no effect |
| 14 | Three declared overlay modes; `Css_Helpers::color_controls()` only implements one | Two of three overlay options silently produced the wrong colour |
| 15 | `textFillBg` with style `image` validated clean, rendered an **invisible heading** | Looked correct at every check that ran; wasn't |

(`docs/ROADMAP.md` decision log, entries 13-15.)

A coerce-and-default resolver is a machine purpose-built to reproduce this category
— the specific failure changes, the shape (looks done, wasn't) does not.

**The sharper cost is to the loop itself.** Phase B's whole mechanism is: the model
calls a tool, gets a result, corrects on the next call. That mechanism needs a
*signal*. `styble-ai`'s validator returns a code and a path — something a retry can
act on. `AttrResolver` returns nothing when it drops a key or defaults a bad
value — the model believes the call succeeded, because from its point of view it
did. There is no error to correct on. This isn't a smaller version of the same
problem; it removes the one thing that makes an agent loop able to fix its own
mistakes.

### 2b. Source of truth for the page

`styble-ai`: generated trees live in `_styble_ai_page` post meta; `post_content` is
**derived** from them (invariant 12). Editing means addressing into the stored tree.
Consequence: only pages `styble-ai` created are editable — `Page_Store::owns()`
gates it.

`styble-ai-demo`: `PageModel::from_post()` loads via WordPress's own `parse_blocks()`
and re-serializes with `serialize_blocks()` on save (`src/Editor/PageModel.php:1-20`).
Consequence: **any** Styble page is editable, including ones a human built by hand.

This is a real capability `styble-ai` doesn't have, and it's orthogonal to §2a —
you can build "edit any page" on a strict validator; nothing about reading via
`parse_blocks()` requires silently coercing what you write back. The two axes get
conflated because one branch happens to combine them; they don't have to travel
together.

### 2c. Catalog: generated vs curated

`styble-ai`: `scripts/generate-catalog.php` reads Styble Pro's block sources, 13
allowlisted blocks, 63 editable attributes, and discards any enum list it can't
verify against the block's own `block.json` default (invariant 6, decision #9).
Never hand-edited.

`styble-ai-demo`: `Catalog::blocks()` is a hand-written array, 8 blocks, described
in its own docblock as *"curated, not dumped"* (`src/Builder/Catalog.php:3-8`).

Trade-off is plain: generated can't drift from Styble Pro and covers more blocks;
hand-curated is smaller, more legible, and will silently go stale the moment Styble
Pro's block.json changes underneath it, with nothing to catch that the way
`generate-catalog.php`'s verification does.

### 2d. Real attribute names vs aliases

`styble-ai`: the model writes real attribute names — `textFillBg`, `textAliment`
(a pre-existing typo in Styble Pro itself, not ours) — documented in the prompt.

`styble-ai-demo`: the model writes a small alias set —
`{"text": "...", "color": "#fff", "tag": "h1"}` — mapped to the real key by
`Catalog::blocks()[...]['aliases']` before anything else happens
(`src/Builder/Catalog.php`, alias tables per block).

**This is a genuinely better idea**, and it's separable from §2a. Aliases dissolve
the exact ambiguity Phase 0.1 spent a prompt-tagging pass tagging around — the model
never has to learn that `textFillBg` and `listTextColor` look like the same kind of
thing but aren't, because it never sees either name. Nothing about mapping
`color → textFillBg` requires *also* accepting whatever shape arrives on the other
side without checking it. Alias-then-reject is a real, better option that this
comparison surfaces and that neither branch currently implements as such.

---

## 3. What each has that the other doesn't

**`styble-ai-demo` has, and `styble-ai` doesn't yet:**

- A working tool-calling loop — `EditSession`, capped at 24 steps, with the cap's
  value recorded from a real defect: *"6 was tuned for the old batched-multi-call-
  per-turn behavior and would truncate [a 20-step nested build] mid-build"*
  (`src/Editor/EditSession.php:33-38`).
- MCP — `McpServer` + 10 tools (`list_pages`, `get_page`, `get_block`,
  `list_block_types`, `update_block`, `move_block`, `insert_block`,
  `duplicate_block`, `delete_block`, `set_global_style`).
- `get_page`'s `outline` mode returns one entry per top-level section with a
  `repeated_child_block` flag when a section is a repeating grid — the same insight
  `docs/SPECTRA_AI_IMPLEMENTATION.md` documents ZIP AI arriving at independently.
- `set_global_style`, with a real, already-fixed defect recorded in its own
  docblock: a colour change has to patch **both** the data slot
  (`colors.presetColors`) **and** the derived `--styble-{slug}` variable inside the
  stored `rootcss` string, because the frontend enqueue prints `rootcss` verbatim —
  *"a data-only change would silently not render"* (`src/Editor/GlobalStyle.php:9-11`).
- Provider tool-call-rejection recovery: some providers (Groq is named) validate
  tool-call arguments server-side and reject the whole completion on a mismatch,
  with no `tool_call` to execute or feed back. `EditSession` catches this and tells
  the model plainly rather than losing the turn (`src/Editor/EditSession.php:99-108`).

**`styble-ai` has, and `styble-ai-demo` doesn't:**

- Tests. 8 suites, 247 checks, 47 fixtures, all offline. Every smoke run done
  against this plugin's own new code this week found a real bug; there is no
  equivalent verification on the other branch to have caught anything.
- A measured baseline — 65-75% first-try valid, two runs, on a real model, with the
  ±10-point spread stated as the binding constraint on the whole experiment. No
  evidence `styble-ai-demo` has been measured at all.
- The decision log — 22 numbered entries recording what was measured vs assumed,
  several of which (§2a above) are exactly the failure mode a coerce-and-default
  resolver is liable to reproduce.
- Per-provider credentials, so a target-model-vs-floor-model comparison is possible
  at all (`docs/ZIPAI_SELECTIVE_PORT.md` decision S4 / roadmap blocker B3).

---

## 4. Verdict

**Reject-and-never-coerce is the better foundation, and it isn't close.** The
argument isn't aesthetic — it's that this exact org, in this exact codebase, has
already paid three times for the failure mode a coerce-and-default resolver exists
to produce (decision log #13-15), and that an agent loop's self-correction has
nothing to grab onto when a bad call is silently absorbed instead of reported.

**What to take from `styble-ai-demo` regardless of which core the org standardises
on**, because none of these three require accepting fail-soft coercion:

1. **Editable aliases** — checked *before* validation rather than instead of it,
   alias-map to the real key, then reject on the real key exactly as today. Strictly
   better than Phase 0.1's tagging approach for the same problem. **Status:
   deferred, deliberately.** This touches the live prompt/schema that Stage 0's
   65-75% baseline was measured against, and the roadmap's own gate says no such
   change lands until the ±10 spread is bounded (steps 0.2/0.3) — landing it now
   would be the exact mistake the gate exists to prevent. Also: demo's own alias
   table reuses `'color'` across blocks with genuinely different real shapes
   (`textFillBg`, an object, on advanced-text; `iconColor`, a string, on
   icon-picker) — safe for them only because `AttrResolver` coerces whichever
   shape arrives. Porting the alias NAMES without porting the coercion would
   reproduce the exact shape ambiguity Phase 0.1 fixed, just hidden behind a
   friendlier name. A correct port needs each alias to imply ONE fixed shape (or
   a resolver that deterministically *expands* a plain string into whichever real
   shape is needed, rejecting anything else) — real design work, not a drop-in,
   and gated behind the spread being bounded regardless.
2. **The `rootcss` dual-patch fix** for anything touching `styble_global_settings` —
   a defect this plugin would otherwise ship into its own `set_global_style`
   equivalent. **Status: recorded, not built.** `styble-ai` writes nothing to
   `styble_global_settings` today, so there's nothing to patch yet; noted as a
   landmine in `docs/ZIPAI_SELECTIVE_PORT.md` §7 B.1 for whenever that tool is
   authorized, rather than built preemptively.
3. **Provider tool-call-rejection recovery** — this plugin had no handling for a
   provider that rejects a whole completion server-side with no tool_use to
   recover. **Status: done**, 2026-07-30. `class-generator.php` now retries a
   narrow, named set of provider failures where the model produced something but
   it never reached the validator (`styble_ai_no_tool_use`, `styble_ai_truncated`,
   `styble_ai_bad_json`, `styble_ai_malformed_tool_call`) — the same one-retry
   budget the validator path already spends, not an additional call. Explicitly
   excludes config errors and `styble_ai_api_error` (which bundles rate limits),
   so the roadmap's existing decision to keep 429 handling out of the plugin
   stands untouched, and the eval harness's `retries=0` measurement mode is
   bit-for-bit unchanged — smoke-verified, not covered by a committed suite (S5).
   See `docs/ROADMAP.md` decision log #23.

**What not to take:** the coerce-and-default resolver itself. Editing any page
(§2b) is worth having and is a separate decision from reject-vs-repair — it can be
built on top of the strict validator without importing the fail-soft write path.

---

## 5. The decision this doesn't make

This document argues one axis — reject vs repair — to a conclusion. It does not
decide, and isn't positioned to decide, the coordination question underneath it:
whether the org converges on one branch, merges specific pieces, or lets both run
until one has more evidence behind it. A teammate is actively building
`styble-ai-demo`; that's an org decision, not a technical one, and belongs to
whoever owns both efforts.

See [[parallel_demo_effort]] (session memory, if read in a future conversation) for
the standing note not to start `styble-ai`'s Phase B agent loop without checking in
on that coordination question first — writing it independently risks duplicating
~2,000 already-working lines under an architecture the org may not keep.
