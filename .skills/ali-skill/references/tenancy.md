# Tenancy — central vs tenant, context, configs, migrations

This application is **multi-tenant with stancl/tenancy**. Getting the tenant context wrong produces silently wrong data (queries against the wrong database, jobs running without a tenant, cache keys colliding). Treat this file as required reading before touching any model, query, job or migration.

## 1. Two databases, two connections

```php
// app/Constants/ConnectionConstants.php (verbatim)
class ConnectionConstants
{
    const TENANT_CONNECTION = 'tenant';

    const APP_CONNECTION = 'app';
}
```

- `'app'` connection = **central** database: tenants (`Company`), platform admins, contracts, base catalogs, live-command run records.
- `'tenant'` connection = **one database per tenant**: company data (locations, menus, orders, customers, …).

Every model states its connection explicitly — never rely on the default:

```php
// app/Models/Company/General/Option.php
protected $table = self::TABLE;
protected $connection = ConnectionConstants::TENANT_CONNECTION;
protected $guarded = [self::ID];

// app/Models/Core/Company.php  (the tenant model itself)
protected $table = self::TABLE;
protected $connection = ConnectionConstants::APP_CONNECTION;
public $incrementing = false;              // tenant ids are generated strings
```

`config/database.php` declares `'default' => env('DB_CONNECTION', 'app')` plus an `app` and a `tenant` entry. The tenant connection is re-pointed at runtime by the tenancy bootstrappers; `config/tenancy.php` also sets `migration_parameters` to `--path => database_path('migrations/tenant')`.

**Rule:** a model for tenant data uses `TENANT_CONNECTION`; a model for central data uses `APP_CONNECTION`. If you copy a model and forget the connection line, it will read the central database for tenant data.

## 2. How a tenant request is initialized

`routes/tenant.php` puts `InitializeTenantMiddleware` on every group, and the URL carries the tenant slug:

```php
// routes/tenant.php
Route::group([
    'prefix' => 'api/{company}/{locale}/',
    'middleware' => [InitializeTenantMiddleware::class, 'api'],
], function () {
    // public customer API …
    Route::prefix('backoffice')->group(function () { include 'backoffice.php'; });
});
```

The middleware itself (verbatim, trimmed):

```php
class InitializeTenantMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $route = $request->route();
        if (! in_array('company', $route->parameterNames())) {
            throw new RouteIsMissingTenantParameterException;
        }

        $companySlug = $route->parameter('company');
        $company = $this->getCachedCompany($companySlug);
        if (! $company) {
            throw new NotFoundHttpException(__('errors.invalid_company'));
        }
        tenancy()->initialize($company);
        $locale = $route->parameter('locale', 'en');
        session_context()->put(SessionConstants::LOCALE, $locale);
        app()->setLocale($locale);

        $route->forgetParameter('company');
        $route->forgetParameter('locale');

        URL::defaults([
           'company' => $company->getSlug(),
           'locale' => $locale,
        ]);

        return $next($request);
    }

    private function getCachedCompany(string $slug): ?Company
    {
        $key = CacheConstants::COMPANY.$slug;
        if (! Cache::has($key)) {
            $company = Company::query()->firstWhere(Company::SLUG, $slug);
            Cache::put($key, $company, now()->addHours(6));
        }

        return Cache::get($key);
    }
}
```

Points to preserve:
- `{company}`/`{locale}` are **removed from the route parameters** after use (`forgetParameter`) so controllers never see them, while `URL::defaults` keeps generated URLs correct.
- The tenant lookup is cached for 6 hours under `CacheConstants::COMPANY.<slug>` (central cache).
- `session_context()` is a request-scoped container (see below), not the PHP session.

Other middlewares in the same pipeline: `LoadCompanyConfigMiddleware` (kebab-cases `locale`, resolves `company` and stores it in the session context) and `BindAttributesMiddleware`, which converts a generic `{type}/{id}` route pair into a bound model:

