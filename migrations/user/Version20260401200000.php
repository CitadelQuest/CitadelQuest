<?php

/**
 * Add AI Tool for CQ Profile settings management:
 * - cqProfileManage: manage profile photo, background, bio, spirit showcase, language
 */
class UserMigration_20260401200000
{
    public function up(\PDO $db): void
    {
        $this->addTool($db, 'cqProfileManage',
            'Manage CQ Profile settings — get current settings, set/remove profile photo, set/remove background image, toggle background overlay, update bio, set spirit showcase mode, and set profile language. Use "getSettings" first to see current configuration. For setProfilePhoto/setBackgroundImage, use a projectFileId from getProjectTree or listFiles (no upload needed, just reference an existing file).',
            [
                'type' => 'object',
                'properties' => [
                    'operation' => [
                        'type' => 'string',
                        'enum' => ['getSettings', 'setProfilePhoto', 'removeProfilePhoto', 'setBackgroundImage', 'removeBackgroundImage', 'setBackgroundOverlay', 'updateBio', 'setSpiritShowcase', 'setLanguage'],
                        'description' => 'Operation to perform',
                    ],
                    'projectFileId' => [
                        'type' => 'string',
                        'description' => 'Project file ID from searchFile/getProjectTree (required for setProfilePhoto and setBackgroundImage)',
                    ],
                    'enabled' => [
                        'type' => 'integer',
                        'enum' => [0, 1],
                        'description' => 'Enable/disable flag (required for setBackgroundOverlay: 0=off, 1=on)',
                    ],
                    'bio' => [
                        'type' => 'string',
                        'description' => 'Bio text content, supports markdown (required for updateBio)',
                    ],
                    'mode' => [
                        'type' => 'integer',
                        'enum' => [0, 1, 2],
                        'description' => 'Spirit showcase mode (required for setSpiritShowcase): 0=Off, 1=Primary Spirit only, 2=All Spirits',
                    ],
                    'language' => [
                        'type' => 'string',
                        'enum' => ['en', 'cs', 'sk', 'es', 'hu', 'pl', 'no', 'it'],
                        'description' => 'Profile page language code (required for setLanguage)',
                    ],
                ],
                'required' => ['operation'],
            ],
            1 // Active by default
        );
    }

    public function down(\PDO $db): void
    {
        $db->exec("DELETE FROM ai_tool WHERE name = 'cqProfileManage'");
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
