<?php

namespace App\Service;

use App\Entity\AiServiceRequest;
use App\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Service for AI Tool diffusion image operations
 * 
 * Implements the diffusionArtistSpirit tool which:
 * 1. Receives natural language prompt from main Spirit
 * 2. Calls a specialized LLM to translate to diffusion keywords
 * 3. Parses JSON response with optimized params
 * 4. Calls CQ AI Gateway /api/ai/image-diffusion/* endpoints
 * 5. Saves images and returns frontend data
 * 
 * @see /docs/features/image-diffusion-spirit.md
 */
class AIToolDiffusionService
{
    public function __construct(
        private readonly ProjectFileService $projectFileService,
        private readonly Security $security,
        private readonly AiGatewayService $aiGatewayService,
        private readonly AiServiceRequestService $aiServiceRequestService,
        private readonly AiServiceResponseService $aiServiceResponseService,
        private readonly AiServiceModelService $aiServiceModelService,
        private readonly SettingsService $settingsService,
        private readonly HttpClientInterface $httpClient,
        private readonly ParameterBagInterface $params
    ) {
    }

    /**
     * Diffusion Artist Spirit - Generate images using diffusion models
     * 
     * This tool receives natural language prompts and translates them to
     * optimized keyword prompts for diffusion models via a specialized LLM.
     * 
     * @param array $arguments Tool arguments:
     *   - projectId: string (required) - Project ID for file storage
     *   - prompt: string (required) - Natural language description
     *   - width: int (optional) - Image width (default 1216)
     *   - height: int (optional) - Image height (default 1216)
     *   - seed: int (optional) - For reproducible results
     *   - style: string (optional) - Style hint (realistic, anime, etc.)
     * 
     * @return array Tool result with success status, files, and frontend data
     */
    public function diffusionArtistSpirit(array $arguments): array
    {
        $this->validateArguments($arguments, ['projectId', 'prompt']);
        
        try {
            // Get primary AI model for the Diffusion Artist (prompt translator)
            $aiServiceModel = null;
            $gateway = $this->aiGatewayService->findByName('CQ AI Gateway');
            if ($gateway) {
                $aiServiceModel = $this->aiServiceModelService->findByModelSlug('citadelquest/grok-4.1-fast', $gateway->getId());
            }
            if (!$aiServiceModel) {
                $aiServiceModel = $this->aiServiceModelService->findByModelSlug('citadelquest/kael', $gateway->getId());
            }
            if (!$aiServiceModel) {
                return [
                    'success' => false,
                    'error' => 'No AI service model configured for Diffusion Artist Spirit'
                ];
            }

            // Build the system prompt for the Diffusion Artist
            $systemPrompt = $this->buildDiffusionArtistSystemPrompt();
            
            // Build user message with the request
            $userMessage = $this->buildDiffusionArtistUserMessage($arguments);
            
            // Create messages array
            $messages = [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => $userMessage]
            ];
            
            // Create AI service request
            $aiServiceRequest = $this->aiServiceRequestService->createRequest(
                $aiServiceModel->getId(),
                $messages,
                null,
                0.5,
                null,
                []
            );
            
            // Get language from arguments
            $lang = $arguments['lang'] ?? 'English';
            
            // Send request to AI service (standard chat endpoint)
            $aiServiceResponse = $this->aiGatewayService->sendRequest(
                $aiServiceRequest, 
                'diffusionArtistSpirit AI Tool - Prompt Translation', 
                $lang, 
                $arguments['projectId']
            );
            
            // Parse the JSON response from Diffusion Artist
            $diffusionParams = $this->parseDiffusionArtistResponse($aiServiceResponse);
            
            // If parsing failed, retry once with error feedback
            if (!$diffusionParams) {
                $failedContent = $this->extractResponseContent($aiServiceResponse);
                
                // Build retry messages with error feedback
                $retryMessages = $messages;
                $retryMessages[] = ['role' => 'assistant', 'content' => $failedContent];
                $retryMessages[] = ['role' => 'user', 'content' => 
                    "Your response was not valid JSON and could not be parsed. " .
                    "Please respond with ONLY a valid JSON object - no markdown code blocks, no explanation text. " .
                    "The JSON must contain 'positivePrompt' as a required field. Start directly with { and end with }."
                ];
                
                // Create retry request
                $retryAiServiceRequest = $this->aiServiceRequestService->createRequest(
                    $aiServiceModel->getId(),
                    $retryMessages,
                    null,
                    0.3, // Lower temperature for more consistent output
                    null,
                    []
                );
                
                // Send retry request
                $retryResponse = $this->aiGatewayService->sendRequest(
                    $retryAiServiceRequest,
                    'diffusionArtistSpirit AI Tool - Prompt Translation (Retry)',
                    $lang,
                    $arguments['projectId']
                );
                
                $diffusionParams = $this->parseDiffusionArtistResponse($retryResponse);
                
                if (!$diffusionParams) {
                    return [
                        'success' => false,
                        'error' => 'Failed to parse Diffusion Artist response after retry. The AI did not return valid JSON.'
                    ];
                }
            }
            
            // Override with user-specified params if provided
            if (isset($arguments['width'])) {
                $diffusionParams['width'] = (int)$arguments['width'];
            }
            if (isset($arguments['height'])) {
                $diffusionParams['height'] = (int)$arguments['height'];
            }
            if (isset($arguments['seed'])) {
                $diffusionParams['seed'] = (int)$arguments['seed'];
            }
            
            // Determine the action (default to generate)
            $action = $diffusionParams['action'] ?? 'generate';
            
            // Call the appropriate CQ AI Gateway endpoint
            $imageResponse = $this->callDiffusionEndpoint($action, $diffusionParams);
            
            if (!$imageResponse['success']) {
                return [
                    'success' => false,
                    'error' => $imageResponse['error'] ?? 'Image generation failed'
                ];
            }
            
            // Save images from response
            $outputPath = (isset($arguments['projectPath']) ? $arguments['projectPath'] : '/uploads/ai/img-diffusion');
            $outputFilename = (isset($arguments['projectFileName']) ? $arguments['projectFileName'] : 'img_' . time() . '.jpg');
            
            $newFiles = $this->saveImagesFromDiffusionResponse($imageResponse, $arguments['projectId'], $outputPath, $outputFilename);
            
            // Build frontend display HTML
            $contentFrontendData = $this->buildFrontendDisplay($newFiles, $diffusionParams, $imageResponse['total_cost_credits']??0);
            
            $returnFiles = [];
            $returnSavedTo = [];
            foreach ($newFiles as $savedFile) {
                $returnFiles[] = $this->projectFileService->findById($savedFile['id']);
                $returnSavedTo[] = '`' . $savedFile['path'] . '/' . $savedFile['name'] . '`';
            }
            
            return [
                'success' => true,
                'files' => $returnFiles,
                'message' => 'Diffusion image generation successful. Saved to [' . implode(', ', $returnSavedTo) . '] and displayed in the user interface.',
                'diffusionParams' => [
                    'positivePrompt' => $diffusionParams['positivePrompt'] ?? '',
                    'negativePrompt' => $diffusionParams['negativePrompt'] ?? '',
                    'model' => $diffusionParams['model'] ?? 'cq:100@1',
                    'seed' => $imageResponse['images'][0]['seed'] ?? null,
                ],
                '_frontendData' => $contentFrontendData,
                'total_cost_credits' => $imageResponse['total_cost_credits'] ?? 0,
            ];
            
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => 'Error in Diffusion Artist Spirit: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * Build the system prompt for the Diffusion Artist LLM
     */
    private function buildDiffusionArtistSystemPrompt(): string
    {
        return <<<'PROMPT'
You are the Diffusion Artist Spirit - an expert at translating natural language image descriptions into optimized prompts for AI diffusion models (Stable Diffusion, FLUX, Pony models, etc.).

## Your Task
Convert the user's natural language description into a structured JSON response with optimized diffusion model parameters.

## Available Models (choose the best model based on the style requested)
- `cq:100@1` - CyberRealistic Pony v15.0: Best for realistic photos, portraits, anime-realistic blend. NSFW capable. Use `score_9, score_8_up, score_7_up` in prompt for proper activation.
- `runware:101@1` - FLUX.1 Dev: High quality, excellent prompt understanding, artistic styles.

## Prompting Guidelines

### Positive Prompt Structure
1. **Whole scene first**: Start with the main subject and environment (female, male, landscape, etc.)
2. **Details**: Add specific details (hair color, clothing, pose, expression, environment details, etc.)
3. **Style tags**: Add style modifiers at the end
4. **Use commas** to separate concepts
5. **Content tags** use keywords only for content that will visually appear on the final image - each keyword will be rendered to final image. There should be at least one keyword for content that will visually appear on the final image.
6. **Less is more**: Use as few tags as possible to achieve the desired effect!!

### Negative Prompt (things to avoid)
Common negative prompts for quality:
- `bad anatomy, bad hands, missing fingers, extra fingers, deformed, ugly, mutated`
- `blurry, low quality, worst quality, jpeg artifacts`
- `watermark, signature, text`
- all `no ..` should be in the negative prompt
- opposite meanings of tags for most important tags should be in the negative prompt

### Style-Specific Tips
- **Realistic photos**: Use `photograph of ..`, `camera` in negative prompt to avoid objects of actual "camera" in the image
- **Anime**: Use `anime, manga, illustration, 2d`
- **Artistic**: Use `painting, artwork, digital art`
- **Cyberpunk**: Use `cyberpunk, neon lights, futuristic, sci-fi`
- **NSFW**: Use `young female, male,` instead of `1girl, girl, boy, child` in prompt to prevent forbidden content.

## Output Format
You MUST respond with ONLY a valid JSON object (no markdown, no explanation):

```json
{
    "action": "generate",
    "positivePrompt": "your optimized positive prompt here",
    "negativePrompt": "your optimized negative prompt here",
    "model": "model id here",
    "width": "user provided width or 1216",
    "height": "user provided height or 1216",
    "steps": 36,
    "CFGScale": 3.3,
    "scheduler": "FlowMatchEulerDiscreteScheduler",
    "seed": "user provided seed or null",
    "lora": [],
    "numberResults": 1
}
```

## Important Rules
1. ONLY output the JSON object, nothing else
2. Choose the best model based on the style requested
3. Adjust dimensions for the content (portrait: 832x1216, landscape: 1216x832, square: 1216x1216)
4. Keep prompts concise but descriptive
PROMPT;
    }
    
    /**
     * Build the user message for the Diffusion Artist
     */
    private function buildDiffusionArtistUserMessage(array $arguments): string
    {
        $message = "Generate an image based on this description:\n\n";
        $message .= "**Description**: " . $arguments['prompt'] . "\n\n";

        $message .= "**Scheduler**: FlowMatchEulerDiscreteScheduler\n";
        
        if (!empty($arguments['style'])) {
            $message .= "**Style hint**: " . $arguments['style'] . "\n";
        }
        
        if (!empty($arguments['width']) || !empty($arguments['height'])) {
            $message .= "**Requested dimensions**: ";
            $message .= ($arguments['width'] ?? 'auto') . " x " . ($arguments['height'] ?? 'auto') . "\n";
        }
        
        if (!empty($arguments['seed'])) {
            $message .= "**Seed**: " . $arguments['seed'] . " (use this exact seed)\n";
        }
        
        $message .= "\nRespond with ONLY the JSON object, no other text.";
        
        return $message;
    }
    
    /**
     * Extract content string from AI service response
     */
    private function extractResponseContent($aiServiceResponse): string
    {
        $message = $aiServiceResponse->getMessage();
        $content = $message['content'] ?? '';
        
        if (is_array($content)) {
            foreach ($content as $item) {
                if (isset($item['type']) && $item['type'] === 'text') {
                    return $item['text'];
                }
            }
            return '';
        }
        
        return is_string($content) ? $content : '';
    }
    
    /**
     * Parse the Diffusion Artist's JSON response
     */
    private function parseDiffusionArtistResponse($aiServiceResponse): ?array
    {
        $message = $aiServiceResponse->getMessage();
        $content = $message['content'] ?? '';
        
        if (is_array($content)) {
            // Handle multimodal response format
            foreach ($content as $item) {
                if (isset($item['type']) && $item['type'] === 'text') {
                    $content = $item['text'];
                    break;
                }
            }
        }
        
        if (!is_string($content)) {
            return null;
        }
        
        // Try to extract JSON from the response
        // First, try direct parse
        $decoded = json_decode($content, true);
        if ($decoded && isset($decoded['positivePrompt'])) {
            return $decoded;
        }
        
        // Try to find JSON in markdown code block
        if (preg_match('/```(?:json)?\s*(\{[\s\S]*?\})\s*```/', $content, $matches)) {
            $decoded = json_decode($matches[1], true);
            if ($decoded && isset($decoded['positivePrompt'])) {
                return $decoded;
            }
        }
        
        // Try to find raw JSON object
        if (preg_match('/\{[\s\S]*"positivePrompt"[\s\S]*\}/', $content, $matches)) {
            $decoded = json_decode($matches[0], true);
            if ($decoded && isset($decoded['positivePrompt'])) {
                return $decoded;
            }
        }
        
        return null;
    }
    
    /**
     * Call the CQ AI Gateway diffusion endpoint
     */
    private function callDiffusionEndpoint(string $action, array $params): array
    {
        // Get the CQ AI Gateway configuration
        $gateway = $this->aiGatewayService->findByName('CQ AI Gateway');
        if (!$gateway) {
            return ['success' => false, 'error' => 'CQ AI Gateway not configured'];
        }
        
        $baseUrl = $gateway->getApiEndpointUrl();
        $apiKey = $gateway->getApiKey();
        
        // Determine endpoint based on action
        $endpoint = match($action) {
            'transform' => '/ai/image-diffusion/transform',
            'inpaint' => '/ai/image-diffusion/inpaint',
            default => '/ai/image-diffusion/generate',
        };
        
        try {
            $response = $this->httpClient->request('POST', $baseUrl . $endpoint, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $apiKey,
                    'Content-Type' => 'application/json',
                ],
                'json' => $params,
                'timeout' => 180, // 3 minutes for image generation
            ]);
            
            $data = json_decode($response->getContent(), true);
            
            return $data;
            
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => 'Failed to call diffusion endpoint: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * Save images from the diffusion API response
     */
    private function saveImagesFromDiffusionResponse(array $response, string $projectId, string $outputPath, string $baseFilename): array
    {
        $savedFiles = [];
        $images = $response['images'] ?? [];
        
        foreach ($images as $index => $imageData) {
            $imageUrl = $imageData['imageURL'] ?? null;
            if (!$imageUrl) {
                continue;
            }
            
            try {
                // Fetch the image content
                $imageContent = file_get_contents($imageUrl);
                if (!$imageContent) {
                    continue;
                }
                
                // Determine filename
                $pathInfo = pathinfo($baseFilename);
                $filename = $pathInfo['filename'];
                $extension = $pathInfo['extension'] ?? 'jpg';
                
                if ($index > 0) {
                    $filename .= '_' . ($index + 1);
                }
                $finalFilename = $filename . '.' . $extension;
                
                // Get MIME type
                $finfo = new \finfo(FILEINFO_MIME_TYPE);
                $mimeType = $finfo->buffer($imageContent);
                
                // Save the file - pass raw binary content, not base64
                // (createFile only decodes base64 if it has 'data:' prefix)
                $savedFile = $this->projectFileService->createFile(
                    $projectId,
                    $outputPath,
                    $finalFilename,
                    $imageContent,
                    $mimeType
                );
                
                if ($savedFile) {
                    $savedFiles[] = [
                        'id' => $savedFile->getId(),
                        'path' => $outputPath,
                        'name' => $finalFilename,
                        'fullPath' => $outputPath . '/' . $finalFilename,
                        'projectId' => $projectId,
                        'base64data' => 'data:' . $mimeType . ';base64,' . base64_encode($imageContent),
                        'seed' => $imageData['seed'] ?? null,
                    ];
                }
                
            } catch (\Exception $e) {
                error_log('Failed to save diffusion image: ' . $e->getMessage());
            }
        }
        
        return $savedFiles;
    }
    
    private function renderDiffusionParam($title, $value, $width = "w-100"): string
    {
        return '
            <div class="d-inline-block ' . $width . ' float-start mb-2">
                <div>' . $title . '</div>
                <code class="d-block" style="font-size: 0.6rem !important;">' . $value . '</code>
            </div>
        ';
    }

    /**
     * Build the frontend display HTML
     */
    private function buildFrontendDisplay(array $savedFiles, array $diffusionParams, float $total_cost_credits): string
    {
        $uniqueId = uniqid();
        $detailsPanelId = 'diffusion-details-' . $uniqueId;
        $imageContainerId = 'diffusion-image-' . $uniqueId;
        $html = '<div class="col-12 position-relative bg-light p-2 rounded bg-opacity-10">';
        
        // Header with Diffusion Artist info and toggle icon
        $html .= '<div class="text-start ms-2 d-inline-block w-100 mb-1 position-relative">';
        $html .= '  <i class="mdi mdi-palette text-cyber fs-5 float-start me-2"></i>';
        $html .= '  <div class="small float-start mt-2 fw-bold">Diffusion Artist Spirit</div>';
        // Toggle icon for details panel (upper right) - toggles both details panel visibility and image container width
        $html .= '  <div class="position-absolute top-0 end-0 me-2 cursor-pointer text-muted" onclick="document.getElementById(\'' . $detailsPanelId . '\').classList.toggle(\'d-none\'); document.getElementById(\'' . $imageContainerId . '\').classList.toggle(\'w-50\'); this.querySelector(\'i\').classList.toggle(\'mdi-information-outline\'); this.querySelector(\'i\').classList.toggle(\'mdi-information-off-outline\');" title="Toggle generation details">';
        $html .= '    <i class="mdi mdi-information-outline fs-5"></i>';
        $html .= '  </div>';
        $html .= '</div>';
        $html .= '<div style="clear:both;"></div>';
        
        // Display each generated image
        foreach ($savedFiles as $savedFile) {
            $randomID = uniqid();
            $html .= '<div class="w-100 m-0 p-0 mb-2 position-relative" id="content-showcase-' . $randomID . '">';
            $html .= '  <div id="' . $imageContainerId . '" class="d-inline-block position-relative m-0">';
            $html .= '    <img src="' . $savedFile['base64data'] . '" alt="' . htmlspecialchars($savedFile['name']) . '" style="max-width: 100%; max-height: 70vh;" class="rounded float-end"/>';
            $html .= '    <div class="content-showcase-icon position-absolute top-0 end-0 p-1 badge bg-dark bg-opacity-25 text-cyber cursor-pointer">';
            $html .= '      <i class="mdi mdi-fullscreen"></i>';
            $html .= '    </div>';
            $html .= '    <div style="clear: both;"></div>';
            $total_cost_credits_parts = explode(".", $total_cost_credits);
            $total_cost_credits_display = $total_cost_credits_parts[0] . '<span class="opacity-75">.' . $total_cost_credits_parts[1] . '</span>';
            $html .= '    <div class="small text-muted float-start mt-2"><i class="mdi mdi-circle-multiple-outline me-1 ms-2 text-cyber opacity-50" title="Credits"></i> ' . $total_cost_credits_display . '</div>';
            // Download button
            $html .= '    <a class="btn btn-sm btn-outline-primary mt-3 float-end me-2" href="/api/project-file/' . $savedFile['id'] . '/download?download=1">';
            $html .= '      <i class="mdi mdi-download"></i>';
            $html .= '    </a>';
            $html .= '  </div>';
            // File info (hidden by default, toggle with info icon)
            $html .= '  <div id="' . $detailsPanelId . '" class="float-end d-none text-start mt-1 w-50 m-0 px-3" style="font-size: 0.8rem !important;">';
            $html .= $this->renderDiffusionParam('Image file', htmlspecialchars($savedFile['fullPath']));

            if ($savedFile['seed']) {
                $html .= $this->renderDiffusionParam('Seed', $savedFile['seed'], "w-50 pe-2");
            }
            if ($diffusionParams['width'] && $diffusionParams['height']) {
                $html .= $this->renderDiffusionParam('Size', $diffusionParams['width'] . 'x' . $diffusionParams['height'], "w-50");
            }
            $html .= $this->renderDiffusionParam('Model', $diffusionParams['model'] ?? 'cq:100@1');
            if ($diffusionParams['positivePrompt']) {
                $html .= $this->renderDiffusionParam('Positive Prompt', $diffusionParams['positivePrompt']);
            }
            if ($diffusionParams['negativePrompt']) {
                $html .= $this->renderDiffusionParam('Negative Prompt', $diffusionParams['negativePrompt']);
            }
            if ($diffusionParams['steps']) {
                $html .= $this->renderDiffusionParam('Steps', $diffusionParams['steps'], "w-50 pe-2");
            }
            if ($diffusionParams['CFGScale']) {
                $html .= $this->renderDiffusionParam('CFGScale', $diffusionParams['CFGScale'], "w-50");
            }
            if ($diffusionParams['scheduler']) {
                $html .= $this->renderDiffusionParam('Scheduler', $diffusionParams['scheduler']);
            }
            if ($diffusionParams['lora']) {
                $html .= $this->renderDiffusionParam('LoRA', nl2br(print_r($diffusionParams['lora'], true)));
            }
            if ($diffusionParams['numberResults']) {
                $html .= $this->renderDiffusionParam('Number Results', $diffusionParams['numberResults']);
            }

            $html .= ' </div>';
            $html .= ' <div style="clear: both;"></div>';
            
            
            $html .= '</div>';
            $html .= '<div style="clear:both;"></div>';
        }
        
        $html .= '</div>';
        $html .= '<div style="clear:both;"></div>';
        
        return $html;
    }
    
    /**
     * Validate required arguments
     */
    private function validateArguments(array $arguments, array $required): void
    {
        foreach ($required as $arg) {
            if (!isset($arguments[$arg])) {
                throw new \InvalidArgumentException("Missing required argument: $arg");
            }
        }
    }
}
