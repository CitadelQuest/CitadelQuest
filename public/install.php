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
    private string $version = 'v0.1.6-alpha';
    private string $prebuiltReleaseUrl;
    private array $requirements = [
        'php' => '8.2.0',
        'extensions' => ['pdo_sqlite', 'json', 'curl', 'zip']
    ];
    private array $installFiles = [
        'install.php'
    ];

    public function __construct()
    {
        // Since we're in the public directory, the install directory should be one level up
        $this->installDir = dirname(__FILE__, 2);
        
        // URL for the pre-built release (Release A)
        $this->prebuiltReleaseUrl = "https://github.com/CitadelQuest/CitadelQuest/releases/download/{$this->version}/citadelquest-prebuilt-{$this->version}.zip";
        
        // Initialize HTML template
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

    public function install(): void
    {
        try {
            $this->showHeader();
            $this->output("Checking requirements...");
            $this->checkRequirements();
            $this->output("✓ <br/>");

            $this->output("Downloading pre-built release...");
            $releaseFile = $this->downloadPrebuiltRelease();
            $this->output("✓ <br/>");

            $this->output("Extracting files...");
            $this->extractPrebuiltRelease($releaseFile);
            $this->output("✓ <br/>");

            $this->output("Configuring environment...");
            $this->configureEnvironment();
            $this->output("✓ <br/>");

            $this->output("Setting directory permissions...");
            $this->setPermissions();
            $this->output("✓ <br/>");

            $this->output("Verifying installation...");
            $this->verifyInstallation();
            $this->output("✓ <br/>");

            $this->output("Cleaning up installation files...");
            $this->cleanupInstallFiles();
            $this->output("✓ <br/>");

            $this->showSuccess();
        } catch (Exception $e) {
            $this->showError($e->getMessage());
        }
    }

    private function output(string $message): void
    {
        if ($message === null || $message === '') return;
        echo "{$message}\n";
        flush();
    }

    private function showHeader(): void
    {
        $this->output("<h2>CitadelQuest Installation</h2>");
        $this->output("<h5 class='text-muted'>Version: {$this->version}</h5>");
        $this->output("<hr>");
    }

    private function checkRequirements(): void
    {
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
        
        // Check HTTPS
        if (!isset($_SERVER['HTTPS']) || $_SERVER['HTTPS'] !== 'on') {
            throw new Exception(
                "HTTPS is required for CitadelQuest. Please configure your web server with SSL/TLS " .
                "and access the installer via https://"
            );
        }
    }

    private function downloadPrebuiltRelease(): string
    {
        // Download directly to the installation directory
        $releasePath = $this->installDir . '/release.zip';
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
        
        // We don't need to detect a root prefix - files should maintain their full paths
        
        // Initialize arrays
        $extractFiles = [];
        $seenPaths = [];
        
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            $name = $stat['name'];
            $size = $stat['size'];
            $isDir = substr($name, -1) === '/';
            

            // Skip empty paths and duplicates
            if (empty($name) || isset($seenPaths[$name])) continue;
            $seenPaths[$name] = true;
            
            // Clean up the path - remove any leading ./ and ensure proper path structure
            $cleanPath = ltrim($name, './');  // Remove leading ./
            
            // Add to extraction list with proper target path
            $extractFiles[$name] = $this->installDir . '/' . $cleanPath;
        }
        
        // Extract each file to its proper location
        foreach ($extractFiles as $zipPath => $targetPath) {
            $targetDir = dirname($targetPath);
            
            // Create target directory if it doesn't exist
            if (!is_dir($targetDir)) {
                if (!mkdir($targetDir, 0755, true)) {
                    $zip->close();
                    throw new Exception("Failed to create directory: $targetDir");
                }
            }
            
            // Prepare target path
            $targetFile = $this->installDir . '/' . ltrim($zipPath, './');
            
            // Check if this is a directory entry (ends with /)
            $isDir = substr($zipPath, -1) === '/';
            
            if ($isDir) {
                // Create directory if it doesn't exist
                if (!is_dir($targetFile) && !mkdir($targetFile, 0755, true)) {
                    $zip->close();
                    throw new Exception("Failed to create directory: $targetFile");
                }
            } else {
                // Handle regular file
                if (file_exists($targetFile) && is_file($targetFile)) {
                    // Remove existing file to ensure it's replaced
                    if (!unlink($targetFile)) {
                        $zip->close();
                        throw new Exception("Failed to remove existing file: $targetFile");
                    }
                }
                
                // Create parent directory if needed
                $targetDir = dirname($targetFile);
                if (!is_dir($targetDir)) {
                    mkdir($targetDir, 0755, true);
                }
                
                // Extract file normally
                if (!$zip->extractTo($this->installDir, $zipPath)) {
                    $zip->close();
                    throw new Exception("Failed to extract file: $zipPath");
                }
            }
        }
        
        $zip->close();
        unlink($releasePath);
    }

    private function configureEnvironment(): void
    {
        // Create basic .env if neither .env nor .env.example exist
        /* if (!file_exists($this->installDir . '/.env')) {
            if (file_exists($this->installDir . '/.env.example')) {
                copy($this->installDir . '/.env.example', $this->installDir . '/.env');
            } else { */
                // Create minimal .env with required settings
                $envContent = "APP_ENV=prod\n";
                $envContent .= "APP_DEBUG=0\n";
                $envContent .= "APP_SECRET=" . bin2hex(random_bytes(16)) . "\n";
                $envContent .= "DATABASE_URL=\"sqlite:///%kernel.project_dir%/var/main.db\"\n";
                
                file_put_contents($this->installDir . '/.env', $envContent);
            /* }
        } else {
            // If .env exists, ensure APP_SECRET is set
            $envContent = file_get_contents($this->installDir . '/.env');
            if (strpos($envContent, 'APP_SECRET=') === false) {
                $secret = bin2hex(random_bytes(16));
                file_put_contents($this->installDir . '/.env', "\nAPP_SECRET={$secret}\n", FILE_APPEND);
            }
        } */
    }

    private function setPermissions(): void
    {
        // Create and set permissions for user backups directory
        $backupDir = $this->installDir . '/var/user_backups';
        if (!is_dir($backupDir)) {
            if (!mkdir($backupDir, 0755, true)) {
                throw new Exception("Failed to create backup directory: $backupDir");
            }
            $this->output("Created backup directory: $backupDir");
        }

        // Ensure web server can write to backup directory
        $webServerUser = $this->getWebServerUser();
        if ($webServerUser) {
            chown($backupDir, $webServerUser);
            $this->output("Set backup directory owner to: $webServerUser");
        }

        // Get web server user and group
        $webUser = $this->getWebServerUser();
        $webGroup = $this->getWebServerGroup();
        
        // Set directory permissions
        $dirs = [
            $this->installDir . '/public' => 0755,
            $this->installDir . '/var' => 0777,
            $this->installDir . '/var/cache' => 0777,
            $this->installDir . '/var/data' => 0777,
            $this->installDir . '/var/log' => 0777,
            $this->installDir . '/var/user_databases' => 0777  // Directory for user databases
        ];

        foreach ($dirs as $dir => $perm) {
            if (!is_dir($dir)) {
                if (!mkdir($dir, $perm, true)) {
                    throw new Exception("Failed to create directory: {$dir}");
                }
            }
            
            if (!chmod($dir, $perm)) {
                throw new Exception("Failed to set permissions on: {$dir}");
            }
            
            // Try to set ownership if we have web server user info, but don't fail if we can't
            if ($webUser && $webGroup) {
                @chown($dir, $webUser);
                @chgrp($dir, $webGroup);
            }
        }
        
        // Handle SQLite database files
        $dbFiles = [
            $this->installDir . '/var/main.db',  // Main application database
        ];
        
        foreach ($dbFiles as $dbFile) {
            if (file_exists($dbFile)) {
                // Set database file permissions
                chmod($dbFile, 0666);  // rw-rw-rw-
                
                // Try to set ownership if we have web server user info, but don't fail if we can't
                if ($webUser && $webGroup) {
                    @chown($dbFile, $webUser);
                    @chgrp($dbFile, $webGroup);
                }
                
                // Handle SQLite companion files (-wal and -shm)
                foreach (['-wal', '-shm'] as $ext) {
                    $companionFile = $dbFile . $ext;
                    if (file_exists($companionFile)) {
                        chmod($companionFile, 0666);
                        if ($webUser && $webGroup) {
                            @chown($companionFile, $webUser);
                            @chgrp($companionFile, $webGroup);
                        }
                    }
                }
            }
        }
    }

    private function verifyInstallation(): void
    {
        // Check for essential directories and files
        $requiredPaths = [
            $this->installDir . '/vendor',
            $this->installDir . '/public/build',
            $this->installDir . '/.env',
            $this->installDir . '/config/bundles.php'
        ];

        foreach ($requiredPaths as $path) {
            if (!file_exists($path)) {
                throw new Exception("Missing required path: {$path}");
            }
        }

        // Ensure var directory exists and is writable
        $varDir = $this->installDir . '/var';
        if (!is_dir($varDir)) {
            if (!mkdir($varDir, 0777, true)) {
                throw new Exception("Failed to create var directory");
            }
        }

        // Verify the pre-built database exists and is accessible
        $dbPath = $varDir . '/main.db';
        if (!file_exists($dbPath)) {
            throw new Exception("Pre-built database not found: {$dbPath}. The release package may be corrupted.");
        }
        
        try {
            $db = new SQLite3($dbPath);
            $db->close();
            
            // Ensure database is writable
            if (!is_writable($dbPath)) {
                chmod($dbPath, 0666);
            }
        } catch (Exception $e) {
            throw new Exception("Database verification failed: " . $e->getMessage());
        }
    }

    private function cleanupInstallFiles(): void
    {
        // Remove installation files
        foreach ($this->installFiles as $file) {
            $path = __DIR__ . '/' . $file;
            if (file_exists($path)) {
                unlink($path);
            }
        }
        
        // Self-delete the installer script after everything else is done
        if (file_exists(__FILE__)) {
            unlink(__FILE__);
        }
    }

    /**
     * Attempts to detect the web server user
     * @return string|null Web server username or null if not detected
     */
    private function getWebServerUser(): ?string
    {
        // Common web server users
        $possibleUsers = ['www-data', 'apache', 'nginx', 'http', 'www'];
        
        foreach ($possibleUsers as $user) {
            if (posix_getpwnam($user)) {
                return $user;
            }
        }
        
        return null;
    }
    
    /**
     * Attempts to detect the web server group
     * @return string|null Web server group or null if not detected
     */
    private function getWebServerGroup(): ?string
    {
        // Common web server groups
        $possibleGroups = ['www-data', 'apache', 'nginx', 'http', 'www'];
        
        foreach ($possibleGroups as $group) {
            if (posix_getgrnam($group)) {
                return $group;
            }
        }
        
        return null;
    }
    
    private function showSuccess(): void
    {
        $this->output("<div class='alert alert-success mt-3 mb-0'>");
        $this->output("✓ CitadelQuest has been successfully installed!<br/>");
        $this->output("<a href='/' class='btn btn-success mt-4'>Open CitadelQuest</a>");
        $this->output("</div>");
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
