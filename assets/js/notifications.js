// Notification handling
document.addEventListener('DOMContentLoaded', () => {
    const notificationContainer = document.getElementById('notifications-container');
    if (!notificationContainer) return;

    // Toggle notification dropdown
    const toggleDropdown = () => {
        const dropdown = notificationContainer.querySelector('.notification-dropdown');
        dropdown.classList.toggle('d-none');
    };

    // Click handler for notification bell
    notificationContainer.querySelector('.mdi-bell').addEventListener('click', (e) => {
        e.preventDefault();
        toggleDropdown();
    });

    // Close dropdown when clicking outside
    document.addEventListener('click', (e) => {
        if (!notificationContainer.contains(e.target)) {
            const dropdown = notificationContainer.querySelector('.notification-dropdown');
            dropdown.classList.add('d-none');
        }
    });

    // Mark notification as read
    notificationContainer.addEventListener('click', (e) => {
        const notificationItem = e.target.closest('.notification-item');
        if (!notificationItem) return;

        const notificationId = notificationItem.dataset.notificationId;
        if (!notificationId) return;

        fetch(`/notifications/${notificationId}/mark-read`, {
            method: 'POST',
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            }
        }).then(response => {
            if (response.ok) {
                notificationItem.classList.remove('unread');
                updateUnreadCount();
            }
        });
    });

    // Create notification item HTML
    const createNotificationItem = (notification) => {
        const typeIcons = {
            success: 'mdi-check-circle text-success',
            warning: 'mdi-alert text-warning',
            error: 'mdi-alert-circle text-danger',
            info: 'mdi-information text-info'
        };

        const iconClass = typeIcons[notification.type] || typeIcons.info;
        
        return `
            <div class="notification-item p-2 border-bottom unread" data-notification-id="${notification.id}">
                <div class="d-flex align-items-center">
                    <div class="notification-icon me-2">
                        <i class="mdi ${iconClass}"></i>
                    </div>
                    <div class="notification-content flex-grow-1">
                        <h6 class="notification-title mb-1">${notification.title}</h6>
                        <p class="notification-message mb-1">${notification.message}</p>
                        <small class="notification-time text-muted">${notification.createdAt}</small>
                    </div>
                </div>
            </div>
        `;
    };

    // Add new notification to list
    const addNotification = (notification) => {
        const list = notificationContainer.querySelector('.notification-list');
        const emptyMessage = list.querySelector('.text-center');
        if (emptyMessage) {
            emptyMessage.remove();
        }
        list.insertAdjacentHTML('afterbegin', createNotificationItem(notification));
        updateUnreadCount();
    };

    // Update unread count badge
    const updateUnreadCount = () => {
        const unreadItems = notificationContainer.querySelectorAll('.notification-item.unread');
        const badge = notificationContainer.querySelector('.badge');
        
        if (unreadItems.length > 0) {
            if (badge) {
                badge.textContent = unreadItems.length;
            } else {
                const newBadge = document.createElement('span');
                newBadge.className = 'position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger';
                newBadge.textContent = unreadItems.length;
                notificationContainer.querySelector('.position-relative').appendChild(newBadge);
            }
        } else if (badge) {
            badge.remove();
        }
    };

    // Test notification button (for development)
    const testButton = document.createElement('button');
    testButton.className = 'btn btn-sm btn-outline-primary mt-2';
    testButton.textContent = 'Test Notification';
    testButton.addEventListener('click', () => {
        fetch('/notifications/test')
            .then(response => response.json())
            .then(data => {
                if (data.status === 'success') {
                    addNotification(data.notification);
                }
            });
    });
    notificationContainer.appendChild(testButton);

    // Listen for SSE events
    document.addEventListener('htmx:sseMessage', (event) => {
        if (event.detail.type === 'notification') {
            addNotification(event.detail.data);
        }
    });
});
