<?php

/**
 * Migration: Add diffusionArtistSpirit AI Tool
 * 
 * This tool enables high-quality image generation using AI diffusion models
 * (Stable Diffusion, FLUX, etc.) via Runware.ai API.
 * 
 * @see /docs/features/image-diffusion-spirit.md
 */
class UserMigration_20260107200000
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
        // Add Diffusion Artist Spirit tool
        $this->addTool($db, 'diffusionArtistSpirit', 
            'Generate high-quality images using AI diffusion models (Stable Diffusion, FLUX, etc.). Describe what you want in natural language - the Diffusion Artist will translate it to optimal prompts and select the best model. Best for: artistic images, specific styles, anime, realistic photos, NSFW content.',
            [
                'type' => 'object',
                'properties' => [
                    'projectId' => [
                        'type' => 'string',
                        'description' => 'Project ID for file storage'
                    ],
                    'projectPath' => [
                        'type' => 'string',
                        'description' => 'Project path for file storage (optional, default /uploads/ai/img-diffusion)',
                    ],
                    'projectFileName' => [
                        'type' => 'string',
                        'description' => 'Project file name for file storage (optional)',
                    ],
                    'prompt' => [
                        'type' => 'string',
                        'description' => 'Natural language description of desired image. Be descriptive about subject, style, setting, and mood.'
                    ],
                    'width' => [
                        'type' => 'integer',
                        'description' => 'Image width in pixels: 832 or 1216 (optional, default 1216)'
                    ],
                    'height' => [
                        'type' => 'integer',
                        'description' => 'Image height in pixels: 832 or 1216 (optional, default 1216)'
                    ],
                    'seed' => [
                        'type' => 'integer',
                        'description' => 'Seed for reproducible results (optional). Use the same seed to generate similar images.'
                    ],
                    'style' => [
                        'type' => 'string',
                        'description' => 'Style hint (optional): realistic, anime, artistic, cyberpunk, fantasy, portrait, landscape, etc.'
                    ]
                ],
                'required' => ['projectId', 'prompt']
            ],
            1 // Active by default
        );
    }
    
    /**
     * Helper method to add a tool if it doesn't exist
     */
    private function addTool(\PDO $db, string $name, string $description, array $parameters, int $isActive = 0): void
    {
        // Check if tool exists
        $stmt = $db->prepare("SELECT id FROM ai_tool WHERE name = ?");
        $stmt->execute([$name]);
        if (!$stmt->fetch(\PDO::FETCH_ASSOC)) {
            // Insert tool
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

    public function down(\PDO $db): void
    {
        // Remove the diffusionArtistSpirit tool
        $db->exec("DELETE FROM ai_tool WHERE name = 'diffusionArtistSpirit'");
    }
}
