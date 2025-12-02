<?php

namespace App\Api\Controller;

use App\Entity\CqContact;
use App\Service\CqContactService;
use App\CitadelVersion;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Psr\Log\LoggerInterface;

#[Route('/api/cq-contact')]
#[IsGranted('ROLE_USER')]
class CqContactApiController extends AbstractController
{
    public function __construct(
        private readonly CqContactService $cqContactService,
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger
    ) {
    }
    
    /**
     * Get badge counts (pending friend requests, etc.)
     */
    #[Route('/badges', name: 'app_api_cq_contact_badges', methods: ['GET'])]
    public function getBadges(): JsonResponse
    {
        try {
            $pendingRequests = $this->cqContactService->countPendingFriendRequests();
            
            return $this->json([
                'pendingFriendRequests' => $pendingRequests
            ]);
        } catch (\Exception $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }
    }

    #[Route('', name: 'app_api_cq_contact_list', methods: ['GET'])]
    public function list(): JsonResponse
    {
        try {
            $contacts = $this->cqContactService->findAll(false);
            foreach ($contacts as &$contact) {
                $contact->setCqContactApiKey('***');
            }   
            return $this->json($contacts);
        } catch (\Exception $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }
    }

    #[Route('/{id}', name: 'app_api_cq_contact_get', methods: ['GET'])]
    public function get(string $id): JsonResponse
    {
        try {
            $contact = $this->cqContactService->findById($id);

            $contact->setCqContactApiKey('***');
            
            if (!$contact) {
                return $this->json(['error' => 'Contact not found'], Response::HTTP_NOT_FOUND);
            }
            
            return $this->json($contact);
        } catch (\Exception $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }
    }

