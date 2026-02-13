<?php

namespace App\Api\Controller;

use App\Service\UpdatesService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/api/updates')]
class UpdatesApiController extends AbstractController
{
    public function __construct(
        private readonly UpdatesService $updatesService
    ) {
    }

    /**
     * Get all updates since a specific timestamp
     * 
     * Query parameters:
     * - since: ISO 8601 timestamp (optional, defaults to 1 minute ago)
     * - openChatId: Currently open chat ID for detailed updates (optional)
     */
    #[Route('', name: 'app_api_updates', methods: ['GET'])]
    public function getUpdates(Request $request): JsonResponse
    {
        try {
            $since = $request->query->get('since', null);
            $openChatId = $request->query->get('openChatId', null);

            // Release session lock early — Memory Job steps make AI calls (3-15s+)
            // No session data needed for updates polling
            $request->getSession()->save();

            $updates = $this->updatesService->getUpdates($since, $openChatId);

            return $this->json($updates);
        } catch (\Exception $e) {
            return $this->json([
                'error' => $e->getMessage()
            ], Response::HTTP_BAD_REQUEST);
        }
    }
}
