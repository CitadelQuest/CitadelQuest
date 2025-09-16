<?php

namespace App\Api\Controller;

use App\Entity\CqContact;
use App\Entity\User;
use App\Service\CqContactService;
use App\Service\NotificationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Psr\Log\LoggerInterface;

class FederationFriendRequestController extends AbstractController
{
    public function __construct(
        private CqContactService $cqContactService,
        private EntityManagerInterface $entityManager,
        private NotificationService $notificationService,
        private LoggerInterface $logger,
    ) {}

    #[Route('/{username}/api/federation/friend-request', name: 'api_federation_friend_request', methods: ['POST'])]
    public function friendRequest(Request $request, string $username): Response
    {
        $this->logger->info('FederationFriendRequestController::friendRequest - Starting friend request processing', [
            'username' => $username,
            'method' => $request->getMethod(),
            'request_uri' => $request->getRequestUri(),
            'client_ip' => $request->getClientIp(),
            'user_agent' => $request->headers->get('User-Agent'),
            'content_type' => $request->headers->get('Content-Type'),
            'content_length' => $request->headers->get('Content-Length')
        ]);
        
        try {
            // Auth Header
            $this->logger->info('FederationFriendRequestController::friendRequest - Checking authorization header');
            $authHeader = $request->headers->get('Authorization');
            if (!$authHeader) {
                $this->logger->warning('FederationFriendRequestController::friendRequest - Missing authorization header');
                return $this->json([
                    'success' => false,
                    'message' => 'Authorization key required'
                ], Response::HTTP_UNAUTHORIZED);
            }
            
            // Get CQ-CONTACT API Key, from Authorization Bearer
            $cqContactApiKey = str_replace('Bearer ', '', $authHeader);
            $this->logger->info('FederationFriendRequestController::friendRequest - Extracted API key', [
                'api_key_length' => strlen($cqContactApiKey)
            ]);
            
            // Get request JSON 
            $requestContent = $request->getContent();
            $this->logger->info('FederationFriendRequestController::friendRequest - Processing request content', [
                'content_length' => strlen($requestContent)
            ]);
            
            $data = json_decode($requestContent, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $this->logger->error('FederationFriendRequestController::friendRequest - JSON decode error', [
                    'json_error' => json_last_error_msg(),
                    'content_preview' => substr($requestContent, 0, 200)
                ]);
                return $this->json([
                    'success' => false,
                    'message' => 'Invalid JSON in request body'
                ], Response::HTTP_BAD_REQUEST);
            }
            
            $this->logger->info('FederationFriendRequestController::friendRequest - Parsed request data', [
                'data_keys' => array_keys($data ?? []),
                'friend_request_status' => $data['friend_request_status'] ?? 'not_set'
            ]);

            // Friend Request Status
            $friendRequestStatus = $data['friend_request_status'] ?? null;
            
            if (!$friendRequestStatus) {
                $this->logger->warning('FederationFriendRequestController::friendRequest - Missing friend request status');
                return $this->json([
                    'success' => false,
                    'message' => 'Friend request status is required'
                ], Response::HTTP_BAD_REQUEST);
            }

            // Validate Friend Request Status
            if (!in_array($friendRequestStatus, ['SENT', 'RECEIVED', 'ACCEPTED', 'REJECTED'])) {
                $this->logger->warning('FederationFriendRequestController::friendRequest - Invalid friend request status', [
                    'status' => $friendRequestStatus,
                    'valid_statuses' => ['SENT', 'RECEIVED', 'ACCEPTED', 'REJECTED']
                ]);
                return $this->json([
                    'success' => false,
                    'message' => 'Invalid friend request status'
                ], Response::HTTP_BAD_REQUEST);
            }

            // Validate request body
            $requiredFields = ['cq_contact_url', 'friend_request_status', 'cq_contact_domain', 'cq_contact_username', 'cq_contact_id'];
            $this->logger->info('FederationFriendRequestController::friendRequest - Validating required fields', [
                'required_fields' => $requiredFields
            ]);
            
            foreach ($requiredFields as $field) {
                if (!isset($data[$field]) || empty($data[$field])) {
                    $this->logger->warning('FederationFriendRequestController::friendRequest - Missing required field', [
                        'missing_field' => $field,
                        'available_fields' => array_keys($data ?? [])
                    ]);
                    return $this->json([
                        'success' => false,
                        'message' => 'Missing required field: ' . $field
                    ], Response::HTTP_BAD_REQUEST);
                }
            }


            // Get system User by `username`
            $this->logger->info('FederationFriendRequestController::friendRequest - Looking up user', [
                'username' => $username
            ]);
            
            $user = $this->entityManager->getRepository(User::class)->findOneBy(['username' => $username]);
            if (!$user) {
                $this->logger->warning('FederationFriendRequestController::friendRequest - User not found', [
                    'username' => $username
                ]);
                return $this->json([
                    'success' => false,
                    'message' => 'User not found'
                ], Response::HTTP_NOT_FOUND);
            }
            
            $this->logger->info('FederationFriendRequestController::friendRequest - Found user', [
                'user_id' => $user->getId(),
                'username' => $user->getUsername()
            ]);
            
            $this->cqContactService->setUser($user);

            // Get CqContact
            $this->logger->info('FederationFriendRequestController::friendRequest - Looking up CQ Contact', [
                'cq_contact_url' => $data['cq_contact_url'],
                'api_key_length' => strlen($cqContactApiKey)
            ]);
            
            $cqContact = $this->cqContactService->findByUrlAndApiKey($data['cq_contact_url'], $cqContactApiKey);
            $this->logger->info('FederationFriendRequestController::friendRequest - CQ Contact lookup result', [
                'contact_found' => $cqContact !== null,
                'contact_id' => $cqContact?->getId(),
                'contact_status' => $cqContact?->getFriendRequestStatus()
            ]);
            
            // Process Friend Request Status
            $this->logger->info('FederationFriendRequestController::friendRequest - Processing friend request status', [
                'status' => $friendRequestStatus
            ]);
            
            switch ($friendRequestStatus) {
                // new friend request from another server
                case 'SENT':
                    $this->logger->info('FederationFriendRequestController::friendRequest - Processing SENT status');
                    if (!$cqContact) {
                        $this->logger->info('FederationFriendRequestController::friendRequest - Creating new contact for SENT request');

                        // create CqContact
                        $cqContact = $this->cqContactService->createContact(
                            $data['cq_contact_url'],
                            $data['cq_contact_domain'],
                            $data['cq_contact_username'],
                            $data['cq_contact_id'],
                            $cqContactApiKey,
                            'RECEIVED',
                            $data['description'] ?? null,
                            null,
                            false
                        );

                        if ($cqContact) {
                            $this->logger->info('FederationFriendRequestController::friendRequest - Created new contact', [
                                'contact_id' => $cqContact->getId(),
                                'contact_username' => $cqContact->getCqContactUsername()
                            ]);
                            
                            // Send a `new friend request` notification
                            $this->notificationService->createNotification(
                                $user,
                                sprintf('New friend request from %s!', $cqContact->getCqContactUsername()),
                                'A new friend request from: ' . $cqContact->getCqContactDomain(),
                                'success'
                            );
                            $this->logger->info('FederationFriendRequestController::friendRequest - Created notification for new friend request');
                        } else {
                            $this->logger->error('FederationFriendRequestController::friendRequest - Failed to create contact');
                        }

                    } elseif ($cqContact && $cqContact->getFriendRequestStatus() === 'REJECTED') {
                        $this->logger->warning('FederationFriendRequestController::friendRequest - Friend request already rejected', [
                            'contact_id' => $cqContact->getId(),
                            'current_status' => $cqContact->getFriendRequestStatus()
                        ]);
                        return $this->json([
                            'success' => false,
                            'message' => 'Friend request already rejected'
                        ], Response::HTTP_BAD_REQUEST);

                    } elseif ($cqContact && $cqContact->getFriendRequestStatus() === 'ACCEPTED') {
                        $this->logger->warning('FederationFriendRequestController::friendRequest - Friend request already accepted', [
                            'contact_id' => $cqContact->getId(),
                            'current_status' => $cqContact->getFriendRequestStatus()
                        ]);
                        return $this->json([
                            'success' => false,
                            'message' => 'Friend request already accepted'
                        ], Response::HTTP_BAD_REQUEST);

                    }
                    break;
                // friend request accepted from another server
                case 'ACCEPTED':
                    $this->logger->info('FederationFriendRequestController::friendRequest - Processing ACCEPTED status');
                    if ($cqContact && $cqContact->getFriendRequestStatus() === 'SENT') {
                        $this->logger->info('FederationFriendRequestController::friendRequest - Updating contact to ACCEPTED', [
                            'contact_id' => $cqContact->getId(),
                            'previous_status' => $cqContact->getFriendRequestStatus()
                        ]);

                        // update CqContact
                        $cqContact->setFriendRequestStatus('ACCEPTED');
                        $cqContact->setCqContactId($data['cq_contact_id']);
                        $cqContact->setIsActive(true);
                        $this->cqContactService->updateContact($cqContact);

                        // Send a `friend request accepted` notification
                        $this->notificationService->createNotification(
                            $user,
                            sprintf('Friend request accepted from %s!', $cqContact->getCqContactUsername()),
                            'Your friend request to ' . $cqContact->getCqContactUsername() . ' has been accepted',
                            'success'
                        );
                        $this->logger->info('FederationFriendRequestController::friendRequest - Created notification for accepted friend request');

                    } elseif ($cqContact && $cqContact->getFriendRequestStatus() === 'REJECTED') {
                        $this->logger->warning('FederationFriendRequestController::friendRequest - Cannot accept already rejected request', [
                            'contact_id' => $cqContact->getId(),
                            'current_status' => $cqContact->getFriendRequestStatus()
                        ]);
                        return $this->json([
                            'success' => false,
                            'message' => 'Friend request already rejected'
                        ], Response::HTTP_BAD_REQUEST);

                    }
                    break;
                // friend request rejected from another server
                case 'REJECTED':
                    $this->logger->info('FederationFriendRequestController::friendRequest - Processing REJECTED status');
                    if ($cqContact) {
                        $this->logger->info('FederationFriendRequestController::friendRequest - Updating contact to REJECTED', [
                            'contact_id' => $cqContact->getId(),
                            'previous_status' => $cqContact->getFriendRequestStatus()
                        ]);

                        // update CqContact
                        $cqContact->setFriendRequestStatus('REJECTED');
                        $cqContact->setCqContactId($data['cq_contact_id']);
                        $cqContact->setIsActive(false);
                        $this->cqContactService->updateContact($cqContact);

                        // Send a `friend request rejected` notification
                        $this->notificationService->createNotification(
                            $user,
                            sprintf('Friend request rejected from %s!', $cqContact->getCqContactUsername()),
                            'Your friend request to ' . $cqContact->getCqContactUsername() . ' has been rejected',
                            'error'
                        );
                        $this->logger->info('FederationFriendRequestController::friendRequest - Created notification for rejected friend request');

                    }
                    break;
            }

            if (!$cqContact) {
                $this->logger->error('FederationFriendRequestController::friendRequest - No contact found after processing');
                return $this->json([
                    'success' => false,
                    'message' => 'CitadelQuest Contact not found'
                ], Response::HTTP_NOT_FOUND);
            }

            $this->logger->info('FederationFriendRequestController::friendRequest - Successfully processed friend request', [
                'contact_id' => $cqContact->getId(),
                'final_status' => $cqContact->getFriendRequestStatus()
            ]);

            return $this->json([
                'success' => true,
                'message' => 'CitadelQuest Contact Friend request status updated successfully'
            ], Response::HTTP_OK);
            
        } catch (\Exception $e) {
            $this->logger->error('FederationFriendRequestController::friendRequest - Exception occurred', [
                'exception_class' => get_class($e),
                'exception_message' => $e->getMessage(),
                'exception_file' => $e->getFile(),
                'exception_line' => $e->getLine(),
                'stack_trace' => $e->getTraceAsString()
            ]);
            
            return $this->json([
                'success' => false,
                'message' => 'Internal server error occurred while processing friend request'
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}