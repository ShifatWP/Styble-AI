# Styble AI — roadmap and progress

**Updated:** 2026-07-29 · **Branch:** `ai-evals` · **Contract:** `emit_layout` 0.1.0

This file tracks **where the experiment actually is**. It does not restate the
reasoning — that lives in [`EXPERIMENT_PLAN.md`](EXPERIMENT_PLAN.md), which owns the
stages and their bars, and in the deck (`~/styble-ai-architecture/styble-ai-architecture.html`),
which owns the destination. This file answers one question: *what is proven, what
is built but unproven, and what is next?*

Keep it honest. A stage is not "done" because its code exists — it is done when it
meets the bar that was stated before the run.

---

## Status at a glance

> **Headline: no baseline exists yet.** Stage 0's harness is built and its scorers
> work, but `evals/runs/` is empty. Until a live run lands there, every claim about
> output quality in this project is an eyeball, and every later stage has nothing
> to be measured against.

| | Stage | Question | Bar | Status |
|---|---|---|---|---|
| **0** | The measuring stick | Can we tell better from worse? | Byte-identical rescore · baseline on both models · breaking the prompt lowers the number | 🟡 **Harness built, bar unmet** |
| **1** | Block choice | Right blocks from plain words? | ≥90% target · ≥80% floor · n≥30 | ⚪ Not started |
| **2** | Attribute edit | Right attr → right value, nothing else? | ≥90% key+value · **0% collateral** | ⚪ Not started |
| **3** | One section | Blocks + attrs + layout composed | ≥85% first-try valid · 100% after retry · ≥80% "would ship" | ⚪ Not started (baseline is this stage) |
| **4** | ⭐ Conversational edit | Change one thing, nothing else moves | ≥90% intended · 0% collateral · ≤6 tool calls | ⚪ Not started |
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
| `emit_layout` contract + validator | **31 error codes, 34 fixtures**, coverage check asserts every code has one | **High** — checks legality, not quality |
| Headless applier (`uniqueId`, layout maths) | **36 checks**; verified in a real WP install | **High** |
| Editor applier (`createBlock` → `insertBlocks`) | Shares the catalog layout table; maths mirrored line-for-line | **High** |
| Retry loop | **7 cases** against a stub provider | **High** |
| Prompt + tool schema invariants | **33 checks** | **High** |
| Providers (Anthropic + 9 OpenAI-compatible) | Runs | **Medium** — no 429 handling in the plugin (deliberate) |
| Stock photo fill | Runs; every failure non-fatal and reported | **Medium** |
| Section generation from a prompt | Runs | **Unknown — never measured** |
| Whole-page chat screen | Runs | **Unknown — never measured** |
| "Edit with AI" on a selection | Runs | **Low** — regenerates the whole selection (the deck's naive column) |

Measured prompt surface, as of this commit:

```
system prompt   9,771 chars  (~2,641 tokens)
tool schema    11,039 chars  (~2,984 tokens)   node expanded to depth 6
cacheable prefix         21,522 bytes  (~5,817 tokens)
```

---

## Stage 0 — the measuring stick 🟡

Built in `c9b7b3b`. The harness exists and its parts work; the *stage* is not done.

**Done**

- [x] `evals/section/cases.json` — **20 cases**, each with a `why` naming what it catches
- [x] `scripts/eval.php` — runs a suite, scores it, writes a run record
- [x] Response cache keyed on `sha1(model | retries | prompt | system_prompt | tool_schema)` — a catalog regeneration or prompt edit **must** miss, or the number is a lie
- [x] Retry **off by default** (`retries=0`) — with it on, a model that never gets it right first time scores like one that always does
- [x] `Styble_AI_Eval_Patient_Provider` — reads the delay a 429 asks for and waits it out. Deliberately *not* in the plugin, where a 429 should reach the user fast
- [x] `live=1` required to touch the network — a mistyped argument cannot spend money
- [x] Run records carry **no timestamp**, so two runs of the same cases are byte-identical
- [x] `compare=A with=B` — per-case FIXED / REGRESS
- [x] Deterministic scorers: `valid`, `counts`, `mustContain` / `mustNotContain`, `minOf` / `maxOf`, `layoutIn`, `headingTags`, `copyMustMention`, `attrEquals`, plus universal `noPlaceholder` and soft `padding` / `preferContain`
- [x] **Prefix caching on the Anthropic path** — one `cache_control` breakpoint on the last system block covers tools + system (render order is `tools` → `system` → `messages`). Verified byte-identical across a first attempt and a corrective retry. `[cache w:N r:N in:N]` printed per live case, because a zero read means a silent invalidator and nothing else would say so *(uncommitted)*

**Prerequisite, found 2026-07-29 while trying to verify the caching live**

The plan says "take the baseline" and assumes the models are reachable. They are not.
`class-provider-factory.php:27` reads a single shared `styble_ai_api_key`, and this
install holds a **Mistral** key with provider `mistral`.

| | Blocker | State |
|---|---|---|
| B1 | No Anthropic key → target `claude-opus-5` unreachable. Prefix caching is therefore **inert** here | ❌ open |
| B2 | No Gemini key → floor `gemini-2.0-flash` unreachable | ❌ open |
| B3 | One shared key option → cannot hold two providers at once, so passes must run sequentially with a swap | ❌ open |
| B4 | Could `mistral-large-latest` satisfy this schema at all? `llama-3.3-70b` could not | ✅ **resolved** |

**B4 resolved by one live call, 2026-07-29.** `hero-basic` came back **valid on the first
try** — 7 nodes, 7/7 case expectations, 6.0s, `sectionPadding` set by the model rather
than backstopped. Mistral is a viable eval model. A single-model baseline is possible
now; the two-model split is not.

**Not done — this is the bar**

- [ ] **Take the baseline.** `evals/runs/` is empty. Retry off. Two models if reachable, one recorded as a substitution if not
- [ ] **Demonstrate** the byte-identical rescore (needs two runs to diff, not just the absence of a clock)
- [ ] **Falsify it** — deliberately break the prompt and confirm the number goes down

**The one thing the harness has already caught.** `evals/cache/1d73fd883901544d.json` is a
*failure*, from the first run: four `attr_type` errors, all the same shape —

```
attr_type  root.children[0].children[0].attrs.subHeading
           "subHeading" must be true or false, got "false"
```

Fixed two commits later in `0ed1fec`. That is the harness doing its job on day one.

**Command**

```
wp eval-file scripts/eval.php suite=section live=1 provider=anthropic model=claude-opus-5
wp eval-file scripts/eval.php suite=section live=1 provider=gemini model=gemini-2.0-flash
wp eval-file scripts/eval.php compare=section-claude-opus-5 with=section-gemini-2.0-flash
```

Expect the first live case to log `w:~5800 r:0` and cases 2–20 to log `w:0 r:~5800`.
Reads stuck at zero means the prefix is being invalidated between calls.

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

## Stage 2 — right attribute, right value? ⚪

Not started. Cheaper than the deck implies, because **the addressing already exists**:

```
headless   uniqueId = prefix + md5(sectionId | nodePath)[0:12]   ← derived, re-computable
editor     uniqueId = prefix + last dash-segment of clientId     ← random, but saved in post_content
```

So `get_block(uid)` needs a reverse index — an O(n) walk over `_styble_ai_page`
recomputing uids — not a new data model. This is the deck's Phase 1 centrepiece,
~80% present as a side effect of the `uniqueId` work.

- [ ] uid reverse index over the page store
- [ ] `get_block(uid)` · `update_block(uid, attrs)` — validates the merged result, returns `{ok, uid, changed[], warnings[]}`, never HTML
- [ ] `evals/attr-edit/cases.json` — ~40 instructions with exact expected diffs
- [ ] Scorer: three numbers — right key, right value, **collateral**

**Collateral is a hard zero.** A model that edits the heading *and* silently
restyles the button is worse than one that refuses, because nobody notices until later.

---

## Stage 3 — one correct section ⚪

Roughly what ships today, so **Stage 0's baseline is this stage's starting number**.

- [ ] Automated scorers (mostly reused from Stage 0)
- [ ] Rendered-screenshot contact sheet — structure passing says nothing about whether it *looks* right. One page, 20 sections, one rater, recorded verdicts

**If it fails:** that is the trigger for the pattern library (deck Phase 2). The deck
is explicit that patterns *are* the quality and freeform is the fallback. Build them
**because** this stage failed, not in case it does — building first destroys the evidence.

---

## Stage 4 — ⭐ the moat: conversational editing ⚪

The deck's differentiator, and the thing never built. What ships today under
"Edit with AI" regenerates the whole selection.

- [ ] Full tool surface: `get_page` · `get_block` · `insert_block` · `update_block` · `move_block` · `delete_block` · `duplicate_block`
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
| 1 — Foundation: page model + `resolve_attrs` + serialize | Prerequisite | **Mostly built.** Catalog ✅ · nesting rules ✅ · serializer ✅ · validator ✅ · uid page model ~80% (Stage 2 finishes it) |
| 2 — Pattern library + `search_patterns` / `insert_pattern` / `fill_slots` | Stage 3's failure branch | Not built, **on purpose** |
| 3 — Freeform `set_page_layout` fallback | Stages 0/3 | **Built, and currently the foundation rather than the fallback** — the inversion Stage 3 exists to judge |
| 4 — ⭐ Edit tools | Stages 2 + 4 | Not built |
| 5 — MCP | Stage 6 | Deferred |
| 6 — Proxy + credits | — | Out of scope until Stage 5 passes |

**Where we diverge from the deck, deliberately**

- **Language.** Deck says Python/LangGraph + a WP connector. We are all-PHP through Stage 5 — cheapest place to run experiments, and nothing in Stages 0–5 depends on the language. Revisit at Stage 6.
- **`resolve_attrs` coerces; our validator does not.** The deck's safety net is `alias → coerce → default`. `CONTRACT.md` states the opposite: pass or reject, with a reason, no silent repair. The 31 codes exist because of that choice. Take the deck's tool *shape*; keep the gate.
- **Freeform is our foundation, the deck's fallback.** Knowingly. Stage 3 decides whether it stays that way.

**Deck token levers, and their status**

| Lever | Deck claim | Status |
|---|---|---|
| Catalog prefix caching | −up to ~90% input | ✅ **Done on the Anthropic path** (uncommitted). Not on the OpenAI-compatible path — `cache_control` is Anthropic's parameter and strict endpoints reject unknown fields |
| Pattern-fill (content-only) | ✅ low | Not built — Stage 3's failure branch |
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

1. **Commit the prefix caching**, then **take the baseline** on both models. One command each. Everything downstream is unmeasurable without it, and the caching makes the run itself cheaper.
2. **Falsify the harness** — break the prompt on purpose, confirm the score drops. An eval that cannot go down is not measuring anything.
3. **Write Stage 1's block purpose lines.** Independent of 1 and 2, but must land *after* the baseline or it teaches us nothing.

---

## How to check progress yourself

```
php scripts/generate-catalog.php     # regenerate from Styble Pro
php scripts/validate.php             # 34 contract fixtures + code coverage
php scripts/test-generator.php       # the retry loop, against a stub provider
php scripts/test-page-applier.php    # headless layout maths + uniqueId + markup
php scripts/test-prompt.php          # invariants of the prompt and tool schema
php scripts/test-provider-body.php   # request body shape + cache prefix stability
php scripts/dump-prompt.php          # exactly what the model is told
```

None of those need WordPress or an API key. The eval runner is the only one that
does — and the only one that measures the model rather than the code.

```
ls evals/runs/                       # empty means there is still no baseline
```

**Rules that keep this file worth reading:** a stage moves to 🟢 only when its stated
bar is met. Cases are added when they fail in real use and **never removed to make a
number look better**. A bar is never moved after seeing a score. 🔴 is a real,
useful outcome — "the model cannot do this reliably" redirects the design, and
pattern-fill exists in the deck precisely because freeform is unreliable.
