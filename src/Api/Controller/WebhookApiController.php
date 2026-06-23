<?php

namespace App\Api\Controller;

use App\Entity\User;
use App\Service\AiWebhookService;
use App\Service\AiGatewayService;
use App\Service\AnnoService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Psr\Log\LoggerInterface;

/**
 * Webhook API Controller
 *
 * Receives webhook callbacks from the CQ AI Gateway when background AI jobs complete.
 * The endpoint is public (no Symfony session auth) but validates the Bearer token
 * against the user's ai_gateway.api_key — the same key used for CitadelQuest → Gateway
 * communication, reused in the reverse direction for webhook authentication.
 */
class WebhookApiController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly AiWebhookService $aiWebhookService,
        private readonly AiGatewayService $aiGatewayService,
        private readonly AnnoService $annoService,
        private readonly LoggerInterface $logger
    ) {
    }

    #[Route('/{username}/api/webhook/ai-gateway', name: 'api_webhook_ai_gateway', methods: ['POST'])]
    public function aiGatewayWebhook(Request $request, string $username): JsonResponse
    {
        try {
            // Validate Authorization header
            $authHeader = $request->headers->get('Authorization');
            if (!$authHeader || !str_starts_with($authHeader, 'Bearer ')) {
                return $this->json(['success' => false, 'error' => 'Missing or invalid authorization header'], Response::HTTP_UNAUTHORIZED);
            }

            $apiKey = substr($authHeader, 7);
            if (empty($apiKey)) {
                return $this->json(['success' => false, 'error' => 'Empty API key'], Response::HTTP_UNAUTHORIZED);
            }

            // Parse request body
            $data = json_decode($request->getContent(), true);
            if (!$data || !isset($data['jobId']) || !isset($data['status'])) {
                return $this->json(['success' => false, 'error' => 'Missing required fields: jobId, status'], Response::HTTP_BAD_REQUEST);
            }

            // Find target user by username
            $user = $this->entityManager->getRepository(User::class)->findOneBy(['username' => $username]);
            if (!$user) {
                return $this->json(['success' => false, 'error' => 'User not found'], Response::HTTP_NOT_FOUND);
            }

            // Validate API key against the user's CQ AI Gateway entry
            $this->aiGatewayService->setUser($user);
            $gateway = $this->aiGatewayService->findByName('CQ AI Gateway');
            if (!$gateway) {
                return $this->json(['success' => false, 'error' => 'Gateway not configured'], Response::HTTP_UNAUTHORIZED);
            }

            if (!hash_equals($gateway->getApiKey(), $apiKey)) {
                $this->logger->warning('WebhookApiController: API key mismatch for user ' . $username);
                return $this->json(['success' => false, 'error' => 'Invalid authorization'], Response::HTTP_UNAUTHORIZED);
            }

            // Store the result in the user's database
            $this->aiWebhookService->setUser($user);
            $this->aiWebhookService->storeResult(
                $data['jobId'],
                $data['status'],
                $data['response'] ?? null,
                $data['error'] ?? null
            );

            // Save annotations to file
            if ( isset($data['response']) && isset($data['response']['annotations']) ) {
                $this->annoService->setUser($user);
                $this->annoService->saveAnnotations($data['response']['annotations']);
            }

            return $this->json(['success' => true]);

        } catch (\Exception $e) {
            $this->logger->error('WebhookApiController::aiGatewayWebhook - Exception', [
                'error' => $e->getMessage(),
                'username' => $username,
            ]);

            return $this->json(['success' => false, 'error' => 'Internal server error'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}
