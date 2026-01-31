<?php

namespace App\Service;

use App\Entity\AiServiceRequest;
use App\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

/**
 * Service for AI Tool image operations
 */
class AIToolImageService
{
    public function __construct(
        private readonly ProjectFileService $projectFileService,
        private readonly Security $security,
        private readonly AiGatewayService $aiGatewayService,
        private readonly AiServiceRequestService $aiServiceRequestService,
        private readonly AiServiceResponseService $aiServiceResponseService,
        private readonly AiServiceModelService $aiServiceModelService,
        private readonly SettingsService $settingsService,
        private readonly ParameterBagInterface $params
    ) {
    }
    
    /**
     * Edit an image using AI
     */
    public function imageEditorSpirit(array $arguments): array
    {
        $this->validateArguments($arguments, ['projectId', 'textPrompt']);
        
        try {
            // Get AI model for image editing (settings: ai.secondary_ai_service_model_id)
            $secondaryModelId = $this->settingsService->getSettingValue('ai.secondary_ai_service_model_id');
            $aiServiceModel = null;
            if ($secondaryModelId) {
                $aiServiceModel = $this->aiServiceModelService->findById($secondaryModelId);
            } else {
                // Get default AI model for image editing (CQ AI Gateway)
                $gateway = $this->aiGatewayService->findByName('CQ AI Gateway');
                $aiServiceModel = $this->aiServiceModelService->findByModelSlug('citadelquest/gemini-2.5-flash-image', $gateway->getId());
            }
            if (!$aiServiceModel) {
                return [
                    'success' => false,
                    'error' => 'No Image Editor AI service model configured'
                ];
            }

            $systemPrompt = 'You are an expert image editor and creator. Edit the provided image according to the user\'s instructions.';
            
            // Prepare input images
            $inputImages = [];
            $inputImageFiles = [];
            if (isset($arguments['inputImageFiles']) && !empty($arguments['inputImageFiles'])) {
                // Parse comma-separated string of file paths (simplified format for better LLM compatibility)
                $filePaths = is_array($arguments['inputImageFiles']) 
                    ? $arguments['inputImageFiles'] // Legacy array support
                    : array_map('trim', explode(',', $arguments['inputImageFiles']));
                
                foreach ($filePaths as $filePath) {
                    // Handle legacy array format with 'imageFile' key
                    $imageFile = is_array($filePath) && isset($filePath['imageFile']) 
                        ? $filePath['imageFile'] 
                        : $filePath;
                    
                    if (empty($imageFile)) {
                        continue;
                    }
                    
                    // Find the file
                    $pathParts = pathinfo($imageFile);
                    $file = $this->projectFileService->findByPathAndName(
                        $arguments['projectId'],
                        $pathParts['dirname'],
                        $pathParts['basename']
                    );
                    
                    if (!$file) {
                        return [
                            'success' => false,
                            'error' => 'Input image file not found: ' . $imageFile
                        ];
                    }
                    
                    // Get file content as base64
                    $content = $this->projectFileService->getFileContent($file->getId()); // Already base64 encoded
                    $inputImages[] = [
                        'type' => 'image_url',
                        'image_url' => [
                            'url' => $content
                        ]
                    ];

                    $inputImageFiles[] = [
                        'path' => $pathParts['dirname'],
                        'name' => $pathParts['basename'],
                        'base64data' => $content
                    ];
                }
            }
            
            if (empty($inputImages)) {
                $systemPrompt = 'You are an expert image creator. Create the image(s) according to the user\'s instructions.';
            }
            
            // Create messages array with text prompt and images
            $messages = [
                [
                    'role' => 'system',
                    'content' => $systemPrompt . '<clean_system_prompt>'
                ],
                [
                    'role' => 'user',
                    'content' => array_merge(
                        [
                            [
                                'type' => 'text',
                                'text' => $arguments['textPrompt']
                            ]
                        ],
                        $inputImages
                    )
                ]
            ];
            
            // Create AI service request
            $aiServiceRequest = $this->aiServiceRequestService->createRequest(
                $aiServiceModel->getId(),
                $messages,
                null,
                0.1,
                null,
                []
            );
            
            // Get language from arguments or default to English
            $lang = isset($arguments['lang']) ? $arguments['lang'] : 'English';
            
            // Send request to AI service
            $aiServiceResponse = $this->aiGatewayService->sendRequest($aiServiceRequest, 'imageEditorSpirit AI Tool', $lang, $arguments['projectId']);
            
            // Check for content filter
            $fullResponse = isset($aiServiceResponse->getFullResponse()['choices'][0]) ? $aiServiceResponse->getFullResponse()['choices'][0] : null;
            if ($aiServiceResponse->getFinishReason() === 'content_filter' || 
                (isset($fullResponse['finish_reason']) && $fullResponse['finish_reason'] === 'content_filter') ||
                (isset($fullResponse['native_finish_reason']) && $fullResponse['native_finish_reason'] === 'PROHIBITED_CONTENT')) {
                return [
                    'success' => false,
                    'error' => 'Content filter triggered. The image edit request or response may contain prohibited content.'
                ];
            }

            // Get usage from full response
            $fullResponse = $aiServiceResponse->getFullResponse();
            $usage = isset($fullResponse['usage']) ? $fullResponse['usage'] : null;
            $totalCost = isset($usage['cost']) ? number_format($usage['cost']*100.0, 2) : null;
            $total_cost_credits_parts = explode(".", $totalCost);
            $total_cost_credits_display = $total_cost_credits_parts[0] . '<span class="opacity-75">.' . $total_cost_credits_parts[1] . '</span>';
            
            // Determine output path
            $outputPath = '/uploads/ai/img';
            $outputFilename = 'edited_image_' . time() . '.png';
            
            if (isset($arguments['outputImageFile']) && !empty($arguments['outputImageFile'])) {
                $pathParts = pathinfo($arguments['outputImageFile']);
                $outputPath = $pathParts['dirname'];
                $outputFilename = $pathParts['basename'];
            }

            // Save images from response
            $newFiles = $this->aiServiceResponseService->saveImagesFromMessage($aiServiceResponse, $arguments['projectId'], $outputPath, $outputFilename);
            
            //$savedFile = isset($newFiles[0]) ? $newFiles[0] : null;
            
            $messageFromAI = isset($aiServiceResponse->getMessage()['content']) && is_string($aiServiceResponse->getMessage()['content']) ? 
                $aiServiceResponse->getMessage()['content'] : null;
            
            // Create HTML for frontend display
            $contentFrontendData = '<div class="mb-3 col-12 col-md-4 float-start text-center px-3 position-relative">';
            foreach ($inputImageFiles as $inputImage) {
                $contentFrontendData .= '<img src="'. $inputImage['base64data'] . '" alt="'. $inputImage['name'] . '" style="width: 100%; height: auto;" class="rounded mb-0 border border-light border-opacity-10"/>';
                $contentFrontendData .= '<div class="text-start small p mb-2 mx-2 bg-light bg-opacity-10 rounded-bottom" style="font-size: 0.6rem !important;">';
                $contentFrontendData .=     '<i class="mdi mdi-file-image text-cyber fs-6 mx-1 opacity-75"></i>';
                $contentFrontendData .=     '<span class="text-muted">original:</span> ' . $inputImage['name'];
                $contentFrontendData .= '</div>';
            }
            $contentFrontendData .= '</div>';

            $returnFiles = [];
            $returnSavedTo = [];
            if ($newFiles && is_array($newFiles) && count($newFiles) > 0) {
                $contentFrontendData .= '<div class="col-12 col-md-8 float-end text-center position-relative bg-light p-2 rounded bg-opacity-10 border border-success border-opacity-10">';

                $contentFrontendData .=     '<div class="text-start ms-2">';
                $contentFrontendData .=         '<i class="mdi mdi-image-edit-outline text-cyber fs-6 float-start me-2 mb-2"></i>';
                // if ':" in model's name, let's use just last part
                $modelName_display = $aiServiceModel->getModelName();
                if (strpos($modelName_display, ':') !== false) {
                    $modelName_display = substr($modelName_display, strpos($modelName_display, ':') + 1);
                }
                $contentFrontendData .=         '<span class="small float-start mt-1 fw-bold">Image Editor Spirit</span><span class="small float-start mt-1 ms-2">'. $modelName_display .'</span>';
                if ($messageFromAI) {
                    $contentFrontendData .=     '<div class="small text-start float-start my-1">';
                    $contentFrontendData .=         '<div class="ps-1 pb-1">' . nl2br(htmlspecialchars($messageFromAI)) . '</div>';
                    $contentFrontendData .=     '</div>';
                }
                $contentFrontendData .=     '</div>';
                $contentFrontendData .=     '<div style="clear:both;"></div>';

                foreach ($newFiles as $savedFile) {
                    $randomID = uniqid();
                    $contentFrontendData .= '<div class="w-100 m-0 p-0 mb-2 text-center position-relative" id="content-showcase-'.$randomID.'">';
                    $contentFrontendData .=     '<img src="'. $savedFile['base64data'] . '" alt="'. $savedFile['name'] . '" style="width: 100%; height: auto;" class="rounded"/>';
                    $contentFrontendData .=     '<div class="content-showcase-icon position-absolute top-0 end-0 p-1 _py-2 badge bg-dark bg-opacity-25 text-cyber cursor-pointer">' .
                                                    '<i class="mdi mdi-fullscreen"></i>' .
                                                '</div>';
                    if ($totalCost !== null) {
                        $contentFrontendData .= '<div class="small float-start text-start mt-1 ms-2 opacity-75" title="Credits"><i class="mdi mdi-circle-multiple-outline me-1 text-cyber opacity-50"></i> ' . $total_cost_credits_display . '</div>';
                    }
                    $contentFrontendData .=     '<div class="small float-end text-end mt-1 me-2 opacity-75">
                                                    <i class="mdi mdi-folder text-cyber ms-2 me-1"></i>' . $savedFile['projectId'] . '
                                                    <i class="mdi mdi-file-image text-cyber ms-2 me-1"></i>' . $savedFile['fullPath'] . '
                                                </div>';
                    $contentFrontendData .=     '<div style="clear: both;"></div>';
                    $contentFrontendData .=     '<a class="btn btn-outline-primary btn-sm mt-2 float-end me-2 mb-2" href="/api/project-file/' . $savedFile['id'] . '/download?download=1"><i class="mdi mdi-download"></i></a>';
                    $contentFrontendData .= '</div>';
                    $contentFrontendData .= '<div style="clear:both;"></div>';

                    $returnFiles[] = $this->projectFileService->findById($savedFile['id']);
                    $returnSavedTo[] = '`'.$savedFile['path'].'/'.$savedFile['name'].'`';
                }
                $contentFrontendData .= '</div>';
            }

            $contentFrontendData .= '<div style="clear:both;"></div>';

            // add content showcase icon event listener - no need to do it here - works already in conversations
            
            $return = [
                'success' => true,
                'files' => $returnFiles,            
                'message' => 'Image edit successful. Saved to [. '.implode(', ', $returnSavedTo).'.] and displayed in the user interface.',
                'inputImages' => count($inputImages),
                '_frontendData' => $contentFrontendData
            ];
            
            if ($messageFromAI) {
                $return['messageFromAI'] = $messageFromAI;
            }

            return $return;
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => 'Error editing image: ' . $e->getMessage()
            ];
        }
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
