<?php

namespace App\Api\Controller;

use App\Service\AiServiceRequestService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/ai/request')]
#[IsGranted('ROLE_USER')]
class AiServiceRequestApiController extends AbstractController
{
    public function __construct(
        private readonly AiServiceRequestService $aiServiceRequestService
    ) {
    }

    #[Route('', name: 'app_api_ai_service_request_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $modelId = $request->query->get('model');
        $limit = $request->query->getInt('limit', 100);
        
        $requests = match(true) {
            $modelId !== null => $this->aiServiceRequestService->findByModel($this->getUser(), $modelId, $limit),
            default => $this->aiServiceRequestService->findRecent($this->getUser(), $limit),
        };

        return $this->json([
            'requests' => array_map(fn($req) => $req->jsonSerialize(), $requests)
        ]);
    }

    #[Route('', name: 'app_api_ai_service_request_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        
        if (!isset($data['aiServiceModelId']) || !isset($data['messages']) || !is_array($data['messages'])) {
            return $this->json(['error' => 'Missing required fields'], Response::HTTP_BAD_REQUEST);
        }

        $aiRequest = $this->aiServiceRequestService->createRequest(
            $this->getUser(),
            $data['aiServiceModelId'],
            $data['messages'],
            $data['maxTokens'] ?? null,
            $data['temperature'] ?? null,
            $data['stopSequence'] ?? null
        );

        return $this->json([
            'request' => $aiRequest->jsonSerialize()
        ], Response::HTTP_CREATED);
    }

    #[Route('/{id}', name: 'app_api_ai_service_request_get', methods: ['GET'])]
    public function get(string $id): JsonResponse
    {
        $aiRequest = $this->aiServiceRequestService->findById($this->getUser(), $id);
        
        if (!$aiRequest) {
            return $this->json(['error' => 'Request not found'], Response::HTTP_NOT_FOUND);
        }

        return $this->json([
            'request' => $aiRequest->jsonSerialize()
        ]);
    }

    #[Route('/{id}', name: 'app_api_ai_service_request_delete', methods: ['DELETE'])]
    public function delete(string $id): JsonResponse
    {
        $result = $this->aiServiceRequestService->deleteRequest($this->getUser(), $id);
        
        if (!$result) {
            return $this->json(['error' => 'Request not found'], Response::HTTP_NOT_FOUND);
        }
        
        return $this->json(null, Response::HTTP_NO_CONTENT);
    }
}
