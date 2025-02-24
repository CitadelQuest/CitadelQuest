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

    #[Route('/', name: 'diary_index')]
    public function index(): Response
    {
        // just for dev purposes
        // $this->userDatabaseManager->updateDatabaseSchema($this->getUser());

        $entries = $this->diaryService->findLatestEntries($this->getUser());
        
        return $this->render('diary/index.html.twig', [
            'entries' => $entries
        ]);
    }

    #[Route('/new', name: 'diary_new')]
    public function new(): Response
    {
        return $this->render('diary/new.html.twig');
    }

    #[Route('/{id}', name: 'diary_show', methods: ['GET'])]
    public function show(string $id): Response
    {
        $entry = $this->diaryService->findById($this->getUser(), $id);
        
        if (!$entry) {
            throw $this->createNotFoundException('Diary entry not found');
        }
        
        return $this->render('diary/show.html.twig', [
            'entry' => $entry
        ]);
    }

    #[Route('/{id}/edit', name: 'diary_edit', methods: ['GET'])]
    public function edit(string $id): Response
    {
        $entry = $this->diaryService->findById($this->getUser(), $id);

        if (!$entry) {
            throw $this->createNotFoundException('Diary entry not found');
        }

        return $this->render('diary/edit.html.twig', [
            'entry' => $entry
        ]);
    }
}
