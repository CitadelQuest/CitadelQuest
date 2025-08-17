<?php

namespace App\Api\Controller;

use App\Service\SpiritConversationService;
use App\Service\SpiritService;
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
    private SpiritConversationService $conversationService;
    private SpiritService $spiritService;
    
    public function __construct(SpiritConversationService $conversationService, SpiritService $spiritService)
    {
        $this->conversationService = $conversationService;
        $this->spiritService = $spiritService;
    }
    
    #[Route('/list/{spiritId}', name: 'api_spirit_conversation_list', methods: ['GET'])]
    public function listConversations(string $spiritId, Request $request): JsonResponse
    {
        // Get conversations, without 'messages' field
        $conversations = $this->conversationService->getConversationsBySpirit($spiritId);
        $conversations = array_map(function($conversation) {
            $data = $conversation->jsonSerialize();
            unset($data['messages']);
            $data['messagesCount'] = count($conversation->getMessages());
            return $data;
        }, $conversations);
        
        return $this->json($conversations);
    }
    
    #[Route('/create', name: 'api_spirit_conversation_create', methods: ['POST'])]
    public function createConversation(Request $request): JsonResponse
    {
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
    }

    #[Route('/credit-balance', name: 'api_spirit_conversation_credit_balance', methods: ['GET'])]
    public function getCreditBalance(AiGatewayService $aiGatewayService, HttpClientInterface $httpClient): JsonResponse
    {
        try {
            $gateway = $aiGatewayService->findByName('CQ AI Gateway');
            if ($gateway) {
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

                    return $this->json([
                        'success' => true,
                        'creditBalance' => $CQ_AI_GatewayCredits,
                        'currency' => 'credit',
                        'credits' => $CQ_AI_GatewayCredits
                    ]);
                } else {
                    throw new \Exception('Failed to fetch credit balance');
                }
            } else {
                throw new \Exception('CQ AI Gateway not found');
            }
        } catch (\Exception $e) {
            return $this->json(['error' => $e->getMessage(), 'success' => false], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
    
    #[Route('/{id}', name: 'api_spirit_conversation_get', methods: ['GET'])]
    public function getConversation(string $id): JsonResponse
    {
        // Get conversation
        $conversation = $this->conversationService->getConversation($id);
        if (!$conversation) {
            return $this->json(['error' => 'Conversation not found'], 404);
        }
        
        return $this->json($conversation);
    }
    
    #[Route('/{id}/send', name: 'api_spirit_conversation_send', methods: ['POST'])]
    public function sendMessage(string $id, Request $request, TranslatorInterface $translator): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        if (!isset($data['message'])) {
            return $this->json(['error' => 'Missing message parameter'], 400);
        }
        
        // Extract max_output parameter with default value
        $maxOutput = isset($data['max_output']) ? (int)$data['max_output'] : 500;
        
        try {
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
            return $this->json(['error' => $e->getMessage()], 500);
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
}
