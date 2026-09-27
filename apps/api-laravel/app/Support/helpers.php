<?php

use Illuminate\Support\Str;

if (! function_exists('run')) {
    /**
     * Invokes a small named operation. Deliberately synchronous: actions in this
     * project run inline and only Jobs go through a queue.
     */
    function run(object $action)
    {
        return $action->handle();
    }
}

if (! function_exists('fusion_snake')) {
    /**
     * Shared by the constant-getter trait so accessor names and column constants
     * can never drift apart.
     */
    function fusion_snake(string $value): string
    {
        return Str::snake($value);
    }
}
