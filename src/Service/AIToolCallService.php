<?php

namespace App\Service;

use App\Entity\AiServiceRequest;
use App\Entity\AiServiceResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Service for handling AI tool calls across different AI providers
 */
class AIToolCallService
{
    public function __construct(
        private readonly SettingsService $settingsService,
        private readonly HttpClientInterface $httpClient,
        private readonly AiGatewayService $aiGatewayService,
        private readonly AiServiceRequestService $aiServiceRequestService,
        private readonly AiToolService $aiToolService,
        private readonly AIToolFileService $aiToolFileService
    ) {
    }
    
    /**
     * Get available tools definitions
     */
    public function getToolsDefinitions(): array
    {
        // Get tools from database (only active tools)
        //$tools = $this->aiToolService->getToolDefinitions();
        
        // If no tools are defined in the database, provide default tools
        if (empty($tools)) {
            // Define default tools in a provider-agnostic format
            $tools = [];
            
            // CitadelQuest Weather - mock weather data
            $tools['getWeather'] = [
                'name' => 'getWeather',
                'description' => 'Get the current weather in a given location',
                'parameters' => [
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
                ],
            ];

            // CitadelQuest User Profile - update description
            $tools['updateUserProfile'] = [
                'name' => 'updateUserProfile',
                'description' => 'Update the user profile description by adding new information. When user tell you something new about him/her, some interesting or important fact, etc., you should add it to the profile description, so it is available for you to use in future conversations.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'newInfo' => [
                            'type' => 'string',
                            'description' => 'The new information added to the profile description',
                        ],
                    ],
                    'required' => ['newInfo'],
                ],
            ];
            
            // Project File Management Tools
            
            // List files in a project directory
            $tools['listFiles'] = [
                'name' => 'listFiles',
                'description' => 'List files and directories in a project directory',
                'parameters' => [
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
            ];
            
            // Get file content
            $tools['getFileContent'] = [
                'name' => 'getFileContent',
                'description' => 'Get the content of a file in a project',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'fileId' => [
                            'type' => 'string',
                            'description' => 'The ID of the file',
                        ],
                    ],
                    'required' => ['fileId'],
                ],
            ];
            
            // Create directory
            $tools['createDirectory'] = [
                'name' => 'createDirectory',
                'description' => 'Create a new directory in a project',
                'parameters' => [
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
            ];
            
            // Create file
            $tools['createFile'] = [
                'name' => 'createFile',
                'description' => 'Create a new file in a project with content',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'projectId' => [
                            'type' => 'string',
                            'description' => 'The ID of the project',
                        ],
                        'path' => [
                            'type' => 'string',
                            'description' => 'The directory path where to create the file',
                        ],
                        'name' => [
                            'type' => 'string',
                            'description' => 'The name of the file to create',
                        ],
                        'content' => [
                            'type' => 'string',
                            'description' => 'The content of the file',
                        ],
                        'mimeType' => [
                            'type' => 'string',
                            'description' => 'The MIME type of the file (optional)',
                        ],
                    ],
                    'required' => ['projectId', 'path', 'name', 'content'],
                ],
            ];
            
            // Update file
            $tools['updateFile'] = [
                'name' => 'updateFile',
                'description' => 'Update the content of an existing file in a project',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'fileId' => [
                            'type' => 'string',
                            'description' => 'The ID of the file',
                        ],
                        'content' => [
                            'type' => 'string',
                            'description' => 'The new content of the file',
                        ],
                    ],
                    'required' => ['fileId', 'content'],
                ],
            ];
            
            // Delete file
            $tools['deleteFile'] = [
                'name' => 'deleteFile',
                'description' => 'Delete a file or directory from a project',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'fileId' => [
                            'type' => 'string',
                            'description' => 'The ID of the file or directory to delete',
                        ],
                    ],
                    'required' => ['fileId'],
                ],
            ];
            
            // Get file versions
            $tools['getFileVersions'] = [
                'name' => 'getFileVersions',
                'description' => 'Get the version history of a file',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'fileId' => [
                            'type' => 'string',
                            'description' => 'The ID of the file',
                        ],
                    ],
                    'required' => ['fileId'],
                ],
            ];
            
            // Find file by path
            $tools['findFileByPath'] = [
                'name' => 'findFileByPath',
                'description' => 'Find a file by its project ID, path and name',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'projectId' => [
                            'type' => 'string',
                            'description' => 'The ID of the project',
                        ],
                        'path' => [
                            'type' => 'string',
                            'description' => 'The directory path of the file',
                        ],
                        'name' => [
                            'type' => 'string',
                            'description' => 'The name of the file',
                        ],
                    ],
                    'required' => ['projectId', 'path', 'name'],
                ],
            ];
            
            // Ensure project structure - not available yet
            /*$tools['ensureProjectStructure'] = [
                'name' => 'ensureProjectStructure',
                'description' => 'Ensure the project directory structure exists',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'projectId' => [
                            'type' => 'string',
                            'description' => 'The ID of the project',
                        ],
                    ],
                    'required' => ['projectId'],
                ],
            ];*/
            
            // Save default tools to database
            /* foreach ($tools as $name => $definition) {
                $this->aiToolService->createTool(
                    $name,
                    $definition['description'],
                    $definition['parameters'],
                    true
                );
            } */
        }
        
        return $tools;
    }
    
    /**
     * Execute a specific tool
     */
    public function executeTool(string $toolName, array $arguments, string $lang = 'English'): array
    {
        try {
            $arguments['lang'] = $lang;

            // Check if we have a specific method for this tool
            $methodName = lcfirst($toolName);
            if (method_exists($this, $methodName)) {
                return $this->{$methodName}($arguments);
            }
            
            // For file management tools, delegate to AIToolFileService
            if (in_array($toolName, ['listFiles', 'getFileContent', 'createDirectory', 'createFile', 'updateFile', 'deleteFile', 'getFileVersions', 'findFileByPath', 'ensureProjectStructure'])) {
                return $this->aiToolFileService->{$toolName}($arguments);
            }
            
            // For tools without specific implementations, check if they exist in the database
            $tool = $this->aiToolService->findByName($toolName);
            if ($tool) {
                // Generic tool execution logic could be added here
                // For now, return an error that the tool isn't implemented
                return ['error' => 'Tool found but no implementation available: ' . $toolName];
            }
            
            return ['error' => 'Tool not found or not implemented: ' . $toolName];
        } catch (\Exception $e) {
            return ['error' => 'Error executing tool: ' . $e->getMessage()];
        }
    }
    
    /**
     * Get weather information (mock implementation)
     */
    private function getWeather(array $arguments): array
    {
        if (!isset($arguments['location'])) {
            return ['error' => 'Location is required'];
        }
        
        // random mock weather
        $weather = [
            'temperature' => (10 + rand(-5, 5)) . '°' . (!isset($arguments['unit']) || $arguments['unit']=='celsius' ? 'C' : 'F'),
            'condition' => ['sunny', 'cloudy'][rand(0, 1)],
            'location' => $arguments['location'],
        ];
        return $weather;
    }
    
    /**
     * Update user profile with new information
     */
    private function updateUserProfile(array $arguments): array
    {
        if (!isset($arguments['newInfo'])) {
            return ['error' => 'New information is required'];
        }
        
        // get current profile description
        $currentDescription = $this->settingsService->getSettingValue('profile.description', '');

        // update(save) profile description
        $currentDescription .= ($arguments['newInfo'] ? "\n\n" . $arguments['newInfo'] : '');
        $this->settingsService->setSetting('profile.description', $currentDescription);
        
        // get current profile description newInfo counter
        $newInfoCounter = intval($this->settingsService->getSettingValue('profile.new_info_counter', 0));

        // increment newInfo counter
        $newInfoCounter++;
        $this->settingsService->setSetting('profile.new_info_counter', strval($newInfoCounter));

        // rewrite profile description every N newInfo added
        $rewriteProfileDescriptionEveryN = 7777777;//10;
        if ($newInfoCounter % $rewriteProfileDescriptionEveryN == 0) {
            // make and send new ai service request to rewrite profile description
            try {
                $aiServiceResponse = $this->aiGatewayService->sendRequest(
                    $this->aiServiceRequestService->createRequest(
                        $this->aiGatewayService->getSecondaryAiServiceModel()->getId(),
                        [
                            [
                                'role' => 'system',
                                'content' => "You are a helpful assistant that consolidates/refines user profiles, you are best in your profession, very experienced, always on-point, never miss a detail. {$rewriteProfileDescriptionEveryN} new information has been added to the profile description - so it needs to be consolidated to make it more readable, less repetitive, keep all the important information and do not reduce information. This process is called on regular basis to keep profile description up-to-date and readable - it will grow in size, it's OK. Do not reduce informations. Please respond only with the new profile description - it will be saved in the database. <response-language>{$arguments['lang']}</response-language>"
                            ],
                            [
                                'role' => 'user',
                                'content' => "Consolidate the following profile description: {$currentDescription}"
                            ]
                        ],
                        4000, 0.1, null, []
                    ),
                    'tool_call: updateUserProfile (Profile Description Consolidation)'
                );
                
                // get message from response
                $message = $aiServiceResponse->getMessage();
                $content = $currentDescription;
                if (isset($message['content']) 
                    && is_array($message['content']) 
                    && count($message['content']) > 0 
                    && isset($message['content'][0]['text'])) {

                    $content = $message['content'][0]['text'];
                }

                // update profile description
                $this->settingsService->setSetting('profile.description', $content);

            } catch (\Exception $e) {
                // return error
                return ['error' => 'Error updating profile description: ' . $e->getMessage()];
            }
        }
        
        return ['success' => true];
    }
}
