<?php

namespace App\Api\Controller;

use App\Service\AIToolGitService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Psr\Log\LoggerInterface;

/**
 * User-facing git API for the File Browser git panel.
 * Thin layer over AIToolGitService (gitOperation / gitSetCredentials),
 * reusing the exact same functionality that AI Spirits use via AI Tools.
 */
#[Route('/api/git')]
#[IsGranted('ROLE_USER')]
class GitApiController extends AbstractController
{
    public function __construct(
        private readonly AIToolGitService $aiToolGitService,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Check if a directory is a git repository and get its status
     */
    #[Route('/status/{projectId}', name: 'app_api_git_status', methods: ['GET'])]
    public function status(string $projectId, Request $request): JsonResponse
    {
        $repoPath = $request->query->get('repoPath');

        try {
            $isRepo = $this->aiToolGitService->isGitRepository($projectId, $repoPath);

            if (!$isRepo) {
                return $this->json([
                    'success' => true,
                    'isRepo' => false
                ]);
            }

            $status = $this->aiToolGitService->gitOperation([
                'projectId' => $projectId,
                'operation' => 'status',
                'localRepoPath' => $repoPath
            ]);

            return $this->json(array_merge(['isRepo' => true], $status));
        } catch (\Exception $e) {
            $this->logger->error('Git status check failed', [
                'projectId' => $projectId,
                'repoPath' => $repoPath,
                'error' => $e->getMessage()
            ]);
            return $this->json([
                'success' => false,
                'error' => $e->getMessage()
            ], 400);
        }
    }

    /**
     * Execute a git operation (clone, pull, commitAndPush, status, diff, log, remoteAddOrigin)
     * Body parameters are forwarded 1:1 to AIToolGitService::gitOperation()
     */
    #[Route('/operation/{projectId}', name: 'app_api_git_operation', methods: ['POST'])]
    public function operation(string $projectId, Request $request): JsonResponse
    {
        try {
            $data = json_decode($request->getContent(), true) ?? [];
            $data['projectId'] = $projectId;

            $result = $this->aiToolGitService->gitOperation($data);

            return $this->json($result, ($result['success'] ?? false) ? 200 : 400);
        } catch (\Exception $e) {
            $this->logger->error('Git operation failed', [
                'projectId' => $projectId,
                'operation' => $data['operation'] ?? null,
                'error' => $e->getMessage()
            ]);
            return $this->json([
                'success' => false,
                'error' => $e->getMessage()
            ], 400);
        }
    }

    /**
     * Store git credentials (HTTPS token or SSH key)
     * Body parameters are forwarded 1:1 to AIToolGitService::gitSetCredentials()
     */
    #[Route('/credentials/{projectId}', name: 'app_api_git_credentials', methods: ['POST'])]
    public function setCredentials(string $projectId, Request $request): JsonResponse
    {
        try {
            $data = json_decode($request->getContent(), true) ?? [];
            $data['projectId'] = $projectId;

            $result = $this->aiToolGitService->gitSetCredentials($data);

            return $this->json($result, ($result['success'] ?? false) ? 200 : 400);
        } catch (\Exception $e) {
            $this->logger->error('Git credentials store failed', [
                'projectId' => $projectId,
                'error' => $e->getMessage()
            ]);
            return $this->json([
                'success' => false,
                'error' => $e->getMessage()
            ], 400);
        }
    }
}
