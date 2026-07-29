# Styble AI — roadmap and progress

**Updated:** 2026-07-29 (evening) · **Branch:** `ai-evals` · **Contract:** `emit_layout` 0.1.0

This file tracks **where the experiment actually is**. It does not restate the
reasoning — that lives in [`EXPERIMENT_PLAN.md`](EXPERIMENT_PLAN.md), which owns the
stages and their bars, and in the deck (`~/styble-ai-architecture/styble-ai-architecture.html`),
which owns the destination. This file answers one question: *what is proven, what
is built but unproven, and what is next?*

Keep it honest. A stage is not "done" because its code exists — it is done when it
meets the bar that was stated before the run.

---

## Status at a glance

> **Headline: the number is 65–75%, and the spread is the finding.** Two full runs
> of the 20-case section suite on `mistral-large-latest`, retry off, nothing changed
> between them: **15/20 then 13/20**. Every failure was a contract violation, not a
> quality judgement — every scorer that ran on a valid tree passed both times.
>
> ±10 points of run-to-run variance means **a single run cannot detect a real
> change**. Any prompt edit scoring 70% next week is inside the noise. That is now
> the central constraint on how this experiment can be run, and it is not
> fixable by a better scorer — the model is non-deterministic and the
> OpenAI-compatible path sends `temperature: 0.7`.

| | Stage | Question | Bar | Status |
|---|---|---|---|---|
| **0** | The measuring stick | Can we tell better from worse? | A baseline exists · ≥3 runs so the spread is known · breaking the prompt moves the number beyond that spread | 🟡 **Baseline taken, spread not yet bounded** |
| **1** | Block choice | Right blocks from plain words? | ≥90% target · ≥80% floor · n≥30 | ⚪ Not started |
| **2** | Attribute edit | Right attr → right value, nothing else? | ≥90% key+value · **0% collateral** | 🟡 **Model + tools built, 38 checks; eval suite not written** |
| **3** | One section | Blocks + attrs + layout composed | ≥85% first-try valid · 100% after retry · ≥80% "would ship" | 🔴 **65–75% first-try — below the bar** |
| **4** | ⭐ Conversational edit | Change one thing, nothing else moves | ≥90% intended · 0% collateral · ≤6 tool calls | ⚪ Not started — `update_block` exists, the loop does not |
| **5** | Whole pages | Does a page hold together? | ≥80% need no structural edit | ⚪ Built, unmeasured |
| **6** | Distribution (MCP) | — | — | ⚪ Deferred until 4 passes |

Legend: 🟢 bar met · 🟡 built, bar unmet · ⚪ not started · 🔴 failed (a valid, useful result)

---

## What is built and trusted

Proven by tests that need neither WordPress nor an API key. These are the assets
every stage sits on.

