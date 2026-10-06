# Actions, services, jobs, cache and transaction boundaries

Where does a piece of logic belong? In this codebase the answer is defined by **what the logic needs**, not by preference. Use this guide to place a change in the right directory on the first try.

## 1. The decision table

| The logic is… | Put it in | Base class / shape |
|---|---|---|
| One named operation with a clear input → output | `app/Actions/<Domain>/<Verb><Thing>Action.php` | `handle()` (+ optional `AsAction`) |
| A synchronous helper invoked from code/helper functions | same | plain class, `run(new MyAction(...))` |
| A long-lived capability (integration client, cache store, builder) | `app/Services/<Domain>/<Name>Service.php` | stateful service, constructor-injected deps |
| A reusable class facade over an external API | `app/Services/<X>/ApiAbstract.php` subclass | token/cache + `post()/get()` |
| Work that must run outside the request | `app/Jobs/<Domain>/<Name>Job.php` | `ShouldQueue` + `Queueable` + `SerializesModels` |
| A schema-less "do this once in production" script | `app/Actions/Company/Live/<Name>.php` | extends `LiveCommandAbstract` |
| Data access for a model | `app/Repositories/<Domain>/<Name>Repository.php` | extends `BaseRepository` |
| Side effects on model lifecycle | `app/Observers/...` | `#[ObservedBy]` on the model |
| Cross-aggregate reaction | `app/Events` + `app/Listeners` | auto-discovery (`shouldDiscoverEvents() === true`) |

## 2. Actions

The minimal action (verbatim):

```php
namespace App\Actions\Company\Menu;

use App\Models\Company\Menu\Menu;
use App\Models\Company\Menu\MenuGroup;
use App\Models\Company\Menu\MenuSection;
use Lorisleiva\Actions\Concerns\AsAction;

class CreateDefaultMenuGroupAction
{
    use AsAction;

    public function __construct(private Menu $menu) {}

    public function handle()
    {
        /** @var MenuGroup $group */
        $group = $this->menu->groups()->create([
            MenuGroup::NAME => 'Default',
            MenuGroup::DISPLAY_ORDER => 1,
            MenuGroup::IS_ACTIVE => true,
        ]);

        $group->sections()->create([
            MenuSection::NAME => 'Default',
            MenuGroup::DISPLAY_ORDER => 1,
            MenuGroup::IS_ACTIVE => true,
        ]);
    }
}
```

Call it through the global helper — this is the house style:

```php
function run(object $action)
{
    return $action->handle();
}

// call site
run(new RefreshConfigsAction);
run(new StoreVisitAction($location, $route));
```

**`run()` is synchronous.** `lorisleiva/laravel-actions` is installed and `AsAction` is used on ~42 classes, but in this codebase `run($action)` always executes inline; queue dispatch happens only through explicit Jobs. Do not describe an Action as "queued" — check the call site. `run(new Foo)` (no parentheses) is the preferred instantiation style for no-arg classes.

An action with dependencies and a return value (verbatim excerpt):

```php
class CreateOrderAction
{
    public function __construct(
        private array $orderAttributes,
        private CartValidationService $service,
        private ?Customer $customer = null
    ) {}

    public function handle(): Order|Model
    {
        [$location, $coupon] = $this->extractOrderDetails($this->orderAttributes);
        $this->unsetOrderAttributes($this->orderAttributes, ['location_uuid', 'items']);

        $summary = $this->service->getSummarizedOrderableItems();
        $attributes = $this->prepareOrderAttributes($summary, $this->orderAttributes, $location, $coupon, $this->customer);
        // …
    }
}
```

Long `handle()` methods are split with **private helpers and intent comments** (`// Extract order details`, `// Remove unnecessary order attributes`, `// Prepare order attributes`) rather than extra abstraction layers.

### Live commands — one-shot production operations as actions

