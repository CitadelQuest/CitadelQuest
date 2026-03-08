/**
 * ProfileSettingsManager
 * 
 * Manages the profile settings page: bio, photo upload/delete, 
 * public page toggles, and federation visibility settings.
 */
export class ProfileSettingsManager {
    constructor() {
        this.container = document.getElementById('profile-settings');
        if (!this.container) return;

        this.saveUrl = this.container.dataset.saveUrl;
        this.photoUploadUrl = this.container.dataset.photoUploadUrl;
        this.photoDeleteUrl = this.container.dataset.photoDeleteUrl;
        this.publicUrl = this.container.dataset.publicUrl;
        this.translations = JSON.parse(this.container.dataset.translations || '{}');

        this.initElements();
        this.initEventListeners();
    }

    initElements() {
        // Photo
        this.photoInput = document.getElementById('profile-photo-input');
        this.photoUploadBtn = document.getElementById('profile-photo-upload-btn');
        this.photoRemoveBtn = document.getElementById('profile-photo-remove-btn');
        this.photoPreview = document.getElementById('profile-photo-preview');

        // Bio
        this.bioTextarea = document.getElementById('profile-bio');
        this.bioCount = document.getElementById('profile-bio-count');

        // Public page
        this.publicEnabledCheckbox = document.getElementById('profile-public-enabled');
        this.publicUrlRow = document.getElementById('profile-public-url-row');
        this.copyUrlBtn = document.getElementById('profile-copy-url-btn');
        this.publicShowPhotoCheckbox = document.getElementById('profile-public-show-photo');
        this.publicShowSharesCheckbox = document.getElementById('profile-public-show-shares');
        this.publicShowShareContentCheckbox = document.getElementById('profile-public-show-share-content');
        this.showShareContentRow = document.getElementById('profile-show-share-content-row');
        this.publicShowSpiritsSelect = document.getElementById('profile-public-show-spirits');
        this.selectedTheme = '';

        // Federation
        this.federationBioCheckbox = document.getElementById('profile-federation-bio');
        this.federationPhotoCheckbox = document.getElementById('profile-federation-photo');
        this.federationSpiritsSelect = document.getElementById('profile-federation-spirits');

        // Save
        this.saveBtn = document.getElementById('profile-save-btn');
    }

    initEventListeners() {
        // Photo upload
        this.photoUploadBtn?.addEventListener('click', () => this.photoInput?.click());
        this.photoInput?.addEventListener('change', (e) => this.handlePhotoUpload(e));
        this.photoRemoveBtn?.addEventListener('click', () => this.handlePhotoRemove());

        // Bio character count
        this.bioTextarea?.addEventListener('input', () => {
            if (this.bioCount) {
                this.bioCount.textContent = this.bioTextarea.value.length;
            }
        });

        // Public page toggle
        this.publicEnabledCheckbox?.addEventListener('change', () => {
            this.publicUrlRow?.classList.toggle('d-none', !this.publicEnabledCheckbox.checked);
        });

        // Show shares toggle — show/hide share content sub-toggle
        this.publicShowSharesCheckbox?.addEventListener('change', () => {
            this.showShareContentRow?.classList.toggle('d-none', !this.publicShowSharesCheckbox.checked);
        });

        // Copy URL
        this.copyUrlBtn?.addEventListener('click', () => this.copyPublicUrl());

        // Save
        this.saveBtn?.addEventListener('click', () => this.saveSettings());

        // Theme previews for public profile
        this.initThemePreviews();
    }

    initThemePreviews() {
        const container = document.getElementById('profile-theme-previews');
        if (!container || !window.themeService) return;

        const themes = window.themeService.getThemes();
        const currentTheme = container.dataset.currentTheme || '';
        this.selectedTheme = currentTheme;

        // Import theme images dynamically
        const themeImages = {};
        try {
            // Access the same images used by SettingsGeneralManager
            themeImages['day'] = new URL('../../../../images/citadel_quest_bg.jpg', import.meta.url).href;
            themeImages['night-1'] = new URL('../../../../images/night-forest-sky.webp', import.meta.url).href;
            themeImages['night-2'] = new URL('../../../../images/bg-dreamy-flowers-2.webp', import.meta.url).href;
        } catch (e) {
            // Fallback if import.meta.url doesn't work
        }

        // Add "User's theme" option (empty = use visitor's own theme)
        const defaultCard = this.createThemeCard('', 'Default', null, currentTheme === '');
        container.appendChild(defaultCard);

        themes.forEach(theme => {
            const card = this.createThemeCard(theme.id, theme.name, themeImages[theme.id] || null, theme.id === currentTheme);
            container.appendChild(card);
        });
    }

