# SpiderNetOS — working rules

## Where the code is

The canonical checkout is the **nested** repository, `Apex/SpiderNetOS/SpiderNetOS`.
The outer `Apex/SpiderNetOS` is stale — never build, test or commit there.
Confirm with `git rev-parse --git-common-dir` before starting.

## Verify with all five, or narrow the claim

Backend work is verified by `php -l`, **`phpstan`**, `pint --test`, `phpunit`,
and `php artisan skills:validate`. Running four and reporting "clean" is an
overclaim — Larastan has been failing branch-wide while "Pint clean" was being
reported as though it covered static analysis.

Say what was checked and on which revision:

> Implemented and locally verified; remote execution blocked; release
> integration outstanding.

A CI job with `steps=0` did not run the code. That is *unverified*, not *failing*.

## The rules

`docs/internal/engineering-rules.md` is the method: separating observation from
inference, grouping failures by mechanism, acceptance conditions and disproofs,
sabotaging the guard rather than exercising the code, denominators on every pass,
reusing a guarantee only where its prerequisite is enforced, sequencing by
reversibility, reporting verification in dimensions, and building safeguards
before the capability they protect.

Two that bite most often here:

- **A pass must name its denominator.** `declared = 69, executed = 0, failed = 0
  → EXIT 0` was real. Keep missing data, an empty collection, and an unavailable
  checker distinguishable.
- **Prove the guard, not the code.** For any control that matters, introduce the
  smallest realistic defect that would defeat it and watch the suite go red. A
  test asserting only red-versus-green cannot tell a detected violation from a
  check that never ran.

## Declarations need a checker, both ways

When anything is declared — a suppression, a deferral, a test suite, a property
type — a checker must fail on **undeclared work** *and* on **a declaration whose
subject is gone**. See `.github/deferred.yaml` + `check_deferred.py` and
`.github/test-suites.yaml` + `check_suites_wired.py`.

Anything marked unfinished (`TODO`, `FIXME`, "not implemented yet", "for now,
return", "in production this would") must either be made to work or registered in
`.github/deferred.yaml` with impact, what finishing takes, an owner and a review
date.

## CI costs real money

GitHub Actions is metered and the free allowance has been exhausted once. Every
push to an open PR runs ~20 jobs. Batch pushes; do not push per commit. Before
changing workflows to save cost, read `docs/internal/engineering-rules.md` §3 — removing
an *execution* and passing a *check* look identical in a green tick, and every
check must still run in full on the final candidate commit.

## Standing constraints

- **`POSTGRES_HOST_AUTH_METHOD: trust`** is acceptable only for a disposable,
  job-scoped container on a runner's loopback. It must never reach a shared
  environment.
- **Phone fields** are a single international input (`+1`, `+44`, `+263`), never
  a country dropdown.
- **No Azure TTS voices.** Offer ElevenLabs, Fish Audio or Intron.
- The two `Apex/Hannah AI/*.pem.txt` private keys are **compromised** — never
  open, use or copy them; rotation to a vault is a prerequisite for any Hannah
  integration.
- The six named characters (Atlas, Hannah, Forge, Sentinel, Prism, Nexus) are
  never renamed or dropped — only expanded.
