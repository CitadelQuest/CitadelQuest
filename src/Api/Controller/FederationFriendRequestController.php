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

class FederationFriendRequestController extends AbstractController
{
    public function __construct(
        private CqContactService $cqContactService,
        private EntityManagerInterface $entityManager,
        private NotificationService $notificationService,
    ) {}

    #[Route('/{username}/api/federation/friend-request', name: 'api_federation_friend_request', methods: ['POST'])]
    public function friendRequest(Request $request, string $username): Response
    {        
        // Auth Header
        $authHeader = $request->headers->get('Authorization');
        if (!$authHeader) {
            return $this->json([
                'success' => false,
                'message' => 'Authorization key required'
            ], Response::HTTP_UNAUTHORIZED);
        }
        // Get CQ-CONTACT API Key, from Authorization Bearer
        $cqContactApiKey = str_replace('Bearer ', '', $authHeader);
        
        // Get request JSON 
        $data = json_decode($request->getContent(), true);

        // Friend Request Status
        $friendRequestStatus = $data['friend_request_status'];
        
        if (!$friendRequestStatus) {
            return $this->json([
                'success' => false,
                'message' => 'Friend request status is required'
            ], Response::HTTP_BAD_REQUEST);
        }

        // Validate Friend Request Status
        if (!in_array($friendRequestStatus, ['SENT', 'RECEIVED', 'ACCEPTED', 'REJECTED'])) {
            return $this->json([
                'success' => false,
                'message' => 'Invalid friend request status'
            ], Response::HTTP_BAD_REQUEST);
        }

        // Validate request body
        $requiredFields = ['cq_contact_url', 'friend_request_status', 'cq_contact_domain', 'cq_contact_username', 'cq_contact_id'];
        foreach ($requiredFields as $field) {
            if (!$data[$field]) {
                return $this->json([
                    'success' => false,
                    'message' => 'Missing required field: ' . $field
                ], Response::HTTP_BAD_REQUEST);
            }
        }


        // Get system User by `username`
        $user = $this->entityManager->getRepository(User::class)->findOneBy(['username' => $username]);
        if (!$user) {
            return $this->json([
                'success' => false,
                'message' => 'User not found'
            ], Response::HTTP_NOT_FOUND);
        }
        $this->cqContactService->setUser($user);

        // Get CqContact
        $cqContact = $this->cqContactService->findByUrlAndApiKey($data['cq_contact_url'], $cqContactApiKey);
        
        // Process Friend Request Status
        switch ($friendRequestStatus) {
            // new friend request from another server
            case 'SENT':
                if (!$cqContact) {

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
                        // Send a `new friend request` notification
                        $this->notificationService->createNotification(
                            $user,
                            sprintf('New friend request from %s!', $cqContact->getCqContactUsername()),
                            'A new friend request from: ' . $cqContact->getCqContactDomain(),
                            'success'
                        );
                    }

                } elseif ($cqContact && $cqContact->getFriendRequestStatus() === 'REJECTED') {

                    return $this->json([
                        'success' => false,
                        'message' => 'Friend request already rejected'
                    ], Response::HTTP_BAD_REQUEST);

                } elseif ($cqContact && $cqContact->getFriendRequestStatus() === 'ACCEPTED') {

                    return $this->json([
                        'success' => false,
                        'message' => 'Friend request already accepted'
                    ], Response::HTTP_BAD_REQUEST);

                }
                break;
            // friend request accepted from another server
            case 'ACCEPTED':
                if ($cqContact && $cqContact->getFriendRequestStatus() === 'SENT') {

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

                } elseif ($cqContact && $cqContact->getFriendRequestStatus() === 'REJECTED') {

                    return $this->json([
                        'success' => false,
                        'message' => 'Friend request already rejected'
                    ], Response::HTTP_BAD_REQUEST);

                }
                break;
            // friend request rejected from another server
            case 'REJECTED':
                if ($cqContact) {

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

                }
                break;
        }

        if (!$cqContact) {
            return $this->json([
                'success' => false,
                'message' => 'CitadelQuest Contact not found'
            ], Response::HTTP_NOT_FOUND);
        }

        return $this->json([
            'success' => true,
            'message' => 'CitadelQuest Contact Friend request status updated successfully'
        ], Response::HTTP_OK);
    }
}