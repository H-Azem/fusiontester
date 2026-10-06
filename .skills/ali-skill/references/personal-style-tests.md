# Author-attributed samples, formatting, and test writing

This file separates **patterns visible in shared source** (everyone follows them) from **patterns directly demonstrated in specific changes attributed to Ali Mousavi** (the strongest available evidence of personal style). Both matter; do not blur them.

## 1. Attribution summary (how to read this file)

- The studied backend repository is multi-author; a bounded scan matched ~552 commit records by the author's name/email (including merges and 78 records with a typo-variant email that are weaker evidence). Repository-wide patterns below are therefore labelled as *convention*, not sole authorship.
- Six changes were inspected line-by-line. They are the source of every "his sample" claim in this package. Where a pattern appears only in those changes, it is labelled **sample**.
- Never set commit authorship to this person or claim his review/approval. Match the *code*, not the identity.

## 2. Commit hygiene (observed)

- Subjects are short, lowercase, imperative, prefixed when it is a real feature/fix: `fix: SerializesModels for eloquent related jobs`, `feat: description for options`, `feat: dashboard period data`, `fix: enable cancellation in draft and saved order`, `chore: ws security`, `chore: random order improvment`.
- Some subjects have no prefix at all (`add quickbooks customer integration`, `address for policy`, `KH cached menu builder`) — prefix presence is not a hard rule; *lowercase and short* is.
- Typos occur in subjects ("geenration", "improvment"). This is a fact about history, **not** something to imitate: write correct spelling.
- Each change is one behaviour. The jobs change touched 12 job classes + 1 abstract + 1 test to add a single trait — one intent, many files. The policy change was 2 deleted lines and nothing else.
- Docs ride along when a payload changes: the option-description change added an API markdown document in the same commit.

## 3. Diff shape — minimal, in-place, formatting-clean

**Sample: policy fix (complete diff, nothing else changed in the file):**

```diff
 public function cancel(User $user, Order $order)
     if (in_array($order->getStatus(), [
-        Order::STATUS_DRAFT,
-        Order::STATUS_SAVED,
         Order::STATUS_COMPLETED,
         Order::STATUS_CANCELED,
     ])) {
```

**Sample: the same change also normalized formatting in the files it touched** (Pint Laravel preset + editorconfig). Diff-visible consequences:

```diff
-    public function __construct(private TableRepository $repository)
-    {
-    }
+    public function __construct(private TableRepository $repository) {}
```

```diff
-        $this->authorize('create', new Table());
+        $this->authorize('create', new Table);
```

```diff
-            $newTable->setName("Copy of " . $table->getName())->save();
+            $newTable->setName('Copy of '.$table->getName())->save();
```

```diff
-        if (!$bestQuote) {
+        if (! $bestQuote) {
```

```diff
-            $digits = '1' . $digits;
+            $digits = '1'.$digits;
```

```diff
-        private Company  $company,
-        private Order   $order,
-        private array   $configs)
+        private Company $company,
+        private Order $order,
+        private array $configs)
```

```diff
-    }
-    {
+    ) {
         tenancy()->initialize($this->company);
     }
```

```diff
-        $token = (string) tenant_config(\App\Constants\ConfigConstants::TELEGRAM_BOT_TOKEN, '');
+        $token = (string) tenant_config(ConfigConstants::TELEGRAM_BOT_TOKEN, '');
```

Import order is alphabetical by full namespace and unused imports are removed in the same change:

```diff
-use App\Models\Order\GiftCard;
-use App\Models\Company\Order\GiftCardOrder;
-use App\Models\Order\GiftCardTransaction;
 use App\Models\Core\Company;
+use App\Models\Order\GiftCard;
+use App\Models\Order\GiftCardTransaction;
```

```diff
-use App\Constants\ConfigConstants;     // removed in the same commit that added the same import elsewhere
```

**Rule to copy:** formatting-only churn is limited to lines/files you already touch. Do not run a repo-wide formatter in a behavioural change. `pint.json` = `{"preset": "laravel", "rules": {"class_definition": {"multi_line_extends_each_single_line": true}}}`; `.editorconfig` = 4 spaces, LF, final newline, 2 spaces for YAML.

