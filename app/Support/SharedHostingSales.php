<?php

namespace App\Support;

/**
 * Platform (super-admin) DirectAdmin / shared hosting sales.
 *
 * Platform customers order application hosting only. Resellers still sell
 * DirectAdmin packages through their own catalog (/my/catalog).
 */
class SharedHostingSales
{
    /**
     * Whether platform customers can browse/order DirectAdmin shared hosting.
     * Permanently disabled — use application hosting + Mailcow instead.
     */
    public static function enabled(): bool
    {
        return false;
    }
}
