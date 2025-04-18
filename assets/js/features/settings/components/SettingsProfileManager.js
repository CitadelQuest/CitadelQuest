// Profile settings management
import * as bootstrap from 'bootstrap';

export class SettingsProfileManager {
    constructor() {
        this.initDescriptionForm();
    }

    initDescriptionForm() {
        const editDescriptionButton = document.getElementById('edit-description-button');
        if (!editDescriptionButton) return;

        const descriptionForm = document.getElementById('description-form');
        if (!descriptionForm) return;

        editDescriptionButton.addEventListener('click', function() {
            const description = this.getAttribute('data-description');
            document.getElementById('edit-description').value = description;
        });

        descriptionForm.addEventListener('submit', async (e) => {
            e.preventDefault();
            
            const submitButton = descriptionForm.querySelector('button[type="submit"]');
            const spinner = submitButton.querySelector('.spinner-border');
            const descriptionValue = document.getElementById('edit-description').value;
            
            // Show spinner
            spinner.classList.remove('d-none');
            submitButton.disabled = true;
            
            try {
                const response = await fetch('/api/settings/profile.description', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: JSON.stringify({ value: descriptionValue })
                });
                
                const data = await response.json();
                
                if (response.ok) {
                    // Update the description on the page
                    const descriptionElement = document.getElementById('profile-description');
                    if (descriptionElement) {
                        descriptionElement.innerText = descriptionValue;//.replace(/\n/g, '<br>');
                    }
                    const editDescriptionButton = document.getElementById('edit-description-button');
                    editDescriptionButton.setAttribute('data-description', descriptionValue);
                    
                    // Show success message
                    window.toast.show('Description updated successfully', 'success');
                    
                    // Close the modal
                    const modal = bootstrap.Modal.getInstance(document.getElementById('descriptionModal'));
                    modal.hide();
                } else {
                    // Show error message
                    window.toast.show('Failed to update description', 'error');
                }
            } catch (error) {
                console.error('Error updating description:', error);
                window.toast.show('Connection error. Please try again.', 'error');
            } finally {
                // Hide spinner
                spinner.classList.add('d-none');
                submitButton.disabled = false;
            }
        });
    }
}
