import { ToastService } from '../../shared/toast';

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
                this.showToast('success', this.translations.email_updated, ':)');
            } else {
                this.showToast('error', data.message || this.translations.email_error, ':(');
            }
        } catch (error) {
            this.showToast('error', this.translations.connection_error, ':(');
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

    showToast(type, message, title = null) {
        const toast = new ToastService();
        switch(type) {
            case 'success':
                toast.success(message, title);
                break;
            case 'error':
                toast.error(message, title);
                break;
            case 'warning':
                toast.warning(message, title);
                break;
            default:
                toast.info(message, title);
        }
    }
}
