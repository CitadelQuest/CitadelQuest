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
use App\Service\AiServiceUseLogService;
use App\Service\SpiritChatTurnService;
use App\CitadelVersion;

#[Route('/api/spirit-conversation')]
#[IsGranted('ROLE_USER')]
class SpiritConversationApiController extends AbstractController
{   
    public function __construct(
        private readonly SpiritConversationService $conversationService, 
        private readonly SpiritService $spiritService, 
        private readonly SettingsService $settingsService,
        private readonly AiServiceResponseService $aiServiceResponseService,
        private readonly AiServiceUseLogService $aiServiceUseLogService)
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
            // get conversation tokens
            $conversationTokens = $this->conversationService->getConversationTokens($id);
            if (!$conversationTokens) {
                return $this->json(['error' => 'Conversation tokens not found'], 404);
            }

            // get conversation price
            $conversationPrice = $this->conversationService->getConversationPrice($id);
            
            return $this->json( array_merge($conversationTokens, $conversationPrice));
        } catch (\Exception $e) {
            return $this->json(['error' => $e->getMessage(), 'success' => false], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
    
    #[Route('/{id}', name: 'api_spirit_conversation_get', methods: ['GET'])]
    public function getConversation(
        string $id,
        Request $request,
        \App\Service\SpiritConversationMessageService $messageService,
        \App\Service\AIToolMemoryService $aiToolMemoryService,
        \App\Service\CQMemoryPackService $packService
    ): JsonResponse
    {
        try {
            // Clear stale recall session cache for this conversation
            // Prevents abandoned pre-send data from being used by a later send-async
            $session = $request->getSession();
            $session->remove("recall_{$id}_latest");
            $session->remove("recall_{$id}_nodes");
            $session->remove("recall_{$id}_user_message");
            $session->save();
            
            // Get conversation
            $conversation = $this->conversationService->getConversation($id);
            if (!$conversation) {
                return $this->json(['error' => 'Conversation not found'], 404);
            }

            // Pagination parameters
            $limit = (int) $request->query->get('limit', 5); // Default: last 5 messages
            $offset = (int) $request->query->get('offset', 0);
            
            // Get total message count
            $totalMessages = $messageService->countMessagesByConversation($id);
            
            // Load messages from message table with pagination
            $messages = $messageService->getMessagesByConversation($id, $limit, $offset);
            
            // Calculate if there are more older messages
            $loadedCount = $limit + $offset;
            $hasMore = $totalMessages > $loadedCount;
            
            // Convert conversation to array and add messages with usage data
            $messagesWithUsage = array_map(function($msg) {
                $msgData = $msg->jsonSerialize();
                
                // Add usage data if message has ai_service_request_id
                if ($msg->getAiServiceRequestId()) {
                    $usage = $this->aiServiceUseLogService->getUsageByRequestId($msg->getAiServiceRequestId());
                    if ($usage) {
                        $msgData['usage'] = $usage;
                    }
                }
                
                return $msgData;
            }, $messages);
            
            // Check if conversation has been memory-extracted (closed)
            $memoryExtracted = false;
            try {
                $targetPack = $aiToolMemoryService->getSpiritTargetPack($conversation->getSpiritId());
                $packService->open($targetPack['projectId'], $targetPack['path'], $targetPack['name']);
                $extracted = $packService->hasExtractedFromSource('spirit_conversation', $id);
                $memoryExtracted = $extracted !== null;
                $packService->close();
            } catch (\Exception $e) {
                // Non-fatal — if pack doesn't exist yet, conversation is not extracted
            }
            
            $conversationData = [
                'id' => $conversation->getId(),
                'spiritId' => $conversation->getSpiritId(),
                'title' => $conversation->getTitle(),
                'createdAt' => $conversation->getCreatedAt()->format('Y-m-d H:i:s'),
                'lastInteraction' => $conversation->getLastInteraction()->format('Y-m-d H:i:s'),
                'memoryExtracted' => $memoryExtracted,
                'messages' => $messagesWithUsage,
                'pagination' => [
                    'total' => $totalMessages,
                    'limit' => $limit,
                    'offset' => $offset,
                    'hasMore' => $hasMore
                ]
            ];

            return $this->json($conversationData);
        } catch (\Exception $e) {
            return $this->json(['error' => $e->getMessage(), 'success' => false], Response::HTTP_INTERNAL_SERVER_ERROR);
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
    
    /**
     * Pre-send: run Reflexes recall, cache system prompt, return recalled nodes
     * Phase 3.5: Separates recall from AI send for visual feedback + timeout prevention
     * Phase 4: Also returns shouldTriggerSubAgent flag for Subconsciousness agent
     */
    #[Route('/{id}/pre-send', name: 'api_spirit_conversation_pre_send', methods: ['POST'])]
    public function preSend(
        string $id,
        Request $request,
        TranslatorInterface $translator
    ): JsonResponse {
        try {
            $data = json_decode($request->getContent(), true);
            $messageText = trim($data['message'] ?? '');
            
            if (empty($messageText)) {
                return $this->json(['error' => 'Missing message parameter'], 400);
            }
            
            // Get locale for language
            $locale = $request->getSession()->get('_locale') ?? 
                      $request->getSession()->get('citadel_locale') ?? 'en';
            $lang = $translator->trans('navigation.language.' . $locale) . ' (' . $locale . ')';
            
            // Run pre-process recall
            $result = $this->conversationService->preProcessRecall($id, $messageText, $lang);
            
            // Cache system prompt + recalled nodes in session (one-shot, used by send-async)
            $session = $request->getSession();
            $session->set("recall_{$id}_latest", $result['systemPrompt']);
            $session->set("recall_{$id}_nodes", [
                'recalledNodes' => $result['recalledNodes'],
                'keywords' => $result['keywords'],
                'packInfo' => $result['packInfo'],
                'rootNodes' => $result['rootNodes'] ?? [],
                'packsToSearch' => $result['packsToSearch'] ?? [],
                'shouldTriggerSubAgent' => $result['shouldTriggerSubAgent'],
            ]);
            
            // Phase 4: Store user message for sub-agent context
            // Always store when trigger flag is true (sub-agent endpoint reads it from session)
            $session->set("recall_{$id}_user_message", $messageText);
            
            // Release session lock early — prevents blocking other requests from this user
            $session->save();
            
            return $this->json([
                'success' => true,
                'recalledNodes' => $result['recalledNodes'],
                'keywords' => $result['keywords'],
                'packInfo' => $result['packInfo'],
                'shouldTriggerSubAgent' => $result['shouldTriggerSubAgent'],
                'cached' => true,
            ]);
            
        } catch (\Exception $e) {
            return $this->json(['error' => $e->getMessage()], 500);
        }
    }
    
    /**
     * Phase 4: Run Subconsciousness sub-agent to enrich Reflexes recall.
     * Deprecated in the main flow: the sub-agent now runs inside the background worker
     * (see start-turn / SpiritChatTurnCommand). Kept for backward compatibility and
     * manual API/testing use.
     */
    #[Route('/{id}/sub-agent-recall', name: 'api_spirit_conversation_sub_agent_recall', methods: ['POST'])]
    public function subAgentRecall(
        string $id,
        Request $request
    ): JsonResponse {
        try {
            $session = $request->getSession();
            
            // Read cached data from pre-send
            $cachedSystemPrompt = $session->get("recall_{$id}_latest");
            $cachedNodes = $session->get("recall_{$id}_nodes");
            $userMessageText = $session->get("recall_{$id}_user_message");
            
            if (!$cachedSystemPrompt || !$cachedNodes || !$userMessageText) {
                return $this->json([
                    'success' => false,
                    'error' => 'No cached recall data found. Pre-send must run first.'
                ], 400);
            }
            
            $recalledNodes = $cachedNodes['recalledNodes'] ?? [];
            $keywords = $cachedNodes['keywords'] ?? [];
            $rootNodes = $cachedNodes['rootNodes'] ?? [];
            $packsSearched = $cachedNodes['packsToSearch'] ?? [];
            
            // Release session lock early — the AI call below can take 3-15s
            $session->save();
            
            // Run the Subconsciousness sub-agent
            $result = $this->conversationService->runSubconsciousnessAgent(
                $id,
                $userMessageText,
                $cachedSystemPrompt,
                $recalledNodes,
                $keywords,
                $rootNodes,
                $packsSearched
            );
            
            // Overwrite session cache with enriched data
            $session->set("recall_{$id}_latest", $result['systemPrompt']);
            $session->set("recall_{$id}_nodes", [
                'recalledNodes' => $result['recalledNodes'],
                'keywords' => $keywords,
                'packInfo' => $cachedNodes['packInfo'] ?? [],
                'rootNodes' => $cachedNodes['rootNodes'] ?? [],
                'packsToSearch' => $cachedNodes['packsToSearch'] ?? [],
                'synthesis' => $result['synthesis'],
                'confidence' => $result['confidence'],
                'subAgentUsage' => $result['usage'],
                // Enrichment has already happened here; the worker should not re-run it.
                'shouldTriggerSubAgent' => false,
            ]);
            // Clean up user message from session
            $session->remove("recall_{$id}_user_message");
            
            // Release session lock after writing enriched results
            $session->save();
            
            return $this->json([
                'success' => true,
                'recalledNodes' => $result['recalledNodes'],
                'synthesis' => $result['synthesis'],
                'confidence' => $result['confidence'],
                'usage' => $result['usage'],
            ]);
            
        } catch (\Exception $e) {
            return $this->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Send message (returns immediately without executing tools)
     */
    #[Route('/{id}/send-async', name: 'api_spirit_conversation_send_async', methods: ['POST'])]
    public function sendMessageAsync(
        string $id, 
        Request $request, 
        TranslatorInterface $translator,
        \App\Service\SpiritConversationMessageService $messageService,
        \App\Service\ProjectFileService $projectFileService
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
                    'text' => '<div class="small float-end text-end font-monospace opacity-50 text-cyber">Image file: `' . $image['fullPath'] . '`<br>projectId: `' . $image['projectId'] . '`</div><div style="clear: both;"></div>',
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
            
            // Check for cached system prompt from pre-send (Phase 3.5)
            $session = $request->getSession();
            $cachedSystemPrompt = $session->get("recall_{$id}_latest");
            $cachedRecallNodes = $session->get("recall_{$id}_nodes");
            if ($cachedSystemPrompt) {
                $session->remove("recall_{$id}_latest"); // one-shot: clear after use
            }
            if ($cachedRecallNodes) {
                $session->remove("recall_{$id}_nodes"); // one-shot: clear after use
            }
            
            // Release session lock early — the AI call below can take 5-60s+
            // All session data has been read and cleared; no further session access needed
            $session->save();
            
            // Save memory_recall message if pre-send returned recalled nodes (Phase 3.5)
            if ($cachedRecallNodes && !empty($cachedRecallNodes['recalledNodes'])) {
                $messageService->createMessage(
                    $id,
                    'assistant',
                    'memory_recall',
                    $cachedRecallNodes,
                    $userMessage->getId()
                );
            }
            
            // Send to AI and get immediate response
            $aiResponse = $this->conversationService->sendMessageAsync(
                $id,
                $userMessage,
                $lang,
                $maxOutput,
                $data['temperature'] ?? 0.7,
                $cachedSystemPrompt
            );
            
            // Enrich message with usage data
            if (isset($aiResponse['message']) && $aiResponse['message']->getAiServiceRequestId()) {
                $usage = $this->aiServiceUseLogService->getUsageByRequestId($aiResponse['message']->getAiServiceRequestId());
                if ($usage) {
                    // Convert message to array and add usage
                    $messageData = $aiResponse['message']->jsonSerialize();
                    $messageData['usage'] = $usage;
                    $aiResponse['message'] = $messageData;
                }
            }
            
            // Include saved files/images info for live UI update
            if (!empty($newFilesInfo) || !empty($newImagesInfo)) {
                $aiResponse['savedAttachments'] = array_merge($newFilesInfo, $newImagesInfo);
            }
            
            return $this->json($aiResponse);
            
        } catch (\Exception $e) {
            return $this->json(['error' => $e->getMessage()], 500);
        }
    }
    
    /**
     * DEPRECATED
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
            
            // Release session lock early — tool execution + AI call can take 5-30s+
            $request->getSession()->save();
            
            // Execute tools and get AI's next response
            $aiResponse = $this->conversationService->executeToolsAsync(
                $id,
                $data['assistantMessageId'],
                $data['toolCalls'],
                $lang,
                $data['max_output'] ?? 500,
                $data['temperature'] ?? 0.7
            );
            
            // Enrich message with usage data
            if (isset($aiResponse['message']) && $aiResponse['message']->getAiServiceRequestId()) {
                $usage = $this->aiServiceUseLogService->getUsageByRequestId($aiResponse['message']->getAiServiceRequestId());
                if ($usage) {
                    // Convert message to array and add usage
                    $messageData = $aiResponse['message']->jsonSerialize();
                    $messageData['usage'] = $usage;
                    $aiResponse['message'] = $messageData;
                }
            }
            
            return $this->json($aiResponse);
            
        } catch (\Exception $e) {
            return $this->json(['error' => $e->getMessage()], 500);
        }
    }
    
    /**
     * Start a background turn: persist the user message, then hand off the full
     * AI response + tool-execution loop to a detached CLI worker. Returns immediately
     * with a turn job id; the browser polls turn-status for results.
     *
     * This is the timeout-proof replacement for send-async + execute-tools: no single
     * HTTP request is held open for the long AI call, so Cloudflare's 100s limit (524)
     * is never hit, regardless of how long the AI turn takes.
     */
    #[Route('/{id}/start-turn', name: 'api_spirit_conversation_start_turn', methods: ['POST'])]
    public function startTurn(
        string $id,
        Request $request,
        TranslatorInterface $translator,
        \App\Service\SpiritConversationMessageService $messageService,
        SpiritChatTurnService $turnService
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
            $userMessage = $messageService->createMessage($id, 'user', 'text', $messageContent);

            $userMessageArray = $userMessage->jsonSerialize();
            // Save files from message
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

            // Save images from message
            $newImages = $this->aiServiceResponseService->saveImagesFromMessage(
                new AiServiceResponse('', $userMessageArray, []),
                'general',
                '/uploads/img'
            );
            $newImagesInfo = [];
            foreach ($newImages as $image) {
                $newImagesInfo[] = [
                    'type' => 'text',
                    'text' => '<div class="small float-end text-end font-monospace opacity-50 text-cyber">Image file: `' . $image['fullPath'] . '`<br>projectId: `' . $image['projectId'] . '`</div><div style="clear: both;"></div>',
                ];
            }
            if (count($newImagesInfo) > 0) {
                $userMessageArray['content'] = array_merge($userMessageArray['content'], $newImagesInfo);
            }
            $userMessage->setContent($userMessageArray['content']);
            $messageService->updateMessageContent($userMessage);

            // Get max output + temperature
            $maxOutput = isset($data['max_output']) ? (int) $data['max_output'] : 500;
            $temperature = isset($data['temperature']) ? (float) $data['temperature'] : 0.7;

            // Get locale for language
            $locale = $request->getSession()->get('_locale') ??
                      $request->getSession()->get('citadel_locale') ?? 'en';
            $lang = $translator->trans('navigation.language.' . $locale) . ' (' . $locale . ')';

            // Read cached system prompt + recalled nodes from pre-send (one-shot)
            $session = $request->getSession();
            $cachedSystemPrompt = $session->get("recall_{$id}_latest");
            $cachedRecallNodes = $session->get("recall_{$id}_nodes");
            if ($cachedSystemPrompt) {
                $session->remove("recall_{$id}_latest");
            }
            if ($cachedRecallNodes) {
                $session->remove("recall_{$id}_nodes");
            }
            // The sub-agent now runs in the worker; clear the session copy of the user message text.
            $session->remove("recall_{$id}_user_message");
            $session->save();

            // The memory_recall message is now created by the background worker after
            // sub-agent enrichment, so it always reflects the final (enriched) recall.
            // We do not create it here to avoid stale Reflexes-only badges in the UI.

            // Build a worker payload from the cached recall data. Only the metadata
            // the worker needs is stored in the turn payload to keep the JSON small.
            $workerPreSendData = $cachedRecallNodes ?: [];

            // Create the turn job and spawn the detached worker
            $turnJobId = $turnService->create($id, $userMessage->getId(), [
                'lang' => $lang,
                'maxOutput' => $maxOutput,
                'temperature' => $temperature,
                'toolTemperature' => 0.5,
                'cachedSystemPrompt' => $cachedSystemPrompt,
                'preSendData' => $workerPreSendData, // recall metadata for the worker
                'host' => $request->getHost(), // restored in the worker so system-info matches web
                'scheme' => $request->getScheme(), // restored for webhook URL generation in CLI
                'port' => $request->getPort(),
            ]);

            try {
                $this->spawnTurnWorker((string) $this->getUser()->getId(), $turnJobId);
            } catch (\Throwable $spawnError) {
                // Could not start the background worker — fail the turn now so the UI
                // shows a clear error instead of polling a job that will never run.
                $turnService->markFailed($turnJobId, 'Could not start background worker: ' . $spawnError->getMessage());
                return $this->json([
                    'error' => 'Could not start background processing. ' . $spawnError->getMessage(),
                ], 500);
            }

            // Return immediately — the browser will poll turn-status
            $result = [
                'success' => true,
                'jobId' => $turnJobId,
                'userMessage' => $userMessage->jsonSerialize(),
                'turnStartedAt' => $turnService->find($turnJobId)['created_at'] ?? null,
            ];
            if (!empty($newFilesInfo) || !empty($newImagesInfo)) {
                $result['savedAttachments'] = array_merge($newFilesInfo, $newImagesInfo);
            }

            return $this->json($result);

        } catch (\Exception $e) {
            return $this->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Poll the status of a background turn and fetch any new messages produced so far.
     * Lightweight + fast — always returns well under Cloudflare's 100s window.
     */
    #[Route('/{id}/turn-status/{jobId}', name: 'api_spirit_conversation_turn_status', methods: ['GET'])]
    public function turnStatus(
        string $id,
        string $jobId,
        \App\Service\SpiritConversationMessageService $messageService,
        SpiritChatTurnService $turnService
    ): JsonResponse {
        try {
            $turn = $turnService->find($jobId);
            if (!$turn || $turn['conversation_id'] !== $id) {
                return $this->json(['error' => 'Turn not found'], 404);
            }

            // New assistant/tool/memory_recall messages produced since the turn started.
            // memory_recall is now created by the worker after sub-agent enrichment, so
            // we include it here so the live UI can render the updated recall badge.
            $since = $turn['created_at'] ?? (new \DateTime())->format('Y-m-d H:i:s');
            $messages = $messageService->getMessagesByConversationSince($id, $since, ['assistant', 'tool']);

            $messagesWithUsage = array_map(function ($msg) {
                $msgData = $msg->jsonSerialize();
                if ($msg->getAiServiceRequestId()) {
                    $usage = $this->aiServiceUseLogService->getUsageByRequestId($msg->getAiServiceRequestId());
                    if ($usage) {
                        $msgData['usage'] = $usage;
                    }
                }
                return $msgData;
            }, $messages);
            $messagesWithUsage = array_values(array_filter($messagesWithUsage));

            $done = in_array($turn['status'], [
                SpiritChatTurnService::STATUS_COMPLETED,
                SpiritChatTurnService::STATUS_FAILED,
                SpiritChatTurnService::STATUS_STOPPED,
            ], true);

            return $this->json([
                'success' => true,
                'status' => $turn['status'],
                'done' => $done,
                'error' => $turn['error'] ?? null,
                'stopRequested' => (int) ($turn['stop_requested'] ?? 0) === 1,
                'messages' => $messagesWithUsage,
            ]);

        } catch (\Exception $e) {
            return $this->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Report whether a conversation has a still-running background turn (pending/processing).
     * Called when the Spirit Chat modal is (re)opened so the UI can resume the loading
     * indicator + Stop button and re-attach the poll loop for a worker that is still running
     * after the browser was closed.
     */
    #[Route('/{id}/active-turn', name: 'api_spirit_conversation_active_turn', methods: ['GET'])]
    public function activeTurn(string $id, SpiritChatTurnService $turnService): JsonResponse
    {
        try {
            $turn = $turnService->findActiveByConversation($id);
            if (!$turn) {
                return $this->json(['success' => true, 'active' => false]);
            }

            return $this->json([
                'success' => true,
                'active' => true,
                'jobId' => $turn['id'],
                'status' => $turn['status'],
                'turnStartedAt' => $turn['created_at'] ?? null,
            ]);
        } catch (\Exception $e) {
            return $this->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Stop tool execution chain — flags the background turn to stop after the
     * current AI call finishes.
     */
    #[Route('/{id}/stop-execution', name: 'api_spirit_conversation_stop_execution', methods: ['POST'])]
    public function stopExecution(string $id, Request $request, SpiritChatTurnService $turnService): JsonResponse
    {
        try {
            $data = json_decode($request->getContent(), true) ?: [];
            $jobId = $data['jobId'] ?? null;
            if ($jobId) {
                $turn = $turnService->find($jobId);
                if ($turn && $turn['conversation_id'] === $id) {
                    $turnService->requestStop($jobId);
                }
            }

            return $this->json(['success' => true]);
        } catch (\Exception $e) {
            return $this->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Spawn the detached background worker that processes a turn.
     * The process is fully detached (nohup + background) so it outlives this HTTP request.
     */
    private function spawnTurnWorker(string $userId, string $turnJobId): void
    {
        // Ensure exec() is available (some hardened PHP setups disable it)
        $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));
        if (!function_exists('exec') || in_array('exec', $disabled, true)) {
            throw new \RuntimeException('PHP exec() is disabled; cannot spawn background worker.');
        }

        $projectDir = $this->getParameter('kernel.project_dir');
        $env = $this->getParameter('kernel.environment');
        $logFile = $projectDir . '/var/log/spirit-turn.log';

        $php = $this->resolvePhpBinary();
        if ($php === null) {
            throw new \RuntimeException('Could not locate a PHP CLI binary to run the worker (php-fpm is not usable).');
        }

        $cmd = sprintf(
            'nohup %s %s/bin/console app:spirit-chat-turn %s %s --env=%s >> %s 2>&1 &',
            escapeshellarg($php),
            $projectDir,
            escapeshellarg($userId),
            escapeshellarg($turnJobId),
            escapeshellarg($env),
            escapeshellarg($logFile)
        );

        // Debug trace so we can see exactly what was launched (and from which SAPI)
        @file_put_contents(
            $logFile,
            sprintf(
                "[%s] spawn turn=%s user=%s sapi=%s php=%s\n  cmd: %s\n",
                date('c'),
                $turnJobId,
                $userId,
                PHP_SAPI,
                $php,
                $cmd
            ),
            FILE_APPEND
        );

        // exec returns immediately because the command is backgrounded with `&`
        @exec($cmd);
    }

    /**
     * Resolve the PHP **CLI** binary path.
     *
     * This is intentionally careful: under PHP-FPM (and mod_php) PHP_BINARY points to
     * php-fpm / apache, NOT the CLI — running `php-fpm bin/console` just prints FPM usage.
     * We therefore build a candidate list and validate each one by running `-v` and
     * checking for the "(cli)" marker, so we never launch the FPM/CGI binary by mistake.
     *
     * Returns null if no working CLI binary can be found.
     */
    private function resolvePhpBinary(): ?string
    {
        $candidates = [];

        // 1. Explicit override (set CQ_PHP_BINARY=/usr/bin/php to force it)
        $envBinary = getenv('CQ_PHP_BINARY');
        if ($envBinary) {
            $candidates[] = $envBinary;
        }

        // 2. PATH-resolved CLI — matches what works in the user's shell (`php bin/console ...`)
        if (function_exists('exec')) {
            $out = [];
            $code = null;
            @exec('command -v php 2>/dev/null', $out, $code);
            if ($code === 0 && !empty($out[0])) {
                $candidates[] = trim($out[0]);
            }
        }

        // 3. PHP_BINARY only if it is a real CLI binary (not php-fpm / php-cgi)
        if (defined('PHP_BINARY') && PHP_BINARY) {
            $base = basename(PHP_BINARY);
            if (str_contains($base, 'php') && !str_contains($base, 'fpm') && !str_contains($base, 'cgi')) {
                $candidates[] = PHP_BINARY;
            }
        }

        // 4. Version-suffixed + common install locations
        if (defined('PHP_MAJOR_VERSION') && defined('PHP_MINOR_VERSION')) {
            $ver = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
            $candidates[] = '/usr/local/bin/php' . $ver;
            $candidates[] = '/usr/bin/php' . $ver;
        }
        if (defined('PHP_BINDIR') && PHP_BINDIR) {
            $candidates[] = PHP_BINDIR . '/php';
        }
        $candidates[] = '/usr/local/bin/php';
        $candidates[] = '/usr/bin/php';

        foreach ($candidates as $candidate) {
            if ($this->isCliPhpBinary($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Verify a binary is a usable PHP CLI by running `<bin> -v` and looking for "(cli)".
     */
    private function isCliPhpBinary(string $binary): bool
    {
        if ($binary === '' || !function_exists('exec')) {
            return false;
        }
        // Absolute/relative path must be executable; bare "php" relies on PATH
        if (str_contains($binary, '/') && !is_executable($binary)) {
            return false;
        }

        $out = [];
        $code = null;
        @exec(escapeshellarg($binary) . ' -v 2>&1', $out, $code);

        return $code === 0 && str_contains(implode(' ', $out), '(cli)');
    }
}
