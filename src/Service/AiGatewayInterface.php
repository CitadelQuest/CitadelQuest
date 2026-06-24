<?php

namespace App\Service;

use App\Entity\AiGateway;
use App\Entity\AiServiceRequest;
use App\Entity\AiServiceResponse;

interface AiGatewayInterface
{
    /**
     * Send a request to the AI service
     */
    public function sendRequest(AiServiceRequest $request): AiServiceResponse;
    
    /**
     * Get available models from the AI service
     */
    public function getAvailableModels(AiGateway $aiGateway): array;
    
    /**
     * Get available tools for the AI service
     */
    public function getAvailableTools(): array;
    
    /**
     * Handle tool calls
     */
    public function handleToolCalls(AiServiceRequest $request, AiServiceResponse $response, string $lang = 'English'): AiServiceResponse;

    /**
     * Start an async job for a single request (non-blocking).
     * Returns job context array for later polling, or null if gateway doesn't support async.
     */
    public function startJob(AiServiceRequest $request): ?array;

    /**
     * Wait for a single job to complete (blocking poll loop).
     */
    public function waitForJob(array $jobContext): array;

    /**
     * Start multiple async jobs simultaneously (non-blocking).
     *
     * @param array<string, AiServiceRequest> $requests [requestId => request]
     * @return array<string, array|null> [requestId => jobContext|null]
     */
    public function startBatch(array $requests): array;

    /**
     * Wait for all batch jobs to complete. Polls all pending jobs in one loop.
     *
     * @param array<string, array> $jobContexts [requestId => jobContext]
     * @return array<string, array> [requestId => responseData]
     */
    public function waitForBatch(array $jobContexts): array;
}
