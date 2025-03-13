<?php

namespace App\Controller;

use App\Service\DiaryService;
use App\Service\UserDatabaseManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/diary')]
#[IsGranted('ROLE_USER')]
class DiaryController extends AbstractController
{
    public function __construct(
        private readonly DiaryService $diaryService,
        private readonly UserDatabaseManager $userDatabaseManager
    ) {
    }

    // "one route to rule them all"
    #[Route('', name: 'diary_index')]
    #[Route('/new', name: 'diary_new')]
    #[Route('/{id}', name: 'diary_show', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('diary/index.html.twig');
    }
}
