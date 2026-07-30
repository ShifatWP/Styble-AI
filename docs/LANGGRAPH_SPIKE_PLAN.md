# One-day spike: the deck's architecture on a local server

**Status:** Proposed · **Written:** 2026-07-29 · **For:** the next working day
**Target:** the architecture in `~/styble-ai-architecture/styble-ai-architecture.html` §2
**Relationship to the other plans:** this is a *spike*, not a migration.
[`EXPERIMENT_PLAN.md`](EXPERIMENT_PLAN.md) still owns the stages and their bars;
[`ROADMAP.md`](ROADMAP.md) still owns what is proven. Nothing here replaces the PHP
pipeline — it stands a Python orchestration layer beside it so the two can be
compared on the same cases.

---

## 0. The question this day answers

> **Does LangGraph orchestration produce better output than the PHP pipeline, on the
> same 20 cases, with the same scorers?**

Not "can it be built" — it can. The useful question is whether the orchestration
layer changes the *number*, because that is the only thing that justifies carrying
a second runtime.

Secondary questions, cheap to answer once the harness exists:

- Does a plan → generate → **critique → revise** graph beat one-shot generation?
  That loop is the main thing LangGraph buys and PHP does not have.
- What does it cost per page, measured rather than estimated?
- Does LangChain's abstraction obscure token accounting? (It usually does. Worth
  knowing before it is load-bearing.)

**Success is a number you can compare, not a working demo.** A graph that runs but
cannot be scored against `65–75%` has answered nothing.

---

## 1. The decision that shapes the whole day

**Do not port the catalog, the validator, the appliers, or the page model.**

The deck already says this — its Bridge row is *"LangGraph ↔ WP connector plugin
(PHP): `resolve_attrs` → `serialize_blocks()` → `wp_insert_post()`"* — and its own
ownership table gives WordPress *"Know real block attrs / defaults: ✅ source of
truth"* while LangGraph gets *"catalog snapshot only"*.

Concretely, that means these stay in PHP and keep their 164 passing checks:

| Asset | Checks | Why it cannot move |
|---|---|---|
| `catalog.json` + generator | self-verifying | Reads Styble Pro's own source. Python has no access to `block.json` semantics |
| `Styble_AI_Validator` | 47 fixtures, 31 codes | The contract. Reimplementing it in Python means two contracts that will drift |
| `Styble_AI_Page_Applier` | 42 checks | Mints `uniqueId`, mirrors the editor's layout maths. Wrong here = unstyled pages |
| `Styble_AI_Page_Model` | 38 checks | uid addressing, `get_block`/`update_block` |
| `Styble_AI_Media` | — | Sideloads into the WP media library. Cannot happen outside WP |

**Python's job is orchestration only:** decide what to ask for, in what order, how
many times, and with what critique. That is exactly what LangGraph is for, and it is
~10% of the code.

> ⚠️ **The drift trap.** If Python builds its own prompt and tool schema from
> `catalog.json`, there are then two generators of the same artifact — the failure
> mode this codebase has hit four times (`advancedTextColor`, `iconListTextcolor`,
> the overlay modes, the icon-list cascade). **Fetch the rendered prompt and schema
> from the connector instead.** `scripts/dump-prompt.php` already renders both; a
> `GET /prompt` route is ten lines and removes the whole class of bug.

---

## 2. Prerequisites — do these before the day starts (~40 min)

### 2a. Python: pin to 3.12, not 3.14

Installed is **3.14.6**. LangGraph, LangChain and several transitive deps
(`pydantic-core`, `orjson`, `grpcio`) publish wheels late for a new minor, and
building them from source will eat the morning.

```bash
# If pyenv is present:
pyenv install 3.12.8 && pyenv local 3.12.8
# Otherwise use whatever 3.12 is available; do not spend >15 min on this.

mkdir -p ~/styble-ai-graph && cd ~/styble-ai-graph
python3.12 -m venv .venv && source .venv/bin/activate
pip install -U pip
pip install langgraph langchain-core anthropic httpx python-dotenv
pip freeze > requirements.txt      # pin it, so the afternoon is reproducible
```