```php
class RetireConfigs extends LiveCommandAbstract
{
    public bool $runOnce = false;

    public function __construct(
        private readonly array $retiredConfigCodes = ['item_cache_enabled'],
    ) {}

    public function handle(): void
    {
        $deleted = Config::query()
            ->whereIn(Config::CODE, $this->retiredConfigCodes)
            ->delete();

        if ($deleted > 0) {
            run(new RefreshConfigsAction);
        }
    }
}
```

`LiveCommandAbstract` (verbatim excerpt) provides the run-once bookkeeping, with **separate records for central vs tenant scope**:

```php
abstract class LiveCommandAbstract
{
    public bool $runOnce = true;
    public string $runAt = 'tenant';      // or 'app'
    public ?string $environment = null;

    public function alreadyRan(): bool
    {
        if ($this->runAt == 'tenant') {
            return TenantLiveCommandRun::query()
                ->where(TenantLiveCommandRun::SERVICE_CLASS, $this::class)
                ->exists();
        }

        return LiveCommandRun::query()
            ->where(LiveCommandRun::SERVICE_CLASS, $this::class)
            ->exists();
    }

    public function shouldRunIn(string $environment): bool
    {
        if (! $this->environment) { return true; }

        return $this->environment == $environment;
    }
}
```

Pattern for a data-migration style change: subclass, declare `$runOnce`/`$runAt`/`$environment`, do the work in `handle()`, mark it ran — and add a unit test that runs the action twice and asserts idempotence (there are real examples: `BackfillOrderItemFreeQuantityTest`, `CompleteStalePreparingOrdersTest`, `SeedBasePaymentMethodsTest`).

## 3. Services

Two distinct flavours exist; know which you are writing.

**(a) Domain capability object** — injectable, constructor takes collaborators, exposes a small API, may hold per-instance state:

```php
// app/Services/Company/Menu/MenuCacheService.php (excerpt)
class MenuCacheService
{
    public function get(Location $location, string $serviceType, MenuScope $scope, bool $onlyVisible = true): array
    {
        $response = $this->readOrGenerate($location, $serviceType, $scope);

        return $onlyVisible ? app(ItemCacheService::class)->onlyVisibleAnswers($response) : $response;
    }

    private function readOrGenerate(Location $location, string $serviceType, MenuScope $scope): array
    {
        if (! tenant_config(ConfigConstants::MENU_CACHE_ENABLED, false)) {
            return $this->generate($location, $serviceType, $scope, app()->getLocale());
        }

        try {
            return app(MenuCacheStore::class)->read($location->getId(), 'menus', $serviceType, $scope->value);
        } catch (HttpException $exception) {
            if ($exception->getStatusCode() !== 503) {
                throw $exception;
            }

            app(MenuCacheFallbackNotifier::class)->notify(/* … */);

            return $this->generate($location, $serviceType, $scope, app()->getLocale());
        }
    }
}
```

Read this as the house idempotent-cache recipe: **feature flag → try cache → on "not ready" (503) throw away and fall back to a fresh build → notify**. Only 503 triggers the fallback; every other HTTP exception is rethrown. `app(SomeService::class)` is used freely inside services instead of injecting everything.

**(b) External API client** — subclass `ApiAbstract` (verbatim excerpt), which caches the token and normalizes errors:

```php
abstract class ApiAbstract
{
    protected string $url;
    protected string $token;
    protected string $key;
    protected int $tokenExpiresIn = 30;

    abstract protected function getHeaders(): array;
    abstract protected function login(): string;
    abstract protected function checkResponse(array $data, ?array $parameters = []): array;

    public function getToken(): string
    {
        if (empty($this->token)) {
            if (Cache::has($this->key)) {
                $this->token = Cache::get($this->key);
            } else {
                $response = $this->login();
                Cache::put($this->key, $response, now()->addMinutes($this->tokenExpiresIn));
                $this->token = $response;
            }
        }

        return $this->token;
    }

    protected function post(string $path, ?array $data = [], ?array $headers = null): array
    {
        if (empty($headers)) { $headers = $this->getHeaders(); }
        try {
            $response = Http::withHeaders($headers)->post($this->url . $path, $data);
        } catch (\Exception $exception) {
            throw new \Exception(__('errors.curl_exception_message'));
        }
        // …
    }
}
```

