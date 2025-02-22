import { Toast } from 'bootstrap';

export class ProfileManager {
    constructor() {
        const container = document.querySelector('[data-translations]');
        this.translations = container ? JSON.parse(container.dataset.translations) : {};
        this.emailForm = document.getElementById('email-form');
        this.passwordForm = document.getElementById('password-form');
        this.initializeEventListeners();
    }

    initializeEventListeners() {
        this.emailForm.addEventListener('submit', this.handleEmailUpdate.bind(this));
        this.passwordForm.addEventListener('submit', this.handlePasswordUpdate.bind(this));
    }

    async handleEmailUpdate(event) {
        event.preventDefault();
        const form = event.target;
        const formData = new FormData(form);
        const submitButton = form.querySelector('button[type="submit"]');
        const spinner = submitButton.querySelector('.spinner-border');

        try {
            // Show loading state
            form.classList.add('form-processing');
            spinner.classList.remove('d-none');
            submitButton.disabled = true;

            const response = await fetch(form.action, {
                method: 'POST',
                body: formData,
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            });

            const data = await response.json();

            if (response.ok) {
                // Update email display
                document.getElementById('current-email').textContent = formData.get('email');
                form.reset();
                
                // Show success message
                this.showToast('success', this.translations.email_updated);
            } else {
                this.showToast('error', data.message || this.translations.email_error);
            }
        } catch (error) {
            this.showToast('error', this.translations.connection_error);
        } finally {
            // Reset loading state
            form.classList.remove('form-processing');
            spinner.classList.add('d-none');
            submitButton.disabled = false;
        }
    }

    async handlePasswordUpdate(event) {
        event.preventDefault();
        const form = event.target;
        const formData = new FormData(form);
        const submitButton = form.querySelector('button[type="submit"]');
        const spinner = submitButton.querySelector('.spinner-border');

        try {
            // Show loading state
            form.classList.add('form-processing');
            spinner.classList.remove('d-none');
            submitButton.disabled = true;

            const response = await fetch(form.action, {
                method: 'POST',
                body: formData,
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            });

            const data = await response.json();

            if (response.ok) {
                form.reset();
                this.showToast('success', this.translations.password_updated);
            } else {
                this.showToast('error', data.message || this.translations.password_error);
            }
        } catch (error) {
            this.showToast('error', this.translations.connection_error);
        } finally {
            // Reset loading state
            form.classList.remove('form-processing');
            spinner.classList.add('d-none');
            submitButton.disabled = false;
        }
    }

    showToast(type, message) {
        // Create toast notification
        const toast = document.createElement('div');
        toast.className = `toast align-items-center text-white bg-${type === 'error' ? 'danger' : type} border-0`;
        toast.setAttribute('role', 'alert');
        toast.setAttribute('aria-live', 'assertive');
        toast.setAttribute('aria-atomic', 'true');
        
        toast.innerHTML = `
            <div class="d-flex">
                <div class="toast-body">
                    ${message}
                </div>
                <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>
            </div>
        `;

        document.getElementById('toast-container').appendChild(toast);
        const bsToast = new Toast(toast);
        bsToast.show();

        // Remove toast after it's hidden
        toast.addEventListener('hidden.bs.toast', () => toast.remove());
    }
}
