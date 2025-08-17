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
    public function listAITools(array $arguments): array
    {
        try {
            // arg: activeOnly
            $activeOnly = isset($arguments['activeOnly']) ? $arguments['activeOnly'] : false;
            
            // Get all tools
            $allTools = $this->aiToolService->findAll($activeOnly);
            
            // Format the response
            $formattedTools = array_map(function(AiTool $tool) {
                return [
                    'id' => $tool->getId(),
                    'name' => $tool->getName(),
                    'description' => $tool->getDescription(),
                    'isActive' => $tool->isActive(),
                    //'parameters' => $tool->getParameters(),
                ];
            }, $allTools);
            
            return [
                'success' => true,
                'tools' => $formattedTools,
                'activeCount' => count(array_filter($allTools, fn(AiTool $tool) => $tool->isActive())),
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
    public function setAIToolActive(array $arguments): array
    {
        // Validate required arguments
        if (!isset($arguments['toolName']) || !isset($arguments['active'])) {
            return [
                'success' => false,
                'error' => 'Missing required arguments: toolName and active'
            ];
        }

        // skip `this` tool
        if ($arguments['toolName'] == 'setAIToolActive') {
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
            
            // Update the tool
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
                ],
                'message' => "Tool '{$updatedTool->getName()}' is now " . ($isActive ? 'active' : 'inactive')
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
}
