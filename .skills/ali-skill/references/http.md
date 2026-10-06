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
