<?php

namespace App\Controller;

use App\Service\ProjectFileService;
use App\Service\ProjectService;
use App\Service\SettingsService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Project (workspace) management page.
 *
 * See docs/features/CQ-PROJECT.md.
 */
#[IsGranted('ROLE_USER')]
class ProjectController extends AbstractController
{
    #[Route('/projects', name: 'app_projects')]
    public function index(
        ProjectService $projectService,
        ProjectFileService $projectFileService,
        SettingsService $settingsService,
        TranslatorInterface $translator
    ): Response {
        $projects = $projectService->findAll();

        $activeProjectId = $settingsService->getSettingValue('active_project_id', ProjectService::GENERAL_PROJECT_ID)
            ?: ProjectService::GENERAL_PROJECT_ID;

        $cards = [];
        foreach ($projects as $project) {
            $cards[] = [
                'project' => $project,
                'fileCount' => $projectFileService->getProjectFileCount($project->getId()),
                'spiritCount' => count($projectService->getProjectSpiritIds($project->getId())),
                'totalSize' => $projectFileService->formatBytes($projectFileService->getTotalProjectSize($project->getId())),
            ];
        }

        // Strings consumed by the projects JS manager
        $translations = [
            'created_success' => $translator->trans('project.created_success'),
            'updated_success' => $translator->trans('project.updated_success'),
            'deleted_success' => $translator->trans('project.deleted_success'),
            'error_generic' => $translator->trans('project.error_generic'),
            'delete_warning' => $translator->trans('project.delete_warning'),
            'delete_confirm_hint' => $translator->trans('project.delete_confirm_hint'),
            'switch_failed' => $translator->trans('project.switch_failed'),
            'title_required' => $translator->trans('project.field_title'),
            'spirits_empty' => $translator->trans('project.spirits_empty'),
            'spirits_saved' => $translator->trans('project.spirits_saved'),
        ];

        return $this->render('projects/index.html.twig', [
            'cards' => $cards,
            'activeProjectId' => $activeProjectId,
            'translations' => json_encode($translations),
        ]);
    }
}
