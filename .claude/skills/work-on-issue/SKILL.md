---
name: work-on-issue
description: Use when asked to work on, implement, start or continue a GitHub issue in this repository (e.g. "work on #4", "implement issue 7"). Runs the plan → tests → implement → verify → review → commit loop.
---

# Work on an issue

Argument: issue number.

1. **Load context**
   - `gh issue view <n>` — scope, acceptance criteria, out-of-scope.
   - `docs/ARCHITECTURE.md` sections and ADRs the issue names; `config/html-translator.php` for any config key involved.
   - The core code and docs you will call, in `vendor/mtubis/html-translator/` (`docs/usage.md`, `docs/providers.md`, `docs/profiles.md`, the constructors you need). Do not guess core signatures — read them.
   - Existing package code and tests you will touch.
2. **Branch**: `git switch -c <type>/<n>-<short-slug>` unless already on one.
3. **Plan** (plan mode): files, public signatures, config keys read, test cases mapped to each acceptance criterion. Flag: anything that would need a core change (ADR-0002), anything conflicting with an ADR, any new dependency. Wait for approval.
4. **Tests first.** Unit tests for factories (no Testbench), feature tests in Testbench for anything with the container. Stop and show the tests when the issue says "review tests first" or touches public API (ARCHITECTURE §4) or secrets.
5. **Implement** the minimum, following `CLAUDE.md`. Run the relevant tests often.
6. **Verify**: `composer check` green. Tick every acceptance criterion in your summary; say why if one is not met. For user-visible behaviour, also try it in the workbench (`composer serve` or `vendor/bin/testbench html-translator:…`).
7. **Review**: `architecture-reviewer` subagent on `git diff main...HEAD`. Fix real findings; list skipped ones with a reason.
8. **Commit** in logical Conventional Commits (`feat(config): …`, `feat(console): …`, `test: …`), each ending with `Refs #<n>`. Update CHANGELOG `[Unreleased]` for user-visible changes.
9. **Report**: what changed, criteria status, core friction found (draft core issue titles), follow-ups. Do not push or open a PR unless asked.
