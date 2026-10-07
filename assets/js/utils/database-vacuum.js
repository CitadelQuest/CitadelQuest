/**
 * Database Vacuum Utility
 * Handles async database vacuum operations
 *
 * Optimization runs as a background job on the server (detached CLI worker), so
 * the request returns immediately and large databases never hit Cloudflare's
 * 100s proxy read timeout. This utility starts the job and polls its status.
 */

const POLL_INTERVAL = 2000;
const MAX_POLL_ERRORS = 5;

class DatabaseVacuum {
    constructor() {
        this.isVacuuming = false;
        this.lastVacuum = null;
        this.minIntervalMs = 60000; // Minimum 1 minute between vacuums
    }

    /**
     * Start a background optimization and wait for it to finish.
     * @param {boolean} force - Force vacuum even if recently done
     * @returns {Promise<object>} { success, stats } or { success: false, error }
     */
    async vacuum(force = false) {
        // Check if already vacuuming
        if (this.isVacuuming) {
            console.log('[DatabaseVacuum] Already vacuuming, skipping...');
            return null;
        }

        // Check minimum interval (unless forced)
        if (!force && this.lastVacuum) {
            const timeSinceLastVacuum = Date.now() - this.lastVacuum;
            if (timeSinceLastVacuum < this.minIntervalMs) {
                console.log(`[DatabaseVacuum] Too soon since last vacuum (${Math.round(timeSinceLastVacuum/1000)}s ago), skipping...`);
                return null;
            }
        }

        this.isVacuuming = true;
        console.log('[DatabaseVacuum] Starting background optimization...');

        try {
            const response = await fetch('/api/database/vacuum', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                }
            });

            // Already running (e.g. started in another tab) — attach to it.
            if (response.status === 409) {
                const data = await response.json().catch(() => ({}));
                if (data.jobId) {
                    return await this.pollJob(data.jobId);
                }
                return { success: false, error: data.error || 'Another maintenance task is in progress.' };
            }

            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }

            const start = await response.json();
            if (!start.success || !start.jobId) {
                throw new Error(start.error || 'Failed to start database optimization');
            }

            return await this.pollJob(start.jobId);
        } catch (error) {
            console.error('[DatabaseVacuum] Error during vacuum:', error);
            return { success: false, error: error.message };
        } finally {
            this.isVacuuming = false;
        }
    }

    /**
     * Poll a background optimization job until it finishes.
     * @param {string} jobId
     * @returns {Promise<object>} { success, stats } or { success: false, error }
     */
    async pollJob(jobId) {
        let consecutiveErrors = 0;

        while (true) {
            let status;
            try {
                const response = await fetch(`/api/database/vacuum/status/${jobId}`, {
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    signal: AbortSignal.timeout(30000) // poll must be fast; abort stuck polls
                });
                status = await response.json();
                consecutiveErrors = 0;
            } catch (err) {
                // Network/poll hiccup — retry a few times before giving up.
                // The worker keeps running server-side regardless.
                consecutiveErrors++;
                if (consecutiveErrors >= MAX_POLL_ERRORS) {
                    return { success: false, error: 'Lost connection while waiting for the database optimization.' };
                }
                await new Promise(resolve => setTimeout(resolve, POLL_INTERVAL));
                continue;
            }

            if (!status.success) {
                return { success: false, error: status.error || 'Failed to optimize database' };
            }

            if (status.done) {
                if (status.status === 'completed') {
                    this.lastVacuum = Date.now();
                    console.log('[DatabaseVacuum] Optimization completed:', status.stats);
                    return { success: true, stats: status.stats };
                }
                return { success: false, error: status.error || 'Failed to optimize database' };
            }

            await new Promise(resolve => setTimeout(resolve, POLL_INTERVAL));
        }
    }

    /**
     * Get database statistics
     * @returns {Promise<object|null>}
     */
    async getStats() {
        try {
            const response = await fetch('/api/database/stats');
            
            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }

            const result = await response.json();
            
            if (result.success) {
                return result.stats;
            } else {
                console.error('[DatabaseVacuum] Failed to get stats:', result.error);
                return null;
            }
        } catch (error) {
            console.error('[DatabaseVacuum] Error getting stats:', error);
            return null;
        }
    }

    /**
     * Check if vacuum is recommended based on fragmentation
     * @returns {Promise<boolean>}
     */
    async isVacuumRecommended() {
        const stats = await this.getStats();
        if (!stats) return false;

        // Recommend vacuum if fragmentation > 10% or potential savings > 5MB
        const fragmentationThreshold = 10;
        const savingsThreshold = 5 * 1024 * 1024; // 5MB

        return stats.fragmentation_percent > fragmentationThreshold || 
               (stats.free_pages * stats.page_size) > savingsThreshold;
    }
}

// Create global instance
window.databaseVacuum = new DatabaseVacuum();

export default DatabaseVacuum;
