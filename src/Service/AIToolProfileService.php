<?php

namespace App\Service;

use Symfony\Component\String\Slugger\SluggerInterface;

/**
 * AI Tool service for CQ Profile Content management.
 * Provides Spirit tools to manage Share Groups and their items.
 */
class AIToolProfileService
{
    public function __construct(
        private readonly CQShareGroupService $shareGroupService,
        private readonly CQShareService $shareService,
        private readonly SluggerInterface $slugger,
        private readonly ProjectFileService $projectFileService
    ) {
    }

    /**
     * Manage CQ Profile Share Groups (list, create, update, delete)
     */
    public function cqProfileManageGroup(array $arguments): array
    {
        try {
            $operation = $arguments['operation'] ?? null;
            if (!$operation) {
                throw new \InvalidArgumentException('operation is required. Supported: list, create, update, delete');
            }

            switch ($operation) {
                case 'list':
                    $groups = $this->shareGroupService->listGroups();
                    return [
                        'success' => true,
                        'operation' => 'list',
                        'groups' => $groups,
                        'count' => count($groups),
                    ];

                case 'create':
                    if (empty($arguments['title'])) {
                        throw new \InvalidArgumentException('create requires title');
                    }
                    $group = $this->shareGroupService->createGroup(
                        $arguments['title'],
                        $arguments['mdiIcon'] ?? 'mdi-folder',
                        (int) ($arguments['scope'] ?? CQShareService::SCOPE_PUBLIC),
                        isset($arguments['showInNav']) ? (bool) $arguments['showInNav'] : true,
                        $arguments['urlSlug'] ?? null,
                        $arguments['iconColor'] ?? null
                    );
                    return ['success' => true, 'operation' => 'create', 'group' => $group];

                case 'update':
                    if (empty($arguments['id'])) {
                        throw new \InvalidArgumentException('update requires id');
                    }
                    $group = $this->shareGroupService->findGroupById($arguments['id']);
                    if (!$group) {
                        throw new \InvalidArgumentException('Group not found: ' . $arguments['id']);
                    }
                    $updated = $this->shareGroupService->updateGroup($arguments['id'], $arguments);
                    return ['success' => true, 'operation' => 'update', 'group' => $updated];

                case 'delete':
                    if (empty($arguments['id'])) {
                        throw new \InvalidArgumentException('delete requires id');
                    }
                    $group = $this->shareGroupService->findGroupById($arguments['id']);
                    if (!$group) {
                        throw new \InvalidArgumentException('Group not found: ' . $arguments['id']);
                    }
                    $this->shareGroupService->deleteGroup($arguments['id']);
                    return ['success' => true, 'operation' => 'delete', 'id' => $arguments['id']];

                default:
                    throw new \InvalidArgumentException('Unsupported operation: ' . $operation . '. Supported: list, create, update, delete');
            }
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'operation' => $arguments['operation'] ?? 'unknown',
            ];
        }
    }

    /**
     * Manage items within a CQ Profile Share Group (list, add_file, add_share, remove, update)
     */
    public function cqProfileManageItem(array $arguments): array
    {
        try {
            $operation = $arguments['operation'] ?? null;
            if (!$operation) {
                throw new \InvalidArgumentException('operation is required. Supported: list, add_file, add_share, remove, update');
            }

            switch ($operation) {
                case 'list':
                    if (empty($arguments['groupId'])) {
                        throw new \InvalidArgumentException('list requires groupId');
                    }
                    $items = $this->shareGroupService->listItems($arguments['groupId']);
                    return [
                        'success' => true,
                        'operation' => 'list',
                        'groupId' => $arguments['groupId'],
                        'items' => $items,
                        'count' => count($items),
                    ];

                case 'add_file':
                    if (empty($arguments['groupId']) || empty($arguments['projectFileId'])) {
                        throw new \InvalidArgumentException('add_file requires groupId and projectFileId');
                    }
                    $group = $this->shareGroupService->findGroupById($arguments['groupId']);
                    if (!$group) {
                        throw new \InvalidArgumentException('Group not found: ' . $arguments['groupId']);
                    }
                    $projectFile = $this->projectFileService->findById($arguments['projectFileId']);
                    $fileName = $arguments['fileName'] ?? ($projectFile ? $projectFile->getName() : 'File');
                    $groupScope = (int) ($group['scope'] ?? CQShareService::SCOPE_PUBLIC);

                    // Find or create a CQ Share for this file (same logic as CQ Feed attachments and profile UI)
                    $share = $this->shareService->findActiveBySourceTypeAndSourceId(
                        CQShareService::TYPE_FILE,
                        $arguments['projectFileId']
                    );
                    if (!$share) {
                        $shareUrl = $this->slugger->slug($fileName)->lower()->toString();
                        if (empty($shareUrl)) $shareUrl = 'file';
                        $shareUrl .= '-' . substr(bin2hex(random_bytes(3)), 0, 6);
                        $share = $this->shareService->create(
                            CQShareService::TYPE_FILE,
                            $arguments['projectFileId'],
                            $fileName,
                            $shareUrl,
                            $groupScope
                        );
                    }
                    if (!$share) {
                        throw new \RuntimeException('Failed to create CQ Share for file');
                    }
                    $item = $this->shareGroupService->addItem($arguments['groupId'], $share['id']);
                    return [
                        'success' => true,
                        'operation' => 'add_file',
                        'item' => $item,
                        'share' => $share,
                        'shareCreated' => true,
                    ];

                case 'add_share':
                    if (empty($arguments['groupId']) || empty($arguments['shareId'])) {
                        throw new \InvalidArgumentException('add_share requires groupId and shareId');
                    }
                    $item = $this->shareGroupService->addItem($arguments['groupId'], $arguments['shareId']);
                    return ['success' => true, 'operation' => 'add_share', 'item' => $item];

                case 'remove':
                    if (empty($arguments['itemId'])) {
                        throw new \InvalidArgumentException('remove requires itemId');
                    }
                    $this->shareGroupService->deleteItem($arguments['itemId']);
                    return ['success' => true, 'operation' => 'remove', 'itemId' => $arguments['itemId']];

                case 'update':
                    if (empty($arguments['itemId'])) {
                        throw new \InvalidArgumentException('update requires itemId');
                    }
                    $updated = $this->shareGroupService->updateItem($arguments['itemId'], $arguments);
                    return ['success' => true, 'operation' => 'update', 'item' => $updated];

                default:
                    throw new \InvalidArgumentException('Unsupported operation: ' . $operation . '. Supported: list, add_file, add_share, remove, update');
            }
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'operation' => $arguments['operation'] ?? 'unknown',
            ];
        }
    }
}
