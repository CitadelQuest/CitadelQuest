<?php

class UserMigration_20250812191800
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
        // Remove deprecated updateUserProfile tool
        $db->exec("DELETE FROM ai_tool WHERE name = 'updateUserProfile'");
        
        // Add new file management tools
        $this->addTool($db, 'listFiles', 
            'List files and directories in a project directory',
            [
                'type' => 'object',
                'properties' => [
                    'projectId' => [
                        'type' => 'string',
                        'description' => 'The ID of the project',
                    ],
                    'path' => [
                        'type' => 'string',
                        'description' => 'The directory path to list files from (default: "/")',
                    ],
                ],
                'required' => ['projectId'],
            ]
        );
        
        $this->addTool($db, 'getFileContent',
            'Get the content of a file in a project, with optional line numbers for easy reference for text files. Image, video, and audio files are displayed directly in the frontend, so you can use this tool when user would like to see images, videos, or audio files.',
            [
                'type' => 'object',
                'properties' => [
                    'projectId' => [
                        'type' => 'string',
                        'description' => 'The ID of the project',
                    ],
                    'path' => [
                        'type' => 'string',
                        'description' => 'The directory path where the file is located',
                    ],
                    'name' => [
                        'type' => 'string',
                        'description' => 'The name of the file to read',
                    ],
                    'fileId' => [
                        'type' => 'string',
                        'description' => 'The ID of the file',
                    ],
                    'withLineNumbers' => [
                        'type' => 'boolean',
                        'description' => 'Whether to include line numbers in the output (helpful for updateFileEfficient)',
                    ],
                ],
                'required' => ['projectId', 'path', 'name'],
            ]
        );
        
        $this->addTool($db, 'createDirectory',
            'Create a new directory in a project',
            [
                'type' => 'object',
                'properties' => [
                    'projectId' => [
                        'type' => 'string',
                        'description' => 'The ID of the project',
                    ],
                    'path' => [
                        'type' => 'string',
                        'description' => 'The parent directory path',
                    ],
                    'name' => [
                        'type' => 'string',
                        'description' => 'The name of the directory to create',
                    ],
                ],
                'required' => ['projectId', 'path', 'name'],
            ]
        );
        
        $this->addTool($db, 'updateFileEfficient',
            'Update file content efficiently using find/replace operations. Token-efficient alternative to updateFile - only send changed parts. Supports multiple operation types: replace, lineRange, append, prepend, insertAtLine.',
            [
                'type' => 'object',
                'properties' => [
                    'projectId' => [
                        'type' => 'string',
                        'description' => 'Project ID'
                    ],
                    'path' => [
                        'type' => 'string',
                        'description' => 'The directory path where to update the file',
                    ],
                    'name' => [
                        'type' => 'string',
                        'description' => 'The name of the file to update',
                    ],
                    'updates' => [
                        'type' => 'array',
                        'description' => 'Array of update operations to perform',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'operation' => [
                                    'type' => 'string',
                                    'enum' => ['replace', 'lineRange', 'append', 'prepend', 'insertAtLine'],
                                    'description' => 'Update operation'
                                ],
                                'find' => [
                                    'type' => 'string',
                                    'description' => 'Text to find (for replace operation). Only single line!'
                                ],
                                'replace' => [
                                    'type' => 'string',
                                    'description' => 'Text to replace with (for replace operation). Only single line!'
                                ],
                                'startLine' => [
                                    'type' => 'integer',
                                    'description' => 'Start line number (for lineRange operation, 1-based)'
                                ],
                                'endLine' => [
                                    'type' => 'integer',
                                    'description' => 'End line number (for lineRange operation, 1-based, inclusive)'
                                ],
                                'line' => [
                                    'type' => 'integer',
                                    'description' => 'Line number to insert at (for insertAtLine operation, 1-based)'
                                ],
                                'content' => [
                                    'type' => 'string',
                                    'description' => 'Content to insert/append/prepend/replace (for lineRange, append, prepend, insertAtLine operations)'
                                ]
                            ],
                            'required' => ['operation']
                        ]
                    ]
                ],
                'required' => ['projectId', 'path', 'name', 'updates']
            ]
        );
        
        $this->addTool($db, 'getProjectTree',
            'Get the complete hierarchical tree structure of all files and directories in a project',
            [
                'type' => 'object',
                'properties' => [
                    'projectId' => [
                        'type' => 'string',
                        'description' => 'The ID of the project',
                    ],
                ],
                'required' => ['projectId'],
            ]
        );
        
        $this->addTool($db, 'manageFile',
            'Unified file management operations. Supports create, copy, rename_move, and delete operations in one tool. More efficient for AI prompting than separate tools.',
            [
                'type' => 'object',
                'properties' => [
                    'projectId' => [
                        'type' => 'string',
                        'description' => 'Project ID'
                    ],
                    'operation' => [
                        'type' => 'string',
                        'enum' => ['create', 'copy', 'rename_move', 'delete'],
                        'description' => 'Type of file operation to perform'
                    ],
                    'source' => [
                        'type' => 'object',
                        'description' => 'Source file/directory information (required for copy, rename_move, delete)',
                        'properties' => [
                            'path' => [
                                'type' => 'string',
                                'description' => 'Source file path (e.g., "/src/")'
                            ],
                            'name' => [
                                'type' => 'string',
                                'description' => 'Source file/directory name (e.g., "file.txt")'
                            ]
                        ],
                        'required' => ['path', 'name']
                    ],
                    'destination' => [
                        'type' => 'object',
                        'description' => 'Destination file/directory information (required for create, copy, rename_move)',
                        'properties' => [
                            'path' => [
                                'type' => 'string',
                                'description' => 'Destination file path (e.g., "/dest/")'
                            ],
                            'name' => [
                                'type' => 'string',
                                'description' => 'Destination file/directory name (e.g., "new_file.txt")'
                            ]
                        ],
                        'required' => ['path', 'name']
                    ],
                    'content' => [
                        'type' => 'string',
                        'description' => 'File content (required for create operation)'
                    ]
                ],
                'required' => ['projectId', 'operation']
            ]
        );
        
        // Add AI Tool management tools
        $this->addTool($db, 'listAITools',
            'List all AI Tools for Spirit(you)',
            [
                'type' => 'object',
                'properties' => [
                    'activeOnly' => [
                        'type' => 'boolean',
                        'description' => 'Only list active AI Tools',
                    ],
                ],
                'required' => [],
            ]
        );
        
        $this->addTool($db, 'setAIToolActive',
            'Set an AI Tool active or inactive for Spirit(you)',
            [
                'type' => 'object',
                'properties' => [
                    'toolName' => [
                        'type' => 'string',
                        'description' => 'The name of the AI Tool',
                    ],
                    'active' => [
                        'type' => 'boolean',
                        'description' => 'Set the AI Tool active or inactive',
                    ],
                ],
                'required' => ['toolName', 'active'],
            ]
        );
    }
    
    /**
     * Helper method to add a tool if it doesn't exist
     */
    private function addTool(\PDO $db, string $name, string $description, array $parameters): void
    {
        // Check if tool exists
        $stmt = $db->prepare("SELECT id FROM ai_tool WHERE name = ?");
        $stmt->execute([$name]);
        if (!$stmt->fetch(\PDO::FETCH_ASSOC)) {
            // Active by default: getFileContent, updateFileEfficient, getProjectTree, manageFile, setAIToolActive; 
            // Inactive by default: listFiles, createDirectory, listAITools
            $isActive = in_array($name, ['getFileContent', 'updateFileEfficient', 'getProjectTree', 'manageFile', 'setAIToolActive']) ? 1 : 0;


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
        $db->exec("DELETE FROM ai_tool WHERE name = 'listFiles'");
        $db->exec("DELETE FROM ai_tool WHERE name = 'getFileContent'");
        $db->exec("DELETE FROM ai_tool WHERE name = 'createDirectory'");
        $db->exec("DELETE FROM ai_tool WHERE name = 'updateFileEfficient'");
        $db->exec("DELETE FROM ai_tool WHERE name = 'getProjectTree'");
        $db->exec("DELETE FROM ai_tool WHERE name = 'manageFile'");
        $db->exec("DELETE FROM ai_tool WHERE name = 'listAITools'");
        $db->exec("DELETE FROM ai_tool WHERE name = 'setAIToolActive'");
        
        // Re-add the deprecated tool
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
