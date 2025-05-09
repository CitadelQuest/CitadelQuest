/**
 * CitadelQuest Onboarding Flow
 * 
 * Handles the interactive onboarding process for new users
 */
import { showNotification } from '../../shared/notifications';

document.addEventListener('DOMContentLoaded', function() {
    // Disable spirit chat during onboarding to prevent errors
    // The spirit icon in the menu should be inactive during onboarding
    const spiritChatButton = document.getElementById('spiritChatButton');
    if (spiritChatButton) {
        // Disable the button during onboarding
        spiritChatButton.style.pointerEvents = 'none';
        spiritChatButton.style.opacity = '0.5';
        
        // Prevent the default SpiritChatManager from initializing
        spiritChatButton.classList.remove('spirit-chat-trigger');
    }
    // Elements
    const apiKeyInput = document.getElementById('apiKey');
    const validateApiKeyBtn = document.getElementById('validateApiKey');
    const spiritNameInput = document.getElementById('spiritName');
    const createSpiritBtn = document.getElementById('createSpirit');
    const backToStep1Btn = document.getElementById('backToStep1');
    const startJourneyBtn = document.getElementById('startJourney');
    const spiritImage = document.getElementById('spiritImage');
    const spiritNameDisplay = document.getElementById('spiritNameDisplay');
    const stepDots = document.querySelectorAll('.step-dot');
    const steps = document.querySelectorAll('.step-container');
    
    // Skip if elements don't exist (not on onboarding page)
    if (!apiKeyInput || !validateApiKeyBtn) return;
    
    // State
    let currentStep = 1;
    let selectedColor = '#6c5ce7';
    let selectedModelId = '';
    
    // Initialize model selection
    const modelRadios = document.querySelectorAll('input[name="aiModel"]');
    
    // Get all available models from the server
    const availableModelsElement = document.getElementById('availableModels');
    let availableModels = {};
    let gatewayId = null;
    
    // Debug the models data
    console.log('Raw models data:', availableModelsElement?.dataset?.models);
    
    try {
        if (availableModelsElement && availableModelsElement.dataset.models) {
            availableModels = JSON.parse(availableModelsElement.dataset.models);
            console.log('Parsed models:', availableModels);
        } else {
            console.warn('No models data found in the DOM');
        }
    } catch (e) {
        console.error('Error parsing models data:', e);
    }
    
    // Function to fetch models directly from the gateway API
    function fetchModelsFromGateway() {
        console.log('Fetching models from gateway...');
        // First, we need to get the gateway ID
        fetch('/api/ai/gateway', {
            method: 'GET',
            headers: {
                'Content-Type': 'application/json'
            }
        })
        .then(response => response.json())
        .then(gateways => {
            console.log('Available gateways:', gateways);
            if (gateways && gateways.length > 0) {
                // Find the CQ gateway
                const cqGateway = gateways.find(g => 
                    g.name.toLowerCase().includes('cq') || 
                    g.apiEndpointUrl.toLowerCase().includes('cqaigateway.com')
                );
                
                if (cqGateway) {
                    gatewayId = cqGateway.id;
                    console.log('Found CQ gateway with ID:', gatewayId);
                    
                    // Now fetch models for this gateway
                    return fetch(`/api/ai/gateway/${gatewayId}/models`, {
                        method: 'GET',
                        headers: {
                            'Content-Type': 'application/json'
                        }
                    });
                } else {
                    console.warn('No CQ gateway found');
                    throw new Error('No CQ gateway found');
                }
            } else {
                console.warn('No gateways found');
                throw new Error('No gateways found');
            }
        })
        .then(response => response.json())
        .then(models => {
            console.log('Fetched models from API:', models);
            
            // Convert models to the format we need
            availableModels = {};
            models.forEach(model => {
                availableModels[model.id] = {
                    id: model.id,
                    name: model.name,
                    modelSlug: model.id,
                    gatewayId: gatewayId
                };
            });
            
            console.log('Processed models:', availableModels);
        })
        .catch(error => {
            console.error('Error fetching models:', error);
        });
    }
    
    // If no models found, try to fetch them directly
    if (Object.keys(availableModels).length === 0) {
        fetchModelsFromGateway();
    }
    
    // Color picker functionality
    const colorOptions = document.querySelectorAll('.color-option');
    colorOptions.forEach(option => {
        option.addEventListener('click', function() {
            // Remove selected class from all options
            colorOptions.forEach(opt => opt.classList.remove('selected'));
            // Add selected class to clicked option
            this.classList.add('selected');
            // Update selected color
            selectedColor = this.dataset.color;
        });
    });
    
    // Enable/disable validate button based on API key input
    apiKeyInput.addEventListener('input', function() {
        validateApiKeyBtn.disabled = !this.value.trim();
    });
    
    // Enable/disable create spirit button based on spirit name input
    spiritNameInput.addEventListener('input', function() {
        createSpiritBtn.disabled = !this.value.trim();
    });
    
    // Step 1: Validate API Key and proceed to step 2
    validateApiKeyBtn.addEventListener('click', function() {
        const apiKey = apiKeyInput.value.trim();
        
        if (!apiKey) {
            showError('Please enter a valid API key');
            return;
        }
        
        // Disable button and show loading state
        validateApiKeyBtn.disabled = true;
        validateApiKeyBtn.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Validating...';
        
        // Call the API to validate and add the gateway
        fetch('/welcome/add-gateway', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: new URLSearchParams({
                apiKey: apiKey
            })
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                // Update UI to show success
                spiritImage.classList.remove('spirit-inactive');
                spiritImage.classList.add('spirit-active');
                
                // Populate model selection with actual models
                updateModelSelection();
                
                // Move to step 2
                goToStep(2);
            } else {
                showError(data.message || 'Failed to validate API key');
                validateApiKeyBtn.disabled = false;
                validateApiKeyBtn.textContent = 'Validate & Continue';
            }
        })
        .catch(error => {
            console.error('Error:', error);
            showError('An error occurred while validating your API key');
            validateApiKeyBtn.disabled = false;
            validateApiKeyBtn.textContent = 'Validate & Continue';
        });
    });
    
    // Step 2: Create Spirit and proceed to step 3
    createSpiritBtn.addEventListener('click', function() {
        const spiritName = spiritNameInput.value.trim();
        
        if (!spiritName) {
            showError('Please enter a name for your Spirit');
            return;
        }
        
        // Get selected model ID based on radio button value
        const selectedModelValue = document.querySelector('input[name="aiModel"]:checked').value;
        console.log('Selected model value:', selectedModelValue);
        
        // Find the corresponding model ID from hidden inputs
        const modelInput = document.querySelector(`input[name="modelId"][data-model="${selectedModelValue}"]`);
        if (modelInput) {
            selectedModelId = modelInput.value;
            console.log('Found model ID from hidden input:', selectedModelId);
        } else {
            // Fallback to the old method if hidden input not found
            switch (selectedModelValue) {
                case 'claude':
                    selectedModelId = findModelIdBySlug('claude');
                    break;
                case 'gemini':
                    selectedModelId = findModelIdBySlug('gemini');
                    break;
                case 'grok':
                    selectedModelId = findModelIdBySlug('grok');
                    break;
                default:
                    selectedModelId = findModelIdBySlug('claude');
            }
            console.log('Found model ID using slug search:', selectedModelId);
        }
        
        // If we have models from the API but couldn't find a match, try a more flexible search
        if (!selectedModelId && Object.keys(availableModels).length > 0) {
            console.log('Trying flexible model search...');
            // Try to find any model that matches the selected type
            for (const [id, model] of Object.entries(availableModels)) {
                const modelName = model.name.toLowerCase();
                const modelSlug = (model.modelSlug || '').toLowerCase();
                
                if ((selectedModelValue === 'claude' && (modelName.includes('claude') || modelSlug.includes('claude'))) ||
                    (selectedModelValue === 'gemini' && (modelName.includes('gemini') || modelSlug.includes('gemini'))) ||
                    (selectedModelValue === 'grok' && (modelName.includes('grok') || modelSlug.includes('grok')))) {
                    selectedModelId = id;
                    console.log('Found model with flexible search:', selectedModelId, model);
                    break;
                }
            }
        }
        
        // If still no model ID, try to get any model ID as fallback
        if (!selectedModelId) {
            const anyModelInput = document.querySelector('input[name="modelId"]');
            if (anyModelInput) {
                selectedModelId = anyModelInput.value;
                console.log('Using fallback model ID:', selectedModelId);
            }
        }
        
        if (!selectedModelId) {
            showError('Please select an AI model');
            return;
        }
        
        // Disable button and show loading state
        createSpiritBtn.disabled = true;
        createSpiritBtn.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Creating...';
        
        // Call the API to create the spirit
        console.log('Creating spirit with:', {
            name: spiritName,
            modelId: selectedModelId,
            color: selectedColor
        });
        
        fetch('/welcome/create-spirit', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: new URLSearchParams({
                name: spiritName,
                modelId: selectedModelId,
                color: selectedColor
            })
        })
        .then(response => {
            console.log('Response status:', response.status);
            if (!response.ok) {
                console.error('Response not OK:', response.status, response.statusText);
            }
            return response.json().catch(err => {
                console.error('Error parsing JSON:', err);
                throw new Error('Invalid JSON response');
            });
        })
        .then(data => {
            console.log('Response data:', data);
            if (data.success) {
                // Update UI for step 3
                spiritNameDisplay.textContent = spiritName;
                
                // Move to step 3
                goToStep(3);
            } else {
                showError(data.message || 'Failed to create Spirit');
                createSpiritBtn.disabled = false;
                createSpiritBtn.textContent = 'Create Spirit';
            }
        })
        .catch(error => {
            console.error('Error creating spirit:', error);
            showError('An error occurred while creating your Spirit');
            createSpiritBtn.disabled = false;
            createSpiritBtn.textContent = 'Create Spirit';
        });
    });
    
    // Back button functionality
    backToStep1Btn.addEventListener('click', function() {
        goToStep(1);
    });
    
    // Start journey button
    startJourneyBtn.addEventListener('click', function() {
        // Mark onboarding as complete
        fetch('/welcome/complete', {
            method: 'POST',
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            }
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                // Redirect to spirit chat
                window.location.href = '/spirit';
            }
        })
        .catch(error => {
            console.error('Error:', error);
            // Redirect anyway
            window.location.href = '/spirit';
        });
    });
    
    // Helper function to go to a specific step
    function goToStep(stepNumber) {
        // Update current step
        currentStep = stepNumber;
        
        // Hide all steps
        steps.forEach(step => step.classList.remove('active'));
        
        // Show current step
        document.getElementById(`step${stepNumber}`).classList.add('active');
        
        // Update step dots
        stepDots.forEach((dot, index) => {
            const dotStep = index + 1;
            
            dot.classList.remove('active', 'completed');
            
            if (dotStep === currentStep) {
                dot.classList.add('active');
            } else if (dotStep < currentStep) {
                dot.classList.add('completed');
            }
        });
    }
    
    // Helper function to show error message
    function showError(message) {
        // Check if notification system is available
        if (typeof showNotification === 'function') {
            showNotification('danger', message);
        } else {
            alert(message);
        }
    }
    
    // Helper function to update model selection based on available models
    function updateModelSelection() {
        // This function would populate the model selection with actual models
        // from the server, but for simplicity we're using the predefined radio buttons
    }
    
    // Helper function to find model ID by slug
    function findModelIdBySlug(slugPart) {
        console.log('Finding model ID for slug part:', slugPart);
        console.log('Available models:', availableModels);
        
        // Default to first model if no matches found
        if (!availableModels || Object.keys(availableModels).length === 0) {
            console.log('No available models found, using fallback');
            const fallbackId = document.querySelector('input[name="modelId"]')?.value || '';
            console.log('Fallback model ID:', fallbackId);
            return fallbackId;
        }
        
        // Find model by slug
        for (const [id, model] of Object.entries(availableModels)) {
            console.log('Checking model:', id, model);
            
            // Check model slug
            if (model.modelSlug && model.modelSlug.toLowerCase().includes(slugPart.toLowerCase())) {
                console.log('Found matching model by modelSlug:', id);
                return id;
            }
            
            // Also check model name
            if (model.name && model.name.toLowerCase().includes(slugPart.toLowerCase())) {
                console.log('Found matching model by name:', id);
                return id;
            }
            
            // Check the ID itself
            if (id.toLowerCase().includes(slugPart.toLowerCase())) {
                console.log('Found matching model by ID:', id);
                return id;
            }
        }
        
        // Return first model ID as fallback
        const firstId = Object.keys(availableModels)[0] || '';
        console.log('Using first model as fallback:', firstId);
        return firstId;
    }
});
