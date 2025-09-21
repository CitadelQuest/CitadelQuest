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
    let currentStep = parseInt(localStorage.getItem('currentStep')) || parseInt(sessionStorage.getItem('currentStep')) || 1;
    let currentStepEl = document.getElementById('currentStep');
    if (currentStepEl) {
        currentStep = parseInt(currentStepEl.innerText);
    }
    return currentStep;
}

document.addEventListener('DOMContentLoaded', function() {
    console.log('Onboarding loaded');
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
    const spiritImage = document.getElementById('spiritImage');
    const stepDots = document.querySelectorAll('.step-dot');
    const steps = document.querySelectorAll('.step-container');
    
    // Skip if elements don't exist (not on onboarding page)
    console.log('Elements:', apiKeyInput, validateApiKeyBtn);
    if (!apiKeyInput || !validateApiKeyBtn) return;
    
    // State
    let currentStep = loadCurrentStep();
    console.log('Current step:', currentStep);
    let selectedColor = '#6c5ce7';
    
    // Color picker functionality
    const colorOptions = document.querySelectorAll('.color-option');
    const colorPickerInput = document.getElementById('colorPickerInput');
    const colorPicker = document.getElementById('colorPicker');
    const colorOptionCustom = document.getElementById('color-option-custom');
    
    // Function to update selected color and UI
    function selectColor(element, color) {
        // Remove selected class from all options
        colorOptions.forEach(opt => opt.classList.remove('selected'));
        // Add selected class to clicked option
        element.classList.add('selected');
        // Update selected color
        selectedColor = color;
        // Update avatar glow color
        updateSpiritAvatarColor(color);
    }
    
    // Function to update spirit avatar color
    function updateSpiritAvatarColor(color) {
        const spiritAvatars = document.querySelectorAll('#spiritChatButtonIcon, .spiritChatButtonIcon');
        if (spiritAvatars && color) {
            spiritAvatars.forEach(icon => icon.style.color = color);
        }
    }
    
    // Add click handlers to predefined color options
    colorOptions.forEach(option => {
        // Skip the custom color option - it has special handling
        if (option.id === 'color-option-custom') return;
        
        option.addEventListener('click', function() {
            // Reset custom color option when selecting predefined colors
            if (colorOptionCustom && colorPicker) {
                colorOptionCustom.style.backgroundColor = '#ffffff';
                colorOptionCustom.style.border = '2px dashed #6c757d';
                colorOptionCustom.dataset.color = '';
                colorPicker.style.display = 'block';
                colorPicker.style.color = '#6c757d';
            }
            
            selectColor(this, this.dataset.color);
        });
    });

    // Custom color picker functionality
    if (colorPicker && colorPickerInput && colorOptionCustom) {
        // When clicking the palette icon, open the color picker
        colorPicker.addEventListener('click', function(e) {
            e.stopPropagation(); // Prevent the parent div click
            // deselect all color options
            colorOptions.forEach(opt => opt.classList.remove('selected'));
            colorOptionCustom.classList.remove('selected');

            colorPickerInput.click();
        });
        
        // When clicking the custom color option itself, also open color picker
        colorOptionCustom.addEventListener('click', function() {
            colorPickerInput.click();
        });
        
        // When color is selected from the picker
        colorPickerInput.addEventListener('input', function() {
            const selectedCustomColor = this.value;
            
            // Update the custom option appearance
            colorOptionCustom.style.backgroundColor = selectedCustomColor;
            colorOptionCustom.style.border = '2px solid #ffffff';
            colorOptionCustom.dataset.color = selectedCustomColor;
            
            // Hide the palette icon when a color is selected
            colorPicker.style.display = 'none';
            
            // Select this custom color
            selectColor(colorOptionCustom, selectedCustomColor);
        });
    }
    
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
                spiritImage?.classList?.remove('spirit-inactive');
                spiritImage?.classList?.add('spirit-active');
                
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
    
    // Step 2: Create Spirit
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
        createSpiritBtn.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> ...';
        
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
                // save spiritId to localStorage 
                localStorage.setItem('spiritId', data.spirit.id);
                
                // remove onboarding from localStorage
                localStorage.removeItem('currentStep');

                // enable spiritChatButton
                spiritChatButton.style.pointerEvents = 'auto';
                spiritChatButton.style.opacity = '1';

                // Create 'first' spirit_conversation
                fetch('/api/spirit-conversation/create', {
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
                        //document.getElementById("spiritChatButton").dispatchEvent(new Event('click', { bubbles: true }));

                        // Hide onboarding and show onboarding complete
                        document.querySelector('.onboarding-container').classList.add('d-none');
                        document.querySelector('.onboarding-complete-container').classList.remove('d-none');
                    }
                })
                .catch(error => {
                    console.error('Error creating spirit_conversation:', error);
                });

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
        modelSelection.classList.add('d-none');
        modelSelection.classList.remove('d-flex');
        modelSelection.innerHTML = '';
        
        // Add new models
        models.forEach(model => {
            const modelId = model.id;
            const modelName = model.modelName;
            const modelSlug = model.modelSlug;
            const modelSlugProvider = modelSlug.split('/')[0];
            
            if (modelSlugProvider === 'citadelquest' && (modelSlug.indexOf('-') === -1)) {
                // (modelSlug.indexOf('-') === -1) means it's one of MAIN CQ AI Gateway models
                // we want to show only MAIN CQ AI Gateway models
                // so we skip models that have '-' in their slug
            } else {
                return;
            }

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

        // show model selection
        modelSelection.classList.remove('d-none');
        modelSelection.classList.add('d-flex');
    }

    if (currentStep > 1) {
        console.log('Going to step:', currentStep);

        if (currentStep === 2) {
            // get models from session
            const modelsFromSession = JSON.parse(sessionStorage.getItem('models'));
            if (modelsFromSession) {
                updateModelSelection(modelsFromSession);   
            } else {
                // get models from API
                fetch('/api/ai/model')
                    .then(response => response.json())
                    .then(data => {
                        updateModelSelection(data.models);
                    })
                    .catch(error => {
                        console.error('Error fetching models:', error);
                    });
            }
        }

        goToStep(currentStep);
    }    
});