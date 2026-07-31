# Styble AI ← ZIP AI: a selective port

**Rebuild styble-ai's control flow on ZIP AI's concepts, using its code as the
reference implementation — and deliberately leave four of its subsystems behind.**

Named a *selective* port because it is one. An earlier draft was titled
"alignment", which overclaimed: this adopts roughly twenty of ZIP AI's mechanisms
and refuses four of its subsystems, ~31,000 lines' worth. §2 is what we take, §3 is
what we don't, and §3 is **settled** — see the decision record below.

Companion to [`SPECTRA_AI_IMPLEMENTATION.md`](SPECTRA_AI_IMPLEMENTATION.md), which
documents what ZIP AI actually does.

Read alongside [`ROADMAP.md`](ROADMAP.md), which owns the honest state, and
[`EXPERIMENT_PLAN.md`](EXPERIMENT_PLAN.md), which owns the stage bars. Where this
plan and the roadmap disagree about readiness, **the roadmap wins** — it is the one
with measurements behind it.

## Decision record

Settled 2026-07-30. Recorded here in the roadmap's own idiom so it is not
re-argued mid-phase.

| # | Decision | Basis | Revisit when |
|---|---|---|---|
| S1 | **Keep the loop local and in PHP.** No remote brain. | BYO key is the product. A local loop is testable offline against a stub, as `test-generator.php` already is. Phase F keeps the door open at zero cost. | The business decides to ship hosted. Then the brain becomes a *client* of the Phase F surface, not a rewrite. |
| S2 | **Headless stays the primary write path.** The browser bridge is for live unsaved sessions only. | `class-page-applier.php` does the same mutations headless with 42 checks. ZIP AI's editor tools cannot run headless, which is why they need a capability handshake, a sessionStorage dedup map and a 20s timing invariant. | Never as a reversal — Phase C adds the bridge *beside* headless, not instead. |
| S3 | **Keep catalog-driven block attributes.** No className/JIT contract. | Styble Pro has no utility-class engine; Spectra's is 14,303 lines. Adopting it also discards the validator's exact per-block attribute knowledge. | **The one arguable refusal.** 22 of 26 baseline validator errors are attribute-shape confusion — evidence *for* Spectra's approach. If shape errors persist after Phase 0.1 and E.3, re-open. |
| S4 | **Tool surface stays inside the page.** No site management, no WP-CLI, no arbitrary REST, no code snippets. | 11,103 lines of abilities + 5,697 of snippets, and ZIP AI's entire §11 guardrail stack exists *because* of them. We inherit one item (protected options) as a cheap backstop and skip the rest by not building the hazard. | A written argument per tool. §3d is a boundary, not a backlog. |
| S5 | **New work ships without tests.** The eight existing suites stay and stay green; the per-phase suites in §6–§11 are not written. | Owner's decision. | Invariant 16 records what this trades away — the new engines are the uncovered surface, and the guards ported from ZIP AI are the specific thing going unasserted. |

S3 is the only one with a live trigger. Phase 0.1 is, among other things, a cheap
test of whether the attribute contract is salvageable — and it runs before any of
this is committed.

---

## 0. The single decision this plan makes

ZIP AI and styble-ai answer the same question with opposite control flow.

```
styble-ai today   prompt → ONE forced tool call → whole tree → validator → applier
ZIP AI            prompt → agent loop → N tool calls → each mutates WP live
```

styble-ai generates a **document**. ZIP AI **operates a machine**.

That difference is the whole plan. Everything below is downstream of adopting
ZIP AI's inversion: **the model stops emitting the artifact and starts driving the
editor, one addressable call at a time.**

The roadmap already names this as Stage 4 and calls it "⭐ the moat". ZIP AI is
proof that it works, at what cost, and with which specific failures. So this plan
is not a change of direction — it is Stage 4 with a reference implementation
attached, and Stages 2/6 folded in because ZIP AI shows they are the same build.

**One correction to the obvious reading of that.** "The model drives the editor"
does *not* mean everything becomes a tool call. ZIP AI runs **three** engines — a
one-shot call for scoped rewrites, the agent loop for multi-step work, and a
server-side service for whole-page builds — and the third deliberately hides its
write channel from the model. See §4a. Adopting the inversion means adopting that
split too, not collapsing everything into the loop.

---

## 1. What we already have that ZIP AI had to build

This is the reason the plan is affordable. Do not rebuild any of it.

| ZIP AI piece | Our equivalent | Trust |
|---|---|---|
| Block vocabulary in the brain's prompt | `catalog/catalog.json`, generated, self-verifying — 23 blocks, 13 allowlisted, 63 attrs, 23 layouts | **High** |
| `partitionAttrs` against `getBlockType()` | `class-validator.php` — 31 codes, 47 fixtures, coverage-checked | **High**, and stricter |
| `editor/apply-change` clientId targeting | `class-page-model.php` — uid index, `get_block`, `update_block`, 38 checks | **High** |
| Headless block writing | `class-page-applier.php` — layout maths, `uniqueId`, 42 checks | **High** |
| Editor-side apply | `assets/applier.js` — mirrors the catalog layout table | **High** |
| Prefix cache discipline | `class-anthropic-provider.php` + 24 body/prefix checks | **High** |
| Site memory / brand facts | `class-brand-context.php` ← `styble_global_settings` | Medium |
| Token accounting | `class-usage-tracker.php` + Token Usage screen | Medium |
| **Design Library (`lib/gutenberg-templates/`)** | **`styble-patterns-provider` + Styble Pro's Ready Patterns / Ready Pages / Popup Library** | **Built and serving** |
| Provider abstraction | `class-provider-factory.php` — Anthropic + 9 OpenAI-compatible | Medium |

**The pattern library finding matters more than anything else in this document.**
Spectra's whole first-generation AI is "human-designed templates + AI-written copy"
(§14a of the companion doc) — and we already have the serving half *and* three
consumer surfaces. It is simply not AI-addressable yet. The roadmap defers the
pattern library to "Stage 3's failure branch"; that framing is now wrong, because
the expensive part is already paid for. See Phase D.

Seven WP-free suites, no WordPress, no API key. **Every phase below adds a suite
and none is allowed to remove a check.**

---

## 2. What we take from ZIP AI

Ranked by value per unit of work, which is not the same as ranked by size.

| # | Take | From | Why |
|---|---|---|---|
| 1 | **Unknown-key reporting** | `apply-change`'s `partitionAttrs` | Turns a silent no-op into a learnable error. Our validator rejects; theirs *teaches*. |
| 2 | **Agent loop with N tool calls per turn** | brain `runTurn` + `AgentBrowserLoop` | The inversion. Stage 4. |
| 3 | **Abilities-shaped tool registry** | `Abstract_Ability` + `Ability_Loader` | One file per tool, auto-discovered, uniform validate/rate-limit/log. |
| 4 | **Fail-safe `tool_type`** | `Abstract_Ability::get_tool_type()` | Their documented bug: default `READ` let a forgotten override bypass approval. |
| 5 | **Model-addressed error text** | `out_of_scope`, `unknown_block` | Errors that say what to do instead. |
| 6 | **Scope lock from editor state** | `page_wide = !hasLiveSelection` | Selection confinement the prompt can't defeat. |
| 7 | **Partial apply with `failed[]` by index** | `apply-change` | One bad op doesn't lose the good ones. |
| 8 | **Pre-mutation in-flight marker** | `core/rpc-dedup.js` | Retry safety that survives a crash mid-apply. |
| 9 | **Read-first-write pairing** | `$resource` + symmetric get/set tools | Three read/write pairs, one per substrate. |
| 10 | **`dry_run` auto-injected** | `get_final_input_schema()` | Free preview for every destructive tool. |
| 11 | **Canonical-verb tool naming, regex-enforced** | `check_ability_format` | Guessable tool names. Cheap; compounds. |
| 12 | **`get_output_schema()`** | `Abstract_Ability` | Response contract as schema, not prose. |
| 13 | **Rendered-truth reads** | `get-context`'s `computed` | Edit relative to reality, not authored tokens. |
| 14 | **Content-ownership detection** | `get-context` leaf/rendered compare | Catches dead-lever attrs with zero per-block knowledge. |
| 15 | **Protected-options backstop** | `pre_update_option_<key>` filter | One hook covers every write surface. |
| 16 | **Write-time lint with the fix in the error** | `Snippet_Lint` | Generalizes to any "model keeps hand-rolling what the schema offers". |
| 17 | **A separate one-shot engine** | Quick Edit — "single LLM call, no `runTurn`" | A full agent turn to rewrite one heading is waste, and loses native undo. §7.7 |
| 18 | **Section fan-out with bin-packed batches** | `partitionTargets`, 80/40/24000 caps | One-shot scales to a whole section's copy without becoming a loop. |
| 19 | **Page building stays a service, write channel hidden from the model** | `PageDeliveryService` + `visibility:'internal'` | The LLM edits; a service builds. §7 B.0 |
| 20 | **One chat endpoint, situations as context fields** | `/agent/chat/stream` + `is_block_editor` / `page_wide` | Kills per-situation routing. §7 B.0 |