**Stop condition:** if the install is not clean in 15 minutes, drop LangGraph and
use the Anthropic SDK's own tool runner plus a plain `while` loop. The graph shape
matters; the framework does not. Note the substitution and move on.

### 2b. Auth: a connector token, not an application password

`wp_is_application_passwords_available()` returns **`no`** on this install, because
the site is `http://location-weather-pro.test` and WordPress disables app passwords
without TLS. Do not fight that. Use the deck's other option — a shared connector
token — added as a small mu-plugin so it is obviously local-only:

```php
<?php
// wp-content/mu-plugins/styble-ai-connector-auth.php
// LOCAL SPIKE ONLY. Never ship this: it is a bearer token with edit_pages rights
// and no rate limiting, rotation, or audit trail.
add_filter( 'determine_current_user', function ( $user_id ) {
	if ( $user_id ) {
		return $user_id;
	}
	$sent = $_SERVER['HTTP_X_STYBLE_CONNECTOR'] ?? '';
	if ( '' !== $sent && hash_equals( getenv( 'STYBLE_CONNECTOR_TOKEN' ) ?: 'dev-only-change-me', $sent ) ) {
		return (int) get_option( 'styble_ai_connector_user', 1 );
	}
	return $user_id;
}, 20 );
```

Then `export STYBLE_CONNECTOR_TOKEN=...` for both the PHP-FPM process and the
Python venv. Verify with a single curl before writing any Python.

### 2c. Provider keys

Today's blockers are unchanged and they cap what the day can conclude:

| | State | Effect on the spike |
|---|---|---|
| Anthropic key | absent | The deck's default model is unavailable, and prefix caching stays inert |
| Gemini key | absent | No floor model, so no target-vs-floor signal |
| Mistral key | present | `mistral-large-latest`, the current baseline model |

**Get at least a free Gemini key before the day starts.** It costs nothing and it is
the difference between one number and a comparison. If both a Gemini and an
Anthropic key can be had, the day becomes far more informative — and note that
`class-provider-factory.php:27` reads **one shared** `styble_ai_api_key`, so the
Python side will need its own key handling rather than borrowing WordPress's.

---

## 3. Phase 1 — the connector API (PHP, ~2 hours)

Seven routes, every one a thin wrapper over a class that already exists and is
tested. Add as `includes/class-connector-controller.php`, namespace
`styble-ai/v1`, permission `current_user_can( 'edit_pages' )` — which the connector
token satisfies via 2b.

| Method | Route | Wraps | Returns |
|---|---|---|---|
| `GET` | `/prompt` | `Styble_AI_Prompt` | `{ system, tool: {name, description, input_schema}, contractVersion }` |
| `GET` | `/catalog` | `Styble_AI_Catalog::to_array()` | the catalog, for inspection only |
| `POST` | `/validate` | `Styble_AI_Validator` | `{ valid, errors[] }` — **no side effects** |
| `POST` | `/pages` | `Styble_AI_Page_Store::create` + `set_plan` | `{ pageId, previewUrl, editUrl }` |
| `PUT` | `/pages/{id}/sections/{sid}` | `set_section_tree` (validates first) | `{ ok, pageId, sections[] }` |
| `GET` | `/pages/{id}/blocks` | `Styble_AI_Page_Model::index` | uid → block, section, path, attrs |
| `PATCH` | `/pages/{id}/blocks/{uid}` | `Page_Model::update_block` | `{ ok, uid, changed[], warnings[] }` |
| `POST` | `/media/fill` | `Styble_AI_Media::fill` | `{ tree, filled, warnings[] }` |

**Rules that make this safe to build fast:**

- **Every write validates first.** `PUT /sections` must run the validator before
  `set_section_tree`, exactly as `Styble_AI_Chat_Controller::section()` does. The
  connector must not become a way to bypass the contract.
- **No route returns markup.** Same rule the page model already follows.
- `/validate` is the one route Python will call most — it is how the graph's critique
  loop learns what is wrong — so it must be side-effect-free and fast.

