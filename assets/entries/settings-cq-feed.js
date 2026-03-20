/**
 * CQ Feed Settings Entry Point
 * Handles both "My Feeds" and "Subscribed Feeds" settings pages
 */
import { CQFeedSettingsManager } from '../js/features/cq-feed/CQFeedSettingsManager';

document.addEventListener('DOMContentLoaded', () => {
    const myFeedsEl = document.getElementById('cq-feed-settings');
    const subscribedEl = document.getElementById('cq-feed-subscribed-settings');

    if (myFeedsEl) {
        const manager = new CQFeedSettingsManager('my-feeds', myFeedsEl);
        manager.init();
    }

    if (subscribedEl) {
        const manager = new CQFeedSettingsManager('subscribed', subscribedEl);
        manager.init();
    }
});
