# 0001. Record architecture decisions

- Status: accepted
- Date: 2026-10-13

## Context
As in the core: the package is built mostly by coding agents steered by one maintainer, and agents start every session without memory.

## Decision
Significant decisions are recorded as short ADRs in `docs/adr/`, numbered sequentially, using `0000-template.md`. Agents read the relevant ADRs before planning and propose a new ADR instead of deviating from an accepted one. Numbering is independent of the core's ADRs; a reference to a core decision is written as "core ADR-0011".

## Consequences
Consistent decisions across sessions; changes of direction are explicit.
