/**
 * CitadelQuest Push Notification Service
 * Uses the Notifications API to show system notifications for new CQ Chat messages
 * when the user is not actively viewing the chat.
 */

export class PushNotificationService {
    constructor() {
        this.permission = 'default'; // 'default', 'granted', 'denied'
        this._lastNotifiedMessageIds = new Set(); // Prevent duplicate notifications
        this._maxStoredIds = 100; // Keep the set from growing indefinitely
        this._onNotificationClick = null; // Callback when notification is clicked
        this._initPermission();
    }

    _initPermission() {
        if ('Notification' in window) {
            this.permission = Notification.permission;
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
            const result = await Notification.requestPermission();
            this.permission = result;
            return result;
        } catch (error) {
            console.error('Error requesting notification permission:', error);
            return 'denied';
        }
    }

    /**
     * Check if we can show notifications
     */
    canNotify() {
        return 'Notification' in window && this.permission === 'granted';
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

        const notification = new Notification(title, {
            body,
            icon,
            badge: this._getBadge(),
            tag: `cq-chat-${chatId}`, // Group notifications by chat
            renotify: true,
            timestamp: message.createdAt ? new Date(message.createdAt).getTime() : Date.now(),
        });

        notification.onclick = () => {
            notification.close();
            // Focus the window
            window.focus();
            // Call the click handler
            if (this._onNotificationClick) {
                this._onNotificationClick({ chatId, contactName, contactDomain });
            }
        };

        // Auto-close after 8 seconds
        setTimeout(() => notification.close(), 8000);
    }

    /**
     * Show a generic notification (for other notification types)
     */
    notify(title, body, options = {}) {
        if (!this.canNotify()) return;

        const notification = new Notification(title, {
            body,
            icon: options.icon || this._getIcon(),
            badge: this._getBadge(),
            tag: options.tag || 'citadelquest',
            timestamp: Date.now(),
        });

        notification.onclick = () => {
            notification.close();
            window.focus();
            if (options.onClick) options.onClick();
        };

        setTimeout(() => notification.close(), 8000);
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
