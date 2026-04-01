<?php

/**
 * Fix cqProfileManageGroup AI Tool: rename snake_case parameters to camelCase
 * (mdiIcon, showInNav, isActive, urlSlug, iconColor) for consistency with other tools.
 */
class UserMigration_20260401130000
{
    public function up(\PDO $db): void
    {
        $stmt = $db->prepare("SELECT id, parameters FROM ai_tool WHERE name = 'cqProfileManageGroup'");
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
            'mdi_icon'    => 'mdiIcon',
            'show_in_nav' => 'showInNav',
            'is_active'   => 'isActive',
            'url_slug'    => 'urlSlug',
            'icon_color'  => 'iconColor',
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
        $stmt = $db->prepare("SELECT id, parameters FROM ai_tool WHERE name = 'cqProfileManageGroup'");
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
            'mdiIcon'   => 'mdi_icon',
            'showInNav' => 'show_in_nav',
            'isActive'  => 'is_active',
            'urlSlug'   => 'url_slug',
            'iconColor' => 'icon_color',
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
