<?php

namespace App\Service;

use App\Entity\AiGateway;
use App\Entity\AiServiceModel;
use App\Entity\AiServiceRequest;
use App\Entity\AiServiceResponse;
use App\Service\AIToolCallService;
use App\Service\AiServiceRequestService;
use App\Service\AiGatewayService;
use App\Service\SettingsService;
use App\Service\AiServiceModelService;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Contracts\HttpClient\HttpClientInterface;

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
        
        if (!$apiKey) {
            throw new \Exception('CQAIGateway API key not configured');
        }
        
        // Prepare request data
        $requestData = [
            'model' => $aiServiceModel->getModelSlug(),
            'messages' => $request->getMessages()
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
            'Authorization' => 'Bearer ' . $apiKey
        ];
        
        try {
            // Send request to CQAIGateway API
            $response = $this->httpClient->request('POST', $aiGateway->getApiEndpointUrl() . '/chat/completions', [
                'headers' => $headers,
                'json' => $requestData,
            ]);
            
            // Get response data
            $statusCode = $response->getStatusCode(false);
            $responseContent = $response->getContent(false);

            /* sample response:
            {
                "id": "gen-1746222835-aNYPyuYk85N8N6ubSbUv",
                "provider": "DeepSeek",
                "model": "deepseek-chat",
                "object": "chat.completion",
                "created": 1746222835,
                "choices": [
                    {
                    "logprobs": null,
                    "finish_reason": "stop",
                    "native_finish_reason": "stop",
                    "index": 0,
                    "message": {
                        "role": "assistant",
                        "content": "Hello! How can I assist you today?",
                        "refusal": null,
                        "reasoning": null
                    }
                    }
                ],
                "system_fingerprint": null,
                "usage": {
                    "prompt_tokens": 8,
                    "completion_tokens": 10,
                    "total_tokens": 18,
                    "prompt_tokens_details": {
                    "cached_tokens": 0
                    },
                    "completion_tokens_details": {
                    "reasoning_tokens": 0
                    }
                }
            }            
            */

            // Parse response data
            $responseData = json_decode($responseContent, true);
            $choices = $responseData['choices'] ?? [];
            $message = $choices[0]['message'] ?? [];
            $finishReason = $choices[0]['finish_reason'] ?? null;
            
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
            }
            
            $aiServiceResponse->setFinishReason($finishReason);
            
            return $aiServiceResponse;
            
        } catch (\Exception $e) {
            // Handle errors
            $errorResponse = new AiServiceResponse(
                $request->getId(),
                ['error' => $e->getMessage()],
                ['response' => $e->getMessage()]
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
            // get all tool calls from fullResponse
            $toolCalls = $response->getFullResponse()['choices'][0]['message']['tool_calls'];
            
            $messages = $request->getMessages();

            // add current assistant message
            $messages[] = $response->getFullResponse()['choices'][0]['message'];

            // Process tool_calls
            $toolMessageContents = [];
            foreach ($toolCalls as $toolCall) {
                // Call tool and add result using AIToolCallService
                $toolMessageContents[] = [
                    'tool_call_id' => $toolCall['id'],
                    'role' => 'tool',
                    'name' => $toolCall['function']['name'],
                    'content' => json_encode($aiToolCallService->executeTool(
                        $toolCall['function']['name'], 
                        isset($toolCall['function']['arguments']) ? 
                            (is_array($toolCall['function']['arguments']) ? 
                                $toolCall['function']['arguments'] : 
                                json_decode($toolCall['function']['arguments'], true)
                            ) : 
                            [],
                        $lang
                    ))
                ];
            }
            
            // Add tool response message
            $messages[] = count($toolMessageContents) > 1 ? $toolMessageContents : $toolMessageContents[0];
            
            // Create and save the AI service request
            $aiServiceRequest = $aiServiceRequestService->createRequest(
                $request->getAiServiceModelId(),
                $messages,
                1000, 0.1, null, $request->getTools()
            );
            
            // Send the request to the AI service
            $aiServiceResponse = $aiGatewayService->sendRequest($aiServiceRequest, 'Tool use response [' . $toolCall['function']['name'] . ']');

            $aiServiceResponse->setMessage([
                'role' => $aiServiceResponse->getMessage()['role'],
                'content' => "•\n\n" . $aiServiceResponse->getMessage()['content']
            ]);

            $response = $aiServiceResponse;
        }
        return $response;
    }

    /**
     * Get available models from the AI service
     */
    public function getAvailableModels(AiGateway $aiGateway): array
    {
        try {
            // Fetch models from CQAIGateway API
            $response = $this->httpClient->request('GET', $aiGateway->getApiEndpointUrl() . '/models', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $aiGateway->getApiKey(),
                    'Content-Type' => 'application/json'
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
                        $models[] = [
                            'id' => $model['id'],
                            'name' => $model['name'],
                            'provider' => 'cqaigateway'
                        ];
                    }
                }
            }
            
            // If no models were found, return a default list
            //if (empty($models)) {
            //    $this->getDefaultOpenRouterModels();
            //}
            
            return $models;
            
        } catch (\Exception $e) {
            // In case of error, return default models
            return [$e->getMessage()];//$this->getDefaultOpenRouterModels();
        }
    }
    
    /**
     * Get a default list of CQAIGateway models in case the API call fails
     */
    private function getDefaultOpenRouterModels(): array
    {
        return [
            ['id' => 'anthropic/claude-3-7-sonnet', 'name' => 'Claude 3.7 Sonnet', 'provider' => 'cqaigateway'],
            ['id' => 'anthropic/claude-3-5-haiku', 'name' => 'Claude 3.5 Haiku', 'provider' => 'cqaigateway'],
            ['id' => 'meta-llama/llama-4-scout-17b-16e-instruct', 'name' => 'Llama 4 Scout', 'provider' => 'cqaigateway'],
            ['id' => 'meta-llama/llama-4-maverick-17b-128e-instruct', 'name' => 'Llama 4 Maverick', 'provider' => 'cqaigateway']
        ];
    }

    /**
     * Get available tools for CQAIGateway
     */
    public function getAvailableTools(): array
    {
        // Use the static getInstance method to avoid circular dependencies
        $aiToolCallService = $this->serviceLocator->get(AIToolCallService::class);
        $toolsBase = $aiToolCallService->getToolsDefinitions();

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