**Checkpoint (do not proceed past this until it passes):**

```bash
curl -s -H "X-Styble-Connector: $STYBLE_CONNECTOR_TOKEN" \
  http://location-weather-pro.test/wp-json/styble-ai/v1/prompt | python3 -m json.tool | head -20

# A tree the validator should reject, round-tripped through the connector:
curl -s -X POST -H "X-Styble-Connector: $STYBLE_CONNECTOR_TOKEN" \
  -H 'content-type: application/json' \
  -d '{"tree":{"version":"0.1.0","root":{"block":"styble/container","attrs":{"containerWidth":"contained"}}}}' \
  http://location-weather-pro.test/wp-json/styble-ai/v1/validate
# expect: {"valid":false,"errors":[{"code":"attr_value",...}]}
```

If `/validate` rejects a known-bad tree with the right code, the whole PHP half is
proven. That is the morning done.

---

## 4. Phase 2 — the LangGraph service (Python, ~3 hours)

### State

```python
class SectionState(TypedDict):
    brief: str
    tree: dict | None
    errors: list[dict]      # from the connector's validator
    attempts: int
    critique: str | None
```

### Graph

```
                    ┌─────────────┐
   brief ──────────▶│    plan     │  one call → title + ordered section briefs
                    └──────┬──────┘
                           │  fan out, one branch per section
                    ┌──────▼──────┐
                    │  generate   │  forced tool call, schema from GET /prompt
                    └──────┬──────┘
                           ▼
                    ┌─────────────┐
                    │  validate   │  POST /validate   (PHP owns the contract)
                    └──┬───────┬──┘
                 valid │       │ invalid
                       │       ▼
                       │  ┌──────────┐
                       │  │  revise  │  errors fed back verbatim; max 2
                       │  └────┬─────┘
                       │       └──────────▶ generate
                       ▼
                    ┌─────────────┐
                    │  critique   │  ★ the thing PHP does not have
                    └──────┬──────┘   "is this section actually good?" → revise or pass
                           ▼
                    ┌─────────────┐
                    │  assemble   │  PUT /pages/{id}/sections/{sid} per section
                    └─────────────┘
```

**The `critique` node is the whole point of using a graph.** Everything else is what
`class-generator.php` already does, and reimplementing it in Python proves nothing.
If the day runs short, cut `plan` (hardcode the briefs from `cases.json`) and keep
`critique` — not the other way round.

### Two things to get right

1. **Never build the schema in Python.** Fetch it from `GET /prompt` at startup and
   cache it in memory for the process lifetime. One source, per §1.
2. **Log token usage per node.** LangChain wraps the provider response and it is easy
   to lose `usage`. Attach a callback handler that records
   `input_tokens`, `output_tokens`, `cache_read_input_tokens` per call and totals per
   page. Without this the cost question cannot be answered, and cost is half the
   reason the deck exists.

```python
from langchain_core.callbacks import BaseCallbackHandler

class TokenLog(BaseCallbackHandler):
    def __init__(self): self.calls = []
    def on_llm_end(self, response, **kw):
        u = (response.llm_output or {}).get("usage", {})
        self.calls.append(u)          # print the total at the end of every run
```

---

## 5. Phase 3 — the A/B, and the constraint on it (~1.5 hours)

This is the part that makes the day worth doing, and it has one hard constraint
established by measurement today:

> **Run-to-run variance on the same suite is ±10 points.** Two identical PHP runs
> scored 75% and 65%. A single LangGraph run scoring 80% therefore proves nothing.

So the protocol is:

```
PHP pipeline      3 runs × 20 cases, retries=0     → min / median / max
LangGraph one-shot 3 runs × 20 cases, no critique   → min / median / max
LangGraph + critique 3 runs × 20 cases              → min / median / max
```

Nine runs of 20 cases = 180 calls. At Mistral-large rates and ~7,140 input tokens
per call that is roughly **$1.50–3.00 total** — cheap enough not to economise on,
and the pacing matters more than the money: budget ~15 minutes per run.

