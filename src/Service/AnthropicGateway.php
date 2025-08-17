<?php

namespace App\Service;

use App\Entity\AiGateway;
use App\Entity\AiServiceModel;
use App\Entity\AiServiceRequest;
use App\Entity\AiServiceResponse;
use App\Service\AiToolService;
use App\Service\AIToolCallService;
use App\Service\AiServiceRequestService;
use App\Service\AiGatewayService;
use App\Service\SettingsService;
use App\Service\AiServiceModelService;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class AnthropicGateway implements AiGatewayInterface
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly SettingsService $settingsService,
        private readonly ServiceLocator $serviceLocator
    ) {
    }
    
    /**
     * Send a request to the AI service
     */
    public function sendRequest(AiServiceRequest $request): AiServiceResponse
    {
        // Get services
        $aiServiceModelService = $this->serviceLocator->get(AiServiceModelService::class);
        $aiGatewayService = $this->serviceLocator->get(AiGatewayService::class);
        
        // Get the model
        $aiServiceModel = $aiServiceModelService->findById($request->getAiServiceModelId());
        if (!$aiServiceModel) {
            throw new \Exception('AI Service Model not found');
        }
        // Get the gateway
        $aiGateway = $aiGatewayService->findById($aiServiceModel->getAiGatewayId());
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
    public function handleToolCalls(AiServiceRequest $request, AiServiceResponse $response, string $lang = 'English'): AiServiceResponse
    {
        // Get services
        $aiToolCallService = $this->serviceLocator->get(AIToolCallService::class);
        $aiServiceRequestService = $this->serviceLocator->get(AiServiceRequestService::class);
        $aiGatewayService = $this->serviceLocator->get(AiGatewayService::class);
        
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
            $logCaption = '';
            $toolMessageContents = [];
            foreach ($toolCalls as $toolCall) {
                // Call tool and add result using AIToolCallService
                $toolMessageContents[] = [
                    'type' => 'tool_result',
                    'tool_use_id' => $toolCall['id'],
                    'content' => json_encode($aiToolCallService->executeTool($toolCall['name'], $toolCall['input'] ?? [], $lang))
                ];
                $logCaption .= $toolCall['name'] . ', ';
            }
            
            // Add tool response message
            $toolMessage = [
                'role' => 'user',
                'content' => $toolMessageContents
            ];
            $messages[] = $toolMessage;
            
            // Create and save the AI service request
            $aiServiceRequest = $aiServiceRequestService->createRequest(
                $request->getAiServiceModelId(),
                $messages,
                4000, 0.1, null, $request->getTools()
            );
            
            // Send the request to the AI service
            $aiServiceResponse = $aiGatewayService->sendRequest($aiServiceRequest, 'Tool use response [' . $logCaption . ']');

            // add original response text to aiServiceResponse message['content'][0]['text'] - as first item
            if ($originalResponseText) {
                $fullMessageContent = $aiServiceResponse->getMessage()['content'];
                // check if message content is available - sometimes anthropic returns empty content [] :-/
                if (isset($fullMessageContent[0]) && isset($fullMessageContent[0]['text'])) {
                    $fullMessageContent[0]['text'] = $originalResponseText . "\n<span class='text-cyber' title='AI tool call: " . $logCaption . "'>•</span>\n" . $fullMessageContent[0]['text'];
                } else {
                    $fullMessageContent = [
                        [
                            'type' => 'text',
                            'text' => $originalResponseText . "\n<span class='text-cyber' title='AI tool call: " . $logCaption . "'>•</span>\n"
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
     * Get available tools for Anthropic
     */
    public function getAvailableTools(): array
    {
        $aiToolService = $this->serviceLocator->get(AiToolService::class);
        $toolsBase = $aiToolService->getToolDefinitions();

        // Convert to Anthropic format - Anthropic has a different schema than OpenAI/Groq
        $tools = [];
        foreach ($toolsBase as $toolDef) {
            $tools[] = [
                'name' => $toolDef['name'],
                'description' => $toolDef['description'],
                'input_schema' => $toolDef['parameters']
            ];
        }

        return $tools;
    }
}
