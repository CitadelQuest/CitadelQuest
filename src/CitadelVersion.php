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
    public const VERSION = 'v0.7.44-beta'; // 2026-05-23

    /**
     * Get the current version string
     */
    public static function getVersion(): string
    {
        return self::VERSION;
    }
}
