<?php

namespace App\Controller;

use App\Entity\User;
use App\Entity\AiGateway;
use App\Service\AiGatewayService;
use App\Service\AiServiceModelService;
use App\Service\NotificationService;
use App\Service\SettingsService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[Route('/settings')]
#[IsGranted('ROLE_USER')]
class UserSettingsController extends AbstractController
{
    public function __construct(
        private readonly AiGatewayService $aiGatewayService,
        private readonly AiServiceModelService $aiServiceModelService,
        private readonly SettingsService $settingsService
    ) {
    }

    #[Route('', name: 'app_user_settings')]
    public function index(): Response
    {
        // Get user's settings
        $settings = $this->settingsService->getAllSettings();
        
        return $this->render('user_settings/index.html.twig', [
            'settings' => $settings,
            'user' => $this->getUser(),
        ]);
    }

    #[Route('/email', name: 'app_user_settings_email', methods: ['POST'])]
    public function updateEmail(
        Request $request,
        EntityManagerInterface $entityManager,
        NotificationService $notificationService,
        TranslatorInterface $translator
    ): JsonResponse {
        if (!$request->isXmlHttpRequest()) {
            throw $this->createAccessDeniedException();
        }

        $user = $this->getUser();
        $email = $request->request->get('email');
        
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return new JsonResponse([
                'message' => $translator->trans('profile.email.invalid')
            ], Response::HTTP_BAD_REQUEST);
        }

        // Check if email is already used
        $existingUser = $entityManager->getRepository(User::class)->findOneBy(['email' => $email]);
        if ($existingUser && $existingUser !== $user) {
            return new JsonResponse([
                'message' => $translator->trans('auth.register.error.email_already_used')
            ], Response::HTTP_BAD_REQUEST);
        }

        $user->setEmail($email);
        $entityManager->flush();
        
        $notificationService->createNotification(
            $user,
            $translator->trans('profile.email.updated.title'),
            $translator->trans('profile.email.updated.message'),
            'success'
        );

