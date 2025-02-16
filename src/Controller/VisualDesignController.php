<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class VisualDesignController extends AbstractController
{
    #[Route('/visual-design', name: 'app_visual_design')]
    public function index(): Response
    {
        return $this->render('visual_design/index.html.twig');
    }

    #[Route('/visual-design/fonts', name: 'app_visual_design_fonts')]
    public function fonts(): Response
    {
        return $this->render('visual_design/fonts.html.twig');
    }

    #[Route('/visual-design/colors', name: 'app_visual_design_colors')]
    public function colors(): Response
    {
        return $this->render('visual_design/colors.html.twig');
    }

    #[Route('/visual-design/background', name: 'app_visual_design_background')]
    public function background(): Response
    {
        return $this->render('visual_design/background.html.twig');
    }

    #[Route('/visual-design/components', name: 'app_visual_design_components')]
    public function components(): Response
    {
        return $this->render('visual_design/components.html.twig');
    }
}
