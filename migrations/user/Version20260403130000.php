<?php

/**
 * Remove obsolete getWeather AI Tool
 */
class UserMigration_20260403130000
{
    public function up(\PDO $db): void
    {
        // Remove the getWeather tool from ai_tool table
        $db->exec("DELETE FROM ai_tool WHERE name = 'getWeather'");
    }

    public function down(\PDO $db): void
    {
        // Tool removal is not reversible - tool would need to be recreated with full definition
    }
}