        return new JsonResponse([
            'message' => $translator->trans('profile.email.updated.title')
        ]);
    }

    #[Route('/password', name: 'app_user_settings_password', methods: ['POST'])]
    public function updatePassword(
        Request $request,
        UserPasswordHasherInterface $passwordHasher,
        EntityManagerInterface $entityManager,
        NotificationService $notificationService,
        TranslatorInterface $translator
    ): JsonResponse {
        if (!$request->isXmlHttpRequest()) {
            throw $this->createAccessDeniedException();
        }

        $user = $this->getUser();
        $currentPassword = $request->request->get('current_password');
        $newPassword = $request->request->get('new_password');
        $confirmPassword = $request->request->get('confirm_password');

        if (!$passwordHasher->isPasswordValid($user, $currentPassword)) {
            return new JsonResponse([
                'message' => $translator->trans('profile.password.current_invalid')
            ], Response::HTTP_BAD_REQUEST);
        }

        if ($newPassword !== $confirmPassword) {
            return new JsonResponse([
                'message' => $translator->trans('profile.password.mismatch')
            ], Response::HTTP_BAD_REQUEST);
        }

        if (strlen($newPassword) < 8) {
            return new JsonResponse([
                'message' => $translator->trans('profile.password.too_short')
            ], Response::HTTP_BAD_REQUEST);
        }

        $user->setPassword($passwordHasher->hashPassword($user, $newPassword));
        $entityManager->flush();

        $notificationService->createNotification(
            $user,
            $translator->trans('profile.password.updated.title'),
            $translator->trans('profile.password.updated.message'),
            'success'
        );

        return new JsonResponse([
            'message' => $translator->trans('profile.password.updated.title')
        ]);
    }

    #[Route('/profile', name: 'app_user_settings_profile')]
    public function profile(SettingsService $settingsService): Response
    {
        // Get user description from settings or use default empty value
        $description = $settingsService->getSettingValue('profile.description', '');

        return $this->render('user_settings/profile.html.twig', [
            'user' => $this->getUser(),
            'profile_description' => $description
        ]);
    }

    #[Route('/ai', name: 'app_user_settings_ai')]
    public function aiSettings(Request $request): Response
    {
        // Get user's settings
        $settings = $this->settingsService->getAllSettings();
        
        // Get all available AI models
        $aiModels = $this->aiServiceModelService->findAll(true);
        
        // Handle form submission
        if ($request->isMethod('POST')) {
            // Update existing settings
            $this->settingsService->setSetting('ai.primary_ai_service_model_id', $request->request->get('primary_model', ''));
            $this->settingsService->setSetting('ai.secondary_ai_service_model_id', $request->request->get('secondary_model', ''));
            
            $this->addFlash('success', 'AI settings updated successfully.');
            
            return $this->redirectToRoute('app_user_settings_ai');
        }
        
        return $this->render('user_settings/ai.html.twig', [
            'settings' => $settings,
            'aiModels' => $aiModels,
        ]);
    }
    
    #[Route('/ai/gateways', name: 'app_user_settings_ai_gateways')]
    public function aiGateways(): Response
    {
        // Get all available AI gateways
        $aiGateways = $this->aiGatewayService->findAll();
        
        return $this->render('user_settings/ai_gateways.html.twig', [
            'aiGateways' => $aiGateways,
        ]);
    }
    
    #[Route('/ai/gateways/add', name: 'app_user_settings_ai_gateways_add', methods: ['POST'])]
    public function addAiGateway(Request $request): Response
    {
        $name = $request->request->get('name');
        $apiEndpointUrl = $request->request->get('apiEndpointUrl');
        $apiKey = $request->request->get('apiKey');
        
        if (!$name || !$apiEndpointUrl || !$apiKey) {
            $this->addFlash('danger', 'All fields are required.');
            return $this->redirectToRoute('app_user_settings_ai_gateways');
        }
        
        // Create new gateway using the service
        $this->aiGatewayService->createGateway(
            $name,
            $apiKey,
            $apiEndpointUrl
        );
        
        $this->addFlash('success', 'AI gateway added successfully.');
        return $this->redirectToRoute('app_user_settings_ai_gateways');
    }
    
    #[Route('/ai/gateways/edit', name: 'app_user_settings_ai_gateways_edit', methods: ['POST'])]
    public function editAiGateway(Request $request): Response
    {
        $id = $request->request->get('id');
        $name = $request->request->get('name');
        $apiEndpointUrl = $request->request->get('apiEndpointUrl');
        $apiKey = $request->request->get('apiKey');
        
        if (!$id || !$name || !$apiEndpointUrl) {
            $this->addFlash('danger', 'Required fields are missing.');
            return $this->redirectToRoute('app_user_settings_ai_gateways');
        }
        
        // Find the gateway
        $gateway = $this->aiGatewayService->findById($id);
        
        if (!$gateway) {
            $this->addFlash('danger', 'Gateway not found.');
            return $this->redirectToRoute('app_user_settings_ai_gateways');
        }
        
        // Update gateway
        $updateData = [
            'name' => $name,
            'apiEndpointUrl' => $apiEndpointUrl
        ];
        
        // Only update API key if provided
        if ($apiKey) {
            $updateData['apiKey'] = $apiKey;
        }
        
        $this->aiGatewayService->updateGateway($id, $updateData);
        
        $this->addFlash('success', 'AI gateway updated successfully.');
        return $this->redirectToRoute('app_user_settings_ai_gateways');
    }
    
    #[Route('/ai/gateways/delete', name: 'app_user_settings_ai_gateways_delete', methods: ['POST'])]
    public function deleteAiGateway(Request $request): Response
    {
        $id = $request->request->get('id');
        
        if (!$id) {
            $this->addFlash('danger', 'Gateway ID is required.');
            return $this->redirectToRoute('app_user_settings_ai_gateways');
        }
        
        // Find the gateway
        $gateway = $this->aiGatewayService->findById($id);
        
        if (!$gateway) {
            $this->addFlash('danger', 'Gateway not found.');
            return $this->redirectToRoute('app_user_settings_ai_gateways');
        }
        
        // Delete gateway [todo: and its models]
        $this->aiGatewayService->deleteGateway($id);
        
        $this->addFlash('success', 'AI gateway deleted successfully.');
        return $this->redirectToRoute('app_user_settings_ai_gateways');
    }
    
    #[Route('/ai/models', name: 'app_user_settings_ai_models')]
    public function aiModels(): Response
    {
        // Get all available AI models
        $aiModels = $this->aiServiceModelService->findAll();
        
        // Get all available AI gateways for the dropdown
        $aiGateways = $this->aiGatewayService->findAll();
        
        return $this->render('user_settings/ai_models.html.twig', [
            'aiModels' => $aiModels,
            'aiGateways' => $aiGateways,
        ]);
    }
    
    #[Route('/ai/models/add', name: 'app_user_settings_ai_models_add', methods: ['POST'])]
    public function addAiModel(Request $request): Response
    {
        $aiGatewayId = $request->request->get('aiGatewayId');
        $modelName = $request->request->get('modelName');
        $modelSlug = $request->request->get('modelSlug');
        $virtualKey = $request->request->get('virtualKey');
        $contextWindow = $request->request->get('contextWindow');
        $maxInput = $request->request->get('maxInput');
        $maxInputImageSize = $request->request->get('maxInputImageSize');
        $maxOutput = $request->request->get('maxOutput');
        $ppmInput = $request->request->get('ppmInput');
        $ppmOutput = $request->request->get('ppmOutput');
        $isActive = $request->request->getBoolean('isActive');
        
        if (!$aiGatewayId || !$modelName || !$modelSlug) {
            $this->addFlash('danger', 'Gateway, model name, and model slug are required.');
            return $this->redirectToRoute('app_user_settings_ai_models');
        }
        
        // Create new model using the service
        $this->aiServiceModelService->createModel(
            $aiGatewayId,
            $modelName,
            $modelSlug,
            $virtualKey,
            $contextWindow ? (int) $contextWindow : 64000,
            $maxInput,
            $maxInputImageSize,
            $maxOutput ? (int) $maxOutput : 8192,
            $ppmInput ? (float) $ppmInput : null,
            $ppmOutput ? (float) $ppmOutput : null,
            $isActive
        );
        
        $this->addFlash('success', 'AI model added successfully.');
        return $this->redirectToRoute('app_user_settings_ai_models');
    }
    
    #[Route('/ai/models/edit', name: 'app_user_settings_ai_models_edit', methods: ['POST'])]
    public function editAiModel(Request $request): Response
    {
        $id = $request->request->get('id');
        $aiGatewayId = $request->request->get('aiGatewayId');
        $modelName = $request->request->get('modelName');
        $modelSlug = $request->request->get('modelSlug');
        $virtualKey = $request->request->get('virtualKey');
        $contextWindow = $request->request->get('contextWindow');
        $maxInput = $request->request->get('maxInput');
        $maxInputImageSize = $request->request->get('maxInputImageSize');
        $maxOutput = $request->request->get('maxOutput');
        $ppmInput = $request->request->get('ppmInput');
        $ppmOutput = $request->request->get('ppmOutput');
        $isActive = $request->request->getBoolean('isActive');
        
        if (!$id || !$aiGatewayId || !$modelName || !$modelSlug) {
            $this->addFlash('danger', 'Required fields are missing.');
            return $this->redirectToRoute('app_user_settings_ai_models');
        }
        
        // Find the model
        $model = $this->aiServiceModelService->findById($id);
        
        if (!$model) {
            $this->addFlash('danger', 'Model not found.');
            return $this->redirectToRoute('app_user_settings_ai_models');
        }
        
        // Update model
        $updateData = [
            'aiGatewayId' => $aiGatewayId,
            'modelName' => $modelName,
            'modelSlug' => $modelSlug,
            'contextWindow' => $contextWindow ? (int) $contextWindow : 64000,
            'maxInput' => $maxInput,
            'maxInputImageSize' => $maxInputImageSize,
            'maxOutput' => $maxOutput ? (int) $maxOutput : 8192,
            'ppmInput' => $ppmInput ? (float) $ppmInput : null,
            'ppmOutput' => $ppmOutput ? (float) $ppmOutput : null,
            'isActive' => $isActive
        ];
        
        // Only update virtual key if provided
        if ($virtualKey) {
            $updateData['virtualKey'] = $virtualKey;
        }
        
        $this->aiServiceModelService->updateModel($id, $updateData);
        
        $this->addFlash('success', 'AI model updated successfully.');
        return $this->redirectToRoute('app_user_settings_ai_models');
    }
    
    #[Route('/ai/models/delete', name: 'app_user_settings_ai_models_delete', methods: ['POST'])]
    public function deleteAiModel(Request $request): Response
    {
        $id = $request->request->get('id');
        
        if (!$id) {
            $this->addFlash('danger', 'Model ID is required.');
            return $this->redirectToRoute('app_user_settings_ai_models');
        }
        
        // Find the model
        $model = $this->aiServiceModelService->findById($id);
        
        if (!$model) {
            $this->addFlash('danger', 'Model not found.');
            return $this->redirectToRoute('app_user_settings_ai_models');
        }
        
        // Check if this model is in use by the user's settings
        $settings = $this->settingsService->getAllSettings();
        if ($settings && 
            ($settings['ai.primary_ai_service_model_id'] === $id || $settings['ai.secondary_ai_service_model_id'] === $id)) {
            $this->addFlash('danger', 'This model is currently in use in your AI settings. Please select a different model in AI Services settings first.');
            return $this->redirectToRoute('app_user_settings_ai_models');
        }
        
        // Delete model
        $this->aiServiceModelService->deleteModel($id);
        
        $this->addFlash('success', 'AI model deleted successfully.');
        return $this->redirectToRoute('app_user_settings_ai_models');
    }
}
