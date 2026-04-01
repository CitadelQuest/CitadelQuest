<?php

/**
 * Add AI Tools for CQ Profile Content management:
 * - cqProfileManageGroup: create/update/delete/list Share Groups
 * - cqProfileManageItem: add/remove/update/list items in Share Groups
 */
class UserMigration_20260401000000
{
    public function up(\PDO $db): void
    {
        $this->addTool($db, 'cqProfileManageGroup',
            'Manage CQ Profile Share Groups — list, create, update, or delete content groups on your CQ Profile page. Use "list" first to see existing groups and their IDs. Groups are used to organize shared files and memory packs into sections visible on your public profile.',
            [
                'type' => 'object',
                'properties' => [
                    'operation' => [
                        'type' => 'string',
                        'enum' => ['list', 'create', 'update', 'delete'],
                        'description' => 'Operation to perform: list=show all groups, create=new group, update=edit group, delete=remove group',
                    ],
                    'id' => [
                        'type' => 'string',
                        'description' => 'Group ID (required for update and delete)',
                    ],
                    'title' => [
                        'type' => 'string',
                        'description' => 'Group title (required for create; optional for update)',
                    ],
                    'mdi_icon' => [
                        'type' => 'string',
                        'description' => 'MDI icon class e.g. "mdi-folder", "mdi-star", "mdi-music" (optional, default: mdi-folder)',
                    ],
                    'scope' => [
                        'type' => 'integer',
                        'enum' => [0, 1],
                        'description' => 'Visibility scope: 0=Public (visible to everyone), 1=Federation (visible to CQ Contacts only). Default: 0',
                    ],
                    'show_in_nav' => [
                        'type' => 'boolean',
                        'description' => 'Show group in profile navigation bar (optional, default: true)',
                    ],
                    'is_active' => [
                        'type' => 'boolean',
                        'description' => 'Whether the group is active/visible (optional, for update)',
                    ],
                    'url_slug' => [
                        'type' => 'string',
                        'description' => 'URL-friendly slug for direct link (optional, auto-generated from title if not set)',
                    ],
                    'icon_color' => [
                        'type' => 'string',
                        'description' => 'Hex color for the icon e.g. "#95ec86" (optional)',
                    ],
                ],
                'required' => ['operation'],
            ],
            1 // Active by default
        );

        $this->addTool($db, 'cqProfileManageItem',
            'Manage items within a CQ Profile Share Group — list items, add a file (auto-creates CQ Share if needed), add an existing share, remove or update an item. Use "list" to see current items. Use "add_file" with a projectFileId (from getProjectTree/listFiles) to add a file directly — the system will auto-create a CQ Share for it.',
            [
                'type' => 'object',
                'properties' => [
                    'operation' => [
                        'type' => 'string',
                        'enum' => ['list', 'add_file', 'add_share', 'remove', 'update'],
                        'description' => 'Operation: list=show group items, add_file=add file to group (auto-creates share), add_share=add existing share, remove=remove item, update=change item display settings',
                    ],
                    'groupId' => [
                        'type' => 'string',
                        'description' => 'Share Group ID (required for list, add_file, add_share)',
                    ],
                    'itemId' => [
                        'type' => 'string',
                        'description' => 'Group item ID (required for remove and update)',
                    ],
                    'projectFileId' => [
                        'type' => 'string',
                        'description' => 'Project file ID from getProjectTree or listFiles (required for add_file)',
                    ],
                    'fileName' => [
                        'type' => 'string',
                        'description' => 'File name for the CQ Share title (required for add_file, e.g. "my-document.pdf")',
                    ],
                    'shareId' => [
                        'type' => 'string',
                        'description' => 'Existing CQ Share ID (required for add_share)',
                    ],
                    'display_style' => [
                        'type' => 'integer',
                        'enum' => [0, 1, 2],
                        'description' => 'Display style override for this item: 0=hidden, 1=preview, 2=full (optional, inherits from share if not set)',
                    ],
                    'description_display_style' => [
                        'type' => 'integer',
                        'enum' => [0, 1, 2, 3],
                        'description' => 'Description position: 0=above, 1=below, 2=left, 3=right (optional)',
                    ],
                    'show_header' => [
                        'type' => 'integer',
                        'enum' => [0, 1],
                        'description' => 'Show item header: 1=yes, 0=no (optional, default: 1)',
                    ],
                ],
                'required' => ['operation'],
            ],
            1 // Active by default
        );
    }

    public function down(\PDO $db): void
    {
        $db->exec("DELETE FROM ai_tool WHERE name IN ('cqProfileManageGroup', 'cqProfileManageItem')");
    }

    private function addTool(\PDO $db, string $name, string $description, array $parameters, int $isActive = 0): void
    {
        $stmt = $db->prepare("SELECT id FROM ai_tool WHERE name = ?");
        $stmt->execute([$name]);
        if (!$stmt->fetch(\PDO::FETCH_ASSOC)) {
            $stmt = $db->prepare(
                'INSERT INTO ai_tool (id, name, description, parameters, is_active, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $this->generateUuid(),
                $name,
                $description,
                json_encode($parameters),
                $isActive,
                date('Y-m-d H:i:s'),
                date('Y-m-d H:i:s')
            ]);
        }
    }

    private function generateUuid(): string
    {
        return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );
    }
}
