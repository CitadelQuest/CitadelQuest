<?php

namespace App\Service;

use App\Entity\AiGateway;
use App\Entity\AiServiceModel;
use App\Entity\AiServiceRequest;
use App\Entity\AiServiceResponse;
use App\Service\AiServiceRequestService;
use App\Service\AiGatewayService;
use App\Service\SettingsService;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class AnthropicGateway implements AiGatewayInterface
{
    private AiGatewayService $aiGatewayService;
    private AiServiceRequestService $aiServiceRequestService;
    
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly SettingsService $settingsService
    ) {
    }
    
    public function sendRequest(AiServiceRequest $request, AiGatewayService $aiGatewayService): AiServiceResponse
    {
        $this->aiGatewayService = $aiGatewayService;
        
        // Get the model
        $aiServiceModel = $this->aiGatewayService->getAiServiceModel($request->getAiServiceModelId());
        if (!$aiServiceModel) {
            throw new \Exception('AI Service Model not found');
        }
        // Get the gateway
        $aiGateway = $this->aiGatewayService->findById($aiServiceModel->getAiGatewayId());
        if (!$aiGateway) {
            throw new \Exception('AI Gateway not found');
        }
        // Get API key from gateway
        $apiKey = $aiGateway->getApiKey();
        
        if (!$apiKey) {
            throw new \Exception('Anthropic API key not configured');
        }
        
        // Anthropic requires system message separated from messages
        // extract first message with role `system` from messages (and remove it from messages)
        $systemMessage = null;
        $messages = [];
        foreach ($request->getMessages() as $message) {
            if ($message['role'] === 'system') {
                $systemMessage = $message['content'];
            } else {
                $messages[] = $message;
            }
        }
        
        // Prepare request data
        $requestData = [
            'model' => $aiServiceModel->getModelSlug(),
            'messages' => $messages,
            'system' => $systemMessage
        ];
        
        if ($request->getMaxTokens() !== null) {
            $requestData['max_tokens'] = $request->getMaxTokens();
        }
        
        if ($request->getTemperature() !== null) {
            $requestData['temperature'] = $request->getTemperature();
        }
        
        if ($request->getStopSequence() !== null) {
            $requestData['stop_sequences'] = [$request->getStopSequence()];
        }
        
        if ($request->getTools() !== null && count($request->getTools()) > 0) {
            $requestData['tools'] = $request->getTools();
            $requestData['tool_choice'] = ['type' => 'auto'];
        }
        
        // Anthropic requires all messages to have non-empty content
        foreach ($requestData['messages'] as &$message) {
            if (empty($message['content']) || $message['content'] === '') {
                $message['content'] = '...';
            }
        }
        
        // Prepare headers
        $headers = [
            'Content-Type' => 'application/json',
            'x-api-key' => $apiKey,
            'anthropic-version' => '2023-06-01'
        ];
        
        try {
            // Send request to Anthropic API
            $response = $this->httpClient->request('POST', $aiGateway->getApiEndpointUrl() . '/v1/messages', [
                'headers' => $headers,
                'json' => $requestData,
            ]);
            
            // Get response data
            // TODO: Check status code + logic
            $statusCode = $response->getStatusCode(false);
            $responseContent = $response->getContent(false);

            // Parse response data
            $responseData = json_decode($responseContent, true);

            // TODO: check if response is valid, type="message"
            /*if (!isset($responseData['type']) || $responseData['type'] !== 'message') {
                if (isset($responseData['error'])) {
                    // throw new \Exception($responseData['error']['message']);
                    // TODO: handle error
                }
                // throw new \Exception('Invalid response: ' . $responseContent);
                // TODO: handle invalid response
            }*/
            
            // Extract message (with tool calls)
            $message = [
                'role' => $responseData['role'],
                'content' => $responseData['content']
            ];
            
            // Create response entity
            $aiServiceResponse = new AiServiceResponse(
                $request->getId(),
                $message,
                $responseData
            );
            
            // Calculate tokens if available in response
            if (isset($responseData['usage'])) {
                $aiServiceResponse->setInputTokens($responseData['usage']['input_tokens'] ?? null);
                $aiServiceResponse->setOutputTokens($responseData['usage']['output_tokens'] ?? null);
                $aiServiceResponse->setTotalTokens(
                    ($responseData['usage']['input_tokens'] ?? 0) + 
                    ($responseData['usage']['output_tokens'] ?? 0)
                );
            }
            
            $aiServiceResponse->setFinishReason($responseData['stop_reason'] ?? null);
            
            return $aiServiceResponse;
            
        } catch (\Exception $e) {
            // Handle errors
            $errorResponse = new AiServiceResponse(
                $request->getId(),
                ['error' => $e->getMessage()],
                ['response' => $e->getMessage()]
            );
            
            return $errorResponse;
        }
    }

    /**
     * Handle tool calls
     */
    public function handleToolCalls(AiServiceRequest $request, AiServiceResponse $response, AiGatewayService $aiGatewayService, AiServiceRequestService $aiServiceRequestService, string $lang = 'English'): AiServiceResponse
    {
        $this->aiGatewayService = $aiGatewayService;
        $this->aiServiceRequestService = $aiServiceRequestService;
        
        if ($response->getFinishReason() === 'tool_use') {
            // get all tool_use content[type="tool_use"] messages
            $toolCalls = array_filter($response->getFullResponse()['content'], fn($message) => $message['type'] === 'tool_use');
            
            $messages = $request->getMessages();
            // add current assistant message
            $messages[] = [
                'role' => 'assistant',
                'content' => $response->getFullResponse()['content']
            ];
            
            // get original response text
            $originalResponseText = array_filter($response->getFullResponse()['content'], fn($message) => $message['type'] === 'text');
            if ($originalResponseText) {
                $originalResponseText = $originalResponseText[0]['text'];
            }

            // Process tool_calls
            $toolMessageContents = [];
            foreach ($toolCalls as $toolCall) {
                // Call tool and add result
                $toolMessageContents[] = [
                    'type' => 'tool_result',
                    'tool_use_id' => $toolCall['id'],
                    'content' => json_encode($this->callTool($toolCall, $lang))
                ];
            }
            
            // Add tool response message
            $toolMessage = [
                'role' => 'user',
                'content' => $toolMessageContents
            ];
            $messages[] = $toolMessage;
            
            // Create and save the AI service request
            $aiServiceRequest = $this->aiServiceRequestService->createRequest(
                $request->getAiServiceModelId(),
                $messages,
                1000, 0.1, null, $request->getTools()
            );
            
            // Send the request to the AI service
            $aiServiceResponse = $this->aiGatewayService->sendRequest($aiServiceRequest, 'Tool use response [' . $toolCall['name'] . ']');

            // add original response text to aiServiceResponse message['content'][0]['text'] - as first item
            if ($originalResponseText) {
                $fullMessageContent = $aiServiceResponse->getMessage()['content'];
                // check if message content is available - sometimes anthropic returns empty content [] :-/
                if (isset($fullMessageContent[0]) && isset($fullMessageContent[0]['text'])) {
                    $fullMessageContent[0]['text'] = $originalResponseText . "\n\n•\n\n" . $fullMessageContent[0]['text'];
                } else {
                    $fullMessageContent = [
                        [
                            'type' => 'text',
                            'text' => $originalResponseText . "\n\n•"
                        ]
                    ];
                }
                $aiServiceResponse->setMessage([
                    'role' => $aiServiceResponse->getMessage()['role'],
                    'content' => $fullMessageContent
                ]);
            }

            $response = $aiServiceResponse;
        }
        return $response;
    }

    /**
     * Call a tool
     */
    private function callTool(array $tool, string $lang): array
    {
        if (!isset($tool['name'])) {
            return [];
        }

        try {
            $arguments = isset($tool['input']) ? (is_array($tool['input']) ? $tool['input'] : json_decode($tool['input'], true)) : [];
            $arguments['lang'] = $lang;

            switch ($tool['name']) {
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

    private function getWeather(array $arguments): array
    {
        // random mock weather
        $weather = [
            'temperature' => (20 + rand(-5, 5)) . '°' . ($arguments['unit']=='celsius' ? 'C' : 'F'),
            'condition' => ['sunny', 'cloudy'][rand(0, 1)],
            'location' => $arguments['location'],
        ];
        return $weather;
    }

    private function updateUserProfile(array $arguments): array
    {
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
                // Log error
                //error_log('Error updating profile description: ' . $e->getMessage());
            }
        }
        
        return ['success' => true];
    }
    
    /**
     * Get available models from the AI service
     */
    public function getAvailableModels(AiGateway $aiGateway): array
    {
        // Get API key from gateway
        $apiKey = $aiGateway->getApiKey();
        
        if (!$apiKey) {
            throw new \Exception('Anthropic API key not configured');
        }
        
        // Prepare headers
        $headers = [
            'x-api-key' => $apiKey,
            'anthropic-version' => '2023-06-01'
        ];
        
        try {
            // Fetch models from Anthropic API
            $response = $this->httpClient->request('GET', $aiGateway->getApiEndpointUrl() . '/v1/models', [
                'headers' => $headers
            ]);
            
            // Parse response
            $responseData = json_decode($response->getContent(), true);
            $models = [];
            
            // Extract models from response
            if (isset($responseData['data']) && is_array($responseData['data'])) {
                foreach ($responseData['data'] as $model) {
                    if (isset($model['id']) && isset($model['display_name'])) {
                        $models[] = [
                            'id' => $model['id'],
                            'name' => $model['display_name'],
                            'provider' => 'anthropic'
                        ];
                    }
                }
            }
            
            // If no models were found, return a default list
            if (empty($models)) {
                return $this->getDefaultAnthropicModels();
            }
            
            return $models;
            
        } catch (\Exception $e) {
            // In case of error, return default models
            return $this->getDefaultAnthropicModels();
        }
    }
    
    /**
     * Get a default list of Anthropic models in case the API call fails
     */
    private function getDefaultAnthropicModels(): array
    {
        return [
            ['id' => 'claude-3-7-sonnet-20250219', 'name' => 'Claude 3.7 Sonnet', 'provider' => 'anthropic'],
            ['id' => 'claude-3-5-haiku-20241022', 'name' => 'Claude 3.5 Haiku', 'provider' => 'anthropic'],
            ['id' => 'claude-3-opus-20240229', 'name' => 'Claude 3 Opus', 'provider' => 'anthropic'],
            ['id' => 'claude-3-sonnet-20240229', 'name' => 'Claude 3 Sonnet', 'provider' => 'anthropic'],
            ['id' => 'claude-3-haiku-20240307', 'name' => 'Claude 3 Haiku', 'provider' => 'anthropic'],
            ['id' => 'claude-2.0', 'name' => 'Claude 2', 'provider' => 'anthropic']
        ];
    }
    
    /**
     * Get available tools for the AI service
     */
    public function getAvailableTools(): array
    {
        // Weather tool (demo mock tool)
        $tool_getWeather = [
            'name' => 'getWeather',
            'description' => 'Get the current weather in a given location',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'location' => [
                        'type' => 'string',
                        'description' => 'The city and state',
                    ],
                    'unit' => [
                        'type' => 'string',
                        'enum' => ['celsius', 'fahrenheit'],
                    ],
                ],
                'required' => ['location'],
            ],
        ];

        // CitadelQuest User Profile - update description
        $tool_updateUserProfile = [
            'name' => 'updateUserProfile',
            'description' => 'Update the user profile description by adding new information. When user tell you something new about him/her, some interesting or important fact, etc., you should add it to the profile description, so it is available for you to use in future conversations.',
            'input_schema' => [
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
        
        return [$tool_getWeather, $tool_updateUserProfile];
    }
}
