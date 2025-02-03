<?php

namespace App\Controller;

use App\Repository\UserRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

class KeyController extends AbstractController
{
    #[Route('/api/keys', name: 'api_keys', methods: ['POST'])]
    public function getKeys(Request $request, UserRepository $userRepository): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $username = $data['username'] ?? null;

        if (!$username) {
            return new JsonResponse(['error' => 'Username is required'], 400);
        }

        $user = $userRepository->findOneBy(['username' => $username]);
        if (!$user) {
            return new JsonResponse(['error' => 'User not found'], 404);
        }

        return new JsonResponse([
            'encryptedPrivateKey' => $user->getEncryptedPrivateKey(),
            'keySalt' => $user->getKeySalt(),
        ]);
    }
}
