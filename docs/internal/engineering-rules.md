# Engineering rules

**Owner:** Jason
**Date:** 2026-09-21
**Last reviewed:** 2026-09-21
**Next review:** 2026-12-21 (quarterly) OR on any change to the checkers in `.github/`

How work is reasoned about, verified and reported in SpiderNetOS.

Every rule below is grounded in something that actually went wrong in this
repository, because a rule with no incident behind it is a preference. Where a
rule is mechanically enforced, the enforcing file is named. Where it is not, it
says so — claiming enforcement you do not have is itself a violation of rule 8.

The governing principle these serve:

> **A declaration is not a control until something validates it, something
> enforces it, and a test proves the enforcement path — and the evidence names
> the version and environment in which the enforcement was demonstrated.**

---

## 1. Say which layer a statement is on

Observation, interpretation, hypothesis, decision and verification are different
kinds of claim. The failure is promoting a plausible interpretation into an
observed fact.

| Layer | Example |
|---|---|
| Observation | A job has `steps=0` and a billing annotation |
| Interpretation | Those jobs never executed repository code |
| Hypothesis | Rapid pushes produced redundant compute |
| Decision | Add cancellation to the four workflows lacking it |
| Verification | A newer run cancels the older one in the intended group |

*What went wrong:* the September usage report established how many minutes each
workflow consumed. It could not establish whether those minutes came from
overlapping pushes, duplicate checks, slow tests or large matrices — and
"missing concurrency is the most likely single cause" was written as though it
had. The arithmetic refuted it: the two workflows that already **had**
concurrency accounted for 64.7% of that day.

**Carry four fields on any consequential finding:**

```
Claim:
Evidence:
What remains unknown:
Next check that would resolve it:
```

*Enforced by:* nothing. Judgement rule.

---

## 2. Group failures by causal signature, not by notification count

Eight failure emails can be one blocker. One workflow can hold several
independent defects. The unit of investigation is the **mechanism**.

Record per failure: commit and run id, workflow and job, **first failing step**,
the error or annotation signature, **whether any steps executed at all**, and
whether it reproduces independently.

*What went wrong:* six failure notifications per commit across eight workflows
were one Actions billing block. `steps=0` plus a billing annotation is an
infrastructure rejection; it is not evidence about the code. Three commits were
described as "remotely failing" when the honest word was **unverified**.

A billing-blocked Python job and a billing-blocked frontend job are one issue. A
Python import error and a frontend compile error stay two, even on one push.

*Enforced by:* nothing. Judgement rule. Findings that are out of scope to fix go
to `docs/internal/awareness-list.md` under the rule already stated in
`docs/internal/operating-model.md` §Awareness — not into a TODO comment, and not
into silence.

---

## 3. Every change carries an acceptance condition and a disproof

"Improve CI" cannot be finished. This can:

> A newer push to the same branch cancels obsolete validation work without
> cancelling another workflow or another branch.

State each change as `problem → change → expected behaviour → the test that
would disprove it`.

And keep two things apart that are easy to merge: **faster feedback** and
**better coverage** are different improvements. Running an incomplete check more
often leaves it incomplete — moving `tests/Unit/Skills` into `CiFast` bought
speed; the registry, the corpus-completeness assertion and the negative-test
contract closed the 51-type hole.

*Enforced by:* nothing directly. The commit convention in this repo is to state
the acceptance condition in the message body.

---

## 4. Prove the guard, not the code

For every control that matters, ask:

> What is the smallest realistic defect that would defeat this protection, and
> does our test catch it?

Then introduce that defect deliberately and watch the suite go red.

*What went wrong, twice:*

- `no_banned_phrase` (singular) was deleted. Its test asserted `failed` and had
  meant *"the known check found prohibited content"*; afterwards the identical
  assertion still passed and meant *"an unknown property could not execute"*.
  **Same colour, different meaning.** A suite reading only red-versus-green
  accepts both, while one of them has stopped testing content safety.
- A handler returning the right status for the **wrong reason** was invisible
  until results carried a stable reason code. That sabotage leaves the status
  unchanged, so only a contract test on the reason catches it.

Worth a deliberate defect, in this repo:

| Control | The defect to introduce |
|---|---|
| Tenant isolation | Return one record belonging to another tenant |
| Egress control | Drop the classification from one outbound payload |
| Approval enforcement | Execute without the approval record |
| Cost governor | Understate an estimated cost |
| Evaluation gate | Return the right status with the wrong reason |

Do not mutate every trivial function. Concentrate on controls whose silent
failure would matter.

*Enforced by:* `PropertyCheckerTest` (a passing **and** a failing example, with
its reason and path, for every implemented property) and
`PrimitiveConformanceTest` (a structural check that no primitive addresses the
output outside its boundary). Both were proved by sabotage before being trusted.

---

## 5. A pass must name its denominator

A check can be logically satisfied while establishing nothing. *"All angles have
sources"* is true over zero angles. Whether that is acceptable is a contract
question, and four sub-questions have to stay separate:

1. Must any subjects exist?
2. If they exist, must each satisfy the assertion?
3. Is the collection actually present and correctly typed?
4. Was the check available, and did it run?

The shape recurs far outside evals: *every payment reconciled* when none were
loaded; *all content passed safety checks* when generation failed; *no security
violations found* when the scanner never started; *all customers messaged* when
recipient selection returned nothing.

