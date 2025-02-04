<?php
/**
 * CitadelQuest Installation Script
 * 
 * This script handles the installation process for CitadelQuest:
 * 1. Environment checks
 * 2. Download and extract release
 * 3. Configure environment
 * 4. Set permissions
 * 5. Install dependencies
 * 6. Set up database
 */

class CitadelQuestInstaller
{
    private string $installDir;
    private string $version = 'v0.1.2-alpha';
    private string $releaseUrl;
    private array $requirements = [
        'php' => '8.2.0',
        'extensions' => ['pdo_sqlite', 'json', 'curl', 'zip']
    ];

    public function __construct()
    {
        $this->installDir = dirname(__FILE__);
        $this->releaseUrl = "https://github.com/CitadelQuest/CitadelQuest/archive/refs/tags/{$this->version}.tar.gz";
    }

    public function install(): void
    {
        try {
            $this->showHeader();
            $this->checkRequirements();
            $this->downloadAndExtract();
            $this->configureEnvironment();
            $this->setPermissions();
            $this->installDependencies();
            $this->setupDatabase();
            $this->cleanup();
            $this->showSuccess();
        } catch (Exception $e) {
            $this->showError($e->getMessage());
        }
    }

    private function showHeader(): void
    {
        echo "=================================\n";
        echo "CitadelQuest Installation Script\n";
        echo "Version: {$this->version}\n";
        echo "=================================\n\n";
    }

    private function checkRequirements(): void
    {
        echo "Checking requirements...\n";
        
        // Check PHP version
        if (version_compare(PHP_VERSION, $this->requirements['php'], '<')) {
            throw new Exception("PHP version {$this->requirements['php']} or higher required. Current: " . PHP_VERSION);
        }

        // Check extensions
        foreach ($this->requirements['extensions'] as $ext) {
            if (!extension_loaded($ext)) {
                throw new Exception("Required PHP extension missing: {$ext}");
            }
        }

        // Check if Composer is available globally or as composer.phar
        exec('composer --version 2>/dev/null', $output, $returnVar);
        $hasGlobalComposer = ($returnVar === 0);
        
        exec('php composer.phar --version 2>/dev/null', $output, $returnVar);
        $hasLocalComposer = ($returnVar === 0);
        
        if (!$hasGlobalComposer && !$hasLocalComposer) {
            throw new Exception(
                "Composer not found. Please either:\n" .
                "1. Install Composer globally (https://getcomposer.org/download/)\n" .
                "2. OR download composer.phar to the same directory as install.php:\n" .
                "   curl -sS https://getcomposer.org/installer | php"
        }

        echo "✓ All requirements met\n\n";
    }

    private function downloadAndExtract(): void
    {
        echo "Downloading CitadelQuest {$this->version}...\n";
        
        $tempFile = tempnam(sys_get_temp_dir(), 'cq_');
        if (!file_put_contents($tempFile, file_get_contents($this->releaseUrl))) {
            throw new Exception("Failed to download release");
        }

        echo "Extracting files...\n";
        $zip = new ZipArchive;
        if ($zip->open($tempFile) !== true) {
            throw new Exception("Failed to open downloaded archive");
        }
        $zip->extractTo($this->installDir);
        $zip->close();
        unlink($tempFile);

        // Move files from extracted directory
        $extractedDir = $this->installDir . "/CitadelQuest-" . substr($this->version, 1);
        $this->recursiveCopy($extractedDir, $this->installDir);
        $this->recursiveRmdir($extractedDir);

        echo "✓ Files extracted successfully\n\n";
    }

    private function configureEnvironment(): void
    {
        echo "Configuring environment...\n";
        
        $envContent = <<<EOT
APP_ENV=prod
APP_DEBUG=0
APP_SECRET=
DATABASE_PATH=%kernel.project_dir%/var/data
EOT;

        // Generate random APP_SECRET
        $appSecret = bin2hex(random_bytes(16));
        $envContent = str_replace('APP_SECRET=', "APP_SECRET={$appSecret}", $envContent);

        if (!file_put_contents($this->installDir . '/.env.local', $envContent)) {
            throw new Exception("Failed to create .env.local");
        }

        echo "✓ Environment configured\n\n";
    }

    private function setPermissions(): void
    {
        echo "Setting directory permissions...\n";
        
        $dirs = [
            $this->installDir . '/public' => 0755,
            $this->installDir . '/var' => 0777,
            $this->installDir . '/var/data' => 0777
        ];

        foreach ($dirs as $dir => $perm) {
            if (!file_exists($dir)) {
                mkdir($dir, $perm, true);
            }
            chmod($dir, $perm);
        }

        echo "✓ Permissions set\n\n";
    }

    private function installDependencies(): void
    {
        echo "Installing dependencies...\n";
        
        $composerCmd = $hasGlobalComposer ? 'composer' : 'php composer.phar';
        exec("$composerCmd install --no-dev --optimize-autoloader", $output, $returnVar);
        if ($returnVar !== 0) {
            throw new Exception("Failed to install dependencies");
        }

        echo "✓ Dependencies installed\n\n";
    }

    private function setupDatabase(): void
    {
        echo "Setting up database...\n";
        
        // Create database directory if it doesn't exist
        $dbDir = $this->installDir . '/var/data';
        if (!file_exists($dbDir)) {
            mkdir($dbDir, 0777, true);
        }

        // Run database migrations
        exec('php bin/console doctrine:schema:create --force', $output, $returnVar);
        if ($returnVar !== 0) {
            throw new Exception("Failed to create database schema");
        }

        echo "✓ Database setup complete\n\n";
    }

    private function cleanup(): void
    {
        echo "Cleaning up...\n";
        // Remove installation script
        unlink(__FILE__);
        echo "✓ Cleanup complete\n\n";
    }

    private function showSuccess(): void
    {
        echo "=================================\n";
        echo "Installation Complete!\n";
        echo "CitadelQuest has been successfully installed.\n";
        echo "You can now access your installation through your web browser.\n";
        echo "=================================\n";
    }

    private function showError(string $message): void
    {
        echo "\n❌ ERROR: {$message}\n";
        echo "Installation failed. Please fix the error and try again.\n";
        exit(1);
    }

    private function recursiveCopy(string $src, string $dst): void
    {
        $dir = opendir($src);
        if (!file_exists($dst)) {
            mkdir($dst);
        }
        while (($file = readdir($dir))) {
            if ($file != '.' && $file != '..') {
                if (is_dir($src . '/' . $file)) {
                    $this->recursiveCopy($src . '/' . $file, $dst . '/' . $file);
                } else {
                    copy($src . '/' . $file, $dst . '/' . $file);
                }
            }
        }
        closedir($dir);
    }

    private function recursiveRmdir(string $dir): void
    {
        if (is_dir($dir)) {
            $objects = scandir($dir);
            foreach ($objects as $object) {
                if ($object != "." && $object != "..") {
                    if (is_dir($dir . "/" . $object)) {
                        $this->recursiveRmdir($dir . "/" . $object);
                    } else {
                        unlink($dir . "/" . $object);
                    }
                }
            }
            rmdir($dir);
        }
    }
}

// Run the installer
$installer = new CitadelQuestInstaller();
$installer->install();
