<?php

namespace App\Api\Controller;

use App\Service\AiServiceUseLogService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/ai/log')]
#[IsGranted('ROLE_USER')]
class AiServiceUseLogApiController extends AbstractController
{
    public function __construct(
        private readonly AiServiceUseLogService $aiServiceUseLogService
    ) {
    }

    #[Route('', name: 'app_api_ai_service_use_log_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $gatewayId = $request->query->get('gateway');
        $modelId = $request->query->get('model');
        $purpose = $request->query->get('purpose');
        $limit = $request->query->getInt('limit', 100);
        
        $logs = match(true) {
            $gatewayId !== null => $this->aiServiceUseLogService->findByGateway($gatewayId, $limit),
            $modelId !== null => $this->aiServiceUseLogService->findByModel($modelId, $limit),
            $purpose !== null => $this->aiServiceUseLogService->findByPurpose($purpose, $limit),
            default => $this->aiServiceUseLogService->findRecent($limit),
        };

        return $this->json([
            'logs' => array_map(fn($log) => $log->jsonSerialize(), $logs)
        ]);
    }

    #[Route('', name: 'app_api_ai_service_use_log_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        
        if (!isset($data['aiGatewayId']) || !isset($data['aiServiceModelId']) || 
            !isset($data['aiServiceRequestId']) || !isset($data['aiServiceResponseId'])) {
            return $this->json(['error' => 'Missing required fields'], Response::HTTP_BAD_REQUEST);
        }

        $log = $this->aiServiceUseLogService->createLog(
            $data['aiGatewayId'],
            $data['aiServiceModelId'],
            $data['aiServiceRequestId'],
            $data['aiServiceResponseId'],
            $data['purpose'] ?? null,
            $data['inputTokens'] ?? null,
            $data['outputTokens'] ?? null,
            $data['totalTokens'] ?? null,
            $data['inputPrice'] ?? null,
            $data['outputPrice'] ?? null,
            $data['totalPrice'] ?? null
        );

        return $this->json([
            'log' => $log->jsonSerialize()
        ], Response::HTTP_CREATED);
    }

    #[Route('/{id}', name: 'app_api_ai_service_use_log_get', methods: ['GET'])]
    public function get(string $id): JsonResponse
    {
        $log = $this->aiServiceUseLogService->findById($id);
        
        if (!$log) {
            return $this->json(['error' => 'Log not found'], Response::HTTP_NOT_FOUND);
        }

        return $this->json([
            'log' => $log->jsonSerialize()
        ]);
    }

    #[Route('/summary', name: 'app_api_ai_service_use_log_summary', methods: ['GET'])]
    public function summary(Request $request): JsonResponse
    {
        $startDate = null;
        $endDate = null;
        
        if ($request->query->has('start')) {
            $startDate = new \DateTime($request->query->get('start'));
        }
        
        if ($request->query->has('end')) {
            $endDate = new \DateTime($request->query->get('end'));
        }
        
        $summary = $this->aiServiceUseLogService->getUsageSummary(
            $startDate,
            $endDate
        );

        return $this->json([
            'summary' => $summary
        ]);
    }

    #[Route('/summary/by-model', name: 'app_api_ai_service_use_log_summary_by_model', methods: ['GET'])]
    public function summaryByModel(Request $request): JsonResponse
    {
        $startDate = null;
        $endDate = null;
        
        if ($request->query->has('start')) {
            $startDate = new \DateTime($request->query->get('start'));
        }
        
        if ($request->query->has('end')) {
            $endDate = new \DateTime($request->query->get('end'));
        }
        
        $summary = $this->aiServiceUseLogService->getUsageByModel(
            $startDate,
            $endDate
        );

        return $this->json([
            'summary' => $summary
        ]);
    }

    #[Route('/{id}', name: 'app_api_ai_service_use_log_delete', methods: ['DELETE'])]
    public function delete(string $id): JsonResponse
    {
        $result = $this->aiServiceUseLogService->deleteLog($id);
        
        if (!$result) {
            return $this->json(['error' => 'Log not found'], Response::HTTP_NOT_FOUND);
        }
        
        return $this->json(null, Response::HTTP_NO_CONTENT);
    }
}
