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
    private bool $isWebMode;
    private array $requirements = [
        'php' => '8.2.0',
        'extensions' => ['pdo_sqlite', 'json', 'curl', 'zip']
    ];

    public function __construct()
    {
        // Since we're in the public directory, the install directory should be one level up
        $this->installDir = dirname(__FILE__, 2);
        $this->releaseUrl = "https://github.com/CitadelQuest/CitadelQuest/archive/refs/tags/{$this->version}.tar.gz";
        $this->isWebMode = PHP_SAPI !== 'cli';
        
        if ($this->isWebMode) {
            echo "<!DOCTYPE html>\n";
            echo "<html lang=\"en\">\n";
            echo "<head>\n";
            echo "    <meta charset=\"UTF-8\">\n";
            echo "    <meta name=\"viewport\" content=\"width=device-width, initial-scale=1.0\">\n";
            echo "    <title>CitadelQuest Installer</title>\n";
            echo "    <link href=\"https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css\" rel=\"stylesheet\">\n";
            echo "    <style>\n";
            echo "        .log { font-family: monospace; margin: 0; }\n";
            echo "        .error { color: #dc3545; }\n";
            echo "    </style>\n";
            echo "</head>\n";
            echo "<body class=\"container py-4\">\n";
            echo "    <div class=\"card\">\n";
            echo "        <div class=\"card-body\">\n";
        }
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
        
        if ($this->isWebMode) {
            echo "        </div>\n";
            echo "    </div>\n";
            echo "</body>\n";
            echo "</html>\n";
        }
    }

    private function output(string $message, string $type = 'log'): void
    {
        if ($this->isWebMode) {
            $class = $type === 'error' ? 'log error' : 'log';
            echo "<p class=\"$class\">$message</p>\n";
        } else {
            echo $message . "\n";
        }
    }

    private function showHeader(): void
    {
        if ($this->isWebMode) {
            $this->output("<h2>CitadelQuest Installation</h2>");
            $this->output("<h5 class='text-muted'>Version: {$this->version}</h5>");
            $this->output("<hr>");
        } else {
            $this->output("=================================");
            $this->output("CitadelQuest Installation Script");
            $this->output("Version: {$this->version}");
            $this->output("=================================");
            $this->output("");
        }
    }

    private function checkRequirements(): void
    {
        $this->output("Checking requirements...");
        
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
                "   curl -sS https://getcomposer.org/installer | php");
        }

        $this->output("✓ All requirements met");
    }

    private function downloadAndExtract(): void
    {
        $this->output("Downloading CitadelQuest {$this->version}...");
        
        $tempFile = tempnam(sys_get_temp_dir(), 'cq_');
        if (!file_put_contents($tempFile, file_get_contents($this->releaseUrl))) {
            throw new Exception("Failed to download release");
        }

        $this->output("Extracting files...");
        
        // Create a temporary extraction directory
        $extractDir = $this->installDir . '/tmp_extract';
        if (!is_dir($extractDir)) {
            mkdir($extractDir, 0755, true);
        }
        
        // Extract the tar.gz file
        $command = "tar -xzf " . escapeshellarg($tempFile) . " -C " . escapeshellarg($extractDir);
        exec($command, $output, $returnVar);
        
        if ($returnVar !== 0) {
            throw new Exception("Failed to extract archive. Error: " . implode("\n", $output));
        }
        
        // Clean up the downloaded archive
        unlink($tempFile);
        
        // The extracted files will be in CitadelQuest-{version} subdirectory
        $extractedDir = $extractDir . "/CitadelQuest-" . substr($this->version, 1);
        if (!is_dir($extractedDir)) {
            throw new Exception("Extracted directory not found: $extractedDir");
        }
        
        // Move files to the parent directory (one level up from public)
        $this->recursiveCopy($extractedDir, $this->installDir);
        
        // Clean up the temporary directory
        $this->recursiveRmdir($extractDir);
        
        // Move install.php to its new location in public/
        if (!is_dir($this->installDir . '/public')) {
            mkdir($this->installDir . '/public', 0755, true);
        }
        if (basename(__FILE__) === 'install.php' && dirname(__FILE__) !== $this->installDir . '/public') {
            copy(__FILE__, $this->installDir . '/public/install.php');
        }

        $this->output("✓ Files extracted successfully");
    }

    private function configureEnvironment(): void
    {
        $this->output("Configuring environment...");
        
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

        $this->output("✓ Environment configured");
    }

    private function setPermissions(): void
    {
        $this->output("Setting directory permissions...");
        
        $dirs = [
            $this->installDir . '/public' => 0755,
            $this->installDir . '/var' => 0777,
            $this->installDir . '/var/cache' => 0777,
            $this->installDir . '/var/data' => 0777,
            $this->installDir . '/var/log' => 0777
        ];

        foreach ($dirs as $dir => $perm) {
            if (!file_exists($dir)) {
                mkdir($dir, $perm, true);
            }
            chmod($dir, $perm);
        }

        $this->output("✓ Permissions set");
    }

    private function installDependencies(): void
    {
        $this->output("Installing dependencies...");
        
        // Check if Composer is available globally or as composer.phar
        exec('composer --version 2>/dev/null', $output, $returnVar);
        $hasGlobalComposer = ($returnVar === 0);
        
        $composerCmd = $hasGlobalComposer ? 'composer' : 'php composer.phar';
        // Run composer from the installation directory
        $command = "cd " . escapeshellarg($this->installDir) . " && $composerCmd install --no-dev --optimize-autoloader 2>&1";
        exec($command, $output, $returnVar);
        if ($returnVar !== 0) {
            throw new Exception("Failed to install dependencies");
        }

        $this->output("✓ Dependencies installed");
    }

    private function setupDatabase(): void
    {
        $this->output("Setting up database...");
        
        // Create database directory if it doesn't exist
        $dbDir = $this->installDir . '/var/data';
        if (!file_exists($dbDir)) {
            mkdir($dbDir, 0777, true);
        }

        // Run database migrations from the installation directory
        $command = "cd " . escapeshellarg($this->installDir) . " && php bin/console doctrine:schema:create --force 2>&1";
        exec($command, $output, $returnVar);
        if ($returnVar !== 0) {
            throw new Exception("Failed to create database schema");
        }

        $this->output("✓ Database setup complete");
    }

    private function cleanup(): void
    {
        $this->output("Cleaning up...");
        // Remove installation script
        unlink(__FILE__);
        $this->output("✓ Cleanup complete");
    }

    private function showSuccess(): void
    {
        $url = 'http://' . $_SERVER['HTTP_HOST'] . dirname($_SERVER['REQUEST_URI']);
        if ($this->isWebMode) {
            $this->output("<div class='alert alert-success mt-3'>");
            $this->output("✓ CitadelQuest has been successfully installed!");
            $this->output("You can now access it at: <a href='$url'>$url</a>");
            $this->output("</div>");
        } else {
            $this->output("=================================");
            $this->output("Installation Complete!");
            $this->output("CitadelQuest has been successfully installed.");
            $this->output("You can now access it at: $url");
            $this->output("=================================");
        }
    }

    private function showError(string $message): void
    {
        if ($this->isWebMode) {
            $this->output("<div class='alert alert-danger'>");
            $this->output("❌ ERROR: $message");
            $this->output("Installation failed. Please fix the error and try again.");
            $this->output("</div>");
            echo "        </div>\n";
            echo "    </div>\n";
            echo "</body>\n";
            echo "</html>\n";
        } else {
            $this->output("");
            $this->output("❌ ERROR: $message", 'error');
            $this->output("Installation failed. Please fix the error and try again.", 'error');
        }
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
