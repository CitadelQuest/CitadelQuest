<?php

namespace App\Service;

use App\Entity\AiGateway;
use App\Entity\AiServiceModel;
use App\Entity\AiServiceRequest;
use App\Entity\AiServiceResponse;
use App\Service\AiToolService;
use App\Service\AIToolCallService;
use App\Service\AiServiceRequestService;
use App\Service\AiServiceResponseService;
use App\Service\AiGatewayService;
use App\Service\SettingsService;
use App\Service\AiServiceModelService;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use App\CitadelVersion;

class CQAIGateway implements AiGatewayInterface
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly SettingsService $settingsService,
        private readonly ServiceLocator $serviceLocator
    ) {
    }
    
    /**
     * Send a request to the AI service
     */
    public function sendRequest(AiServiceRequest $request): AiServiceResponse
    {
        // Get services
        $aiServiceModelService = $this->serviceLocator->get(AiServiceModelService::class);
        $aiGatewayService = $this->serviceLocator->get(AiGatewayService::class);
        $aiServiceRequestService = $this->serviceLocator->get(AiServiceRequestService::class);
        $aiServiceResponseService = $this->serviceLocator->get(AiServiceResponseService::class);

        // Get the model
        $aiServiceModel = $aiServiceModelService->findById($request->getAiServiceModelId());
        if (!$aiServiceModel) {
            throw new \Exception('AI Service Model not found');
        }
        // Get the gateway
        $aiGateway = $aiGatewayService->findById($aiServiceModel->getAiGatewayId());
        if (!$aiGateway) {
            throw new \Exception('AI Gateway not found');
        }        
        // Get API key from gateway
        $apiKey = $aiGateway->getApiKey();
        
        if (!$apiKey || $apiKey === '') {
            throw new \Exception('CQAIGateway API key not configured');
        }

        // filter injected system data from messages
        $filteredMessages = $this->filterInjectedSystemData($request->getMessages());
        
        // Prepare request data
        $requestData = [
            'model' => $aiServiceModel->getModelSlug(),
            'messages' => $filteredMessages
        ];
        
        if ($request->getMaxTokens() !== null) {
            $requestData['max_tokens'] = $request->getMaxTokens();
        }
        
        if ($request->getTemperature() !== null) {
            $requestData['temperature'] = $request->getTemperature();
        }
        
        if ($request->getStopSequence() !== null) {
            $requestData['stop'] = [$request->getStopSequence()];
        }
        
        if ($request->getTools() !== null && count($request->getTools()) > 0) {
            $requestData['tools'] = $request->getTools();
            $requestData['tool_choice'] = 'auto';
        }
        
        // Prepare headers
        $headers = [
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . $apiKey,
            'User-Agent' => 'CitadelQuest ' . CitadelVersion::VERSION . ' HTTP Client'
        ];
        
        try {
            // Send request to CQAIGateway API
            $response = $this->httpClient->request('POST', $aiGateway->getApiEndpointUrl() . '/ai/chat/completions', [
                'headers' => $headers,
                'json' => $requestData,
                'timeout' => 600, // 10 minutes timeout
                'max_duration' => 600 // 10 minutes max duration for the entire request
            ]);
            
            // Get response data
            $responseContent = $response->getContent();

            // Parse response data
            $responseData = json_decode($responseContent, true) ?? [];
            $choices = $responseData['choices'] ?? [];
            $message = $choices[0]['message'] ?? [];
            $finishReason = $choices[0]['finish_reason'] ?? null;

            // check if message contains injected system data
            if (isset($message['content'])) {
                $message['content'] = $this->detectAndMarkInjectedSystemDataAsHallucination($message['content']);
                // TODO: if hallucination detected, make request again
            }
            
            // Create response entity
            $aiServiceResponse = new AiServiceResponse(
                $request->getId(),
                $message,
                $responseData
            );
            
            // Calculate tokens if available in response
            if (isset($responseData['usage'])) {
                $aiServiceResponse->setInputTokens($responseData['usage']['prompt_tokens'] ?? null);
                $aiServiceResponse->setOutputTokens($responseData['usage']['completion_tokens'] ?? null);
                $aiServiceResponse->setTotalTokens($responseData['usage']['total_tokens'] ?? null);
                // TODO (in future): set also price/credit balance
            }
            
            $aiServiceResponse->setFinishReason($finishReason);

            return $aiServiceResponse;
            
        } catch (\Exception $e) {
            // Handle errors
            $errorResponse = new AiServiceResponse(
                $request->getId(),
                ['error' => $e->getMessage()],
                ['response' => $response->getContent(false)]
            );
            
            return $errorResponse;
        }
    }

    /**
     * Handle tool calls
     */
    public function handleToolCalls(AiServiceRequest $request, AiServiceResponse $response, string $lang = 'English'): AiServiceResponse
    {
        // Get services
        $aiToolCallService = $this->serviceLocator->get(AIToolCallService::class);
        $aiServiceRequestService = $this->serviceLocator->get(AiServiceRequestService::class);
        $aiGatewayService = $this->serviceLocator->get(AiGatewayService::class);
        
        if ($response->getFinishReason() === 'tool_calls') {
            // Get all tool calls from fullResponse
            $toolCalls = $response->getFullResponse()['choices'][0]['message']['tool_calls'];
            
            $messages = $request->getMessages();

            // Filter injected system data from messages, so we do not send it to AI + database
            $messages = $this->filterInjectedSystemData($messages);

            // Add current assistant message, including tool_calls
            $messages[] = $response->getFullResponse()['choices'][0]['message'];

            // Process tool_calls
            $logCaption = '';
            $injectedSystemDataItems = [];
            foreach ($toolCalls as $toolCall) {
                // Call tool and add result using AIToolCallService
                $toolExecutionResult = $aiToolCallService->executeTool(
                        $toolCall['function']['name'], 
                        isset($toolCall['function']['arguments']) ? 
                            (is_array($toolCall['function']['arguments']) ? 
                                $toolCall['function']['arguments'] : 
                                json_decode($toolCall['function']['arguments'], true)
                            ) : 
                            [],
                        $lang
                );                

                // Inject system data:
                // > tool name + success/fail icon
                $injectedSystemDataItems[] = 
                "<span class='text-cyber font-monospace small'>" . 
                    $toolCall['function']['name'] . "(): " . 
                    ((isset($toolExecutionResult['success']) && $toolExecutionResult['success'] == true) ? 
                        "<span class='text-success' title='" . (isset($toolExecutionResult['message']) ? $toolExecutionResult['message'] : 'Success') . "'>🗸</span>" : 
                        "<span class='text-danger' title='" . (isset($toolExecutionResult['error']) ? $toolExecutionResult['error'] : 'Failed') . "'>✗</span>") . 
                "</span>";
                // > `_frontendData`
                if (isset($toolExecutionResult['_frontendData'])) {
                    $injectedSystemDataItems[] = 
                    "<div data-src='injected system data' data-type='tool_calls_frontend_data' data-ai-generated='false' class='bg-dark p-2 mb-2 rounded d-block w-100'>\n" . 
                        $toolExecutionResult['_frontendData'] . 
                    "</div>\n";

                    // remove _frontendData from toolExecutionResult for AI
                    unset($toolExecutionResult['_frontendData']);
                }

                $toolMessageContent = [
                    'tool_call_id' => $toolCall['id'],
                    'role' => 'tool',
                    'name' => $toolCall['function']['name'],
                    'content' => json_encode($toolExecutionResult)
                ];
                // Add tool response messages
                $messages[] = $toolMessageContent;

                // Add tool name to log caption
                $logCaption .= $toolMessageContent['name'] . ', ';
            }
            
            // Create and save the AI service request
            $aiServiceRequest = $aiServiceRequestService->createRequest(
                $request->getAiServiceModelId(),
                $messages,
                $request->getMaxTokens(), $request->getTemperature(), null, $request->getTools()
            );
            
            // Send the request to the AI service
            $aiServiceResponse = $aiGatewayService->sendRequest($aiServiceRequest, 'Tool use response [' . $logCaption . ']');

            // Combine full response message: before tool call AI response + injected system data + after tool call AI response
            // > before tool call AI response
            $responseContent_before = isset($response->getFullResponse()['choices'][0]['message']['content']) ? $response->getFullResponse()['choices'][0]['message']['content'] : '';
            
            // > Inject system data
            // TODO: this need FIX, if there will be `</div>` in $injectedSystemDataItems, it will break(aka fuck up) the message
            $responseContent_injectedSystemData = "\n<div data-src='injected system data' data-type='tool_calls_response' data-ai-generated='false' class='bg-dark p-2 mb-2 rounded'>\n" . 
                                        implode("<br>\n", $injectedSystemDataItems) . 
                                    "</div>\n";

            // > append after tool call AI response
            $responseContent_after = isset($aiServiceResponse->getMessage()['content']) ? $aiServiceResponse->getMessage()['content'] : '';
            // TODO: Gemini 2.5 pro - sometimes produces same response + tool call / reproduce, debug, fix
            if ($responseContent_before == $responseContent_after) {
                $responseContent_after = '';
            }

            // Set full response message
            $aiServiceResponse->setMessage([
                'role' => 'assistant',
                'content' => $responseContent_before . $responseContent_injectedSystemData . $responseContent_after
            ]);

            $response = $aiServiceResponse;
        }
        return $response;
    }

    /**
     * Filter out injected system data from messages
     */
    public function filterInjectedSystemData(array $messages): array
    {
        $filteredMessages = [];
        // TODO: this need FIX, if there will be `</div>` in $injectedSystemDataItems, it will break(aka fuck up) the message
        $filterRegex = '/\n<div data-src=\'injected system data\' data-type=\'tool_calls_frontend_data\' data-ai-generated=\'false\' class=\'bg-dark p-2 mb-2 rounded d-block w-100\'>.*?<\/div>\n/s';

        foreach ($messages as $message) {
            $filteredMessage = $message;
            
            if (($message['role'] == 'user' || $message['role'] == 'assistant') && isset($message['content'])) {

                $filteredContent = $message['content'];

                // simple string message content
                if (is_string($filteredContent)) {
                    $filteredContent = preg_replace($filterRegex, '', $filteredContent);
                    // do not execute next line, it's actually usefull to hallucination detection to keep it (and keep this comment too)
                    //$filteredContent = preg_replace('/\n<div data-src=\'injected system data\' data-type=\'tool_calls_response\' data-ai-generated=\'false\' class=\'bg-dark p-2 mb-2 rounded\'>.*?<\/div>\n/s', '', $filteredContent);
                    $filteredMessage['content'] = $filteredContent;
                } 
                // complex message content (array of objects), text + images..
                elseif (is_array($filteredContent)) {
                    for ($i = 0; $i < count($filteredContent); $i++) {
                        // we are only interested in text content for now filtering
                        if (isset($filteredContent[$i]['type']) && $filteredContent[$i]['type'] == 'text' && isset($filteredContent[$i]['text'])) {
                            $filteredContent[$i]['text'] = preg_replace($filterRegex, '', $filteredContent[$i]['text']);
                        }
                    }
                    $filteredMessage['content'] = $filteredContent;
                }
            }

            $filteredMessages[] = $filteredMessage;
        }

        return $filteredMessages;
    }

    /**
     * Detect injected system data in AI response message and mark it as hallucination
     */
    public function detectAndMarkInjectedSystemDataAsHallucination(string $messageContent): string
    {
        // TODO: this need FIX, if there will be `</div>` in $injectedSystemDataItems, it will break(aka fuck up) the message
        $messageContent = preg_replace('/<div data-src=\'injected system data\' data-type=\'tool_calls_frontend_data\' data-ai-generated=\'false\' class=\'bg-dark p-2 mb-2 rounded d-block w-100\'>.*?<\/div>/s', 
            '<div class=\'alert alert-danger\'>Hallucination detected (system tool calls frontend data generated)</div>', $messageContent);
        
        $messageContent = preg_replace('/<div data-src=\'injected system data\' data-type=\'tool_calls_response\' data-ai-generated=\'false\' class=\'bg-dark p-2 mb-2 rounded\'>.*?<\/div>/s', 
            '<div class=\'alert alert-danger\'>Hallucination detected (system tool calls response generated)</div>', $messageContent);
        
        return $messageContent;
    }

    /**
     * Get available models from the AI service
     */
    public function getAvailableModels(AiGateway $aiGateway): array
    {
        try {
            // Fetch models from CQAIGateway API
            $response = $this->httpClient->request('GET', $aiGateway->getApiEndpointUrl() . '/ai/models', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $aiGateway->getApiKey(),
                    'Content-Type' => 'application/json',
                    'User-Agent' => 'CitadelQuest ' . CitadelVersion::VERSION . ' HTTP Client'
                ]
            ]);

            /* output:
            {
                "data": [
                    {
                    "id": "microsoft/phi-4-reasoning-plus:free",
                    "name": "Microsoft: Phi 4 Reasoning Plus (free)",
                    "created": 1746130961,
                    "description": "Phi-4-reasoning-plus is an enhanced 14B parameter model from Microsoft, fine-tuned from Phi-4 with additional reinforcement learning to boost accuracy on math, science, and code reasoning tasks. It uses the same dense decoder-only transformer architecture as Phi-4, but generates longer, more comprehensive outputs structured into a step-by-step reasoning trace and final answer.\n\nWhile it offers improved benchmark scores over Phi-4-reasoning across tasks like AIME, OmniMath, and HumanEvalPlus, its responses are typically ~50% longer, resulting in higher latency. Designed for English-only applications, it is well-suited for structured reasoning workflows where output quality takes priority over response speed.",
                    "context_length": 32768,
                    "architecture": {
                        "modality": "text->text",
                        "input_modalities": [
                        "text"
                        ],
                        "output_modalities": [
                        "text"
                        ],
                        "tokenizer": "Other",
                        "instruct_type": null
                    },
                    "pricing": {
                        "prompt": "0",
                        "completion": "0",
                        "request": "0",
                        "image": "0",
                        "web_search": "0",
                        "internal_reasoning": "0"
                    },
                    "top_provider": {
                        "context_length": 32768,
                        "max_completion_tokens": null,
                        "is_moderated": false
                    },
                    "per_request_limits": null,
                    "supported_parameters": [
                        "max_tokens",
                        "temperature",
                        "top_p",
                        "reasoning",
                        "include_reasoning",
                        "stop",
                        "frequency_penalty",
                        "presence_penalty",
                        "seed",
                        "top_k",
                        "min_p",
                        "repetition_penalty",
                        "logprobs",
                        "logit_bias",
                        "top_logprobs"
                    ]
                    },
                    {
                    "id": "microsoft/phi-4-reasoning-plus",
                    "name": "Microsoft: Phi 4 Reasoning Plus",
                    "created": 1746130961,
                    "description": "Phi-4-reasoning-plus is an enhanced 14B parameter model from Microsoft, fine-tuned from Phi-4 with additional reinforcement learning to boost accuracy on math, science, and code reasoning tasks. It uses the same dense decoder-only transformer architecture as Phi-4, but generates longer, more comprehensive outputs structured into a step-by-step reasoning trace and final answer.\n\nWhile it offers improved benchmark scores over Phi-4-reasoning across tasks like AIME, OmniMath, and HumanEvalPlus, its responses are typically ~50% longer, resulting in higher latency. Designed for English-only applications, it is well-suited for structured reasoning workflows where output quality takes priority over response speed.",
                    "context_length": 32768,
                    "architecture": {
                        "modality": "text->text",
                        "input_modalities": [
                        "text"
                        ],
                        "output_modalities": [
                        "text"
                        ],
                        "tokenizer": "Other",
                        "instruct_type": null
                    },
                    "pricing": {
                        "prompt": "0.00000007",
                        "completion": "0.00000035",
                        "request": "0",
                        "image": "0",
                        "web_search": "0",
                        "internal_reasoning": "0"
                    },
                    "top_provider": {
                        "context_length": 32768,
                        "max_completion_tokens": null,
                        "is_moderated": false
                    },
                    "per_request_limits": null,
                    "supported_parameters": [
                        "max_tokens",
                        "temperature",
                        "top_p",
                        "reasoning",
                        "include_reasoning",
                        "stop",
                        "frequency_penalty",
                        "presence_penalty",
                        "repetition_penalty",
                        "response_format",
                        "top_k",
                        "seed",
                        "min_p"
                    ]
                    }
                ]
            }
            */
            
            // Parse response
            $responseData = json_decode($response->getContent(), true);
            $models = [];
            
            // Extract models from response
            if (isset($responseData['models']) && is_array($responseData['models'])) {

                // re-order: by 'name' asc
                usort($responseData['models'], function ($a, $b) {
                    return strcasecmp($a['name'], $b['name']);
                });

                foreach ($responseData['models'] as $model) {
                    if (isset($model['id'])) {
                        $model['provider'] = 'cqaigateway';
                        $models[] = $model;
                    }
                }
            }
            
            return $models;
            
        } catch (\Exception $e) {
            // In case of error, return default models
            return [$e->getMessage()];
        }
    }

    /**
     * Get available tools for CQAIGateway
     */
    public function getAvailableTools(): array
    {
        // Use the static getInstance method to avoid circular dependencies
        $aiToolService = $this->serviceLocator->get(AiToolService::class);
        $toolsBase = $aiToolService->getToolDefinitions();

        // Convert to CQAIGateway format
        $tools = [];
        foreach ($toolsBase as $toolDef) {
            $tools[] = [
                'type' => 'function',
                'function' => [
                    'name' => $toolDef['name'],
                    'description' => $toolDef['description'],
                    'parameters' => $toolDef['parameters']
                ]
            ];
        }

        return $tools;
    }
}
