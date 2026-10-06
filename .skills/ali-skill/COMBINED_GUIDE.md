# Ali Mousavi Backend Style — Combined Guide

## references/contracts.md

# Payload contracts, validation boundaries, serialization shapes

Companion to `http.md`. Everything here is about **what crosses the boundary**: request payload shapes, validation ownership, pivot payloads, and the response contract consumers depend on.

## 1. Ownership map

| Concern | Owner | Rule |
|---|---|---|
| Field presence/types | `<Domain><Action>Request::rules()` | Constants for keys, `Rule::` for cross-table integrity |
| Reusable rule blocks | `app/Traits/Request/*` | Spread into `rules()`; never copy-paste a rule array twice |
| Single-purpose validation logic | `app/Rules/<Domain>/*Rule.php` | One class, `validate(string $attribute, mixed $value, Closure $fail)` |
| Object-level permission | Policy + `$this->authorize()` in controller | Request `authorize()` stays `true` |
| Cross-tenant/location access | `HasAccessToLocationsRule`, repository `index($user)` | Validate **and** scope; one is not the other |
| Response field visibility | `BaseResource::get<Audience>Array()` | Never widen `getCommonArray()` for one role |
| Payload transformation into persistence shape | Request helper (`optionSyncData()`) | Controller stays a one-liner |

## 2. Reusable rule blocks: the request-trait pattern

A real example introduced with the option-description feature (verbatim):

```php
namespace App\Traits\Request;

use App\Models\Company\General\Option;
use App\Models\Company\General\Optionable;
use App\Rules\General\OptionDescriptionRule;
use Illuminate\Validation\Rule;

trait ValidatesOptions
{
    protected function optionRules(string $type): array
    {
        return [
            'options' => ['sometimes', 'array'],
            'options.*' => ['required', 'array:'.Option::ID.','.Optionable::DESCRIPTION, new OptionDescriptionRule],
            'options.*.'.Option::ID => ['required', 'distinct', Rule::exists(Option::class, Option::ID)
                ->withoutTrashed()->where(Option::TYPE, $type)],
            'options.*.'.Optionable::DESCRIPTION => ['nullable', 'string'],
        ];
    }

    public function optionSyncData(): array
    {
        $data = [];
        foreach ($this->validated('options', []) as $option) {
            $data[$option[Option::ID]] = [Optionable::DESCRIPTION => $option[Optionable::DESCRIPTION] ?? null];
        }

        return $data;
    }
}
```

