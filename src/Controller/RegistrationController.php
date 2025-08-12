<?php

namespace App\Controller;

use App\Entity\User;
use App\Form\RegistrationType;
use App\Repository\UserRepository;
use App\Service\UserDatabaseManager;
use App\Service\UserKeyManager;
use App\Service\SettingsService;
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
        private LoggerInterface $logger
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
        UserRepository $userRepository
    ): Response {
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

            // Save user's locale, from cookie
            $session->set('_locale', $request->cookies->get('citadel_locale', 'en'));
            $settingsService->setSetting('_locale', $session->get('_locale'));

            // Add flash message
            $this->addFlash('success', $this->translator->trans('auth.register.success'));

            // Login the user
            $userAuthenticator->authenticateUser($user, $authenticator, $request);

            // Create `CQ AI Gateway` user account(while we have the raw password) via API, to get the CQ AI API key
            if ($form->get('createAlsoCQAIGatewayAccount')->getData()) {
            
                $citadelQuestURL = $request->getScheme() . '://' . $request->getHost();
                
                try {
                    $response = $httpClient->request(
                        'POST',
                        'https://cqaigateway.com/api/user/register',
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
                    
                    $statusCode = $response->getStatusCode();
                    $content = $response->getContent();
                    
                    $data = json_decode($content, true);
                    
                    if ($statusCode !== Response::HTTP_CREATED && $statusCode !== Response::HTTP_OK) {
                        $this->logger->error('CQAIGateway registration for user ' . $user->getEmail() . ' failed with status code ' . $statusCode . ': ' . $content);
                        return $this->redirectToRoute('app_welcome_onboarding');
                    }
                    
                    $apiKey = $data['user_api_key'];

                    // save API key to user settings - it will be used in onboarding, pre-filled in the form
                    $settingsService->setSetting('cqaigateway.api_key', $apiKey);
                    // save username, email for CQ AI Gateway
                    $settingsService->setSetting('cqaigateway.username', $data['username']);
                    $settingsService->setSetting('cqaigateway.email', $data['email']);

                    $this->logger->info('CQAIGateway registration successful for user ' . $user->getEmail());
                    
                } catch (\Exception $e) {
                    $this->logger->error('CQAIGateway registration failed for user ' . $user->getEmail() . ': ' . $e->getMessage());
                    $this->addFlash('error', $this->translator->trans('auth.register.error') . ' (' . $e->getMessage() . ')');
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