*What went wrong:* `declared = 69, executed = 0, failed = 0 → EXIT 0` — a
vacuous-truth bug at the centre of an evaluation system. And in the corpus,
`every_angle_has_source` was asserted in three cases, only one of which required
any angles to exist.

**Report the denominator and the execution status with the rate**, and keep
missing data, an empty collection, and an unavailable checker distinguishable. An
intentionally empty collection does **not** automatically become
`not_applicable`: a contract may legitimately accept emptiness. What it may not
do is spell "zero counterexamples among zero subjects" the same way as "subjects
existed and all complied".

*Enforced by:* `PathOutcome` (seven distinguishable resolution results),
`Cardinality` (no rule turns zero subjects into a silent pass), and the accounting
identity in the eval harness.

---

## 6. A guarantee is reusable only where its prerequisite is enforced

Before leaning on one control to satisfy another, check that it applies to **the
same subject** and that its enforcement **cannot be bypassed**.

- A count over `angles` cannot establish that `sources` is non-empty.
- A schema declaring `minItems: 1` cannot replace schema validation actually
  running, with a failed or unavailable result blocking the case.
- A recorded approval cannot authorise a different action, payload or revision.
- A green evaluation on one commit cannot certify a later one.

*What went wrong:* fourteen declared tools did not exist, because
`skills:validate` read `config('agents.tool_costs')` while the runtime read
`ToolCatalogue` — two authorities for one concept. Separately, the first draft of
the empty-collection gate accepted `angles_min:1` as pairing for an assertion
about `sources`.

Where one control depends on another, document the dependency and **test its
absence**. Enforce it at the case or release gate rather than making individual
predicates order-dependent.

*Enforced by:* the pairing check in `SkillRegistry::pairingErrors()`, which
matches a companion to its subject by target and refuses a schema minimum unless
the case asserts `schema_valid`; and `SkillRegistry::composes()`, which refuses
to read a schema shape it cannot follow rather than reporting "no minimum".

---

## 7. Sequence by risk reduction and reversibility

Make a small protective change first when it reduces immediate exposure and does
not compromise the main work. Defer the larger changes that can silently remove
coverage until there is evidence to aim them.

Four questions per change:

- What immediate risk does it reduce?
- Can it weaken an existing protection?
- Can it be verified independently?
- Can it be reversed cleanly?

Different answers mean different commits. A cost-control change and an
evaluation-semantics change must be separately reviewable.

The same principle forbids a weakening intermediate state: when reason codes were
introduced, four sites that reported a missing dependency kept the status each
already had. Reclassifying the two failing ones to `skipped` before `unavailable`
existed would have opened a window in which a missing fixture read as success.
**Tighten later; never loosen in passing.**

*Enforced by:* nothing. Judgement rule, visible in commit granularity.

---

## 8. Report verification in dimensions, not as "green"

All of these were true at once on this branch, and "green" compresses them away:

| Dimension | Question |
|---|---|
| Contract correctness | Does the component obey its declared behaviour? |
| Detection strength | Do realistic defects make the tests fail? |
| Integration | Does it work against real dependencies? |
| Coverage | Is every required property implemented **and** exercised? |
| Remote execution | Did CI execute on **this exact revision**? |
| Release readiness | Are all required gates satisfied together? |

*What went wrong:* "Pint clean" was reported as though it covered static
analysis — it does not, and Larastan was failing branch-wide with ~154 errors,
nine of them newly introduced. "248 Unit tests" was reported without saying ten
were erroring. A suite of 90 was reported as 58 because the number came from a
subdirectory.

Use completion language that says what may safely happen next:

> Implemented and locally verified; remote execution blocked; release
> integration outstanding.

The local verification set for backend work is **all five**: `php -l`,
`phpstan`, `pint --test`, `phpunit`, `skills:validate`. Fewer than five is a
narrower claim than it sounds.

*Enforced by:* nothing. Judgement rule, and the one most often broken.

---

## 9. Safeguards before capability; abstractions alongside their first use

These are compatible, not opposed:

- **Build a safeguard before the behaviour it must protect becomes reachable.**
  Cardinality and the empty-set rule landed before the handlers that could
  otherwise pass over an empty collection.
- **Build a specialised abstraction alongside its first real consumer.**
  `selector_identity` validates `where:` against fixture inputs, and no case uses
  `where:` yet — building it now would add a gate that checks nothing, which is
  the shape this work exists to remove.

New infrastructure needs **a named consumer or a named failure mode**. "We might
want this later" is weaker than "this handler can otherwise pass over an empty
collection".

*Enforced by:* `.github/deferred.yaml` + `check_deferred.py` and
`.github/test-suites.yaml` + `check_suites_wired.py`, which both fail on
undeclared work **and** on a declaration whose subject is gone.

---

## The review record

For consequential work, in the commit body or the PR:

```
Objective:
Observed evidence:
Hypothesis and remaining uncertainty:
Contract that must hold:
Smallest change:
Deliberate defect the tests must catch:
Local verification:
Remote verification:
Remaining release blockers:
```

---

## The registry pattern, restated

Whenever something is declared — a suppression, a deferral, a suite, an
exception, a property type — a checker must verify **both**:

1. nothing undeclared exists, and
2. nothing declared is stale.

Prove both directions by hand before wiring it into CI. One direction is a
comment with a linter attached.
