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
        private readonly AiServiceRequestService $aiServiceRequestService
    ) {
    }
    
    /**
     * Get available tools definitions
     */
    public function getToolsDefinitions(): array
    {
        // Define tools in a provider-agnostic format
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
        
        return $tools;
    }
    
    /**
     * Execute a specific tool
     */
    public function executeTool(string $toolName, array $arguments, string $lang = 'English'): array
    {
        try {
            $arguments['lang'] = $lang;

            switch ($toolName) {
                case 'getWeather':
                    return $this->getWeather($arguments);
                case 'updateUserProfile':
                    return $this->updateUserProfile($arguments);
                default:
                    return [];
            }
        } catch (\Exception $e) {
            return ['error' => $e->getMessage()];
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
        $rewriteProfileDescriptionEveryN = 10;
        if ($newInfoCounter % $rewriteProfileDescriptionEveryN == 0) {
            // make and send new ai service request to rewrite profile description
            try {
                $aiServiceResponse = $this->aiGatewayService->sendRequest(
                    $this->aiServiceRequestService->createRequest(
                        $this->aiGatewayService->getSecondaryAiServiceModel()->getId(),
                        [
                            [
                                'role' => 'system',
                                'content' => "You are a helpful assistant that consolidates/refines user profiles, you are best in your profession, very experienced, always on-point, never miss a detail. {$rewriteProfileDescriptionEveryN} new information has been added to the profile description - so it needs to be consolidated to make it more readable, less repetitive, keep all the important information. Please respond only with the new profile description - it will be saved in the database. <response-language>{$arguments['lang']}</response-language>"
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
                $this->settingsService->setSetting('profile.description', $aiServiceResponse->getMessage()['content'] ?? $currentDescription);
            } catch (\Exception $e) {
                // return error
                return ['error' => 'Error updating profile description: ' . $e->getMessage()];
            }
        }
        
        return ['success' => true];
    }
}
