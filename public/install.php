<?php
// Enable error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);

/**
 * CitadelQuest Installation Script
 * 
 * This lightweight installer downloads and sets up a pre-built CitadelQuest instance:
 * 1. Environment checks (PHP version, extensions)
 * 2. Download pre-built release (includes vendor, assets, database)
 * 3. Extract and verify contents
 * 4. Configure environment
 * 5. Set permissions
 * 6. Clean up installation files
 */

class CitadelQuestInstaller
{
    private string $installDir;
    private string $version = 'v0.1.4-alpha';
    private string $prebuiltReleaseUrl;
    private bool $isWebMode;
    private array $requirements = [
        'php' => '8.2.0',
        'extensions' => ['pdo_sqlite', 'json', 'curl', 'zip']
    ];
    private array $installFiles = [
        'install.php',
        'install.htaccess'
    ];

    public function __construct()
    {
        // Since we're in the public directory, the install directory should be one level up
        $this->installDir = dirname(__FILE__, 2);
        
        // Set up .htaccess for error reporting during installation
        if (file_exists(__DIR__ . '/install.htaccess')) {
            rename(__DIR__ . '/install.htaccess', __DIR__ . '/.htaccess.install');
            if (file_exists(__DIR__ . '/.htaccess')) {
                copy(__DIR__ . '/.htaccess', __DIR__ . '/.htaccess.backup');
            }
            rename(__DIR__ . '/.htaccess.install', __DIR__ . '/.htaccess');
        }
        
        // URL for the pre-built release (Release A)
        $this->prebuiltReleaseUrl = "https://github.com/CitadelQuest/CitadelQuest/releases/download/{$this->version}/citadelquest-prebuilt-{$this->version}.zip";
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
            $this->output("Checking requirements...");
            $this->checkRequirements();
            $this->output("✓ All requirements met");

            $this->output("Downloading pre-built release...");
            $releaseFile = $this->downloadPrebuiltRelease();
            $this->output("✓ Download complete");

            $this->output("Extracting files...");
            $this->extractPrebuiltRelease($releaseFile);
            $this->output("✓ Files extracted successfully");

            $this->output("Configuring environment...");
            $this->configureEnvironment();
            $this->output("✓ Environment configured");

            $this->output("Setting directory permissions...");
            $this->setPermissions();
            $this->output("✓ Permissions set");

            $this->output("Verifying installation...");
            $this->verifyInstallation();
            $this->output("✓ Installation verified");

            $this->output("Cleaning up installation files...");
            $this->cleanupInstallFiles();
            $this->output("✓ Cleanup complete");

            $this->showSuccess();
        } catch (Exception $e) {
            $this->showError($e->getMessage());
        }
    }

    private function output(string $message, string $type = 'log'): void
    {
        if ($this->isWebMode) {
            echo "            <p class=\"{$type}\">{$message}</p>\n";
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
            throw new Exception(
                "PHP version {$this->requirements['php']} or higher is required. " .
                "Current version: " . PHP_VERSION
            );
        }

        // Check required extensions
        foreach ($this->requirements['extensions'] as $ext) {
            if (!extension_loaded($ext)) {
                throw new Exception("Required PHP extension missing: {$ext}");
            }
        }

        $this->output("✓ All requirements met");
    }

    private function downloadPrebuiltRelease(): string
    {
        // Create a temporary directory for download
        $tempDir = sys_get_temp_dir() . '/citadelquest_' . uniqid();
        if (!mkdir($tempDir)) {
            throw new Exception("Failed to create temporary directory");
        }
        
        // Download the pre-built release
        $releasePath = $tempDir . '/release.zip';
        if (!file_put_contents($releasePath, file_get_contents($this->prebuiltReleaseUrl))) {
            throw new Exception("Failed to download pre-built release");
        }
        
        return $releasePath;
    }

    private function extractPrebuiltRelease(string $releasePath): void
    {
        $zip = new ZipArchive();
        if ($zip->open($releasePath) !== true) {
            throw new Exception("Failed to open release archive");
        }
        
        // Extract to the installation directory
        if (!$zip->extractTo($this->installDir)) {
            throw new Exception("Failed to extract release files");
        }
        $zip->close();
        
        // Clean up the downloaded file
        unlink($releasePath);
        rmdir(dirname($releasePath));
        
        // Extract the archive
        $command = "cd " . escapeshellarg($extractDir) . " && tar xzf " . escapeshellarg($archivePath);
        exec($command, $output, $returnVar);
        if ($returnVar !== 0) {
            throw new Exception("Failed to extract archive");
        }
        
        // Find the extracted directory
        $files = glob($extractDir . '/CitadelQuest-*', GLOB_ONLYDIR);
        if (empty($files)) {
            throw new Exception("Failed to locate extracted files");
        }
        
        $extractedDir = $files[0];
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
        
        // Copy .env.example to .env if it doesn't exist
        if (!file_exists($this->installDir . '/.env') && file_exists($this->installDir . '/.env.example')) {
            copy($this->installDir . '/.env.example', $this->installDir . '/.env');
        }
        
        // Generate APP_SECRET if not already set
        $envContent = file_get_contents($this->installDir . '/.env');
        if (strpos($envContent, 'APP_SECRET=') === false) {
            $secret = bin2hex(random_bytes(16));
            file_put_contents($this->installDir . '/.env', "\nAPP_SECRET={$secret}\n", FILE_APPEND);
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
            if (!is_dir($dir)) {
                mkdir($dir, $perm, true);
            } else {
                chmod($dir, $perm);
            }
        }

        $this->output("✓ Permissions set");
    }

    private function verifyInstallation(): void
    {
        // Check for essential directories and files
        $requiredPaths = [
            $this->installDir . '/vendor',
            $this->installDir . '/public/build',
            $this->installDir . '/var/app.db',
            $this->installDir . '/.env',
            $this->installDir . '/config/bundles.php'
        ];

        foreach ($requiredPaths as $path) {
            if (!file_exists($path)) {
                throw new Exception("Missing required path: {$path}");
            }
        }

        // Verify database is accessible
        try {
            $db = new SQLite3($this->installDir . '/var/app.db');
            $db->close();
        } catch (Exception $e) {
            throw new Exception("Database verification failed: " . $e->getMessage());
        }
    }

    private function cleanupInstallFiles(): void
    {
        // Restore original .htaccess if it exists
        if (file_exists(__DIR__ . '/.htaccess.backup')) {
            unlink(__DIR__ . '/.htaccess');
            rename(__DIR__ . '/.htaccess.backup', __DIR__ . '/.htaccess');
        }
        
        // Remove installation files
        foreach ($this->installFiles as $file) {
            $path = __DIR__ . '/' . $file;
            if (file_exists($path)) {
                unlink($path);
            }
        }
    }

    private function showSuccess(): void
    {
        if ($this->isWebMode) {
            $this->output("<div class='alert alert-success'>");
            $this->output("✓ CitadelQuest has been successfully installed!");
            $this->output("You can now access your installation at: <a href='/' class='alert-link'>Open CitadelQuest</a>");
            $this->output("</div>");
        } else {
            $this->output("");
            $this->output("✓ CitadelQuest has been successfully installed!");
            $this->output("You can now access your installation at: http://localhost/");
        }
    }

    private function showError(string $message): void
    {
        // Get the last few lines from error log if it exists
        $errorDetails = '';
        $logFile = $this->installDir . '/var/log/prod.log';
        if (file_exists($logFile)) {
            $errorLog = file_get_contents($logFile);
            if ($errorLog) {
                $logLines = array_slice(explode("\n", $errorLog), -10);
                $errorDetails = "\n\nError Log (last 10 lines):\n" . implode("\n", $logLines);
            }
        }

        // Get PHP error log if available
        $phpErrorLog = error_get_last();
        if ($phpErrorLog) {
            $errorDetails .= "\n\nPHP Error:\n" . 
                           "Type: " . $phpErrorLog['type'] . "\n" .
                           "Message: " . $phpErrorLog['message'] . "\n" .
                           "File: " . $phpErrorLog['file'] . "\n" .
                           "Line: " . $phpErrorLog['line'];
        }

        if ($this->isWebMode) {
            $this->output("<div class='alert alert-danger'>");
            $this->output("❌ ERROR: $message");
            $this->output("Installation failed. Please fix the error and try again.");
            if ($errorDetails) {
                $this->output("<pre class='mt-3 p-3 bg-light'><code>" . htmlspecialchars($errorDetails) . "</code></pre>");
            }
            $this->output("</div>");
            echo "        </div>\n";
            echo "    </div>\n";
            echo "</body>\n";
            echo "</html>\n";
        } else {
            $this->output("");
            $this->output("❌ ERROR: $message", 'error');
            $this->output("Installation failed. Please fix the error and try again.", 'error');
            if ($errorDetails) {
                $this->output($errorDetails, 'error');
            }
        }
        exit(1);
    }

    private function recursiveCopy(string $src, string $dst): void
    {
        $dir = opendir($src);
        if (!is_dir($dst)) {
            mkdir($dst);
        }
        while (($file = readdir($dir)) !== false) {
            if ($file === '.' || $file === '..') {
                continue;
            }
            $srcPath = $src . '/' . $file;
            $dstPath = $dst . '/' . $file;
            if (is_dir($srcPath)) {
                $this->recursiveCopy($srcPath, $dstPath);
            } else {
                copy($srcPath, $dstPath);
            }
        }
        closedir($dir);
    }

    private function recursiveRmdir(string $dir): void
    {
        if (is_dir($dir)) {
            $objects = scandir($dir);
            foreach ($objects as $object) {
                if ($object === '.' || $object === '..') {
                    continue;
                }
                $path = $dir . '/' . $object;
                if (is_dir($path)) {
                    $this->recursiveRmdir($path);
                } else {
                    unlink($path);
                }
            }
            rmdir($dir);
        }
    }
}

// Run the installer
$installer = new CitadelQuestInstaller();
$installer->install();
