<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class ManifestController extends AbstractController
{
    #[Route('/site.webmanifest', name: 'app_manifest')]
    public function manifest(): Response
    {
        $response = $this->render('manifest.json.twig');
        $response->headers->set('Content-Type', 'application/manifest+json');
        return $response;
    }
}