Also fixed in these diffs: duplicate blank lines, missing blank line before `return` inside `if` blocks, trailing blank line inside a class, single quotes over double quotes when nothing is interpolated (`return "";` → `return '';`), and `// todo from lang` spacing (`//todo from lang` → `// todo from lang`).

## 4. Refactor style — rename, extract, keep the contract

**Sample: dashboard period data.** Three similar metrics methods were collapsed into one bucketed aggregate while the HTTP response stayed byte-compatible:

```php
// before: three separate methods, three separate query shapes
'total_visits' => $this->getTotalVisits($dateRanges, $location),
'total_orders' => $this->getTotalOrders($dateRanges, $location),
'revenue' => $this->getTotalRevenue($dateRanges, $location),

// after: one call, same keys, same response shape
[$visits, $orders, $revenue] = $this->getMetrics($dateRanges, $location);

$dashboardResponse = [
    'date_ranges' => $request->getResponseRanges($dateRanges),
    'total_visits' => $visits,
    'total_orders' => $orders,
    'last_orders' => $this->getLastOrders($location),
    'revenue' => $revenue,
    'by_source' => $this->getAggregatedOrders($dateRanges, $location, Order::SOURCE, AppConstants::$sources),
    'by_type' => $this->getAggregatedOrders($dateRanges, $location, Order::TYPE, AppConstants::$serviceTypes),
    'by_hour' => $this->getAggregatedOrdersByHour($dateRanges, $location),
    // …
];
```

Inside the extracted helper the queries are cloned rather than rebuilt per period:

```php
private function getMetrics(array $dateRanges, Location $location): array
{
    $visitsQuery = Visit::query()->where(Visit::LOCATION_ID, $location->getId());
    $ordersQuery = $location->orders()->where(Order::STATUS, Order::STATUS_COMPLETED);
    $visits = $orders = $revenue = ['periods' => []];

    foreach ($dateRanges as $name => $range) {
        $queryRange = array_map(fn ($date) => $date->format(DateConstants::FULL_DATETIME), $range);
        $visitMetrics = $this->addPeriodAggregates(
            $visitsQuery->clone()->whereBetween(Visit::HOUR, $queryRange),
            $range,
            // …
        );
        // …
    }
}
```

What to copy: extract a private helper with **explicit typed parameters** (`Builder|Relation $query, array $range, string $dateColumn, string $sumColumn, bool $withCount = false`), `->clone()` the base query instead of re-building it, iterate the ranges once, and keep the public response keys untouched. Add a constant for date formats (`DateConstants::FULL_DATETIME`) instead of inline `'Y-m-d H:i:s'`.

**Sample: cached reader extracted behind a fallback.** A new service class was introduced to read a snapshot, and the existing builder was changed to *prefer* it — with the old path kept as the fallback and a test that asserts the DB is not queried when the snapshot exists:

```php
private const DAYS = ['MONDAY', 'TUESDAY', …];

public function __construct(
    private DefaultTaxResolver $defaultTaxResolver,
    private KitchenHubMenuCacheReader $menuCacheReader,
) {}

public function build(Location $location, string $serviceType = 'delivery'): array
{
    $menus = $this->menuCacheReader->get($location, $serviceType) ?? $location->getActiveMenus($serviceType, true, [
        'groups.sections.items.sizes.questionGroups.questions.itemSizes',
    ]);
    // …
}
```

```php
// the reader returns null (never throws) for "not ready", so callers use null coalescing
public function get(Location $location, string $serviceType = 'delivery'): ?Collection
{
    try {
        $payload = $this->store->read($location->getId(), 'menus', $serviceType, MenuScope::CustomerComplete->value);
    } catch (HttpException $exception) {
        if ($exception->getStatusCode() !== 503) {
            throw $exception;
        }

        return null;
    }
    // …
}
```

