/**
 * AdminUsersManager.js
 * Manages the admin user management functionality
 */

import * as bootstrap from 'bootstrap';

export class AdminUsersManager {
    constructor() {
        this.initSearchFilter();
        this.initUserActions();
    }

    /**
     * Initialize the user search filter
     */
    initSearchFilter() {
        const searchInput = document.getElementById('userSearch');
        if (!searchInput) return;

        // Remove the inline event handler if it exists
        searchInput.removeAttribute('oninput');
        
        // Add proper event listener
        searchInput.addEventListener('input', this.filterUsers.bind(this));
    }

    /**
     * Filter users based on search input
     * @param {Event} event - The input event
     */
    filterUsers(event) {
        const searchTerm = event.target.value.toLowerCase();
        const rows = document.querySelectorAll('.user-row');
        
        rows.forEach(row => {
            const username = row.querySelector('.user-username').textContent.toLowerCase();
            const email = row.querySelector('.user-email').textContent.toLowerCase();
            
            if (username.includes(searchTerm) || email.includes(searchTerm)) {
                row.style.display = '';
            } else {
                row.style.display = 'none';
            }
        });
    }

    /**
     * Initialize user action buttons (info, toggle admin, delete)
     */
    initUserActions() {
        // Add event listeners to user info buttons
        document.querySelectorAll('.user-info-btn').forEach(btn => {
            const userId = btn.dataset.userId;
            if (userId) {
                btn.addEventListener('click', () => this.showUserInfo(userId));
            }
        });

        // Add event listeners to toggle admin buttons
        document.querySelectorAll('.toggle-admin-btn').forEach(btn => {
            const userId = btn.dataset.userId;
            if (userId) {
                btn.addEventListener('click', () => this.toggleAdmin(userId));
            }
        });

        // Add event listeners to delete user buttons
        document.querySelectorAll('.delete-user-btn').forEach(btn => {
            const userId = btn.dataset.userId;
            const username = btn.dataset.username;
            if (userId && username) {
                btn.addEventListener('click', () => this.deleteUser(userId, username));
            }
        });
    }

    /**
     * Show user information in a modal
     * @param {string} userId - The user ID
     */
    async showUserInfo(userId) {
        try {
            const response = await fetch(`/admin/user/${userId}/info`, {
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            });
            
            const data = await response.json();
            
            if (response.ok) {
                const translations = {
                    username: document.querySelector('[data-trans-username]')?.dataset.transUsername,
                    email: document.querySelector('[data-trans-email]')?.dataset.transEmail,
                    roles: document.querySelector('[data-trans-roles]')?.dataset.transRoles,
                    database: document.querySelector('[data-trans-database]')?.dataset.transDatabase,
                    userId: document.querySelector('[data-trans-user-id]')?.dataset.transUserId,
                    admin: document.querySelector('[data-trans-admin]')?.dataset.transAdmin,
                    user: document.querySelector('[data-trans-user]')?.dataset.transUser
                };
                
                const content = `
                    <div class="row">
                        <div class="col-sm-5"><strong>${translations.username || 'Username'}:</strong></div>
                        <div class="col-sm-7">${data.username}</div>
                    </div>
                    <div class="row mt-2">
                        <div class="col-sm-5"><strong>${translations.email || 'Email'}:</strong></div>
                        <div class="col-sm-7">${data.email}</div>
                    </div>
                    <div class="row mt-2">
                        <div class="col-sm-5"><strong>${translations.roles || 'Roles'}:</strong></div>
                        <div class="col-sm-7">
                            ${data.roles.map(role => {
                                if (role === 'ROLE_ADMIN') {
                                    return `<span class="badge bg-warning me-1">${translations.admin || 'Admin'}</span>`;
                                } else if (role === 'ROLE_USER') {
                                    return `<span class="badge bg-info me-1">${translations.user || 'User'}</span>`;
                                }
                                return `<span class="badge bg-secondary me-1">${role}</span>`;
                            }).join('')}
                        </div>
                    </div>
                    <div class="row mt-2">
                        <div class="col-sm-5"><strong>${translations.database || 'Database'}:</strong></div>
                        <div class="col-sm-7"><code class="text-cyber">${data.databasePath}</code></div>
                    </div>
                    <div class="row mt-2">
                        <div class="col-sm-5"><strong>${translations.userId || 'User ID'}:</strong></div>
                        <div class="col-sm-7"><code class="text-muted">${data.id}</code></div>
                    </div>
                `;
                
                document.getElementById('userInfoContent').innerHTML = content;
                const modalEl = document.getElementById('userInfoModal');
                const modal = bootstrap.Modal.getOrCreateInstance(modalEl);
                modal.show();
            } else {
                window.toast.error(document.querySelector('[data-error-loading-info]')?.dataset.errorLoadingInfo || 'Error loading user information');
            }
        } catch (error) {
            console.error('Error:', error);
            window.toast.error(document.querySelector('[data-error-loading-info]')?.dataset.errorLoadingInfo || 'Error loading user information');
        }
    }

