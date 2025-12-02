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
use Symfony\Component\String\Slugger\SluggerInterface;

class AiServiceResponseService
{
    public function __construct(
        private readonly UserDatabaseManager $userDatabaseManager,
        private readonly AiServiceRequestService $aiServiceRequestService,
        private readonly Security $security,
        private readonly ProjectFileService $projectFileService,
        private readonly LoggerInterface $logger,
        private readonly SluggerInterface $slugger
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

    public function saveAnnotationsToFile(AiServiceResponse $response, string $projectId = 'general'): array
    {
        $annotations = isset($response->getMessage()['annotations']) ? $response->getMessage()['annotations'] : null;
        if (!$annotations) {
            return [];
        }
        
        // Set annotations file path
        $filePath = '/annotations';
        $newFiles = [];

        foreach ($annotations as $annotation) {
            // Skip non-file annotations
            if (!isset($annotation['type']) || $annotation['type'] !== 'file') {
                continue;
            }
            
            // Skip annotations without file name
            if (!isset($annotation['file']) || !isset($annotation['file']['name'])) {
                $this->logger->warning('saveAnnotationsToFile(): Annotation file name not found');
                continue;
            }

            // Check for PDF file and update $filePath
            if (strtolower(pathinfo($annotation['file']['name'], PATHINFO_EXTENSION)) === 'pdf') {
                $filePath = '/annotations/pdf/' . $this->slugger->slug( $annotation['file']['name'] );
            } else {
                $filePath = '/annotations/' . $this->slugger->slug($annotation['file']['name']);
            }

            // Set file name and create file
            $fileName = $annotation['file']['name'] . '.anno';
            try {
                $newFile = $this->projectFileService->createFile($projectId, $filePath, $fileName, json_encode($annotation));
                $newFiles[] = $newFile;

                $this->logger->info('saveAnnotationsToFile(): Annotation file saved: ' . $fileName . ' (size: ' . $newFile->getSize() . ' bytes)');
            } catch (\Throwable $th) {
                $this->logger->error('saveAnnotationsToFile(): Error saving annotation file ' . $fileName . ': ' . $th->getMessage());
            }

            // Check all annotation.file.content for `image_url` data
            if (isset($annotation['file']['content']) && is_array($annotation['file']['content'])) {
                $i = 0;
                foreach ($annotation['file']['content'] as $content) {
                    // Check if content has image_url
                    $imageDataContent = isset($content['image_url']) && isset($content['image_url']['url']) ? $content['image_url']['url'] : null;
                    if ($imageDataContent) {
                        // Create image file
                        $imgFileName = $annotation['file']['name'] . '.img-' . $i . '.jpg';
                        try {
                            $i++;
                            $newImageFile = $this->projectFileService->createFile($projectId, $filePath, $imgFileName, $imageDataContent);
                            $newFiles[] = $newImageFile;

                            $this->logger->info('saveAnnotationsToFile(): Annotation image file saved: ' . $imgFileName . ' (size: ' . $newImageFile->getSize() . ' bytes)');
                        } catch (\Throwable $th) {
                            $this->logger->error('saveAnnotationsToFile(): Error saving annotation image file ' . $imgFileName . ': ' . $th->getMessage());
                        }
                    }
                }
            }

            // TODO future FEATURE: save annotations to '.annoz' file with ZIP compression
            // TODO future FEATURE: add hash check to prevent duplicate annotations
        }

        return $newFiles;
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
                        // index
                        $index = isset($image['index']) && is_int($image['index']) && $image['index'] > 0 ? '-' . $image['index'] : '';
                        // generate new filename, second part of mimetype is extension
                        $mimeType = mime_content_type($image['image_url']['url']);
                        $newFilename = '';
                        if ($filename === '') {
                            $newFilename = uniqid() . $index . '.' . (explode('/', $mimeType)[1] ?? pathinfo($image['image_url']['url'], PATHINFO_EXTENSION));
                        } else {
                            $filenameparts = pathinfo($filename);
                            $newFilename = $filenameparts['filename'] . $index . '.' . $filenameparts['extension'];
                        }

                        $newFile = $this->projectFileService->createFile($projectId, $filePath, $newFilename, $image['image_url']['url']);
                        $newFiles[] = [
                            'id' => $newFile->getId(),
                            'name' => $newFile->getName(),
                            'path' => $newFile->getPath(),
                            'projectId' => $projectId,
                            'fullPath' => $newFile->getFullPath(),
                            'size' => $newFile->getSize(),
                            'base64data' => $image['image_url']['url']
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
