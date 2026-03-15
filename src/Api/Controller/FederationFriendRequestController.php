<?php

namespace App\Api\Controller;

use App\Entity\CqContact;
use App\Entity\User;
use App\Service\CqContactService;
use App\Service\NotificationService;
use App\Service\SettingsService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

class FederationFriendRequestController extends AbstractController
{
    public function __construct(
        private CqContactService $cqContactService,
        private EntityManagerInterface $entityManager,
        private NotificationService $notificationService,
        private LoggerInterface $logger,
        private TranslatorInterface $translator,
        private SettingsService $settingsService,
    ) {}

    #[Route('/{username}/api/federation/friend-request', name: 'api_federation_friend_request', methods: ['POST'])]
    public function friendRequest(Request $request, string $username): Response
    {
        // Prioritize Cloudflare-Connecting-IP if present, otherwise fallback to standard client IP
        $ip = $request->headers->get('CF-Connecting-IP') ?? ($request->headers->get('X-Forwarded-For') ?? $request->getClientIp());
        
        try {
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
            $requestContent = $request->getContent();
            
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
            if (!in_array($friendRequestStatus, ['SENT', 'RECEIVED', 'ACCEPTED', 'REJECTED', 'REMOVED'])) {
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
            $this->cqContactService->setUser($user);

            // Set translator locale to user's preferred locale
            $this->settingsService->setUser($user);
            $userLocale = $this->settingsService->getSettingValue('_locale');
            if ($userLocale) {
                $this->translator->setLocale($userLocale);
            }

            // Fraud prevention: validate origin matches claimed domain
            $origin = $request->headers->get('Origin') ?? $request->headers->get('Referer') ?? '';
            $claimedDomain = $data['cq_contact_domain'];
            if ($origin && !str_contains($origin, $claimedDomain)) {
                $this->logger->warning('FederationFriendRequestController: Origin mismatch', [
                    'origin' => $origin,
                    'claimed_domain' => $claimedDomain
                ]);
                // Log but don't block — server-to-server requests may not send Origin
                // Send a `friend request warning` notification too
                $this->notificationService->createNotification(
                    $user,
                    $this->translator->trans('notifications.friend_request.origin_mismatch_title'),
                    $origin . ' vs. ' . $claimedDomain,
                    'warning',
                    '/cq-contacts' . (isset($data['cq_contact_url']) ? '?url='.urlencode($data['cq_contact_url']) : '')
                );
            }

            // Get CqContact
            $cqContact = $this->cqContactService->findByUrlAndApiKey($data['cq_contact_url'], $cqContactApiKey);
            
            // Process Friend Request Status
            switch ($friendRequestStatus) {
                // new friend request from another server
                case 'SENT':
                    if (!$cqContact) {
                        // No match by URL+apiKey — check if contact exists by URL alone
                        // (happens when contact was deleted on sender side and re-added with new API key)
                        $existingByUrl = $this->cqContactService->findByUrl($data['cq_contact_url']);

                        if ($existingByUrl) {
                            // Update existing contact with new API key and reset status
                            $existingByUrl->setCqContactApiKey($cqContactApiKey);
                            $existingByUrl->setFriendRequestStatus('RECEIVED');
                            $existingByUrl->setIsActive(false);
                            $existingByUrl->setCqContactUsername($data['cq_contact_username']);
                            $this->cqContactService->updateContact($existingByUrl);
                            $cqContact = $existingByUrl;

                            $this->logger->info('FederationFriendRequestController::friendRequest - Re-linked existing contact with new API key', [
                                'contact_id' => $cqContact->getId(),
                                'contact_url' => $data['cq_contact_url']
                            ]);
                        } else {
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
                        }

                        if ($cqContact) {
                            // Send a `new friend request` notification
                            $this->notificationService->createNotification(
                                $user,
                                $this->translator->trans('notifications.friend_request.new_title', ['%username%' => $cqContact->getCqContactUsername()]),
                                $this->translator->trans('notifications.friend_request.new_message', ['%domain%' => $cqContact->getCqContactDomain()]),
                                'success',
                                '/cq-contacts' . (isset($data['cq_contact_url']) ? '?url='.urlencode($data['cq_contact_url']) : '')
                            );
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
                    if ($cqContact && $cqContact->getFriendRequestStatus() === 'SENT') {
                        // Replace contact row: old local UUID -> new global Federation UUID
                        $oldId = $cqContact->getId();
                        $newId = $data['cq_contact_id'];
                        if ($oldId !== $newId) {
                            $this->cqContactService->deleteContact($oldId);
                            $cqContact = $this->cqContactService->createContact(
                                $cqContact->getCqContactUrl(),
                                $cqContact->getCqContactDomain(),
                                $cqContact->getCqContactUsername(),
                                $newId,
                                $cqContact->getCqContactApiKey(),
                                'ACCEPTED',
                                $cqContact->getDescription(),
                                $cqContact->getProfilePhotoProjectFileId(),
                                true
                            );
                        } else {
                            $cqContact->setFriendRequestStatus('ACCEPTED');
                            $cqContact->setIsActive(true);
                            $this->cqContactService->updateContact($cqContact);
                        }

                        // Send a `friend request accepted` notification
                        $this->notificationService->createNotification(
                            $user,
                            $this->translator->trans('notifications.friend_request.accepted_title', ['%username%' => $cqContact->getCqContactUsername()]),
                            $this->translator->trans('notifications.friend_request.accepted_message', ['%username%' => $cqContact->getCqContactUsername()]),
                            'success',
                            '/cq-contacts?url=' . urlencode($cqContact->getCqContactUrl())
                        );

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
                // contact removed from another server
                case 'REMOVED':
                    if ($cqContact) {
                        $cqContact->setFriendRequestStatus('REJECTED');
                        $cqContact->setIsActive(false);
                        $this->cqContactService->updateContact($cqContact);

                        $this->notificationService->createNotification(
                            $user,
                            $this->translator->trans('notifications.friend_request.removed_title', ['%username%' => $cqContact->getCqContactUsername()]),
                            $this->translator->trans('notifications.friend_request.removed_message', ['%username%' => $data['cq_contact_username'], '%domain%' => $data['cq_contact_domain']]),
                            'warning',
                            '/cq-contacts?url=' . urlencode($cqContact->getCqContactUrl())
                        );
                    }
                    break;

                // friend request rejected from another server
                case 'REJECTED':
                    if ($cqContact) {
                        // update CqContact
                        $cqContact->setFriendRequestStatus('REJECTED');
                        $cqContact->setIsActive(false);
                        $this->cqContactService->updateContact($cqContact);

                        // Send a `friend request rejected` notification
                        $this->notificationService->createNotification(
                            $user,
                            $this->translator->trans('notifications.friend_request.rejected_title', ['%username%' => $cqContact->getCqContactUsername()]),
                            $this->translator->trans('notifications.friend_request.rejected_message', ['%username%' => $cqContact->getCqContactUsername()]),
                            'error',
                            '/cq-contacts?url=' . urlencode($cqContact->getCqContactUrl())
                        );
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