---

## 2b. Where we improve on it

Porting a mechanism is not the same as porting its limits. Eight places where ZIP AI's
version is the weaker half, and we have a structural reason to do better. Each is
wired into the phase that owns it — this table is the index, not the spec.

**The context that reframes all of them: ZIP AI ships no tests.** No jest config, no
test files, no PHPUnit anywhere in the plugin — verified 2026-07-30. Its most
dangerous code is `apply-change` (1,383 lines, seven guards) and the idempotency
decision, and `core/rpc-dedup.js` was *explicitly restructured* as a dual-mode module
"so the idempotency logic is unit-tested instead of living only inline in the browser
IIFE" — with no shipped test to do it. Their harness is not in the artifact.

So the honest framing of this whole port: **we are taking a set of well-reasoned,
production-hardened, unverified mechanisms and giving them the tests they never had.**
That is where most of the value is.

| # | Improvement | Their limit | Our reason we can do better | Phase |
|---|---|---|---|---|
| I1 | **`unknown_attrs` names the fix, not just the miss** | `registeredAttrKeysOf()` reports `tagName` invalid but cannot say `htmlTag` was meant | The catalog knows every legal attribute per block, its type, and its *verified* enum (decision #9). Alias table + edit distance over the block's real names | B.5 |
| I2 | **Do NOT copy their degrade-open on unknown blocks** | `if (!t \|\| !t.attributes) return null` → `partitionAttrs` treats **everything** as valid, so an unregistered block gets arbitrary attrs written | Our validator rejects unknown blocks outright (`block_unknown`, `block_not_allowlisted`). Silent damage is the failure mode design principle 5 exists to prevent | B.5 |
| I3 | **Golden-value test on the batch estimator** | `est = ceil(chars/2) + count*64 + 4096` "MUST equal the brain's `EST_CEILING`" — two repos, two languages, held by a comment. Nothing fails if one side drifts | One process, one method, one test with fixed inputs and a pinned expected value | B.7 |
| I4 | **Three-state rendered check** | `computedOf()` returns `null` when the canvas node is unmounted, so the model cannot tell "no divergence" from "could not check" — and may conclude an attribute works when nothing was verified | Return `verified` / `diverged` / `unchecked`. Costs nothing; removes a false-confidence path | B.4 |
| I5 | **Build-time check that an attribute renders anything** | Same hole as ours, unclosed on both sides | `generate-catalog.php` already reads Styble Pro's source, and its CSS lives in `blocks/Includes/Styles/*.php` + `Css_Helpers`. Refuse to allowlist an attribute no generator references. **Closes invariant 17 and decision #13** | E.5 |
| I6 | **Scope lock derived, never transmitted** | The brain *stamps* `scope_lock` on the envelope; enforcement is real but the trigger is remote and optional. Forget to stamp → no lock | Derive it in the executor from the request's own selection. Cannot be forgotten because it is never on the wire | B.6 |
| I7 | **`dry_run` default-on for an unread resource** | They inject `dry_run` into every destructive schema, default `false`, and built `$resource` for read-first-write pairing — then never wired the two together | Same two mechanisms, connected: a destructive call with no prior read of that resource **this turn** runs the dry pass first and returns the diff | E.2 |
| I8 | **Per-turn and per-tool cost, on screen** | Credits are metered centrally and invisibly per tool, so "did the loop cost more than one big call" is unanswerable from inside | `class-usage-tracker.php` and the Token Usage screen already exist. Add a `turn` operation and per-tool rows. Turns §15's open risk into a column | B.2 |

### A note on how these get verified

An earlier draft made this section's principle *"a ported guard without a test is not
a ported guard"* — every mechanism arriving with the verification ZIP AI shipped
without.

**That was withdrawn 2026-07-30 (decision S5): new work is written without tests.**
See invariant 16 for what that changes and what risk is now carried instead.

The eight existing suites are unaffected and remain the regression floor:

```
validate 47 fixtures + 31 codes · page-applier 42 · page-model 38 · prompt 42
provider-body 27 · usage-tracker 43 · credentials 48 · generator 7
```

So the catalog, the validator, both appliers, the uid page model, the prompt, the
request bodies, usage accounting and credentials stay covered. **The new engines do
not.** I1–I8 are still worth doing — they are improvements to the design, not to the
test suite — but I3 in particular loses most of its point, since "pin the numbers"
was a test. It survives as "one method, one constant", which is the weaker half.

---

## 3. What we refuse, and why

**Settled — S1–S4 in the decision record.** Each is a place where copying ZIP AI
would make styble-ai worse, or would build a different product. The reasoning is
kept in full here so that a future revisit argues against the actual basis rather
than a summary of it.

Measured cost of the refusals, for scale:

| Refused | Lines it would add |
|---|---|
| GBS className/JIT engine (S3) | **14,303** in Styble Pro, plus inverting all 63 catalog attrs |
| Site-management abilities (S4) | **11,103** |
| Code snippets (S4) | **5,697** |
| Remote brain (S1) | a Node service + Laravel relay + Redis + credit system |

≈31,000 lines of ported subsystems, a CSS compiler inside Styble Pro, and a SaaS.
That is a company, not a plugin milestone — and none of it moves Stage 4, which is
the thing that is not built.

### 3a. The remote brain — refused

ZIP AI ships no model. Prompting, planning, and model calls live on
`brain.zipwp.com`; the plugin is a tool host with a credential.

We keep the loop **local and in PHP**. Reasons, in order:

- BYO key is the current product. A hosted brain is the *business* layer, and the
  roadmap defers it until Stage 5 passes. Nothing in Phases A–F needs it.
- Our three High-trust assets (catalog, validator, applier) are PHP with PHP
  tests. Moving the loop out means either porting them or calling back in — ZIP AI
  chose "call back in", which is why it needs MCP, App Passwords, HMAC secrets,
  Redis BRPOP, and a 20-second timing invariant.
- A local loop is **testable offline against a stub provider**. `test-generator.php`
  already proves the retry loop this way. That property is worth more than
  anything a remote brain buys us at this stage.

So: build ZIP AI's `AgentBrowserLoop` **shape** as `class-agent-loop.php`, in
process. Phase F then exposes the same tool surface over MCP, which is how a remote
brain becomes possible *later* without a rewrite.

### 3b. The browser as a required participant in every write — refused

ZIP AI's editor tools cannot run headless. That forces: a `rpc` capability
handshake, a sessionStorage dedup map, a bounded-retry reply POST, and the
invariant that reply time must stay under `BRAIN_EDITOR_RPC_TIMEOUT_MS` — all to
make a round-trip through a browser tab behave transactionally.

`class-page-applier.php` does the same mutations **headless in PHP**, with 42
checks. We keep that as the primary path.

The bridge is added in Phase C for one reason only: editing a **live, unsaved**
editor session, where `wp.data` genuinely is the only source of truth. Two paths,
explicitly:

```
headless  (page store)      → class-page-model.php → class-page-store.php → post_content
live      (open editor)     → browser bridge → wp.data dispatch
```

Same tool names, same schemas, two executors — like ZIP AI's `rest_api` vs `js_rpc`
split, but with the headless side *complete* rather than a fallback.

### 3c. The GBS Tailwind-JIT className contract — refused, and this one is load-bearing

**Verified: Styble Pro has no `GlobalStyles/`, no `JitCompiler`, no `ClassRegistry`.**
Nothing exists to compile `md:hover:scale-105` or `px-[71px]` into CSS.

Spectra's model — model writes Tailwind-ish class tokens, a 4,140-line JIT compiles
them, and per-block visual attrs are *banned* — is elegant and plays to a strength
(Tailwind is in every model's training data). It is also a **large new subsystem
plus a whole-catalog inversion**: our 63 editable attributes are exactly the eight
kinds of thing ZIP AI forbids.

We keep **catalog-driven block attributes** as the styling contract. Consequences
accepted:

- We do not get "the model already knows this grammar" for free.
- We do keep a validator that knows every legal attribute and value per block — the
  thing ZIP AI has to approximate with `unknown_attrs` feedback loops.
- Decision-log entries #13/#14/#15 (attributes that render nothing, overlay modes
  that lie, `textFillBg` clipping an invisible heading) are all *catalog* findings.
  They stay valuable. Under a className contract they'd have to be re-found.

**Revisit trigger:** Styble Pro ships a utility-class engine. Not before.

What we *do* take from §10 is the **ownership model**, which is contract-independent:
a visual property is set by exactly one layer, and to change it you edit the current
owner rather than stacking a new declaration. That becomes a rule in `get_block`'s
response (Phase B).

### 3d. Code snippets, WP-CLI runner, plugin/theme lifecycle tools — out of scope

ZIP AI is a **site manager**: 18 abilities covering plugins, themes, media, fonts,
WP-CLI, arbitrary REST, and executable PHP/JS/CSS snippets. That is a different
product with a much larger blast radius — hence a 16-capability denylist, a
shell-operator rejection, an auto-disable-on-fatal snippet executor, and a 715-line
lint.

styble-ai builds pages. Our tool surface stays inside **the page**. No
`run-wp-cli`, no `run-rest-request`, no snippets.

That is also a security posture, not just scope: the entire §11 guardrail stack
exists because those tools exist. We inherit **one** item from it (protected
options, Phase E) as a cheap backstop, and skip the rest by not building the
hazard.

### 3e. Spectra's `ai/v1/content` business-details fill — superseded

`zipwp_user_business_details` → remote copy generation → per-block placeholder swap
on import is Spectra's *first* generation. We have `class-brand-context.php` reading
`styble_global_settings`, and generation is already live rather than cached per
category. Phase D takes the **slot-filling idea**, not the AJAX pipeline.

---

## 4. Target architecture

### 4a. Three engines, not one

ZIP AI does **not** route by situation. "Create a page", "add a section", "edit this
heading" are not separate endpoints — its chat surface is a single
`/agent/chat/stream`, and what varies is two booleans in the context payload.

But it does run **three architecturally distinct engines**, chosen by scope:

| Engine | ZIP AI | Ours | When |
|---|---|---|---|
| **One-shot** | `/inline-edit/stream` — single LLM call, explicitly *no* `runTurn`, applies via `setAttributes` | one-shot path | a scoped rewrite: one block, or one section's text |
| **Agent loop** | `/agent/chat/stream` — `runTurn`, N tool calls, scope lock | `class-agent-loop.php` | multi-step or cross-block work |
| **Service** | `PageDeliveryService` / `ParallelPageBuilderService` — server-side, writes back through **one LLM-hidden** ability | `class-page-planner.php` + per-section pipeline | whole-page generation |

The third row is the finding that most changes this plan. ZIP AI has **no
page-creation ability** among its 18. Whole-page building is a brain-side service
that writes into WordPress through `zipai/run-rest-request`, which is marked
`meta['visibility'] = 'internal'` and filtered out of the tool list before the model
ever sees it. **The LLM edits; a service builds.** styble-ai already has that shape.

### 4b. The picture

```
                    ┌──────────────────────────────────────────┐
  scoped rewrite ──►│  ONE-SHOT                                │
                    │  single provider call, no loop           │
                    └───────────────┬──────────────────────────┘
                                    │
  multi-step edit ─►┌───────────────┴──────────────────────────┐
                    │  class-agent-loop.php                    │
                    │  the local "brain": turn loop,           │
                    │  tool_choice:auto, N calls per turn,     │
                    │  approval gate, budget cap               │
                    └───────────────┬──────────────────────────┘
                                    │  resolves tool → executor
                    ┌───────────────▼──────────────────────────┐
                    │  class-tool-registry.php                 │
                    │  auto-discovers includes/tools/**        │
                    │  → schemas for the provider              │
                    │  → validate · rate-limit · log · dry_run │
                    └──────┬──────────────────────┬────────────┘
                           │ headless             │ live editor
             ┌─────────────▼────────┐  ┌──────────▼───────────────┐
   whole ───►│ class-page-model.php │  │ assets/bridge.js         │
   page      │ class-page-store.php │  │ tool handlers → wp.data  │
   (service) │ class-page-applier   │  │ POST /styble-ai/v1/      │
             │        ↓             │  │      tool-reply          │
             │   post_content       │  └──────────────────────────┘
             └──────────┬───────────┘
                        │ EVERY write passes, from all three engines
             ┌──────────▼───────────┐
             │ class-validator.php  │  31 codes · never coerces
             └──────────────────────┘
                        ▲
             ┌──────────┴───────────┐
             │ catalog/catalog.json │  generated from Styble Pro
             └──────────────────────┘

Phase F adds:  POST /styble-ai/v1/mcp   (JSON-RPC 2.0, same tool surface)
```

Three entry points, **one validator, one pair of appliers**. That is the invariant
that makes three engines affordable rather than three times the risk.

New files, all phases:

```
includes/
  class-tool-registry.php        ← Ability_Loader + Tool_Registry, merged
  class-tool.php                 ← Abstract_Ability equivalent
  class-agent-loop.php           ← the local brain
  class-one-shot.php             ← the second engine (§7.7)
  class-turn-context.php         ← getContext() equivalent
  class-approval-gate.php        ← tool_type × read_only_actions
  class-tool-log.php             ← Event_Logger equivalent
  class-mcp-controller.php       ← Phase F
  class-pattern-library.php      ← Phase D
  class-protected-options.php    ← Phase E
  tools/
    page/get-page.php            insert-block.php   move-block.php
        get-block.php            update-block.php   delete-block.php
        duplicate-block.php      replace-block.php
    pattern/search-patterns.php  insert-pattern.php fill-slots.php
    media/search-photos.php      set-image.php
assets/
  bridge.js                      ← wp-bridge-host equivalent, trimmed
  tools/                         ← per-tool browser handlers
scripts/
  test-tool-registry.php   test-agent-loop.php   test-turn-context.php
  test-approval-gate.php   test-pattern-tools.php  test-one-shot.php
evals/
  attr-edit/cases.json     edit/cases.json       pattern/cases.json
```

---

## 5. Phase 0 — the gate (must clear before Phase A)

**Non-negotiable, and it comes from the roadmap, not from ZIP AI.**

The roadmap's "next three things" are: fix the colour shape ambiguity, bound the
±10-point spread, then falsify the harness. Its closing line:

> landing a change we cannot evaluate is how the project got here.

This plan is a large change. Landing it on an unmeasurable baseline reproduces
exactly that failure, at greater cost.

- [ ] **0.1** Fix the colour shape ambiguity. `listTextColor`, `btnTextColor`,
      `iconColor`, `titleTextColor`, `contentTextColor` are plain strings sitting
      beside two background-shaped attrs with no tag distinguishing them. 22 of 26
      baseline validator errors. Small prompt change, largest single win available.
- [ ] **0.2** Two more unchanged eval runs. Learn whether ±10 is the band.
- [ ] **0.3** Break the prompt deliberately; confirm the number moves *beyond* the
      spread. An eval that cannot move is not measuring.
- [ ] **0.4** Resolve blocker B3 — one shared `styble_ai_api_key` means two
      providers can't be held at once, so target-vs-floor is unavailable. Per-provider
      key options. ~20 lines in `class-settings.php` + `class-provider-factory.php`.

**Bar:** the spread is known, and a deliberate break moves the number outside it.

**Why 0.4 is in the gate:** every phase below changes model-facing surface. Without
target-vs-floor we cannot tell "our tools are bad" from "this model can't do it" —
the exact confound the roadmap flags on the current 65–75%.

---

## 6. Phase A — the tool host

Port ZIP AI's registry shape. **No model behaviour changes.** Existing generation
keeps working unchanged; this phase only re-homes it.

### A.1 `class-tool.php` — the base

Mirror `Abstract_Ability`, minus what we refuse.

```php
abstract class Styble_AI_Tool {
    protected $id;                  // 'page/update-block'
    protected $label;
    protected $description;
    protected $capability = 'edit_pages';
    protected $is_destructive = false;
    protected $read_only_actions = array();
    protected $resource = null;     // 'page' | 'block' | 'pattern' | 'media'
    protected $version = '1.0.0';

    abstract public function configure();
    abstract public function get_input_schema();
    abstract public function execute( $args );

    public function get_output_schema() { return array(); }   // declare it
    public function get_tool_type() {                          // FAIL-SAFE
        return $this->is_destructive ? self::ACTION : self::READ;
    }
    public function handle_execute( $args ) { /* the wrapper, below */ }
}
```

`handle_execute()` runs, in order — this is ZIP AI's sequence and the reason no tool
author can forget a step:

1. **Budget check** — tokens spent this turn vs the cap. (Ours, not theirs; we pay
   per call, they meter credits centrally.)
2. **Rate limit** — transient-backed, per user+tool.
3. **Validate + sanitize** against the final schema.
4. **`dry_run`** if destructive and requested.
5. `execute( $validated )`.
6. **Metrics** — ms + peak-memory delta.
7. **Log** via `class-tool-log.php`.
8. Catch `Exception` **and** `Error` separately; both still log.

`get_final_input_schema()` auto-injects `dry_run` into every destructive tool, as
theirs does.

### A.2 Naming, regex-enforced

Copy `check_ability_format` verbatim in spirit. Trim the verb list to what a page
builder needs:

```
get  list  search  insert  update  move  delete  duplicate  replace  fill  apply
```

Pattern `^[a-z0-9-]+\/(verb)-[a-z0-9-]+$`, `trigger_error` under `WP_DEBUG`.

### A.3 `class-tool-registry.php`

- `RecursiveDirectoryIterator` over `includes/tools/**`, PSR-4-ish namespace from
  path, skip `index.php`. **One file per tool, zero registry edits.**
- `get_schemas()` → the provider's `tools` array. **This replaces `class-prompt.php`'s
  single `emit_layout` schema** as the tool surface, and inherits its
  decision-log constraints wholesale:
  - #5 — no `$defs`/`$ref`. One of nine OpenAI-compatible endpoints failing to
    resolve a `$ref` breaks generation outright.
  - #10 — prompt grows ~29 chars/attr; schema grows ~700 chars/attr per depth
    level. **Grow the prompt freely; never grow `attrs.properties`.**
  - #1/#2 — the `attrs` union was measured and reverted twice.
- Third-party hook: `do_action( 'styble_ai_register_tools', $registry )`.

### A.4 The token win, stated honestly

Roadmap decision #10 plus the measured prompt surface:

```
today       system 15,380 + schema 11,039 = 26,419 chars/call, node at depth 6
after A     system 15,380 + N small tool schemas, no recursion
```

Granular tools kill the ×6 depth multiplier — the roadmap already predicts this at
Stage 4 ("attributes cost ×1 instead of ×6"). **Do not claim a number.** Measure
with `dump-prompt.php` extended to print per-tool schema sizes, and record it.

### A.5 Migrate generation onto the registry

`emit_layout` becomes `page/apply-layout` — one tool, same schema, same validator,
same applier. `class-generator.php`'s retry loop calls the registry instead of the
provider directly.

**Bar:** all seven existing suites pass unchanged. One eval run scores inside the
Phase-0 spread. If it drops outside, Phase A broke something — stop and find it.

- [ ] `scripts/test-tool-registry.php` — discovery, ID format rejection, schema
      shape, `dry_run` injection, fail-safe `tool_type`, rate limit, budget refusal,
      `Error` caught as well as `Exception`

---

## 7. Phase B — the agent loop (Stage 4, the ⭐)

The inversion. This is the phase that matters.

### B.0 Route collapse, and one explicit non-goal

**Retire three routes.** ZIP AI has one chat endpoint; situations are context
fields, not URLs.

| Today | After Phase B |
|---|---|
| `POST /styble-ai/v1/generate` | gone — `page/apply-layout` tool (A.5) |
| `POST /styble-ai/v1/chat/plan` | **kept**, as the service engine (below) |
| `POST /styble-ai/v1/chat/section` | **kept**, as the service engine (below) |
| — | `POST /styble-ai/v1/turn` — the loop |
| — | `POST /styble-ai/v1/rewrite` — the one-shot (§7.7) |

Left implicit, Phase B ships a fourth route beside three survivors and the
branching moves into a controller — which is exactly what the inversion is meant
to delete. So: `/generate` goes, and the two chat routes stay **only** because they
are the service engine, not because they are chat.

**Non-goal: whole-page generation does not become tool calls.**

`class-page-planner.php` + the per-section pipeline stay a **service**. The model
does not assemble a page by calling `insert-block` forty times.

The evidence is ZIP AI's own shape: no page-creation ability exists among its 18,
and page building runs server-side (`PageDeliveryService`,
`ParallelPageBuilderService`, `BuildPageFromPatternsTool`) writing back through a
single ability that is hidden from the model:

```php
$this->id = 'zipai/run-rest-request';
$this->meta['visibility'] = 'internal';   // filtered before the tool list ships
```

They arrived independently at the architecture styble-ai already has. `Parallel` in
that service name also implies concurrent section generation — something a
browser-mediated tool loop cannot do at all. Keep the pipeline; do not port it into
the loop.

What the loop *may* do is **call the service as one tool** —
`page/generate-page(brief)` — so "build me an about page" works in chat without
forty round-trips. One tool call, service does the work, validator on every section
as today.

### B.1 The page tool surface

Eight tools under `includes/tools/page/`. Two exist as page-model methods already
(38 checks) and just need wrapping.

| Tool | Type | Notes |
|---|---|---|
| `page/get-page` | read | outline only — §7.3 |
| `page/get-block` | read | ✅ built. Extend per §7.4 |
| `page/update-block` | action | ✅ built. Extend per §7.5 |
| `page/insert-block` | action | `before`/`after`/`inside` anchor → parent+index, resolved server-side |
| `page/move-block` | action | preserves uid (a splice) |
| `page/delete-block` | delete | |
| `page/duplicate-block` | action | mints new uids, returns them |
| `page/replace-block` | action | mints new uids, returns them |

ZIP AI's contract rule, adopted exactly: **operations that preserve identity say so,
operations that mint identity return the new ids** so the model can target them on
the next call. `applied[].new_uids`.

`$resource = 'block'` on all eight → read-first-write pairing for free.

**Not in these eight, flagged for whenever it is: a global-style tool.** No tool here
touches `styble_global_settings` — every one of the eight is scoped to a block. If a
`page/set-global-style` (or similarly-scoped) tool is ever added, it must patch data
and CSS together, not data alone. `styble-ai-demo`'s `GlobalStyle` class hit this
exactly and documented the fix (`docs/STYBLE_AI_DEMO_COMPARISON.md` §3): a colour
edit has to update **both** the data slot (`colors.presetColors`, whatever key
Styble Pro's `Global_Settings_Helper` calls it) **and** the derived
`--styble-{slug}` CSS variable inside the stored `rootcss` string — because the
frontend enqueue prints `rootcss` verbatim, a data-only write silently does not
render. Recorded here, not built here: `styble-ai` writes nothing to
`styble_global_settings` today, and adding a tool that does is a scope decision for
whoever authorizes it, not something to slip in alongside this note.

### B.2 The loop

`class-agent-loop.php`. Mirror `runTurn`:

```
run( $message, $context ) :
  messages = [ system, context_block, user ]
  loop up to MAX_STEPS (start 8):
    response = provider->call( messages, registry->get_schemas(), tool_choice:auto )
    track usage        ← class-usage-tracker.php, BEFORE parsing (existing rule)
    if no tool calls   → return assistant text
    for each tool call:
      gate = approval_gate->check( tool, args )
      if gate blocks   → append refusal, continue
      result = registry->execute( tool, args )
      append tool_result
```

Adopt from ZIP AI:

- **Partial apply.** A failed call records into `failed[]` by index and the loop
  continues. Earlier calls stay applied.
- **`refused` with `ok:true`.** A guard refusal is *not* an error — it returns
  successfully with the reason, so the model sees "you may not do that because X"
  instead of "the tool broke". Their `post_id` mismatch case, generalized.
- **Verify-before-retry.** A tool whose outcome is uncertain returns
  `ok:false, error:'apply_pending_no_confirmation'`, and the system prompt requires
  a `get_block` read before any retry. This is `IN_FLIGHT_MARKER` (§8) applied to a
  headless loop.
- **Budget cap** as a hard ceiling, per turn. `MAX_STEPS` alone is not enough — a
  loop of cheap reads is fine, a loop of full-section generations is not.

**I8 — make the loop's cost visible, so §15's risk stops being an argument.** The open
question is whether N granular calls cost more than one big call; granular schemas
shrink per-call cost while step count grows it, and the net is unknown. ZIP AI cannot
answer this from inside — credits are metered centrally and invisibly per tool.

We already have the instrument: `class-usage-tracker.php` records every response
*before* parsing (invariant 9), and **Styble AI → Token Usage** renders totals per
day/model/operation plus the last 200 calls. So add:

- a `turn` operation, so one conversational edit is one row with a total;
- per-tool attribution inside it, so an expensive tool is identifiable rather than
  averaged away;
- the step count, so "12 cheap calls" and "3 expensive calls" are distinguishable.

Then the risk becomes a column on a screen that already exists. This is close to free
and it is the difference between deciding the granular-tools bet on evidence and
deciding it on the deck's estimate.

### B.3 `class-turn-context.php` — the context block

ZIP AI's `getContext()`, minus what needs a browser. Every field earns its place;
this is where token cost accumulates silently.

```php
array(
  'page'    => array( 'post_id', 'title', 'outline' ),
  'scope'   => array( 'page_wide' => bool, 'selected_uid' => ?string ),
  'brand'   => Styble_AI_Brand_Context::summary(),
  'catalog' => array( 'allowlisted_blocks', 'layouts' ),
)
```

`outline` copies `buildPageOutline` and its two hard-won rules:

- **Labels come from live attributes only.** Never from serialized markup. Their
  note: stale `originalContent` → wrong section → wrong id → select-X-operate-Y.
  Ours is worse-exposed, because `post_content` is *derived* from `_styble_ai_page`
  — read the trees, never the markup.
- **Repeater detection.** `dominantRepeatedChildType` (a child block name occurring
  3+ times) and `nestedGrid` (exactly one child container whose children repeat —
  the `section > content > [card × N]` shape, which is precisely how our card
  layouts nest). So "add one more" routes to `duplicate-block` on the last child
  rather than authoring a new section.

`scope` copies their best small idea:

```php
$page_wide = ( null === $selected_uid );
```

Driven by real editor/UI state, never by the user's words — so a coincidental "all
sections" cannot silently unlock a scoped edit. Then enforce it, per §7.6.

### B.4 `get_block` extended — teach, don't just answer

Add three fields, each from a named ZIP AI mechanism:

| Field | From | What it gives the model |
|---|---|---|
| `editable` | already partly there | attr → type, enum, shape, default. **The catalog knows this exactly**; ZIP AI has to infer it. |
| `owner` | `get-styles`' `styleContext` | which layer currently sets each visual property, so the model edits the owner instead of stacking |
| `rendered` | `get-context`'s content-ownership check | for leaf text blocks, the value that actually renders |

`rendered` is worth the work. Their finding: when a leaf's rendered text differs
from its own authored attribute, that attribute is a **dead lever** — the value is
computed by a parent composite, and editing the child silently no-ops. We have this
hazard: `class-catalog.php` scans `block.json`, and decision-log #13 records two
attributes that were declared, defaulted sensibly, and **rendered by nothing** —
the AI set `advancedTextColor` for weeks with no effect.

Headless, we cannot call `getComputedStyle`. But we can do the cheaper half: flag
attributes the catalog cannot trace to a CSS generator. That is decision #13 turned
into a build-time check — see **E.5**, which closes it properly at catalog-generation
time rather than per request.

Scope it exactly as they do — leaves only, text attrs only, both sides normalized,
never `number` (it self-formats: 1000 → "1,000").

**I4 — three states, not a nullable one.** Their `computedOf()` returns `null` when
the canvas node is not mounted (off-screen, virtualized). So `null` means both *"no
divergence"* and *"could not check"*, and a model reading it may conclude an attribute
works when nothing was verified — a false-confidence path that is invisible in the
reply. Return the state explicitly:

| State | Meaning |
|---|---|
| `verified` | read, and authored matches rendered |
| `diverged` | read, and they differ — the attr is likely a dead lever, redirect the edit upstream |
| `unchecked` | not readable here (headless, unmounted, or a skipped type) — **draw no conclusion** |

"I don't know" becomes a value the model can act on instead of an absence it will
misread. Costs one string.

### B.5 `update_block` extended — the highest-value single change

**Return `unknown_attrs`.**

```php
array(
  'ok'            => true,
  'uid'           => 'stb-a1b2c3d4e5f6',
  'changed'       => array( 'headingColor' => array( '#111', '#fff' ) ),
  'unknown_attrs' => array( 'tagName' ),
  'hint'          => 'tagName is not an attribute of styble/container. Did you mean htmlTag? '
                   . 'Legal here: sectionBg, sectionPadding, layout, containerWidth, …',
  'warnings'      => array(),
)
```

Their exact failure, from the `partitionAttrs` docblock: passing `tagName` to a
`spectra/container` (which uses `htmlTag`) gets silently dropped by the registry,
the model never sees the edit complete, **and it retries forever**.

**I1 — the hint names the fix.** Their `registeredAttrKeysOf` returns whatever
`getBlockType()` happens to declare, so it can report that `tagName` is invalid but
not that `htmlTag` was meant. Our catalog knows every legal attribute, its type, its
enum — and per decision #9 has already *discarded* enum lists it could not verify
against `block.json` defaults, so what it does know is trustworthy. Resolution order:

1. a small alias table for confusions we have actually observed
   (`tagName → htmlTag`, `color → the block's own colour attr`);
2. otherwise the closest name by edit distance among the block's real attributes,
   offered only under a distance threshold — a bad guess is worse than none;
3. always followed by the legal set for that block, truncated.

The cost of getting this wrong is asymmetric in our favour: a wrong suggestion still
carries the correct legal list beside it.

**I2 — do NOT port their degrade-open.** The branch to leave behind:

```js
if (!t || !t.attributes) return null;   // → partitionAttrs treats EVERYTHING as valid
```

An unregistered block type therefore accepts arbitrary attributes, written straight
into the live tree. That is silent damage, and design principle 5 exists to refuse it.
Our validator already rejects an unknown block (`block_unknown`,
`block_not_allowlisted`) before attributes are considered at all — **keep that, and do
not soften it to match theirs.** A literal port of `partitionAttrs` imports the hole,
which is why it is called out here rather than left to judgement.

Keep everything the page model already guarantees:

- **Never coerces.** A merge that would not validate is refused whole. `CONTRACT.md`.
- **Whole-envelope validation, not node-only.** Most interesting rules are
  cross-field — editing a container's `layout` alone is caught by
  `layout_children_mismatch`. A node-only check passes it and breaks the section.
- **A rejected edit leaves the page byte-identical.** Already asserted.
- **`null` reverts to the block default; an identical value reports no change** —
  or a collateral count is meaningless.

### B.6 Scope enforcement — the airtight half

`assertWithinScope` ported to uids. When `selected_uid` is set, every mutating call
must target it or a descendant; walk `path` from the uid index. `get-*` exempt.

**I6 — derive the lock, never accept it.** ZIP AI's brain *stamps* `scope_lock` onto
the envelope and the browser enforces whatever it was told. The enforcement is real,
but the **trigger is remote and optional**: a turn the brain forgets to stamp has no
lock at all, and nothing local notices. Their own reasoning about `page_wide` — derive
it from editor state so the prompt cannot defeat it — applies one level further up and
they did not take it there.

So the lock is **not a parameter**. The executor reads `selected_uid` off the turn
context it built itself and derives the constraint; no caller can pass, omit, or
weaken it. Same argument as invariant 15, and it means a tool schema that never
mentions scope cannot be talked out of it.

A mutating call with **no resolved target** is a page-root op and is refused.

Copy the message register — errors written *for the model*:

> `out_of_scope: heading-7f3a is outside the selected section hero-1 (and its children). You are scoped to that section — edit inside it, or tell the user if a different section is intended.`

Their soft/hard split is worth keeping: the system prompt asks the model to stay in
scope; the executor makes it true. Prompt gates are advisory, tree walks are not.

### B.7 Rebuild "Edit with AI" as the one-shot engine — do not retire it

Earlier drafts of this plan said retire it. That was wrong, and ZIP AI is why.

Their Quick Edit is a **deliberate second architecture**, not legacy:

> "SEPARATE from the chat agent loop: streams from `/api/agent/inline-edit/*`
> (brain runs a single LLM call, no `runTurn`) and applies straight to the block via
> `setAttributes` (native Gutenberg undo)."

A full agent turn to rewrite one heading is waste — it resends the system prompt,
burns a planning step, and loses native undo. The defect in our "Edit with AI" is
that it **regenerates the whole selection**, not that it is one-shot. Fix the scope,
keep the engine.

`class-one-shot.php`:

- One provider call. No loop, no `tool_choice: auto`, no approval gate.
- Writes through `update_block` — so the validator, the never-coerce rule and the
  byte-identical-on-reject guarantee all still apply.
- Text attributes only. A one-shot never changes structure; that is the loop's job.
- Intent presets, theirs adapted: `rewrite improve shorten expand grammar` up front,
  `simplify professional friendly humanize punchier` behind "more", plus a free-text
  box.

**Take their section fan-out.** Quick Edit on a container collects every editable
text descendant and bin-packs them into batches that each fit one call:

```
MAX_SECTION_TARGETS  = 80      total cap — a whole-page selection stays per-block
MAX_BATCH_TARGETS    = 40      count cap per call
SECTION_COST_CEILING = 24000   est-token cap per call
BATCH_GAP_MS         = 300     pause between batches, eases provider rate limits
est = ceil(chars/2) + count*64 + 4096
```

So one-shot scales to a whole section's copy without becoming a loop. Two caps
matter for different reasons: the per-batch pair bounds a single call, and
`MAX_SECTION_TARGETS` is a runaway guard against someone selecting the page root.

**I3 — kill the cross-repo convention.** Their code carries this warning: the
estimator **must equal** the brain's `EST_CEILING` in `inlineEdit.ts` — "same value +
same formula means a batch the client builds is exactly one the server accepts (no
drift)." Two repos, two languages, held by a comment. Nothing fails if one side
changes, and the symptom would be a batch the server rejects — read as a provider
error, not a drift bug.

Ours is one process, so the formula lives in one method. But "one method" is not the
fix on its own: someone will improve the formula later. So test it as a **golden
value** — fixed inputs, a pinned expected number — not as a re-implementation:

```
est_for( chars: 4000, count: 10 )  === 2000 + 640 + 4096  === 6736
partition( 41 targets under the char cap )      → 2 batches   (count cap)
partition( 3 targets, 60k chars )               → 3 batches   (cost cap)
partition( 1 target, 90k chars )                → dropped, not truncated
```

A test that recomputes the formula passes whatever the formula becomes, which is the
failure mode their comment already has. Pin the numbers.

**Routing between engines** — by scope, decided in code, never by the prompt:

| Selection | Engine |
|---|---|
| one block, text-only instruction | one-shot |
| a container, text-only instruction | one-shot, fanned out + batched |
| anything structural (add / move / delete / relayout) | agent loop |
| no selection | agent loop |

- [ ] `scripts/test-one-shot.php` — batch partitioning at each boundary, single
      target over the ceiling is dropped not truncated, text-only enforcement,
      structural instruction refuses and hands off, writes still go through the
      validator

**Bars** (from `EXPERIMENT_PLAN.md` Stage 4, unchanged — a bar is never moved after
seeing a score):

- ≥90% intended change applied
- **0% collateral** — hard zero. A model that edits the heading *and* silently
  restyles the button is worse than one that refuses, because nobody notices until
  later.
- ≤6 tool calls per edit

- [ ] `evals/edit/cases.json` — ~25 instructions against fixed pages
- [ ] `evals/attr-edit/cases.json` — ~40 with exact expected diffs (roadmap Stage 2)
- [ ] Scorer: right key · right value · **collateral**, diffing the whole page index
      before and after
- [ ] `scripts/test-agent-loop.php` — stub provider, as `test-generator.php` does:
      multi-call turns, partial apply, refusal-not-error, budget refusal, step cap,
      scope enforcement, `unknown_attrs` surfaced
- [ ] `scripts/test-turn-context.php` — outline labels from attrs not markup,
      repeater + nested-grid detection, `page_wide` derivation
- [ ] `scripts/test-approval-gate.php` — fail-safe default, `read_only_actions`

---

## 8. Phase C — the browser bridge

**Only after Phase B ships and meets its bar.** Phase B is complete without this;
C extends the same tools to a live unsaved editor session.

### C.1 What actually needs the browser

Only tools whose truth is the unsaved `wp.data` store. Their `get-scripts` docblock
is the rule:

> "A REST write here would race the open editor's copy and mutate before save —
> never do that."

Everything else stays headless.

### C.2 Two executors, one schema

```php
protected $execution = 'headless';   // | 'browser' | 'both'
```

`'both'` = headless by default, browser when an editor session is open on that
post. Tool authors write the schema once. This is `rest_api` / `js_rpc` /
`hybrid`, renamed for a plugin that does not have a remote brain.

### C.3 The dispatch, simplified by locality

We do **not** need Redis, BRPOP, a session-owner check, or a 20-second budget — the
loop is in-process on the same server. `POST /styble-ai/v1/tool-reply`, transient
keyed by `call_id`, loop polls with a timeout.

But **keep three of their four hard-won mechanisms**, because the hazard is the
browser, not the transport:

1. **`rpc` capability version** stamped into every context snapshot. Their reason:
   a bundle without the stamp degrades to the headless flow instead of timing out
   on every editor tool. Bump only on an incompatible contract change.
2. **Idempotency with the marker recorded first.** A browser apply mutates the tree
   *before* it replies. A replay — SSE re-delivery, a page reload mid-turn —
   double-applies and silently duplicates content. So:
   - any prior entry (in-flight *or* complete) → never re-dispatch; re-post the
     stored reply
   - no prior entry → record `IN_FLIGHT_MARKER = { ok:false,
     error:'apply_pending_no_confirmation' }` **before** running the handler, so a
     crash between mutation and completion still blocks a replay
   - bounded FIFO in `sessionStorage`, because the worst case is exactly a mid-turn
     reload, which an in-memory map does not survive
   - **extract the decision as a pure function** so it is unit-testable. Their
     `core/rpc-dedup.js` is 49 lines and dual-mode for exactly this reason. Their
     header calls it "the silent-until-it-bites duplicate-content path."
3. **`post_id` two-tab guard.** Mismatch aborts *all* operations and returns
   `refused` with `ok:true` — so the model reads the real reason, not "browser
   unreachable".

Skipped: the timing invariant. In-process, so there is no independent timeout to
lose a race against.

### C.4 Editor guards, ported

Our `assets/applier.js` already mirrors the catalog layout table. Add the lock
family, all of which degrade to **ALLOW** when a selector is absent — never a false
block:

- `canEditBlock` / `canRemoveBlock` / `canMoveBlock` before any mutation.
  Gutenberg's own selectors already fold in template lock, content lock, and
  synced-pattern instance locks.
- `getTemplateLock(root)` before **insert** and **duplicate** — their GBR-2 finding:
  those two *add* to a container, a case the canEdit/Remove/Move family does not
  cover, so they bypassed every check.
- `getTemplateLock(dest) === 'all'` before a **move-in** — their M2 finding:
  `canMoveBlock` folds in the *source* parent's lock only.

Three separate bugs they found and fixed in sequence. Take all three at once.

### C.5 Liveness

`stale_uid:<uid>` thrown when a uid does not resolve. Their rule, and it is the more
important half: **a stale id must never default to "top".**

- [ ] `scripts/test-bridge-dedup.php` — the pure decision function, jest-style
      cases in PHP: no prior entry → run + marker; in-flight → repost; complete →
      repost; never re-dispatch

---

## 9. Phase D — the pattern library, AI-addressable

**Reordered from the roadmap.** It defers this to "Stage 3's failure branch" because
it assumed the library had to be built. It doesn't — `styble-patterns-provider` is
serving and three consumer surfaces exist.

So this is no longer a fallback for a failed Stage 3. It is **Spectra's entire
first-generation product**, already two-thirds built, waiting for three tools.

### D.1 What exists — verified on disk

Serving half — `styble-patterns-provider/` (authoring site only, never customer
sites). Patterns authored as a CPT in the block editor, grouped by a Block Type
taxonomy:

- `GET /pattern-list` → every published pattern grouped by Block Type slug,
  12-hour transient cache
- `POST /single-pattern` (`template_id`) → one pattern's raw block markup, bumps a
  popularity counter

Consumer half — `styble-pro/`:

```
blocks/Dashboard/Ready_Patterns.php     the PHP surface
src/prebuild-library/Library.js         the picker
src/prebuild-library/starterSites.js    Ready Pages
src/templates/layoutPreset/index.js     layout presets
```

The tab a pattern lands in is decided purely by its Block Type slug — one catalog,
three surfaces. **Both halves ship today.** What is missing is a tool the model can
call.

### D.2 Three tools

| Tool | Type | Notes |
|---|---|---|
| `pattern/search-patterns` | search | intent → ranked candidates. Names + one-line purposes, **never markup** |
| `pattern/insert-pattern` | action | fetch, parse, validate against the catalog, insert via the page store |
| `pattern/fill-slots` | action | content-only rewrite of an inserted pattern's text attrs |

`fill-slots` is Spectra's `ai/v1/content` idea done right: they generate copy for a
whole category and cache it per club, then swap placeholders on import
(`replace_contact_details`, `Images::$image_index`). We fill a *specific* inserted
pattern from `class-brand-context.php`, live, with the validator on the write path.

### D.3 Why this may be the highest-value phase

Roadmap's own measurement: **$0.24 → $0.05 per page**, "the single biggest lever".
And it inverts the failure mode — a pattern is human-designed, so a valid insert is
also a *good* insert. Freeform's 65–75% contract-validity problem does not apply:
the markup was authored by a designer and validated once at ingest.

The roadmap's caution stands and is worth restating: *building this before Stage 3
is measured destroys the evidence that freeform failed.* Phase 0 resolves that —
the spread gets bounded and the colour bug fixed first, so Stage 3's number is real
before patterns arrive to obscure it.

### D.4 Ingest-time validation — do this once, not per generation

Run every served pattern through `class-validator.php` at ingest. A pattern that
fails is a **library** bug, fixed once by a human, not a per-request model failure.
This is the structural advantage over freeform and the reason the phase pays.

- [ ] `scripts/test-pattern-tools.php` — search ranking, ingest validation refuses
      a bad pattern, slot fill touches only text attrs, insert goes through the
      validator
- [ ] `evals/pattern/cases.json` — ~20 intents → expected pattern set + acceptable
      alternates

---

## 10. Phase E — guardrails

Small, and each item is a specific hazard the phases above introduce.

### E.1 Protected options

Port `class-protected-options.php` from §11.1. `pre_update_option_<key>` filters,
armed only while a tool call is in flight, refusing by returning `$old_value` (WP's
documented cancel semantics). `register_shutdown_function` clears the flag on the
fatal path.

Their reason for having this *in addition to* per-tool checks: it is the **one place
WordPress core itself observes every option write**. Adding a key covers every write
surface at once.

Our list is shorter than theirs because our tool surface is smaller — we do not
expose raw option writes at all. This is a backstop against a future tool, and
against third-party code running inside our request.

Take their `template`/`stylesheet` lesson too: they are deliberately *not*
protected, because `switch_theme()` writes both and blocking them makes the handler
report false success. **A guard that silently no-ops a legitimate operation is worse
than no guard.**

### E.2 Approval gate

`class-approval-gate.php`. `tool_type` × `read_only_actions`, with the fail-safe
default. Destructive tools need either explicit user approval or `dry_run` first.

Their `read_only_actions` exists for multiplexed tools — one ability routing
`create|list|get|update|delete` through an `action` enum, where `is_destructive`
is right overall but wrong for `list`. Keep our tools single-purpose and the
problem does not arise; keep the field anyway, for `pattern/fill-slots`, which will
want a preview mode.

**I7 — connect `dry_run` to `$resource`.** ZIP AI built both halves and left them
apart: `get_final_input_schema()` injects `dry_run` into every destructive schema but
defaults it `false`, and `$resource` exists specifically so "tools that operate on the
same resource" can be paired read-to-write — a pairing nothing then enforces. The
safety is present and entirely opt-in by the model.

Wire them: a destructive call whose `$resource` has had **no read this turn** runs the
dry pass first and returns the diff instead of applying. The model then either
confirms or corrects, having seen what would change.

Why this is the right default rather than paranoia: it is the same shape as their
`apply_pending_no_confirmation` marker — prefer an extra verify round-trip to an
unconfirmed mutation — and it costs nothing on the common path, because any turn that
read the block first (which the system prompt already asks for) proceeds directly.
`class-turn-context.php` tracks the reads, so the gate needs no new state.

### E.3 Write-time lint

`Snippet_Lint`'s shape, applied to our recurring model mistakes. Not snippets —
**the attribute-shape confusion in the baseline.**

The pattern to copy is not the specific check. It is: when a model repeatedly does
the wrong thing, block it at write time **and hand it the correct construct in the
error**. Their map is `is_home → conditions:[{type:'page',...}]`. Ours is:

```
listTextColor: {"color":{...}}  →  listTextColor is a plain string: "#ffffff".
                                   The object shape belongs to textFillBg / subHeadingBg.
```

That is decision-log #1's colour ambiguity, converted from a prompt problem into a
validator hint. Phase 0.1 fixes the prompt; this makes the fix self-correcting when
the model regresses.

### E.4 Rate limit + budget

Transients per user+tool (theirs: 100/min). Plus a **per-turn token budget**, which
they don't need and we do — they meter credits centrally, we spend the user's key.

### E.5 I5 — refuse to allowlist an attribute nothing renders

The one improvement with no ZIP AI counterpart at all: they have the identical hole
and have not closed it either.

**The defect, twice measured.** Decision #13: `advancedTextColor` and
`iconListTextcolor` were declared in `block.json` with sensible defaults and
referenced by **no CSS generator, frontend or editor** — and the AI set
`advancedTextColor` for weeks with no effect. Invariant 17 states the rule and admits
the gap: *"An attribute existing in `block.json` does not mean anything renders it.
There is still no build-time check for this."*

**Why we can close it and they cannot, cheaply.** Their vocabulary *is* the live block
registry — `getBlockType().attributes` at runtime, with no build step to hook. Ours is
**generated**: `scripts/generate-catalog.php` already reads Styble Pro's source, and
Styble Pro's rendering lives in a small, greppable set of places:

```
blocks/Includes/Styles/*.php        per-block CSS generators
blocks/Includes/Utils/Css_Helpers.php
blocks/Includes/Utils/DynamicCssGenerator.php
blocks/Types/*/                     block-specific render paths
```

So the generator gains one gate: an attribute proposed for `aiAllowlist` whose name
appears nowhere in the render tree is **refused, not shipped** — the same posture the
catalog already takes toward unverifiable enum lists (decision #9: *"the catalog never
guesses"*).

**Precision matters more than recall here.** A name-grep will have false *positives*
(an attribute mentioned in a comment) which are harmless, and the dangerous case —
declared, defaulted, referenced nowhere — is exactly what a grep finds reliably. Where
it is genuinely ambiguous the answer is a recorded exception with a reason, not a
silent pass.

This converts a whole class of "the feature ran and did nothing" into a catalog-
generation error, which is design principle 5 applied one layer earlier than the
validator can reach.

- [ ] Gate in `generate-catalog.php`, with the exception list and its reasons in-file
- [ ] Re-run against the current 13 allowlisted blocks and record what it finds — if
      it finds a third dead attribute, that is the check paying for itself immediately

---

## 11. Phase F — MCP (distribution)

Roadmap Stage 6, unchanged in position: **after Stage 4 passes.** By then the tool
surface is defined by its tests, so exposing it is mechanical.

`class-mcp-controller.php`, one route, `POST /styble-ai/v1/mcp`, strict JSON-RPC
2.0. `initialize` · `tools/list` · `tools/call` · `notifications/initialized`.

Port from `rest-api.php`:

- **Whitelisted meta forwarding.** Only keys a consumer actually uses.
  Their stated reason: keep the payload bounded and stop new internal fields
  leaking by accident. Filter: `styble_ai_mcp_allowed_meta_keys`.
- **`outputSchema` when declared.** The response contract as schema, not prose.
- **`tool_type` forwarded** so a consumer's classifier reads the source-of-truth
  annotation instead of guessing from the tool name. Their note: without it the
  brain fell back to a verb heuristic that misclassified reads as writes and
  blocked legitimate reads during plan stages.
- **Identity bound to the credential, not asserted by the caller.** They retired an
  `x_wp_user_id` header gate to get here. Application Password Basic auth →
  `wp_authenticate_application_password()` sets the current user as a side effect,
  so capability checks resolve natively.
- **Exclude introspection tools.** Their `get-ability-info` post-mortem: the
  consumer already has every `inputSchema` in the same payload, and a name-resolution
  mismatch (`namespace/name` vs `namespace__name`) made the call return a misleading
  "invalid permissions" that dead-ended arg-correction recovery.

Also the moment to reconsider the deck's Python/LangGraph service — with MCP up, a
remote brain becomes a client of a tested surface rather than a rewrite. Which is
the whole reason §3a's refusal is safe.

---

## 12. Phase G — hosted proxy (out of scope)

Listed for completeness. Roadmap: after Stage 5 passes.

If it happens, §4 of the companion doc is the reference implementation and its
mistakes are documented:

- Split the encryption key across **filesystem** (`wp_salt('secure_auth')`) and
  **database** (a per-site random salt), derive with HMAC-SHA256, never store the
  key. A DB-only compromise then cannot decrypt.
- Deliver any server-side credential **server-to-server**, never through the
  browser. Their `wpAuthorizationHeader` in `window.ZIPAI_CONFIG` is a documented
  mistake, removed, with the reasoning preserved in the code.
- Mint App Passwords idempotently — a stored UUID that still resolves is left alone,
  so reconnecting does not litter the user's Profile screen.

---

## 13. Sequence and dependencies

```
Phase 0  gate ──────────────────────────────────────────────► blocks everything
   │
   ├─► Phase A  tool host ──┬─► B.7  one-shot ──┐
   │                        │                   ├─► B.1/4/5  tools ──┐
   │                        │                   │                    │
   │                        │                   └────────────────────┼─► B.2/3  loop
   │                        │                                        │      │
   │                        │                            B.6 scope ──┘      │
   │                        │                            B.0 collapse ──────┤
   │                        │                                (Stage 4 ⭐)   │
   │                        │                                               ├─► Phase C  bridge
   │                        │                                               └─► Phase F  MCP
   │                        └─► Phase D  patterns
   └─► Phase E  guardrails   (E.1/E.4/E.5 anytime; E.2 with B.2; E.3 with 0.1)
```

**E.5 does not wait.** The dead-attribute check (I5) touches only
`generate-catalog.php` and needs no loop, no tools and no measurement — and if it finds
a third attribute that renders nothing, that is a live defect in what the model is
currently being told. Worth running early for the finding alone, independently of
whether the rest of the plan proceeds.

- **A before B** — every engine resolves tools through the registry.
- **B.7 first inside B** — smallest engine, clearest bar, and it proves the write
  path before a loop exists to confuse the diagnosis.
- **B.0 last inside B** — do not delete `/generate` until `/turn` and `/rewrite`
  both work.
- **B before C** — do not debug a browser transport and a turn loop at once.
- **B before F** — MCP wraps the executor. Nothing to wrap yet.
- **D parallel to B** — different tools, same registry. D's value does not depend
  on B's bar being met.
- **0 before all** — otherwise nothing downstream is measurable.

### Rough sizing

| Phase | New | Modified | Feels like |
|---|---|---|---|
| 0 | — | prompt, settings, factory | days |
| A | ~600 lines + 1 suite | generator, prompt, rest | ~1 week |
| B | ~1,800 lines + 4 suites + 2 eval sets | page-model, chat-controller, rest-controller | **3–4 weeks** |
| C | ~900 lines JS + 1 suite | applier.js, chat.js | ~2 weeks |
| D | ~500 lines + 1 suite + 1 eval set | — | ~1 week |
| E | ~600 lines | validator, registry, generate-catalog | ~5 days |
| F | ~350 lines + 1 suite | — | ~4 days |

B is the phase. Everything else is scaffolding around it or distribution after it.

B grew from the earlier estimate (~1,400 / 2–3 weeks) because it now carries the
one-shot engine and the route collapse, not just the loop. That is a real cost, and
it buys the cheap path for the most common request — "reword this" — which the loop
would otherwise serve at full turn price.

---

## 14. Invariants — nothing in this plan may break these

Carried from `CLAUDE.md`, `CONTRACT.md`, and the decision log. Each has a
measurement or a documented defect behind it.

1. **The model never emits block markup.** The one rule that defines the
   architecture. Tools take and return structured data; the appliers own
   serialization.
2. **The validator never coerces.** Pass or reject, with a reason. The deck's
   `alias → coerce → default` was considered and refused. The 31 codes exist
   because of that choice.
3. **Validation runs on the whole section envelope, not the node.** Cross-field
   rules are the interesting ones.
4. **A rejected write leaves storage byte-identical.**
5. **`class-page-applier.php` and `assets/applier.js` must agree on layout maths.**
   Change one, change the other.
6. **Never hand-edit `catalog/catalog.json`.** Regenerate.
7. **Never grow `attrs.properties`.** Measured twice, reverted twice, ~700 chars per
   attribute per depth level.
8. **No `$defs`/`$ref` in tool schemas.** One endpoint failing to resolve a `$ref`
   breaks generation outright.
9. **Every provider response is accounted for** — usage handed to the tracker
   *before* parsing, so a truncated or 429'd call is still counted. It was still
   billed.
10. **Prefix stability is asserted, not assumed.** 24 checks. Adding a tool must not
    move the cached prefix.
11. **`uniqueId` is derived from `sectionId | nodePath`**, so it survives a rebuild.
    Scoped CSS keys off it: a block that loses it renders unstyled.
12. **`post_content` is derived** from `_styble_ai_page`. Read the trees. Never
    parse the markup back.
13. **Cases are added when they fail in real use and never removed to make a number
    look better. A bar is never moved after seeing a score.**
14. **All three engines write through the same validator and the same appliers.**
    No engine gets a private write path. One-shot, loop and service differ in how
    they *decide*, never in how they *persist*.
15. **Engine selection is decided in code, from editor state and instruction shape
    — never by the model.** Same reasoning as `page_wide`: a prompt-decided route is
    a prompt-defeatable route.
16. ~~**A ported guard without a test is not a ported guard.**~~ **Withdrawn
    2026-07-30 by decision (S5):** new work is written without tests. The eight
    existing suites (247 checks + 47 fixtures) stay and stay green; the per-phase
    suites listed in §6–§11 are **not** being written.

    Recorded rather than deleted because the reasoning still stands and the risk is
    now carried rather than mitigated: every ZIP AI guard we port was added in
    response to a real defect (GBR-2's insert path, M2's move destination, B-1's
    duplicate apply), and an unasserted guard is one refactor from decorative. We are
    now in the same position ZIP AI is in — the difference being that they at least
    have a private harness, and we will have the eight suites covering the catalog,
    validator, appliers, page model, prompt, provider bodies, usage and credentials.
    The uncovered surface is exactly the new engines.
17. **A constraint is derived, never accepted from the caller.** Scope locks, budgets
    and engine choice are computed by the executor from state it built itself. Nothing
    security- or correctness-bearing arrives as a tool parameter, because a parameter
    can be omitted. §2b I6.
18. **Every documented trigger has an asserted hook.** ZIP AI's site scanner is 721
    lines, named in its privacy policy, and its `register_hooks()` call is commented
    out — shipped dark for a release. A test walks the documented triggers and asserts
    each has a live `add_action`.

---

## 15. Risks

| Risk | Why it is real | Mitigation |
|---|---|---|
| **Phase 0 gets skipped** | It is unglamorous and blocks the interesting work. It is also the exact failure the roadmap diagnoses. | Phase A's bar is "one eval run inside the Phase-0 spread". Without a known spread that sentence is meaningless — A cannot be declared done. |
| **The loop costs more than one big call** | N round-trips each resend the system prompt. Granular schemas shrink per-call cost; step count grows it. Net effect **unknown**. | **I8 makes it a column, not an argument** — a `turn` operation plus per-tool rows on the existing Token Usage screen. Measure at A.4 and again after B. Per-turn budget cap from day one. Prefix caching already exists on the Anthropic path and is inert on Mistral, so this may not show until B3 is fixed (now Phase 0.4). |
| **Collateral damage in multi-call turns** | Stage 4's hard zero is harder with 6 calls than 1. | Scope lock (B.6) enforced in the executor, not the prompt. Collateral scorer diffs the whole page index. Refuse rather than guess. |
| **The bridge duplicates content** | ZIP AI's most-commented hazard. Their header: "silent-until-it-bites." | Take all four mechanisms in C.3, marker-before-mutation included. Extract the decision as a pure function and test it. Headless stays primary. |
| **The two appliers drift** | Already an invariant; C adds a third surface (browser tool handlers). | Both read the catalog layout table. Extend `test-page-applier.php` to assert the JS handler's table matches. |
| **A capable model makes the `attrs` union work after all** | Decisions #1/#2 were measured on `gemini-3.1-flash-lite`, explicitly "not verified on a capable model". | Phase 0.4 unblocks target-vs-floor. Re-test once, on Claude, and record. Do not act on a hunch. |
| **Refusing the className contract is wrong** | Spectra bet the product on it. Tailwind is in every model's training data; our 63 attributes are not. | Attribute-shape errors are the measured failure (22 of 26 baseline errors). If they persist after 0.1 and E.3, that is the trigger to re-open §3c. Record it either way. |
| **Patterns obscure the freeform result** | Roadmap's own caution. | D ships after 0.2/0.3, so Stage 3's number is bounded first. Keep scoring freeform separately, forever. |
| **Scope creep toward site management** | ZIP AI's 18 abilities are seductive and its guardrail stack exists *because* of them. | §3d is a boundary, not a backlog. A tool that touches anything outside the page needs a written argument. |
| **Three engines become three code paths that disagree** | Two appliers already have to be kept in lockstep. Three writers is worse. | **All three write through `update_block` / the page store, so the validator is unavoidable.** No engine gets its own write path. Assert it: every engine's tests use the same rejected-edit fixture and check the page is byte-identical. |
| **Engine routing gets decided by the model** | "Is this structural?" looks like a judgement call, so it is tempting to ask the LLM. | Route in code from the selection and the instruction shape (§7.7 table). A prompt-decided route is a prompt-defeatable route — same reasoning as `page_wide`. |
| **The one-shot engine drifts into a second loop** | Every "just one more call" is locally reasonable. | One provider call, text attributes only, no structural tools reachable. Enforced in `class-one-shot.php`, tested in `test-one-shot.php`. Structural intent must *hand off*, not grow the engine. |

---

## 16. First five things, in order

1. **Fix the colour shape ambiguity** (0.1). 22 of 26 baseline errors, small prompt
   change, largest single win available.
2. **Two unchanged eval runs** (0.2) — bound the spread.
3. **Break the prompt deliberately** (0.3) — confirm the number moves beyond it.
4. **Per-provider keys** (0.4) — unblock target-vs-floor.
5. **`class-tool.php` + `class-tool-registry.php` + `test-tool-registry.php`**, and
   migrate `emit_layout` → `page/apply-layout` behind them. No behaviour change,
   all seven suites green.

Then Phase B, which is the actual product. Inside B, build in this order —
cheapest-to-verify first, and each one is independently shippable:

1. **B.7 one-shot** — smallest engine, clearest bar, immediately replaces the
   Low-trust "Edit with AI". Proves the write path before the loop exists.
2. **B.1 + B.4 + B.5 tools** — the eight `page/*` tools, `unknown_attrs`, extended
   `get_block`. Testable against the page model with no loop at all.
3. **B.2 + B.3 loop and context** — only once the tools are trusted.
4. **B.6 scope enforcement** — last, because it constrains a surface that has to
   exist first.
5. **B.0 route collapse** — once `/turn` and `/rewrite` both work, delete
   `/generate`.

---

*Companion: [`SPECTRA_AI_IMPLEMENTATION.md`](SPECTRA_AI_IMPLEMENTATION.md). State of
truth: [`ROADMAP.md`](ROADMAP.md). Bars: [`EXPERIMENT_PLAN.md`](EXPERIMENT_PLAN.md).
Contract: [`CONTRACT.md`](CONTRACT.md).*
