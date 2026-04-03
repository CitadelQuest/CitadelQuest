<?php

namespace App\Service;

use App\Entity\AiTool;

/**
 * Service for AI Tool management operations by AI Spirits
 * Meta-service that allows Spirits to manage their own toolset
 */
class AIToolToolService
{
    public function __construct(
        private readonly AiToolService $aiToolService
    ) {
    }
    
    /**
     * List all AI Tools, with active tools first
     */
    public function aiToolList(array $arguments): array
    {
        try {
            // arg: activeOnly
            $activeOnly = isset($arguments['activeOnly']) ? $arguments['activeOnly'] : false;
            
            // Get all tools
            $allTools = [];
            if (isset($arguments['spiritId'])) {
                $allTools = $this->aiToolService->findAllWithSpiritState($arguments['spiritId']);
            } else {
                $allTools = $this->aiToolService->findAll($activeOnly);
            }
            
            // Format the response
            $formattedTools = array_map(function($tool) {
                // Handle both AiTool objects and arrays from findAllWithSpiritState
                if ($tool instanceof AiTool) {
                    return [
                        'id' => $tool->getId(),
                        'name' => $tool->getName(),
                        'description' => $tool->getDescription(),
                        'isActive' => $tool->isActive(),
                    ];
                } else {
                    // Array format from findAllWithSpiritState
                    return [
                        'id' => $tool['id'],
                        'name' => $tool['name'],
                        'description' => $tool['description'],
                        'isActive' => $tool['isActiveForSpirit'] ?? $tool['isActive'],
                    ];
                }
            }, $allTools);
            
            return [
                'success' => true,
                'tools' => $formattedTools,
                'activeCount' => count(array_filter($allTools, fn($tool) => 
                    $tool instanceof AiTool ? $tool->isActive() : ($tool['isActiveForSpirit'] ?? $tool['isActive'])
                )),
                'totalCount' => count($allTools)
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Set an AI Tool's active status
     */
    public function aiToolSetActive(array $arguments): array
    {
        // Validate required arguments
        if (!isset($arguments['toolName']) || !isset($arguments['active'])) {
            return [
                'success' => false,
                'error' => 'Missing required arguments: toolName and active'
            ];
        }

        // skip `this` tool
        if ($arguments['toolName'] == 'aiToolSetActive') {
            return [
                'success' => false,
                'error' => 'Tool ' . $arguments['toolName'] . ' must always be active'
            ];
        }
        
        try {
            // Find the tool by name
            $tool = $this->aiToolService->findByName($arguments['toolName']);            
            if (!$tool) {
                return [
                    'success' => false,
                    'error' => "Tool '{$arguments['toolName']}' not found"
                ];
            }
            
            // Convert active parameter to boolean
            $isActive = (bool)$arguments['active'];
            
            // If spiritId is provided, store per-spirit setting
            if (isset($arguments['spiritId'])) {
                $spiritId = $arguments['spiritId'];
                $toolId = $tool->getId();
                
                // Get current active tool IDs for this spirit
                $activeToolIds = $this->aiToolService->getSpiritActiveToolIds($spiritId);
                
                // If no per-spirit config exists yet, initialize with all globally active tools
                if ($activeToolIds === null) {
                    $allTools = $this->aiToolService->findAll(false);
                    $activeToolIds = [];
                    foreach ($allTools as $t) {
                        if ($t->isActive()) {
                            $activeToolIds[] = $t->getId();
                        }
                    }
                }
                
                // Add or remove the tool ID based on active status
                if ($isActive) {
                    if (!in_array($toolId, $activeToolIds)) {
                        $activeToolIds[] = $toolId;
                    }
                } else {
                    $activeToolIds = array_filter($activeToolIds, fn($id) => $id !== $toolId);
                    $activeToolIds = array_values($activeToolIds); // re-index
                }
                
                // Save back to spirit settings
                $this->aiToolService->setSpiritActiveToolIds($spiritId, $activeToolIds);
                
                return [
                    'success' => true,
                    'tool' => [
                        'id' => $tool->getId(),
                        'name' => $tool->getName(),
                        'description' => $tool->getDescription(),
                        'isActive' => $isActive,
                        'isSpiritSpecific' => true,
                    ],
                    'message' => "Tool '{$tool->getName()}' is now " . ($isActive ? 'active' : 'inactive') . ' for this Spirit'
                ];
            }
            
            // Otherwise, update global tool state
            $updatedTool = $this->aiToolService->updateTool(
                $tool->getId(),
                ['isActive' => $isActive]
            );
            
            if (!$updatedTool) {
                return [
                    'success' => false,
                    'error' => 'Failed to update tool'
                ];
            }
            
            return [
                'success' => true,
                'tool' => [
                    'id' => $updatedTool->getId(),
                    'name' => $updatedTool->getName(),
                    'description' => $updatedTool->getDescription(),
                    'isActive' => $updatedTool->isActive(),
                    'isSpiritSpecific' => false,
                ],
                'message' => "Tool '{$updatedTool->getName()}' is now " . ($isActive ? 'active' : 'inactive') . ' globally'
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
}
