---
name: ali-mousavi-backend-style
description: "Use when matching Ali Mousavi's backend coding style."
version: 1.0.0
author: Amir (requester), Hermes Agent (analysis)
platforms: [linux, macos, windows]
---

# Ali Mousavi — Offline Backend Coding Guide

## When to Use

Use to implement or review Laravel backend work compatible with the observed multi-tenant Fusion backend and, where explicitly identified, with the second (single-tenant) backend. This package explains every project-specific concept inline. Reading it requires **no Git account, no repository access, no source links, and no other installed skill**.

This is a compatibility guide, not a claim to reproduce a person exactly. Never set commit authorship to Ali Mousavi or imply his approval of a change.

## Evidence and Limits

Two backend snapshots were examined. The multi-tenant Fusion backend is the primary study; the second backend is a contrast, not a copy of the same design. A bounded scan of the latest commits per branch returned ~552 author-matched metadata records (including merges; 78 use a typo-variant email and are weaker identity evidence). Six changes were read line-by-line; every "observed in a change" claim in this package comes from those six.

Use these labels throughout:

- **Convention** — present in shared source; authorship of the whole pattern is not proven.
- **Observed code** — copied from the repository, with declared omissions; not runnable on its own.
- **Illustrative example** — adapted to explain a point; not original project code.
- **Recommended guardrail** — advice for your next change, not a historical claim.

No PHP runtime, Composer environment, database or queue worker was available during this study. Static reading and documentation checks prove nothing about framework behaviour or production safety. Never report a build/test result this guide cannot back up.

## Mental Model

A domain-grouped, multi-tenant Laravel application. Source contains HTTP Requests/Controllers/Resources, Policies, Repositories, Actions, Services, Models with capability interfaces and traits, Jobs, Events, Listeners and Observers. This is **not** proof of strict layered architecture: some controller helpers still run reporting queries directly.

The recurring conventions worth preserving:

1. **Constants instead of column strings.** Model interfaces compose small capability interfaces; models inherit those constants and the companion traits.
2. **Getters can be dynamic.** A magic `__call` maps `getX()/setX()` onto attributes whose names exist as class constants. A docblock `@method` proves nothing at runtime.
3. **Access-aware queries.** The repository `index($user)` entry point dispatches by caller category; a raw `query()` is not interchangeable with it.
4. **Role-aware output.** The base resource merges common fields with audience-specific ones; response design is part of the access boundary.
5. **Small named operations.** Actions expose `handle()`; the local `run()` helper invokes it synchronously — never describe an action as queued.
6. **Domain services for sustained work.** Preserve the existing Actions/Services/Repositories split; do not add a parallel generic architecture.
7. **Tenant context is real state.** Models, queries, cache keys, jobs and migrations must agree about central versus tenant connection.
8. **Minimal correction before redesign.** Fix the policy, rule, resource or operation in place; do not re-architect to solve a local problem.

These are actionable without the original repository. For an existing local project, that project's actual contracts always win. For a new project, implement each capability explicitly — do not import unavailable classes and call it a working example.

## Read by Task

- `references/http.md` — routes → request → controller → resource; guards, filters, policies, the response envelope.
- `references/persistence.md` — model interfaces, traits, constants, magic getters, repositories, migrations.
- `references/contracts.md` — payload shapes, validation ownership, pivot payloads, "absent vs empty" semantics.
- `references/tenancy.md` — central/tenant lifecycle, context helpers, config scopes, migrations, pitfalls.
- `references/services-jobs-cache.md` — Actions, services, jobs, observers, transactions, caching, scheduling.
- `references/personal-style-tests.md` — observed changes line-by-line, formatting rules, how tests are written.
- `references/prestige-boundaries.md` — what is shared with the second backend and what must not be ported.
- `references/implementation-playbook.md` — step-by-step assembly of a compatible change + self-review checklist.

## Procedure

1. Classify the request: HTTP endpoint, persistence, domain operation, integration, queue, reporting or migration. Identify caller and tenant scope before writing.
2. Read the matching guide here, then find the nearest sibling in the target codebase and mirror its contracts.
3. Work outward-in: constants/interface → model + cast + relation trait → migration → repository → request + rule → controller + policy → resource → test → doc.
4. Trace input validation, authorization, state transition, persistence and output. Add a layer only when a responsibility needs it.
5. Add a regression test for the changed behaviour in the same change. A test in a sample change proves that test exists, not that every historical change had one.
6. Review connection choice, eager loading, cache invalidation, transaction failure and queued execution context. Separate correctness from query-cost concerns.
7. With a compatible runtime, run syntax checks, the project formatter (`./vendor/bin/pint`) and targeted tests. Run broad suites when relevant and safe. Never run production migrations to validate style.
8. Report exact commands/results, missing checks, and central-vs-tenant migration scope. Keep unrelated cleanup in a separate change.

## Pitfalls

- Do not copy permissive `authorize() { return true; }` or raw repository queries as an authorization policy — object-level permission is checked in controllers/policies and scoping in the repository.
- Do not put restricted fields into the common resource array because one caller is an admin.
- Do not infer central versus tenant from a class name; inspect the connection constant and tenancy initialization.
- Do not assume `response()`, `sendResponse()`, a Resource and a paginated collection share one envelope.
- Do not infer asynchronous behaviour from an action's name; the `run()` helper is synchronous.
- Do not treat `SerializesModels` as sufficient tenant isolation or as exactly-once processing.
- Do not add bulk maps or new abstractions for a cheap, already-cached query path without evidence; correctness issues stay important even on cached or low-traffic paths.
- Never reproduce credentials, real customer data, unrelated personal information, or historical spelling errors as a style requirement.
- Do not run a repository-wide formatter inside a behavioural change.

## Verification Checklist

- Constants and capability traits agree with the schema; every tenant model declares its connection.
- Request authorization, query scoping and resource field visibility were checked independently.
- Payload contracts are explicit, including error, pagination and "absent vs empty" variations.
- Central and tenant side effects are separated; migrations live in the correct directory.
- Transaction, queue and cache failure paths were considered.
- Examples are marked as observed code or adaptations, never advertised as runnable applications.
- Actual verification limits are reported; no fabricated build or test success.
