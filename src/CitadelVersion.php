<?php

namespace App;

/**
 * Central location for CitadelQuest version information
 */
final class CitadelVersion
{
    /**
     * Current version of CitadelQuest
     * @var string
     */
    public const VERSION = 'v0.5.12-beta'; // 2025-12-04

    /**
     * Get the current version string
     */
    public static function getVersion(): string
    {
        return self::VERSION;
    }
}
