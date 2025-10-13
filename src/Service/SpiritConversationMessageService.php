<?php

namespace App\Service;

use App\Entity\SpiritConversationMessage;
use App\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;
use Psr\Log\LoggerInterface;

/**
 * Spirit Conversation Message Service
 * 
 * Manages individual messages in Spirit conversations.
 * Each message is a separate database record linked via parent_message_id.
 */
class SpiritConversationMessageService
{
    private ?User $user;
    
    public function __construct(
        private readonly UserDatabaseManager $userDatabaseManager,
        private readonly Security $security,
        private readonly LoggerInterface $logger
    ) {
        $this->user = $security->getUser();
    }
    
    /**
     * Get user database connection
     */
    private function getUserDb()
    {
        if (!$this->user) {
            throw new \Exception('User not authenticated');
        }
        
        return $this->userDatabaseManager->getDatabaseConnection($this->user);
    }
    
    /**
     * Create a new message
     */
    public function createMessage(
        string $conversationId,
        string $role,
        string $type,
        array $content,
        ?string $parentMessageId = null
    ): SpiritConversationMessage {
        $db = $this->getUserDb();
        
        $message = new SpiritConversationMessage(
            $conversationId,
            $role,
            $type,
            $content,
            $parentMessageId
        );
        
        $this->logger->info('Creating spirit conversation message', [
            'message_id' => $message->getId(),
            'conversation_id' => $conversationId,
            'role' => $role,
            'type' => $type,
            'parent_message_id' => $parentMessageId
        ]);
        
        $db->executeStatement(
            'INSERT INTO spirit_conversation_message 
            (id, conversation_id, role, type, content, parent_message_id, created_at) 
            VALUES (?, ?, ?, ?, ?, ?, ?)',
            [
                $message->getId(),
                $message->getConversationId(),
                $message->getRole(),
                $message->getType(),
                json_encode($message->getContent()),
                $message->getParentMessageId(),
                $message->getCreatedAt()->format('Y-m-d H:i:s')
            ]
        );
        
        return $message;
    }
    
    /**
     * Update message with AI service request/response IDs
     */
    public function updateMessage(SpiritConversationMessage $message): void
    {
        $db = $this->getUserDb();
        
        $this->logger->debug('Updating spirit conversation message', [
            'message_id' => $message->getId(),
            'ai_service_request_id' => $message->getAiServiceRequestId(),
            'ai_service_response_id' => $message->getAiServiceResponseId()
        ]);
        
        $db->executeStatement(
            'UPDATE spirit_conversation_message 
            SET ai_service_request_id = ?, ai_service_response_id = ? 
            WHERE id = ?',
            [
                $message->getAiServiceRequestId(),
                $message->getAiServiceResponseId(),
                $message->getId()
            ]
        );
    }
    
    /**
     * Get all messages for a conversation
     * 
     * @return SpiritConversationMessage[]
     */
    public function getMessagesByConversation(string $conversationId): array
    {
        $db = $this->getUserDb();
        
        $result = $db->executeQuery(
            'SELECT * FROM spirit_conversation_message 
            WHERE conversation_id = ? 
            ORDER BY created_at ASC',
            [$conversationId]
        );
        
        $messages = [];
        foreach ($result->fetchAllAssociative() as $data) {
            $messages[] = SpiritConversationMessage::fromArray($data);
        }
        
        $this->logger->debug('Loaded messages for conversation', [
            'conversation_id' => $conversationId,
            'message_count' => count($messages)
        ]);
        
        return $messages;
    }
    
    /**
     * Get a single message by ID
     */
    public function getMessageById(string $messageId): ?SpiritConversationMessage
    {
        $db = $this->getUserDb();
        
        $result = $db->executeQuery(
            'SELECT * FROM spirit_conversation_message WHERE id = ?',
            [$messageId]
        );
        
        $data = $result->fetchAssociative();
        if (!$data) {
            return null;
        }
        
        return SpiritConversationMessage::fromArray($data);
    }
    
    /**
     * Get message chain (follow parent_message_id links)
     * Returns messages from root to specified message
     * 
     * @return SpiritConversationMessage[]
     */
    public function getMessageChain(string $messageId): array
    {
        $db = $this->getUserDb();
        $chain = [];
        $currentId = $messageId;
        $maxDepth = 100;  // Prevent infinite loops
        $depth = 0;
        
        while ($currentId !== null && $depth < $maxDepth) {
            $result = $db->executeQuery(
                'SELECT * FROM spirit_conversation_message WHERE id = ?',
                [$currentId]
            );
            
            $data = $result->fetchAssociative();
            if (!$data) {
                break;
            }
            
            $message = SpiritConversationMessage::fromArray($data);
            array_unshift($chain, $message);  // Add to beginning
            $currentId = $message->getParentMessageId();
            $depth++;
        }
        
        $this->logger->debug('Built message chain', [
            'message_id' => $messageId,
            'chain_length' => count($chain)
        ]);
        
        return $chain;
    }
    
    /**
     * Delete all messages for a conversation
     */
    public function deleteMessagesByConversation(string $conversationId): void
    {
        $db = $this->getUserDb();
        
        $this->logger->info('Deleting messages for conversation', [
            'conversation_id' => $conversationId
        ]);
        
        $db->executeStatement(
            'DELETE FROM spirit_conversation_message WHERE conversation_id = ?',
            [$conversationId]
        );
    }
    
    /**
     * Count messages in a conversation
     */
    public function countMessagesByConversation(string $conversationId): int
    {
        $db = $this->getUserDb();
        
        $result = $db->executeQuery(
            'SELECT COUNT(*) as count FROM spirit_conversation_message WHERE conversation_id = ?',
            [$conversationId]
        );
        
        return (int) $result->fetchOne();
    }
}
