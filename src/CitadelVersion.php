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
    public const VERSION = 'v0.5.34-beta'; // 2025-01-03

    /**
     * Get the current version string
     */
    public static function getVersion(): string
    {
        return self::VERSION;
    }
}
