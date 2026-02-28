<?php

namespace App\Service;

use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Psr\Log\LoggerInterface;

/**
 * Service for managing CQ Memory Library (.cqmlib) JSON files
 * 
 * A CQ Memory Library is a JSON file that references multiple Memory Packs,
 * allowing organized collections of knowledge.
 * 
 * All file operations use ProjectFileService with projectId + relativePath + name.
 */
class CQMemoryLibraryService
{
    public const LIBRARY_VERSION = '1.0';
    public const FILE_EXTENSION = 'cqmlib';
    
    public function __construct(
        private readonly CQMemoryPackService $packService,
        private readonly ProjectFileService $projectFileService,
        private readonly CqContactService $cqContactService,
        private readonly Security $security,
        private readonly ParameterBagInterface $params,
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger
    ) {}
    
    /**
     * Create a new memory library file
     * 
     * @param string $projectId Project ID
     * @param string $path Directory path (e.g., '/memory/libs')
     * @param string $name Library filename (without extension)
     * @param array $options Optional library options
     */
    public function createLibrary(string $projectId, string $path, string $name, array $options = []): array
    {
        // Ensure file has correct extension
        if (pathinfo($name, PATHINFO_EXTENSION) !== self::FILE_EXTENSION) {
            $name .= '.' . self::FILE_EXTENSION;
        }
        
        // Check if file already exists
        if ($this->projectFileService->findByPathAndName($projectId, $path, $name)) {
            throw new \RuntimeException("Library file already exists: {$name}");
        }
        
        $library = [
            'version' => self::LIBRARY_VERSION,
            'name' => $options['name'] ?? basename($name, '.' . self::FILE_EXTENSION),
            'description' => $options['description'] ?? '',
            'created_at' => date('c'),
            'updated_at' => date('c'),
            'packs' => [],
            'metadata' => [
                'total_nodes' => 0,
                'total_relationships' => 0,
                'last_sync' => null
            ]
        ];
        
        $this->saveLibrary($projectId, $path, $name, $library);
        
        return $library;
    }
    
    /**
     * Load a memory library from file
     * 
     * @param string $projectId Project ID
     * @param string $path Directory path
     * @param string $name Library filename
     */
    public function loadLibrary(string $projectId, string $path, string $name): array
    {
        $file = $this->projectFileService->findByPathAndName($projectId, $path, $name);
        if (!$file) {
            throw new \RuntimeException("Library file not found: {$name}");
        }
        
        $content = $this->projectFileService->getFileContent($file->getId());
        
        $library = json_decode($content, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException("Invalid library file: " . json_last_error_msg());
        }
        
        // Validate required fields
        if (!isset($library['version']) || !isset($library['packs'])) {
            throw new \RuntimeException("Invalid library file: missing required fields");
        }
        
        return $library;
    }
    
