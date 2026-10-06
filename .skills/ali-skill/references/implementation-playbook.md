# Implementation playbook — assemble a compatible change, then review it

End-to-end procedure for adding or changing behaviour in this codebase so the result looks like it was written by the same team. Read `http.md`, `persistence.md`, `contracts.md`, `tenancy.md` and `services-jobs-cache.md` for the detail behind each step; this file is the order of operations and the checklist.

## Step 0 — Reconnaissance (5 minutes, mandatory)

1. Which backend? Presence of `app/Actions` + `stancl/tenancy` in `composer.json` means the multi-tenant one (`prestige-boundaries.md` lists the differences).
2. Which test framework? `tests/Pest.php` → Pest closures; `phpunit.xml` + attribute-based tests → PHPUnit.
3. Find the nearest existing sibling for what you are about to write and copy its shape:
   - a CRUD endpoint → the closest `<X>Controller` + `<X>StoreRequest` + `<X>Resource` + `<X>Repository`
   - a list endpoint → a controller using `index($user)` + a `<X>Filter`
   - a domain operation → an `Action` with `handle()`, or a `Service` if it is long-lived
   - deferred work → a `Job` next to its domain folder
4. Check the connection the data lives on (`ConnectionConstants`) and the isolation key (`user`, `customer`, `location`, `company`).

## Step 1 — Model layer (constants first)

1. Add/extend the model **interface** with `const` declarations for every column and every enum-like value (`const HAS_DESCRIPTION = 'has_description';`) and extend the capability interfaces that fit (`HasNameInterface`, `HasIsActiveInterface`, …).
2. Implement them in the model: `use` the matching traits, set `protected $table = self::TABLE;`, `protected $connection = ConnectionConstants::TENANT_CONNECTION;`, `protected $guarded = [self::ID];`, add `protected $casts = [self::X => 'boolean'];` before the static arrays, add `public static array $types = [...]` when the model has a type column.
3. Relation behaviour goes into a trait next to the model (`HasOptionsTrait` with `#[RecognizedBy(Option::class)]` and `->withPivot(...)`) — a new pivot column is not complete until the relation loads it.
4. Add behaviour that must always run (normalization, derived defaults) to an **observer**, registered with `#[ObservedBy(...)]` on the model.

## Step 2 — Persistence

1. Repository extends `BaseRepository`, sets `$this->model = new X;` in the constructor.
2. Implement `create(array $attributes)` / `update(X $model, array $attributes)` returning `X|Model`.
3. Override only the `get<Role>Index(Builder $builder, …)` hooks you need; list endpoints must go through `index($user)` so scoping is applied in one place.
4. Wrap multi-write operations in `DB::transaction(...)` (or manual `beginTransaction/commit` when you need a custom abort), and keep transactions out of job bodies.
5. Migration lives in the right directory: `database/migrations/tenant/` for tenant tables, top level for central. Column names come from the interface constants; add `softDeletes()` unless the table genuinely must hard-delete.
6. If seed-like data is involved, make the seeder **idempotent per row** (`updateOrCreate` on a natural key) rather than guarded by an `exists()` early return.

## Step 3 — HTTP layer

1. **Request**: extend `BaseRequest`; `rules()` keyed by model constants; `Rule::exists(...)->withoutTrashed()->where(OtherModel::COL, $this->input(...))` for relational integrity; a `Rule::unique(...)` scoped by the same parent; `Rule::in([...])` for enumerated values; extract repeated blocks into a `app/Traits/Request/*` trait and spread with `...$this->rulesFor(...)`.
2. Custom checks that need database state go into `app/Rules/<Domain>/<Name>Rule.php` implementing `ValidationRule`, with **early returns** for shapes the rule does not own.
3. Request helpers (`getCustomer()`, `optionSyncData()`, `getRanges()`) convert validated input into the exact shape the persistence layer expects — controllers must not do array reshaping.
4. Context needed by the response is attached in `passedValidation()` via `$this->attributes->set(...)` and `request()->attributes->set(...)`.
5. New public endpoints go in the route file matching their audience; add extra routes next to the `apiResource` (`batchDestroy`, `restore` with `->withTrashed()`, `duplicate`); use UUID binding (`{model:uuid}`) where the model has a uuid.
6. **Controller**: inject the repository; one line of orchestration per action; `$this->authorize('ability', $modelOrClass)` for every object-level action (`new X()` for create); return the resource for writes, `$this->deleted()` / `$this->batchDeleted($count)` for deletes; `->filter($filter)->paginate($request->perPage())` for lists.
7. **Policy**: state/precondition checks live here, with `$this->deny(__('domain.key'))` when the caller deserves an explanation; keep the deny-list or precondition list short and in one method. Add a `Rule::exists`-independent policy test asserting the allowed and denied statuses.
8. **Resource**: extend `BaseResource`, put audience-neutral fields in `getCommonArray()` keyed by constants, audience-specific fields in `get<Role>Array()`, optional relations through `whenLoaded(...)`, pivot fields through `whenPivotLoaded(...)`, and `use SuccessStatusTrait;`.