```php
$type = $request->route('type');
$id = $request->route('id');
$class = AttributeConstants::$types[$type] ?? null;

if (!$class) { abort(Response::HTTP_NOT_FOUND); }
$attributable = $class::find($id);
$request->route()->setParameter('attributable', $attributable);
$request->route()->forgetParameter('type');
$request->route()->forgetParameter('id');
```

That is the project idiom for "same endpoint, several parent types": declare a type→class map in a Constants class, bind in middleware, and type-hint a shared interface in the controller.

## 3. Contexts and global helpers

Two request/tenant-scoped containers exist, plus global helper functions in `app/helpers.php` (loaded by composer `files` autoload, so they are available everywhere — including tests):

```php
function config_context()                                  // TenantConfigContext instance
function tenant_config(?string $key = null, mixed $default = null): mixed
function location_config(int|string|Location $location, ?string $key = null, mixed $default = null): mixed
function app_config(?string $key = null, mixed $default = null): mixed      // AppConfigRepository
function session_context(?string $key = null, mixed $default = null)
function init(string $companyId): Company                  // find + tenancy()->initialize()
function find(string $companyId, bool $fail = true)         // by id OR slug
```

`init()`/`find()` are the canonical way to enter a tenant outside HTTP (jobs, commands):

```php
function init(string $companyId): Company
{
    $company = find($companyId);
    tenancy()->initialize($company);

    return $company;
}

function find(string $companyId, bool $fail = true)
{
    $action = $fail ? 'firstOrFail' : 'first';

    return Company::query()
        ->where(function (Builder $query) use ($companyId) {
            $query->where(Company::ID, $companyId)
                ->orWhere(Company::SLUG, $companyId);
        })
        ->$action();
}
```

Configs are split by scope; `TenantConfigContext` holds two maps — company-wide (rows with `location_id IS NULL`) and per-location:

```php
class TenantConfigContext
{
    public function set(Collection $configs): void
    {
        $this->locationConfigs = [];
        $this->configs = $configs->whereNull(Config::LOCATION_ID)
            ->mapWithKeys(fn (Config $c) => [$c->getCode() => $c->getValue()])->toArray();
        $configs->whereNotNull(Config::LOCATION_ID)
            ->each(function (Config $config) {
                $locationId = $config->getLocationId();
                // … $this->locationConfigs[$locationId][$config->getCode()] = $config->getValue();
            });
    }

    public function get(?string $key = null, mixed $default = null): mixed
    {
        if (! $key) { return $this->configs; }

        return $this->configs[$key] ?? $default;
    }
}
```

It is populated when tenancy boots, by an event listener — never queried per request:

```php
class LoadCompanyConfigs
{
    public function handle(mixed $event): void
    {
        if (! $event->tenancy->tenant->getIsActive()) { return; }

        $configs = $this->getConfigs($event->tenancy->tenant);
        app(TenantConfigContext::class)->set($configs);
    }

    private function getConfigs(Company $company)
    {
        if (!app()->isProduction()) {
            return Config::query()->get();
        }

        return Cache::remember(
            CacheConstants::CONFIG . $company->getId(),
            now()->addHours(6),
            fn () => Config::query()->get());
    }
}
```

**Usage rule:** read tenant settings with `tenant_config(ConfigConstants::SOMETHING, $default)` / `location_config($location, ...)`, never with direct `Config::query()` calls in business code. Toggle semantics rely on defaults (`tenant_config(X, false)`) so a missing row means "feature off".

## 4. Tenant lifecycle: create, migrate, seed, delete

`app/Providers/TenancyServiceProvider.php` wires the stancl event pipeline (verbatim fragment):

