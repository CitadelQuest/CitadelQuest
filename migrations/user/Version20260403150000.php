<?php

/**
 * Refactor file management AI Tools for better naming consistency and consolidation:
 * 
 * Renames:
 * - searchFile -> fileSearch
 * - updateFileEfficient -> fileUpdate
 * - manageFile -> fileManage
 * 
 * Consolidates into fileManage tool as new operations:
 * - createDirectory -> operation: createDirectory
 * - listFiles -> operation: list
 * - getProjectTree -> operation: tree
 * - getFileContent -> operation: read
 * 
 * Then removes standalone tools:
 * - createDirectory
 * - listFiles
 * - getProjectTree
 * - getFileContent
 */
class UserMigration_20260403150000
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
        // Rename searchFile -> fileSearch
        $db->exec("UPDATE ai_tool SET name = 'fileSearch' WHERE name = 'searchFile'");

        // Rename updateFileEfficient -> fileUpdate
        $db->exec("UPDATE ai_tool SET name = 'fileUpdate' WHERE name = 'updateFileEfficient'");

        // Rename manageFile -> fileManage (with expanded operations)
        $db->exec("UPDATE ai_tool SET name = 'fileManage' WHERE name = 'manageFile'");
        
        // Update fileManage tool definition to include consolidated operations
        $newFileManageParams = [
            'type' => 'object',
            'properties' => [
                'projectId' => [
                    'type' => 'string',
                    'description' => 'Project ID'
                ],
                'operation' => [
                    'type' => 'string',
                    'enum' => ['create', 'read', 'copy', 'rename_move', 'delete', 'createDirectory', 'list', 'tree'],
                    'description' => 'Type of file operation to perform. File operations: create, read, copy, rename_move, delete, createDirectory, list (list files in dir), tree (full project tree).'
                ],
                'sourcePath' => [
                    'type' => 'string',
                    'description' => 'Source file/directory path (required for copy, read, rename_move, delete, list, tree operations)'
                ],
                'sourceName' => [
                    'type' => 'string',
                    'description' => 'Source file/directory name (required for copy, read, rename_move, delete operations)'
                ],
                'destPath' => [
                    'type' => 'string',
                    'description' => 'Destination file/directory path (required for create, copy, rename_move, createDirectory operations)'
                ],
                'destName' => [
                    'type' => 'string',
                    'description' => 'Destination file/directory name (required for create, copy, rename_move, createDirectory operations)'
                ],
                'content' => [
                    'type' => 'string',
                    'description' => 'File content (required for create operation)'
                ],
                'withLineNumbers' => [
                    'type' => 'boolean',
                    'description' => 'Whether to include line numbers in the output (for read operation)'
                ]
            ],
            'required' => ['projectId', 'operation']
        ];
        
        $stmt = $db->prepare("UPDATE ai_tool SET parameters = ?, description = ? WHERE name = 'fileManage'");
        $stmt->execute([
            json_encode($newFileManageParams),
            'Unified file management operations. Supports operations: create, read, copy, rename_move, delete, createDirectory, list, tree.'
        ]);

        // Remove consolidated standalone tools
        $db->exec("DELETE FROM ai_tool WHERE name = 'createDirectory'");
        $db->exec("DELETE FROM ai_tool WHERE name = 'listFiles'");
        $db->exec("DELETE FROM ai_tool WHERE name = 'getProjectTree'");
        $db->exec("DELETE FROM ai_tool WHERE name = 'getFileContent'");
    }

    public function down(\PDO $db): void
    {
        // Revert fileSearch -> searchFile
        $db->exec("UPDATE ai_tool SET name = 'searchFile' WHERE name = 'fileSearch'");

        // Revert fileUpdate -> updateFileEfficient
        $db->exec("UPDATE ai_tool SET name = 'updateFileEfficient' WHERE name = 'fileUpdate'");

        // Revert fileManage -> manageFile (with original parameters)
        $db->exec("UPDATE ai_tool SET name = 'manageFile' WHERE name = 'fileManage'");
        
        $oldManageParams = [
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
        ];
        
        $stmt = $db->prepare("UPDATE ai_tool SET parameters = ?, description = ? WHERE name = 'manageFile'");
        $stmt->execute([
            json_encode($oldManageParams),
            'Unified file management operations. Supports: create, copy, rename_move, and delete operations in one tool. More efficient for AI prompting than separate tools.'
        ]);

        // Re-add the consolidated tools
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
            ],
            false // inactive by default
        );
        
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
            ],
            false // inactive by default
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
            ],
            true // active by default
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
            ],
            true // active by default
        );
    }
    
    /**
     * Helper method to add a tool if it doesn't exist
     */
    private function addTool(\PDO $db, string $name, string $description, array $parameters, bool $isActive): void
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
                $isActive ? 1 : 0, 
                date('Y-m-d H:i:s'),
                date('Y-m-d H:i:s')
            ]);
        }
    }
}
