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
