<?php

class UserMigration_20250602173000
{
    /**
     * Generate a UUID v4
     */
    private function generateUuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
    
    public function up(\PDO $db): void
    {
        // Weather tool
        $weatherId = $this->generateUuid();
        $weatherName = 'getWeather';
        $weatherDescription = 'Get the current weather in a given location';
        $weatherParameters = json_encode([
            'type' => 'object',
            'properties' => [
                'location' => [
                    'type' => 'string',
                    'description' => 'The city and state, e.g. San Francisco, CA',
                ],
                'unit' => [
                    'type' => 'string',
                    'enum' => ['celsius', 'fahrenheit'],
                    'description' => 'The temperature unit to use. Infer this from the users location.',
                ],
            ],
            'required' => ['location'],
        ]);
        
        // User profile tool
        $profileId = $this->generateUuid();
        $profileName = 'updateUserProfile';
        $profileDescription = 'Update the user profile description by adding new information. When user tell you something new about him/her, some interesting or important fact, etc., you should add it to the profile description, so it is available for you to use in future conversations.';
        $profileParameters = json_encode([
            'type' => 'object',
            'properties' => [
                'newInfo' => [
                    'type' => 'string',
                    'description' => 'The new information added to the profile description',
                ],
            ],
            'required' => ['newInfo'],
        ]);
        
        // Check if weather tool exists
        $stmt = $db->prepare("SELECT id FROM ai_tool WHERE name = ?");
        $stmt->execute([$weatherName]);
        if (!$stmt->fetch(\PDO::FETCH_ASSOC)) {
            // Insert weather tool
            $stmt = $db->prepare(
                'INSERT INTO ai_tool (id, name, description, parameters, is_active, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $weatherId,
                $weatherName,
                $weatherDescription,
                $weatherParameters,
                1,
                date('Y-m-d H:i:s'),
                date('Y-m-d H:i:s')
            ]);
        }
        
        // Check if profile tool exists
        $stmt = $db->prepare("SELECT id FROM ai_tool WHERE name = ?");
        $stmt->execute([$profileName]);
        if (!$stmt->fetch(\PDO::FETCH_ASSOC)) {
            // Insert profile tool
            $stmt = $db->prepare(
                'INSERT INTO ai_tool (id, name, description, parameters, is_active, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $profileId,
                $profileName,
                $profileDescription,
                $profileParameters,
                1,
                date('Y-m-d H:i:s'),
                date('Y-m-d H:i:s')
            ]);
        }
    }

    public function down(\PDO $db): void
    {
        // Remove the tools if they exist
        $db->exec("DELETE FROM ai_tool WHERE name = 'getWeather'");
        $db->exec("DELETE FROM ai_tool WHERE name = 'updateUserProfile'");
    }
}