Note the API shape choices: a **defaulted parameter** was added (`string $serviceType = 'delivery'`) instead of a new overload, and the reader returns `?Collection` rather than a result object. Constructor property promotion order groups collaborators. `Mockery` is used with explicit expectations in the test (`$store->shouldReceive('read')->once()->with(1, 'menus', 'delivery', 'customer-complete')`), and `shouldNotReceive('getActiveMenus')` proves the DB path was skipped — copy that technique when you claim a cache hit avoids queries.

**Sample: new pivot field end-to-end.** Adding option descriptions touched the model interface, the model, a request trait, a rule class, a trait for the relation, the resource, the seeder, the controller, an API doc and two tests. There was **no** new controller method and no new route — existing `store`/`update`/`duplicate` were adapted:

```diff
-        if ($request->filled('options')) {
-            $table->options()->sync($request->array('options'));
+        if ($request->has('options')) {
+            $table->options()->sync($request->optionSyncData());
         }
```

```diff
-            $this->repository->index($request->user())->with('tableType')
+            $this->repository->index($request->user())->with(['tableType', 'options'])
```

The eager-load list was widened in the same commit as the resource field that needs it — eager loads and resource relations travel together.

## 5. Interfaces, constants and traits in his own changes

The constants-first habit is visible in every touched interface:

```php
interface OptionInterface extends
    BaseModelInterface,
    HasDisplayOrderInterface,
    HasNameInterface,
    HasTypeInterface
{
    const TABLE = 'options';

    const TYPE_FLOOR = 'floor';

    const TYPE_TABLE = 'table';

    const HAS_DESCRIPTION = 'has_description';
}

interface OptionableInterface extends
    BaseModelInterface,
    HasOptionIdInterface
{
    const TABLE = 'optionables';

    const OPTIONABLE = 'optionable';

    const OPTIONABLE_TYPE = 'optionable_type';

    const OPTIONABLE_ID = 'optionable_id';

    const DESCRIPTION = 'description';
}
```

Behaviour that accompanies a constant goes into a matching trait, and a documented "IDE Helpers" docblock is regenerated for it:

```php
trait HasOptionsTrait
{
    #[RecognizedBy(Option::class)]
    public function options(): MorphToMany
    {
        return $this->morphToMany(Option::class, Optionable::OPTIONABLE)
            ->withPivot(Optionable::DESCRIPTION);
    }
}
```

```php
protected $casts = [
    self::HAS_DESCRIPTION => 'boolean',
];

public static array $types = [
    self::TYPE_FLOOR,
    self::TYPE_TABLE,
];
```

Rule: when you add a column, add the constant to the interface (plus a trait if behaviour is needed), declare the cast on the model, and let resources/requests reference the constant. `$casts` sits before the static arrays in the class body; trait `use` lines are alphabetized.

## 6. Test writing (samples + conventions)

Both frameworks appear in the org: the main backend uses **PHPUnit 12** (`tests/Unit`, `tests/Feature`, `phpunit.xml` with two suites and `APP_ENV=testing`, `CACHE_DRIVER=array`, `QUEUE_CONNECTION=sync`); the second backend uses **Pest 5** on top of PHPUnit (`tests/Pest.php` extends `Tests\TestCase`, `uses(LazilyRefreshDatabase::class)` per file, `it('…', function () { … })`, `actingAs(...)`, `getJson(route(...))->assertSuccessful()->assertJsonCount(5, 'response')`). Choose the framework the target repository already uses.

**His sample: unit test for a private helper via reflection + in-memory sqlite.**

```php
class DashboardControllerTest extends TestCase
{
    #[Test]
    public function dashboard_periods_cover_the_whole_range_without_overlap(): void
    {
        $method = new ReflectionMethod(DashboardController::class, 'getPeriodBounds');
        $start = CarbonImmutable::parse('2026-09-01 00:00:00', 'UTC');
        $end = $start->addMicroseconds(10);
        $previousEnd = $start;

        for ($index = 0; $index < 7; $index++) {
            [$bucketStart, $bucketEnd] = $method->invoke(new DashboardController, [$start, $end], $index);

            $this->assertTrue($bucketStart->equalTo($previousEnd));
            $this->assertTrue($bucketEnd->greaterThan($bucketStart));
            $previousEnd = $bucketEnd;
        }

        $this->assertTrue($previousEnd->equalTo($end));
    }

    #[Test]
    public function period_aggregates_return_all_buckets_in_one_query(): void
    {
        config()->set('database.connections.dashboard_test', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
        $connection = DB::connection('dashboard_test');
        $connection->getSchemaBuilder()->create('dashboard_metrics', function (Blueprint $table) {
            $table->dateTime('occurred_at');
            $table->integer('amount');
        });
        // …insert 8 rows across the boundary, then invoke addPeriodAggregates via ReflectionMethod…
    }
}
```

