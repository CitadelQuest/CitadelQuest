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

class GroqGateway implements AiGatewayInterface
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
            throw new \Exception('Groq API key not configured');
        }
        
        // Prepare request data
        $requestData = [
            'model' => $aiServiceModel->getModelSlug(),
            'messages' => $request->getMessages()
        ];
        
        if ($request->getMaxTokens() !== null) {
            $requestData['max_tokens'] = $request->getMaxTokens();
        }
        
        if ($request->getTemperature() !== null) {
            $requestData['temperature'] = $request->getTemperature();
        }
        
        if ($request->getStopSequence() !== null) {
            $requestData['stop'] = [$request->getStopSequence()];
        }
        
        if ($request->getTools() !== null && count($request->getTools()) > 0) {
            $requestData['tools'] = $request->getTools();
            $requestData['tool_choice'] = 'auto';
        }
        
        // Prepare headers
        $headers = [
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . $apiKey
        ];
        
        try {
            // Send request to Groq API
            $response = $this->httpClient->request('POST', $aiGateway->getApiEndpointUrl() . '/chat/completions', [
                'headers' => $headers,
                'json' => $requestData,
            ]);
            
            // Get response data
            $statusCode = $response->getStatusCode(false);
            $responseContent = $response->getContent(false);

            // Parse response data
            $responseData = json_decode($responseContent, true);
            $choices = $responseData['choices'] ?? [];
            $message = $choices[0]['message'] ?? [];
            $finishReason = $choices[0]['finish_reason'] ?? null;
            
            // Create response entity
            $aiServiceResponse = new AiServiceResponse(
                $request->getId(),
                $message,
                $responseData
            );
            
            // Calculate tokens if available in response
            if (isset($responseData['usage'])) {
                $aiServiceResponse->setInputTokens($responseData['usage']['prompt_tokens'] ?? null);
                $aiServiceResponse->setOutputTokens($responseData['usage']['completion_tokens'] ?? null);
                $aiServiceResponse->setTotalTokens($responseData['usage']['total_tokens'] ?? null);
            }
            
            $aiServiceResponse->setFinishReason($finishReason);
            
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
        
        if ($response->getFinishReason() === 'tool_calls') {
            // get all tool calls from fullResponse:
            /*
                "model": "llama-3.3-70b-versatile",
                "choices": [{
                    "index": 0,
                    "message": {
                        "role": "assistant",
                        "tool_calls": [{
                            "id": "call_d5wg",
                            "type": "function",
                            "function": {
                                "name": "get_weather",
                                "arguments": "{\"location\": \"New York, NY\"}"
                            }
                        }]
                    },
                    "logprobs": null,
                    "finish_reason": "tool_calls"
                }],
            */
            $toolCalls = $response->getFullResponse()['choices'][0]['message']['tool_calls'];
            
            $messages = $request->getMessages();
            // add current assistant message
            $messages[] = $response->getFullResponse()['choices'][0]['message'];
            // Process tool_calls
            $toolMessageContents = [];
            foreach ($toolCalls as $toolCall) {
                // Call tool and add result
                $toolMessageContents[] = [
                    'tool_call_id' => $toolCall['id'],
                    'role' => 'tool',
                    'name' => $toolCall['function']['name'],
                    'content' => json_encode($this->callTool($toolCall['function'], $lang))
                ];
            }
            
            // Add tool response message
            $messages[] = count($toolMessageContents) > 1 ? $toolMessageContents : $toolMessageContents[0];
            
            // Create and save the AI service request
            $aiServiceRequest = $this->aiServiceRequestService->createRequest(
                $request->getAiServiceModelId(),
                $messages,
                1000, 0.1, null, $request->getTools()
            );
            
            // Send the request to the AI service
            $aiServiceResponse = $this->aiGatewayService->sendRequest($aiServiceRequest, 'Tool use response [' . $toolCall['function']['name'] . ']');

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
            $arguments = isset($tool['arguments']) ? (is_array($tool['arguments']) ? $tool['arguments'] : json_decode($tool['arguments'], true)) : [];
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
    
    public function getAvailableModels(AiGateway $aiGateway): array
    {
        // Get API key from gateway
        $apiKey = $aiGateway->getApiKey();
        
        if (!$apiKey) {
            throw new \Exception('Groq API key not configured');
        }
        
        // Prepare headers
        $headers = [
            'Authorization' => 'Bearer ' . $apiKey
        ];
        
        try {
            // Fetch models from Groq API
            $response = $this->httpClient->request('GET', $aiGateway->getApiEndpointUrl() . '/openai/v1/models', [
                'headers' => $headers
            ]);
            
            // Parse response
            $responseData = json_decode($response->getContent(), true);
            $models = [];
            
            // Extract models from response
            if (isset($responseData['data']) && is_array($responseData['data'])) {
                foreach ($responseData['data'] as $model) {
                    if (isset($model['id'])) {
                        // Format the model name for display
                        $displayName = $this->formatModelName($model['id']);
                        
                        $models[] = [
                            'id' => $model['id'],
                            'name' => $displayName,
                            'provider' => 'groq'
                        ];
                    }
                }
            }
            
            // If no models were found, return a default list
            if (empty($models)) {
                return $this->getDefaultGroqModels();
            }
            
            return $models;
            
        } catch (\Exception $e) {
            // In case of error, return default models
            return $this->getDefaultGroqModels();
        }
    }
    
    /**
     * Format model ID into a readable name
     */
    private function formatModelName(string $modelId): string
    {
        // Remove any vendor prefixes like 'meta-llama/'
        $name = preg_replace('/^.*\//', '', $modelId);
        
        // Replace hyphens and underscores with spaces
        $name = str_replace(['-', '_'], ' ', $name);
        
        // Capitalize words
        $name = ucwords($name);
        
        // Special case handling for common model names
        $name = str_replace('Llama3', 'Llama 3', $name);
        $name = str_replace('Llama 3 1', 'Llama 3.1', $name);
        $name = str_replace('Llama 3 3', 'Llama 3.3', $name);
        $name = str_replace('Llama 4', 'Llama 4', $name);
        $name = str_replace('Gemma2', 'Gemma 2', $name);
        
        return $name;
    }
    
    /**
     * Get a default list of Groq models in case the API call fails
     */
    private function getDefaultGroqModels(): array
    {
        return [
            ['id' => 'llama-3.3-70b-versatile', 'name' => 'Llama 3.3 Versatile', 'provider' => 'groq'],
            ['id' => 'llama-3.1-8b-instant', 'name' => 'Llama 3.1 Instant', 'provider' => 'groq'],
            ['id' => 'gemma2-9b-it', 'name' => 'Gemma 2', 'provider' => 'groq'],
            ['id' => 'qwen-qwq-32b', 'name' => 'Qwen QWQ', 'provider' => 'groq'],
            ['id' => 'qwen-2.5-coder-32b', 'name' => 'Qwen 2.5 Coder', 'provider' => 'groq'],
            ['id' => 'qwen-2.5-32b', 'name' => 'Qwen 2.5', 'provider' => 'groq'],
            ['id' => 'deepseek-r1-distill-llama-70b', 'name' => 'DeepSeek R1 Distill', 'provider' => 'groq'],
            ['id' => 'meta-llama/llama-4-scout-17b-16e-instruct', 'name' => 'Llama 4 Scout', 'provider' => 'groq'],
            ['id' => 'meta-llama/llama-4-maverick-17b-128e-instruct', 'name' => 'Llama 4 Maverick', 'provider' => 'groq']
        ];
    }
    
    /**
     * Get available tools for the AI service
     */
    public function getAvailableTools(): array
    {
        // Weather tool (demo mock tool)
        $tool_getWeather = [
            'type' => 'function',
            'function' => [
                'name' => 'getWeather',
                'description' => 'Get the current weather in a given location',
                'parameters' => [
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
            ]
        ];

        // CitadelQuest User Profile - update description
        $tool_updateUserProfile = [
            'type' => 'function',
            'function' => [
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
            ],
        ];
        
        return [$tool_getWeather, $tool_updateUserProfile];
    }

}
