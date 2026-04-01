<?php

namespace App\Service;

use Symfony\Component\String\Slugger\SluggerInterface;

/**
 * AI Tool service for CQ Profile management.
 * Provides Spirit tools to manage profile settings, Share Groups and their items.
 */
class AIToolProfileService
{
    public function __construct(
        private readonly CQShareGroupService $shareGroupService,
        private readonly CQShareService $shareService,
        private readonly SluggerInterface $slugger,
        private readonly ProjectFileService $projectFileService,
        private readonly SettingsService $settingsService
    ) {
    }

    /**
     * Manage CQ Profile settings (photo, background, bio, spirit showcase, language, etc.)
     */
    public function cqProfileManage(array $arguments): array
    {
        try {
            $operation = $arguments['operation'] ?? null;
            if (!$operation) {
                throw new \InvalidArgumentException('operation is required. Supported: getSettings, setProfilePhoto, removeProfilePhoto, setBackgroundImage, removeBackgroundImage, setBackgroundOverlay, updateBio, setSpiritShowcase, setLanguage');
            }

            switch ($operation) {
                case 'getSettings':
                    return $this->profileGetSettings();

                case 'setProfilePhoto':
                    return $this->profileSetPhoto($arguments);

                case 'removeProfilePhoto':
                    return $this->profileRemovePhoto();

                case 'setBackgroundImage':
                    return $this->profileSetBackgroundImage($arguments);

                case 'removeBackgroundImage':
                    return $this->profileRemoveBackgroundImage();

                case 'setBackgroundOverlay':
                    return $this->profileSetBackgroundOverlay($arguments);

                case 'updateBio':
                    return $this->profileUpdateBio($arguments);

                case 'setSpiritShowcase':
                    return $this->profileSetSpiritShowcase($arguments);

                case 'setLanguage':
                    return $this->profileSetLanguage($arguments);

                default:
                    throw new \InvalidArgumentException('Unsupported operation: ' . $operation . '. Supported: getSettings, setProfilePhoto, removeProfilePhoto, setBackgroundImage, removeBackgroundImage, setBackgroundOverlay, updateBio, setSpiritShowcase, setLanguage');
            }
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'operation' => $arguments['operation'] ?? 'unknown',
            ];
        }
    }

    private function profileGetSettings(): array
    {
        $settings = $this->settingsService->getAllSettings();
        $profileKeys = [
            'profile.bio',
            'profile.photo_project_file_id',
            'profile.public_page_enabled',
            'profile.public_page_show_photo',
            'profile.public_page_show_profile_content',
            'profile.public_page_show_shares',
            'profile.public_page_show_share_content',
            'profile.public_page_show_spirits',
            'profile.public_page_locale',
            'profile.public_page_theme',
            'profile.public_page_custom_bg_file_id',
            'profile.public_page_bg_overlay',
        ];
        $result = [];
        foreach ($profileKeys as $key) {
            $result[$key] = $settings[$key] ?? null;
        }
        return [
            'success' => true,
            'operation' => 'getSettings',
            'settings' => $result,
        ];
    }

    private function profileSetPhoto(array $arguments): array
    {
        $fileId = $arguments['projectFileId'] ?? null;
        if (!$fileId) {
            throw new \InvalidArgumentException('setProfilePhoto requires projectFileId (ID of an existing image file from getProjectTree/listFiles)');
        }
        $file = $this->projectFileService->findById($fileId);
        if (!$file) {
            throw new \InvalidArgumentException('File not found: ' . $fileId);
        }

        // Delete old photo file if it differs
        $oldPhotoId = $this->settingsService->getSettingValue('profile.photo_project_file_id');
        if ($oldPhotoId && $oldPhotoId !== $fileId) {
            try {
                $this->projectFileService->delete($oldPhotoId);
            } catch (\Exception $e) {
                // Old file may already be gone
            }
        }

        // Save file ID
        $this->settingsService->setSetting('profile.photo_project_file_id', $fileId);

        // Pre-compute and cache absolute paths for fast public serving
        $originalPath = $this->projectFileService->getFileAbsolutePath($fileId);
        $thumbPath = $this->projectFileService->generateThumbnail($fileId);
        $thumbIconPath = $this->projectFileService->generateThumbnailIcon($fileId);
        $this->settingsService->setSetting('profile.public_photo_file_path', $originalPath);
        $this->settingsService->setSetting('profile.public_photo_thumb_file_path', $thumbPath ?: $originalPath);
        $this->settingsService->setSetting('profile.public_photo_thumb_icon_file_path', $thumbIconPath ?: $originalPath);
        $this->settingsService->setSetting('profile.public_photo_last_updated', (new \DateTime())->format('Y-m-d H:i:s'));

        return [
            'success' => true,
            'operation' => 'setProfilePhoto',
            'message' => 'Profile photo set successfully',
            'fileId' => $fileId,
        ];
    }

    private function profileRemovePhoto(): array
    {
        $photoFileId = $this->settingsService->getSettingValue('profile.photo_project_file_id');
        if ($photoFileId) {
            try {
                $this->projectFileService->delete($photoFileId);
            } catch (\Exception $e) {
                // File may already be gone
            }
            $this->settingsService->setSetting('profile.photo_project_file_id', null);
            $this->settingsService->setSetting('profile.public_photo_file_path', null);
            $this->settingsService->setSetting('profile.public_photo_thumb_file_path', null);
            $this->settingsService->setSetting('profile.public_photo_thumb_icon_file_path', null);
            $this->settingsService->setSetting('profile.public_photo_last_updated', null);
        }

        return [
            'success' => true,
            'operation' => 'removeProfilePhoto',
            'message' => 'Profile photo removed',
        ];
    }

    private function profileSetBackgroundImage(array $arguments): array
    {
        $fileId = $arguments['projectFileId'] ?? null;
        if (!$fileId) {
            throw new \InvalidArgumentException('setBackgroundImage requires projectFileId (ID of an existing image file from getProjectTree/listFiles)');
        }
        $file = $this->projectFileService->findById($fileId);
        if (!$file) {
            throw new \InvalidArgumentException('File not found: ' . $fileId);
        }

        // Delete old background file if it differs
        $oldBgId = $this->settingsService->getSettingValue('profile.public_page_custom_bg_file_id');
        if ($oldBgId && $oldBgId !== $fileId) {
            try {
                $this->projectFileService->delete($oldBgId);
            } catch (\Exception $e) {
                // Old file may already be gone
            }
        }

        $this->settingsService->setSetting('profile.public_page_custom_bg_file_id', $fileId);

        return [
            'success' => true,
            'operation' => 'setBackgroundImage',
            'message' => 'Background image set successfully',
            'fileId' => $fileId,
        ];
    }

    private function profileRemoveBackgroundImage(): array
    {
        $bgFileId = $this->settingsService->getSettingValue('profile.public_page_custom_bg_file_id');
        if ($bgFileId) {
            try {
                $this->projectFileService->delete($bgFileId);
            } catch (\Exception $e) {
                // File may already be gone
            }
            $this->settingsService->setSetting('profile.public_page_custom_bg_file_id', null);
        }

        return [
            'success' => true,
            'operation' => 'removeBackgroundImage',
            'message' => 'Background image removed',
        ];
    }

    private function profileSetBackgroundOverlay(array $arguments): array
    {
        $enabled = $arguments['enabled'] ?? null;
        if ($enabled === null) {
            throw new \InvalidArgumentException('setBackgroundOverlay requires enabled (0 or 1)');
        }
        $this->settingsService->setSetting('profile.public_page_bg_overlay', $enabled ? '1' : '0');

        return [
            'success' => true,
            'operation' => 'setBackgroundOverlay',
            'message' => 'Background overlay ' . ($enabled ? 'enabled' : 'disabled'),
            'enabled' => (bool) $enabled,
        ];
    }

    private function profileUpdateBio(array $arguments): array
    {
        $bio = $arguments['bio'] ?? null;
        if ($bio === null) {
            throw new \InvalidArgumentException('updateBio requires bio (text content, supports markdown)');
        }
        $this->settingsService->setSetting('profile.bio', $bio);

        return [
            'success' => true,
            'operation' => 'updateBio',
            'message' => 'Bio updated successfully',
            'length' => strlen($bio),
        ];
    }

    private function profileSetSpiritShowcase(array $arguments): array
    {
        $mode = $arguments['mode'] ?? null;
        if ($mode === null || !in_array((int) $mode, [0, 1, 2], true)) {
            throw new \InvalidArgumentException('setSpiritShowcase requires mode: 0=Off, 1=Primary Spirit only, 2=All Spirits');
        }
        $this->settingsService->setSetting('profile.public_page_show_spirits', (string) $mode);

        $labels = [0 => 'Off', 1 => 'Primary Spirit only', 2 => 'All Spirits'];
        return [
            'success' => true,
            'operation' => 'setSpiritShowcase',
            'message' => 'Spirit showcase set to: ' . $labels[(int) $mode],
            'mode' => (int) $mode,
        ];
    }

    private function profileSetLanguage(array $arguments): array
    {
        $language = $arguments['language'] ?? null;
        $allowed = ['en', 'cs', 'sk', 'es', 'hu', 'pl', 'no', 'it'];
        if (!$language || !in_array($language, $allowed, true)) {
            throw new \InvalidArgumentException('setLanguage requires language: one of ' . implode(', ', $allowed));
        }
        $this->settingsService->setSetting('profile.public_page_locale', $language);

        return [
            'success' => true,
            'operation' => 'setLanguage',
            'message' => 'Profile language set to: ' . $language,
            'language' => $language,
        ];
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
