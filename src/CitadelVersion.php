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
    public const VERSION = 'v0.7.24-beta'; // 2026-03-24

    /**
     * Get the current version string
     */
    public static function getVersion(): string
    {
        return self::VERSION;
    }
}
