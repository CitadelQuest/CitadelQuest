<?php

namespace App\Api\Controller;

use App\Service\CQShareGroupService;
use App\Service\CQShareService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Psr\Log\LoggerInterface;

/**
 * CQ Share Group Controller — management API endpoints.
 * 
 * @see /docs/features/CQ-SHARE-GROUPS.md
 */
#[IsGranted('ROLE_USER')]
class CQShareGroupController extends AbstractController
{
    public function __construct(
        private readonly CQShareGroupService $groupService,
        private readonly CQShareService $shareService,
        private readonly LoggerInterface $logger
    ) {}

    // ========================================
    // Group CRUD
    // ========================================

    #[Route('/api/share-group', name: 'api_share_group_list', methods: ['GET'])]
    public function listGroups(Request $request): JsonResponse
    {
        try {
            $groups = $this->groupService->listGroups();
            $user = $this->getUser();
            $username = $user?->getUsername() ?? '';
            $withPreview = $request->query->getBoolean('preview', false);

            // Attach items to each group
            foreach ($groups as &$group) {
                $items = $this->groupService->listItems($group['id']);

                // Compute effective display styles (same as listActiveGroupsWithItems)
                foreach ($items as &$item) {
                    $item['effective_display_style'] = $item['display_style'] ?? $item['share_display_style'];
                    $item['effective_description_display_style'] = $item['description_display_style'] ?? $item['share_description_display_style'];
                }
                unset($item);

                if ($withPreview && $user) {
                    $items = $this->shareService->enrichSharesWithPreview($user, $username, $items);
                }
                $group['items'] = $items;
            }
            unset($group);

            return $this->json(['success' => true, 'groups' => $groups]);
        } catch (\Exception $e) {
            $this->logger->error('CQShareGroupController::listGroups error', ['error' => $e->getMessage()]);
            return $this->json(['success' => false, 'message' => $e->getMessage()], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    #[Route('/api/share-group', name: 'api_share_group_create', methods: ['POST'])]
    public function createGroup(Request $request): JsonResponse
    {
        try {
            $data = json_decode($request->getContent(), true);
            if (!$data || empty($data['title'])) {
                return $this->json(['success' => false, 'message' => 'Missing required field: title'], Response::HTTP_BAD_REQUEST);
            }

            $group = $this->groupService->createGroup(
                $data['title'],
                $data['mdi_icon'] ?? 'mdi-folder',
                (int) ($data['scope'] ?? CQShareService::SCOPE_PUBLIC),
                (bool) ($data['show_in_nav'] ?? true),
                $data['url_slug'] ?? null,
                $data['icon_color'] ?? null
            );

            return $this->json(['success' => true, 'group' => $group]);
        } catch (\Exception $e) {
            $this->logger->error('CQShareGroupController::createGroup error', ['error' => $e->getMessage()]);
            return $this->json(['success' => false, 'message' => $e->getMessage()], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    #[Route('/api/share-group/{id}', name: 'api_share_group_update', methods: ['PUT'])]
    public function updateGroup(Request $request, string $id): JsonResponse
    {
        try {
            $existing = $this->groupService->findGroupById($id);
            if (!$existing) {
                return $this->json(['success' => false, 'message' => 'Group not found'], Response::HTTP_NOT_FOUND);
            }

            $data = json_decode($request->getContent(), true);
            if (!$data) {
                return $this->json(['success' => false, 'message' => 'Invalid JSON'], Response::HTTP_BAD_REQUEST);
            }

            $group = $this->groupService->updateGroup($id, $data);
            return $this->json(['success' => true, 'group' => $group]);
        } catch (\Exception $e) {
            $this->logger->error('CQShareGroupController::updateGroup error', ['error' => $e->getMessage()]);
            return $this->json(['success' => false, 'message' => $e->getMessage()], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    #[Route('/api/share-group/{id}', name: 'api_share_group_delete', methods: ['DELETE'])]
    public function deleteGroup(string $id): JsonResponse
    {
        try {
            $existing = $this->groupService->findGroupById($id);
            if (!$existing) {
                return $this->json(['success' => false, 'message' => 'Group not found'], Response::HTTP_NOT_FOUND);
            }

            $this->groupService->deleteGroup($id);
            return $this->json(['success' => true]);
        } catch (\Exception $e) {
            $this->logger->error('CQShareGroupController::deleteGroup error', ['error' => $e->getMessage()]);
            return $this->json(['success' => false, 'message' => $e->getMessage()], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    #[Route('/api/share-group/reorder', name: 'api_share_group_reorder', methods: ['PUT'], priority: 10)]
    public function reorderGroups(Request $request): JsonResponse
    {
        try {
            $data = json_decode($request->getContent(), true);
            if (!$data || !isset($data['ordered_ids']) || !is_array($data['ordered_ids'])) {
                return $this->json(['success' => false, 'message' => 'Missing ordered_ids array'], Response::HTTP_BAD_REQUEST);
            }

            $this->groupService->reorderGroups($data['ordered_ids']);
            return $this->json(['success' => true]);
        } catch (\Exception $e) {
            $this->logger->error('CQShareGroupController::reorderGroups error', ['error' => $e->getMessage()]);
            return $this->json(['success' => false, 'message' => $e->getMessage()], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    // ========================================
    // Group Item CRUD
    // ========================================

    #[Route('/api/share-group/{id}/items', name: 'api_share_group_add_item', methods: ['POST'])]
    public function addItem(Request $request, string $id): JsonResponse
    {
        try {
            $group = $this->groupService->findGroupById($id);
            if (!$group) {
                return $this->json(['success' => false, 'message' => 'Group not found'], Response::HTTP_NOT_FOUND);
            }

            $data = json_decode($request->getContent(), true);
            if (!$data || empty($data['share_id'])) {
                return $this->json(['success' => false, 'message' => 'Missing required field: share_id'], Response::HTTP_BAD_REQUEST);
            }

            $item = $this->groupService->addItem($id, $data['share_id'], $data);
            return $this->json(['success' => true, 'item' => $item]);
        } catch (\Exception $e) {
            $this->logger->error('CQShareGroupController::addItem error', ['error' => $e->getMessage()]);
            return $this->json(['success' => false, 'message' => $e->getMessage()], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    #[Route('/api/share-group/{id}/items/{itemId}', name: 'api_share_group_update_item', methods: ['PUT'])]
    public function updateItem(Request $request, string $id, string $itemId): JsonResponse
    {
        try {
            $item = $this->groupService->findItemById($itemId);
            if (!$item || $item['group_id'] !== $id) {
                return $this->json(['success' => false, 'message' => 'Item not found'], Response::HTTP_NOT_FOUND);
            }

            $data = json_decode($request->getContent(), true);
            if (!$data) {
                return $this->json(['success' => false, 'message' => 'Invalid JSON'], Response::HTTP_BAD_REQUEST);
            }

            $updated = $this->groupService->updateItem($itemId, $data);
            return $this->json(['success' => true, 'item' => $updated]);
        } catch (\Exception $e) {
            $this->logger->error('CQShareGroupController::updateItem error', ['error' => $e->getMessage()]);
            return $this->json(['success' => false, 'message' => $e->getMessage()], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    #[Route('/api/share-group/{id}/items/{itemId}', name: 'api_share_group_delete_item', methods: ['DELETE'])]
    public function deleteItem(string $id, string $itemId): JsonResponse
    {
        try {
            $item = $this->groupService->findItemById($itemId);
            if (!$item || $item['group_id'] !== $id) {
                return $this->json(['success' => false, 'message' => 'Item not found'], Response::HTTP_NOT_FOUND);
            }

            $this->groupService->deleteItem($itemId);
            return $this->json(['success' => true]);
        } catch (\Exception $e) {
            $this->logger->error('CQShareGroupController::deleteItem error', ['error' => $e->getMessage()]);
            return $this->json(['success' => false, 'message' => $e->getMessage()], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    #[Route('/api/share-group/{id}/items/reorder', name: 'api_share_group_reorder_items', methods: ['PUT'], priority: 10)]
    public function reorderItems(Request $request, string $id): JsonResponse
    {
        try {
            $group = $this->groupService->findGroupById($id);
            if (!$group) {
                return $this->json(['success' => false, 'message' => 'Group not found'], Response::HTTP_NOT_FOUND);
            }

            $data = json_decode($request->getContent(), true);
            if (!$data || !isset($data['ordered_ids']) || !is_array($data['ordered_ids'])) {
                return $this->json(['success' => false, 'message' => 'Missing ordered_ids array'], Response::HTTP_BAD_REQUEST);
            }

            $this->groupService->reorderItems($data['ordered_ids']);
            return $this->json(['success' => true]);
        } catch (\Exception $e) {
            $this->logger->error('CQShareGroupController::reorderItems error', ['error' => $e->getMessage()]);
            return $this->json(['success' => false, 'message' => $e->getMessage()], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}
