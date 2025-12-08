<?php

namespace App\Controller;

use App\Entity\User;
use App\Form\RegistrationType;
use App\Repository\UserRepository;
use App\Service\UserDatabaseManager;
use App\Service\UserKeyManager;
use App\Service\SettingsService;
use App\Service\AiModelsSyncService;
use App\Service\SystemSettingsService;
use App\Service\AiGatewayService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\Component\Security\Http\Authentication\UserAuthenticatorInterface;
use App\Security\LoginFormAuthenticator;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use App\CitadelVersion;
use Psr\Log\LoggerInterface;

class RegistrationController extends AbstractController
{
    public function __construct(
        private TranslatorInterface $translator,
        private LoggerInterface $logger,
        private AiModelsSyncService $aiModelsSyncService,
        private AiGatewayService $aiGatewayService
    ) {}
    #[Route('/register', name: 'app_register')]
    public function register(
        Request $request,
        UserPasswordHasherInterface $userPasswordHasher,
        EntityManagerInterface $entityManager,
        UserDatabaseManager $databaseManager,
        UserKeyManager $keyManager,
        UserAuthenticatorInterface $userAuthenticator,
        LoginFormAuthenticator $authenticator,
        HttpClientInterface $httpClient,
        SettingsService $settingsService,
        SessionInterface $session,
        UserRepository $userRepository,
        SystemSettingsService $systemSettingsService
    ): Response {
        // Check if registration is enabled
        if (!$systemSettingsService->getBooleanValue('cq_register', true)) {
            return $this->render('registration/disabled.html.twig');
        }
        $user = new User();
        $form = $this->createForm(RegistrationType::class, $user);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // Hash the password
            $user->setPassword(
                $userPasswordHasher->hashPassword(
                    $user,
                    $form->get('password')->getData()
                )
            );

            // Check if this is the first user
            $connection = $entityManager->getConnection();
            $userCount = $connection->executeQuery('SELECT COUNT(*) FROM user')->fetchOne();
            
            // If this is the first user, make them an admin
            if ($userCount === 0) {
                $user->setRoles(['ROLE_USER', 'ROLE_ADMIN']);
            } else {
                $user->setRoles(['ROLE_USER']);
            }

            // Create user's personal database first
            $databaseManager->createUserDatabase($user);

            // Store the keys in user's personal database
            $keyManager->storeKeys(
                $user,
                $form->get('publicKey')->getData(),
                $form->get('encryptedPrivateKey')->getData(),
                $form->get('keySalt')->getData()
            );

            try {
                // Now save the user with the database path
                $entityManager->persist($user);
                $entityManager->flush();
            } catch (\Exception $e) {
                // If saving fails, we should clean up the created database
                $databaseManager->deleteUserDatabase($user);
                throw $e;
            }

            $settingsService->setUser($user);

            // Save user's locale, from cookie
            $session->set('_locale', $request->cookies->get('citadel_locale', 'en'));
            $settingsService->setSetting('_locale', $session->get('_locale'));

            // Add flash message
            $this->addFlash('success', $this->translator->trans('auth.register.success'));

            // Login the user
            $userAuthenticator->authenticateUser($user, $authenticator, $request);

            // Create `CQ AI Gateway` user account(while we have the raw password) via API, to get the CQ AI API key
            if ($form->get('createAlsoCQAIGatewayAccount')->getData()) {
                $cqAiGatewayApiUrl = 'https://cqaigateway.com/api';
                $citadelQuestURL = $request->getScheme() . '://' . $request->getHost();
                
                try {
                    // Pre-check if email is available on CQ AI Gateway
                    $verifyResponse = $httpClient->request(
                        'POST',
                        $cqAiGatewayApiUrl . '/user/verify-email',
                        [
                            'headers' => [
                                'User-Agent' => 'CitadelQuest ' . CitadelVersion::VERSION . ' HTTP Client',
                                'Content-Type' => 'application/json',
                            ],
                            'json' => [
                                'email' => $user->getEmail()
                            ]
                        ]
                    );
                    
                    $verifyData = json_decode($verifyResponse->getContent(), true);
                    
                    // If email already exists on CQ AI Gateway, skip registration but continue with local flow
                    if (isset($verifyData['exists']) && $verifyData['exists'] === true) {
                        $this->logger->info('CQAIGateway: Email already registered, skipping auto-create for user ' . $user->getEmail());
                        $this->addFlash('warning', $this->translator->trans('auth.register.cqaigateway_email_exists'));
                        return $this->redirectToRoute('app_welcome_onboarding');
                    }

                    // Email is available, proceed with registration
                    $response = $httpClient->request(
                        'POST',
                        $cqAiGatewayApiUrl . '/user/register',
                        [
                            'headers' => [
                                'User-Agent' => 'CitadelQuest ' . CitadelVersion::VERSION . ' HTTP Client',
                                'Content-Type' => 'application/json',
                            ],
                            'json' => [
                                'username' => $userRepository->getCQAIGatewayUsername($user),
                                'email' => $user->getEmail(),
                                'password' => $form->get('password')->getData(),
                                'citadelquest_url' => $citadelQuestURL
                            ]
                        ]
                    );
                    
                    $statusCode = $response->getStatusCode(false);
                    $content = $response->getContent(false); // Don't throw on error status
                    
                    $data = json_decode($content, true);
                    
                    // Handle specific error codes gracefully
                    if ($statusCode === Response::HTTP_CONFLICT) {
                        $errorCode = $data['error_code'] ?? '';
                        if ($errorCode === 'EMAIL_EXISTS') {
                            $this->logger->info('CQAIGateway: Email already registered (race condition), skipping for user ' . $user->getEmail());
                            $this->addFlash('warning', $this->translator->trans('auth.register.cqaigateway_email_exists'));
                        } elseif ($errorCode === 'USERNAME_EXISTS') {
                            $this->logger->info('CQAIGateway: Username already registered, skipping for user ' . $user->getEmail());
                            $this->addFlash('warning', $this->translator->trans('auth.register.cqaigateway_username_exists'));
                        } else {
                            $this->logger->warning('CQAIGateway: Registration conflict for user ' . $user->getEmail() . ': ' . ($data['error'] ?? 'Unknown'));
                            $this->addFlash('warning', $this->translator->trans('auth.register.cqaigateway_account_exists'));
                        }
                        return $this->redirectToRoute('app_welcome_onboarding');
                    }
                    
                    if ($statusCode !== Response::HTTP_CREATED && $statusCode !== Response::HTTP_OK) {
                        $this->logger->error('CQAIGateway registration for user ' . $user->getEmail() . ' failed with status code ' . $statusCode . ': ' . $content);
                        return $this->redirectToRoute('app_welcome_onboarding');
                    }
                    
                    $apiKey = $data['user_api_key'];

                    // create 'CQ AI Gateway'
                    $gateway = $this->aiGatewayService->findByName('CQ AI Gateway');
                    if ($gateway) {
                        $this->aiGatewayService->updateGateway($gateway->getId(), [
                            'apiKey' => $apiKey,
                            'apiEndpointUrl' => $cqAiGatewayApiUrl,
                            'type' => 'cq_ai_gateway'
                        ]);
                    } else {
                        $gateway = $this->aiGatewayService->createGateway(
                            'CQ AI Gateway',
                            $apiKey,
                            $cqAiGatewayApiUrl,
                            'cq_ai_gateway'
                        );
                    }
                    // save email for CQ AI Gateway
                    $settingsService->setSetting('cqaigateway.email', $data['email']);

                    // update AI models list from CQ AI Gateway
                    try {
                        // Attempt to sync models in background
                        $syncModelsResult = $this->aiModelsSyncService->syncModels($user);
                        
                        if ($syncModelsResult['success']) {

                            $this->logger->info('RegistrationController: AI models updated successfully', [
                                'models_count' => count($syncModelsResult['models'] ?? [])
                            ]);
                        } else {
                            $this->logger->warning('RegistrationController: AI models sync failed', [
                                'error' => $syncModelsResult['message'],
                                'error_code' => $syncModelsResult['error_code'] ?? 'UNKNOWN'
                            ]);
                        }
                    } catch (\Exception $e) {
                        $this->logger->error('RegistrationController: Exception during AI models sync', [
                            'error' => $e->getMessage()
                        ]);
                    }

                    $this->logger->info('CQAIGateway registration successful for user ' . $user->getEmail());
                    
                } catch (\Exception $e) {
                    $this->logger->error('CQAIGateway registration failed for user ' . $user->getEmail() . ': ' . $e->getMessage());
                    // Don't show error to user, just continue with onboarding - they can link account later
                    $this->addFlash('warning', $this->translator->trans('auth.register.cqaigateway_connection_failed'));
                    return $this->redirectToRoute('app_welcome_onboarding');
                }
            }

            return $this->redirectToRoute('app_welcome_onboarding');
        }

        return $this->render('registration/register.html.twig', [
            'registrationForm' => $form->createView()
        ]);
    }
}
