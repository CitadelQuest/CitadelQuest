<?php

namespace App\Service;

use App\Entity\AiServiceRequest;
use App\Entity\AiServiceResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use SepaQr\SepaQrData;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;

/**
 * Service for handling AI tool calls across different AI providers
 */
class AIToolCallService
{
    public function __construct(
        private readonly SettingsService $settingsService,
        private readonly HttpClientInterface $httpClient,
        private readonly AiGatewayService $aiGatewayService,
        private readonly AiServiceRequestService $aiServiceRequestService,
        private readonly AiToolService $aiToolService,
        private readonly AIToolFileService $aiToolFileService,
        private readonly AIToolToolService $aiToolToolService,
        private readonly AIToolImageService $aiToolImageService,
        private readonly AIToolDiffusionService $aiToolDiffusionService,
        private readonly AIToolWebService $aiToolWebService,
        private readonly AIToolMemoryService $aiToolMemoryService
    ) {
    }
    
    /**
     * Execute a specific tool
     */
    public function executeTool(string $toolName, ?array $arguments, string $lang = 'English'): array
    {
        try {
            if ($arguments === null) {
                $arguments = [];
            }
            
            $arguments['lang'] = $lang;

            // Check if we have a specific method for this tool
            $methodName = lcfirst($toolName);
            if (method_exists($this, $methodName)) {
                return $this->{$methodName}($arguments);
            }
            
            // For file management tools, delegate to AIToolFileService
            if (in_array($toolName, ['listFiles', 'getFileContent', 'createDirectory', 'updateFileEfficient', 'manageFile', 'getFileVersions', 'getProjectTree'/* , 'findFileByPath', 'ensureProjectStructure' */])) {
                return $this->aiToolFileService->{$toolName}($arguments);
            }

            // For AI Tool management tools, delegate to AIToolToolService
            if (in_array($toolName, ['listAITools', 'setAIToolActive'])) {
                return $this->aiToolToolService->{$toolName}($arguments);
            }
            
            // For image tools, delegate to AIToolImageService
            if (in_array($toolName, ['imageEditorSpirit'])) {
                return $this->aiToolImageService->{$toolName}($arguments);
            }
            
            // For diffusion image tools, delegate to AIToolDiffusionService
            if (in_array($toolName, ['diffusionArtistSpirit'])) {
                return $this->aiToolDiffusionService->{$toolName}($arguments);
            }
            
            // For web tools, delegate to AIToolWebService
            if (in_array($toolName, ['fetchURL'])) {
                return $this->aiToolWebService->{$toolName}($arguments);
            }

            // For memory tools, delegate to AIToolMemoryService
            if (in_array($toolName, ['memoryStore', 'memoryRecall', 'memoryUpdate', 'memoryForget', 'memoryExtract', 'memoryAnalyzeRelationships'])) {
                return $this->aiToolMemoryService->{$toolName}($arguments);
            }

            // createSepaEuroPaymentQrCode
            if (in_array($toolName, ['createSepaEuroPaymentQrCode'])) {
                return $this->createSepaEuroPaymentQrCode($arguments);
            }
            
            // For tools without specific implementations, check if they exist in the database
            $tool = $this->aiToolService->findByName($toolName);
            if ($tool) {
                // Generic tool execution logic could be added here
                // For now, return an error that the tool isn't implemented
                return ['error' => 'Tool found but no implementation available: ' . $toolName];
            }
            
            return ['error' => 'Tool not found or not implemented: ' . $toolName];
        } catch (\Exception $e) {
            return ['error' => 'Error executing tool: ' . $e->getMessage()];
        }
    }
    
    /**
     * Get weather information (mock implementation)
     */
    private function getWeather(array $arguments): array
    {
        if (!isset($arguments['location'])) {
            return [
                'success' => false,
                'error' => 'Location is required'
            ];
        }
        
        // random mock weather
        $weather = [
            'success' => true,
            'temperature' => (10 + rand(-5, 5)) . '°' . (!isset($arguments['unit']) || $arguments['unit']=='celsius' ? 'C' : 'F'),
            'condition' => ['sunny', 'cloudy'][rand(0, 1)],
            'location' => $arguments['location'],
            'message' => 'This is just a mock weather response, it is not real weather data. It was first AI Tool implementation test :)',
        ];
        return $weather;
    }

    /**
     * Create SEPA payment QR code
     */
    private function createSepaEuroPaymentQrCode(array $arguments): array
    {
        if (!isset($arguments['name']) || !isset($arguments['iban']) || !isset($arguments['bic']) || !isset($arguments['amount'])) {
            return [
                'success' => false,
                'error' => 'Missing required arguments'
            ];
        }
        
        try {
            $paymentData = (new SepaQrData())
                ->setName($arguments['name'])
                ->setIban($arguments['iban'])
                ->setBic($arguments['bic'])
                ->setAmount($arguments['amount']); // The amount in Euro
            
            $contentFrontendData_remittanceText = '';
            if (isset($arguments['remittanceText'])) {
                $paymentData->setRemittanceText($arguments['remittanceText']);
                $contentFrontendData_remittanceText = '<span>Text: ' . $arguments['remittanceText'] . '</span><br>';
            }

            $qrOptions = new QROptions([
                'eccLevel' => QRCode::ECC_M // required by EPC standard
            ]);
            
            $qrCodeData = (new QRCode($qrOptions))->render($paymentData);

            $contentFrontendData = '<img src="' . $qrCodeData . '" alt="QR Code" class="rounded shadow w-100" style="max-width: 16rem !important; height: auto !important; max-height: 16rem !important;"><br>' .
                '<span><strong>' . $arguments['amount'] . '</strong> EUR</span> <i class="mdi mdi-arrow-right mx-2 text-cyber"></i> <span>' . $arguments['name'] . '</span><br>' .
                '<span><strong>IBAN:</strong> ' . $arguments['iban'] . '</span><br>' .
                '<span><strong>BIC:</strong> ' . $arguments['bic'] . '</span><br>' .
                $contentFrontendData_remittanceText;

            return [
                'success' => true,
                'message' => 'SEPA payment QR code created successfully and displayed in user interface',
                '_frontendData' => $contentFrontendData
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => 'Error creating SEPA payment QR code: ' . $e->getMessage()
            ];
        }
    }
}
