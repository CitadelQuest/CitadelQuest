/**
 * Dashboard Badge Manager
 * Handles loading and displaying notification badges on dashboard tiles
 */

class DashboardBadgeManager {
    constructor() {
        this.friendRequestBadge = document.getElementById('friend-request-badge');
        this.updateAvailableBadge = document.getElementById('update-available-badge');
        this.init();
    }

    init() {
        // Load contact badges
        if (this.friendRequestBadge) {
            this.loadContactBadges();
            setInterval(() => this.loadContactBadges(), 30000);
        }

        // Load update badge (admin only — element only exists for admins)
        if (this.updateAvailableBadge) {
            this.checkUpdateAvailable();
        }
    }

    async loadContactBadges() {
        try {
            const response = await fetch('/api/cq-contact/badges');
            if (!response.ok) {
                throw new Error('Failed to load badges');
            }

            const data = await response.json();
            this.updateFriendRequestBadge(data.pendingFriendRequests || 0);
        } catch (error) {
            console.error('Error loading badges:', error);
        }
    }

    async checkUpdateAvailable() {
        try {
            const response = await fetch('/administration/update-available');
            if (!response.ok) return;

            const data = await response.json();
            if (data.updateAvailable) {
                this.updateAvailableBadge.classList.remove('d-none');
                this.updateAvailableBadge.title = data.latestVersion || 'Update available';
            } else {
                this.updateAvailableBadge.classList.add('d-none');
            }
        } catch (error) {
            // Silently fail — update check is non-critical
        }
    }

    updateFriendRequestBadge(count) {
        if (!this.friendRequestBadge) return;

        if (count > 0) {
            this.friendRequestBadge.textContent = count;
            this.friendRequestBadge.classList.remove('d-none');
        } else {
            this.friendRequestBadge.classList.add('d-none');
        }
    }
}

// Initialize on DOM ready
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => {
        new DashboardBadgeManager();
    });
} else {
    new DashboardBadgeManager();
}
