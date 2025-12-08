import { Modal } from 'bootstrap';

/**
 * Admin Migrations Manager
 * 
 * Handles admin operations for incoming migration requests.
 */

class AdminMigrationsManager {
    constructor() {
        this.translations = window.migrationTranslations || {};
        this.rejectModal = null;
        this.transferModal = null;
        this.currentRequestId = null;
        
        this.init();
    }

    init() {
        // Initialize Bootstrap modals
        const rejectModalEl = document.getElementById('rejectModal');
        const transferModalEl = document.getElementById('transferModal');
        
        if (rejectModalEl) {
            this.rejectModal = new Modal(rejectModalEl);
        }
        if (transferModalEl) {
            this.transferModal = new Modal(transferModalEl);
        }

        // Bind event handlers
        this.bindEvents();
    }

    bindEvents() {
        // Accept buttons
        document.querySelectorAll('.btn-accept-migration').forEach(btn => {
            btn.addEventListener('click', (e) => this.handleAccept(e));
        });

        // Reject buttons
        document.querySelectorAll('.btn-reject-migration').forEach(btn => {
            btn.addEventListener('click', (e) => this.handleRejectClick(e));
        });

        // Confirm reject button in modal
        const confirmRejectBtn = document.getElementById('confirmReject');
        if (confirmRejectBtn) {
            confirmRejectBtn.addEventListener('click', () => this.handleRejectConfirm());
        }
    }

    async handleAccept(e) {
        const requestId = e.currentTarget.dataset.requestId;
        
        if (!confirm(this.translations.accept_confirm || 'Accept this migration request?')) {
            return;
        }

        try {
            // First, accept the migration
            const acceptResponse = await fetch(`/admin/migrations/api/${requestId}/accept`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                }
            });

            const acceptData = await acceptResponse.json();

            if (!acceptData.success) {
                throw new Error(acceptData.error || 'Failed to accept migration');
            }

            window.toast?.success(this.translations.accept_success || 'Migration accepted');

            // Now start the transfer
            this.showTransferModal();
            
            const transferResponse = await fetch(`/admin/migrations/api/${requestId}/transfer`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                }
            });

            const transferData = await transferResponse.json();

            this.hideTransferModal();

            if (transferData.success) {
                window.toast?.success(this.translations.transfer_success || 'Migration completed successfully');
                // Reload page to show updated status
                setTimeout(() => window.location.reload(), 1500);
            } else {
                throw new Error(transferData.error || 'Transfer failed');
            }

        } catch (error) {
            this.hideTransferModal();
            console.error('Migration accept error:', error);
            window.toast?.error(error.message || this.translations.error || 'An error occurred');
        }
    }

    handleRejectClick(e) {
        this.currentRequestId = e.currentTarget.dataset.requestId;
        document.getElementById('rejectReason').value = '';
        this.rejectModal?.show();
    }

    async handleRejectConfirm() {
        if (!this.currentRequestId) return;

        const reason = document.getElementById('rejectReason').value.trim();

        try {
            const response = await fetch(`/admin/migrations/api/${this.currentRequestId}/reject`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({ reason })
            });

            const data = await response.json();

            if (data.success) {
                this.rejectModal?.hide();
                window.toast?.success(this.translations.reject_success || 'Migration rejected');
                
                // Remove the row from the table
                const row = document.querySelector(`tr[data-request-id="${this.currentRequestId}"]`);
                if (row) {
                    row.remove();
                }
                
                // Reload to update history
                setTimeout(() => window.location.reload(), 1500);
            } else {
                throw new Error(data.error || 'Failed to reject migration');
            }

        } catch (error) {
            console.error('Migration reject error:', error);
            window.toast?.error(error.message || this.translations.error || 'An error occurred');
        }

        this.currentRequestId = null;
    }

    showTransferModal() {
        this.transferModal?.show();
    }

    hideTransferModal() {
        this.transferModal?.hide();
    }
}

// Initialize when DOM is ready
document.addEventListener('DOMContentLoaded', () => {
    new AdminMigrationsManager();
});

export { AdminMigrationsManager };
