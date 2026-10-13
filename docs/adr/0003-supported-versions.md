# 0003. Supported PHP and Laravel versions

- Status: accepted
- Date: 2026-10-13

## Context
The core requires PHP ^8.4. In October 2026 Laravel 13 (March 2026, PHP 8.3–8.5) is the current major; Laravel 12 receives security fixes only (until February 2027); Laravel 14 is expected in Q1 2027 and will require PHP 8.4. Each additional Laravel major doubles parts of the CI matrix and Testbench versions.

## Decision
- PHP `^8.4` (inherited from the core), tested on 8.4 and 8.5.
- Laravel `^13.0` only (`illuminate/*` components, Testbench `^11`). Laravel 14 is added in a minor release once Testbench supports it.
- A Laravel major is dropped in the next minor release after its bug-fix window ends.

## Consequences
- Applications still on Laravel 12 cannot install the package; adding `^12.0` later is cheap if users ask (Testbench 10, one more CI row).
- New Laravel 13 APIs (job attributes like `#[Tries]`, `Queue::route`, `Cache::touch`) can be used freely.
