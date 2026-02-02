<?php

namespace App\Service;

use App\Entity\AiServiceResponse;
use App\Entity\User;
use App\Service\UserDatabaseManager;
use App\Service\AiServiceRequestService;
use App\Service\ProjectFileService;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\User\UserInterface;

class AiServiceResponseService
{
    public function __construct(
        private readonly UserDatabaseManager $userDatabaseManager,
        private readonly AiServiceRequestService $aiServiceRequestService,
        private readonly Security $security,
        private readonly ProjectFileService $projectFileService,
        private readonly LoggerInterface $logger,
        private readonly AnnoService $annoService
    ) {
    }
    
    /**
     * Get a fresh database connection for the current user
     */
    private function getUserDb()
    {
        /** @var User $user */
        $user = $this->security->getUser();
        return $this->userDatabaseManager->getDatabaseConnection($user);
    }

    public function createResponse(
        string $aiServiceRequestId,
        array $message,
        array $fullResponse,
        ?string $finishReason = null,
        ?int $inputTokens = null,
        ?int $outputTokens = null,
        ?int $totalTokens = null
    ): AiServiceResponse {
        $response = new AiServiceResponse($aiServiceRequestId, $message, $fullResponse);
        
        if ($finishReason !== null) {
            $response->setFinishReason($finishReason);
        }
        
        if ($inputTokens !== null) {
            $response->setInputTokens($inputTokens);
        }
        
        if ($outputTokens !== null) {
            $response->setOutputTokens($outputTokens);
        }
        
        if ($totalTokens !== null) {
            $response->setTotalTokens($totalTokens);
        }
        
        // Store in user's database
        $userDb = $this->getUserDb();
        $userDb->executeStatement(
            'INSERT INTO ai_service_response (
                id, ai_service_request_id, message, full_response, finish_reason, 
                input_tokens, output_tokens, total_tokens, created_at
             ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $response->getId(),
                $response->getAiServiceRequestId(),
                $response->getMessageRaw(),
                $response->getFullResponseRaw(),
                $response->getFinishReason(),
                $response->getInputTokens(),
                $response->getOutputTokens(),
                $response->getTotalTokens(),
                $response->getCreatedAt()->format('Y-m-d H:i:s')
            ]
        );

