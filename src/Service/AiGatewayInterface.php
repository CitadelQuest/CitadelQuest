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
}