## 4. Jobs — three rules that are always followed

```php
class SendSmsJob implements ShouldQueue
{
    use Queueable, SerializesModels;

    private array $stoppedPhones = [];

    public function __construct(
        public Company $company,
        public string|array $to,
        public string $text,
        public bool $isComplianceMessage = false,
    ) {
        tenancy()->initialize($this->company);
    }
```

```php
class AutoAssignCourierJob implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        private Company $company,
        private Order $order,
        private array $configs)
    {
        init($this->company->getId());
    }
```

```php
class NotifyOwnerForNewGiftCardJob implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public Company $company,
        // …
        public GiftCardActionsEnum $action,
        public string $amount,
    ) {
        tenancy()->initialize($this->company);
    }
```

1. **`SerializesModels` is mandatory** for jobs whose constructor takes Eloquent models. It stores a model identifier (`ModelIdentifier`) instead of the whole payload, so the serialized job stays small and stale attributes cannot travel through the queue. A later change added the trait to 12 job classes plus `ScheduledJobAbstract` and shipped a regression test:

```php
class ModelSerializationTest extends TestCase
{
    public function test_job_serializes_a_model_identifier_instead_of_the_model_payload(): void
    {
        $company = new Company;
        $company->setAttribute(Company::ID, 'tenant-id');
        $company->setAttribute('payload_marker', 'must-not-be-serialized');

        /** @var SendSmsJob $job */
        $job = (new ReflectionClass(SendSmsJob::class))->newInstanceWithoutConstructor();
        $job->company = $company;
        $job->to = ['+155****4567'];
        $job->text = 'Test';
        $job->isComplianceMessage = false;

        $serialized = serialize($job);

        $this->assertStringContainsString('ModelIdentifier', $serialized);
        $this->assertStringContainsString('tenant-id', $serialized);
        $this->assertStringNotContainsString('must-not-be-serialized', $serialized);
    }
}
```

2. **The tenant is initialized in the constructor** — either `tenancy()->initialize($this->company)` directly or via the `init($id)` helper. This is deliberate: `handle()` runs on a worker without any HTTP context, and constructor initialization also guards against mixing two tenants in one job. Never move that call into `handle()` "for cleanliness" without checking every `handle()` path.

3. **`handle()` returns early, not deeply nested** — guard clauses with a blank line before `return` (a Pint rule in this repo), and each failed precondition emits an event before returning:

```php
public function handle(): void
{
    run(new EstimateDeliveryAction($this->order));
    if (is_null($this->order->getEstimatedDeliveryDuration())) {
        event(new OrderDeliveryFailed($this->order, null, __('delivery.errors.failed_to_estimate_delivery_duration')));

        return;
    }

    $repo = new DeliveryRepository;
    // …
}
```

Scheduling lives in `app/Console/Kernel.php` and dispatches **jobs**, not closures:

```php
$schedule->job(new DispatchScheduledJobs)->everyMinute()->withoutOverlapping()->onOneServer();
$schedule->job(new DeleteOldConnectionsJob)->everyTwoMinutes();
```

`withoutOverlapping()` + `onOneServer()` is the default posture for recurring work. Custom `schedule(...)` helpers exist for per-tenant scheduled rows (`schedule($jobClass, Carbon $scheduledFor, Company $company, array $payload)`).

## 5. Observers and events

Lifecycle side effects sit in observers registered by attribute on the model:

```php
#[ObservedBy(CompanyObserver::class)]
class Company extends BaseTenant implements CompanyInterface { /* … */ }
```

```php
class OrderObserver
{
    public function creating(Order $order): void
    {
        if (! $order->getOrderStatusId()) {
            $this->updateOrderStatus($order);
        }

        if ($order->getFirstName()) {
            $order->setFirstName(Str::headline($order->getFirstName()));
        }
        // same for lastName / name
    }
}
```

