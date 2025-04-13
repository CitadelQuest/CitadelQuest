<?php

namespace App\Service;

use App\Entity\AiGateway;
use App\Entity\AiServiceModel;
use App\Entity\AiServiceRequest;
use App\Entity\AiServiceResponse;

interface AiGatewayInterface
{
    /**
     * Send a request to the AI service
     */
    public function sendRequest(AiGateway $aiGateway, AiServiceModel $model, AiServiceRequest $request): AiServiceResponse;
    
    /**
     * Get available models from the AI service
     */
    public function getAvailableModels(AiGateway $aiGateway): array;
}
