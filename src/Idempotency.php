<?php

declare(strict_types=1);

namespace WendellAdriel\Idempotency;

use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

final class Idempotency
{
    public static function key(): string
    {
        return Str::random(64);
    }

    public static function field(?string $key = null): HtmlString
    {
        return new HtmlString(sprintf(
            '<input type="hidden" name="%s" value="%s" autocomplete="off">',
            e(config()->string('idempotency.input')),
            e($key ?? self::key()),
        ));
    }
}
