# Changelog

All notable changes to this project are documented here. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project adheres to [Semantic Versioning](https://semver.org/).

## [Unreleased]

### Added
- Project skeleton: architecture, ADRs 0001–0008, configuration specification, service provider that merges and publishes the configuration.
- `LaravelPsr18Client`: model calls go through Laravel's HTTP client (ADR-0004, accepted), so `Http::fake()`, Telescope and Laravel's HTTP events see them; connection failures become a PSR-18 `NetworkException` with credentials redacted.
