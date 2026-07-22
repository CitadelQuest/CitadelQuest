<?php

/**
 * Migration: Update category and display_order for memoryMap, memoryReadNode, memorySource
 *
 * - memoryMap:      set category = 'memory'
 * - memoryReadNode: set category = 'memory', display_order = 5
 * - memorySource:   set display_order = 7
 */
class UserMigration_20260722160000
{
    public function up(\PDO $db): void
    {
        $db->exec("UPDATE ai_tool SET category = 'memory' WHERE name = 'memoryMap'");
        $db->exec("UPDATE ai_tool SET category = 'memory', display_order = 5 WHERE name = 'memoryReadNode'");
        $db->exec("UPDATE ai_tool SET display_order = 7 WHERE name = 'memorySource'");
    }

    public function down(\PDO $db): void
    {
        // No-op: reverting would require knowing the previous values
    }
}