```php
public function events()
{
    return [
        Events\TenantCreated::class => [
            JobPipeline::make([
                Jobs\CreateDatabase::class,
                Jobs\MigrateDatabase::class,
                //Jobs\SeedDatabase::class,
                ForceSeedDatabase::class,
            ])->send(function (Events\TenantCreated $event) {
                return $event->tenant;
            })->shouldBeQueued(false),
        ],
        Events\TenantDeleted::class => [
            JobPipeline::make([Jobs\DeleteDatabase::class])
                ->send(fn (Events\TenantDeleted $event) => $event->tenant)
                ->shouldBeQueued(false),
        ],
        // …stancl defaults for domain / database events…
    ];
}
```

The same provider also listens for `TenancyInitialized` → `LoadCompanyConfigs`. `config/tenancy.php` uses `'tenant_model' => Company::class`, a custom `'id_generator' => App\Services\CompanyIdGenerator::class`, and enumerates `central_domains` — anything not in that list is a tenant domain, so when adding a new central host, add it there.

The tenant model itself extends the stancl base and reuses the same conventions as domain models:

```php
abstract class BaseTenant extends Tenant implements BaseModelInterface, TenantWithDatabase
{
    use Filterable;
    use HasDatabase;
    use HasDomains;
    use HasFactory;
    use HasIdTrait;
    use MagicMethodsTrait;

    const DELETED_AT = 'deleted_at';
}
```

## 5. Migrations: two directories, never mixed

```
database/migrations/           → central database (companies, domains, contracts, base catalogs)
database/migrations/tenant/    → tenant database (204 files in the studied snapshot)
```

Rules:
- Add a new tenant table/file under `database/migrations/tenant/`; a central one stays at the top level. The tenancy config points `tenants:migrate` at the tenant path, so a misplaced file simply never runs on tenants.
- Column names must match the model interface constants (`$table->string(OptionInterface::NAME)` scale of precision: use `Option::NAME`), since validation and resources reference the same constants.
- `SoftDeletes` is the norm; `deleted_at` is also a constant on the base classes.
- Seeding is idempotent and re-runnable — see the real seeder rewritten in a later change (verbatim):

```php
public function run(): void
{
    $typeOptions = [
        Option::TYPE_FLOOR => ['Outdoor patio' => false, 'VIP/Private' => false, 'Bar area' => false],
        Option::TYPE_TABLE => [
            'Mergeability with Other Tables' => true,
            'High Chair Accessibility' => false,
            'Robot Compatible Seating Areas' => false,
            'Booking Restrictions' => true,
            'Specific Zones' => true,
        ],
    ];

    foreach ($typeOptions as $type => $options) {
        foreach ($options as $name => $hasDescription) {
            Option::query()->updateOrCreate(
                [Option::NAME => $name],
                [Option::TYPE => $type, Option::HAS_DESCRIPTION => $hasDescription]
            );
        }
    }
}
```

Note the change of shape: the older version bailed out early with `if (Option::query()->exists()) { return; }` and inserted plain rows; the newer one is **idempotent per row** (`updateOrCreate` on a natural key) so re-running reconciles data instead of skipping — and it got a test asserting a double run leaves 8 rows with the updated flags.

## 6. Tenancy pitfalls

- A queued job that touches tenant models must initialize the tenant in its **constructor** (see `services-jobs-cache.md`) or its handle will fail/leak.
- Commands/jobs that only touch central data must NOT initialize a tenant; check `$this->runAt` style flags when a class can run in both worlds (`LiveCommandAbstract` keeps separate central and tenant run-records for exactly this reason).
- Caching must be tenant-aware: prefer keys built from the tenant/location id (e.g. `CacheConstants::COMPANY.$slug`, `CacheConstants::CONFIG.$companyId`) or filesystem paths per location, because the cache bootstrapper only covers the Laravel cache store.
- Never validate a foreign id with `Rule::exists(Model::class, Model::ID)` alone for tenant data; combine with the repository/`index($user)` scoping or a `HasAccessToLocationsRule`-style rule (see `contracts.md`).
