<?php

namespace App\Api\Controller;

use App\Service\SpiritConversationService;
use App\Service\SpiritService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/api/spirit-conversation')]
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
        $user = $this->getUser();
        if (!$user instanceof UserInterface) {
            return $this->json(['error' => 'User not authenticated'], 401);
        }
        
        // Get conversations
        $conversations = $this->conversationService->getConversationsBySpirit($spiritId);
        
        return $this->json($conversations);
    }
    
    #[Route('/create', name: 'api_spirit_conversation_create', methods: ['POST'])]
    public function createConversation(Request $request): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof UserInterface) {
            return $this->json(['error' => 'User not authenticated'], 401);
        }
        
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
    
    #[Route('/{id}', name: 'api_spirit_conversation_get', methods: ['GET'])]
    public function getConversation(string $id): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof UserInterface) {
            return $this->json(['error' => 'User not authenticated'], 401);
        }
        
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
        $user = $this->getUser();
        if (!$user instanceof UserInterface) {
            return $this->json(['error' => 'User not authenticated'], 401);
        }
        
        $data = json_decode($request->getContent(), true);
        if (!isset($data['message'])) {
            return $this->json(['error' => 'Missing message parameter'], 400);
        }
        
        try {
            $locale = $request->getSession()->get('_locale');
            // Send message
            $messages = $this->conversationService->sendMessage(
                $id,
                $data['message'],
                $translator->trans('navigation.language.' . $locale) . ' (' . $locale . ')'
            );
            
            return $this->json(['messages' => $messages]);
        } catch (\Exception $e) {
            return $this->json(['error' => $e->getMessage()], 500);
        }
    }
    
    #[Route('/{id}', name: 'api_spirit_conversation_delete', methods: ['DELETE'])]
    public function deleteConversation(string $id): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof UserInterface) {
            return $this->json(['error' => 'User not authenticated'], 401);
        }
        
        // Check if conversation exists
        $conversation = $this->conversationService->getConversation($id);
        if (!$conversation) {
            return $this->json(['error' => 'Conversation not found'], 404);
        }
        
        // Delete conversation
        $this->conversationService->deleteConversation($id);
        
        return $this->json(['success' => true]);
    }
}
