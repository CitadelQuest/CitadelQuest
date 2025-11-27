<?php

namespace App\Api\Controller;

use App\Entity\AiServiceResponse;
use App\Service\SpiritConversationService;
use App\Service\SpiritService;
use App\Service\SettingsService;
use App\Service\AiServiceResponseService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use App\Service\AiGatewayService;
use App\CitadelVersion;

#[Route('/api/spirit-conversation')]
#[IsGranted('ROLE_USER')]
class SpiritConversationApiController extends AbstractController
{   
    public function __construct(
        private readonly SpiritConversationService $conversationService, 
        private readonly SpiritService $spiritService, 
        private readonly SettingsService $settingsService,
        private readonly AiServiceResponseService $aiServiceResponseService)
    {
    }
    
    #[Route('/list/{spiritId}', name: 'api_spirit_conversation_list', methods: ['GET'])]
    public function listConversations(string $spiritId, Request $request): JsonResponse
    {
        try {
            // Get conversations, without 'messages' field
            $conversations = $this->conversationService->getConversationsBySpirit($spiritId);            
            
            return $this->json($conversations);
        } catch (\Exception $e) {
            return $this->json(['error' => $e->getMessage(), 'success' => false], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
    
    #[Route('/create', name: 'api_spirit_conversation_create', methods: ['POST'])]
    public function createConversation(Request $request): JsonResponse
    {
        try {
            $data = json_decode($request->getContent(), true);
            if (!isset($data['spiritId']) || !isset($data['title'])) {
                return $this->json(['error' => 'Missing required parameters'], 400);
            }
            
            // Get the user's spirit
            $spirit = $this->spiritService->getUserSpirit();
            if (!$spirit) {
                return $this->json(['error' => 'Spirit not found'], 404);
            }
            
            // Verify it's the requested spirit
            if ($spirit->getId() !== $data['spiritId']) {
                return $this->json(['error' => 'Spirit not found'], 404);
            }
            
            // Create conversation
            $conversation = $this->conversationService->createConversation(
                $data['spiritId'],
                $data['title']
            );
            
            return $this->json($conversation);
        } catch (\Exception $e) {
            return $this->json(['error' => $e->getMessage(), 'success' => false], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    #[Route('/credit-balance', name: 'api_spirit_conversation_credit_balance', methods: ['GET'])]
    public function getCreditBalance(AiGatewayService $aiGatewayService, HttpClientInterface $httpClient): JsonResponse
    {
        try {
            $gateway = $aiGatewayService->findByName('CQ AI Gateway');
            if (!$gateway) {
                throw new \Exception('CQ AI Gateway not found');
            }
            
            // Check if API key is configured
            $apiKey = $gateway->getApiKey();
            if (empty($apiKey)) {
                throw new \Exception('CQ AI Gateway API key not configured');
            }
            
            $responseProfile = $httpClient->request(
                    'GET',
                    $gateway->getApiEndpointUrl() . '/payment/balance', 
                    [
                        'headers' => [
                            'Authorization' => 'Bearer ' . $gateway->getApiKey(),
                            'Content-Type' => 'application/json',
                            'User-Agent' => 'CitadelQuest ' . CitadelVersion::VERSION . ' HTTP Client',
                        ]
                    ]
                );
                
                //  check response status
                // ['balance' => $balance, 'currency' => 'credit', 'credits' => $balance]
                $responseStatus = $responseProfile->getStatusCode(false);                

                if ($responseStatus == Response::HTTP_OK && isset($responseProfile->toArray()['credits'])) {    
                    $CQ_AI_GatewayCredits = round($responseProfile->toArray()['credits']);

                    // save to settings
                    $this->settingsService->setSetting('cqaigateway.credits', $CQ_AI_GatewayCredits);

                    return $this->json([
                        'success' => true,
                        'creditBalance' => $CQ_AI_GatewayCredits,
                        'currency' => 'credit',
                        'credits' => $CQ_AI_GatewayCredits
                    ]);
                } else {
                    throw new \Exception('Failed to fetch credit balance');
                }
        } catch (\Exception $e) {
            return $this->json(['error' => $e->getMessage(), 'success' => false], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    #[Route('/{id}/tokens', name: 'api_spirit_conversation_tokens', methods: ['GET'])]
    public function getConversationTokens(string $id): JsonResponse
    {
        try {
            $conversationTokens = $this->conversationService->getConversationTokens($id);
            if (!$conversationTokens) {
                return $this->json(['error' => 'Conversation tokens not found'], 404);
            }
            return $this->json($conversationTokens);
        } catch (\Exception $e) {
            return $this->json(['error' => $e->getMessage(), 'success' => false], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
    
    #[Route('/{id}', name: 'api_spirit_conversation_get', methods: ['GET'])]
    public function getConversation(string $id, \App\Service\SpiritConversationMessageService $messageService): JsonResponse
    {
        try {
            // Get conversation
            $conversation = $this->conversationService->getConversation($id);
            if (!$conversation) {
                return $this->json(['error' => 'Conversation not found'], 404);
            }

            // Load messages from message table
            $messages = $messageService->getMessagesByConversation($id);
            
            // Convert conversation to array and add messages
            $conversationData = [
                'id' => $conversation->getId(),
                'spiritId' => $conversation->getSpiritId(),
                'title' => $conversation->getTitle(),
                'createdAt' => $conversation->getCreatedAt()->format('Y-m-d H:i:s'),
                'lastInteraction' => $conversation->getLastInteraction()->format('Y-m-d H:i:s'),
                'messages' => array_map(fn($msg) => $msg->jsonSerialize(), $messages)
            ];

            return $this->json($conversationData);
        } catch (\Exception $e) {
            return $this->json(['error' => $e->getMessage(), 'success' => false], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
    
    #[Route('/{id}/send', name: 'api_spirit_conversation_send', methods: ['POST'])]
    public function sendMessage(string $id, Request $request, TranslatorInterface $translator): JsonResponse
    {
        try {
            $data = json_decode($request->getContent(), true);
            if (!isset($data['message'])) {
                return $this->json(['error' => 'Missing message parameter'], 400);
            }
            
            // Extract max_output parameter with default value
            $maxOutput = isset($data['max_output']) ? (int)$data['max_output'] : 500;
            
            $locale = $request->getSession()->get('_locale');
            if (!$locale) {
                $locale = $request->getSession()->get('citadel_locale');
            }
            if (!$locale) {
                $locale = 'en';
            }
            // Send message with max_output parameter
            $messages = $this->conversationService->sendMessage(
                $id,
                $data['message'],
                $translator->trans('navigation.language.' . $locale) . ' (' . $locale . ')',
                $maxOutput
            );
            
            return $this->json(['messages' => $messages]);
        } catch (\Exception $e) {
            return $this->json(['error' => $e->getMessage()/*, 'line' => $e->getLine(), 'file' => $e->getFile()*/], 500);
        }
    }
    
    #[Route('/{id}', name: 'api_spirit_conversation_delete', methods: ['DELETE'])]
    public function deleteConversation(string $id): JsonResponse
    {
        try {
            // Check if conversation exists
            $conversation = $this->conversationService->getConversation($id);
            if (!$conversation) {
                return $this->json(['error' => 'Conversation not found'], 404);
            }
            
            // Delete conversation
            $this->conversationService->deleteConversation($id);
            
            return $this->json(['success' => true]);
        } catch (\Exception $e) {
            return $this->json(['error' => $e->getMessage()], 500);
        }
    }
    
    // ========================================================================
    // ASYNC SPIRIT CONVERSATION ENDPOINTS
    // ========================================================================
    
    /**
     * Send message asynchronously (returns immediately without executing tools)
     */
    #[Route('/{id}/send-async', name: 'api_spirit_conversation_send_async', methods: ['POST'])]
    public function sendMessageAsync(
        string $id, 
        Request $request, 
        TranslatorInterface $translator,
        \App\Service\SpiritConversationMessageService $messageService
    ): JsonResponse {
        try {
            $data = json_decode($request->getContent(), true);
            if (!isset($data['message'])) {
                return $this->json(['error' => 'Missing message parameter'], 400);
            }
            
            // Get conversation
            $conversation = $this->conversationService->getConversation($id);
            if (!$conversation) {
                return $this->json(['error' => 'Conversation not found'], 404);
            }
            
            // Create user message
            $messageContent = is_array($data['message']) ? $data['message'] : [['type' => 'text', 'text' => $data['message']]];
            $userMessage = $messageService->createMessage(
                $id,
                'user',
                'text',
                $messageContent
            );

            $userMessageArray = $userMessage->jsonSerialize();
            // save files from message
            $newFiles = $this->conversationService->saveFilesFromMessage($userMessageArray, 'general');
            $newFilesInfo = [];
            foreach ($newFiles as $file) {
                $newFilesInfo[] = [
                    'type' => 'text',
                    'text' => 'File: `' . $file->getFullPath() . '` (projectId: `' . $file->getProjectId() . '`)',
                ];
            }
            if (count($newFilesInfo) > 0) {
                $userMessageArray['content'] = array_merge($userMessageArray['content'], $newFilesInfo);
            }
            // save images from message
            $newImages = $this->aiServiceResponseService->saveImagesFromMessage(
                new AiServiceResponse('', $userMessageArray, []),
                'general',
                '/uploads/img'
            );
            $newImagesInfo = [];
            foreach ($newImages as $image) {
                $newImagesInfo[] = [
                    'type' => 'text',
                    'text' => '<div class="small float-end text-end">Image file: `' . $image['fullPath'] . '`<br>projectId: `' . $image['projectId'] . '`</div><div style="clear: both;"></div>',
                ];
            }
            if (count($newImagesInfo) > 0) {
                $userMessageArray['content'] = array_merge($userMessageArray['content'], $newImagesInfo);
            }
            $userMessage->setContent($userMessageArray['content']);
            $messageService->updateMessageContent($userMessage);
            
            // Get max output
            $maxOutput = isset($data['max_output']) ? (int)$data['max_output'] : 500;
            
            // Get locale for language
            $locale = $request->getSession()->get('_locale') ?? 
                      $request->getSession()->get('citadel_locale') ?? 'en';
            $lang = $translator->trans('navigation.language.' . $locale) . ' (' . $locale . ')';
            
            // Send to AI and get immediate response
            $aiResponse = $this->conversationService->sendMessageAsync(
                $id,
                $userMessage,
                $lang,
                $maxOutput,
                $data['temperature'] ?? 0.7
            );
            
            return $this->json($aiResponse);
            
        } catch (\Exception $e) {
            return $this->json(['error' => $e->getMessage()], 500);
        }
    }
    
    /**
     * Execute tools and get AI's next response
     */
    #[Route('/{id}/execute-tools', name: 'api_spirit_conversation_execute_tools', methods: ['POST'])]
    public function executeTools(
        string $id,
        Request $request,
        TranslatorInterface $translator
    ): JsonResponse {
        try {
            $data = json_decode($request->getContent(), true);
            if (!isset($data['toolCalls']) || !isset($data['assistantMessageId'])) {
                return $this->json(['error' => 'Missing required parameters'], 400);
            }
            
            // Get locale for language
            $locale = $request->getSession()->get('_locale') ?? 
                      $request->getSession()->get('citadel_locale') ?? 'en';
            $lang = $translator->trans('navigation.language.' . $locale) . ' (' . $locale . ')';
            
            // Execute tools and get AI's next response
            $aiResponse = $this->conversationService->executeToolsAsync(
                $id,
                $data['assistantMessageId'],
                $data['toolCalls'],
                $lang,
                $data['max_output'] ?? 500,
                $data['temperature'] ?? 0.7
            );
            
            return $this->json($aiResponse);
            
        } catch (\Exception $e) {
            return $this->json(['error' => $e->getMessage()], 500);
        }
    }
    
    /**
     * Stop tool execution chain
     */
    #[Route('/{id}/stop-execution', name: 'api_spirit_conversation_stop_execution', methods: ['POST'])]
    public function stopExecution(string $id): JsonResponse
    {
        try {
            // For now, just return success
            // In the future, this could set a flag in the database or session
            // to signal the frontend to stop the execution chain
            
            return $this->json(['success' => true]);
        } catch (\Exception $e) {
            return $this->json(['error' => $e->getMessage()], 500);
        }
    }
}