        return $response;
    }

    public function findById(string $id): ?AiServiceResponse
    {
        $userDb = $this->getUserDb();
        $result = $userDb->executeQuery(
            'SELECT * FROM ai_service_response WHERE id = ?',
            [$id]
        )->fetchAssociative();

        if (!$result) {
            return null;
        }

        $response = AiServiceResponse::fromArray($result);
        
        // Load related request
        $request = $this->aiServiceRequestService->findById($response->getAiServiceRequestId());
        if ($request) {
            $response->setAiServiceRequest($request);
        }

        return $response;
    }

    public function findByRequest(string $requestId): ?AiServiceResponse
    {
        $userDb = $this->getUserDb();
        $result = $userDb->executeQuery(
            'SELECT * FROM ai_service_response WHERE ai_service_request_id = ? ORDER BY created_at DESC LIMIT 1',
            [$requestId]
        )->fetchAssociative();

        if (!$result) {
            return null;
        }

        $response = AiServiceResponse::fromArray($result);
        
        // Load related request
        $request = $this->aiServiceRequestService->findById($response->getAiServiceRequestId());
        if ($request) {
            $response->setAiServiceRequest($request);
        }

        return $response;
    }

    public function findRecent(int $limit = 100): array
    {
        $userDb = $this->getUserDb();
        $results = $userDb->executeQuery(
            'SELECT * FROM ai_service_response ORDER BY created_at DESC LIMIT ?',
            [$limit]
        )->fetchAllAssociative();

        return array_map(fn($data) => AiServiceResponse::fromArray($data), $results);
    }

    public function deleteResponse(string $id): bool
    {
        $userDb = $this->getUserDb();
        $result = $userDb->executeStatement(
            'DELETE FROM ai_service_response WHERE id = ?',
            [$id]
        );

        return $result > 0;
    }

    public function saveImagesFromMessage(AiServiceResponse $response, string $projectId = 'general', string $path = '/uploads/ai/img', string $filename = ''): array
    {
        $message = $response->getMessage();

        /* if (!isset($message['images']) || !is_array($message['images'])) {
            return [];
        } */
        /* message data structure:

            "message": {
                "content": "Certainly! Here is a pattern texture created from the image you provided. ",
                "images": [
                    {
                        "image_url": {
                            "url": "data:image/png;base64,..."
                        },
                        "index": 0,
                        "type": "image_url"
                    }
                ],
            
        */

        // check if message['content'] is string or array
        if (isset($message['content']) && is_string($message['content'])) {
            $originalContent = $message['content'];
            $message['content'] = [];
            $message['content'][] = [
                'text' => $originalContent,
                'type' => 'text'
            ];
        }

        $newFiles = [];

        $messages = $message['content'];
        if (isset($message['images']) && is_array($message['images'])) {
            $messages = array_merge($messages, $message['images']);
        }

        if (isset($messages) && is_array($messages)) {
            foreach ($messages as $image) {
                if (is_array($image) && 
                    isset($image['image_url']) && 
                    is_array($image['image_url']) && 
                    isset($image['image_url']['url'])) {
                    
                    $filePath = $path;
                    try {
                        $imageUrl = $image['image_url']['url'];
                        $imageContent = null;
                        $mimeType = null;
                        
                        // Check if URL is HTTP(S) - fetch the image content
                        if (str_starts_with($imageUrl, 'http://') || str_starts_with($imageUrl, 'https://')) {
                            // Fetch image from URL
                            $imageContent = @file_get_contents($imageUrl);
                            if ($imageContent === false) {
                                $this->logger->error('saveImageFromMessage(): Failed to fetch image from URL: ' . $imageUrl);
                                continue;
                            }
                            // Get mime type from URL extension or default to jpg
                            $urlPath = parse_url($imageUrl, PHP_URL_PATH);
                            $extension = pathinfo($urlPath, PATHINFO_EXTENSION) ?: 'jpg';
                            $mimeType = 'image/' . $extension;
                        } else {
                            // Base64 data - use mime_content_type
                            $mimeType = @mime_content_type($imageUrl);
                            if (!$mimeType) {
                                $mimeType = 'image/png'; // Default fallback
                            }
                        }
                        
                        // index
                        $index = isset($image['index']) && is_int($image['index']) && $image['index'] > 0 ? '-' . $image['index'] : '';
                        $newFilename = '';
                        
                        // Priority: 1) filename from image_url (user upload), 2) method parameter, 3) generate unique
                        if (isset($image['image_url']['filename']) && !empty($image['image_url']['filename'])) {
                            // Use original filename from user upload
                            $originalFilename = $image['image_url']['filename'];
                            $filenameparts = pathinfo($originalFilename);
                            // Ensure we have a valid extension, fallback to mime type
                            $extension = !empty($filenameparts['extension']) 
                                ? $filenameparts['extension'] 
                                : (explode('/', $mimeType)[1] ?? 'jpg');
                            $newFilename = $this->slugger->slug($filenameparts['filename']) . $index . '.' . $extension;
                        } elseif ($filename !== '') {
                            $filenameparts = pathinfo($filename);
                            $newFilename = $filenameparts['filename'] . $index . '.' . $filenameparts['extension'];
                        } else {
                            $newFilename = uniqid() . $index . '.' . (explode('/', $mimeType)[1] ?? 'jpg');
                        }

                        // If we fetched from URL, pass the binary content directly
                        $contentToSave = $imageContent !== null ? $imageContent : $imageUrl;
                        
                        $newFile = $this->projectFileService->createFile($projectId, $filePath, $newFilename, $contentToSave, $mimeType);
                        
                        // For base64data in response, convert fetched content to base64 data URL
                        $base64Data = $imageContent !== null 
                            ? 'data:' . $mimeType . ';base64,' . base64_encode($imageContent)
                            : $imageUrl;
                        
                        $newFiles[] = [
                            'id' => $newFile->getId(),
                            'name' => $newFile->getName(),
                            'path' => $newFile->getPath(),
                            'projectId' => $projectId,
                            'fullPath' => $newFile->getFullPath(),
                            'size' => $newFile->getSize(),
                            'base64data' => $base64Data
                        ];

                        $this->logger->info('saveImageFromMessage(): File created: ' . $newFile->getId() . ' ' . $newFile->getName() . ' (size: ' . $newFile->getSize() . ' bytes)');
                    } catch (\Exception $e) {
                        $this->logger->error('saveImageFromMessage(): Error creating file: ' . $e->getMessage());
                    }
                }
            }
        }

        return $newFiles;
    }
}
