<?php

namespace App\Controller;

use App\Service\SettingsService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use App\Service\SpiritConversationService;
use App\Service\SpiritService;
use App\Service\AiGatewayService;
use SepaQr\SepaQrData;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;

class HomeController extends AbstractController
{
    public function __construct(
        private readonly SettingsService $settingsService,
        private readonly SpiritConversationService $spiritConversationService,
        private readonly SpiritService $spiritService,
        private readonly AiGatewayService $aiGatewayService
    ) {}
    
    #[Route('/about', name: 'app_about')]
    public function about(): Response
    {
        return $this->render('home/index.html.twig', [
            'controller_name' => 'HomeController',
        ]);
    }
    /*
    #[Route('/sepa-qr-code', name: 'app_home_sepa_qr_code')]
    public function sepaQrCode(): Response
    {
        $paymentData = (new SepaQrData())
            ->setName('KOOPERATIVA poisťovňa, a.s.')
            ->setIban('SK25 0900 0000 0001 7512 6457')
            ->setBic('GIBASKBX')
            ->setAmount(108.02) // The amount in Euro
            //->setInformation('tst1')
            ->setRemittanceText('/VS/6607851896');

        $qrOptions = new QROptions([
            'eccLevel' => QRCode::ECC_M // required by EPC standard
        ]);
        
        $result = (new QRCode($qrOptions))->render($paymentData);

        $htmlImg = '<img src="' . ($result) . '" alt="QR Code" style="width: 200px; height: 200px;">';

        return new Response(
            $htmlImg,
            Response::HTTP_OK
        );
    }*/

}
