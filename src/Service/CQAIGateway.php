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
     * Send a request to the AI service (synchronous — blocks until response).
     * Uses async start+poll internally for Cloudflare-524 safety, with legacy fallback.
     */
    public function sendRequest(AiServiceRequest $request): AiServiceResponse
    {
        try {
            $prepared = $this->prepareRequest($request);

            $gatewayJobId = $this->startJobFromPrepared($prepared);
            if ($gatewayJobId === null) {
                // Gateway doesn't support async endpoints — legacy synchronous POST
                $responseData = $this->sendRequestLegacy(
                    $prepared['endpointBase'] . $this->apiEndpointUrlPath,
                    $prepared['headers'],
                    $prepared['requestData']
                );
            } else {
                $jobContext = [
                    'gatewayJobId' => $gatewayJobId,
                    'endpointBase' => $prepared['endpointBase'],
                    'headers'      => $prepared['headers'],
                    'webhookUrl'   => $prepared['webhookUrl'],
                    'requestId'    => $request->getId(),
                ];
                $responseData = $this->waitForJob($jobContext);
            }

            return $this->parseResponse($responseData, $request->getId());

        } catch (\Exception $e) {
            $errorResponse = new AiServiceResponse(
                $request->getId(),
                ['error' => $e->getMessage()],
                ['error' => $e->getMessage()]
            );
            return $errorResponse;
        }
    }

    /**
     * Prepare request data for sending to the gateway.
     * Handles model lookup, message filtering, and header/URL construction.
     *
     * @return array{endpointBase: string, headers: array, requestData: array, webhookUrl: ?string}
     */
    private function prepareRequest(AiServiceRequest $request): array
    {
        $aiServiceModelService = $this->serviceLocator->get(AiServiceModelService::class);
        $aiGatewayService = $this->serviceLocator->get(AiGatewayService::class);
        $aiServiceRequestService = $this->serviceLocator->get(AiServiceRequestService::class);

        $aiServiceModel = $aiServiceModelService->findById($request->getAiServiceModelId());
        if (!$aiServiceModel) {
            throw new \Exception('AI Service Model not found');
        }
        $aiGateway = $aiGatewayService->findById($aiServiceModel->getAiGatewayId());
        if (!$aiGateway) {
            throw new \Exception('AI Gateway not found');
        }
        $apiKey = $aiGateway->getApiKey();
        if (!$apiKey || $apiKey === '') {
            throw new \Exception('CQAIGateway API key not configured');
        }

        $filteredMessages = $request->getMessages();
        $modelFullConfig = $aiServiceModel->getFullConfig();
        $inputModalities = $modelFullConfig['architecture']['input_modalities'] ?? [];
        if (!in_array('image', $inputModalities)) {
            $this->filterImageUrlContent($filteredMessages);
        }

        $request->setMessages($filteredMessages);
        $aiServiceRequestService->updateRequest($request);

        $requestData = [
            'model'    => $aiServiceModel->getModelSlug(),
            'messages' => $filteredMessages,
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

        $headers = [
            'Content-Type'     => 'application/json',
            'Authorization'    => 'Bearer ' . $apiKey,
            'User-Agent'       => 'CitadelQuest ' . CitadelVersion::VERSION . ' HTTP Client',
            'X-CQ-CONTACT-ID'  => $this->security->getUser()?->getId()?->toRfc4122() ?? '',
        ];

        $endpointBase = rtrim($aiGateway->getApiEndpointUrl(), '/');
        $webhookUrl = $this->buildWebhookUrl();

        return [
            'endpointBase' => $endpointBase,
            'headers'      => $headers,
            'requestData'  => $requestData,
            'webhookUrl'   => $webhookUrl,
        ];
    }

    /**
     * Start an async job on the gateway (non-blocking).
     * Returns gateway job ID string, or null if the gateway doesn't support async endpoints.
     */
    private function startJobFromPrepared(array $prepared): ?string
    {
        $startUrl = $prepared['endpointBase'] . $this->apiEndpointUrlPath . '/start';
        $requestData = $prepared['requestData'];
        if ($prepared['webhookUrl'] !== null) {
            $requestData['webhook_url'] = $prepared['webhookUrl'];
        }

        $startResponse = $this->httpClient->request('POST', $startUrl, [
            'headers'      => $prepared['headers'],
            'json'         => $requestData,
            'timeout'      => 30,
            'max_duration' => 30,
        ]);

        $startStatus = $startResponse->getStatusCode();
        if ($startStatus === 404) {
            return null; // Gateway doesn't support async — caller falls back to legacy
        }

        $startContent = $startResponse->getContent(false);
        $startJson = json_decode($startContent, true) ?? [];

        if ($startStatus >= 400 || empty($startJson['jobId'])) {
            $err = $startJson['error']['message'] ?? ($startJson['error'] ?? $startContent);
            throw new \Exception('CQ AI Gateway start failed (' . $startStatus . '): ' . (is_string($err) ? $err : json_encode($err)));
        }

        return $startJson['jobId'];
    }

    /**
     * Check a single job for completion (non-blocking, single check).
     * Returns response data array if done, null if still pending.
     */
    private function checkJobOnce(array $jobContext, bool $allowHttpFallback): ?array
    {
        $aiWebhookService = $this->serviceLocator->get(AiWebhookService::class);
        $gatewayJobId = $jobContext['gatewayJobId'];

        // 1) Check local DB — instant, no HTTP round-trip (webhook may have already delivered)
        $cached = $aiWebhookService->getResult($gatewayJobId);
        if ($cached !== null) {
            $aiWebhookService->deleteResult($gatewayJobId);
            if ($cached['status'] === 'completed' && $cached['response'] !== null) {
                return $cached['response'];
            }
            $err = $cached['error'] ?? 'Unknown gateway job error';
            throw new \Exception('CQ AI Gateway job failed: ' . (is_string($err) ? $err : json_encode($err)));
        }

        // 2) HTTP fallback — only for local dev where gateway can't reach the webhook URL
        if ($allowHttpFallback
            && $jobContext['webhookUrl'] !== null
            && str_contains($jobContext['webhookUrl'], '.local')
        ) {
            $statusUrlBase = $jobContext['endpointBase'] . $this->apiEndpointUrlPath . '/status/';
            $statusResponse = $this->httpClient->request('GET', $statusUrlBase . $gatewayJobId, [
                'headers'      => $jobContext['headers'],
                'timeout'      => 30,
                'max_duration' => 30,
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

        return null; // Still pending
    }

    /**
     * Wait for a single job to complete (blocking poll loop).
     */
    public function waitForJob(array $jobContext): array
    {
        $maxWaitSeconds = 900;
        $pollIntervalSeconds = 1;
        $httpFallbackInterval = 10;
        $deadline = time() + $maxWaitSeconds;
        $lastHttpPoll = 0;

        while (time() < $deadline) {
            sleep($pollIntervalSeconds);
            $allowHttp = (time() - $lastHttpPoll) >= $httpFallbackInterval;
            if ($allowHttp) {
                $lastHttpPoll = time();
            }

            $result = $this->checkJobOnce($jobContext, $allowHttp);
            if ($result !== null) {
                return $result;
            }
        }

        throw new \Exception('CQ AI Gateway job timed out after ' . $maxWaitSeconds . 's (jobId: ' . $jobContext['gatewayJobId'] . ')');
    }

    /**
     * Parse raw gateway response data into an AiServiceResponse entity.
     */
    private function parseResponse(array $responseData, string $requestId): AiServiceResponse
    {
        $choices = $responseData['choices'] ?? [];
        $message = $choices[0]['message'] ?? [];
        $finishReason = $choices[0]['finish_reason'] ?? null;

        $aiServiceResponse = new AiServiceResponse($requestId, $message, $responseData);

        if (isset($responseData['usage'])) {
            $aiServiceResponse->setInputTokens($responseData['usage']['prompt_tokens'] ?? null);
            $aiServiceResponse->setOutputTokens($responseData['usage']['completion_tokens'] ?? null);
            $aiServiceResponse->setTotalTokens($responseData['usage']['total_tokens'] ?? null);
        }
        $aiServiceResponse->setFinishReason($finishReason);

        return $aiServiceResponse;
    }

    // ─── Batch API (parallel async calls) ───────────────────────────────────

    /**
     * Start an async job for a single request (non-blocking).
     * Returns job context array for later polling, or null if gateway doesn't support async.
     */
    public function startJob(AiServiceRequest $request): ?array
    {
        $prepared = $this->prepareRequest($request);
        $gatewayJobId = $this->startJobFromPrepared($prepared);
        if ($gatewayJobId === null) {
            return null;
        }
        return [
            'gatewayJobId' => $gatewayJobId,
            'endpointBase' => $prepared['endpointBase'],
            'headers'      => $prepared['headers'],
            'webhookUrl'   => $prepared['webhookUrl'],
            'requestId'    => $request->getId(),
        ];
    }

    /**
     * Start multiple async jobs simultaneously (non-blocking).
     *
     * @param array<string, AiServiceRequest> $requests [requestId => request]
     * @return array<string, array|null> [requestId => jobContext|null]
     */
    public function startBatch(array $requests): array
    {
        $contexts = [];
        foreach ($requests as $requestId => $request) {
            $contexts[$requestId] = $this->startJob($request);
            usleep(90000); // 100 ms delay
        }
        return $contexts;
    }

    /**
     * Wait for all batch jobs to complete. Polls all pending jobs in one loop.
     *
     * @param array<string, array> $jobContexts [requestId => jobContext]
     * @return array<string, array> [requestId => responseData]
     */
    public function waitForBatch(array $jobContexts): array
    {
        $results = [];
        $pending = $jobContexts;
        $maxWaitSeconds = 900;
        $pollIntervalSeconds = 1;
        $httpFallbackInterval = 10;
        $deadline = time() + $maxWaitSeconds;
        $lastHttpPoll = 0;

        while (!empty($pending) && time() < $deadline) {
            sleep($pollIntervalSeconds);
            $allowHttp = (time() - $lastHttpPoll) >= $httpFallbackInterval;
            if ($allowHttp) {
                $lastHttpPoll = time();
            }

            foreach ($pending as $requestId => $jobContext) {
                try {
                    $result = $this->checkJobOnce($jobContext, $allowHttp);
                    if ($result !== null) {
                        $results[$requestId] = $result;
                        unset($pending[$requestId]);
                    }
                } catch (\Exception $e) {
                    // Job failed — record error as response data
                    $results[$requestId] = ['error' => $e->getMessage()];
                    unset($pending[$requestId]);
                }
            }
        }

        // Mark remaining as timed out
        foreach ($pending as $requestId => $ctx) {
            $results[$requestId] = ['error' => 'CQ AI Gateway batch job timed out (jobId: ' . $ctx['gatewayJobId'] . ')'];
        }

        return $results;
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
            // CLI context (SpiritChatTurnCommand): $_SERVER is restored from turn payload
            $host = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? null;
            if (!$host) {
                return null;
            }
            $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $port = (int) ($_SERVER['SERVER_PORT'] ?? ($scheme === 'https' ? 443 : 80));
        }

        $username = $user->getUserIdentifier();

        $portPart = '';
        if (($scheme === 'https' && $port !== 443) || ($scheme === 'http' && $port !== 80)) {
            $portPart = ':' . $port;
        }

        return sprintf('%s://%s%s/%s/api/webhook/ai-gateway', $scheme, $host, $portPart, $username);
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