Used by a request as `use ValidatesOptions;` and `...$this->optionRules(Option::TYPE_TABLE),` inside `rules()`. Three things to copy:
1. The rule block is parameterized by type (`Option::TYPE_TABLE`) so floors and tables reuse it.
2. `'array:'.Option::ID.','.Optionable::DESCRIPTION` **whitelists the allowed keys** of each item — extra keys are rejected, not silently ignored.
3. `optionSyncData()` converts the validated list into the exact `sync()` payload (`[id => [pivot fields]])`, so the controller does no array surgery.

## 3. Custom rule classes: validate against database state

```php
class OptionDescriptionRule implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_array($value)) {
            return;
        }

        if (! isset($value[Option::ID])) {
            return;
        }

        if (Option::query()->whereKey($value[Option::ID])
            ->where(Option::HAS_DESCRIPTION, true)->exists()
            && ! filled($value[Optionable::DESCRIPTION] ?? null)) {
            $fail('The :attribute description field is required.');
        }
    }
}
```

Pattern: **early-return** on shapes this rule does not own (`if (! is_array($value)) return;`) so several rules can co-exist on the same attribute without fighting; only the specific failure calls `$fail()`. Dependency-free rules like this are unit-testable without HTTP.

## 4. Validation is not authorization

Real pairing in this codebase:

```php
// request: the location must exist AND belong to this tenant
AddressIndexRequest/rules():
'sometimes',
'integer',
Rule::exists(Location::class, Location::ID),
// plus, for staff users only:
new HasAccessToLocationsRule($this->user()),
```

```php
// repository: even after validation, lists are still scoped
public function getUserIndex(Builder $builder, User $user): Builder
{
    return $builder->where(Table::LOCATION_ID, $user->getLocationId());
}
```

Never rely on the rule alone: an array of ids can pass `exists` for the tenant and still contain another location's record set. The repository `index($user)` hook is where the row-level scope must land.

## 5. "Absent vs empty" contract

Because updates are `PATCH`-style with `sometimes` rules, the controller must distinguish:

```php
if ($request->has('options')) {      // present (even []) → replace the whole set
    $table->options()->sync($request->optionSyncData());
}
// absent → leave existing relations untouched
```

And for sync semantics: `sync([...])` replaces the attachments; `"options": []` clears them; omitting the key keeps them. When copying a parent (duplicate action), copy the **existing collection with its pivot data**, not a fresh id list:

```php
$table->options->mapWithKeys(fn (Option $option) => [
    $option->getId() => ['description' => $option->pivot->description],
])->all()
```

(`$table->options` collection, always eagerly loaded with `->with('options')` first — pivot data is only present on a loaded relation.)

## 6. Response contract stability

- Key names come from the base controller constants: `response`, `status`, `message`, `errors`, `model`, `filters`, `queryables`, `per_page`.
- A single resource wrapped by `JsonResource::wrap(Controller::RESPONSE)` produces `{response: {...}, status: 200}`; the status comes from `SuccessStatusTrait::with()`.
- Collections add status explicitly: `->additional([self::STATUS => 200])`.
- Validation/permission failures come from `sendErrorResponse($message, $status, $errors)` → `{message, errors, response?}`.
- Pagination payload comes from `->paginate($request->perPage())` inside `AddressResource::collection(...)`, i.e. Laravel's standard `data/meta` keys **inside** `response`.
- When refactoring a dashboard/report endpoint, keep the response keys (`total_visits`, `total_orders`, `revenue`, `by_source`, `by_hour`, …) even if you rename private methods — see `personal-style-tests.md`.

## 7. Documenting a payload change

The option-description change shipped `docs/option-descriptions-api.md` in the same commit: full request/response examples per endpoint, an explicit statement of what changed for consumers ("the `GET /backoffice/options` response now includes `has_description`"), and the replacement semantics ("the `options` array replaces all existing attachments; send `[]` to remove all"). When a payload shape changes, the doc file belongs in the same change as the code and the tests.


---

## references/http.md

# HTTP layer — routes → request → controller → resource

Fusion backend (Laravel 13, PHP 8.3, multi-tenant). Excerpts are **verbatim with declared omissions**; they are not runnable alone. Framework classes are explained inline; no remote repository is needed to use this guide.

## 1. Route files are split by context, never one big api.php

Real file set: `routes/tenant.php`, `routes/backoffice.php`, `routes/root_api.php`, `routes/api.php`, `routes/share.php`, `routes/web.php`, `routes/root_web.php`, `routes/short_url.php`, `routes/channels.php`, `routes/console.php`.

Tenant traffic is grouped by the URL prefix that carries company + locale, and the tenant is initialized by middleware:

```php
// routes/tenant.php (excerpt)
Route::group([
    'prefix' => 'api/{company}/{locale}/',
    'middleware' => [
        InitializeTenantMiddleware::class,
        'api',
    ],
], function () {
    Route::get('', [CompanyController::class, 'me']);
    Route::get('locations', [LocationController::class, 'index']);
    Route::get('menus', [MenuController::class, 'customerIndex']);

    Route::prefix('backoffice')->group(function () {
        include 'backoffice.php';
    });

    Route::prefix('share')
        ->middleware('signed')
        ->group(function () {
            include 'share.php';
        });
});

// WEB (same file, later)
Route::prefix('{company}')
    ->middleware([InitializeTenantMiddleware::class, 'web'])
    ->group(function () {
        Route::get('download/{file:uuid}/{filename}', [FileController::class, 'download'])
            ->name('web.files.download');
        Route::post('payments/link', [PaymentController::class, 'webStore'])
            ->name('web.payments.store')
            ->withoutMiddleware(VerifyCsrfToken::class);
    });
```

Reads: the backoffice file is **included inside the tenant prefix**, so every backoffice route is tenant-scoped automatically; CSRF is disabled per route for external callbacks; route parameters use UUID binding (`{delivery:uuid}`, `{file:uuid}`) instead of numeric ids.

Inside `routes/backoffice.php`, authentication is a group gate, and the public auth endpoints sit outside it:

```php
// routes/backoffice.php (excerpt, start and end of file)
Route::post('auth/login', [BackofficeAuthController::class, 'login']);
Route::post('auth/refresh-token', [BackofficeAuthController::class, 'refreshToken']);
Route::post('auth/forgot-password', [BackofficeAuthController::class, 'forgotPassword']);
Route::post('auth/reset-password', [BackofficeAuthController::class, 'resetPassword']);

Route::middleware('auth.user')->group(function () {
    Route::get('hello', [BackofficeAuthController::class, 'hello']);
    Route::get('dashboard', DashboardController::class);          // invokable controller
    Route::get('auth/me', [BackofficeAuthController::class, 'getMe']);
    // … resource blocks …
    Route::apiResource('floors', FloorController::class);
    Route::delete('floors', [FloorController::class, 'batchDestroy']);
    Route::apiResource('tables', TableController::class);
    Route::patch('tables/{table}/restore', [TableController::class, 'restore'])->withTrashed();
    Route::post('tables/{table}/duplicate', [TableController::class, 'duplicate']);
});
```

Central (platform-admin) endpoints use a different guard and `withoutMiddleware` for public ones:

```php
// routes/root_api.php (excerpt)
Route::withoutMiddleware([
    JwtTokenMiddleware::class,
    CheckShouldLoginMiddleware::class,
])->group(function () {
    Route::post('auth/login', [AuthController::class, 'login']);
    Route::post('third-parties/doordash', DoordashController::class);
});

Route::middleware('auth.platformAdmin')->group(function () {
    Route::post('contracts/{contract}/sign', [ContractController::class, 'sign'])
        ->name('contracts.sign');
    Route::apiResource('contracts', ContractController::class);
});
```

Named routes exist (`contracts.sign`, `web.files.download`, `driver.deliveries.proofs.store`) and are used by tests via `route(...)`.

Routing registration is explicit in `app/Providers/RouteServiceProvider.php`: `mapApiRoutes()` loads `root_api.php` under `Route::prefix('api')->middleware(['api','secure'])`, and for each **central domain** loads `api.php`/`web.php`; tenant routes come from `config/tenancy.php` (`'routes' => true`) plus `TenancyServiceProvider`. Rate limiting is a named `api` limiter: `Limit::none()` in non-production and when a CI header/query flag is present, otherwise `Limit::perMinute(120)->by($request->user()?->id ?? $request->ip())`.

Conventions to copy:
1. New tenant endpoints go into `backoffice.php` (authenticated) or the top of `tenant.php` (public); central ones into `root_api.php`/`api.php`. Never a new catch-all file.
2. Keep `Route::apiResource(...)` plus explicit extra routes around it (`batchDestroy`, `restore` with `->withTrashed()`, `duplicate`) instead of inventing custom verbs.
3. Guard names are semantic: `auth.user`, `auth.platformAdmin`; public sets are carved out with `withoutMiddleware([...])` rather than a second file.

## 2. Requests — validation, authorization, context extraction

Every project request extends `App\Http\Requests\BaseRequest` (real code, trimmed):

```php
/** @method User|Customer|AuthenticatableInterface user($guard = null) */
class BaseRequest extends FormRequest
{
    const USER_ID = HasUserIdInterface::USER_ID;

    const CUSTOMER_ID = HasCustomerIdInterface::CUSTOMER_ID;

    protected array $excluded = [];

    public function authorize()
    {
        return true;
    }

    public function safeValidated(): array
    {
        return Arr::except($this->validated(), $this->excluded);
    }

    public function forUser($attribute = self::USER_ID): User
    {
        if ($this->has($attribute) && $this->isAdmin()) {
            return User::find($this->get($attribute));
        }

        return $this->user();
    }

    public function forCustomer($attribute = self::CUSTOMER_ID): Customer
    {
        if ($this->has($attribute) && $this->isAdmin()) {
            return Customer::find($this->get($attribute));
        }

        return $this->user();
    }

    public function isAdmin(): bool { return $this->user()?->isAdmin(); }

    public function isUser(): bool
    {
        if (! $this->user()) { return false; }
        if ($this->user() instanceof Customer) { return false; }

        return $this->user()->isUser();
    }

    public function getCompany(): Company { return tenant(); }

    public function anotherRequestRules(string $keyPrefix, Request|DutyAbstract $request, array $except = []): array
    {
        $rules = [];
        foreach ($request->rules() as $key => $rule) {
            if (in_array($key, $except)) { continue; }
            $rules[$keyPrefix.$key] = $rule;
        }

        return $rules;
    }

    public function perPage(): int
    {
        if ($this->isUser()) {
            return min(
                $this->integer(Controller::PER_PAGE, Controller::DEFAULT_PAGE_SIZE),
                Controller::DEFAULT_PAGE_SIZE
            );
        }

        return Controller::DEFAULT_PAGE_SIZE;
    }
}
```

What this gives you, and why to keep it:
- `authorize()` returning `true` is **intentional**: object-level permission is checked in the controller with `$this->authorize(...)`. Do not treat `authorize() { return true; }` as "no security" without checking the controller.
- `perPage()` is a hard cap: external/non-user callers can never raise page size. Any new list request should call `$request->perPage()` rather than `$request->input('per_page')`.
- `anotherRequestRules($prefix, $otherRequest)` embeds another request's rules with a key prefix (`'filters.'`, `'queryables.'`) — the pattern behind nested filter payloads.
- `$excluded` + `safeValidated()` is the sanctioned way to drop fields that were validated only for authorization reasons.

A concrete project request (verbatim, trimmed) — rules keyed by model constants, custom Rule objects, and `passedValidation()` pushing context onto the request for the resource layer:

```php
class AddressStoreRequest extends BaseRequest
{
    protected function prepareForValidation()
    {
        if ($this->user() instanceof Customer) {
            $this->merge([
               Address::CUSTOMER_ID => $this->user()->getId()
            ]);
        }
    }

    public function rules()
    {
        return [
            Address::NAME => ['required', 'string'],
            Address::COUNTRY_ID => ['required', new CountryIdIsValidRule],
            Address::CUSTOMER_ID => ['required', Rule::exists(Customer::class, Customer::ID)],
            Address::ICON => ['required', 'string', Rule::in(AddressIconConstants::$addressIcons)],
            Address::TAG => ['sometimes', 'nullable', 'string'],
            Address::POSTAL_CODE => ['required', 'string', new PostalCodePatternIsValidRule],
            Address::LATITUDE => ['required', 'numeric'],
            Address::LONGITUDE => ['required', 'numeric'],
            Address::IS_DEFAULT => ['sometimes', 'boolean'],
        ];
    }

    public function getCustomer()
    {
        return Customer::query()->find($this->input(Address::CUSTOMER_ID));
    }

    protected function passedValidation()
    {
        if ($this->isUser() && $this->user()->getLocationId()) {
            $location = $this->user()->location;
            $this->attributes->set(AddressResource::DELIVERY_LOCATION_ATTRIBUTE, $location);
            request()->attributes->set(AddressResource::DELIVERY_LOCATION_ATTRIBUTE, $location);
        }
    }
}
```

`TableStoreRequest` shows the "exists + belongs-together" idiom used for relational integrity:

```php
Table::FLOOR_ID => [
    'required',
    Rule::exists(Floor::class, Floor::ID)
        ->withoutTrashed()
        ->where(Floor::LOCATION_ID, $this->input(Table::LOCATION_ID)),
],
Table::NAME => [
    'required',
    'string',
    Rule::unique(Table::class, Table::NAME)
        ->withoutTrashed()
        ->where(Table::FLOOR_ID, $this->input(Table::FLOOR_ID)),
],
Table::ROTATION => ['required', 'integer', Rule::in([0, 90, 180, 270])],
```

Style rules for requests:
- Field names are **model constants** (`Table::FLOOR_ID`), never string literals.
- Reusable rule sets live in request traits under `app/Traits/Request/` and are spread into `rules()` (see `ValidatesOptions` in contracts.md).
- Custom validation belongs in `app/Rules/<Domain>/` as a `ValidationRule` class, one file per rule.
- Context needed by the response (a loaded `Location`, a resolved customer) is attached in `passedValidation()` and read back via `$request->attributes->get(...)`.

## 3. Controllers — thin orchestration + base-controller envelope

Base controller (real code, trimmed) defines the envelope vocabulary:

```php
class Controller extends BaseController
{
    use AuthorizesRequests, DispatchesJobs, ValidatesRequests;

    const DEFAULT_PAGE_SIZE = 15;
    const FILTERS = 'filters';
    const QUERYABLES = 'queryables';
    const PER_PAGE = 'per_page';
    const STATUS = 'status';
    const ERRORS = 'errors';
    const MODEL = 'model';
    const RESPONSE = 'response';
    const MESSAGE = 'message';

    public function sendResponse(
        array|null|AnonymousResourceCollection $content = [],
        ?string $message = null,
        int $status = Response::HTTP_OK,
        array $headers = []): JsonResponse
    {
        $response = [
            self::RESPONSE => $content,
            self::STATUS => $status,
        ];
        if ($message) {
            $response[self::MESSAGE] = $message;
        }

        return response()->json($response, $status, $headers);
    }

    public function sendErrorResponse(
        ?string $message = null,
        int $status = Response::HTTP_UNAUTHORIZED,
        array $errors = [],
        ?array $response = null,
    ): JsonResponse {
        $toResponse = [
            self::MESSAGE => $message,
            self::ERRORS => $errors,
        ];

        if ($response) {
            $toResponse[self::RESPONSE] = $response;
        }

        return response()->json($toResponse, $status);
    }

    protected function deleted(): Response
    {
        return response()->json([
            self::MESSAGE => __('errors.deleted_successfully'),
            self::STATUS => Response::HTTP_OK,
        ], Response::HTTP_OK);
    }

    protected function batchDeleted(int $count = 0): Response
    {
        return response()->json([
            self::MESSAGE => __('errors.deleted_successfully'),
            'count' => $count,
            self::STATUS => Response::HTTP_OK,
        ], Response::HTTP_OK);
    }
}
```

Also note `JsonResource::wrap(Controller::RESPONSE)` in `AppServiceProvider::register()` — that is why single resources serialize as `{"response": {...}, "status": 200}`.

A CRUD controller in full (verbatim, one file, trimmed comments) — repository injected in the constructor, authorize per action, resource returned directly:

```php
class AddressController extends Controller
{
    public function __construct(private AddressRepository $repository) {}

    public function store(AddressStoreRequest $request)
    {
        $address = $this->repository->create($request->getCustomer(), $request->validated());

        return new AddressResource($address);
    }

    public function update(AddressUpdateRequest $request, Address $address)
    {
        $this->authorize('update', $address);

        $newAddress = $this->repository->update($address, $request->validated());

        return new AddressResource($newAddress);
    }

    public function index(AddressIndexRequest $request, AddressFilter $filter): AnonymousResourceCollection
    {
        return AddressResource::collection(
            $this->repository->index($request->user())
                ->filter($filter)
                ->paginate($request->perPage())
        )->additional([
            self::STATUS => 200,
        ]);
    }

    public function destroy(Address $address): Response
    {
        $this->authorize('delete', $address);
        $address->delete();

        return $this->deleted();
    }

    public function show(Address $address)
    {
        $this->authorize('view', $address);

        return new AddressResource($address);
    }
}
```

And a richer one showing options sync, eager loading and the duplicate idiom (`TableController`):

```php
public function store(TableStoreRequest $request)
{
    $this->authorize('create', new Table());
    $table = $this->repository->create($request->validated());
    if ($request->has('options')) {
        $table->options()->sync($request->optionSyncData());
    }

    return new TableResource(
        $table->load('options')
    );
}

public function index(Request $request, TableFilter $filter): AnonymousResourceCollection
{
    return TableResource::collection(
        $this->repository->index($request->user())->with(['tableType', 'options'])
            ->filter($filter)->get()
    )
        ->additional([
            self::STATUS => 200,
        ]);
}

public function duplicate(Table $table, TableDuplicateRequest $request)
{
    $this->authorize('view', $table);
    $newTable = $table->replicate([Table::NAME, Table::ID, Table::UUID]);
    $newTable->setName('Copy of '.$table->getName())->save();
    $newTable->fill($request->validated())->save();
    if ($request->has('options')) {
        $newTable->options()->sync($request->optionSyncData());
    } else {
        $newTable->options()->sync(
            $table->options->mapWithKeys(fn (Option $option) => [
                $option->getId() => ['description' => $option->pivot->description],
            ])->all()
        );
    }

    return new TableResource($newTable->load(['tableType', 'options']));
}
```

Controller conventions:
- Constructor property promotion for the repository: `__construct(private TableRepository $repository) {}` (empty body on one line).
- The controller never builds queries itself for list endpoints; it calls `$this->repository->index($user)` and then `->filter($filter)`.
- `if ($request->has('options'))` distinguishes "field absent → leave unchanged" from "present but empty → clear".
- Retrieve before delete/mutate when the old relation must be preserved (`$table->options` collection, not `$table->options()->pluck()`).
- Deletions return `$this->deleted()` / `batchDestroy` returns `$this->batchDeleted($count)`; creations usually return the resource (HTTP 200), not 201.

## 4. Resources — audience-aware output

`BaseResource` is the single dispatcher (verbatim, trimmed). It merges `getCommonArray()` with one audience-specific array:

```php
public function toArray($request): array
{
    /** @var Customer|User|PlatformAdmin|null $user */
    $user = $request instanceof Request ? ($request->user() ?? Auth::user()) : null;
    $commonResponse = $this->getCommonArray($request);
    $specialResponse = [];
    if (!$user) {
        $specialResponse = $this->getCustomerArray($request);
    } elseif ($user instanceof PlatformAdmin) {
        $specialResponse = $this->getPlatformAdminArray($request, $user);
    } elseif ($user instanceof Customer) {
        $specialResponse = $this->getCustomerArray($request, $user);
    } elseif ($user instanceof User && $user->isAdmin()) {
        $specialResponse = $this->getAdminArray($request, $user);
    } elseif ($user instanceof User) {
        $type = Str::ucfirst(Str::camel($user->getType()));
        $method = "get{$type}Array";

        if (method_exists($this, $method)) {
            $specialResponse = $this->$method($request, $user);
        } else {
            $specialResponse = $this->getUserArray($request, $user);
        }
    } else {
        $specialResponse = $this->getCustomerArray($request);
    }

    return array_merge($commonResponse, $specialResponse);
}
```

`SuccessStatusTrait` (used by most concrete resources) injects `status` into every single-resource response and provides a "loaded and non-empty" helper:

```php
trait SuccessStatusTrait
{
    public function with($request)
    {
        return [
            Controller::STATUS => Response::HTTP_OK,
        ];
    }

    public function whenLoadedAndNotEmpty($relationship)
    {
        if (! $this->resource->relationLoaded($relationship)) {
            return new MissingValue;
        }

        /** @var Collection $loadedValue */
        $loadedValue = $this->resource->{$relationship};
        if ($loadedValue instanceof Model) {
            return $loadedValue;
        }

        if ($loadedValue instanceof Collection) {
            if ($loadedValue->isEmpty()) {
                return new MissingValue;
            }
            // …
        }
    }
}
```

A concrete resource with pivot data and lazy relations (verbatim excerpt):

```php
class OptionResource extends BaseResource
{
    use SuccessStatusTrait;
    private const MODEL = Option::class;

    protected function getCommonArray($request): array
    {
        return [
            Option::ID => $this->getId(),
            Option::NAME => $this->getName(),
        ];
    }
}
```

```php
// the richer variant added with option descriptions
protected function getCommonArray($request): array
{
    return [
        Option::ID => $this->getId(),
        Option::NAME => $this->getName(),
        Option::HAS_DESCRIPTION => $this->resource->getAttribute(Option::HAS_DESCRIPTION),
        Optionable::DESCRIPTION => $this->whenPivotLoaded(
            Optionable::TABLE,
            fn () => $this->resource->pivot->getAttribute(Optionable::DESCRIPTION)
        ),
    ];
}
```

Resource conventions:
- Keys are model constants (`Option::ID`); values come from magic getters (`$this->getId()`).
- Optional relations always go through `new CountryResource($this->whenLoaded('country'))` — an unloaded relation must disappear from the payload, not render `null`.
- Use `$this->resource->getAttribute(...)` when you need a cast raw value (a boolean cast), and `whenPivotLoaded(...)` for many-to-many pivot fields.
- Never widen `getCommonArray()` with data that only an admin may see; add a `get<Role>Array` instead.
- `AnonymousResourceCollection` is overridden in-project only to expose `toAttributes()` for merges; normal collections come from `Resource::collection(...)->additional([Controller::STATUS => 200])`.

## 5. Policies — state checks live here, not in controllers

Policies use `HandlesAuthorization` plus the project `IsAdminTrait` and receive the repository by constructor injection when they need data:

```php
class OrderPolicy
{
    use HandlesAuthorization;
    use IsAdminTrait;

    public function __construct(private OrderRepository $repository) {}

    public function submit(null|User|Customer $user, Order $order)
    {
        if (! $order->isPaid()) {
            return $this->deny(__('order.order_payment_pending'));
        }

        if ($order->getStatus() != Order::STATUS_DRAFT) {
            return $this->deny(__('order.order_already_submitted'));
        }

        return true;
    }

    public function accept(User $user, Order $order)
    {
        if (! $user->isAdmin() && $user->getLocationId() != $order->getLocationId()) {
            return false;
        }

        return true;
    }
}
```

Two shapes are legitimate here: a **deny-list** (`if (in_array($status, [COMPLETED, CANCELED])) return false;` — everything not denied is allowed) and a **precondition list** with translated denial messages (`$this->deny(__('...'))`). Keep state-machine rules in one policy method so a single test can flip one status.

## 6. Filters — declarative query params

List filters subclass `AliMousavi\Filoquent\Filters\FilterAbstract` and declare allow-lists; each custom param is a method returning the modified builder:

```php
class TableFilter extends FilterAbstract
{
    use FilterLocationIdTrait;

    protected array $filterables = [
        'locationId' => self::TYPE_INTEGER,
        'floorId' => self::TYPE_INTEGER,
    ];

    protected array $searchables = [
        Table::NAME,
    ];

    protected array $orderBy = [
        // Add your orderable fields here
    ];

    protected array $orderables = [
      Table::NAME,
    ];

    protected function floorId(string $floorId): Builder
    {
        return $this->builder->where(Table::FLOOR_ID, $floorId);
    }
}
```

Rule of thumb: anything a client may filter/search/order by must be declared in these arrays; unknown params are ignored by design. Controllers pass the filter object as a method parameter (`index(AddressIndexRequest $request, AddressFilter $filter)`) and apply it with `->filter($filter)`.

## 7. Envelope contract — do not invent another one

Success (single resource, because of `JsonResource::wrap(Controller::RESPONSE)` + `SuccessStatusTrait`):

```json
{ "response": { "id": 1, "name": "…" }, "status": 200 }
```

Success (collection with `->additional([Controller::STATUS => 200])`), and `sendResponse()` shapes as `{response, status, message?}`:

```json
{ "response": [ { "id": 1 } ], "status": 200 }
```

Error (`sendErrorResponse` / validation):

```json
{ "message": "…", "errors": { "field": ["…"] }, "response": null }
```

Deletion (`deleted()` / `batchDeleted($count)`):

```json
{ "message": "Deleted successfully", "status": 200 }
```

Frontend consumers read `response` and `status` — keep those keys stable when refactoring, even if you rename internal methods.


---

## references/implementation-playbook.md

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


---

## references/persistence.md

# Persistence — models, interfaces, constants, magic getters, repositories

Fusion backend observations. Excerpts verbatim with omissions; not runnable alone.

## 1. The model + interface pair (the signature pattern)

Real code:

```php
// app/Interfaces/Models/Company/General/OptionInterface.php
interface OptionInterface extends
    BaseModelInterface,          // brings HasIdInterface (ID const)
    HasNameInterface,            // NAME const + scopeWhereNameLike contract
    HasTypeInterface,            // TYPE const
    HasDisplayOrderInterface     // DISPLAY_ORDER const
{
    const TABLE = 'options';
    const TYPE_FLOOR = 'floor';
    const TYPE_TABLE = 'table';
}

// app/Models/Company/General/Option.php
class Option extends BaseModel implements OptionInterface
{
    use SoftDeletes;
    use HasFactory;
    use HasIdTrait;            // pairs with HasIdInterface
    use HasNameTrait;          // pairs with HasNameInterface (adds scopeWhereNameLike)
    use HasDisplayOrderTrait;  // pairs with HasDisplayOrderInterface

    protected $table = self::TABLE;
    protected $connection = ConnectionConstants::TENANT_CONNECTION;
    protected $guarded = [self::ID];

    public static array $types = [self::TYPE_FLOOR, self::TYPE_TABLE];
}
```

Why two files: the **interface holds constants + contracts** (so requests/resources/repositories can reference columns without loading the model), the **model provides companion traits** implementing those contracts. Adding a column constant means adding it to the interface, then a `Has<X>Trait` if behavior accompanies it.

Every model declares its **connection explicitly** (`ConnectionConstants::TENANT_CONNECTION` for tenant entities) — never rely on the default.

## 2. Magic getters — MagicMethodsTrait (real code)

```php
// app/Traits/MagicMethodsTrait.php (on BaseModel)
public function __call($method, $parameters)
{
    $constants = (new \ReflectionClass($this))->getConstants();
    $splitName = preg_split('/([A-Z]+[^A-Z]+)/', $method, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);

    $methodType = array_shift($splitName);
    $columnName = strtolower(implode('_', $splitName));

    if (! method_exists($this, $method) && in_array($columnName, $constants)) {
        if ($methodType === 'set') {
            $this->$columnName = current($parameters);
            return $this;
        }
        if ($methodType === 'get') {
            return $this->$columnName ?? null;
        }
    }
    return parent::__call($method, $parameters);
}
```

So `$table->getName()` works because `NAME = 'name'` exists as a constant — the constant IS the column registry. Consequences:
- Rename a column = update the interface constant; all magic call sites follow.
- IDE support comes from generated docblocks (`@method mixed getName() ...` inside `END IDE Helpers` blocks) — a docblock method proves nothing at runtime; verify the constant exists.
- Adding a column: add the constant to the interface + trait if needed; getters/setters come free.

## 3. BaseModel — shared behavior (real excerpt)

```php
abstract class BaseModel extends Model implements BaseModelInterface
{
    use Filterable;          // from the `alimousavi/filoquent` composer package: filter scopes
    use HasFactory;
    use HasIdTrait;          // ID const + id accessors
    use MagicMethodsTrait;   // dynamic get/set via constants
    use RelationshipsTrait;  // eager-load helpers

    public function getResourceClass(): array|string
    {
        $class = $this->getMorphClass();
        return str_replace('\Models\', '\Http\Resources\', $class) . "Resource";
    }

    public function countLoaded(string $relationship): bool
    {
        $attribute = (new Stringable($relationship))->snake()->finish('_count')->value();
        return array_key_exists($attribute, $this->getAttributes());
    }
}
```

`getResourceClass()` means a model always knows its resource — controllers can do `$model->getResourceClass()` generically. `countLoaded()` checks whether a `withCount` alias actually landed on the model (avoids N+1 on counts).

## 4. Repositories — access-aware query builder (real excerpt)

```php
abstract class BaseRepository
{
    protected Model $model;

    public function query(): Builder { return $this->model::query(); }

    // THE entry point for lists: scopes by caller category
    public function index(Customer|User|PlatformAdmin|null $user): Builder
    {
        if (! $user) return $this->getCustomerIndex($this->query(), $user);
        if ($user instanceof PlatformAdmin) return $this->getPlatformAdminIndex($this->query(), $user);
        if ($user instanceof Customer) return $this->getCustomerIndex($this->query(), $user);
        if ($user->isAdmin()) return $this->getAdminIndex($this->query(), $user);

        $model = $user->getModelType();
        $modelType = $this->modelMapping[$model] ?? null;         // e.g. Device => 'Device'
        $method = $modelType ? "get{$modelType}ModelIndex" : 'get' . Str::ucfirst(Str::camel($user->getType())) . 'Index';
        return is_callable([$this, $method]) ? $this->$method($this->query(), $user) : $this->getUserIndex($this->query(), $user);
    }

    protected function getAdminIndex(Builder $builder, AuthenticatableInterface $user): Builder { return $builder; }
    protected function getUserIndex(Builder $builder, User $user): Builder
    { return $builder->where(HasUserIdInterface::USER_ID, $user->getId()); }
    protected function getCustomerIndex(Builder $builder, ?AuthenticatableInterface $customer = null): Builder
    { return $builder->where(HasCustomerIdInterface::CUSTOMER_ID, $customer->getId()); }

    public function canIndex(Customer|User|PlatformAdmin|null $user, BaseModel $model): bool
    { return $this->index($user)->where($model->getKeyName(), $model->getKey())->exists(); }
}
```

Rules:
- **Never** expose `$this->query()` to a controller for list endpoints — go through `index($user)` so tenant scoping (`CUSTOMER_ID`/`USER_ID`/company filter) is applied by the repository, in one place.
- Subclass repositories override `get<ROLE>Index` (e.g. `getManagerIndex`) to add domain scope; dynamic dispatch mirrors the resource layer.
- `canIndex()`/`hasAccessTo()` = cheap existence check inside the scoped query — used for record-level access.

## 5. Query-cost calibration (review posture, observed)

- Eager-loading lists: resources render relations with `whenLoaded(...)`; the feeding query must `with([...])` the same keys. Check the pair, not just one side.
- Accepted-cost N+1s exist (cache-backed renders, admin-only pages, small pivots). Flag **correctness** bugs (e.g. `loadMissing` on shared pivots returning stale first-parent data) separately from cost; propose minimal in-place diffs, not bulk pivot maps or new abstractions — that bar was explicitly set by the team's review history.
- Aggregates: prefer one bucketed query (CASE WHEN per period) over N separate sums — his dashboard refactor is the reference sample (see personal-style-tests.md).

## 6. Migrations

- Central migrations: `database/migrations/`; tenant migrations: `database/migrations/tenant` (stancl/tenancy). Never mix.
- Column names in migrations agree with the interface constants (`$table->string(OptionInterface::NAME)`).
- Standard SoftDeletes on most models; deleted_at reserved const on BaseModel.


---

## references/personal-style-tests.md

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


---

## references/prestige-boundaries.md

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


---

## references/services-jobs-cache.md

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


---

## references/tenancy.md

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


---

