// Using global window.toast service
import * as bootstrap from 'bootstrap';

import imgDay from '../../../../images/citadel_quest_bg.jpg';
import imgNight1 from '../../../../images/night-forest-sky.webp';
import imgNight2 from '../../../../images/bg-dreamy-flowers-2.webp';

const themeImages = {
    'day': imgDay,
    'night-1': imgNight1,
    'night-2': imgNight2,
    'clear': null,
};

export class SettingsGeneralManager {
    constructor() {
        const container = document.querySelector('[data-translations]');
        this.translations = container ? JSON.parse(container.dataset.translations) : {};
        this.emailForm = document.getElementById('email-form');
        this.passwordForm = document.getElementById('password-form');
        this.databaseSizeElement = document.getElementById('database-size');
        this.databaseOptimizeBtn = document.getElementById('database-optimize');
        this.navItems = document.querySelectorAll('.settings-nav-item');
        this.sections = document.querySelectorAll('.settings-section');
        this.initializeEventListeners();
        this.initializeSectionNavigation();
        this.initializeThemePreviews();
        this.loadDatabaseStats();
    }

    initializeEventListeners() {
        if (this.emailForm) {
            this.emailForm.addEventListener('submit', this.handleEmailUpdate.bind(this));
        }
        if (this.passwordForm) {
            this.passwordForm.addEventListener('submit', this.handlePasswordUpdate.bind(this));
        }
        
        if (this.databaseOptimizeBtn) {
            this.databaseOptimizeBtn.addEventListener('click', this.handleDatabaseOptimize.bind(this));
        }

        window.addEventListener('hashchange', () => this.showSectionFromHash());
    }

    initializeSectionNavigation() {
        this.navItems.forEach(item => {
            item.addEventListener('click', (e) => {
                e.preventDefault();
                const section = item.dataset.section;
                if (section) {
                    window.location.hash = section;
                }
            });
        });

        this.showSectionFromHash();
    }

    showSectionFromHash() {
        const hash = window.location.hash.replace('#', '') || 'credentials';
        this.showSection(hash);
    }

    showSection(sectionName) {
        this.sections.forEach(section => {
            if (section.dataset.section === sectionName) {
                section.classList.remove('d-none');
            } else {
                section.classList.add('d-none');
            }
        });

        this.navItems.forEach(item => {
            if (item.dataset.section === sectionName) {
                item.classList.add('active', 'bg-primary', 'bg-opacity-25');
                item.classList.remove('bg-transparent');
            } else {
                item.classList.remove('active', 'bg-primary', 'bg-opacity-25');
                item.classList.add('bg-transparent');
            }
        });
    }
    
    initializeThemePreviews() {
        const container = document.getElementById('theme-previews');
        if (!container || !window.themeService) return;

        const themes = window.themeService.getThemes();
        const currentTheme = window.themeService.getCurrentTheme();

        themes.forEach(theme => {
            const card = document.createElement('div');
            card.className = 'theme-preview-card' + (theme.id === currentTheme ? ' active' : '');
            card.dataset.themeId = theme.id;
            card.style.cssText = 'width:22%; min-height:100px; transition: border-color 0.2s, box-shadow 0.2s; border:1px solid transparent;';
            card.classList.add('position-relative', 'd-inline-block', 'mb-2', 'rounded', 'overflow-hidden', 'cursor-pointer');
            if (theme.id === currentTheme) {
                card.classList.add('border-primary');
            }

            const img = document.createElement('div');
            img.style.cssText = 'width:100%; height:70px; opacity:0.7; background-size:cover; background-position:center;';
            if (themeImages[theme.id]) {
                img.style.backgroundImage = `url(${themeImages[theme.id]})`;
            } else {
                img.style.backgroundColor = '#2e3135';
            }

            const label = document.createElement('div');
            label.classList.add('bg-secondary', 'bg-opacity-50', 'rounded-bottom', 'small', 'text-center', 'p-1');
            label.textContent = theme.name;

            card.appendChild(img);
            card.appendChild(label);

            card.addEventListener('click', () => {
                window.themeService.setTheme(theme.id);
                container.querySelectorAll('.theme-preview-card').forEach(c => {
                    c.classList.remove('active', 'border', 'border-primary', 'shadow');
                });
                card.classList.add('active', 'shadow', 'border', 'border-primary');
            });

            container.appendChild(card);
        });
    }

    async loadDatabaseStats() {
        if (!this.databaseSizeElement) return;
        
        try {
            // Use global databaseVacuum utility if available
            if (window.databaseVacuum) {
                const stats = await window.databaseVacuum.getStats();
                if (stats) {
                    this.databaseSizeElement.textContent = stats.file_size;
                    return;
                }
            }
            this.databaseSizeElement.textContent = 'N/A';
        } catch (error) {
            console.error('Failed to load database stats:', error);
            this.databaseSizeElement.textContent = 'N/A';
        }
    }
    
    async handleDatabaseOptimize() {
        if (!this.databaseOptimizeBtn) return;
        
        const spinner = this.databaseOptimizeBtn.querySelector('.spinner-border');
        const icon = this.databaseOptimizeBtn.querySelector('.mdi');
        
        try {
            // Show loading state
            this.databaseOptimizeBtn.disabled = true;
            spinner.classList.remove('d-none');
            icon.classList.add('d-none');
            
            // Use global databaseVacuum utility (force=true to bypass interval check)
            if (!window.databaseVacuum) {
                throw new Error('Database vacuum utility not available');
            }
            
            const result = await window.databaseVacuum.vacuum(true);
            
            if (result && result.success) {
                // Update displayed size
                if (result.stats && result.stats.size_after) {
                    this.databaseSizeElement.textContent = result.stats.size_after;
                }
                
                // Show success with space saved info
                let message = this.translations.database_optimized;
                if (result.stats && result.stats.space_saved_bytes > 0) {
                    message += ` (-${result.stats.space_saved})`;
                }
                this.showToast('success', message);
            } else {
                this.showToast('error', this.translations.database_error);
            }
        } catch (error) {
            console.error('Database optimize error:', error);
            this.showToast('error', this.translations.database_error);
        } finally {
            // Reset loading state
            this.databaseOptimizeBtn.disabled = false;
            spinner.classList.add('d-none');
            icon.classList.remove('d-none');
        }
    }

    async handleEmailUpdate(event) {
        event.preventDefault();
        const form = event.target;
        const formData = new FormData(form);
        const submitButton = form.querySelector('button[type="submit"]');
        const spinner = submitButton.querySelector('.spinner-border');
        const modal = document.getElementById('emailModal');
        const modalInstance = bootstrap.Modal.getInstance(modal);

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
                
                // Close modal
                if (modalInstance) {
                    modalInstance.hide();
                }
                
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
        const modal = document.getElementById('passwordModal');
        const modalInstance = bootstrap.Modal.getInstance(modal);

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
                
                // Close modal
                if (modalInstance) {
                    modalInstance.hide();
                }
                
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
        switch(type) {
            case 'success':
                window.toast.success(message, title);
                break;
            case 'error':
                window.toast.error(message, title);
                break;
            case 'warning':
                window.toast.warning(message, title);
                break;
            default:
                window.toast.info(message, title);
        }
    }
}