    createThemeCard(id, name, imageUrl, isActive) {
        const card = document.createElement('div');
        card.className = 'theme-preview-card position-relative d-inline-block rounded overflow-hidden cursor-pointer' + (isActive ? ' border border-primary shadow' : '');
        card.dataset.themeId = id;
        card.style.cssText = 'width:18%; min-width:80px; transition: border-color 0.2s, box-shadow 0.2s; border:1px solid transparent; cursor:pointer;';
        if (isActive) {
            card.style.borderColor = '';
        }

        const img = document.createElement('div');
        img.style.cssText = 'width:100%; height:50px; opacity:0.7; background-size:cover; background-position:center;';
        if (id === '') {
            img.style.backgroundColor = '#2e3135';
            img.innerHTML = '<div class="d-flex align-items-center justify-content-center h-100"><i class="mdi mdi-auto-fix text-muted"></i></div>';
        } else if (imageUrl) {
            img.style.backgroundImage = `url(${imageUrl})`;
        } else {
            img.style.backgroundColor = '#2e3135';
        }

        const label = document.createElement('div');
        label.classList.add('bg-secondary', 'bg-opacity-50', 'rounded-bottom', 'small', 'text-center', 'p-1');
        label.style.fontSize = '0.7rem';
        label.textContent = name;

        card.appendChild(img);
        card.appendChild(label);

        card.addEventListener('click', () => {
            this.selectedTheme = id;
            const container = document.getElementById('profile-theme-previews');
            container.querySelectorAll('.theme-preview-card').forEach(c => {
                c.classList.remove('border', 'border-primary', 'shadow');
                c.style.borderColor = 'transparent';
            });
            card.classList.add('border', 'border-primary', 'shadow');
        });

        return card;
    }

    async handlePhotoUpload(e) {
        const file = e.target.files?.[0];
        if (!file) return;

        const formData = new FormData();
        formData.append('photo', file);

        try {
            this.photoUploadBtn.disabled = true;
            const response = await fetch(this.photoUploadUrl, {
                method: 'POST',
                body: formData,
            });

            const data = await response.json();
            if (data.success) {
                // Update preview with new photo
                const reader = new FileReader();
                reader.onload = (ev) => {
                    this.photoPreview.innerHTML = `
                        <img src="${ev.target.result}" 
                             alt="Profile" 
                             class="rounded-circle border border-2 border-success"
                             style="width: 96px; height: 96px; object-fit: cover;">
                    `;
                };
                reader.readAsDataURL(file);

                this.photoRemoveBtn?.classList.remove('d-none');
                window.toast?.success(this.translations.photo_uploaded);
            } else {
                window.toast?.error(data.message || this.translations.photo_upload_error);
            }
        } catch (err) {
            window.toast?.error(this.translations.photo_upload_error);
        } finally {
            this.photoUploadBtn.disabled = false;
            this.photoInput.value = '';
        }
    }

    async handlePhotoRemove() {
        try {
            this.photoRemoveBtn.disabled = true;
            const response = await fetch(this.photoDeleteUrl, {
                method: 'DELETE',
            });

            const data = await response.json();
            if (data.success) {
                this.photoPreview.innerHTML = `
                    <div class="rounded-circle border border-2 border-secondary d-flex align-items-center justify-content-center"
                         style="width: 96px; height: 96px; background: rgba(255,255,255,0.05);">
                        <i class="mdi mdi-account text-cyber opacity-75" style="font-size: 48px;"></i>
                    </div>
                `;
                this.photoRemoveBtn?.classList.add('d-none');
                window.toast?.success(this.translations.photo_removed);
            } else {
                window.toast?.error(data.message || this.translations.photo_remove_error);
            }
        } catch (err) {
            window.toast?.error(this.translations.photo_remove_error);
        } finally {
            this.photoRemoveBtn.disabled = false;
        }
    }

    copyPublicUrl() {
        const urlInput = document.getElementById('profile-public-url');
        if (urlInput) {
            navigator.clipboard.writeText(urlInput.value).then(() => {
                const icon = this.copyUrlBtn.querySelector('i');
                icon?.classList.replace('mdi-content-copy', 'mdi-check');
                setTimeout(() => icon?.classList.replace('mdi-check', 'mdi-content-copy'), 2000);
            });
        }
    }

    async saveSettings() {
        const spinner = this.saveBtn.querySelector('.spinner-border');
        spinner?.classList.remove('d-none');
        this.saveBtn.disabled = true;

        try {
            const payload = {
                bio: this.bioTextarea?.value || '',
                public_page_enabled: this.publicEnabledCheckbox?.checked ? '1' : '0',
                public_page_show_photo: this.publicShowPhotoCheckbox?.checked ? '1' : '0',
                public_page_show_shares: this.publicShowSharesCheckbox?.checked ? '1' : '0',
                public_page_show_share_content: this.publicShowShareContentCheckbox?.checked ? '1' : '0',
                public_page_show_spirits: this.publicShowSpiritsSelect?.value ?? '1',
                public_page_theme: this.selectedTheme ?? '',
                federation_show_bio: this.federationBioCheckbox?.checked ? '1' : '0',
                federation_show_photo: this.federationPhotoCheckbox?.checked ? '1' : '0',
                federation_show_spirits: this.federationSpiritsSelect?.value ?? '1',
            };

            const response = await fetch(this.saveUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload),
            });

            const data = await response.json();
            if (data.success) {
                window.toast?.success(this.translations.saved);
            } else {
                window.toast?.error(data.message || this.translations.save_error);
            }
        } catch (err) {
            window.toast?.error(this.translations.save_error);
        } finally {
            spinner?.classList.add('d-none');
            this.saveBtn.disabled = false;
        }
    }
}
