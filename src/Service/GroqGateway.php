<?php

namespace App\Service;

use App\Entity\AiGateway;
use App\Entity\AiServiceModel;
use App\Entity\AiServiceRequest;
use App\Entity\AiServiceResponse;
use App\Service\AIToolCallService;
use App\Service\AiServiceRequestService;
use App\Service\AiGatewayService;
use App\Service\SettingsService;
use App\Service\AiServiceModelService;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use App\CitadelVersion;

class GroqGateway implements AiGatewayInterface
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
            'Authorization' => 'Bearer ' . $apiKey,
            'User-Agent' => 'CitadelQuest ' . CitadelVersion::VERSION . ' HTTP Client'
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
    public function handleToolCalls(AiServiceRequest $request, AiServiceResponse $response, string $lang = 'English'): AiServiceResponse
    {
        // Get services
        $aiToolCallService = $this->serviceLocator->get(AIToolCallService::class);
        $aiServiceRequestService = $this->serviceLocator->get(AiServiceRequestService::class);
        $aiGatewayService = $this->serviceLocator->get(AiGatewayService::class);
        
        if ($response->getFinishReason() === 'tool_calls') {
            // get all tool calls from fullResponse
            $toolCalls = $response->getFullResponse()['choices'][0]['message']['tool_calls'];
            
            $messages = $request->getMessages();

            // add current assistant message
            $messages[] = $response->getFullResponse()['choices'][0]['message'];

            // Process tool_calls
            $toolMessageContents = [];
            foreach ($toolCalls as $toolCall) {
                // Call tool and add result using AIToolCallService
                $toolMessageContents[] = [
                    'tool_call_id' => $toolCall['id'],
                    'role' => 'tool',
                    'name' => $toolCall['function']['name'],
                    'content' => json_encode($aiToolCallService->executeTool(
                        $toolCall['function']['name'], 
                        isset($toolCall['function']['arguments']) ? 
                            (is_array($toolCall['function']['arguments']) ? 
                                $toolCall['function']['arguments'] : 
                                json_decode($toolCall['function']['arguments'], true)
                            ) : 
                            [],
                        $lang
                    ))
                ];
            }
            
            // Add tool response message
            $messages[] = count($toolMessageContents) > 1 ? $toolMessageContents : $toolMessageContents[0];
            
            // Create and save the AI service request
            $aiServiceRequest = $aiServiceRequestService->createRequest(
                $request->getAiServiceModelId(),
                $messages,
                1000, 0.1, null, $request->getTools()
            );
            
            // Send the request to the AI service
            $aiServiceResponse = $aiGatewayService->sendRequest($aiServiceRequest, 'Tool use response [' . $toolCall['function']['name'] . ']');

            $aiServiceResponse->setMessage([
                'role' => $aiServiceResponse->getMessage()['role'],
                'content' => "•\n\n" . $aiServiceResponse->getMessage()['content']
            ]);

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
            throw new \Exception('Groq API key not configured');
        }
        
        // Prepare headers
        $headers = [
            'Authorization' => 'Bearer ' . $apiKey,
            'User-Agent' => 'CitadelQuest ' . CitadelVersion::VERSION . ' HTTP Client',
            'Content-Type' => 'application/json',
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
     * Get available tools for Groq/OpenAI
     */
    public function getAvailableTools(): array
    {
        // Use the static getInstance method to avoid circular dependencies
        $aiToolCallService = $this->serviceLocator->get(AIToolCallService::class);
        $toolsBase = $aiToolCallService->getToolsDefinitions();

        // Convert to Groq/OpenAI format - Groq/OpenAI uses 'parameters' directly
        $tools = [];
        foreach ($toolsBase as $toolDef) {
            $tools[] = [
                'type' => 'function',
                'function' => [
                    'name' => $toolDef['name'],
                    'description' => $toolDef['description'],
                    'parameters' => $toolDef['parameters']
                ]
            ];
        }

        return $tools;
    }
}
