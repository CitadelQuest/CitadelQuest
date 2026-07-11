<?php

namespace App\Service;

use App\Entity\Spirit;
use App\Entity\ProjectFile;
use Psr\Log\LoggerInterface;
use Symfony\Component\String\Slugger\SluggerInterface;

/**
 * SpiritSkillService — manages Spirit "Skills": file-based, dynamic persistent
 * context documents for a Spirit.
 *
 * Convention-based structure (under projectId 'general'):
 *   /spirit/{slug}/skill/active/{skillNameSlug}.md      → injected into system prompt
 *   /spirit/{slug}/skill/available/{skillNameSlug}.md   → dormant, not injected
 *
 * All file operations go through ProjectFileService (source of truth for the
 * File Browser, AI Tools, versioning, …). The Spirit itself can grow/refine
 * active skills anytime with the existing `fileUpdate` / `fileManage` AI tools.
 */
class SpiritSkillService
{
    public const PROJECT_ID = 'general';
    public const STATE_ACTIVE = 'active';
    public const STATE_AVAILABLE = 'available';
    public const FILE_EXTENSION = 'md';

    public function __construct(
        private ProjectFileService $projectFileService,
        private SluggerInterface $slugger,
        private LoggerInterface $logger,
    ) {}

    /**
     * Ensure the skill directory structure exists for a Spirit.
     * Idempotent — safe to call repeatedly.
     *
     * @return array{projectId:string, spiritNameSlug:string, skillPath:string, activePath:string, availablePath:string}
     */
    public function initSpiritSkills(Spirit $spirit): array
    {
        $spiritNameSlug = (string) $this->slugger->slug($spirit->getName());
        $skillPath = '/spirit/' . $spiritNameSlug . '/skill';
        $activePath = $skillPath . '/' . self::STATE_ACTIVE;
        $availablePath = $skillPath . '/' . self::STATE_AVAILABLE;

        try {
            $dirs = [
                ['/', 'spirit'],
                ['/spirit', $spiritNameSlug],
                ['/spirit/' . $spiritNameSlug, 'skill'],
                [$skillPath, self::STATE_ACTIVE],
                [$skillPath, self::STATE_AVAILABLE],
            ];

            foreach ($dirs as [$parentPath, $dirName]) {
                if (!$this->projectFileService->findByPathAndName(self::PROJECT_ID, $parentPath, $dirName)) {
                    $this->projectFileService->createDirectory(self::PROJECT_ID, $parentPath, $dirName);
                }
            }
        } catch (\Throwable $e) {
            $this->logger->warning('Failed to initialize Spirit skills', [
                'spiritId' => $spirit->getId(),
                'error' => $e->getMessage(),
            ]);
        }

        return [
            'projectId' => self::PROJECT_ID,
            'spiritNameSlug' => $spiritNameSlug,
            'skillPath' => $skillPath,
            'activePath' => $activePath,
            'availablePath' => $availablePath,
        ];
    }

    /**
     * List all skills for a Spirit, grouped by state.
     *
     * @return array{active: array<int,array>, available: array<int,array>}
     */
    public function listSkills(Spirit $spirit): array
    {
        $paths = $this->initSpiritSkills($spirit);

        return [
            'active' => $this->listSkillsInPath($paths['activePath']),
            'available' => $this->listSkillsInPath($paths['availablePath']),
        ];
    }

    /**
     * @return array<int,array{id:string, name:string, displayName:string, size:int, updatedAt:string}>
     */
    private function listSkillsInPath(string $path): array
    {
        $skills = [];
        try {
            $files = $this->projectFileService->listFiles(self::PROJECT_ID, $path);
            foreach ($files as $file) {
                if ($file->isDirectory()) {
                    continue;
                }
                $name = $file->getName();
                if (!str_ends_with(strtolower($name), '.' . self::FILE_EXTENSION)) {
                    continue;
                }
                $skills[] = [
                    'id' => $file->getId(),
                    'name' => $name,
                    'displayName' => preg_replace('/\.' . preg_quote(self::FILE_EXTENSION, '/') . '$/i', '', $name),
                    'size' => $file->getSize() ?? 0,
                    'updatedAt' => $file->getUpdatedAt()?->format('Y-m-d H:i:s') ?? '',
                ];
            }
        } catch (\Throwable $e) {
            $this->logger->warning('Failed to list Spirit skills', [
                'path' => $path,
                'error' => $e->getMessage(),
            ]);
        }

        usort($skills, fn($a, $b) => strcasecmp($a['displayName'], $b['displayName']));
        return $skills;
    }

    /**
     * Get a skill file's raw content.
     */
    public function getSkillContent(string $fileId): string
    {
        return $this->projectFileService->getFileContent($fileId);
    }

