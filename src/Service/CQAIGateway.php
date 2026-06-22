<?php

namespace App\Service;

use App\Entity\AiGateway;
use App\Entity\AiServiceModel;
use App\Entity\AiServiceRequest;
use App\Entity\AiServiceResponse;
use App\Service\AiToolService;
use App\Service\AIToolCallService;
use App\Service\AiServiceRequestService;
use App\Service\AiServiceResponseService;
use App\Service\AiGatewayService;
use App\Service\SettingsService;
use App\Service\AiServiceModelService;
use App\Service\AiWebhookService;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use App\CitadelVersion;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;

class CQAIGateway implements AiGatewayInterface
{
    private string $apiEndpointUrlPath = '/ai/chat/completions';
    
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly SettingsService $settingsService,
        private readonly ServiceLocator $serviceLocator, 
        private readonly LoggerInterface $logger,
        private readonly Security $security,
        private readonly RequestStack $requestStack
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
        $aiServiceRequestService = $this->serviceLocator->get(AiServiceRequestService::class);
        $aiServiceResponseService = $this->serviceLocator->get(AiServiceResponseService::class);

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
        
        if (!$apiKey || $apiKey === '') {
            throw new \Exception('CQAIGateway API key not configured');
        }

        $filteredMessages = $request->getMessages();

        // Filter out image_url type content from messages
        // for models that do not have image input modality
        $modelFullConfig = $aiServiceModel->getFullConfig();
        $inputModalities = $modelFullConfig['architecture']['input_modalities'] ?? [];
        if ( !in_array('image', $inputModalities) ) {
            $this->filterImageUrlContent($filteredMessages);
        }

        // save filtered messages to request
        $request->setMessages($filteredMessages);
        $aiServiceRequestService->updateRequest($request);
        
        // Prepare request data
        $requestData = [
            'model' => $aiServiceModel->getModelSlug(),
            'messages' => $filteredMessages
        ];
        
        if ($request->getMaxTokens() !== null) {
            $requestData['max_completion_tokens'] = $request->getMaxTokens();
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
            'User-Agent' => 'CitadelQuest ' . CitadelVersion::VERSION . ' HTTP Client',
            'X-CQ-CONTACT-ID' => $this->security->getUser()?->getId()?->toRfc4122() ?? ''
        ];
        
        try {
            $endpointBase = rtrim($aiGateway->getApiEndpointUrl(), '/');

            // Build webhook URL for the gateway to call back when the job is done
            $webhookUrl = $this->buildWebhookUrl();

            // Async start + poll (Cloudflare-524-proof): the slow OpenRouter call runs
            // in a detached gateway worker, while we poll fast/short status requests that
            // never exceed Cloudflare's ~100s proxy window. Falls back to the legacy
            // single synchronous POST if the gateway has not been updated yet.
            $responseData = $this->sendRequestViaJob($endpointBase, $headers, $requestData, $webhookUrl);
            if ($responseData === null) {
                $responseData = $this->sendRequestLegacy($endpointBase . $this->apiEndpointUrlPath, $headers, $requestData);
            }

            // Parse response data
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
                ['error' => $e->getMessage()]
            );
            
