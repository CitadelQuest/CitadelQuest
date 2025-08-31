<?php

class UserMigration_20250831141200
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
        // Add SEPA QR code tool
        $this->addTool($db, 'createSepaEuroPaymentQrCode', 
            'Create a SEPA payment QR code, with optional remittance text/variable symbol in format: `/VS/{variable_symbol_numbers}`',
            [
                'type' => 'object',
                'properties' => [
                    'name' => [
                        'type' => 'string',
                        'description' => 'Name of the person or company to be debited'
                    ],
                    'iban' => [
                        'type' => 'string',
                        'description' => 'IBAN of the person or company to be debited'
                    ],
                    'bic' => [
                        'type' => 'string',
                        'description' => 'BIC of the person or company to be debited'
                    ],
                    'amount' => [
                        'type' => 'number',
                        'description' => 'Amount to be debited only in Euro'
                    ],
                    'remittanceText' => [
                        'type' => 'string',
                        'description' => 'Remittance text. This field is used for `variable symbol` in format: `/VS/{variable_symbol_numbers}` '
                    ]
                ],
                'required' => ['name', 'iban', 'bic', 'amount']
            ],
            0 // Not active by default
        );
        
        // Add image editor tool
        $this->addTool($db, 'imageEditorSpirit', 
            'Edit or generate image file(s) by prompting specialized AI Spirit. Input image files(optional) and text prompt are used to generate the output image file(s).',
            [
                'type' => 'object',
                'properties' => [
                    'projectId' => [
                        'type' => 'string',
                        'description' => 'Project ID'
                    ],
                    'inputImageFiles' => [
                        'type' => 'array',
                        'description' => 'Input image files for smart editing',
                        'items' => [
                            'properties' => [
                                'imageFile' => [
                                    'type' => 'string',
                                    'description' => 'Full path with name of the input image file'
                                ]
                            ],
                            'required' => ['imageFile']
                        ]
                    ],
                    'textPrompt' => [
                        'type' => 'string',
                        'description' => 'Text prompt to edit or generate the image, it can be used to add or remove objects, restore old photo, change the style, etc. It is recommended to use a clear and concise prompt - it will be used with input image files to generate(by specialized AI Spirit) the output image file.'
                    ],
                    'outputImageFile' => [
                        'type' => 'string',
                        'description' => 'Full path with name of the output image file. If not specified, the output image file will be generated in the default output directory `/uploads/ai/img`. If multiple output image files are generated, they will be generated with unique names automatically.'
                    ]
                ],
                'required' => ['projectId', 'textPrompt']
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
        // Remove the new tools
        $db->exec("DELETE FROM ai_tool WHERE name = 'createSepaEuroPaymentQrCode'");
        $db->exec("DELETE FROM ai_tool WHERE name = 'imageEditorSpirit'");
    }
}
