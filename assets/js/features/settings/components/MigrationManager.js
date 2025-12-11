/**
 * MigrationManager
 * 
 * Handles user-facing migration operations from the Settings page.
 */
export class MigrationManager {
    constructor() {
        this.loadingEl = document.getElementById('migration-loading');
        this.migratedEl = document.getElementById('migration-migrated');
        this.pendingEl = document.getElementById('migration-pending');
        this.formEl = document.getElementById('migration-form');
        
        this.contactSelect = document.getElementById('migration-contact');
        this.backupSelect = document.getElementById('migration-backup');
        this.passwordInput = document.getElementById('migration-password');
        this.initiateBtn = document.getElementById('initiate-migration-btn');
        this.cancelBtn = document.getElementById('cancel-migration-btn');
        
        if (this.loadingEl) {
            this.init();
        }
    }

    async init() {
        this.bindEvents();
        await this.loadMigrationStatus();
    }

    bindEvents() {
        if (this.contactSelect) {
            this.contactSelect.addEventListener('change', () => this.validateForm());
        }
        if (this.passwordInput) {
            this.passwordInput.addEventListener('input', () => this.validateForm());
        }
        if (this.initiateBtn) {
            this.initiateBtn.addEventListener('click', () => this.handleInitiate());
        }
        if (this.cancelBtn) {
            this.cancelBtn.addEventListener('click', () => this.handleCancel());
        }
    }

    async loadMigrationStatus() {
        try {
            const response = await fetch('/api/migration/status');
            const data = await response.json();

            this.hideAll();

            if (data.status === 'migrated') {
                // Account already migrated
                document.getElementById('migrated-to-domain').textContent = data.migrated_to;
                document.getElementById('migrated-at-date').textContent = 
                    new Date(data.migrated_at).toLocaleDateString();
                this.migratedEl.classList.remove('d-none');
                
            } else if (data.status && ['pending', 'accepted', 'transferring'].includes(data.status)) {
                // Has pending migration
                const statusText = document.getElementById('migration-status-text');
                const targetDomain = document.getElementById('pending-target-domain');
                
                if (data.migration) {
                    targetDomain.textContent = data.migration.target_domain;
                    
                    if (data.status === 'accepted') {
                        statusText.textContent = 'Migration accepted! Transfer in progress...';
                    } else if (data.status === 'transferring') {
                        statusText.textContent = 'Transferring data...';
                    }
                }
                this.pendingEl.classList.remove('d-none');
                
            } else {
                // Show migration form
                this.populateContacts(data.available_contacts || []);
                this.populateBackups(data.available_backups || []);
                this.formEl.classList.remove('d-none');
            }

        } catch (error) {
            console.error('Failed to load migration status:', error);
            this.hideAll();
            this.formEl.classList.remove('d-none');
        }
    }

    hideAll() {
        this.loadingEl?.classList.add('d-none');
        this.migratedEl?.classList.add('d-none');
        this.pendingEl?.classList.add('d-none');
        this.formEl?.classList.add('d-none');
    }

    populateContacts(contacts) {
        if (!this.contactSelect) return;

        // Clear existing options except the first one
        while (this.contactSelect.options.length > 1) {
            this.contactSelect.remove(1);
        }

        const noContactsMsg = document.getElementById('no-contacts-message');

        if (contacts.length === 0) {
            if (noContactsMsg) noContactsMsg.style.display = 'block';
            this.contactSelect.disabled = true;
            return;
        }

        if (noContactsMsg) noContactsMsg.style.display = 'none';
        this.contactSelect.disabled = false;

        contacts.forEach(contact => {
            const option = document.createElement('option');
            option.value = contact.id;
            option.textContent = `${contact.username} @ ${contact.domain}`;
            this.contactSelect.appendChild(option);
        });
    }

    populateBackups(backups) {
        if (!this.backupSelect) return;

        // Clear existing options except the first one (create new backup)
        while (this.backupSelect.options.length > 1) {
            this.backupSelect.remove(1);
        }

        if (backups.length === 0) {
            return;
        }

        backups.forEach(backup => {
            const option = document.createElement('option');
            option.value = backup.filename;
            option.textContent = `${backup.filename} (${backup.size_formatted}) - ${backup.date}`;
            this.backupSelect.appendChild(option);
        });
    }

    validateForm() {
        const contactSelected = this.contactSelect?.value;
        const passwordEntered = this.passwordInput?.value?.length >= 1;
        
        if (this.initiateBtn) {
            this.initiateBtn.disabled = !(contactSelected && passwordEntered);
        }
    }

    async handleInitiate() {
        const contactId = this.contactSelect?.value;
        const backupFilename = this.backupSelect?.value || null;
        const password = this.passwordInput?.value;

        if (!contactId || !password) return;

        const spinner = this.initiateBtn.querySelector('.spinner-border');
        
        try {
            this.initiateBtn.disabled = true;
            spinner?.classList.remove('d-none');

            const response = await fetch('/api/migration/initiate', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    contact_id: contactId,
                    password: password,
                    backup_filename: backupFilename,
                }),
            });

            const data = await response.json();

            if (data.success) {
                window.toast?.success(data.message || 'Migration request sent successfully');
                // Reload to show pending state
                await this.loadMigrationStatus();
            } else {
                window.toast?.error(data.error || 'Failed to initiate migration');
            }

        } catch (error) {
            console.error('Migration initiate error:', error);
            window.toast?.error('An error occurred while initiating migration');
        } finally {
            this.initiateBtn.disabled = false;
            spinner?.classList.add('d-none');
            this.passwordInput.value = '';
        }
    }

    async handleCancel() {
        if (!confirm('Are you sure you want to cancel the migration request?')) {
            return;
        }

        try {
            const response = await fetch('/api/migration/cancel', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
            });

            const data = await response.json();

            if (data.success) {
                window.toast?.success(data.message || 'Migration cancelled');
                await this.loadMigrationStatus();
            } else {
                window.toast?.error(data.error || 'Failed to cancel migration');
            }

        } catch (error) {
            console.error('Migration cancel error:', error);
            window.toast?.error('An error occurred while cancelling migration');
        }
    }
}
