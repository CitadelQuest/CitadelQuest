<?php

namespace App\Service;

use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Psr\Log\LoggerInterface;

/**
 * Service for managing CQ Memory Library (.cqmlib) JSON files
 * 
 * A CQ Memory Library is a JSON file that references multiple Memory Packs,
 * allowing organized collections of knowledge for Spirits.
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
        private readonly Security $security,
        private readonly ParameterBagInterface $params,
        private readonly LoggerInterface $logger
    ) {}
    
    /**
     * Get the memory directory relative path for a Spirit
     * Returns relative path like: /spirit/{spiritNameSlug}/memory
     */
    public function getSpiritMemoryPath(string $spiritNameSlug): string
    {
        return "/spirit/{$spiritNameSlug}/memory";
    }
    
    /**
     * Create a new memory library file
     * 
     * @param string $projectId Project ID
     * @param string $path Directory path (e.g., '/spirit/memory')
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
        
        $this->logger->info('Created new memory library', ['projectId' => $projectId, 'path' => $path, 'name' => $name]);
        
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
        
        $this->logger->info('Added pack to library', [
            'library' => $libraryName,
            'pack' => $packName
        ]);
        
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
        
        $this->logger->info('Removed pack from library', [
            'library' => $libraryName,
            'pack' => $packPath
        ]);
        
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
     * Find all pack files in a project directory (recursive)
     * 
     * @param string $projectId Project ID
     * @param string $path Directory path to search
     * @param bool $recursive Search subdirectories (default true)
     */
    public function findPacksInDirectory(string $projectId, string $path, bool $recursive = true): array
    {
        $packs = [];
        
        // Get all files in directory from database
        $files = $this->projectFileService->listFiles($projectId, $path);
        
        foreach ($files as $file) {
            if ($file->isDirectory() && $recursive) {
                // Recursively search subdirectories
                $subPath = rtrim($path, '/') . '/' . $file->getName();
                $packs = array_merge($packs, $this->findPacksInDirectory($projectId, $subPath, true));
            } elseif (!$file->isDirectory() && pathinfo($file->getName(), PATHINFO_EXTENSION) === CQMemoryPackService::FILE_EXTENSION) {
                try {
                    $this->packService->open($projectId, $path, $file->getName());
                    $metadata = $this->packService->getAllMetadata();
                    $stats = $this->packService->getStats();
                    $this->packService->close();
                    
                    $packs[] = [
                        'path' => $path,
                        'name' => $file->getName(),
                        'displayName' => $metadata['name'] ?? basename($file->getName(), '.' . CQMemoryPackService::FILE_EXTENSION),
                        'description' => $metadata['description'] ?? '',
                        'totalNodes' => $stats['totalNodes'],
                        'totalRelationships' => $stats['totalRelationships'],
                        'createdAt' => $metadata['created_at'] ?? null,
                        'updatedAt' => $metadata['updated_at'] ?? null
                    ];
                } catch (\Exception $e) {
                    $this->logger->warning('Invalid pack file', [
                        'path' => $path,
                        'name' => $file->getName(),
                        'error' => $e->getMessage()
                    ]);
                    // DEBUG: include error in output
                    $packs[] = [
                        'path' => $path,
                        'name' => $file->getName(),
                        'error' => $e->getMessage()
                    ];
                }
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
            
            // Parse pack path (stored as relative path like "packs/session-2025-02-05.cqmpack")
            $packRelPath = $pack['path'];
            $packDir = dirname($packRelPath);
            $packName = basename($packRelPath);
            
            // Resolve relative to library directory
            $fullPackDir = $libraryPath;
            if ($packDir !== '.') {
                $fullPackDir = rtrim($libraryPath, '/') . '/' . ltrim($packDir, '/');
            }
            
            $packFile = $this->projectFileService->findByPathAndName($projectId, $fullPackDir, $packName);
            if (!$packFile) {
                $this->logger->warning('Pack file not found', ['path' => $fullPackDir, 'name' => $packName]);
                continue;
            }
            
            try {
                $this->packService->open($projectId, $fullPackDir, $packName);
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
    
    /**
     * Create Spirit's root library and initial session pack
     * 
     * @param string $projectId Project ID
     * @param string $spiritNameSlug Spirit name slug
     */
    public function initializeSpiritMemory(string $projectId, string $spiritNameSlug): array
    {
        $memoryPath = $this->getSpiritMemoryPath($spiritNameSlug);
        $packsPath = $memoryPath . '/packs';
        
        // Ensure directories exist via ProjectFileService
        $spiritDir = '/spirit/' . $spiritNameSlug;
        
        // Create directory structure via ProjectFileService (handles both filesystem and database)
        if (!$this->projectFileService->findByPathAndName($projectId, '/', 'spirit')) {
            $this->projectFileService->createDirectory($projectId, '/', 'spirit');
        }
        if (!$this->projectFileService->findByPathAndName($projectId, '/spirit', $spiritNameSlug)) {
            $this->projectFileService->createDirectory($projectId, '/spirit', $spiritNameSlug);
        }
        if (!$this->projectFileService->findByPathAndName($projectId, $spiritDir, 'memory')) {
            $this->projectFileService->createDirectory($projectId, $spiritDir, 'memory');
        }
        if (!$this->projectFileService->findByPathAndName($projectId, $memoryPath, 'packs')) {
            $this->projectFileService->createDirectory($projectId, $memoryPath, 'packs');
        }
        
        // Create root library
        $rootLibraryName = $spiritNameSlug . '.' . self::FILE_EXTENSION;
        
        if (!$this->projectFileService->findByPathAndName($projectId, $memoryPath, $rootLibraryName)) {
            $this->createLibrary($projectId, $memoryPath, $rootLibraryName, [
                'name' => "{$spiritNameSlug} Memory Library",
                'description' => "Root memory library for Spirit: {$spiritNameSlug}"
            ]);
        }
        
        // Create initial session pack
        $sessionPackName = "session-" . date('Y-m-d') . '.' . CQMemoryPackService::FILE_EXTENSION;
        
        if (!$this->projectFileService->findByPathAndName($projectId, $packsPath, $sessionPackName)) {
            $this->packService->create($projectId, $packsPath, $sessionPackName, [
                'name' => "Session " . date('Y-m-d'),
                'description' => "Memories from session on " . date('Y-m-d')
            ]);
            $this->packService->close();
            
            // Add to root library
            $this->addPackToLibrary($projectId, $memoryPath, $rootLibraryName, $packsPath, $sessionPackName, [
                'priority' => 1,
                'tags' => ['session', 'current']
            ]);
        }
        
        return [
            'memoryPath' => $memoryPath,
            'rootLibraryPath' => $memoryPath,
            'rootLibraryName' => $rootLibraryName,
            'currentPackPath' => $packsPath,
            'currentPackName' => $sessionPackName
        ];
    }
    
    /**
     * Get or create current session pack for a Spirit
     */
    public function getCurrentSessionPack(string $projectId, string $spiritNameSlug): array
    {
        $memoryPath = $this->getSpiritMemoryPath($spiritNameSlug);
        $packsPath = $memoryPath . '/packs';
        
        // Check if today's session pack exists
        $sessionPackName = "session-" . date('Y-m-d') . '.' . CQMemoryPackService::FILE_EXTENSION;
        
        if (!$this->projectFileService->findByPathAndName($projectId, $packsPath, $sessionPackName)) {
            // Initialize Spirit memory if not already done
            $this->initializeSpiritMemory($projectId, $spiritNameSlug);
        }
        
        return [
            'path' => $packsPath,
            'name' => $sessionPackName
        ];
    }
}
