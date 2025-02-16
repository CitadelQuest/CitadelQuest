// Notification handling
// Function to format relative time
function getRelativeTime(date) {
    const now = new Date();
    const diff = Math.floor((now - date) / 1000); // diff in seconds

    if (diff < 60) return 'just now';
    if (diff < 3600) return `${Math.floor(diff / 60)} minutes ago`;
    if (diff < 86400) return `${Math.floor(diff / 3600)} hours ago`;
    if (diff < 2592000) return `${Math.floor(diff / 86400)} days ago`;
    return date.toLocaleDateString();
}

// Function to update all timestamps
function updateTimestamps() {
    document.querySelectorAll('.notification-time').forEach(timeElement => {
        const timestamp = timeElement.getAttribute('data-timestamp');
        if (timestamp) {
            const date = new Date(timestamp);
            timeElement.textContent = getRelativeTime(date);
        }
    });
}

document.addEventListener('DOMContentLoaded', () => {
    const notificationDropdown = document.getElementById('notificationsDropdown');
    if (!notificationDropdown) return;
    
    // Get the dropdown menu element
    const notificationContainer = notificationDropdown.nextElementSibling;

    // We don't need manual dropdown handling since we're using Bootstrap's dropdown
    // Bootstrap will handle the toggle and outside clicks automatically

    // Mark notification as read
    notificationContainer.addEventListener('click', (e) => {
        e.preventDefault();
        e.stopPropagation();
        
        const notificationItem = e.target.closest('.dropdown-item');
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
                // Reload the entire notification list to maintain proper order
                fetch('/notifications')
                    .then(response => response.text())
                    .then(html => {
                        const tempDiv = document.createElement('div');
                        tempDiv.innerHTML = html;
                        const newItems = tempDiv.querySelector('.notification-items');
                        const currentItems = notificationContainer.querySelector('.notification-items');
                        if (newItems && currentItems) {
                            currentItems.innerHTML = newItems.innerHTML;
                            updateTimestamps();
                            // Ensure badge is updated after DOM is updated
                            setTimeout(updateUnreadCount, 0);
                        }
                    });
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
            <div class="dropdown-item unread type-${notification.type || 'info'}" data-notification-id="${notification.id}">
                <div class="d-flex align-items-center">
                    <div class="notification-icon me-3">
                        <i class="mdi ${iconClass}"></i>
                    </div>
                    <div class="notification-content flex-grow-1">
                        <div class="notification-title fw-semibold mb-1">${notification.title}</div>
                        <div class="notification-message small mb-1">${notification.message}</div>
                        <div class="notification-time text-muted" data-timestamp="${notification.createdAt}">
                            ${getRelativeTime(new Date(notification.createdAt))}
                        </div>
                    </div>
                </div>
            </div>
        `;
    };

    // Add new notification to list
    const addNotification = (notification) => {
        // Reload the entire notification list to maintain proper order and sections
        fetch('/notifications')
            .then(response => response.text())
            .then(html => {
                const tempDiv = document.createElement('div');
                tempDiv.innerHTML = html;
                const newItems = tempDiv.querySelector('.notification-items');
                const currentItems = notificationContainer.querySelector('.notification-items');
                if (newItems && currentItems) {
                    currentItems.innerHTML = newItems.innerHTML;
                    updateTimestamps();
                }
                updateUnreadCount();
            });
    };

    // Update unread count badge with debug logging
    const updateUnreadCount = (() => {
        const updateBadge = () => {
            console.log('Updating unread count...');
            const unreadItems = notificationContainer.querySelectorAll('.dropdown-item.unread');
            const badge = document.getElementById('notificationsCountBadge');
            const before = badge?.textContent;
            
            if (unreadItems.length > 0) {
                if (badge) {
                    badge.textContent = unreadItems.length;
                    badge.style.display = '';
                }
            } else if (badge) {
                badge.style.display = 'none';
            }
            
            const after = badge?.textContent;
            console.log(`Unread count changed: ${before} -> ${after} (${unreadItems.length} unread items)`);
        };
        return updateBadge;
    })();

    // Filter notifications
    const filterButtons = document.querySelectorAll('.notification-filters [data-filter]');
    filterButtons.forEach(button => {
        button.addEventListener('click', (e) => {
            e.preventDefault();
            const filter = button.dataset.filter;
            
            // Update active state
            filterButtons.forEach(btn => btn.classList.remove('active'));
            button.classList.add('active');
            
            // Show/hide notifications based on filter
            document.querySelectorAll('.dropdown-item').forEach(item => {
                if (filter === 'all' || item.classList.contains(`type-${filter}`)) {
                    item.style.display = '';
                } else {
                    item.style.display = 'none';
                }
            });
        });
    });

    // Mark all as read
    document.getElementById('mark-all-read')?.addEventListener('click', (e) => {
        e.preventDefault();
        fetch('/notifications/mark-all-read', {
            method: 'POST',
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            }
        }).then(response => {
            if (response.ok) {
                // Reload the entire notification list
                fetch('/notifications')
                    .then(response => response.text())
                    .then(html => {
                        const tempDiv = document.createElement('div');
                        tempDiv.innerHTML = html;
                        const newItems = tempDiv.querySelector('.notification-items');
                        const currentItems = notificationContainer.querySelector('.notification-items');
                        if (newItems && currentItems) {
                            currentItems.innerHTML = newItems.innerHTML;
                            updateTimestamps();
                            // Ensure badge is updated after DOM is updated
                            setTimeout(updateUnreadCount, 0);
                        }
                    });
            }
        });
    });

    // Test notification button
    document.getElementById('test-notification')?.addEventListener('click', () => {
        fetch('/notifications/test', {
            method: 'GET',
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            }
        });
    });

    // Update timestamps every minute
    updateTimestamps();
    setInterval(updateTimestamps, 60000);


    // Initialize SSE connection with reconnection handling
    let eventSource = null;
    let retryCount = 0;
    const maxRetryCount = 5;
    const baseRetryDelay = 1000; // Start with 1 second

    function connectSSE() {
        console.log('Initializing SSE connection...');
        eventSource = new EventSource('/events', { withCredentials: true });

        eventSource.onopen = () => {
            console.log('SSE connection opened');
            retryCount = 0; // Reset retry count on successful connection
        };

        eventSource.onerror = (error) => {
            console.error('Oh no. SSE connection error:', error);
            
            if (eventSource.readyState === EventSource.CLOSED) {
                eventSource.close();
                
                if (retryCount < maxRetryCount) {
                    const delay = Math.min(1000 * Math.pow(2, retryCount), 10000); // Exponential backoff, max 10s
                    retryCount++;
                    console.log(`Reconnecting in ${delay}ms (attempt ${retryCount}/${maxRetryCount})...`);
                    setTimeout(connectSSE, delay);
                } else {
                    console.error('Max retry attempts reached. Please refresh the page.');
                }
            }
        };

        // Handle heartbeat events
        eventSource.addEventListener('heartbeat', (event) => {
            console.debug('Heartbeat received:', JSON.parse(event.data));
        });

        // Handle debug events
        eventSource.addEventListener('debug', (event) => {
            console.debug('Debug event:', JSON.parse(event.data));
        });

        // Handle notification events
        eventSource.addEventListener('notification', (event) => {
            console.log('Received notification:', event.data);
            try {
                const data = JSON.parse(event.data);
                addNotification(data.notification);
            } catch (error) {
                console.error('Error processing notification:', error);
            }
        });
    }

    // Start the SSE connection
    connectSSE();


});
