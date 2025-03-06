<?php

namespace App\Controller;

use Doctrine\Persistence\ManagerRegistry;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Psr\Log\LoggerInterface;

class UpdateFromGitController extends AbstractController
{
    #[Route(path: '/update-fg', name: 'update_from_git')]
    public function update(ManagerRegistry $doctrine, Request $request, LoggerInterface $logger): Response
    {
        $logger->info('GitHub Push Update');
        
        $payload = $request->getContent();
        $signature = $request->headers->get('X-Hub-Signature');

        if (!$this->isGitHubSignatureValid($payload, $signature)) {
            $logger->error('Invalid GitHub signature');
            throw new AccessDeniedHttpException('Invalid signature');
        }

        // call 'git pull' command
        $r = shell_exec('cd ~/citadelquest.com/dev/ && git pull 2>&1');

        $logger->info('Git pull command executed. Output: ' . substr($r, 0, 200) . '...');

        return $this->json(['echo' => 'OK'], 200);
    }

    private function isGitHubSignatureValid(string $payload, ?string $signature): bool
    {
        if (null === $signature) {
            return false;
        }

        $secret = $_ENV['GITHUB_WEBHOOK_SECRET'];
        $computedSignature = 'sha1=' . hash_hmac('sha1', $payload, $secret);

        return hash_equals($computedSignature, $signature);
    }
}