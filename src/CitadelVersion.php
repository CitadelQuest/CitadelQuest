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
    public const VERSION = 'v0.4.19-alpha'; // 2025-08-17, AI Toolbox

    /**
     * Get the current version string
     */
    public static function getVersion(): string
    {
        return self::VERSION;
    }
}
