---
name: draft-core-issue
description: Use when work in this package runs into a limitation, bug or awkward API of the core library mtubis/html-translator — instead of patching vendor code or working around it silently.
---

# Draft an issue for the core

1. Confirm it really is a core concern: reproduce it with core classes only (a minimal PHP snippet, no Laravel). If it reproduces only with Laravel, it is a package problem.
2. Check existing core issues: `gh issue list -R mtubis/html-translator --search "<keywords>"`.
3. Write the draft to `docs/core-feedback/<kebab-title>.md` in the core's issue format: `# Title`, `## Context` (what the Laravel package needs and why; link the package issue), `## Scope`, `## Acceptance criteria`, `## Out of scope`. Include the minimal reproduction.
4. Show the draft to the maintainer. Create it on GitHub only when asked: `gh issue create -R mtubis/html-translator --title "…" --body-file docs/core-feedback/<file>.md`.
5. In the package, either wait, or add a temporary workaround marked `// TODO(core#<n>): remove when …` with a test that will fail once the core is fixed (so the workaround is noticed).
