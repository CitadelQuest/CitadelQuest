<?php

namespace App\Service;

use App\Entity\AiGateway;
use App\Entity\AiServiceModel;
use App\Entity\AiServiceRequest;
use App\Entity\AiServiceResponse;
use App\Service\AiGatewayService;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class PortkeyAiGateway implements AiGatewayInterface
{
    private AiGatewayService $aiGatewayService;

    public function __construct(
        private readonly HttpClientInterface $httpClient
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
            throw new \Exception('Portkey API key not configured');
        }

        if (!$aiServiceModel->getVirtualKey()) {
            throw new \Exception('Portkey virtual key for model ' . $aiServiceModel->getModelName() . ' not configured');
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
            $requestData['stop'] = $request->getStopSequence();
        }
        /* if ($request->getTools() !== null && count($request->getTools()) > 0) {
            $requestData['tools'] = $request->getTools();
            $requestData['tool_choice'] = 'auto';
        } */
        // all messages must have non-empty content (anthropic)
        foreach ($requestData['messages'] as &$message) {
            if (empty($message['content']) || $message['content'] === '') {
                $message['content'] = '...';
            }
        }
        
        // Prepare headers
        $headers = [
            'Content-Type' => 'application/json',
            'x-portkey-api-key' => $apiKey,
            'x-portkey-virtual-key' => $aiServiceModel->getVirtualKey(),
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
            // Handle other errors
            $errorResponse = new AiServiceResponse(
                $request->getId(),
                ['error' => $e->getMessage()],
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
        
        // Default to empty string if unknown
        return '';
    }
    
    public function getAvailableModels(AiGateway $aiGateway): array
    {
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
    
    /**
     * Get available tools for the AI service
     */
    public function getAvailableTools(): array
    {
        // Portkey sucks at supporting tools, so we'll return []
        return [];
    }

    /**
     * Handle tool calls
     */
    public function handleToolCalls(AiServiceRequest $request, AiServiceResponse $response, AiGatewayService $aiGatewayService, AiServiceRequestService $aiServiceRequestService, string $lang = 'English'): AiServiceResponse
    {
        // Portkey sucks at supporting tools, so we'll return the original response
        return $response;

        /*
        // PortkeyAI Gateway - buggy as f**k, that's why we have own Anthropic gateway and Groq gateway
        $toolCalls = $aiServiceResponse->getMessage()['tool_calls'] ?? [];
        if (!empty($toolCalls)) {
            // Extract the assistant message from the response
            $assistantMessage = [
                'role' => 'assistant',
                'content' => 'Tool calls request comment - will not be visible to user: ' . ($aiServiceResponse->getMessage()['content'] ?? 'Sorry, I could not generate a response :( '.($aiServiceResponse->getMessage()['error'] ?? '')),
                'tool_calls' => $toolCalls,
                'timestamp' => (new \DateTime())->format(\DateTimeInterface::ATOM)
            ];
        
            // Add assistant message to conversation
            $conversation->addMessage($assistantMessage);
        }

        // Process tool_calls
        $i = 1;
        foreach ($toolCalls as $toolCall) {
            // Call tool
            $toolResult = $this->callTool($toolCall['name'], $lang);

            // Handle tool result
            $toolMessage = [
                'role' => 'tool',
                'content' => json_encode($toolResult), // from Portkey Docs, but did not work for anthropic
                'tool_result' => $toolResult, // anthropic specific
                'tool_call_id' => $toolCall['id'],
                'timestamp' => (new \DateTime())->format(\DateTimeInterface::ATOM)
            ];
            $conversation->addMessage($toolMessage);

            // Create and save the AI service request
            $aiServiceRequest = $this->aiServiceRequestService->createRequest(
                $aiServiceModel->getId(),
                $conversation->getMessages(),
                1000, 0.7, null, $tools
            );

            // Create spirit conversation request
            $spiritConversationRequest = new SpiritConversationRequest(
                $conversation->getId(),
                $aiServiceRequest->getId()
            );
            
            // Save the spirit conversation request
            $db->executeStatement(
                'INSERT INTO spirit_conversation_request (id, spirit_conversation_id, ai_service_request_id, created_at) VALUES (?, ?, ?, ?)',
                [
                    $spiritConversationRequest->getId(),
                    $spiritConversationRequest->getSpiritConversationId(),
                    $spiritConversationRequest->getAiServiceRequestId(),
                    $spiritConversationRequest->getCreatedAt()->format('Y-m-d H:i:s')
                ]
            );

            // Send the request to the AI service
            $aiServiceResponse = $this->aiGatewayService->sendRequest($aiServiceRequest, 'Spirit Conversation [tool call ' . $i . '.]');

            $conversation->removeToolCallsAndResultsFromMessages();

            $i++;
        } */
    }
}
