<?php

namespace App\Models\Concerns;

use BadMethodCallException;
use Illuminate\Support\Str;
use ReflectionClass;

/**
 * Maps getX()/setX() onto the attribute named by a class constant.
 *
 * The constant-first convention means a column is never spelled as a string
 * literal, so accessors can be derived from the constant set instead of being
 * written out. The derived name must exist as a constant, otherwise the call
 * fails loudly rather than reading a nonexistent column.
 */
trait HasConstantGetters
{
    public function __call($method, $parameters)
    {
        if (str_starts_with((string) $method, 'get')) {
            return $this->getAttribute($this->attributeFor($method));
        }

        if (str_starts_with((string) $method, 'set')) {
            $this->setAttribute($this->attributeFor($method), $parameters[0] ?? null);

            return $this;
        }

        return parent::__call($method, $parameters);
    }

    private function attributeFor(string $method): string
    {
        $attribute = Str::snake(substr($method, 3));

        if (! in_array($attribute, (new ReflectionClass($this))->getConstants(), true)) {
            throw new BadMethodCallException(
                sprintf('No constant on %s names the attribute "%s".', static::class, $attribute)
            );
        }

        return $attribute;
    }
}