    /**
     * Create a new skill file in the given state folder (default: available).
     */
    public function createSkill(Spirit $spirit, string $name, string $content = '', string $state = self::STATE_AVAILABLE): ProjectFile
    {
        $paths = $this->initSpiritSkills($spirit);
        $targetPath = $state === self::STATE_ACTIVE ? $paths['activePath'] : $paths['availablePath'];

        $fileName = $this->buildSkillFileName($name);

        // Avoid overwriting an existing skill with the same slug
        if ($this->projectFileService->findByPathAndName(self::PROJECT_ID, $targetPath, $fileName)) {
            throw new \RuntimeException('A skill with this name already exists');
        }

        return $this->projectFileService->createFile(
            self::PROJECT_ID,
            $targetPath,
            $fileName,
            $content,
            'text/markdown'
        );
    }

    /**
     * Update a skill file's content.
     */
    public function updateSkillContent(string $fileId, string $content): ProjectFile
    {
        return $this->projectFileService->updateFile($fileId, $content);
    }

    /**
     * Delete a skill file.
     */
    public function deleteSkill(string $fileId): bool
    {
        return $this->projectFileService->delete($fileId);
    }

    /**
     * Move a skill between the 'active' and 'available' folders.
     */
    public function setSkillState(Spirit $spirit, string $fileId, string $state): ProjectFile
    {
        if (!in_array($state, [self::STATE_ACTIVE, self::STATE_AVAILABLE], true)) {
            throw new \InvalidArgumentException('Invalid skill state');
        }

        $file = $this->projectFileService->findById($fileId);
        if (!$file || $file->isDirectory()) {
            throw new \RuntimeException('Skill not found');
        }

        $paths = $this->initSpiritSkills($spirit);
        $targetPath = $state === self::STATE_ACTIVE ? $paths['activePath'] : $paths['availablePath'];

        // Already in target folder — nothing to do
        if ($file->getPath() === $targetPath) {
            return $file;
        }

        $this->projectFileService->moveFile(self::PROJECT_ID, $file, [
            'path' => $targetPath,
            'name' => $file->getName(),
        ]);

        return $this->projectFileService->findById($fileId);
    }

    /**
     * Build the `<spirit-skills>` section injected into the system prompt.
     * Contains every ACTIVE skill's full content plus a short instruction on
     * how the Spirit can grow/refine skills with the fileUpdate tool.
     *
     * Returns an empty string when the Spirit has no active skills.
     */
    public function buildActiveSkillsSection(Spirit $spirit): string
    {
        $paths = $this->initSpiritSkills($spirit);
        $active = $this->listSkillsInPath($paths['activePath']);

        if (empty($active)) {
            return '';
        }

        $blocks = '';
        foreach ($active as $skill) {
            try {
                $content = $this->projectFileService->getFileContent($skill['id']);
            } catch (\Throwable $e) {
                $content = '';
            }
            if (is_string($content) && str_starts_with($content, 'data:')) {
                continue; // skip binary, skills are markdown text
            }
            $filePath = rtrim($paths['activePath'], '/') . '/' . $skill['name'];
            $blocks .= "\n<skill name=\"{$skill['displayName']}\" file=\"cqfile://{$skill['id']}#{$skill['name']}\" path=\"{$filePath}\">\n"
                . $content
                . "\n</skill>\n";
        }

        if ($blocks === '') {
            return '';
        }

        return "

            <spirit-skills>
                (internal note: The following are your active Skills — dynamic persistent context documents that live in your File Browser under `{$paths['activePath']}`. Each skill is a Markdown file you carry across every conversation. Treat them as your accumulated, verified know-how.
                Whenever you learn something new, useful and repeatable during a conversation — a command, parameter, workflow, gotcha, or the user explicitly asks you to remember it — grow the relevant skill file using the `fileUpdate` AI Tool (use `fileManage read` first to see current content). Keep skills accurate, concise and copy-paste ready. Do NOT store one-off/temporary state here.)
                {$blocks}
            </spirit-skills>";
    }

    /**
     * Build a safe skill file name from a user-provided display name.
     */
    private function buildSkillFileName(string $name): string
    {
        $name = trim($name);
        // Strip an existing .md extension if the user typed one
        $name = preg_replace('/\.' . preg_quote(self::FILE_EXTENSION, '/') . '$/i', '', $name);
        $slug = (string) $this->slugger->slug($name);
        if ($slug === '') {
            $slug = 'skill-' . substr(bin2hex(random_bytes(4)), 0, 6);
        }
        return $slug . '.' . self::FILE_EXTENSION;
    }
}
