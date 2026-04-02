<?php

/**
 * Rename AI Tools for better naming consistency:
 * - listAiTools -> aiToolList
 * - setAiToolActive -> aiToolSetActive
 * - imageEditorSpirit -> spiritCreateOrEditImage
 * - diffusionArtistSpirit -> spiritCreateDiffusionImage
 */
class UserMigration_20260403140000
{
    public function up(\PDO $db): void
    {
        // Rename listAITools -> aiToolList
        $db->exec("UPDATE ai_tool SET name = 'aiToolList' WHERE name = 'listAITools'");

        // Rename setAIToolActive -> aiToolSetActive
        $db->exec("UPDATE ai_tool SET name = 'aiToolSetActive' WHERE name = 'setAIToolActive'");

        // Rename imageEditorSpirit -> spiritCreateOrEditImage
        $db->exec("UPDATE ai_tool SET name = 'spiritCreateOrEditImage' WHERE name = 'imageEditorSpirit'");

        // Rename diffusionArtistSpirit -> spiritCreateDiffusionImage
        $db->exec("UPDATE ai_tool SET name = 'spiritCreateDiffusionImage' WHERE name = 'diffusionArtistSpirit'");
    }

    public function down(\PDO $db): void
    {
        // Revert aiToolList -> listAITools
        $db->exec("UPDATE ai_tool SET name = 'listAITools' WHERE name = 'aiToolList'");

        // Revert aiToolSetActive -> setAIToolActive
        $db->exec("UPDATE ai_tool SET name = 'setAIToolActive' WHERE name = 'aiToolSetActive'");

        // Revert spiritCreateOrEditImage -> imageEditorSpirit
        $db->exec("UPDATE ai_tool SET name = 'imageEditorSpirit' WHERE name = 'spiritCreateOrEditImage'");

        // Revert spiritCreateDiffusionImage -> diffusionArtistSpirit
        $db->exec("UPDATE ai_tool SET name = 'diffusionArtistSpirit' WHERE name = 'spiritCreateDiffusionImage'");
    }
}