## Step 4 — Deferred and cached work

1. Job: `implements ShouldQueue`, `use Queueable, SerializesModels;`, tenant initialized in the **constructor**, guard clauses with early `return` in `handle()`, events emitted before each failure return.
2. Register recurring work in `app/Console/Kernel.php` as `$schedule->job(new XJob)->everyMinute()->withoutOverlapping()->onOneServer();`.
3. For a heavy read model, follow the snapshot recipe: feature-flag → publish snapshot → reader returns the snapshot or signals "not ready" (503 → `null`) → caller falls back to the live query (`?? $model->freshBuild()`) → notifier on fallback.
4. Cache keys come from a `CacheConstants`-style class and include the tenant/location id; skip caching entirely outside production when it is only there to save cycles.

## Step 5 — Tests (ship in the same change)

| Change | Minimum test |
|---|---|
| Bug fix in a state machine | One test flipping exactly the affected statuses |
| New validation rule / request trait | Unit test on the rule or an anonymous class using the trait + in-memory sqlite |
| New/changed payload | Feature test asserting the response keys/paths the frontend reads |
| Extracted private helper | Unit test invoking it directly (reflection is acceptable here) |
| Job serialization/tenancy | Unit test asserting the serialized form / tenant context |
| Seeder / backfill | Run twice, assert counts and reconciled values |
| Cached path | Mock the store, assert `once()->with(...)` and `shouldNotReceive(...)` on the DB path |

## Step 6 — Formatting and hygiene

- `./vendor/bin/pint` (Laravel preset, `class_definition.multi_line_extends_each_single_line`). Touched lines only — no repo-wide reformat inside a behavioural change.
- 4-space indent, LF, final newline, 2 spaces in YAML.
- Single quotes unless interpolating; no space inside concatenation dots (`'a'.$b`); space after `!` (`! $x`); no parentheses on `new X` without arguments; constructor promotion on one line with an empty body: `{ }` → `{}`.
- Imports alphabetized by full namespace, unused imports removed in the change that made them unused, deep namespaces imported rather than inline `\App\…` references.
- Commit: lowercase, imperative, short; `feat:`/`fix:`/`chore:` when it genuinely is one; one behaviour per commit; mention migration scope (central vs tenant) in the PR body.

## Step 7 — Self-review checklist (run before delivering)

- [ ] Every new column exists in the interface constants, the migration and the cast list; query code uses the constant, never the literal.
- [ ] Every tenant model declares `ConnectionConstants::TENANT_CONNECTION` (and central models `APP_CONNECTION`).
- [ ] List endpoints call `index($user)`; no raw `Model::query()` reachable from a controller for a scoped resource.
- [ ] `Rule::exists`/`Rule::unique` are scoped to the parent/location, not only to the id.
- [ ] New response fields are in the right audience method; optional relations use `whenLoaded`; a widened eager-load list travels with the resource change.
- [ ] `options`-style relation payloads distinguish absent (unchanged) from empty (cleared); duplicated parents copy pivot data, not id lists.
- [ ] Jobs: `SerializesModels` present, tenant initialized in constructor, no dispatch inside a transaction that can roll back.
- [ ] Cache reads cannot build inline on the hot path; a fallback path exists and is observable.
- [ ] A regression test exists for the changed behaviour, and the changed response keys still match what consumers read.
- [ ] Formatting limited to touched lines; imports clean; commit message lowercase and specific.
- [ ] Any verification you could not perform (no PHP runtime, no database, no queue worker) is stated explicitly instead of implied.

## Step 8 — Reporting the change (for human reviewers on this team)

Report format that has worked:
1. What changed and why, in one paragraph.
2. Files by layer (migration / model+interface / repository / request+rule / controller / resource / job / test / docs).
3. The exact verification performed (command + observed result), and explicitly what was **not** verified.
4. Central-vs-tenant migration impact, and any deployment step (scheduler, queue, cache warm-up).
5. Risks/rollback in one or two lines. No speculation presented as fact.

Honesty rules for this team: never claim a test run you did not perform; distinguish "static check passed" from "runtime verified"; state the scope of any sample-based analysis (which files/commits were actually read) instead of implying a full audit.
