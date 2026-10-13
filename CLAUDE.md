# html-translator-laravel

Laravel integration of `mtubis/html-translator` (the **core**): config, facade, Artisan, Laravel HTTP/cache/log, later queues, events and Eloquent. All translation logic lives in the core.

## Read before planning
- `docs/ARCHITECTURE.md` — public API (§4), components and dependency rules (§5), testing (§7).
- `docs/adr/` — accepted decisions. Do not contradict an accepted ADR; propose a new one (skill `write-adr`).
- `config/html-translator.php` — the configuration is the specification of what the package supports.
- The GitHub issue (`gh issue view <n>`); its acceptance criteria are the definition of done.
- Core behaviour: `vendor/mtubis/html-translator/docs/` (usage.md, providers.md, profiles.md) and its `src/`. Read, never edit.

## Commands
- `composer check` — Pint (test mode), Rector dry-run, PHPStan (Larastan, max), PHPUnit. Must be green before you say you are done.
- `composer test` · `composer test:unit` · `composer test:feature` · `composer test:integration` (real APIs; do not run unless asked)
- `composer stan` · `composer lint` (Pint fix) · `composer rector` · `composer infection`
- `vendor/bin/phpunit --filter ManagerTest` · `composer serve` (Testbench workbench app)

## Rules
- PHP ^8.4, Laravel ^13 (ADR-0003). `declare(strict_types=1);`, `final` classes, `readonly` where possible, constructor injection.
- **Core public API only** (ADR-0002): never inject `Segmenter`, `HtmlLoader`, `StructureValidator`, `Reassembler`, `Batcher`, `TermChecker`, `PromptBuilder`. Never edit `vendor/`, copy or subclass core classes. If the core needs a change, draft an issue for `mtubis/html-translator` and tell me.
- **No facades or `config()`/`app()` helpers inside `src/`** — except the service provider, which reads config and passes arrays to factories. Facades are for application code.
- `Config\*` factories are pure (arrays in, core objects out) and are unit-tested without Testbench.
- Every package exception implements `HtmlTranslator\Exception\TranslatorException`. Config errors are `InvalidConfigurationException` with the full key (ADR-0007).
- Secrets (API keys, extra header values) never appear in exception messages, logs, `about`, command output or test snapshots. Test this explicitly when you touch such code.
- Prefer `illuminate/*` contracts (`Illuminate\Contracts\Cache\Factory`, `Illuminate\Http\Client\Factory`, `Psr\Log\LoggerInterface`) over concrete classes.
- Laravel naming for public API (`fake()`, `assertTranslated()`, `--to=`), core naming for core concepts (profile, fallback strategy, issue).
- English for code, comments, docs and commits. Ask before adding a Composer dependency.

## Workflow
Use the `work-on-issue` skill for every issue: plan (plan mode) → tests first → implement → `composer check` → `architecture-reviewer` subagent → Conventional Commits with `Refs #<n>`. Public API from ARCHITECTURE §4 changes only with an ADR.

## Testing conventions
- PHPUnit attributes, snake_case behaviour names (`it_names_the_key_of_an_invalid_effort`), as in the core.
- `tests/Unit` — no container. `tests/Feature` — extend `Tests\TestCase` (Testbench); use the `pseudo` driver or `Http::fake()` with canned vendor JSON. No network outside `#[Group('integration')]`.
- Set config in tests with `config()->set()` in the test or `defineEnvironment()`, never by editing `config/html-translator.php`.

## Don't
- Don't wrap or re-export `TranslatedDocument`; return the core result.
- Don't add Laravel-side HTTP retries (the core `Retrier` retries).
- Don't edit `.github/`, `composer.json` dependencies or `.claude/` unless the issue asks for it. Don't push or open PRs unless asked.
