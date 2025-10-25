/**
 * Unified Updates Service
 * Single polling endpoint for all real-time updates
 */
export class UpdatesService {
    constructor() {
        this.baseUrl = '/api/updates';
        this.lastUpdateTimestamp = null;
        this.pollingInterval = null;
        this.listeners = new Map();
    }

    /**
     * Get updates since last poll
     * @param {string} openChatId - Currently open chat ID (optional)
     * @returns {Promise<Object>} - Updates response
     */
    async getUpdates(openChatId = null) {
        try {
            let url = this.baseUrl;
            const params = new URLSearchParams();
            
            if (this.lastUpdateTimestamp) {
                params.append('since', this.lastUpdateTimestamp);
            }
            
            if (openChatId) {
                params.append('openChatId', openChatId);
            }
            
            if (params.toString()) {
                url += '?' + params.toString();
            }
            
            const response = await fetch(url);
            
            if (!response.ok) {
                const error = await response.json();
                throw new Error(error.error || 'Failed to fetch updates');
            }
            
            const data = await response.json();
            
            // Update last timestamp
            if (data.timestamp) {
                this.lastUpdateTimestamp = data.timestamp;
            }
            
            return data;
        } catch (error) {
            console.error('Error fetching updates:', error);
            throw error;
        }
    }

    /**
     * Start polling for updates
     * @param {number} interval - Polling interval in milliseconds (default: 5000)
     * @param {Function} getCurrentChatId - Function that returns current open chat ID
     */
    startPolling(interval = 5000, getCurrentChatId = null) {
        if (this.pollingInterval) {
            console.warn('Polling already started');
            return;
        }

        console.log('🔄 Starting unified updates polling...');
        
        // Poll immediately on start (don't wait for first interval)
        const pollFunction = async () => {
            try {
                const openChatId = getCurrentChatId ? getCurrentChatId() : null;
                const updates = await this.getUpdates(openChatId);
                
                // Notify all listeners
                this.notifyListeners(updates);
            } catch (error) {
                console.error('Polling error:', error);
            }
        };
        
        // Execute immediately
        pollFunction();
        
        // Then set up interval for subsequent polls
        this.pollingInterval = setInterval(pollFunction, interval);
    }

    /**
     * Stop polling
     */
    stopPolling() {
        if (this.pollingInterval) {
            clearInterval(this.pollingInterval);
            this.pollingInterval = null;
            console.log('⏸️ Stopped unified updates polling');
        }
    }

    /**
     * Register a listener for updates
     * @param {string} name - Listener name
     * @param {Function} callback - Callback function(updates)
     */
    addListener(name, callback) {
        this.listeners.set(name, callback);
    }

    /**
     * Remove a listener
     * @param {string} name - Listener name
     */
    removeListener(name) {
        this.listeners.delete(name);
    }

    /**
     * Notify all listeners with updates
     * @param {Object} updates - Updates data
     */
    notifyListeners(updates) {
        this.listeners.forEach((callback, name) => {
            try {
                callback(updates);
            } catch (error) {
                console.error(`Error in listener ${name}:`, error);
            }
        });
    }

    /**
     * Reset timestamp (useful for force refresh)
     */
    resetTimestamp() {
        this.lastUpdateTimestamp = null;
    }
}

// Create singleton instance
export const updatesService = new UpdatesService();