    #[Route('', name: 'app_api_cq_contact_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        
        try {
            $contact = $this->cqContactService->createContact(
                $data['cqContactUrl'],
                $data['cqContactDomain'],
                $data['cqContactUsername'],
                $data['cqContactId'] ?? null,
                $data['cqContactApiKey'] ?? null,
                $data['friendRequestStatus'] ?? null,
                $data['description'] ?? null,
                $data['profilePhotoProjectFileId'] ?? null,
                $data['isActive'] ?? false
            );
            
            return $this->json($contact, Response::HTTP_CREATED);
        } catch (\Exception $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }
    }

    #[Route('/{id}', name: 'app_api_cq_contact_update', methods: ['PUT'])]
    public function update(string $id, Request $request): JsonResponse
    {
        try {
            $contact = $this->cqContactService->findById($id);
            
            if (!$contact) {
                return $this->json(['error' => 'Contact not found'], Response::HTTP_NOT_FOUND);
            }
            
            $data = json_decode($request->getContent(), true);
            
            if (isset($data['cqContactUrl'])) {
                $contact->setCqContactUrl($data['cqContactUrl']);
            }
            if (isset($data['cqContactDomain'])) {
                $contact->setCqContactDomain($data['cqContactDomain']);
            }
            if (isset($data['cqContactUsername'])) {
                $contact->setCqContactUsername($data['cqContactUsername']);
            }
            if (isset($data['cqContactId'])) {
                $contact->setCqContactId($data['cqContactId']);
            }
            if (isset($data['cqContactApiKey'])) {
                $contact->setCqContactApiKey($data['cqContactApiKey']);
            }
            if (isset($data['description'])) {
                $contact->setDescription($data['description']);
            }
            if (isset($data['profilePhotoProjectFileId'])) {
                $contact->setProfilePhotoProjectFileId($data['profilePhotoProjectFileId']);
            }
            if (isset($data['isActive'])) {
                $contact->setIsActive($data['isActive']);
            }
            
            $this->cqContactService->updateContact($contact);
            
            return $this->json($contact);
        } catch (\Exception $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }
    }

    #[Route('/{id}', name: 'app_api_cq_contact_delete', methods: ['DELETE'])]
    public function delete(string $id): JsonResponse
    {
        try {
            $success = $this->cqContactService->deleteContact($id);
            
            if (!$success) {
                return $this->json(['error' => 'Contact not found'], Response::HTTP_NOT_FOUND);
            }
            
            return $this->json(['message' => 'Contact deleted successfully']);
        } catch (\Exception $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }
    }

    #[Route('/{id}/activate', name: 'app_api_cq_contact_activate', methods: ['POST'])]
    public function activate(string $id): JsonResponse
    {
        try {
            $success = $this->cqContactService->activateContact($id);
            
            if (!$success) {
                return $this->json(['error' => 'Contact not found'], Response::HTTP_NOT_FOUND);
            }
            
            return $this->json(['message' => 'Contact activated successfully']);
        } catch (\Exception $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }
    }

    #[Route('/{id}/deactivate', name: 'app_api_cq_contact_deactivate', methods: ['POST'])]
    public function deactivate(string $id): JsonResponse
    {
        try {
            $success = $this->cqContactService->deactivateContact($id);
            
            if (!$success) {
                return $this->json(['error' => 'Contact not found'], Response::HTTP_NOT_FOUND);
            }
            
            return $this->json(['message' => 'Contact deactivated successfully']);
        } catch (\Exception $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }
    }

    #[Route('/{id}/friend-request', name: 'app_api_cq_contact_friend_request', methods: ['POST'])]
    public function sendFriendRequest(string $id, Request $request): JsonResponse
    {
        try {
            $contact = $this->cqContactService->findById($id);
            
            if (!$contact) {
                return $this->json(['error' => 'Contact not found'], Response::HTTP_NOT_FOUND);
            }
            
            $data = json_decode($request->getContent(), true);
            $friendRequestStatus = $data['friendRequestStatus'] ?? 'SENT';
            
            $result = $this->sendFriendRequestToContact($contact, $friendRequestStatus, $request->getHost());
            
            return $this->json($result);
        } catch (\Exception $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }
    }

    /**
     * Send friend request to another CitadelQuest instance
     * 
     * @param CqContact $contact
     * @param string $friendRequestStatus [SENT, RECEIVED, ACCEPTED, REJECTED]
     * @return array
     */
    private function sendFriendRequestToContact(CqContact $contact, string $friendRequestStatus, string $currentDomain): array
    {
        $user = $this->getUser();
        $targetUrl = $contact->getCqContactUrl() . '/api/federation/friend-request';
        
        $this->logger->info('CqContactApiController::sendFriendRequestToContact - Starting federation request', [
            'target_url' => $targetUrl,
            'friend_request_status' => $friendRequestStatus,
            'current_domain' => $currentDomain,
            'contact_username' => $contact->getCqContactUsername()
        ]);
        
        try {
            $requestData = [
                'cq_contact_url' => 'https://' . $currentDomain . '/' . $user->getUsername(),
                'cq_contact_domain' => $currentDomain,
                'cq_contact_username' => $user->getUsername(),
                'cq_contact_id' => $user->getId(),
                'friend_request_status' => $friendRequestStatus,
            ];
            
            $this->logger->info('CqContactApiController::sendFriendRequestToContact - Sending request', [
                'request_data' => $requestData
            ]);

            $response = $this->httpClient->request(
                'POST',
                $targetUrl,
                [
                    'headers' => [
                        'Authorization' => 'Bearer ' . $contact->getCqContactApiKey(),
                        'User-Agent' => 'CitadelQuest ' . CitadelVersion::VERSION . ' HTTP Client',
                        'Content-Type' => 'application/json',
                    ],
                    'json' => $requestData,
                    'timeout' => 30,
                ]
            );
            
            $statusCode = $response->getStatusCode(false);
            $this->logger->info('CqContactApiController::sendFriendRequestToContact - Response received', [
                'status_code' => $statusCode
            ]);
            
            if ($statusCode !== Response::HTTP_OK) {
                $content = $response->getContent(false);
                $data = json_decode($content, true);
                $this->logger->warning('CqContactApiController::sendFriendRequestToContact - Request failed', [
                    'status_code' => $statusCode,
                    'response' => $data
                ]);
                return [
                    'success' => false,
                    'message' => 'Failed to send friend request. ' . ($data['message'] ?? 'Unknown error')
                ];
            }
            
            if ($friendRequestStatus === 'ACCEPTED') {
                $contact->setIsActive(true);
            } elseif ($friendRequestStatus === 'REJECTED') {
                $contact->setIsActive(false);
            }
            
            // Update contact with new friend request status
            $contact->setFriendRequestStatus($friendRequestStatus);
            $this->cqContactService->updateContact($contact);
            
            $this->logger->info('CqContactApiController::sendFriendRequestToContact - Request successful', [
                'new_status' => $friendRequestStatus
            ]);
            
            return [
                'success' => true,
                'message' => 'Friend request sent successfully'
            ];

        } catch (TransportExceptionInterface $e) {
            $this->logger->error('CqContactApiController::sendFriendRequestToContact - Transport error (timeout/connection)', [
                'exception' => $e->getMessage(),
                'target_url' => $targetUrl
            ]);
            return [
                'success' => false,
                'message' => 'Connection failed: ' . $e->getMessage() . '. The target server may be unreachable or slow to respond.'
            ];
        } catch (ClientExceptionInterface $e) {
            $this->logger->error('CqContactApiController::sendFriendRequestToContact - Client error', [
                'exception' => $e->getMessage(),
                'target_url' => $targetUrl
            ]);
            return [
                'success' => false,
                'message' => 'Failed to send friend request. ' . $e->getMessage()
            ];
        } catch (\Exception $e) {
            $this->logger->error('CqContactApiController::sendFriendRequestToContact - Unexpected error', [
                'exception' => $e->getMessage(),
                'exception_class' => get_class($e),
                'target_url' => $targetUrl
            ]);
            return [
                'success' => false,
                'message' => 'Unexpected error: ' . $e->getMessage()
            ];
        }
    }
}