    /**
     * Save a memory library to file
     * 
     * @param string $projectId Project ID
     * @param string $path Directory path
     * @param string $name Library filename
     * @param array $library Library data
     */
    public function saveLibrary(string $projectId, string $path, string $name, array $library): void
    {
        $library['updated_at'] = date('c');
        
        $content = json_encode($library, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        
        $existingFile = $this->projectFileService->findByPathAndName($projectId, $path, $name);
        
        if ($existingFile) {
            $this->projectFileService->updateFile($existingFile->getId(), $content);
        } else {
            $this->projectFileService->createFile($projectId, $path, $name, $content, 'application/json');
        }
    }
    
    /**
     * Add a pack to a library
     * 
     * @param string $projectId Project ID
     * @param string $libraryPath Relative path to library directory
     * @param string $libraryName Library filename
     * @param string $packPath Relative path to pack directory  
     * @param string $packName Pack filename
     * @param array $options Optional pack options
     */
    public function addPackToLibrary(string $projectId, string $libraryPath, string $libraryName, string $packPath, string $packName, array $options = []): array
    {
        $library = $this->loadLibrary($projectId, $libraryPath, $libraryName);
        
        // Check if pack is already in library
        $packRelPath = $packPath . '/' . $packName;
        foreach ($library['packs'] as $pack) {
            if ($pack['path'] === $packRelPath) {
                throw new \RuntimeException("Pack already exists in library: {$packName}");
            }
        }
        
        // Verify pack exists
        $packFile = $this->projectFileService->findByPathAndName($projectId, $packPath, $packName);
        if (!$packFile) {
            throw new \RuntimeException("Pack file not found: {$packName}");
        }
        
        $this->packService->open($projectId, $packPath, $packName);
        $packMetadata = $this->packService->getAllMetadata();
        $packStats = $this->packService->getStats();
        $this->packService->close();
        
        $packEntry = [
            'path' => $packRelPath,
            'enabled' => $options['enabled'] ?? true,
            'priority' => $options['priority'] ?? count($library['packs']) + 1,
            'tags' => $options['tags'] ?? [],
            'name' => $packMetadata['name'] ?? basename($packName, '.' . CQMemoryPackService::FILE_EXTENSION),
            'description' => $packMetadata['description'] ?? '',
            'nodes' => $packStats['totalNodes'],
            'relationships' => $packStats['totalRelationships'],
            'added_at' => date('c')
        ];
        
        $library['packs'][] = $packEntry;
        
        // Update library metadata
        $this->updateLibraryMetadata($library);
        
        $this->saveLibrary($projectId, $libraryPath, $libraryName, $library);
        
        return $library;
    }
    
    /**
     * Remove a pack from a library
     */
    public function removePackFromLibrary(string $projectId, string $libraryPath, string $libraryName, string $packPath): array
    {
        $library = $this->loadLibrary($projectId, $libraryPath, $libraryName);
        
        $found = false;
        $library['packs'] = array_filter($library['packs'], function($pack) use ($packPath, &$found) {
            if ($pack['path'] === $packPath || basename($pack['path']) === basename($packPath)) {
                $found = true;
                return false;
            }
            return true;
        });
        
        if (!$found) {
            throw new \RuntimeException("Pack not found in library: {$packPath}");
        }
        
        // Re-index array
        $library['packs'] = array_values($library['packs']);
        
        // Update library metadata
        $this->updateLibraryMetadata($library);
        
        $this->saveLibrary($projectId, $libraryPath, $libraryName, $library);
        
        return $library;
    }
    
    /**
     * Sync pack stats in a library by reading fresh data from each pack file
     * Updates nodes, relationships, tags counts for each pack entry and library metadata totals
     */
    public function syncPackStats(string $projectId, string $libraryPath, string $libraryName): array
    {
        // Auto-sync remote packs (CQ Share) before reading stats
        try {
            $this->syncRemotePacks($projectId, $libraryPath, $libraryName);
        } catch (\Exception $e) {
            $this->logger->warning('Remote pack sync failed during syncPackStats', ['error' => $e->getMessage()]);
        }

        $library = $this->loadLibrary($projectId, $libraryPath, $libraryName);
        $changed = false;
        
        $packsToRemove = [];
        
        foreach ($library['packs'] as $index => &$packEntry) {
            $packRelPath = $packEntry['path'];
            $packDir = dirname($packRelPath);
            $packName = basename($packRelPath);
            
            $packFile = $this->projectFileService->findByPathAndName($projectId, $packDir, $packName);
            if (!$packFile) {
                // Pack file no longer exists — mark for removal
                $packsToRemove[] = $index;
                $changed = true;
                
                continue;
            }
            
            try {
                $this->packService->open($projectId, $packDir, $packName);
                $stats = $this->packService->getStats();
                $this->packService->close();
                
                $newNodes = $stats['totalNodes'] ?? 0;
                $newRelationships = $stats['totalRelationships'] ?? 0;
                $newTags = $stats['tagsCount'] ?? 0;
                
                if (($packEntry['nodes'] ?? 0) !== $newNodes
                    || ($packEntry['relationships'] ?? 0) !== $newRelationships
                    || ($packEntry['tags'] ?? 0) !== $newTags
                ) {
                    $packEntry['nodes'] = $newNodes;
                    $packEntry['relationships'] = $newRelationships;
                    $packEntry['tags'] = $newTags;
                    $changed = true;
                }
            } catch (\Exception $e) {
                $this->logger->warning('Failed to sync pack stats', [
                    'pack' => $packRelPath,
                    'error' => $e->getMessage()
                ]);
            }
        }
        unset($packEntry);
        
        // Remove stale packs (deleted files) from library
        if (!empty($packsToRemove)) {
            foreach ($packsToRemove as $idx) {
                unset($library['packs'][$idx]);
            }
            $library['packs'] = array_values($library['packs']);
        }
        
        if ($changed) {
            $this->updateLibraryMetadata($library);
            $this->saveLibrary($projectId, $libraryPath, $libraryName, $library);            
        }
        
        return $library;
    }
    
    /**
     * Update library metadata based on all packs
     */
    private function updateLibraryMetadata(array &$library): void
    {
        $totalNodes = 0;
        $totalRelationships = 0;
        
        foreach ($library['packs'] as $pack) {
            $totalNodes += $pack['nodes'] ?? 0;
            $totalRelationships += $pack['relationships'] ?? 0;
        }
        
        $library['metadata']['total_nodes'] = $totalNodes;
        $library['metadata']['total_relationships'] = $totalRelationships;
        $library['metadata']['last_sync'] = date('c');
    }
    
    /**
     * Find and sync any library that references a given pack
     * Searches from project root for all .cqmlib files
     */
    public function syncLibraryForPack(string $projectId, string $packPath, string $packName): void
    {
        // Search from project root to find all libraries that reference this pack
        $packRelPath = $packPath . '/' . $packName;
        
        try {
            $libraries = $this->findLibrariesInDirectory($projectId, '/');
            
            foreach ($libraries as $libInfo) {
                try {
                    $library = $this->loadLibrary($projectId, $libInfo['path'], $libInfo['name']);
                    
                    // Check if this library references the pack
                    foreach ($library['packs'] as $pack) {
                        if ($pack['path'] === $packRelPath || basename($pack['path']) === $packName) {
                            $this->syncPackStats($projectId, $libInfo['path'], $libInfo['name']);
                            break;
                        }
                    }
                } catch (\Exception $e) {
                    // Skip invalid libraries
                }
            }
        } catch (\Exception $e) {
            $this->logger->warning('Failed to sync library for pack', [
                'packPath' => $packPath,
                'packName' => $packName,
                'error' => $e->getMessage()
            ]);
        }
    }
    
    public function findPackFilesInDirectory(string $projectId, string $path, bool $recursive = true): array
    {
        $packFiles = [];

        $files = $this->projectFileService->listFiles($projectId, $path);

        foreach ($files as $file) {
            if ($file->isDirectory() && $recursive) {
                $subPath = rtrim($path, '/') . '/' . $file->getName();
                $packFiles = array_merge($packFiles, $this->findPackFilesInDirectory($projectId, $subPath, true));
            } elseif (!$file->isDirectory() && pathinfo($file->getName(), PATHINFO_EXTENSION) === CQMemoryPackService::FILE_EXTENSION) {
                $packFiles[] = ['path' => $path, 'name' => $file->getName(), 'fileId' => $file->getId()];
            }
        }

        return $packFiles;
    }

    public function findPacksInDirectory(string $projectId, string $path, bool $recursive = true): array
    {
        $packs = [];

        foreach ($this->findPackFilesInDirectory($projectId, $path, $recursive) as $packFile) {
            try {
                $this->packService->open($projectId, $packFile['path'], $packFile['name']);
                $metadata = $this->packService->getAllMetadata();
                $stats = $this->packService->getStats();
                $this->packService->close();

                $fileId = $packFile['fileId'] ?? null;
                $packs[] = [
                    'path' => $packFile['path'],
                    'name' => $packFile['name'],
                    'fileId' => $fileId,
                    'displayName' => $metadata['name'] ?? basename($packFile['name'], '.' . CQMemoryPackService::FILE_EXTENSION),
                    'description' => $metadata['description'] ?? '',
                    'totalNodes' => $stats['totalNodes'],
                    'totalRelationships' => $stats['totalRelationships'],
                    'createdAt' => $metadata['created_at'] ?? null,
                    'updatedAt' => $metadata['updated_at'] ?? null,
                    'sourceUrl' => $metadata['source_url'] ?? null,
                    'sourceCqContactId' => $metadata['source_cq_contact_id'] ?? null,
                    'isShared' => $fileId ? $this->projectFileService->isSharedFile($fileId) : false,
                ];
            } catch (\Exception $e) {
                $this->logger->warning('Invalid pack file', [
                    'path' => $packFile['path'],
                    'name' => $packFile['name'],
                    'error' => $e->getMessage()
                ]);
            }
        }

        return $packs;
    }
    
    /**
     * Find all library files in a project directory (recursive)
     * 
     * @param string $projectId Project ID
     * @param string $path Directory path to search
     * @param bool $recursive Search subdirectories (default true)
     */
    public function findLibrariesInDirectory(string $projectId, string $path, bool $recursive = true): array
    {
        $libraries = [];
        
        // Get all files in directory from database
        $files = $this->projectFileService->listFiles($projectId, $path);
        
        foreach ($files as $file) {
            if ($file->isDirectory() && $recursive) {
                // Recursively search subdirectories
                $subPath = rtrim($path, '/') . '/' . $file->getName();
                $libraries = array_merge($libraries, $this->findLibrariesInDirectory($projectId, $subPath, true));
            } elseif (!$file->isDirectory() && pathinfo($file->getName(), PATHINFO_EXTENSION) === self::FILE_EXTENSION) {
                try {
                    $library = $this->loadLibrary($projectId, $path, $file->getName());
                    $libraries[] = [
                        'path' => $path,
                        'name' => $file->getName(),
                        'displayName' => $library['name'],
                        'description' => $library['description'],
                        'packCount' => count($library['packs']),
                        'totalNodes' => $library['metadata']['total_nodes'] ?? 0,
                        'totalRelationships' => $library['metadata']['total_relationships'] ?? 0,
                        'updatedAt' => $library['updated_at']
                    ];
                } catch (\Exception $e) {
                    $this->logger->warning('Invalid library file', [
                        'path' => $path,
                        'name' => $file->getName(),
                        'error' => $e->getMessage()
                    ]);
                }
            }
        }
        
        return $libraries;
    }
    
    /**
     * Get combined graph data from all enabled packs in a library
     */
    public function getLibraryGraphData(string $projectId, string $libraryPath, string $libraryName): array
    {
        $library = $this->loadLibrary($projectId, $libraryPath, $libraryName);
        
        $combinedNodes = [];
        $combinedEdges = [];
        $packSources = [];
        
        foreach ($library['packs'] as $pack) {
            if (!($pack['enabled'] ?? true)) {
                continue;
            }
            
            // Parse pack path (stored as absolute project-relative path)
            $packRelPath = $pack['path'];
            $packDir = dirname($packRelPath);
            $packName = basename($packRelPath);
            
            $packFile = $this->projectFileService->findByPathAndName($projectId, $packDir, $packName);
            if (!$packFile) {
                $this->logger->warning('Pack file not found', ['path' => $packDir, 'name' => $packName]);
                continue;
            }
            
            try {
                $this->packService->open($projectId, $packDir, $packName);
                $graphData = $this->packService->getGraphData();
                $this->packService->close();
                
                $packId = md5($pack['path']);
                $packSources[$packId] = [
                    'path' => $pack['path'],
                    'name' => $pack['name'] ?? $packName,
                    'priority' => $pack['priority'] ?? 999
                ];
                
                // Add pack source to each node
                foreach ($graphData['nodes'] as $node) {
                    $node['packId'] = $packId;
                    $node['packName'] = $pack['name'] ?? $packName;
                    $combinedNodes[] = $node;
                }
                
                // Add pack source to each edge
                foreach ($graphData['edges'] as $edge) {
                    $edge['packId'] = $packId;
                    $combinedEdges[] = $edge;
                }
            } catch (\Exception $e) {
                $this->logger->warning('Failed to load pack graph data', [
                    'pack' => $packName,
                    'error' => $e->getMessage()
                ]);
            }
        }
        
        return [
            'nodes' => $combinedNodes,
            'edges' => $combinedEdges,
            'stats' => [
                'totalNodes' => count($combinedNodes),
                'totalRelationships' => count($combinedEdges),
                'packCount' => count($packSources)
            ],
            'packs' => $packSources
        ];
    }

    // ========================================
    // CQ Share — Remote Pack Sync
    // ========================================

    /**
     * Sync remote packs in a library that have a source_url.
     * Checks each pack's source_url for newer content, downloads if updated.
     * Called automatically during syncPackStats() for seamless auto-sync on library load.
     * 
     * @return int Number of packs that were updated
     */
    public function syncRemotePacks(string $projectId, string $libraryPath, string $libraryName): int
    {
        $library = $this->loadLibrary($projectId, $libraryPath, $libraryName);
        $updatedCount = 0;

        foreach ($library['packs'] as $packEntry) {
            $packRelPath = $packEntry['path'];
            $packDir = dirname($packRelPath);
            $packName = basename($packRelPath);

            try {
                $this->packService->open($projectId, $packDir, $packName);
                $sourceUrl = $this->packService->getSourceUrl();
                $syncedAt = $this->packService->getSyncedAt();
                $sourceCqContactId = $this->packService->getSourceCqContactId();
                $this->packService->close();

                if (!$sourceUrl) {
                    continue;
                }

                if ($this->syncFromSourceURL($projectId, $packDir, $packName, $sourceUrl, $syncedAt, $sourceCqContactId)) {
                    $updatedCount++;
                }
            } catch (\Exception $e) {
                $this->packService->close();
                $this->logger->warning('Failed to sync remote pack', [
                    'pack' => $packRelPath,
                    'error' => $e->getMessage()
                ]);
            }
        }

        return $updatedCount;
    }

    /**
     * Sync a single pack from its source URL.
     * 
     * Protocol:
     * 1. POST source_url → get remote metadata (updated_at)
     * 2. Compare with local synced_at
     * 3. If remote is newer → GET source_url → download .cqmpack binary
     * 4. Replace local file, update synced_at
     * 
     * @param string|null $sourceCqContactId Global Federation User UUID (cq_contact.id)
     * @return bool True if the pack was updated
     */
    public function syncFromSourceURL(
        string $projectId,
        string $packDir,
        string $packName,
        string $sourceUrl,
        ?string $syncedAt = null,
        ?string $sourceCqContactId = null
    ): bool {
        try {
            $headers = $this->buildSyncHeaders($sourceCqContactId);

            // Step 1: POST to get remote metadata
            $metadataResponse = $this->httpClient->request('POST', $sourceUrl, [
                'timeout' => 10,
                'headers' => $headers,
            ]);

            if ($metadataResponse->getStatusCode() !== 200) {
                $this->logger->warning('CQ Share sync: metadata request failed', [
                    'url' => $sourceUrl,
                    'status' => $metadataResponse->getStatusCode()
                ]);
                return false;
            }

            $remoteData = $metadataResponse->toArray();
            if (!($remoteData['success'] ?? false) || empty($remoteData['share'])) {
                return false;
            }

            $remoteUpdatedAt = $remoteData['share']['updated_at'] ?? null;
            if (!$remoteUpdatedAt) {
                return false;
            }

            // Step 2: Compare timestamps — skip if local is up to date
            if ($syncedAt && strtotime($remoteUpdatedAt) <= strtotime($syncedAt)) {
                return false;
            }

            // Step 3: GET to download the binary file
            $fileResponse = $this->httpClient->request('GET', $sourceUrl, [
                'timeout' => 60,
                'headers' => $headers,
            ]);

            if ($fileResponse->getStatusCode() !== 200) {
                $this->logger->warning('CQ Share sync: file download failed', [
                    'url' => $sourceUrl,
                    'status' => $fileResponse->getStatusCode()
                ]);
                return false;
            }

            $binaryContent = $fileResponse->getContent();
            if (empty($binaryContent)) {
                return false;
            }

            // Step 4: Replace local pack file
            $packFile = $this->projectFileService->findByPathAndName($projectId, $packDir, $packName);
            if (!$packFile) {
                $this->logger->warning('CQ Share sync: local pack file not found', [
                    'packDir' => $packDir,
                    'packName' => $packName
                ]);
                return false;
            }

            // Write binary content to the filesystem
            $user = $this->security->getUser();
            $basePath = $this->params->get('kernel.project_dir') . '/var/user_data/' . $user->getId() . '/p/' . $projectId;
            $relativePath = ltrim($packDir, '/');
            $filePath = $relativePath
                ? $basePath . '/' . $relativePath . '/' . $packName
                : $basePath . '/' . $packName;

            if (file_put_contents($filePath, $binaryContent) === false) {
                throw new \RuntimeException("Failed to write synced pack file: {$filePath}");
            }

            // Update metadata in the pack (preserve source_url & contact after replacement)
            $this->packService->open($projectId, $packDir, $packName);
            $this->packService->setSourceUrl($sourceUrl);
            if ($sourceCqContactId) {
                $this->packService->setSourceCqContactId($sourceCqContactId);
            }
            $this->packService->touchSyncedAt();
            $this->packService->close();

            // Sync file size in project_file DB
            $this->projectFileService->syncFileSize($projectId, $packDir, $packName);

            $this->logger->info('CQ Share sync: pack updated from remote', [
                'pack' => $packDir . '/' . $packName,
                'sourceUrl' => $sourceUrl,
                'remoteUpdatedAt' => $remoteUpdatedAt
            ]);

            return true;

        } catch (\Exception $e) {
            $this->logger->warning('CQ Share sync: error syncing pack', [
                'url' => $sourceUrl,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Build HTTP headers for CQ Share sync requests.
     * Includes CQ Contact API key for scope=1 (CQ Contact) shares.
     *
     * @param string|null $sourceCqContactId Global Federation User UUID (cq_contact.id)
     */
    private function buildSyncHeaders(?string $sourceCqContactId = null): array
    {
        $headers = [
            'Accept' => 'application/json, application/octet-stream',
        ];

        if ($sourceCqContactId) {
            try {
                $contact = $this->cqContactService->findById($sourceCqContactId);
                if ($contact && $contact->getCqContactApiKey()) {
                    $headers['Authorization'] = 'Bearer ' . $contact->getCqContactApiKey();
                }
            } catch (\Exception $e) {
                $this->logger->warning('CQ Share sync: failed to get contact API key', [
                    'sourceCqContactId' => $sourceCqContactId,
                    'error' => $e->getMessage()
                ]);
            }
        }

        return $headers;
    }
}
