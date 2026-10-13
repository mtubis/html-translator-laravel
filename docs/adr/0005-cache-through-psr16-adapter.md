# 0005. Unit cache: Laravel cache store through Symfony's Psr16Adapter

- Status: accepted
- Date: 2026-10-13

## Context
The core caches units through a PSR-6 pool (core ADR-0016) and uses `getItems()`, `saveDeferred()` and `commit()`. Laravel cache stores expose PSR-16 (`Illuminate\Cache\Repository` implements `Psr\SimpleCache\CacheInterface`), not PSR-6. Writing our own PSR-6 pool means re-implementing deferred saves, key validation and expiry semantics correctly — easy to get subtly wrong.

## Decision
Require `symfony/cache` and wrap the configured Laravel store: `new Psr16Adapter(Cache::store($store), $prefix, $ttl ?? 0)`. Cache is enabled by default (`cache.enabled`), uses the default store unless `cache.store` names one.

## Consequences
- A battle-tested PSR-6 implementation; one extra dependency (`symfony/cache` and `symfony/cache-contracts`).
- Values are serialized by Symfony's marshaller inside Laravel's store; `php artisan cache:clear` clears them with everything else, and a dedicated store can be configured to avoid that.
