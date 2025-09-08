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

#[Route('/api/cq-contact')]
#[IsGranted('ROLE_USER')]
class CqContactApiController extends AbstractController
{
    public function __construct(
        private readonly CqContactService $cqContactService,
        private readonly HttpClientInterface $httpClient
    ) {
    }

    #[Route('', name: 'app_api_cq_contact_list', methods: ['GET'])]
    public function list(): JsonResponse
    {
        try {
            $contacts = $this->cqContactService->findAll(false);
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
        try {
            $user = $this->getUser();

            $response = $this->httpClient->request(
                'POST',
                $contact->getCqContactUrl() . '/api/federation/friend-request',
                [
                    'headers' => [
                        'Authorization' => 'Bearer ' . $contact->getCqContactApiKey(),
                        'User-Agent' => 'CitadelQuest ' . CitadelVersion::VERSION . ' HTTP Client',
                        'Content-Type' => 'application/json',
                    ],
                    'json' => [
                        'cq_contact_url' => 'https://' . $currentDomain . '/' . $user->getUsername(),
                        'cq_contact_domain' => $currentDomain,
                        'cq_contact_username' => $user->getUsername(),
                        'cq_contact_id' => $user->getId(),
                        'friend_request_status' => $friendRequestStatus,
                    ]
                ]
            );
            
            if ($response->getStatusCode(false) !== Response::HTTP_OK) {
                $content = $response->getContent();
                $data = json_decode($content, true);
                return [
                    'success' => false,
                    'message' => 'Failed to send friend request. ' . $data['message']
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
            
            return [
                'success' => true,
                'message' => 'Friend request sent successfully'
            ];

        } catch (ClientExceptionInterface $e) {
            return [
                'success' => false,
                'message' => 'Failed to send friend request. ' . $e->getMessage()
            ];
        }
    }
}
