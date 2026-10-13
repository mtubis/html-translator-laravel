# 0008. PHPUnit and Orchestra Testbench

- Status: accepted
- Date: 2026-10-13

## Context
Pest is popular in the Laravel ecosystem; the core uses PHPUnit 13 with attributes, PHPStan at max and Infection. One maintainer works across both repositories, with the same agents and review rules.

## Decision
PHPUnit (same version line and conventions as the core) with `orchestra/testbench` for anything that needs the container. Larastan for PHPStan at level max. Laravel Pint for code style, configured with the core's rule set (`pint.json`), so both repositories format identically while using the tool Laravel contributors know.

## Consequences
- Same test style, same reviewer rules, same mutation-testing setup in both repositories.
- Contributors who prefer Pest can still read and write the tests easily.
