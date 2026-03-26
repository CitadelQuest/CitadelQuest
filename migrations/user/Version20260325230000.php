<?php

/**
 * CQ Feed — seed default "CQ Contacts" feed for existing users whose cq_user_feed table has only 1 `public` feed.
 * 
 */
class UserMigration_20260325230000
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
        // Only seed if cq_user_feed has exactly 1 feed and it is the "Public" one (scope=0).
        // This targets existing users who only got the default Public feed from Version20260318170000.
        $stmt = $db->query('SELECT COUNT(*) FROM cq_user_feed');
        $count = (int) $stmt->fetchColumn();

        if ($count !== 1) {
            return; // 0 feeds = brand new user (onboarding handles it), 2+ = already has custom feeds
        }

        $stmt = $db->query('SELECT scope FROM cq_user_feed LIMIT 1');
        $scope = (int) $stmt->fetchColumn();

        if ($scope === 0) { // SCOPE_PUBLIC
            $id = $this->generateUuid();
            $now = date('Y-m-d H:i:s');

            $stmt = $db->prepare(
                'INSERT INTO cq_user_feed (id, title, feed_url_slug, scope, description, is_active, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, 1, ?, ?)'
            );
            $stmt->execute([
                $id,
                'CQ Contacts',
                'cq-contacts',
                1, // SCOPE_CQ_CONTACT
                'Default CQ Contacts only feed',
                $now,
                $now,
            ]);
        }
    }
}
