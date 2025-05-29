/**
 * AdminDashboardManager.js
 * Manages the admin dashboard functionality
 */

export class AdminDashboardManager {
    constructor() {
        // DOM elements
        this.iframe = document.getElementById('updateModalIframe');
        this.updateModal = document.getElementById('updateModal');
        this.updateModalCheckUpdates = document.getElementById('updateModalCheckUpdates');
        this.updateModalClose = document.getElementById('updateModalClose');
    }

    init() {
        this.initRefreshStats();
        this.initUpdateModal();
    }

    /**
     * Initialize the stats refresh functionality
     */
    initRefreshStats() {
        const refreshStatsButton = document.querySelector('button[onclick="refreshStats()"]');
        if (!refreshStatsButton) return;

        // Replace the inline onclick handler with a proper event listener
        refreshStatsButton.removeAttribute('onclick');
        refreshStatsButton.addEventListener('click', this.refreshStats.bind(this));
    }

    /**
     * Refresh the dashboard statistics
     */
    async refreshStats() {
        try {
            const response = await fetch('/admin/stats', {
                method: 'GET',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                }
            });

            const data = await response.json();
            
            if (response.ok) {
                // Update the stats on the page
                const totalUsers = document.getElementById('totalUsers');
                const adminUsers = document.getElementById('adminUsers');
                const regularUsers = document.getElementById('regularUsers');
                
                if (totalUsers) totalUsers.textContent = data.totalUsers;
                if (adminUsers) adminUsers.textContent = data.adminUsers;
                if (regularUsers) regularUsers.textContent = data.regularUsers;
                
                window.toast.success(document.querySelector('[data-stats-refreshed]').dataset.statsRefreshed);
            } else {
                window.toast.error(document.querySelector('[data-stats-error]').dataset.statsError);
            }
        } catch (error) {
            console.error('Error refreshing stats:', error);
            window.toast.error(document.querySelector('[data-stats-error]').dataset.statsError);
        }
    }

    /**
     * Initialize the update modal functionality
     */
    initUpdateModal() {
        console.log('initUpdateModal');
        if (!this.updateModal) return;

        this.updateModal.addEventListener('shown.bs.modal', () => {
            console.log('Update modal shown');
            this.iframe.classList.add('d-none');
            this.iframe.style.height = '0px';
            this.iframe.src = "";
            this.updateModalCheckUpdates.disabled = false;
            this.updateModalClose.disabled = false;
            this.updateModalCheckUpdates.innerHTML = '<i class="mdi mdi-update me-2"></i>' + this.updateModalCheckUpdates.getAttribute('data-original-text');
        });

        this.updateModalCheckUpdates.addEventListener('click', () => {
            console.log('Update modal check updates clicked');
            console.log('this.iframe.src', this.iframe.src, typeof this.iframe.src);
            if (this.iframe) {
                console.log('Update modal check updates clicked 2');
                this.updateModalCheckUpdates.disabled = true;
                this.updateModalClose.disabled = true;
                this.updateModalCheckUpdates.innerHTML = '<i class="mdi mdi-loading mdi-spin me-2"></i>Please wait... don\'t touch anything!';
                
                this.iframe.classList.remove('d-none');
                this.iframe.setAttribute('height', '460px');
                
                fetch('/admin/update/check', {
                    method: 'GET',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                })
                .then(response => {
                    // Store response.ok before parsing JSON
                    const isResponseOk = response.ok;
                    return response.json().then(data => ({ data, isResponseOk }));
                })
                .then(({ data, isResponseOk }) => {
                    if (isResponseOk && data.success) {
                        this.iframe.src = data.redirect_url;
                        // set interval to scroll to bottom of iframe, to see updates
                        const interval = setInterval(() => {
                            if (this.iframe.contentWindow) {
                                this.iframe.contentWindow.scrollTo(0, this.iframe.contentWindow.document.body.scrollHeight);
                            }
                        }, 1000);
                        
                        this.iframe.addEventListener('load', () => {
                            clearInterval(interval);
                            this.updateModalCheckUpdates.innerHTML = '<i class="mdi mdi-update me-2"></i> Refresh';
                            this.updateModalCheckUpdates.disabled = false;
                            this.updateModalClose.disabled = false;
                        });

                    } else {
                        window.toast.error(data.message || 'Update check failed');
                    }
                })
                .catch(error => {
                    console.error('Error checking for updates:', error);
                    window.toast.error('Failed to check for updates');
                });
            }
        });
    }
}
