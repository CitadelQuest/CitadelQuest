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
            if (isset($arguments['inputImageFiles']) && is_array($arguments['inputImageFiles']) && count($arguments['inputImageFiles']) > 0) {
                foreach ($arguments['inputImageFiles'] as $inputImageFile) {
                    if (!isset($inputImageFile['imageFile'])) {
                        continue;
                    }
                    
                    // Find the file
                    $pathParts = pathinfo($inputImageFile['imageFile']);
                    $file = $this->projectFileService->findByPathAndName(
                        $arguments['projectId'],
                        $pathParts['dirname'],
                        $pathParts['basename']
                    );
                    
                    if (!$file) {
                        return [
                            'success' => false,
                            'error' => 'Input image file not found: ' . $inputImageFile['imageFile']
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
                    'content' => $systemPrompt
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
                $contentFrontendData .=     '<i class="mdi mdi-image-outline text-cyber fs-6 mx-1"></i>';
                $contentFrontendData .=     '<span class="text-muted">original:</span> ' . $inputImage['name'];
                $contentFrontendData .= '</div>';
            }
            $contentFrontendData .= '</div>';

            $returnFiles = [];
            $returnSavedTo = [];
            if ($newFiles && is_array($newFiles) && count($newFiles) > 0) {
                $contentFrontendData .= '<div class="col-12 col-md-8 float-end text-center position-relative bg-light p-2 rounded bg-opacity-10 border border-success border-opacity-10">';

                $contentFrontendData .=     '<div class="text-start ms-2">';
                $contentFrontendData .=         '<i class="mdi mdi-image-edit-outline text-cyber fs-5 float-start me-2"></i>';
                if ($messageFromAI) {
                    $contentFrontendData .=     '<div class="small text-start float-start">';
                    $contentFrontendData .=         '<div class="fw-bold">Image Editor Spirit Comments</div>';
                    $contentFrontendData .=         '<div class="ps-1 pb-1">' . nl2br(htmlspecialchars($messageFromAI)) . '</div>';
                    $contentFrontendData .=     '</div>';
                }
                $contentFrontendData .=     '</div>';
                $contentFrontendData .=     '<div style="clear:both;"></div>';

                foreach ($newFiles as $savedFile) {
                    $randomID = uniqid();
                    $contentFrontendData .= '<div class="w-100 m-0 p-0 mb-2 text-center position-relative" id="content-showcase-'.$randomID.'">';
                    $contentFrontendData .=     '<img src="'. $savedFile['base64data'] . '" alt="'. $savedFile['name'] . '" style="width: 100%; height: auto;" class="rounded shadow"/>';
                    $contentFrontendData .=     '<div class="content-showcase-icon position-absolute top-0 end-0 p-1 _py-2 badge bg-dark bg-opacity-25 text-cyber cursor-pointer">' .
                                                    '<i class="mdi mdi-fullscreen"></i>' .
                                                '</div>';
                    $contentFrontendData .=     '<div class="small float-end text-end">Image file: `' . $savedFile['fullPath'] . '`<br>projectId: `' . $savedFile['projectId'] . '`</div><div style="clear: both;"></div>';
                    $contentFrontendData .=     '<a class="btn btn-cyber btn-sm mt-2 float-end me-2 mb-3" href="/api/project-file/' . $savedFile['id'] . '/download"><i class="mdi mdi-download me-2"></i> Download</a>';
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
