<?php

declare(strict_types=1);

namespace DoctrineMigrations;

/**
 * Migration: Clean-up deprecated files
 * Compatible with both Doctrine and standalone updater
 */
final class Version20260219145000
{
    public function getDescription(): string
    {
        return 'Clean-up: Remove deprecated files (Diary, crypto, mockups, visual design, old controllers)';
    }

    public function up($connection): void
    {
        if ($connection instanceof \PDO) {
            $this->upPdo($connection);
        } else {
            $this->upDoctrine($connection);
        }
    }

    private function upPdo(\PDO $pdo): void
    {
        $this->cleanupFiles();
    }

    private function upDoctrine($schema): void
    {
        $this->cleanupFiles();
    }

    private function cleanupFiles(): void
    {
        $projectDir = dirname(__DIR__);

        $filesToRemove = [
            '/src/Controller/KeyController.php',
            '/src/Controller/MockupController.php',
            '/src/Controller/UpdateFromGitController.php',
            '/src/Controller/VisualDesignController.php',
            '/src/Service/UserKeyManager.php',
            '/src/Command/AddTestDataCommand.php',
            '/src/Command/AddMoreTestDataCommand.php',
            '/templates/mockups/project_detail_1.html.twig',
            '/templates/mockups/project_detail_2.html.twig',
            '/templates/mockups/project_detail_3.html.twig',
            '/templates/mockups/public_project_1.html.twig',
            '/templates/mockups/public_project_2.html.twig',
            '/templates/mockups/public_project_3.html.twig',
            '/templates/visual_design/background.html.twig',
            '/templates/visual_design/colors.html.twig',
            '/templates/visual_design/components.html.twig',
            '/templates/visual_design/fonts.html.twig',
            '/templates/visual_design/index.html.twig',
            '/assets/images/bg-pattern.svg',
            '/assets/images/bg-b.jpg',
            '/assets/images/bg-glow-flowers-2.jpg',
            '/assets/entries/crypto.js',
            '/assets/js/shared/crypto.js',
            '/src/Api/Controller/DiaryEntryApiController.php',
            '/src/Controller/DiaryController.php',
            '/src/Entity/DiaryEntry.php',
            '/src/Service/DiaryService.php',
            '/assets/styles/components/_diary.scss',
            '/assets/entries/diary.js',
            '/assets/js/features/diary/components/DiaryApiService.js',
            '/assets/js/features/diary/components/DiaryEntryDisplay.js',
            '/assets/js/features/diary/components/DiaryEntryEdit.js',
            '/assets/js/features/diary/components/DiaryEntryNew.js',
            '/assets/js/features/diary/components/DiaryManager.js',
            '/templates/diary/index.html.twig',
            '/templates/diary/_rich_editor.html.twig',
        ];

        $directoriesToRemove = [
            '/templates/mockups',
            '/templates/visual_design',
            '/assets/js/features/diary/components',
            '/assets/js/features/diary',
            '/templates/diary',
        ];

        foreach ($filesToRemove as $file) {
            $fullPath = $projectDir . $file;
            if (file_exists($fullPath) && is_file($fullPath)) {
                @unlink($fullPath);
            }
        }

        foreach ($directoriesToRemove as $dir) {
            $fullPath = $projectDir . $dir;
            if (file_exists($fullPath) && is_dir($fullPath)) {
                $this->removeDirectory($fullPath);
            }
        }
    }

    public function down($connection): void
    {
        if ($connection instanceof \PDO) {
            // Cannot restore removed files - this migration is irreversible
        } elseif (method_exists($this, 'addSql')) {
            // Cannot restore removed files - this migration is irreversible
        }
    }

    private function removeDirectory(string $dir): bool
    {
        if (!is_dir($dir)) {
            return false;
        }

        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                @unlink($path);
            }
        }

        return @rmdir($dir);
    }
}
