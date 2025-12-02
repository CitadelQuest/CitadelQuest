/**
 * AdminDashboardManager.js
 * Manages the admin dashboard functionality
 */

export class AdminDashboardManager {
    constructor() {
        // DOM elements
        this.iframe = document.getElementById('updateModalIframe');
        this.updateModal = document.getElementById('updateModal');
        //this.updateModalCheckUpdates = document.getElementById('updateModalCheckUpdates');
        this.updateModalClose = document.getElementById('updateModalClose');

        this.updateModalCheckUpdates_step_1 = document.getElementById('updateModalCheckUpdates_step_1');
        this.updateModalCheckUpdates_step_2 = document.getElementById('updateModalCheckUpdates_step_2');
        this.updateModalCheckUpdates_step_3 = document.getElementById('updateModalCheckUpdates_step_3');
        this.updateModalCheckUpdates_step_4 = document.getElementById('updateModalCheckUpdates_step_4');
        
        this.registrationToggle = document.getElementById('registrationToggle');
    }

    init() {
        this.initRefreshStats();
        this.initUpdateModal();
        this.initRegistrationToggle();
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
            const response = await fetch('/administration/stats', {
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
        if (!this.updateModal) return;

        this.updateModal.addEventListener('shown.bs.modal', () => {
            this.iframe.classList.add('d-none');
            this.iframe.setAttribute('height', '0px');
            this.iframe.src = "";

            //this.updateModalCheckUpdates.disabled = false;
            this.updateModalClose.disabled = false;
            //this.updateModalCheckUpdates.innerHTML = '<i class="mdi mdi-update me-2"></i>' + this.updateModalCheckUpdates.getAttribute('data-original-text');

            this.updateModalCheckUpdates_step_1.disabled = false;
            this.updateModalCheckUpdates_step_2.disabled = false;
            this.updateModalCheckUpdates_step_3.disabled = true;
        });

        // Update modal check updates steps
        // 1 - Create backup
        this.updateModalCheckUpdates_step_1.addEventListener('click', () => this.updateModalCheckUpdateStep(1)); 
        // 2 - Download update
        this.updateModalCheckUpdates_step_2.addEventListener('click', () => this.updateModalCheckUpdateStep(2)); 
        // 3 - Install update
        this.updateModalCheckUpdates_step_3.addEventListener('click', () => this.updateModalCheckUpdateStep(3)); 

    }

    updateModalCheckUpdateStep(step) {
        if (this.iframe) {
            this.updateModalClose.disabled = true;
            //this.updateModalCheckUpdates.disabled = true;
            //this.updateModalCheckUpdates.innerHTML = '<i class="mdi mdi-loading mdi-spin me-2"></i>Please wait... don\'t touch anything!';

            this.updateModalCheckUpdates_step_1.disabled = true;
            this.updateModalCheckUpdates_step_2.disabled = true;
            this.updateModalCheckUpdates_step_3.disabled = true;
            
            this.iframe.classList.remove('d-none');
            this.iframe.setAttribute('height', '460px');
            
            fetch('/administration/update/check/' + step, {
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
                    }, 500);
                    
                    this.iframe.addEventListener('load', () => {
                        clearInterval(interval);
                        this.updateModalClose.disabled = false;
                        //this.updateModalCheckUpdates.innerHTML = '<i class="mdi mdi-update me-2"></i> Refresh';
                        //this.updateModalCheckUpdates.style.display = 'none';
                        
                        switch (step) {
                            case 1:
                                this.updateModalCheckUpdates_step_1.disabled = false;
                                this.updateModalCheckUpdates_step_2.disabled = false;
                                this.updateModalCheckUpdates_step_3.disabled = true;
                                break;
                            case 2:
                                this.updateModalCheckUpdates_step_1.disabled = false;
                                this.updateModalCheckUpdates_step_2.disabled = false;
                                this.updateModalCheckUpdates_step_3.disabled = false;
                                break;
                            case 3:
                                this.updateModalCheckUpdates_step_1.disabled = true;
                                this.updateModalCheckUpdates_step_2.disabled = true;
                                this.updateModalCheckUpdates_step_3.disabled = true;

                                this.updateModalCheckUpdates_step_4.disabled = false;
                                this.updateModalCheckUpdates_step_4.classList.remove('d-none');
                                break;
                        }
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
    }

    /**
     * Initialize the registration toggle functionality
     */
    initRegistrationToggle() {
        if (!this.registrationToggle) return;

        this.registrationToggle.addEventListener('change', async (e) => {
            const toggle = e.target;
            const url = toggle.dataset.url;
            const originalState = toggle.checked;

            try {
                const response = await fetch(url, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                });

                const data = await response.json();

                if (response.ok && data.success) {
                    window.toast.success(data.message);
                    toggle.checked = data.enabled;
                } else {
                    window.toast.error(data.message || 'Failed to toggle registration');
                    toggle.checked = originalState;
                }
            } catch (error) {
                console.error('Error toggling registration:', error);
                window.toast.error('Failed to toggle registration');
                toggle.checked = originalState;
            }
        });
    }
}
