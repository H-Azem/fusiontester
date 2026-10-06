# Boundaries — what is shared with the second backend, what is not

The studied developer maintains two Laravel backends. They share a skeleton and a coding philosophy, but they are **not** the same architecture, and code does not move between them by copy-paste. Read this before you assume a pattern from one repository is valid in the other.

## 1. Shared foundation (safe to assume in both)

- PHP 8.3, Laravel 13 skeleton (`laravel/laravel` composer name), Laravel Pint with the **Laravel preset** and an identically shaped `pint.json`; `.editorconfig` with 4-space indent, LF, final newline.
- `alimousavi/filoquent` as a required package, providing the `Filterable` model trait and the `FilterAbstract` base used by every `app/Filters/*Filter.php`.
- The same folder blueprint under `app/`: `Attributes`, `Constants`/`Enums`, `Console`, `Filters`, `Http` (`Controllers`, `Requests`, `Resources`, `Middleware`), `Interfaces` (`Contracts`, `Models`, `Traits`), `Models`, `Observers`, `Policies`, `Providers`, `Repositories`, `Rules`, `Services`, `Traits`, plus global `app/helpers.php` registered through composer `autoload.files`.
- The **model + interface + trait** convention: an interface under `app/Interfaces/Models/...` holds `const TABLE` and column constants by extending small capability interfaces (`HasIdInterface`, `HasNameInterface`, `HasEmailInterface`, `HasIsActiveInterface`, `HasTypeInterface`, …); the model implements it and uses the matching `Has<X>Trait` implementations; `MagicMethodsTrait` supplies `getX()`/`setX()` from those constants.
- `BaseRepository` with `query()`, `dbQuery()`, `dbQueryFor()`, and an `index($user)` dispatcher that routes to `getAdminIndex` / `get<Type>Index` / a fallback, plus a `canIndex($user, $model)` style existence check.
- Base controller constants (`DEFAULT_PAGE_SIZE = 15`, `FILTERS`, `QUERYABLES`, `PER_PAGE`, `STATUS`, `ERRORS`, `MODEL`, `RESPONSE`, `MESSAGE`) and `sendResponse()` / `sendErrorResponse()` / `deleted()` helpers. `JsonResource::wrap(Controller::RESPONSE)` is what makes the `{response, status}` envelope.
- `BaseResource` with the audience dispatch (`getCommonArray` + `getAdminArray` / `getUserArray` / `getCustomerArray` / `getPlatformAdminArray`) and a `SuccessStatusTrait` that injects `status`.
- `BaseRequest` with `authorize() → true`, `safeValidated()` + `$excluded`, `forUser()`/`forCustomer()` admin override, and `anotherRequestRules($prefix, $request, $except)`.
- Global helpers for phone/file/number formatting and code encode/decode: `normalize_phone`, `human_file_size`, `number_to_base9`/`base9_to_number`, `number_to_base36`/`base36_to_number`, `generate_code`/`is_code_valid`, `date`/`distance`-style utilities.
- Long-lived API clients extend `ApiAbstract` (token cached under a key with `$tokenExpiresIn`, abstract `getHeaders()`/`login()`/`checkResponse()`, `post()`/`get()` that translate transport failures into translated exceptions).

## 2. Fusion-only (the primary backend, the default assumption)

| Feature | Why it is Fusion-only |
|---|---|
| `app/Actions/**` with `handle()` and the `run(new XAction)` helper | The second backend has **no `Actions` directory** at all; its logic lives in Services/Repositories/Controllers. |
| **Multi-tenancy** (`stancl/tenancy`, `ConnectionConstants::TENANT_CONNECTION`/`APP_CONNECTION`, `InitializeTenantMiddleware` on `{company}` routes, `database/migrations/tenant`, `LoadCompanyConfigs` listener, `TenantConfigContext`) | The second backend is single-database, no tenancy package, no `tenant` connection, no per-tenant config context. |
| `init($companyId)` / `find($companyId, $fail)` / `tenant_config()` / `location_config()` helpers | These are tenancy-scoped helpers; in the second backend the equivalent config access is plain config/repository reads. |
| `LiveCommandAbstract` one-shot production actions with central+tenant run records | Needs the two-connection split. |
| Jobs initializing the tenant in the constructor | No tenant to initialize in the second backend. |
| PHPUnit as the primary test runner, `tests/Unit` + `tests/Feature`, `#[Test]` attributes, reflection into private helpers, `Mockery` for absence-of-query assertions | The second backend uses **Pest 5**, `it(...)` closures, `uses(LazilyRefreshDatabase::class)`, HTTP-level assertions (`actingAs(...)`, `getJson(route(...))->assertJsonPath(...)`). |
| The huge integration/service surface (QuickBooks, Doordash/Uber, KitchenHub, Google Food Ordering, Telegram bots, Square/Stripe, Pusher websockets, Horizon queues, `MenuCacheStore` snapshot/generation machinery) | These are business-domain integrations of the restaurant product. Do not port them into another backend as "style". |
| `app/Enums/**` (e.g. `MenuScope`), `AttributeConstants` type→class maps, `#[RecognizedBy]` attributes, `#[ObservedBy]` registration | Present as a coherent set in Fusion; check the target repo for the same primitives before using them. |

## 3. Second-backend-only (do not impose these on Fusion)

- **Pest test style** — `it('scopes the user index by the authenticated user type', function (): void { … })`, `uses(LazilyRefreshDatabase::class)`, `actingAs($admin)`, `getJson(route('users.index'))->assertSuccessful()->assertJsonCount(5, 'response')`, factories with state methods (`User::factory()->admin()->create()`).
- `BaseRepository::getEmptyIndex()` returning a deliberately impossible predicate (`->where(HasIdInterface::ID, '<', 0)`) for the anonymous/no-auth case — Fusion instead falls back to `getCustomerIndex`/`getUserIndex` with a nullable user.
- **Typed constants syntax**: `public const string TABLE = 'users';` and `public const int DEFAULT_PAGE_SIZE = 15;` (class-constant types, PHP 8.3) — Fusion writes `const TABLE = 'options';` without types. Match whichever the file you are editing already uses.
- A smaller dependency surface (dompdf, firebase/php-jwt, horizon, predis, filoquent) versus Fusion's wide integration set.
- Route naming with `route('users.index')`-style lookups and index endpoints asserting the `response` key count directly.

## 4. Practical rules when working across both

1. Before writing a single line, identify which backend you are in (does `app/Actions` exist? is `stancl/tenancy` in `composer.json`? is there a `tests/Pest.php`?). Then pick the pattern set from the matching section here.
2. Shared skeleton does **not** mean shared runtime: never introduce `tenant()`/`tenant_config()`/`init()` calls into a single-tenant backend, and never assume a single-tenant model can be reached with `Model::query()` in a tenant context without the connection constant.
3. Keep the shared layer stable: when you touch `BaseRepository`, `BaseRequest`, `BaseResource` or the base `Controller`, you are changing a contract used by every domain in the repo — treat it as a public API and adjust all call sites in the same change.
4. Testing framework follows the repository, not personal preference. Adding PHPUnit attributes to a Pest repo (or vice versa) will not run.
5. Formatting is identical in both (`pint` Laravel preset), so formatting normalizations inside touched lines are always safe; repo-wide reformatting never is.