| Piece | Evidence | Trust |
|---|---|---|
| `catalog/catalog.json` generated from Styble Pro | Regenerates; self-verifying. 23 blocks, **13 allowlisted**, 63 editable attrs, 23 layouts | **High** |
| `emit_layout` contract + validator | **31 error codes, 47 fixtures**, coverage check asserts every code has one | **High** — checks legality, not quality |
| Headless applier (`uniqueId`, layout maths, card padding) | **42 checks**; verified in a real WP install | **High** |
| Editor applier (`createBlock` → `insertBlocks`) | Shares the catalog layout table; maths mirrored line-for-line | **High** |
| Canonical page model (uid addressing, `get_block`/`update_block`) | **38 checks**. Resolved uids compared against the applier's own output, so the two cannot drift | **High** |
| Request body + cache prefix | **24 checks** — breakpoint placement, byte-identical prefix across a retry | **High** |
| Retry loop | **7 cases** against a stub provider | **High** |
| Prompt + tool schema invariants | **33 checks** | **High** |
| Providers (Anthropic + 9 OpenAI-compatible) | Runs | **Medium** — no 429 handling in the plugin (deliberate) |
| Stock photo fill | Runs; every failure non-fatal and reported | **Medium** |
| Section generation from a prompt | **65–75% first-try valid**, n=20, two runs | **Measured, below bar** |
| Whole-page chat screen | Runs | **Unknown — never measured** |
| "Edit with AI" on a selection | Runs | **Low** — regenerates the whole selection (the deck's naive column) |

Six WP-free suites, **164 checks**, all passing.

Measured prompt surface, 2026-07-29:

```
system prompt   15,380 chars  (~4,157 tokens)
tool schema     11,039 chars  (~2,984 tokens)   node expanded to depth 6
per request     26,419 chars  (~7,140 tokens)   sent in full on EVERY call
```

The system prompt grew 57% today (9,771 → 15,380) adding background, card and
text-colour guidance. Whether that bought anything is unmeasured: the baseline
was taken after the growth, not before, so there is no before to compare to.

---

## Stage 0 — the measuring stick 🟡

Built in `c9b7b3b`, stripped of persistence 2026-07-29 by decision (see the log).
The baseline exists; what is not yet known is how wide the noise band is.

**Done**

- [x] `evals/section/cases.json` — **20 cases**, each with a `why` naming what it catches
- [x] `scripts/eval.php` — runs a suite, scores it, prints the result
- [x] Retry **off by default** (`retries=0`) — with it on, a model that never gets it right first time scores like one that always does
- [x] `Styble_AI_Eval_Patient_Provider` — reads the delay a 429 asks for and waits it out. Deliberately *not* in the plugin, where a 429 should reach the user fast
- [x] `live=1` required — every run spends real tokens, so a mistyped argument must not start one
- [x] Provider/key mismatch **refuses to run**. There is one shared key option, so `provider=X` moves the endpoint but never the credential; a mismatch used to fail all 20 cases on auth and read like a catastrophic model score
- [x] Deterministic scorers: `valid`, `counts`, `mustContain` / `mustNotContain`, `minOf` / `maxOf`, `layoutIn`, `headingTags`, `copyMustMention`, `attrEquals`, plus universal `noPlaceholder` and soft `padding` / `preferContain`
- [x] Per-case token accounting printed, so a silent cache invalidation on the Anthropic path is visible
- [x] **The baseline, twice** — see below

**Not done — this is the bar**

- [ ] **Bound the spread.** Two runs gave 75% and 65%. A third and fourth would say whether ±10 is the band or an outlier. Until that is known, no prompt change can be evaluated
- [ ] **Falsify it** — break the prompt deliberately and confirm the number moves *beyond* the spread, not merely down

**Unreachable by construction, and struck from the bar**

Nothing is persisted, so these two items from the original plan cannot be met and
are no longer claimed:

- ~~the same case set scored twice from cache gives a byte-identical result~~ — no cache
- ~~`compare=A with=B` → per-case FIXED / REGRESS~~ — no records to diff

The consequence is not cosmetic. Detecting a real change now needs several live
runs averaged, because the same responses cannot be re-scored for free and two
runs cannot be diffed for you.

### The baseline

`mistral-large-latest`, retry off, 20 cases, nothing changed between runs:

| Run | Passed | First-try valid | Validator codes seen |
|---|---|---|---|
| 1 | 15/20 | **75%** | `attr_type`, `attr_value` |
| 2 | 13/20 | **65%** | `attr_type`, `attr_value`, `block_missing`, `child_not_allowed`, `parent_not_allowed` |

**Every non-`valid` scorer passed in both runs.** `counts`, `mustContain`, `minOf`,
`maxOf`, `layout`, `headingTags`, `copyMustMention`, `attrEquals`, `noPlaceholder`
and `padding` were clean throughout — the model set `sectionPadding` itself every
time rather than relying on the applier backstop. Nothing failed on quality; every
failure was a contract violation.

Against Stage 3's bar of ≥85% first-try, that is 🔴 — a real, useful result.

### What the baseline caught immediately

22 of the 26 validator errors in run 1 were **one mistake made hours earlier the
same day**:

```
15×  listTextColor      must be a string, got {"color":{"style":"bgColor",…}}
 3×  iconColor          must be a string, got {"color":{…}}
 1×  btnTextColor       must be a string, got {"color":{…}}
 1×  titleTextColor     must be a string, got {"color":{…}}
 1×  contentTextColor   must be a string, got {"color":{…}}
```

`textFillBg` and `subHeadingBg` take the `(background)` object shape, and the prompt
documents that shape prominently. The other colour attributes are plain strings and
carry **no shape tag at all**, by the rule that *"plain strings get no tag: they are
the common case and tagging them all would bury the ones that matter."* That rule
held until a background-shaped colour appeared beside them; now the ambiguity sits
exactly where the tag is needed, and the model generalises the object shape to all
of them. Fixing it is a small prompt change — but see the spread problem above for
why proving the fix worked is not.

### Model access

`class-provider-factory.php:27` reads a single shared `styble_ai_api_key`, so only
one provider is reachable at a time.

| | Blocker | State |
|---|---|---|
| B1 | No Anthropic key → target `claude-opus-5` unreachable, and prefix caching is **inert** here | ❌ open |
| B2 | No Gemini key → floor `gemini-2.0-flash` unreachable | ❌ open |
| B3 | One shared key option → two providers cannot be held at once | ❌ open |
| B4 | Could `mistral-large-latest` satisfy this schema at all? `llama-3.3-70b` could not | ✅ resolved |

So the baseline is **single-model**, recorded as a substitution. The plan's
target-versus-floor split — which it calls the most useful signal in the document —
is not available, and a 65–75% score cannot be attributed to our prompt versus the
model's ceiling.

**Command**

```
wp eval-file scripts/eval.php suite=section live=1 delay=8
```

Provider and model come from Settings; passing a `provider=` that disagrees with
them is refused rather than run with the wrong key.

---

## Stage 1 — does it pick the right block? ⚪

Not started. Cheapest suspected win in the whole plan, and **verified, not suspected**:
the model is currently told *nothing* about what any block is for. Re-measured
2026-07-29 — **12 of the 13** allowlisted blocks carry a tautology in `block.json`
(`"Styble Info Box block."`, `"Styble Accordion block."`, …); `separator` is the only
one with a real description, and none of them are printed in the prompt anyway.

> The plan recorded 10 of 11. It is now 12 of 13 — `8889b2b` allowlisted the accordion
> pair and both descriptions are tautologies too. The gap grows every time the
> allowlist does, which is the argument for putting the curated table in
> `generate-catalog.php` rather than fixing `block.json` upstream one block at a time.

- [ ] `choose_blocks` tool — flat list of block names, one-line reason each. No attrs, no nesting, no layout
- [ ] `purpose` / `useWhen` / `dontUseWhen` per allowlisted block, from a curated table in `generate-catalog.php`
- [ ] `evals/block-choice/cases.json` — ~30 needs → expected set + an `acceptable` alternates set

**Independent of Stage 0's runner** — the purpose lines can be written while the
baseline is pending. But the baseline must be taken *before* they land, or it says
nothing about whether they helped.

---

## Stage 2 — right attribute, right value? 🟡

**The model and its two tools are built** — `includes/class-page-model.php`, 38 checks.
What is missing is the eval suite that scores the model's *judgement* rather than the
plumbing's correctness.

It cost far less than the deck implies, because the addressing already existed:

```
headless   uniqueId = prefix + md5(sectionId | nodePath)[0:12]   ← derived, re-computable
editor     uniqueId = prefix + last dash-segment of clientId     ← random, but saved in post_content
```

Because the headless derivation is deterministic it is also *reversible by
recomputation*, so the model is a **projection of `_styble_ai_page`**, not a second
source of truth. No new data structure, no migration. The arithmetic lives once, in
`Styble_AI_Page_Applier::unique_id()`, and the test compares the model's resolved
uids against the applier's own output rather than against a second `md5()` — so the
two cannot drift apart silently.

- [x] uid reverse index over the page store — `index()`, document order
- [x] `get_block(uid)` — block, section, path, attrs, child uids, and what may be edited. **Never markup**
- [x] `update_block(uid, attrs)` — merges, validates, returns `{ok, uid, block, changed[], warnings[]}`
- [x] Validation runs on the **whole section envelope**, not the node: most interesting rules are cross-field, so editing a container's `layout` alone is caught by `layout_children_mismatch`. A node-only check would have passed it and broken the section
- [x] A rejected edit leaves the stored page **byte-identical** — asserted against illegal enum values, emptied copy and foreign attributes
- [x] `null` reverts an attribute to the block's default; re-sending an identical value reports no change, or a collateral count would be meaningless
- [ ] `evals/attr-edit/cases.json` — ~40 instructions with exact expected diffs
- [ ] Scorer: three numbers — right key, right value, **collateral**

**Collateral is a hard zero.** A model that edits the heading *and* silently
restyles the button is worse than one that refuses, because nobody notices until
later. The plumbing already guarantees it — an edit diffs the entire page index
before and after — so what Stage 2 still has to measure is whether the *model*
picks the right attribute.

---

## Stage 3 — one correct section 🔴

**Measured, and below the bar.** This is roughly what ships today, so Stage 0's
baseline *is* this stage's number: **65–75% first-try valid** against a bar of ≥85%.

- [x] Automated scorers — reused from Stage 0, and every one of them passed on every valid tree
- [ ] Bound the spread before acting on the number (see Stage 0)
- [ ] Retry-on measurement: the bar also wants 100% valid after one corrective retry, and that has never been run — every measurement so far is `retries=0`
- [ ] Rendered-screenshot contact sheet — structure passing says nothing about whether it *looks* right. One page, 20 sections, one rater, recorded verdicts

**What the failures were.** Not composition, not copy, not layout — every failure was
a contract violation, and most were one attribute-shape ambiguity introduced the same
day (see Stage 0). So this 🔴 is not yet evidence that freeform generation cannot
work; it is evidence that the prompt currently contradicts itself about colour shapes
and that four boolean/enum mistakes recur.

**The trigger it would arm.** A genuine Stage 3 failure is what justifies the pattern
library (deck Phase 2), and that is also the biggest cost lever measured: **$0.24 →
$0.05 per page**. The deck is explicit that patterns *are* the quality and freeform
is the fallback. But the current number is confounded by a fixable prompt bug and an
unbounded noise band, so it does not yet justify anything. Fix the shape tags,
re-measure with the spread known, and *then* decide.

---

## Stage 4 — ⭐ the moat: conversational editing ⚪

The deck's differentiator, and the thing never built. What ships today under
"Edit with AI" regenerates the whole selection.

- [x] `get_block` · `update_block` — built in Stage 2, 38 checks
- [ ] The rest of the surface: `get_page` · `insert_block` · `move_block` · `delete_block` · `duplicate_block`
- [ ] Tool-calling loop, `tool_choice: auto`, destructive tools gated
- [ ] Diff application — re-serialize only the changed subtree
- [ ] `evals/edit/cases.json` — ~25 instructions against fixed pages

This is also the structural fix for the `attrs` union problem: a call naming one
`uid` means the executor knows which block it is, so the "which block am I on →
which keys are legal" mapping stops being the model's job. And there is no
recursion, so attributes cost ×1 instead of ×6.

---

## Stage 5 — whole pages ⚪

Built (`d725cf3`), unmeasured. The plumbing is verified; the **planner** is not.

- [ ] `evals/page/cases.json` — ~10 page briefs
- [ ] Scorers: every section valid · no two sections making the same point · one `h1` across the page · 4–6 sections for a standard brief
- [ ] Reuse Stage 0's rate-limit handling in the chat loop, so a free-tier user gets a slow page rather than a failed one

Until then the chat screen stays where it is, unadvertised. It is a UI for a
capability nobody has measured.

---

## Stage 6 — distribution (MCP) ⚪

Deferred until Stage 4 passes, deliberately. Wrap the one executor built in Stage 4
as a Streamable-HTTP MCP server. Also the natural moment to revisit PHP-in-WordPress
versus the deck's Python/LangGraph service — by then the tool surface is defined by
its tests, so porting is mechanical rather than speculative.

---

## Mapping to the deck

| Deck phase | Our stage | State |
|---|---|---|
| 1 — Foundation: page model + `resolve_attrs` + serialize | Prerequisite | ✅ **Built.** Catalog · nesting rules · serializer · validator · **uid page model, 38 checks** |
| 2 — Pattern library + `search_patterns` / `insert_pattern` / `fill_slots` | Stage 3's failure branch | Not built, **on purpose** |
| 3 — Freeform `set_page_layout` fallback | Stages 0/3 | **Built, and currently the foundation rather than the fallback** — the inversion Stage 3 exists to judge |
| 4 — ⭐ Edit tools | Stages 2 + 4 | ⚠️ **`get_block`/`update_block` built.** The tool-calling loop and the other five mutators are not |
| 5 — MCP | Stage 6 | Deferred |
| 6 — Proxy + credits | — | Out of scope until Stage 5 passes |

**Where we diverge from the deck, deliberately**

- **Language.** Deck says Python/LangGraph + a WP connector. We are all-PHP through Stage 5 — cheapest place to run experiments, and nothing in Stages 0–5 depends on the language. Revisit at Stage 6.
- **`resolve_attrs` coerces; our validator does not.** The deck's safety net is `alias → coerce → default`. `CONTRACT.md` states the opposite: pass or reject, with a reason, no silent repair. The 31 codes exist because of that choice. Take the deck's tool *shape*; keep the gate.
- **Freeform is our foundation, the deck's fallback.** Knowingly. Stage 3 decides whether it stays that way.

**Deck token levers, and their status**

| Lever | Deck claim | Status |
|---|---|---|
| Catalog prefix caching | −up to ~90% input | ✅ Done on the Anthropic path (`586bb46`), but **inert on this install** — the configured provider is Mistral. Not mirrored to the OpenAI-compatible path: `cache_control` is Anthropic's parameter and strict endpoints reject unknown fields. Measured effect on a 6-section page: −28% total, because generation is output-dominated |
| Pattern-fill (content-only) | ✅ low | Not built — Stage 3's failure branch. Measured potential: **$0.33 → $0.05 per page**, the single biggest lever |
| Granular per-tool schemas | tiny diffs | Stages 2 + 4 |
| Fetch vocabulary on demand (`list_block_types`, tool search `defer_loading`) | — | Not built |
| Programmatic tool calling | "saving big tokens" | Not built — needs Stage 4's executor first |

---

## Decision log

Things measured, and what the measurement changed. This is the part that stops the
same ground being re-argued.

| # | Decision | Measured or assumed | Outcome |
|---|---|---|---|
| 1 | `attrs` schema declaration | **Measured** on `gemini-3.1-flash-lite`. Full 25-key union → content attrs on the root container. No properties → *every* `attrs` empty, a perfect tree of grey placeholders. Content-only (6 keys) → two keys misplaced | All three fail. Content-only ships because both its failure modes are **rejections**, not silent damage. **Not verified on a capable model** — recorded in `class-prompt.php:136-161` |
| 2 | Union reverted | **Measured** | `5d44ca5` — "caused a worse failure than it fixed" |
| 3 | Booleans declared in the schema | **Measured** — 4× `attr_type` on `"true"`/`"false"` strings in the first eval run | `0ed1fec` |
| 4 | Thinking left **on** | **Measured** on Claude Opus 5 — with thinking off the model writes the tool call into visible text and the call silently never happens | Budget raised to 16k instead. **Corroborated by Anthropic's own reference**, which documents the same failure mode plus a second one (`<thinking>` tags leaking into output) |
| 5 | `$defs` / `$ref` not used | **Assumed** — one of nine OpenAI-compatible endpoints failing to resolve a `$ref` breaks generation outright | Node schema expanded to depth 6 instead. Costs ~700 chars per declared attribute vs ~29 in the prompt |
| 6 | Media cache keyed by source URL | **Reasoned from a real defect** — an id-only cache can't tell "already used" from "a different photo of the same thing", so it downloads the top result twice | `dc20210` |
| 7 | Accordion allowlisted | **Measured** — without it the model flattened FAQs into loose text blocks that read answer-then-question | `8889b2b`. Also fixed `acceptsChildren` being scanned from the edit component only, which recorded the accordion as a LEAF while recording `accordion-item` as its allowed child — every accordion rejected, contradiction invisible |
| 8 | `content_empty` added | **Reasoned** — six blocks ship placeholder defaults, so an omitted content attr passes every structural rule and renders grey placeholders. Worse than a rejection because it looks like the feature ran | `251cc30` |
| 9 | Enum lists verified against `block.json` defaults | **Reasoned** — extraction is a heuristic; an unverified list gave `iconOrderedStyle` a neighbouring control's `row\|column` | Default not blank and not in the list → discard the list. The catalog never guesses |
| 10 | Attribute cost, prompt vs schema | **Measured** — prompt grows ~29 chars/attr (×1); schema grows ~700 chars/attr (×6 depth levels) | Grow the prompt freely; never grow `attrs.properties` |
| 11 | Prefix caching, 5-min TTL not 1h | **Measured** prefix (21,522 bytes ≈ 5,817 tokens) + documented economics (write 1.25× at 5 min vs 2× at 1h) | Every caller here bursts, so 5 min pays back in two requests where an hour needs three |
| 12 | `llama-3.3-70b-versatile` retired as an eval model | **Measured** — 12,000 TPM cannot fit a ~5.8k-token prefix plus output, and the settings screen already records it emitting unparseable tool calls for this schema | Floor model is `gemini-2.0-flash` |
| 13 | Two attributes exposed that nothing reads | **Measured** — audited every colour attribute against the whole render tree. `advancedTextColor` and `iconListTextcolor` are declared in `block.json` with sensible defaults and referenced by no CSS generator, frontend or editor | The AI had been setting `advancedTextColor` for weeks with no effect. Replaced by `textFillBg` / `subHeadingBg`, which paint via `background-clip:text`. **An attribute existing in `block.json` does not mean anything renders it** — and there is still no build-time check for this |
| 14 | Overlay modes restricted to two of the block's own three | **Measured** — `Css_Helpers::color_controls()` has no case for `no-overlay`, `solid-overlay` or `gradient-overlay`, so all three fall to its default branch and return the SOLID colour; `has_active_section_bg_overlay()` counts anything but blank or `transparent` as active | Only `solid-overlay` and `transparent` do what their names say, so only those two are allowed. The catalog's own default-membership check had already rejected the control's list |
| 15 | Image backgrounds allowed, but only where a photo can be filled | **Measured** — `textFillBg` with style `image` validated clean and rendered an **invisible heading**: `AdvancedText` passes no image object to `color_controls`, so the style resolves to `none` and `background-clip:text` clips nothing | `image` is legal only on a background attribute whose block also allowlists `sectionBgImg`. Catalog-driven, so it stays correct if the allowlist moves |
| 16 | No persistence in the eval harness | **Decision, not a measurement** — cache and run records removed at the owner's request | Re-scoring now costs a fresh run, and `compare=A with=B` is gone. Detecting a change requires several live runs averaged. Stage 0's bar was rewritten to match what the harness can do |
| 17 | Infrastructure failures were being cached as model results | **Measured** — a run pointed at the wrong provider wrote four `Invalid API Key` entries indistinguishable from real measurements | Fixed with a cacheable-outcome allowlist, then made moot by #16. A provider/key mismatch now refuses to run at all |
| 18 | Run-to-run variance is ±10 points | **Measured** — two identical runs of the 20-case suite scored 75% and 65%, with three failure modes appearing in one and not the other | **A single run cannot detect a change.** The model is non-deterministic and the OpenAI-compatible path sends `temperature: 0.7`. This is now the binding constraint on the whole experiment |

---

## Deliberately not doing yet

Each with the trigger that would change the answer.

| Deferred | Why | Revisit when |
|---|---|---|
| Pattern library | Building it before Stage 3 destroys the evidence that freeform failed | Stage 3 misses its bar |
| Python / LangGraph port | Would rewrite the catalog, validator and applier — our three High-trust assets — into a language where they have no tests, to answer a deployment question nobody has asked | Stage 6 |
| MCP server | Wraps the Stage 4 executor. Nothing to wrap yet | Stage 4 passes |
| Hosted proxy + credits | Business layer. Do it after the product works | Stage 5 passes |
| 429 handling in the plugin | Production should surface a rate limit to the user fast; only an eval run should be patient | If a real user hits it often enough to complain |
| Caching on the OpenAI-compatible path | `cache_control` is Anthropic's parameter; strict endpoints reject unknown fields. A win that breaks generation on eight providers is not a win | Never, as one change. Per-provider gating if it ever matters |
| Growing `attrs.properties` | Already tested and reverted (#1, #2). ~700 chars each **and** a worse failure mode | Only if a capable model measurably handles the union |
| Byte-comparing the two appliers | Headless output is sparse where the editor writes every attribute it holds. Not a defect | Never |
| Granular per-block edit tools in the editor UI | Stage 4 replaces "Edit with AI" wholesale; patching it twice is waste | Stage 4 |

---

## Next three things, in order

1. **Fix the colour shape ambiguity.** `listTextColor`, `btnTextColor`, `iconColor`,
   `titleTextColor`, `contentTextColor` are plain strings sitting beside two
   background-shaped ones, with no tag distinguishing them — and the model
   generalises the object shape to all of them. That is 22 of the 26 validator
   errors in the baseline. Small prompt change, largest single win available.
2. **Bound the spread.** Two more runs, unchanged, to learn whether ±10 points is
   the band. Without it, step 1 cannot be shown to have worked — a 70% next run
   proves nothing either way.
3. **Then falsify the harness**: break the prompt deliberately and confirm the
   number moves beyond the spread. An eval that cannot move is not measuring.

Stage 1's block-purpose lines remain the cheapest suspected quality win, but they
are now *behind* the measurement work: with a ±10 band and no ability to re-score,
landing a change we cannot evaluate is how the project got here.

---

## How to check progress yourself

```
php scripts/generate-catalog.php     # regenerate from Styble Pro
php scripts/validate.php             # 47 contract fixtures + code coverage + code coverage
php scripts/test-generator.php       # the retry loop, against a stub provider
php scripts/test-page-applier.php    # headless layout maths + uniqueId + markup
php scripts/test-prompt.php          # invariants of the prompt and tool schema
php scripts/test-provider-body.php   # request body shape + cache prefix stability
php scripts/test-page-model.php      # uid addressing + granular edits
php scripts/dump-prompt.php          # exactly what the model is told
```

None of those need WordPress or an API key. The eval runner is the only one that
does — and the only one that measures the model rather than the code.

**Rules that keep this file worth reading:** a stage moves to 🟢 only when its stated
bar is met. Cases are added when they fail in real use and **never removed to make a
number look better**. A bar is never moved after seeing a score. 🔴 is a real,
useful outcome — "the model cannot do this reliably" redirects the design, and
pattern-fill exists in the deck precisely because freeform is unreliable.
