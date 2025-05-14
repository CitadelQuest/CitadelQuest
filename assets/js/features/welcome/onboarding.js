/**
 * CitadelQuest Onboarding Flow
 * 
 * Handles the interactive onboarding process for new users
 */

import { SpiritChatManager } from '../spirit-chat';

// save currentStep to localStorage
function saveCurrentStep(currentStep) {
    localStorage.setItem('currentStep', currentStep);
}

// load currentStep from localStorage
function loadCurrentStep() {
    return parseInt(localStorage.getItem('currentStep')) || parseInt(sessionStorage.getItem('currentStep')) || 1;
}

document.addEventListener('DOMContentLoaded', function() {
    // Disable spirit chat during onboarding to prevent errors
    // The spirit icon in the menu should be inactive during onboarding
    const spiritChatButton = document.getElementById('spiritChatButton');
    if (spiritChatButton) {
        // Disable the button during onboarding
        spiritChatButton.style.pointerEvents = 'none';
        spiritChatButton.style.opacity = '0.5';
        
        // Prevent the default SpiritChatManager from initializing
        //spiritChatButton.classList.remove('spirit-chat-trigger'); // ??
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
    let currentStep = loadCurrentStep();
    let selectedColor = '#6c5ce7';
    
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
            // update SVG glow color
            document.getElementById('spiritImage').style.filter = `drop-shadow(0 0 10px ${selectedColor}) !important`;
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
                updateModelSelection(data.models);
                
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
        const selectedModelId = document.querySelector('input[name="aiModel"]:checked').value;
        console.log('Selected modelId value:', selectedModelId);
        
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

                // save spiritId to localStorage for step 3
                localStorage.setItem('spiritId', data.spirit.id);
                
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
    if (backToStep1Btn) {
        backToStep1Btn.addEventListener('click', function() {
            goToStep(1);
        });
    }
    
    // Start journey button
    startJourneyBtn.addEventListener('click', async function() {
        // remove onboarding from localStorage
        localStorage.removeItem('currentStep');

        // enable spiritChatButton
        spiritChatButton.style.pointerEvents = 'auto';
        spiritChatButton.style.opacity = '1';

        // Create 'first' spirit_conversation
        await fetch('/api/spirit-conversation/create', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({ spiritId: localStorage.getItem('spiritId'), title: 'First conversation' })
        })
        .then(response => response.json())
        .then(data => {
            if (data.title && data.id) {
                if (window.spiritChatManager) {
                    // destroy the current spiritChatManager
                    window.spiritChatManager = null;
                }
                // Initialize Spirit chat functionality
                window.spiritChatManager = new SpiritChatManager();
                window.spiritChatManager.init();
                
                // Open spirit chat modal
                document.getElementById("spiritChatButton").dispatchEvent(new Event('click', { bubbles: true }));
                // hide this button
                startJourneyBtn.classList.add('d-none');
            }
        })
        .catch(error => {
            console.error('Error creating spirit_conversation:', error);
        });
    });
    
    // Helper function to go to a specific step
    function goToStep(stepNumber) {
        // Update current step
        currentStep = stepNumber;
        saveCurrentStep(currentStep);
        
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
        window.toast.error(message);
    }
    
    // Helper function to update model selection based on available models
    function updateModelSelection(models) {
        // We have data from the server in the `models` parameter
        console.log('Updating model selection with models:', models);
        
        // Clear existing models
        const modelSelection = document.getElementById('modelSelection');
        modelSelection.innerHTML = '';
        
        // Add new models
        models.forEach(model => {
            const modelId = model.id;
            const modelName = model.modelName;

            // Create the label element
            const modelLabel = document.createElement('label');
            modelLabel.className = 'form-check-label form-check form-control';
            modelLabel.htmlFor = 'model-' + modelId;

            // Create the radio input element
            const modelRadio = document.createElement('input');
            modelRadio.className = 'form-check-input ms-1 me-3';
            modelRadio.type = 'radio';
            modelRadio.name = 'aiModel';
            modelRadio.id = 'model-' + modelId;
            modelRadio.value = modelId;

            // Set the first radio button as checked
            if (modelSelection.children.length === 0) {
                modelRadio.checked = true;
            }

            // Append the radio input to the label
            modelLabel.appendChild(modelRadio);

            // Append the model name to the label
            modelLabel.appendChild(document.createTextNode(modelName));

            // Append the label to the model selection container
            modelSelection.appendChild(modelLabel);
        });
    }

    if (currentStep > 1) {
        console.log('Going to step:', currentStep);

        if (currentStep === 2) {
            // get models from session
            const modelsFromSession = JSON.parse(sessionStorage.getItem('models'));
            if (modelsFromSession) {
                updateModelSelection(modelsFromSession);   
            }
        }

        goToStep(currentStep);
    }    
});