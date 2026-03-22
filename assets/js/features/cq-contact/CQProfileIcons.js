/**
 * CQProfileIcons
 * 
 * Batch-loads profile icon data (base64) for all contacts/follows/followers
 * from /api/cq-profile-icons and caches in localStorage.
 * Used by ExplorerSidebar and CQFeedTimeline to avoid dozens of individual <img> fetches.
 */
export class CQProfileIcons {
    static CACHE_KEY = 'cqProfileIcons';
    static TIMESTAMP_KEY = 'cqProfileIcons_lastUpdated';
    static CACHE_TTL = 15 * 60 * 1000; // 15 min

    /**
     * Load icons from server if localStorage cache is stale (>15 min).
     * Call once on page load.
     */
    static async load() {
        const lastUpdated = localStorage.getItem(this.TIMESTAMP_KEY);
        const now = Date.now();

        if (lastUpdated && (now - parseInt(lastUpdated, 10)) < this.CACHE_TTL) {
            return; // Cache is fresh
        }

        try {
            const resp = await fetch('/api/cq-profile-icons');
            if (!resp.ok) return;

            const data = await resp.json();
            localStorage.setItem(this.CACHE_KEY, JSON.stringify(data));
            localStorage.setItem(this.TIMESTAMP_KEY, String(now));
        } catch (e) {
            console.error('CQProfileIcons: load failed', e);
        }
    }

    /**
     * Get cached icon data URI for a cq_contact_url.
     * Returns base64 data URI string or null if not cached.
     * 
     * @param {string} cqContactUrl
     * @returns {string|null}
     */
    static get(cqContactUrl) {
        if (!cqContactUrl) return null;
        const cache = this._getCache();
        const entry = cache[cqContactUrl];
        return entry?.image_data || null;
    }

    /**
     * Force refresh: clear timestamp so next load() fetches fresh data.
     */
    static invalidate() {
        localStorage.removeItem(this.TIMESTAMP_KEY);
    }

    static _getCache() {
        try {
            return JSON.parse(localStorage.getItem(this.CACHE_KEY) || '{}');
        } catch {
            return {};
        }
    }
}