**His sample: trait/rule tested without HTTP**, by instantiating an anonymous class that uses the trait:

```php
class OptionDescriptionValidationTest extends TestCase
{
    public function test_table_option_description_is_required_only_when_configured(): void
    {
        config(['database.connections.tenant' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
        DB::purge('tenant');
        Schema::connection('tenant')->create(Option::TABLE, function (Blueprint $table) {
            $table->id();
            $table->string(Option::TYPE);
            $table->boolean(Option::HAS_DESCRIPTION);
            $table->softDeletes();
        });
        DB::connection('tenant')->table(Option::TABLE)->insert([
            ['id' => 1, 'type' => Option::TYPE_TABLE, 'has_description' => true],
            ['id' => 2, 'type' => Option::TYPE_TABLE, 'has_description' => false],
        ]);

        $rules = (new class
        {
            use ValidatesOptions;

            public function rules(): array
            {
                return $this->optionRules(Option::TYPE_TABLE);
            }
        })->rules();

        $validator = Validator::make(['options' => [['id' => 1], ['id' => 2]]], $rules);
        $this->assertFalse($validator->passes());
        $this->assertTrue($validator->errors()->has('options.0'));
        $this->assertFalse($validator->errors()->has('options.1'));
        // …two more cases: with descriptions, and a bare id which must fail…
    }
}
```

**His sample: seeder idempotence** — run twice, assert the row count and the reconciled values:

```php
$seeder = new OptionSeeder;
$seeder->run();
$seeder->run();

$this->assertSame(8, Option::query()->count());
$this->assertTrue(Option::query()->where(Option::NAME, 'Booking Restrictions')->firstOrFail()->getAttribute(Option::HAS_DESCRIPTION));
```

**His sample: absence-of-query assertions with Mockery** (from the cached-menu change):

```php
$store = Mockery::mock(MenuCacheStore::class);
$store->shouldReceive('read')->once()->with(1, 'menus', 'delivery', 'customer-complete')->andReturn([
    'response' => [ /* … */ ],
]);
$location = Mockery::mock(Location::class)->makePartial()->setId(1)->setUuid('location-uuid');
$location->shouldNotReceive('getActiveMenus');
```

Testing conventions to copy:
- A bug fix ships a regression test in the same change; a new rule/trait ships a unit test that does not need HTTP; a payload change ships an API doc.
- Prefer a **real in-memory database** (`sqlite :memory:` with an explicit schema built in the test) over mocking the query layer, when the thing under test is SQL behaviour (buckets, ordering, uniqueness).
- Reflection is acceptable and used deliberately to test private helpers instead of making them public for tests.
- Test method names read as behaviour: `test_table_option_description_is_required_only_when_configured`, `test_job_serializes_a_model_identifier_instead_of_the_model_payload`, `dashboard_periods_cover_the_whole_range_without_overlap`.
- Assert precise counts/paths (`assertJsonCount(1, 'response')`, `assertJsonPath('response.0.id', $id)`) rather than asserting non-empty.
- Test scaffolding (schema, factories, mocks) is built per test, with no shared mutable fixtures beyond factories.

## 7. What NOT to imitate

- Email/identity variations and commit-message typos.
- Personal or customer data appearing in tests: the serialization test intentionally uses a masked phone (`+155****4567`) — keep that masking when you write similar tests.
- The "copy of a whole file then reformat" step: make the formatting normalizations only inside the lines your change already touches.
- Single-query-per-metric dashboards: if you are adding metrics, use the bucketed helper pattern instead of adding a fourth similar method.