Event/listener wiring is auto-discovered (`EventServiceProvider::shouldDiscoverEvents()` returns `true`, `$listen` is empty) — so a reaction is added by creating a listener class with the right event type-hint, and cross-aggregate notifications (`OrderCreated`, `OrderStatusChanged`, `OrderDeliveryFailed`) are emitted with `event(new X(...))` and consumed by listeners/jobs. When a business rule must run for **every** write regardless of caller, it belongs in an observer; when it is one operation's outcome, keep it in the action.

## 6. Transactions

Only a handful of places need explicit transactions, and both styles are used:

```php
// style 1 — manual, when an action must be wrapped and a custom abort is wanted
public function create(array $orderAttributes, CartValidationService $service, ?Customer $customer = null): Order|Model
{
    DB::beginTransaction();
    try {
        $order = run(new CreateOrderAction($orderAttributes, $service, @$customer));
    } catch (\Exception $exception) {
        DB::rollBack();
        abort(Response::HTTP_INTERNAL_SERVER_ERROR, $exception->getMessage());
    }
    DB::commit();

    return $order;
}

// style 2 — closure, when the work is a sequence of model operations
public function submit(Order $order, array $changes = []): Order
{
    return DB::transaction(function () use ($order, $changes): Order {
        if (! $order->getCustomerId()) {
            $customer = $this->resolveGuestCustomer($order);

            if ($customer) {
                $changes[Order::CUSTOMER_ID] = $customer->getId();
            }
        }

        /** @var Order $order */
        $order = $order->submit($changes);

        return $order;
    });
}
```

Guidelines inferred from usage: transactions live in **repositories** (or in the controller when two aggregates must commit together — only 6 files in the app use `DB::transaction`), never in a job's `handle()` body around queue dispatching, and never wrapping a `dispatch()` of a job that must survive a rollback. Ordering: validate → mutate → persist → dispatch after commit (jobs that read the committed row must be dispatched after the transaction).

## 7. Cache

- Feature-flagged, generator-based caches for heavy read models: `MenuCacheStore` writes versioned snapshots to disk with constants for retention and staleness:

```php
class MenuCacheStore
{
    private const GENERATION_RETENTION_SECONDS = 86400;
    private const MIN_GENERATIONS_TO_KEEP = 2;
    private const DISTRIBUTED_BUILD_STALE_SECONDS = 3600;

    private static array $ownedLocks = [];

    public function ownsLock(int $locationId): bool
    {
        return isset(self::$ownedLocks[$this->root($locationId)]);
    }

    public function publicStatus(int $locationId): array
    {
        $state = $this->status($locationId);
        if ($state['status'] === 'building') {
            if (($state['distributed'] ?? false) && $this->hasRecentHeartbeat($state)) {
                return $this->toPublicStatus($state);
            }
            // A live builder holds the lock. An unlocked building state means its worker exited.
            $this->withLock($locationId, function () use ($locationId) {
                // …mark failed…
            });
            $state = $this->status($locationId);
        }
        // …
    }
}
```

  Note the shape: statuses (`building`, `failed`, `ready`), a heartbeat/staleness check, generation retention, and a lock owner registry. If you extend this, keep it **read-only-fast**: the reader path returns the published snapshot or signals "not ready" (503) — it never builds inline.
- Laravel cache reads in production are wrapped in `Cache::remember(key, ttl, fn () => …)` and skipped in non-production (`if (!app()->isProduction()) { return Config::query()->get(); }`) so local debugging never serves stale config.
- Cache keys come from a `CacheConstants` class and include the tenant/company id.

## 8. Review posture for these paths

- A queued job without `SerializesModels` and without tenant initialization in the constructor is a **correctness** bug, not a style nit.
- A cache read path that builds on miss inside a request is a latency bug; the established pattern is snapshot-or-503 with an explicit fallback.
- When proposing an N+1 fix on a cached/flagged path, verify the flag actually disables the cache before claiming the cost — the team accepts cheap N+1s on cached or admin-only paths and rejects bulk re-architecting for them. Correctness (stale pivot data, wrong tenant scope) is always worth flagging.
- Transaction + queue: dispatching inside a `DB::transaction` closure that can roll back is a bug; move the dispatch after the commit.
