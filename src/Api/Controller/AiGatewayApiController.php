<?php

namespace App\Api\Controller;

use App\Service\AiGatewayService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/ai/gateway')]
#[IsGranted('ROLE_USER')]
class AiGatewayApiController extends AbstractController
{
    public function __construct(
        private readonly AiGatewayService $aiGatewayService
    ) {
    }

    #[Route('', name: 'app_api_ai_gateway_list', methods: ['GET'])]
    public function list(): JsonResponse
    {
        $gateways = $this->aiGatewayService->findAll();

        return $this->json([
            'gateways' => array_map(fn($gateway) => $gateway->jsonSerialize(), $gateways)
        ]);
    }

    #[Route('', name: 'app_api_ai_gateway_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        
        if (!isset($data['name']) || !isset($data['apiKey']) || !isset($data['apiEndpointUrl'])) {
            return $this->json(['error' => 'Missing required fields'], Response::HTTP_BAD_REQUEST);
        }

        $gateway = $this->aiGatewayService->createGateway(
            $data['name'],
            $data['apiKey'],
            $data['apiEndpointUrl']
        );

        return $this->json([
            'gateway' => $gateway->jsonSerialize()
        ], Response::HTTP_CREATED);
    }

    #[Route('/{id}', name: 'app_api_ai_gateway_get', methods: ['GET'])]
    public function get(string $id): JsonResponse
    {
        $gateway = $this->aiGatewayService->findById($id);
        
        if (!$gateway) {
            return $this->json(['error' => 'Gateway not found'], Response::HTTP_NOT_FOUND);
        }

        return $this->json($gateway->jsonSerialize());
    }
    
    #[Route('/{id}/full', name: 'app_api_ai_gateway_get_full', methods: ['GET'])]
    public function getFullDetails(string $id): JsonResponse
    {
        $gateway = $this->aiGatewayService->findById($id);
        
        if (!$gateway) {
            return $this->json(['error' => 'Gateway not found'], Response::HTTP_NOT_FOUND);
        }

        // Return full details including API key for internal use
        return $this->json([
            'id' => $gateway->getId(),
            'name' => $gateway->getName(),
            'apiKey' => $gateway->getApiKey(),
            'apiEndpointUrl' => $gateway->getApiEndpointUrl(),
            'createdAt' => $gateway->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'updatedAt' => $gateway->getUpdatedAt()->format(\DateTimeInterface::ATOM)
        ]);
    }

    #[Route('/{id}', name: 'app_api_ai_gateway_update', methods: ['PUT'])]
    public function update(string $id, Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        
        if (empty($data)) {
            return $this->json(['error' => 'No update data provided'], Response::HTTP_BAD_REQUEST);
        }

        $gateway = $this->aiGatewayService->updateGateway(
            $id,
            $data
        );

        if (!$gateway) {
            return $this->json(['error' => 'Gateway not found'], Response::HTTP_NOT_FOUND);
        }

        return $this->json([
            'gateway' => $gateway->jsonSerialize()
        ]);
    }

    #[Route('/{id}', name: 'app_api_ai_gateway_delete', methods: ['DELETE'])]
    public function delete(string $id): JsonResponse
    {
        $result = $this->aiGatewayService->deleteGateway($id);
        
        if (!$result) {
            return $this->json(['error' => 'Gateway not found'], Response::HTTP_NOT_FOUND);
        }
        
        return $this->json(null, Response::HTTP_NO_CONTENT);
    }
}
