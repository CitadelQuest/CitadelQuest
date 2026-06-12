/**
 * CitadelQuest Push Notification Service
 * Uses the Notifications API to show system notifications for new CQ Chat messages
 * when the user is not actively viewing the chat.
 */

export class PushNotificationService {
    constructor() {
        this._lastNotifiedMessageIds = new Set(); // Prevent duplicate notifications
        this._maxStoredIds = 100; // Keep the set from growing indefinitely
        this._onNotificationClick = null; // Callback when notification is clicked
        this._registration = null; // Service worker registration
        this._registerServiceWorker();
        this._listenForSwMessages();
    }

    /**
     * Register the service worker (required to show notifications on Android)
     */
    async _registerServiceWorker() {
        if (!('serviceWorker' in navigator)) return;
        try {
            this._registration = await navigator.serviceWorker.register('/sw.js');
        } catch (error) {
            console.warn('Service worker registration failed:', error);
        }
    }

    /**
     * Listen for notification-click messages forwarded from the service worker
     */
    _listenForSwMessages() {
        if (!('serviceWorker' in navigator)) return;
        navigator.serviceWorker.addEventListener('message', (event) => {
            if (event.data?.type === 'cq-chat-notification-click' && this._onNotificationClick) {
                this._onNotificationClick({ chatId: event.data.chatId });
            }
        });
    }

    /**
     * Show a notification via the service worker (Android-compatible),
     * falling back to the Notification constructor on desktop browsers.
     */
    async _showNotification(title, options) {
        // Prefer service worker registration (required on Android)
        let reg = this._registration;
        if (!reg && 'serviceWorker' in navigator) {
            try {
                reg = await navigator.serviceWorker.ready;
            } catch (e) { /* ignore */ }
        }

        if (reg && typeof reg.showNotification === 'function') {
            try {
                await reg.showNotification(title, options);
                return;
            } catch (e) {
                console.warn('showNotification failed, falling back:', e);
            }
        }

        // Desktop fallback: Notification constructor
        try {
            const notification = new Notification(title, options);
            notification.onclick = () => {
                notification.close();
                window.focus();
                if (this._onNotificationClick) {
                    this._onNotificationClick(options.data || {});
                }
            };
            setTimeout(() => notification.close(), 8000);
        } catch (e) {
            console.warn('Notification not supported:', e);
        }
    }

    /**
     * Request notification permission from the user
     * @returns {Promise<string>} - The resulting permission state
     */
    async requestPermission() {
        if (!('Notification' in window)) {
            console.warn('Notifications API not supported');
            return 'unsupported';
        }

        try {
            return await Notification.requestPermission();
        } catch (error) {
            console.error('Error requesting notification permission:', error);
            return 'denied';
        }
    }

    /**
     * Check if we can show notifications (reads live permission state)
     */
    canNotify() {
        return 'Notification' in window && Notification.permission === 'granted';
    }

    /**
     * Set callback for notification click
     * @param {Function} callback - Called with {chatId, contactName}
     */
    onNotificationClick(callback) {
        this._onNotificationClick = callback;
    }

    /**
     * Show a notification for a new CQ Chat message
     * @param {Object} message - The message object
     * @param {string} chatId - The chat ID
     * @param {string} contactName - Sender name for display
     * @param {string} contactDomain - Sender domain
     */
    notifyNewMessage(message, chatId, contactName, contactDomain) {
        if (!this.canNotify()) return;

        const messageId = message.id;
        if (this._lastNotifiedMessageIds.has(messageId)) return;

        // Track this message to avoid duplicates
        this._lastNotifiedMessageIds.add(messageId);
        if (this._lastNotifiedMessageIds.size > this._maxStoredIds) {
            const toDelete = [...this._lastNotifiedMessageIds].slice(0, 50);
            toDelete.forEach(id => this._lastNotifiedMessageIds.delete(id));
        }

        // Build notification content
        const title = contactDomain
            ? `${contactName}@${contactDomain}`
            : contactName || 'New message';

        const body = this._truncateMessage(message.content || '', 150);
        const icon = this._getIcon();

        this._showNotification(title, {
            body,
            icon,
            badge: this._getBadge(),
            tag: `cq-chat-${chatId}`, // Group notifications by chat
            renotify: true,
            timestamp: message.createdAt ? new Date(message.createdAt).getTime() : Date.now(),
            data: { chatId, contactName, contactDomain },
        });
    }

    /**
     * Show a generic notification (for other notification types)
     */
    notify(title, body, options = {}) {
        if (!this.canNotify()) return;

        this._showNotification(title, {
            body,
            icon: options.icon || this._getIcon(),
            badge: this._getBadge(),
            tag: options.tag || 'citadelquest',
            timestamp: Date.now(),
            data: options.data || {},
        });
    }

    /**
     * Get the notification icon URL (from Twig asset or fallback)
     */
    _getIcon() {
        return window.cqAssets?.favicon32 || '/build/images/favicon-32x32.png';
    }

    /**
     * Get the notification badge URL (from Twig asset or fallback)
     */
    _getBadge() {
        return window.cqAssets?.favicon16 || '/build/images/favicon-16x16.png';
    }

    // ==================== Badging API ====================

    /**
     * Set the app icon badge count (PWA only)
     * @param {number} count - Unread message count (0 clears the badge)
     */
    setBadge(count) {
        if (!('setAppBadge' in navigator)) return;

        if (count > 0) {
            navigator.setAppBadge(count).catch(() => {});
        } else {
            navigator.clearAppBadge().catch(() => {});
        }
    }

    /**
     * Clear the app icon badge
     */
    clearBadge() {
        if (!('clearAppBadge' in navigator)) return;
        navigator.clearAppBadge().catch(() => {});
    }

    /**
     * Truncate message body for notification display
     */
    _truncateMessage(content, maxLength) {
        if (!content) return '';
        // Strip markdown/image syntax for clean notification
        let clean = content
            .replace(/!\[.*?\]\(.*?\)/g, '[image]')
            .replace(/\[([^\]]*)\]\(.*?\)/g, '$1')
            .replace(/[*_~`#>]/g, '')
            .trim();

        if (clean.length > maxLength) {
            clean = clean.substring(0, maxLength - 3) + '...';
        }
        return clean || '[message]';
    }
}

// Singleton
export const pushNotificationService = new PushNotificationService();
