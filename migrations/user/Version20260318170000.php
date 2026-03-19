<?php

/**
 * CQ Feed — seed default "General" feed for existing users whose cq_user_feed table is empty.
 * 
 */
class UserMigration_20260318170000
{
    private function generateUuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    public function up(\PDO $db): void
    {
        // Only seed if cq_user_feed is empty (existing users who never got the default feed)
        $stmt = $db->query('SELECT COUNT(*) FROM cq_user_feed');
        $count = (int) $stmt->fetchColumn();

        if ($count === 0) {
            $id = $this->generateUuid();
            $now = date('Y-m-d H:i:s');

            $stmt = $db->prepare(
                'INSERT INTO cq_user_feed (id, title, feed_url_slug, scope, description, is_active, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, 1, ?, ?)'
            );
            $stmt->execute([
                $id,
                'Public',
                'public',
                0, // SCOPE_PUBLIC
                'Default public feed',
                $now,
                $now,
            ]);
        }
    }
}
