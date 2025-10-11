/**
 * Dashboard Badge Manager
 * Handles loading and displaying notification badges on dashboard tiles
 */

class DashboardBadgeManager {
    constructor() {
        this.friendRequestBadge = document.getElementById('friend-request-badge');
        this.init();
    }

    init() {
        if (!this.friendRequestBadge) return;
        
        // Load badges on page load
        this.loadBadges();
        
        // Refresh every 30 seconds
        setInterval(() => this.loadBadges(), 30000);
    }

    async loadBadges() {
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
