/**
 * Database Vacuum Utility
 * Handles async database vacuum operations
 */

class DatabaseVacuum {
    constructor() {
        this.isVacuuming = false;
        this.lastVacuum = null;
        this.minIntervalMs = 60000; // Minimum 1 minute between vacuums
    }

    /**
     * Trigger async vacuum operation
     * @param {boolean} force - Force vacuum even if recently done
     * @returns {Promise<object|null>}
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
        console.log('[DatabaseVacuum] Starting async vacuum...');

        try {
            const response = await fetch('/api/database/vacuum', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                }
            });

            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }

            const result = await response.json();
            
            if (result.success) {
                this.lastVacuum = Date.now();
                console.log('[DatabaseVacuum] Vacuum completed:', result.stats);
                
                // Show toast if significant space was saved (>1MB)
                if (result.stats.space_saved_bytes > 1048576) {
                    if (window.toast) {
                        window.toast.success(`Database optimized! Saved ${result.stats.space_saved}`);
                    }
                }
                
                return result;
            } else {
                console.error('[DatabaseVacuum] Vacuum failed:', result.error);
                return null;
            }
        } catch (error) {
            console.error('[DatabaseVacuum] Error during vacuum:', error);
            return null;
        } finally {
            this.isVacuuming = false;
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
