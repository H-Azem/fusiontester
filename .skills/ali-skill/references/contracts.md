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
