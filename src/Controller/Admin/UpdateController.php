<?php

namespace App\Controller\Admin;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\HttpFoundation\RequestStack;

class UpdateController extends AbstractController
{
    private $requestStack;

    public function __construct(RequestStack $requestStack)
    {
        $this->requestStack = $requestStack;
    }
    /* #[Route('/admin/update/check', name: 'admin_update_check')]
    public function checkForUpdates(): Response
    {
        // Only allow admin access
        $this->denyAccessUnlessGranted('ROLE_ADMIN');
        
        // Generate unique update script
        $uuid = Uuid::v4();
        $updateToken = bin2hex(random_bytes(32));
        $scriptName = sprintf('update-%s.php', $uuid->toRfc4122());
        
        // Get paths
        $projectDir = $this->getParameter('kernel.project_dir');
        $templatePath = $projectDir . '/public/.update';
        $scriptPath = $projectDir . '/public/' . $scriptName;
        
        // Read template and add security token
        $content = file_get_contents($templatePath);
        $content = "<?php\ndefine('CITADEL_UPDATE_TOKEN', '{$updateToken}');\n" .
                  "define('CITADEL_UPDATE_SCRIPT', __FILE__);\n" . $content;
        
        // Write the unique update script
        file_put_contents($scriptPath, $content);
        chmod($scriptPath, 0644);
        
        // Store token in session for verification
        $this->requestStack->getSession()->set('update_token', $updateToken);
        
        // Redirect to the unique update script
        return $this->redirect('/' . $scriptName);
    } */
}
