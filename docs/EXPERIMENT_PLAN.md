# Styble AI — experiment plan

**Status:** Proposed — nothing below is built
**Date:** 2026-07-28
**North star:** [`styble-ai-architecture.html`](file:///Users/shifat/styble-ai-architecture/styble-ai-architecture.html)
**Supersedes as the working plan:** `STYBLE_BLOCKS_MIGRATION_PLAN.md` (its phases still
describe the destination; this describes the order and the evidence)

---

## 0. Why replan

We have built, in order: a catalog, a contract, a validator, two appliers, a sidebar,
a chat screen, and headless page generation. Every piece has unit tests and every one
of them passes.

And the output is still not right. Layouts come out broken. Generations fail. We only
found the zero-padding bug and the invented-enum bug by *looking at a page* — no test
we own could have caught either, because every test we own asks "did the code do what
the code says" and none asks "did the model do what the user wanted".

That is the actual problem, and it is not fixable by building more. **We have no way
to tell whether a change made the model better or worse.** Each fix so far has been a
guess validated by one eyeball.

So this plan inverts the order. Instead of building a capability and hoping, each
stage **isolates one capability, measures it against a fixed set of cases, and does
not proceed until it clears a stated bar**. Breadth is the reward for correctness, not
the route to it.

The destination does not change — it is still the deck's architecture. What changes is
that we stop assuming each layer works and start proving it.

### The reframe in one line

| Until now | From here |
|---|---|
| Build a feature, look at one output, move on | State a question, score N cases, meet a bar, move on |
| "It generates a page" | "It picks the right block 94% of the time (n=30, gemini-2.0-flash)" |
| Bugs found by browsing the site | Bugs found by a failing case with a name |

---

## 1. What we actually have, honestly

Worth stating plainly so the plan starts from the truth.

| Piece | State | Trust |
|---|---|---|
| `catalog.json` generated from Styble Pro | Works, self-verifying | **High** — regenerates, checks itself |
| `emit_layout` contract + validator (30 codes) | Works, 32 fixtures | **High** — but only checks legality, not quality |
| Headless applier (uniqueId, layout maths) | Works, 36 assertions, verified in WP | **High** |
| Providers (Anthropic + 9 OpenAI-compatible) | Works | **Medium** — no 429 handling |
| Section generation from a prompt | Runs | **Unknown** — never measured |
| Whole-page chat screen | Runs | **Unknown** — never measured |
| "Edit with AI" on a selection | Runs | **Low** — regenerates the whole selection, which the deck calls the naive approach |

The top four are plumbing and they are sound. **Everything involving the model is
unmeasured.** That is exactly the boundary this plan attacks.

### Where we diverge from the deck, deliberately

The deck specifies a Python/LangGraph backend with a WordPress connector plugin. We
are entirely in PHP inside WordPress. That stays true until Stage 6 — it is the
cheapest place to run experiments, and nothing in Stages 0–5 depends on the language.
The tool surface is designed to port.

---

## 2. Rules for every stage

1. **One question per stage.** If a stage answers two, split it.
2. **A fixed case set, committed to the repo.** Cases are added when they fail in real
   use, never removed to make a number look better.
3. **A stated bar, decided before the run.** Moving the bar after seeing the score is
   how measurement becomes theatre.
4. **Two models, two bars** — see §3.
5. **A stage may fail.** "The model cannot do this reliably" is a valid, useful result
   that redirects the design. Pattern-fill exists in the deck precisely because
   freeform generation is unreliable; if Stage 3 fails, that is the finding.
6. **No UI until the capability underneath it passes.** The chat screen stays where it
   is, unadvertised, until Stage 5.

---

## 3. Which model is the bar

Two, because they answer different questions.

| Role | Model | Question it answers |
|---|---|---|
| **Target** | `claude-opus-5` (or `claude-sonnet-5`) | Is the *design* right? A failure here is our prompt, schema or contract. |
| **Floor** | `gemini-2.0-flash` (free tier) | Is it *affordable*? A failure only here is a model-capability limit. |

Score both, always. A gap between them tells you which kind of problem you have,
which is the single most useful signal in this whole plan.

`llama-3.3-70b-versatile` is retired as an eval model: 12,000 TPM cannot fit our
request, and the settings screen already records it emitting unparseable tool calls
for this schema.

---

## Stage 0 — The measuring stick

> **Question:** can we tell whether a change made the model better or worse?

Nothing else in this plan is possible without this, and it does not exist today.

| Build | Detail |
|---|---|
| `evals/<stage>/cases.json` | Each case: `id`, `prompt`, `expect`, and `why` (what this case exists to catch). |
| `scripts/eval.php` | Runs a case set against a provider, scores it, writes a run record. Paces requests to respect free-tier TPM, and handles 429 with the delay the provider reports. |
| `evals/runs/<date>-<model>.json` | Committed. Score, per-case verdict, the raw model output. |
| Response cache | Keyed by (prompt, model). Re-scoring after a scorer change costs nothing — this matters more than it sounds, because scorers get fixed often. |
| `scripts/eval.php --compare A B` | Two run records in, per-case regressions out. |

**Done when:** the same case set scored twice from cache gives a byte-identical result;
a baseline exists for the current section pipeline on both models; and deliberately
breaking the prompt makes the number go down.

**Note:** this also fixes the 429 problem properly, because the runner has to solve it
to work at all.

---

## Stage 1 — Does it pick the right block?

> **Question:** given a need in plain words, does the model choose the right Styble
> blocks — with no layout, no attributes, no tree to distract it?

This is first because everything downstream is worthless if it is wrong, and because
it is the cheapest thing in the plan to test: tiny prompt, tiny answer, no nesting.

| Build | Detail |
|---|---|
| `choose_blocks` tool | Input: the need. Output: a flat list of block names with a one-line reason each. No attrs, no nesting, no layout. |
| Block purpose lines | See the finding below — this is the substance of the stage. Add `purpose` and `useWhen` / `dontUseWhen` per allowlisted block, from a curated table in `generate-catalog.php`, and print them in the block reference. |
| `evals/block-choice/cases.json` | ~30 needs → expected block set, plus an `acceptable` set of defensible alternates. Scoring an alternate as a pass is not softness; there are genuinely several right answers for "three feature cards". |

**Done when:** ≥90% acceptable-match on the target model and ≥80% on the floor model,
n≥30.

### The finding that makes this stage urgent — verified, not suspected

The model is currently told **nothing whatsoever about what any block is for.** The
system prompt's block reference is names, attribute keys and nesting rules. It never
prints a description, and the descriptions would not help if it did — ten of the
eleven allowlisted blocks carry a tautology in `block.json`:

```
advanced-text   "Styble Advanced Text block."
info-box        "Styble Info Box block."
icon-list       "Styble Icon List block."
container       "Styble Container block."
…
separator       "Add visual separation between content sections with
                 customizable divider lines."   ← the only real one
```

So when the model needs "three feature cards with an icon, a heading and a line of
text", nothing in its context says that `info-box` **is** that card, or that
`advanced-text` already carries a sub-heading so a heading does not need two blocks, or
that `icon-list` is the benefit-tick list. It is guessing from names.

That is very likely a large share of "the layouts look broken", and it costs a curated
table to fix — no model change, no schema change, no architecture change. It is the
single cheapest suspected win in this document, which is why the plan starts here
rather than with anything more interesting.

---

## Stage 2 — Does it set the right attribute to the right value?

> **Question:** given one existing block and an instruction, does the model change the
> attribute the user meant, to the value they meant, and nothing else?

The validator now guarantees the value is *legal*. This measures whether it is
*correct* — a different and much harder thing.

| Build | Detail |
|---|---|
| Canonical page model (minimal) | A uid-keyed block tree, the deck's Phase 1 centrepiece and the thing we skipped. Just enough to address one block by uid. |
| `get_block(uid)` · `update_block(uid, attrs)` | Two tools. `update_block` runs the existing validator on the merged result and returns `{ok, uid, changed[], warnings[]}` — never HTML. |
| `evals/attr-edit/cases.json` | ~40 instructions against fixed starting blocks, each with an exact expected attribute diff. "make this an h2" → `{textHTMLTag: "h2"}`. "centre the text" → `{textAliment: "center"}`. "put the icon on the left" → `{iconPosition: "left"}`. |
| Scorer | Exact diff comparison. Three numbers, not one: **right key**, **right value**, and **collateral** (attributes changed that should not have been). |

**Done when:** ≥90% right-key-and-value on the target model, **0% collateral** on both.

Collateral is a hard zero. A model that edits the heading *and* silently restyles the
button is worse than one that refuses, because the user will not notice until later.

---

## Stage 3 — Can it build one correct section?

> **Question:** now compose Stages 1 and 2 — blocks, attributes, nesting and layout —
> into a single section.

This is roughly what we ship today, so Stage 0's baseline already tells us where we
start. The difference is that we will know.

| Build | Detail |
|---|---|
| `evals/section/cases.json` | ~20 briefs across hero / features / pricing / testimonial / CTA / FAQ. |
| Automated scorers | First-try validator pass rate (**no retry** — the retry masks exactly what we are trying to measure); correct block choice reused from Stage 1; padding present; no placeholder copy. |
| Rendered-screenshot check | Render each result and capture it. Structure passing tells you nothing about whether it *looks* right — this is the stage where a human still has to look, so make looking cheap: a contact sheet of 20 sections on one page. |

**Done when:** ≥85% first-try valid on the target model; 100% valid after one retry;
and a human rates ≥80% of the contact sheet as "would ship after minor edits".

**If it fails:** this is the trigger for the pattern library (deck Phase 2, the Spectra
lesson). The deck is explicit that patterns *are* the quality and freeform is the
fallback. Do not build patterns before this stage — build them because of it.

---

## Stage 4 — The moat: conversational editing

> **Question:** can a user change one thing about an existing page by asking, without
> anything else moving?

The deck's ⭐ phase, and the thing we have never built. What ships today under "Edit
with AI" regenerates the whole selection, which is the naive column of the deck's own
comparison table.

| Build | Detail |
|---|---|
| Full tool surface | `get_page` · `get_block` · `insert_block` · `update_block` · `move_block` · `delete_block` · `duplicate_block`. Every mutator validates on call and returns a diff. |
| The loop | Tool-calling loop with `tool_choice: auto`, driven by Anthropic's tool runner where available. Destructive tools gated. |
| Diff application | Apply to the page model, re-serialize only the changed subtree. |
| `evals/edit/cases.json` | ~25 instructions against fixed pages: "make the hero dark", "add a third feature column", "move testimonials above the CTA", "delete the FAQ". |
| Scorer | Intended change achieved; **collateral diff is empty**; block count changed only where expected. |

**Done when:** ≥90% intended change on the target model, 0% collateral, and no case
requires more than 6 tool calls.

---

## Stage 5 — Whole pages, and the chat screen earns its place

> **Question:** does a multi-section page hold together — no repetition, sane order,
> consistent voice?

Only now does the existing chat screen get promoted from "runs" to "works". Most of the
plumbing is already built and verified; what is unproven is the *planner*.

| Build | Detail |
|---|---|
| `evals/page/cases.json` | ~10 page briefs. |
| Scorers | Every section valid; no two sections making the same point; heading hierarchy sane across the page (one `h1`); section count within 4–6 for a standard brief. |
| Pacing | Reuse Stage 0's rate-limit handling in the chat loop, so a free-tier user gets a slow page instead of a failed one. |

**Done when:** ≥80% of pages need no structural edit before a human would publish them.

---

## Stage 6 — Distribution (MCP), and the backend question

Unchanged from the deck: wrap the one executor built in Stage 4 as a Streamable-HTTP
MCP server. This is also the natural moment to revisit PHP-in-WordPress versus the
deck's Python/LangGraph service — by then the tool surface is defined by its tests, so
porting it is mechanical rather than speculative.

Not planned in detail here. It should not be, until Stage 4 passes.

---

## 4. What happens to what we already built

Nothing is deleted. Specifically:

| Thing | Fate |
|---|---|
| Catalog, contract, validator, appliers | **Keep.** These are the assets. Stages 1–5 all sit on them. |
| Headless page generation + page store | **Keep**, unproven until Stage 5. |
| Chat screen | **Keep**, but stop adding to it until Stage 5. It is currently a UI for a capability we have not measured. |
| "Edit with AI" on a selection | **Replace** at Stage 4. Leave it working until then. |
| Groq / llama-3.3-70b as a recommended model | **Demote** now. It cannot fit our request in its TPM budget. |

---

## 5. Order of work, and the first three things

1. **Stage 0's runner.** Nothing can be judged without it. It also forces the 429
   handling we need anyway.
2. **Baseline the current pipeline** on both models. Expect this to be uncomfortable —
   that is the point, and it is the number every later stage is measured against.
3. **Stage 1's block purpose lines.** Verified above: the model picks blocks from a
   bare list of names and attribute keys and is told nothing about what any of them is
   for. Cheapest suspected win in the document, and measurable the moment step 1
   exists.

Steps 1 and 3 are independent — the purpose lines can be written while the runner is
being built. Step 2 has to sit between them, because a baseline taken *after* the
descriptions land tells us nothing about whether they helped.

---

## 6. Open decisions

| Decision | Default | Revisit at |
|---|---|---|
| Human-in-the-loop scoring for "does it look right" | Contact sheet, one rater, recorded verdicts | Stage 3 |
| Pattern library before or after freeform | **After** — build it because Stage 3 fails, not in case it does | Stage 3 |
| Stay in PHP/WordPress vs the deck's Python service | Stay, through Stage 5 | Stage 6 |
| Hosted proxy + credits | Out of scope until Stage 5 passes | — |
| Eval cost ceiling per full run | Set one before the first run | Stage 0 |
