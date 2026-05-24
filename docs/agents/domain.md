Domain docs layout — single-context

Selected layout: single-context

What this means

- The repository should have a single `CONTEXT.md` at the repository root describing the project's domain language, important business rules, and conventions.
- Architectural decisions should be recorded under `docs/adr/` as individual ADR files (e.g., `docs/adr/0001-use-events.md`).

Consumer rules for skills

- Skills that read domain information will look for `CONTEXT.md` at the repository root.
- Skills that examine architecture will read `docs/adr/` for decision history.

If you don't have these files yet

- Create `CONTEXT.md` at the repo root with a short project summary and important domain terms.
- Create `docs/adr/` and add ADRs as decisions are made.
