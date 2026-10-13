# 0007. Configuration is validated by the package, with keys in the messages

- Status: accepted
- Date: 2026-10-13

## Context
Config arrays come from PHP files and `.env`: types are loose (`env()` returns strings, `null` or booleans), and a wrong value would otherwise surface as a `TypeError` deep inside a core constructor (core issue "Configuration from untyped arrays fails with InvalidArgumentException" improves the core side but cannot name config keys).

## Decision
- `Config\*` factories read arrays through a small `ConfigReader` that checks every value's type and allowed values and throws `InvalidConfigurationException` with the full key (`html-translator.providers.anthropic.effort: expected one of low, medium, high, xhigh, max, got "hihg"`).
- Secret values (API keys, extra header values) are never quoted in messages.
- Validation happens when a provider is first resolved (lazy), not at boot, so an app without keys for an unused provider still boots. `php artisan html-translator:check` (0.1) resolves every provider entry and reports all problems at once.
- Core `InvalidArgumentException`s raised while building core objects are re-thrown as `InvalidConfigurationException` with the key and the core message.

## Consequences
- Clear errors for the most common support question ("why doesn't it work") — and safe to paste into an issue.
- Duplicated validation with the core in places; acceptable, the messages serve different readers.