**Score with the existing scorers, not new ones.** Port nothing — have the Python
side write each case's tree to a file, then score them with the PHP scorer so both
arms are judged by identical code. Simplest version: a `scripts/score-trees.php`
that takes a directory of trees and prints the same table `eval.php` does. ~40 lines,
and it removes "the scorers differ" as an explanation for any gap.

**Read the result like this:**

| Observation | Conclusion |
|---|---|
| LangGraph median inside PHP's min–max band | Orchestration changed nothing. Do not carry a second runtime |
| critique median clears PHP's max by >10 points | The critique loop is the win — and it can be built in PHP for far less |
| Both arms cluster at 65–75% | The ceiling is the prompt or the model, not the orchestration. Go fix the colour shape tags |
| LangGraph markedly worse | Likely the schema or prompt drifted. Check you fetched from `GET /prompt` |

Note the second row carefully: **if critique is what wins, that is an argument for a
critique node, not for Python.** The honest outcome of this day may be "build the
loop in PHP."

---

## 6. Timeboxes and stop conditions

| Phase | Box | Stop condition |
|---|---|---|
| Prereqs (§2) | 40 min | LangGraph won't install in 15 min → use the Anthropic SDK tool runner instead |
| Connector (§3) | 2 h | `/validate` not round-tripping by lunch → stop, the day cannot proceed |
| Graph (§4) | 3 h | No valid tree from Python by 15:00 → cut `plan` and `critique`, get one section end-to-end |
| A/B (§5) | 1.5 h | Fewer than 2 runs per arm → report the spread you have, do not report a single-run comparison |

**A stop is not a failure.** "LangGraph adds nothing measurable over PHP" is a
genuinely useful result and it redirects the roadmap — it is the same shape as
`EXPERIMENT_PLAN.md`'s rule 5.

---

## 7. Risks, with what to do about each

| Risk | Likelihood | Mitigation |
|---|---|---|
| Python 3.14 wheels missing | **high** | Pin 3.12 up front (§2a) |
| Prompt/schema drift between PHP and Python | **high** | `GET /prompt`, never rebuild. This is the day's biggest correctness risk |
| Auth rabbit hole (app passwords unavailable) | medium | Connector-token mu-plugin (§2b), 20 lines, verify with curl first |
| Mistral TPM pacing stalls the A/B | medium | 8s between calls was enough today; keep the delay and run arms sequentially |
| LangChain hides token usage | medium | Callback handler (§4), added before the first real run |
| Scoring the two arms differently | medium | One scorer, in PHP, fed by files (§5) |
| Building a demo instead of a measurement | **high** | The deliverable is nine run summaries, not a page in the browser |

---

## 8. What to write down at the end

Regardless of outcome, record in `ROADMAP.md`:

1. Nine run summaries — three arms, min/median/max.
2. Measured cost per page for each arm, from the token log.
3. A decision-log entry with the verdict, tagged **measured**, and whether the deck's
   Orchestration row (LangGraph) is justified by evidence or still an assumption.
4. Whatever broke that the PHP path had already solved — those are the things a
   migration would have to re-solve, and they are the real cost of the port.

---

## 9. Explicitly out of scope for this day

Each of these needs the day's result first:

- **MCP** (deck row 4) — wraps the executor. Build it once there is one worth wrapping.
- **Pattern library** (deck row 1) — the largest cost lever measured, $0.33 → $0.05 per
  page, but `EXPERIMENT_PLAN.md` Stage 3 says build it *because* freeform failed.
  Today's 65–75% is confounded by a fixable prompt bug, so it does not yet justify it.
- **Proxy + credits** (deck row 8) — after Stage 5.
- **Porting the validator or appliers** — see §1. If the day ends with an argument for
  doing this, that argument belongs in the roadmap, not in code.
- **The colour shape fix.** It is the highest-value change available (22 of 26 baseline
  errors) but it moves the PHP baseline mid-comparison. Do it *before* the day or
  *after* it, never during — otherwise the two arms are not measuring the same prompt.
