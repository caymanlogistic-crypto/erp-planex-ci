<?php

namespace App\Security;

/** Per-request effective access holder. Never persist this object in session. */
final class AccessRuntime
{
    private static ?EffectiveAccess $current = null;

    public static function setCurrent(EffectiveAccess $access): void
    {
        self::$current = $access;
    }

    public static function clear(): void
    {
        self::$current = null;
    }

    public static function current(): EffectiveAccess
    {
        return self::$current ?? EffectiveAccess::denied('access_runtime_not_initialized');
    }
}
