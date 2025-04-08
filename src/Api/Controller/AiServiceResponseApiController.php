<?php

namespace App\Api\Controller;

use App\Service\AiServiceResponseService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/ai/response')]
#[IsGranted('ROLE_USER')]
class AiServiceResponseApiController extends AbstractController
{
    public function __construct(
        private readonly AiServiceResponseService $aiServiceResponseService
    ) {
    }

    #[Route('', name: 'app_api_ai_service_response_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $limit = $request->query->getInt('limit', 100);
        $responses = $this->aiServiceResponseService->findRecent($this->getUser(), $limit);

        return $this->json([
            'responses' => array_map(fn($resp) => $resp->jsonSerialize(), $responses)
        ]);
    }

    #[Route('', name: 'app_api_ai_service_response_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        
        if (!isset($data['aiServiceRequestId']) || !isset($data['message']) || !is_array($data['message'])) {
            return $this->json(['error' => 'Missing required fields'], Response::HTTP_BAD_REQUEST);
        }

        $response = $this->aiServiceResponseService->createResponse(
            $this->getUser(),
            $data['aiServiceRequestId'],
            $data['message'],
            $data['finishReason'] ?? null,
            $data['inputTokens'] ?? null,
            $data['outputTokens'] ?? null,
            $data['totalTokens'] ?? null
        );

        return $this->json([
            'response' => $response->jsonSerialize()
        ], Response::HTTP_CREATED);
    }

    #[Route('/{id}', name: 'app_api_ai_service_response_get', methods: ['GET'])]
    public function get(string $id): JsonResponse
    {
        $response = $this->aiServiceResponseService->findById($this->getUser(), $id);
        
        if (!$response) {
            return $this->json(['error' => 'Response not found'], Response::HTTP_NOT_FOUND);
        }

        return $this->json([
            'response' => $response->jsonSerialize()
        ]);
    }

    #[Route('/request/{requestId}', name: 'app_api_ai_service_response_by_request', methods: ['GET'])]
    public function getByRequest(string $requestId): JsonResponse
    {
        $response = $this->aiServiceResponseService->findByRequest($this->getUser(), $requestId);
        
        if (!$response) {
            return $this->json(['error' => 'Response not found'], Response::HTTP_NOT_FOUND);
        }

        return $this->json([
            'response' => $response->jsonSerialize()
        ]);
    }

    #[Route('/{id}', name: 'app_api_ai_service_response_delete', methods: ['DELETE'])]
    public function delete(string $id): JsonResponse
    {
        $result = $this->aiServiceResponseService->deleteResponse($this->getUser(), $id);
        
        if (!$result) {
            return $this->json(['error' => 'Response not found'], Response::HTTP_NOT_FOUND);
        }
        
        return $this->json(null, Response::HTTP_NO_CONTENT);
    }
}
