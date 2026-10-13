# Releasing

Versions follow [Semantic Versioning](https://semver.org/); while the version is 0.x, a minor version may break the
public API (ARCHITECTURE §4), and the CHANGELOG says how. Releases are cut from `main`.

## Checklist

1. **Green build**: `main` is up to date and `composer check` passes locally and in CI.
2. **Real APIs**: `set -a; . ./.env; set +a; composer test:integration` passes with keys for at least one
   OpenAI-compatible provider and Anthropic (it costs a few tokens; see `.env.dist`).
3. **CHANGELOG**: move the entries of `[Unreleased]` into a new `## [X.Y.Z] - YYYY-MM-DD` section (the tag date), with
   a one-line summary and links to the ADRs of new decisions. Update the link references at the bottom: `[Unreleased]`
   compares `vX.Y.Z...HEAD`, `[X.Y.Z]` points to the release tag.
4. **Version constraints**: the core constraint in `composer.json` follows the core's current minor (ADR-0002), and the
   README's `composer require mtubis/html-translator-laravel` line needs no constraint while 0.x.
5. **Roadmap**: mark the milestone as done in [ROADMAP](ROADMAP.md) and close the GitHub milestone.
6. **Commit and merge** these changes (`docs: release X.Y.Z`) through a pull request.
7. **Tag and publish** (maintainer only): tag the merge commit on `main` with `vX.Y.Z`, push the tag, create the GitHub
   release from the CHANGELOG section and check that Packagist picked up the version. If the tag is created on a later
   day than the CHANGELOG date, correct the date first.

```bash
git switch main && git pull
git tag -a vX.Y.Z -m "X.Y.Z"
git push origin vX.Y.Z
gh release create vX.Y.Z --title "X.Y.Z" \
    --notes "$(awk '/^## \[X.Y.Z\]/{f=1;next} f&&/^(## \[|\[[^]]+\]: )/{exit} f' CHANGELOG.md)"
```

## First release only: Packagist

1. Sign in at [packagist.org](https://packagist.org) with the GitHub account that owns the repository.
2. **Submit** `https://github.com/mtubis/html-translator-laravel`; the package name comes from `composer.json`
   (`mtubis/html-translator-laravel`).
3. Enable automatic updates (the GitHub hook, or the Packagist GitHub app) so later tags appear without a manual update.
4. Check that `composer require mtubis/html-translator-laravel` resolves `v0.1.0` in a fresh Laravel 13 application and
   that `php artisan html-translator:check` and `php artisan about` work there.
