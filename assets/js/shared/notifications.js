/**
 * CitadelQuest Notification System
 * Handles real-time notifications with SSE, filtering, and timestamp updates
 */

// Utility Functions
function getRelativeTime(date) {
    const now = new Date();
    const diff = Math.floor((now - date) / 1000);

    if (diff < 60) return 'just now';
    if (diff < 3600) return `${Math.floor(diff / 60)} minutes ago`;
    if (diff < 86400) return `${Math.floor(diff / 3600)} hours ago`;
    if (diff < 2592000) return `${Math.floor(diff / 86400)} days ago`;
    return date.toLocaleDateString();
}

function updateTimestamps() {
    document.querySelectorAll('.notification-time').forEach(timeElement => {
        const timestamp = timeElement.getAttribute('data-timestamp');
        if (timestamp) {
            timeElement.textContent = getRelativeTime(new Date(timestamp));
        }
    });
}

// Main initialization
document.addEventListener('DOMContentLoaded', () => {
    const notificationDropdown = document.getElementById('notificationsDropdown');
    if (!notificationDropdown) return;
    
    const notificationContainer = notificationDropdown.nextElementSibling;

    // Core notification functions
    const updateNotificationList = (html) => {
        const tempDiv = document.createElement('div');
        tempDiv.innerHTML = html;
        const newItems = tempDiv.querySelector('.notification-items');
        const currentItems = notificationContainer.querySelector('.notification-items');
        if (newItems && currentItems) {
            currentItems.innerHTML = newItems.innerHTML;
            updateTimestamps();
            setTimeout(updateUnreadCount, 0);
        }
    };

    const fetchAndUpdateNotifications = () => {
        return fetch('/notifications')
            .then(response => response.text())
            .then(updateNotificationList);
    };

    const updateUnreadCount = (() => {
        return () => {
            console.log('Updating unread count...');
            const unreadItems = notificationContainer.querySelectorAll('.dropdown-item.unread');
            const badge = document.getElementById('notificationsCountBadge');
            const before = badge?.textContent;
            
            if (badge) {
                if (unreadItems.length > 0) {
                    badge.textContent = unreadItems.length;
                    badge.style.display = '';
                } else {
                    badge.style.display = 'none';
                }
                console.log(`Unread count changed: ${before} -> ${badge.textContent} (${unreadItems.length} unread items)`);
            }
        };
    })();

    // Event Handlers
    notificationContainer.addEventListener('click', (e) => {
        e.preventDefault();
        e.stopPropagation();
        
        const notificationItem = e.target.closest('.dropdown-item');
        if (!notificationItem?.dataset.notificationId) return;

        fetch(`/notifications/${notificationItem.dataset.notificationId}/mark-read`, {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        }).then(response => {
            if (response.ok) fetchAndUpdateNotifications();
        });
    });

    // Filter notifications
    document.querySelectorAll('.notification-filters [data-filter]').forEach(button => {
        button.addEventListener('click', (e) => {
            e.preventDefault();
            const filter = button.dataset.filter;
            
            // Update active state
            document.querySelectorAll('.notification-filters [data-filter]')
                .forEach(btn => btn.classList.remove('active'));
            button.classList.add('active');
            
            // Apply filter
            document.querySelectorAll('.dropdown-item').forEach(item => {
                item.style.display = (filter === 'all' || item.classList.contains(`type-${filter}`)) ? '' : 'none';
            });
        });
    });

    // Mark all as read
    document.getElementById('mark-all-read')?.addEventListener('click', (e) => {
        e.preventDefault();
        fetch('/notifications/mark-all-read', {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        }).then(response => {
            if (response.ok) fetchAndUpdateNotifications();
        });
    });

    // Test notification
    /* document.getElementById('test-notification')?.addEventListener('click', () => {
        fetch('/notifications/test', {
            method: 'GET',
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        });
    }); */

    // SSE Connection Management
    let eventSource = null;
    let retryCount = 0;
    const maxRetryCount = 5;
    const maxRetryDelay = 10000;

    function connectSSE() {
        console.log('Initializing SSE connection...');
        eventSource = new EventSource('/events', { withCredentials: true });

        eventSource.onopen = () => {
            console.log('SSE connection established');
            retryCount = 0;
        };

        eventSource.onerror = (error) => {
            console.error('SSE connection error:', error);
            
            if (eventSource.readyState === EventSource.CLOSED) {
                eventSource.close();
                
                if (retryCount < maxRetryCount) {
                    const delay = Math.min(1000 * Math.pow(2, retryCount), maxRetryDelay);
                    retryCount++;
                    console.log(`Reconnecting in ${delay}ms (attempt ${retryCount}/${maxRetryCount})...`);
                    setTimeout(connectSSE, delay);
                } else {
                    console.error('Max retry attempts reached. Please refresh the page.');
                }
            }
        };

        // Event Handlers
        /* eventSource.addEventListener('heartbeat', (event) => {
            console.debug('Heartbeat received:', JSON.parse(event.data));
        }); */

        eventSource.addEventListener('debug', (event) => {
            console.debug('Debug event:', JSON.parse(event.data));
        });

        eventSource.addEventListener('notification', (event) => {
            console.log('Received notification:', event.data);
            try {
                const data = JSON.parse(event.data);
                fetchAndUpdateNotifications();
            } catch (error) {
                console.error('Error processing notification:', error);
            }
        });
    }

    // Initialize
    updateTimestamps();
    setInterval(updateTimestamps, 60000);
    connectSSE();
});
