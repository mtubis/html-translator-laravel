# 0002. Use only the core's public API; core changes go to the core

- Status: accepted
- Date: 2026-10-13

## Context
The core is 0.x and its 1.0 freeze (core issue "Public API review and the backward compatibility promise") will mark the pipeline internals (`Segmenter`, `Reassembler`, `PromptBuilder`, `Retrier`, …) as `@internal`. A framework package that reaches into them breaks on every core refactoring. It is also tempting for an agent to "just fix" a core problem from inside this repository.

## Decision
- The package uses the core's public API tier only: `HtmlTranslator` constructed with provider, cache, logger, `SkipRules`, `TranslatableAttributes`; `TranslationOptions`, results, profiles, exceptions, provider and chat-model ports, adapters and their configs, `RetryPolicy`/`Retrier`, `PseudoLocalizationProvider`. It never injects `HtmlLoader`, `Segmenter`, `StructureValidator`, `Reassembler`, `Batcher` or `TermChecker`.
- The core is never patched from here (no edits in `vendor/`, no copies of core classes, no subclassing to override behaviour). A needed change is drafted as an issue for `mtubis/html-translator` (`gh issue create -R mtubis/html-translator`), and the package works around it only in a clearly marked, temporary way.
- The Composer constraint follows the core's current minor (`^0.4`); after the core's 1.0, `^1.0`.

## Consequences
- Core refactorings do not break the package.
- Friction with the core API becomes visible as core issues before the 1.0 freeze.
- Some conveniences wait for a core release.
