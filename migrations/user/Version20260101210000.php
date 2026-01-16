<?php

class UserMigration_20260101210000
{
    public function up(\PDO $db): void
    {
        try {
            // Update imageEditorSpirit tool with simplified parameters (string instead of array)
            // This improves compatibility with various LLM models that struggle with nested array types
            $imageEditorParams = json_encode([
                'type' => 'object',
                'properties' => [
                    'projectId' => [
                        'type' => 'string',
                        'description' => 'Project ID'
                    ],
                    'inputImageFiles' => [
                        'type' => 'string',
                        'description' => 'Input image file/s for smart editing. Full path with name of the input image file. If multiple files - separated by comma'
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
            ]);
            
            $stmt = $db->prepare("UPDATE ai_tool SET parameters = ?, updated_at = ? WHERE name = ?");
            $stmt->execute([$imageEditorParams, date('Y-m-d H:i:s'), 'imageEditorSpirit']);
            
            // Update updateFileEfficient tool - flatten array to single operation per call
            $updateFileParams = json_encode([
                'type' => 'object',
                'properties' => [
                    'projectId' => [
                        'type' => 'string',
                        'description' => 'Project ID'
                    ],
                    'path' => [
                        'type' => 'string',
                        'description' => 'The directory path where the file is located'
                    ],
                    'name' => [
                        'type' => 'string',
                        'description' => 'The name of the file to update'
                    ],
                    'operation' => [
                        'type' => 'string',
                        'enum' => ['replace', 'lineRange', 'append', 'prepend', 'insertAtLine'],
                        'description' => 'Update operation type'
                    ],
                    'find' => [
                        'type' => 'string',
                        'description' => 'Text to find (for replace operation)'
                    ],
                    'replaceWith' => [
                        'type' => 'string',
                        'description' => 'Text to replace with (for replace operation)'
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
                        'description' => 'Content for lineRange, append, prepend, or insertAtLine operations'
                    ]
                ],
                'required' => ['projectId', 'path', 'name', 'operation']
            ]);
            
            $stmt = $db->prepare("UPDATE ai_tool SET parameters = ?, description = ?, updated_at = ? WHERE name = ?");
            $stmt->execute([
                $updateFileParams, 
                'Update file content efficiently. One operation per call for better LLM compatibility. Operations: replace (find/replaceWith), lineRange (startLine/endLine/content), append/prepend/insertAtLine (content). For multiple edits, call multiple times.',
                date('Y-m-d H:i:s'), 
                'updateFileEfficient'
            ]);
            
            // Update manageFile tool - flatten nested objects to flat parameters
            $manageFileParams = json_encode([
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
                    'sourcePath' => [
                        'type' => 'string',
                        'description' => 'Source file path (for copy, rename_move, delete)'
                    ],
                    'sourceName' => [
                        'type' => 'string',
                        'description' => 'Source file/directory name (for copy, rename_move, delete)'
                    ],
                    'destPath' => [
                        'type' => 'string',
                        'description' => 'Destination file path (for create, copy, rename_move)'
                    ],
                    'destName' => [
                        'type' => 'string',
                        'description' => 'Destination file/directory name (for create, copy, rename_move)'
                    ],
                    'content' => [
                        'type' => 'string',
                        'description' => 'File content (required for create operation)'
                    ]
                ],
                'required' => ['projectId', 'operation']
            ]);
            
            $stmt = $db->prepare("UPDATE ai_tool SET parameters = ?, description = ?, updated_at = ? WHERE name = ?");
            $stmt->execute([
                $manageFileParams,
                'Unified file management. Operations: create (destPath/destName/content), copy (sourcePath/sourceName to destPath/destName), rename_move (sourcePath/sourceName to destPath/destName), delete (sourcePath/sourceName).',
                date('Y-m-d H:i:s'),
                'manageFile'
            ]);
        } catch (\Exception $e) {
            // Log the error or handle it as needed
            error_log("Error updating ai_tool parameters: " . $e->getMessage());
        }
    }

    public function down(\PDO $db): void
    {
        // Restore original imageEditorSpirit parameters
        $imageEditorOld = json_encode([
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
        ]);
        
        $stmt = $db->prepare("UPDATE ai_tool SET parameters = ?, updated_at = ? WHERE name = ?");
        $stmt->execute([$imageEditorOld, date('Y-m-d H:i:s'), 'imageEditorSpirit']);
        
        // Restore original updateFileEfficient parameters (with updates array)
        $updateFileOld = json_encode([
            'type' => 'object',
            'properties' => [
                'projectId' => [
                    'type' => 'string',
                    'description' => 'Project ID'
                ],
                'path' => [
                    'type' => 'string',
                    'description' => 'The directory path where to update the file'
                ],
                'name' => [
                    'type' => 'string',
                    'description' => 'The name of the file to update'
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
                            'find' => ['type' => 'string', 'description' => 'Text to find (for replace operation)'],
                            'replace' => ['type' => 'string', 'description' => 'Text to replace with (for replace operation)'],
                            'startLine' => ['type' => 'integer', 'description' => 'Start line number (for lineRange operation, 1-based)'],
                            'endLine' => ['type' => 'integer', 'description' => 'End line number (for lineRange operation, 1-based, inclusive)'],
                            'line' => ['type' => 'integer', 'description' => 'Line number to insert at (for insertAtLine operation, 1-based)'],
                            'content' => ['type' => 'string', 'description' => 'Content to insert/append/prepend/replace']
                        ],
                        'required' => ['operation']
                    ]
                ]
            ],
            'required' => ['projectId', 'path', 'name', 'updates']
        ]);
        
        $stmt = $db->prepare("UPDATE ai_tool SET parameters = ?, description = ?, updated_at = ? WHERE name = ?");
        $stmt->execute([
            $updateFileOld,
            'Update file content efficiently using find/replace operations. Token-efficient alternative to updateFile - only send changed parts. Supports multiple operation types: replace, lineRange, append, prepend, insertAtLine.',
            date('Y-m-d H:i:s'),
            'updateFileEfficient'
        ]);
        
        // Restore original manageFile parameters (with nested objects)
        $manageFileOld = json_encode([
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
                        'path' => ['type' => 'string', 'description' => 'Source file path'],
                        'name' => ['type' => 'string', 'description' => 'Source file/directory name']
                    ],
                    'required' => ['path', 'name']
                ],
                'destination' => [
                    'type' => 'object',
                    'description' => 'Destination file/directory information (required for create, copy, rename_move)',
                    'properties' => [
                        'path' => ['type' => 'string', 'description' => 'Destination file path'],
                        'name' => ['type' => 'string', 'description' => 'Destination file/directory name']
                    ],
                    'required' => ['path', 'name']
                ],
                'content' => [
                    'type' => 'string',
                    'description' => 'File content (required for create operation)'
                ]
            ],
            'required' => ['projectId', 'operation']
        ]);
        
        $stmt = $db->prepare("UPDATE ai_tool SET parameters = ?, description = ?, updated_at = ? WHERE name = ?");
        $stmt->execute([
            $manageFileOld,
            'Unified file management operations. Supports create, copy, rename_move, and delete operations in one tool. More efficient for AI prompting than separate tools.',
            date('Y-m-d H:i:s'),
            'manageFile'
        ]);
    }
}
