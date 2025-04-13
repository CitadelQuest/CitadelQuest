<?php

namespace App\Service;

use App\Entity\AiGateway;
use App\Entity\AiServiceModel;
use App\Entity\AiServiceRequest;
use App\Entity\AiServiceResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class PortkeyAiGateway implements AiGatewayInterface
{
    public function __construct(
        private readonly HttpClientInterface $httpClient
    ) {
    }
    
    public function sendRequest(AiGateway $aiGateway, AiServiceModel $model, AiServiceRequest $request): AiServiceResponse
    {
        // Get API key from gateway
        $apiKey = $aiGateway->getApiKey();
        
        if (!$apiKey) {
            throw new \Exception('Portkey API key not configured');
        }

        if (!$model->getVirtualKey()) {
            throw new \Exception('Portkey virtual key for model ' . $model->getModelName() . ' not configured');
        }
        
        // Prepare request data
        $requestData = [
            'model' => $model->getModelSlug(),
            'messages' => $request->getMessages()
        ];
        if ($request->getMaxTokens() !== null) {
            $requestData['max_tokens'] = $request->getMaxTokens();
        }
        if ($request->getTemperature() !== null) {
            $requestData['temperature'] = $request->getTemperature();
        }
        if ($request->getStopSequence() !== null) {
            $requestData['stop'] = $request->getStopSequence();
        }
        
        // Prepare headers
        $headers = [
            'Content-Type' => 'application/json',
            'x-portkey-api-key' => $apiKey,
            'x-portkey-virtual-key' => $model->getVirtualKey(),
        ];
        
        try {
            // Send request to Portkey API
            $response = $this->httpClient->request('POST', $aiGateway->getApiEndpointUrl() . '/chat/completions', [
                'headers' => $headers,
                'json' => $requestData,
            ]);
            
            // Get response data
            $statusCode = $response->getStatusCode();
            $responseContent = $response->getContent();

            // Get response data
            $responseData = json_decode($responseContent, true);
            $choices = $responseData['choices'] ?? [];
            $message = $choices[0]['message'] ?? [];
            $finishReason = $choices[0]['finish_reason'] ?? null;
            
            // Create response entity
            $aiServiceResponse = new AiServiceResponse(
                $request->getId(),
                $message
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
            // Handle other errors
            $errorResponse = new AiServiceResponse(
                $request->getId(),
                ['error' => $e->getMessage()]
            );
            
            return $errorResponse;
        }
    }
    
    /**
     * Map CitadelQuest model to Portkey provider
     */
    private function mapProviderFromModel(AiServiceModel $model): string
    {
        $modelIdentifier = $model->getModelSlug();
        
        // Map model identifier to provider
        if (strpos($modelIdentifier, 'claude') !== false) {
            return 'anthropic';
        }
        
        if (strpos($modelIdentifier, 'llama') !== false) {
            return 'groq';
        }
        
        if (strpos($modelIdentifier, 'mixtral') !== false) {
            return 'groq';
        }
        
        if (strpos($modelIdentifier, 'gpt') !== false) {
            return 'openai';
        }
        
        // Default to openai if unknown
        return 'openai';
    }
    
    public function getAvailableModels(AiGateway $aiGateway): array
    {
        // For Portkey, we'll return a predefined list of common models, same as in AI Settings:
        /*
        // Set default slug based on selection
            if (selectedModel === 'Claude 3.7 Sonnet') {
                modelSlugInput.value = 'claude-3-7-sonnet-20250219';
            } else if (selectedModel === 'Claude 3.5 Haiku') {
                modelSlugInput.value = 'claude-3-5-haiku-20241022';
            } else if (selectedModel === 'Claude 3 Opus') {
                modelSlugInput.value = 'claude-3-opus-20240229';
            } else if (selectedModel === 'Claude 3 Sonnet') {
                modelSlugInput.value = 'claude-3-sonnet-20240229';
            } else if (selectedModel === 'Claude 3 Haiku') {
                modelSlugInput.value = 'claude-3-haiku-20240307';
            } else if (selectedModel === 'Claude 2') {
                modelSlugInput.value = 'claude-2.0';
            } else if (selectedModel === 'Llama 4 Scout') {
                modelSlugInput.value = 'meta-llama/llama-4-scout-17b-16e-instruct';
            } else if (selectedModel === 'Llama 4 Maverick') {
                modelSlugInput.value = 'meta-llama/llama-4-maverick-17b-128e-instruct';
            } else if (selectedModel === 'Llama 3.3 Versatile') {
                modelSlugInput.value = 'llama-3.3-70b-versatile';
            } else if (selectedModel === 'Llama 3.1 Instant') {
                modelSlugInput.value = 'llama-3.1-8b-instant';
            } else if (selectedModel === 'Gemma 2') {
                modelSlugInput.value = 'gemma2-9b-it';
            } else if (selectedModel === 'Qwen QWQ') {
                modelSlugInput.value = 'qwen-qwq-32b';
            } else if (selectedModel === 'Qwen 2.5 Coder') {
                modelSlugInput.value = 'qwen-2.5-coder-32b';
            } else if (selectedModel === 'Qwen 2.5') {
                modelSlugInput.value = 'qwen-2.5-32b';
            } else if (selectedModel === 'DeepSeek R1 Distill') {
                modelSlugInput.value = 'deepseek-r1-distill-llama-70b';
            } else {
                modelSlugInput.value = '';
            }
        */
        // In a real implementation, you might want to query Portkey API for available models
        
        return [
            // Anthropic models
            ['id' => 'claude-3-7-sonnet-20250219', 'name' => 'Claude 3.7 Sonnet', 'provider' => 'anthropic'],
            ['id' => 'claude-3-5-haiku-20241022', 'name' => 'Claude 3.5 Haiku', 'provider' => 'anthropic'],
            ['id' => 'claude-3-opus-20240229', 'name' => 'Claude 3 Opus', 'provider' => 'anthropic'],
            ['id' => 'claude-3-sonnet-20240229', 'name' => 'Claude 3 Sonnet', 'provider' => 'anthropic'],
            ['id' => 'claude-3-haiku-20240307', 'name' => 'Claude 3 Haiku', 'provider' => 'anthropic'],
            ['id' => 'claude-2.0', 'name' => 'Claude 2', 'provider' => 'anthropic'],
            
            // Groq models
            ['id' => 'llama-3.3-70b-versatile', 'name' => 'Llama 3.3 Versatile', 'provider' => 'groq'],
            ['id' => 'llama-3.1-8b-instant', 'name' => 'Llama 3.1 Instant', 'provider' => 'groq'],
            ['id' => 'gemma2-9b-it', 'name' => 'Gemma 2', 'provider' => 'groq'],
            ['id' => 'qwen-qwq-32b', 'name' => 'Qwen QWQ', 'provider' => 'groq'],
            ['id' => 'qwen-2.5-coder-32b', 'name' => 'Qwen 2.5 Coder', 'provider' => 'groq'],
            ['id' => 'qwen-2.5-32b', 'name' => 'Qwen 2.5', 'provider' => 'groq'],
            ['id' => 'deepseek-r1-distill-llama-70b', 'name' => 'DeepSeek R1 Distill', 'provider' => 'groq'],
        ];
    }
}