    /**
     * Toggle admin role for a user
     * @param {string} userId - The user ID
     */
    async toggleAdmin(userId) {
        const confirmMessage = document.querySelector('[data-confirm-toggle-admin]')?.dataset.confirmToggleAdmin || 'Are you sure you want to toggle the admin role for this user?';
        if (!confirm(confirmMessage)) {
            return;
        }
        
        try {
            const response = await fetch(`/admin/user/${userId}/toggle-admin`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                }
            });
            
            const data = await response.json();
            
            if (data.success) {
                window.toast.success(data.message);
                // Update the button icon and roles display
                /* const button = document.getElementById(`admin-btn-${userId}`);
                const icon = button.querySelector('i');
                const rolesDiv = document.querySelector(`[data-user-id="${userId}"] .user-roles`);
                
                const adminRole = document.querySelector('[data-trans-admin]')?.dataset.transAdmin || 'Admin';
                const userRole = document.querySelector('[data-trans-user]')?.dataset.transUser || 'User'; */
                // Update user icon
                const userIcon = document.querySelector(`[data-user-id="${userId}"] .user-icon`);
                if (data.isAdmin) {
                    userIcon.className = 'mdi mdi-account-cowboy-hat text-warning user-icon me-2';
                } else {
                    userIcon.className = 'mdi mdi-account text-cyber user-icon me-2';
                }
                
                /* if (data.isAdmin) {
                    icon.className = 'mdi mdi-account-minus';
                    rolesDiv.innerHTML = `<span class="badge bg-warning me-1">${adminRole}</span><span class="badge bg-info me-1">${userRole}</span>`;
                } else {
                    icon.className = 'mdi mdi-account-plus';
                    rolesDiv.innerHTML = `<span class="badge bg-info me-1">${userRole}</span>`;
                } */
            } else {
                window.toast.error(data.message);
            }
        } catch (error) {
            console.error('Error:', error);
            window.toast.error(document.querySelector('[data-error-toggle-admin]')?.dataset.errorToggleAdmin || 'Error toggling admin role');
        }
    }

    /**
     * Delete a user
     * @param {string} userId - The user ID
     * @param {string} username - The username
     */
    async deleteUser(userId, username) {
        const confirmMessage = (document.querySelector('[data-confirm-delete]')?.dataset.confirmDelete || 'Are you sure you want to delete user %username%?').replace('%username%', username);
        if (!confirm(confirmMessage)) {
            return;
        }
        
        try {
            const response = await fetch(`/admin/user/${userId}/delete`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                }
            });
            
            const data = await response.json();
            
            if (data.success) {
                window.toast.success(data.message);
                // Remove the row from the table
                document.querySelector(`[data-user-id="${userId}"]`).remove();
                
                // Update user count in heading if it exists
                const userCountEl = document.querySelector('.card-header h5');
                if (userCountEl) {
                    const match = userCountEl.textContent.match(/(\d+)/);
                    if (match && match[1]) {
                        const count = parseInt(match[1]) - 1;
                        userCountEl.textContent = userCountEl.textContent.replace(`(${match[1]})`, `(${count})`);
                    }
                }
            } else {
                window.toast.error(data.message);
            }
        } catch (error) {
            console.error('Error:', error);
            window.toast.error(document.querySelector('[data-error-delete]')?.dataset.errorDelete || 'Error deleting user');
        }
    }
}