            return $errorResponse;
        }
    }

    /**
     * Build the webhook URL that the CQ AI Gateway should call when a background job completes.
     * Uses the current request's scheme + host when available (web context).
     * Falls back to $_SERVER values (set by SpiritChatTurnCommand from turn payload) in CLI context.
     */
    private function buildWebhookUrl(): ?string
    {
        $user = $this->security->getUser();
        if (!$user) {
            return null;
        }

        $request = $this->requestStack->getMainRequest();

        if ($request) {
            $scheme = $request->getScheme();
            $host = $request->getHost();
            $port = $request->getPort();
        } else {
            // CLI context (SpiritChatTurnCommand): $_SERVER is set from turn payload
            $host = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? null;
            if (!$host) {
                return null;
            }
            $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'https';
            $port = 443;
        }

        $username = $user->getUserIdentifier();

        $portPart = '';
        if (($scheme === 'https' && $port !== 443) || ($scheme === 'http' && $port !== 80)) {
            $portPart = ':' . $port;
        }

        return sprintf('%s://%s%s/%s/api/webhook/ai-gateway', $scheme, $host, $portPart, $username);
    }

    /**
     * Async pattern: POST /ai/chat/completions/start to enqueue a background job on the
     * gateway, then poll /ai/chat/completions/status/{jobId} until done. Each HTTP call is
     * short, so the chain is safe against Cloudflare's ~100s 524 timeout even when the
     * underlying OpenRouter generation takes minutes.
     *
     * With webhook support: the gateway POSTs the result back to our webhook endpoint,
     * which stores it in the local DB. We poll the local DB (instant) and only fall back
     * to HTTP status polling every 10s for older gateways that don't support webhooks.
     *
     * @return array|null The completion response array, or null if the gateway does not
     *                     support the async endpoints (caller should fall back to legacy).
     */
    private function sendRequestViaJob(string $endpointBase, array $headers, array $requestData, ?string $webhookUrl = null): ?array
    {
        $startUrl = $endpointBase . $this->apiEndpointUrlPath . '/start';
        $statusUrlBase = $endpointBase . $this->apiEndpointUrlPath . '/status/';

        // Include webhook_url in the start request so the gateway can call us back
        if ($webhookUrl !== null) {
            $requestData['webhook_url'] = $webhookUrl;
        }

        // 1) Start the job
        $startResponse = $this->httpClient->request('POST', $startUrl, [
            'headers' => $headers,
            'json' => $requestData,
            'timeout' => 30,
            'max_duration' => 30
        ]);

        $startStatus = $startResponse->getStatusCode();
        // Older gateway without the async endpoint -> signal legacy fallback
        if ($startStatus === 404) {
            return null;
        }

        $startContent = $startResponse->getContent(false);
        $startJson = json_decode($startContent, true) ?? [];

        if ($startStatus >= 400 || empty($startJson['jobId'])) {
            $err = $startJson['error']['message'] ?? ($startJson['error'] ?? $startContent);
            throw new \Exception('CQ AI Gateway start failed (' . $startStatus . '): ' . (is_string($err) ? $err : json_encode($err)));
        }

        $jobId = $startJson['jobId'];

        // 2) Poll for completion: check local DB first (webhook may have already delivered),
        //    fall back to HTTP status polling every 10s for older gateways without webhook support.
        $aiWebhookService = $this->serviceLocator->get(AiWebhookService::class);

        $maxWaitSeconds = 900;       // 15 min hard ceiling
        $pollIntervalSeconds = 1;
        $httpFallbackInterval = 10;
        $deadline = time() + $maxWaitSeconds;
        $lastHttpPoll = 0;

        while (time() < $deadline) {
            sleep($pollIntervalSeconds);

            // Check local DB — instant, no HTTP round-trip
            $cached = $aiWebhookService->getResult($jobId);
            if ($cached !== null) {
                $aiWebhookService->deleteResult($jobId);
                if ($cached['status'] === 'completed' && $cached['response'] !== null) {
                    return $cached['response'];
                }
                $err = $cached['error'] ?? 'Unknown gateway job error';
                throw new \Exception('CQ AI Gateway job failed: ' . (is_string($err) ? $err : json_encode($err)));
            }

            // HTTP fallback — only every 10s, for older gateways without webhook support
            if (time() - $lastHttpPoll >= $httpFallbackInterval) {
                $lastHttpPoll = time();

                $statusResponse = $this->httpClient->request('GET', $statusUrlBase . $jobId, [
                    'headers' => $headers,
                    'timeout' => 30,
                    'max_duration' => 30
                ]);

                $statusCode = $statusResponse->getStatusCode();
                $statusContent = $statusResponse->getContent(false);
                $statusJson = json_decode($statusContent, true) ?? [];

                if ($statusCode >= 400) {
                    $err = $statusJson['error']['message'] ?? ($statusJson['error'] ?? $statusContent);
                    throw new \Exception('CQ AI Gateway status poll failed (' . $statusCode . '): ' . (is_string($err) ? $err : json_encode($err)));
                }

                if (!empty($statusJson['done'])) {
                    if (isset($statusJson['response'])) {
                        return $statusJson['response'];
                    }
                    $err = $statusJson['error'] ?? 'Unknown gateway job error';
                    throw new \Exception('CQ AI Gateway job failed: ' . (is_string($err) ? $err : json_encode($err)));
                }
            }
        }

        throw new \Exception('CQ AI Gateway job timed out after ' . $maxWaitSeconds . 's (jobId: ' . $jobId . ')');
    }

    /**
     * Legacy single synchronous POST to /ai/chat/completions. Used as a fallback when the
     * gateway does not yet expose the async start/status endpoints.
     */
    private function sendRequestLegacy(string $url, array $headers, array $requestData): array
    {
        $response = $this->httpClient->request('POST', $url, [
            'headers' => $headers,
            'json' => $requestData,
            'timeout' => 600,
            'max_duration' => 600
        ]);

        $responseContent = $response->getContent(false);
        return json_decode($responseContent, true) ?? [];
    }

    /**
     * Currently NOT USED anywhere
     * Handle tool calls
     */
    public function handleToolCalls(AiServiceRequest $request, AiServiceResponse $response, string $lang = 'English'): AiServiceResponse
    {
        $this->logger->error('CQAIGatewayService:::handleToolCalls');

        // Get services
        $aiToolCallService = $this->serviceLocator->get(AIToolCallService::class);
        $aiServiceRequestService = $this->serviceLocator->get(AiServiceRequestService::class);
        $aiGatewayService = $this->serviceLocator->get(AiGatewayService::class);
        $aiServiceModelService = $this->serviceLocator->get(AiServiceModelService::class);
        
        if ($response->getFinishReason() === 'tool_calls') {
            $this->logger->error('CQAIGatewayService:::handleToolCalls called');
            // Get all tool calls from fullResponse
            $toolCalls = $response->getFullResponse()['choices'][0]['message']['tool_calls'];
            
            $messages = $request->getMessages();

            // Filter out image_url type content from messages
            // for models that do not have image input modality
            $aiServiceModel = $aiServiceModelService->findById($request->getAiServiceModelId());
            $modelFullConfig = $aiServiceModel->getFullConfig();
            $inputModalities = $modelFullConfig['architecture']['input_modalities'] ?? [];
            if ( !in_array('image', $inputModalities) ) {
                $this->filterImageUrlContent($messages);
            }

            // Add current assistant message, including tool_calls
            $messages[] = $response->getFullResponse()['choices'][0]['message'];

            // Process tool_calls
            $logCaption = '';
            foreach ($toolCalls as $toolCall) {
                // Call tool and add result using AIToolCallService
                $toolExecutionResult = $aiToolCallService->executeTool(
                        $toolCall['function']['name'], 
                        isset($toolCall['function']['arguments']) ? 
                            (is_array($toolCall['function']['arguments']) ? 
                                $toolCall['function']['arguments'] : 
                                json_decode($toolCall['function']['arguments'], true)
                            ) : 
                            [],
                        $lang
                );                

                // remove _frontendData from toolExecutionResult for AI
                if (isset($toolExecutionResult['_frontendData'])) {
                    unset($toolExecutionResult['_frontendData']);
                }

                $toolMessageContent = [
                    'tool_call_id' => $toolCall['id'],
                    'role' => 'tool',
                    'name' => $toolCall['function']['name'],
                    'content' => json_encode($toolExecutionResult)
                ];
                // Add tool response messages
                $messages[] = $toolMessageContent;

                // Add tool name to log caption
                $logCaption .= $toolMessageContent['name'] . ', ';
            }
            
            // Create and save the AI service request
            $aiServiceRequest = $aiServiceRequestService->createRequest(
                $request->getAiServiceModelId(),
                $messages,
                $request->getMaxTokens(), $request->getTemperature(), null, $request->getTools()
            );
            
            // Send the request to the AI service
            $aiServiceResponse = $aiGatewayService->sendRequest($aiServiceRequest, 'Tool use response [' . $logCaption . ']');

            // Combine full response message: before tool call AI response + injected system data + after tool call AI response
            // > before tool call AI response
            $responseContent_before = isset($response->getFullResponse()['choices'][0]['message']['content']) ? $response->getFullResponse()['choices'][0]['message']['content'] : '';
            
            // > append after tool call AI response
            $responseContent_after = isset($aiServiceResponse->getMessage()['content']) ? $aiServiceResponse->getMessage()['content'] : '';
            // TODO: Gemini 2.5 pro (and others too) - sometimes produces same response + tool call / reproduce, debug, fix
            if ($responseContent_before == $responseContent_after && $responseContent_before != '') {
                $responseContent_after = '<br>-+<br>';
            }

            // Set full response message
            $aiServiceResponse->setMessage([
                'role' => 'assistant',
                'content' => $responseContent_before . $responseContent_after
            ]);

            $response = $aiServiceResponse;
        }
        return $response;
    }

    /**
     * Filter out image_url type content from messages
     */
    public function filterImageUrlContent(array &$messages): void
    {
        foreach ($messages as $key => &$message) {
            if (isset($message['content']) && is_array($message['content'])) {
                $filtered = [];
                $skipNext = false;
                foreach ($message['content'] as $content) {
                    if (isset($content['type']) && $content['type'] === 'image_url') {
                        $skipNext = true;
                        continue;
                    }
                    if ($skipNext) {
                        // skipping the text description that follows the image
                        $skipNext = false;
                        continue;
                    }
                    $filtered[] = $content;
                }
                $message['content'] = $filtered;
            }
        }
        unset($message);
    }

    /**
     * Get available models from the AI service
     */
    public function getAvailableModels(AiGateway $aiGateway): array
    {
        try {
            // Fetch models from CQAIGateway API
            $response = $this->httpClient->request('GET', $aiGateway->getApiEndpointUrl() . '/ai/models', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $aiGateway->getApiKey(),
                    'Content-Type' => 'application/json',
                    'User-Agent' => 'CitadelQuest ' . CitadelVersion::VERSION . ' HTTP Client'
                ]
            ]);
            
            // Parse response
            $responseData = json_decode($response->getContent(), true);
            $models = [];
            
            // Extract models from response
            if (isset($responseData['models']) && is_array($responseData['models'])) {

                // re-order: by 'name' asc
                usort($responseData['models'], function ($a, $b) {
                    return strcasecmp($a['name'], $b['name']);
                });

                foreach ($responseData['models'] as $model) {
                    if (isset($model['id'])) {
                        $model['provider'] = 'cqaigateway';
                        $models[] = $model;
                    }
                }
            }
            
            return $models;
            
        } catch (\Exception $e) {
            // In case of error, return default models
            return [$e->getMessage()];
        }
    }

    /**
     * Get available tools for CQAIGateway
     */
    public function getAvailableTools(): array
    {
        // Use the static getInstance method to avoid circular dependencies
        $aiToolService = $this->serviceLocator->get(AiToolService::class);
        $toolsBase = $aiToolService->getToolDefinitions();

        // Convert to CQAIGateway format
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
