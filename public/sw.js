/**
 * CitadelQuest Service Worker
 *
 * Minimal SW dedicated to displaying notifications. Required on Android,
 * where the `new Notification()` constructor is forbidden and notifications
 * must be shown via ServiceWorkerRegistration.showNotification().
 *
 * Note: intentionally NO fetch handler / offline caching here, to avoid
 * serving stale assets. This SW only handles notification display + clicks.
 */

self.addEventListener('install', () => {
    // Activate the new service worker as soon as it's installed
    self.skipWaiting();
});

self.addEventListener('activate', (event) => {
    // Take control of all open clients immediately
    event.waitUntil(self.clients.claim());
});

/**
 * Handle notification clicks: focus an existing window (and tell it which
 * chat to open) or open a new one.
 */
self.addEventListener('notificationclick', (event) => {
    event.notification.close();

    const data = event.notification.data || {};
    const chatId = data.chatId || null;

    event.waitUntil((async () => {
        const allClients = await self.clients.matchAll({
            type: 'window',
            includeUncontrolled: true
        });

        // Focus an already-open CitadelQuest window
        for (const client of allClients) {
            if ('focus' in client) {
                await client.focus();
                client.postMessage({ type: 'cq-chat-notification-click', chatId });
                return;
            }
        }

        // No open window — open one
        if (self.clients.openWindow) {
            const url = chatId ? `/?openChat=${encodeURIComponent(chatId)}` : '/';
            await self.clients.openWindow(url);
        }
    })());
});
