<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * CQ Imager — human-facing image generation & editing page.
 *
 * Renders the 3-column GUI at `/imager`. All actual data flows through
 * {@see \App\Api\Controller\CQImagerApiController}.
 *
 * Query params:
 *   ?model={airId}   — preselect a model (e.g. "google:4@3")
 *   ?dir={path}      — preselect an output directory (e.g. "/uploads/imager")
 *   ?project={id}    — preselect a CQ project id (default "general")
 *
 * @see /docs/features/CQ-IMAGER.md
 */
#[Route('/imager')]
#[IsGranted('ROLE_USER')]
class CQImagerController extends AbstractController
{
    #[Route('', name: 'app_cq_imager', methods: ['GET'])]
    public function index(Request $request): Response
    {
        return $this->render('cq-imager/imager.html.twig', [
            'preselectedModel'     => $request->query->get('model'),
            'preselectedDir'       => $request->query->get('dir'),
            'preselectedProjectId' => $request->query->get('project', 'general'),
        ]);
    }
}
