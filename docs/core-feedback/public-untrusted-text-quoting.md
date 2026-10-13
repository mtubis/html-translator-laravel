# Make quoting of untrusted text public API for integrations

## Context
`mtubis/html-translator-laravel` validates its configuration itself (package ADR-0007) and reports errors such as
`html-translator.profiles.docs.formality: expected one of default, formal, informal, got "casual"`. The offending
values come from `.env` and config files, so they must be quoted the way the core quotes untrusted text: bounded, valid
UTF-8, one line, with no control or bidi characters that could forge or reorder log lines.

`HtmlTranslator\Exception\UntrustedText::quote()` does exactly this but is `@internal`. Under package ADR-0002 (core
public API only), the Laravel package currently carries a marked temporary copy of the method in
`src/Config/ConfigReader.php`. The Symfony bundle will need the same thing. Two copies would drift from the core's
rules, which were hardened in #43.

Package issue: mtubis/html-translator-laravel#2.

Minimal reproduction (core only):

```php
use HtmlTranslator\Exception\UntrustedText;

// Works, but UntrustedText is @internal, so integrations may not rely on it:
echo UntrustedText::quote("casual\n\u{202E}x"); // casual\n\u{202E}x
```

## Scope
- Make `UntrustedText::quote(string $text, int $maxLength = 200): string` public API: remove `@internal`, document it
  in `docs/usage.md` (section on exceptions) and cover it by the BC promise. Alternatively, expose an equivalent public
  helper and keep `UntrustedText` internal.
- Document what is guaranteed: valid UTF-8 (U+FFFD substitution), at most `$maxLength` characters before escaping,
  `\n`/`\r`/`\t` and other unsafe characters escaped, no surrounding quotes added.

## Acceptance criteria
- [ ] The quoting helper is part of the public API and documented, including the guarantees above.
- [ ] Existing tests cover the documented guarantees, so a change to them is a visible BC break.
- [ ] `composer check` green.

## Out of scope
- Redacting secrets: callers decide what is a secret and never pass it in.
- A config-validation helper in the core (see #78 for array element validation).
