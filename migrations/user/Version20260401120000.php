<?php

/**
 * Fix cqProfileManageItem AI Tool: rename snake_case parameters to camelCase
 * (displayStyle, descriptionDisplayStyle, showHeader) for consistency with other tools.
 */
class UserMigration_20260401120000
{
    public function up(\PDO $db): void
    {
        $stmt = $db->prepare("SELECT id, parameters FROM ai_tool WHERE name = 'cqProfileManageItem'");
        $stmt->execute();
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$row) {
            return;
        }

        $params = json_decode($row['parameters'], true);
        if (!isset($params['properties'])) {
            return;
        }

        $renames = [
            'display_style'             => 'displayStyle',
            'description_display_style' => 'descriptionDisplayStyle',
            'show_header'               => 'showHeader',
        ];

        foreach ($renames as $old => $new) {
            if (isset($params['properties'][$old]) && !isset($params['properties'][$new])) {
                $params['properties'][$new] = $params['properties'][$old];
                unset($params['properties'][$old]);
            }
        }

        $stmt = $db->prepare("UPDATE ai_tool SET parameters = ?, updated_at = ? WHERE id = ?");
        $stmt->execute([json_encode($params), date('Y-m-d H:i:s'), $row['id']]);
    }

    public function down(\PDO $db): void
    {
        $stmt = $db->prepare("SELECT id, parameters FROM ai_tool WHERE name = 'cqProfileManageItem'");
        $stmt->execute();
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$row) {
            return;
        }

        $params = json_decode($row['parameters'], true);
        if (!isset($params['properties'])) {
            return;
        }

        $renames = [
            'displayStyle'             => 'display_style',
            'descriptionDisplayStyle'  => 'description_display_style',
            'showHeader'               => 'show_header',
        ];

        foreach ($renames as $old => $new) {
            if (isset($params['properties'][$old]) && !isset($params['properties'][$new])) {
                $params['properties'][$new] = $params['properties'][$old];
                unset($params['properties'][$old]);
            }
        }

        $stmt = $db->prepare("UPDATE ai_tool SET parameters = ?, updated_at = ? WHERE id = ?");
        $stmt->execute([json_encode($params), date('Y-m-d H:i:s'), $row['id']]);
    }
}
