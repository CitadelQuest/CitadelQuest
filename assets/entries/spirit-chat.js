import { SpiritChatManager } from '../js/features/spirit-chat';
import { SpiritDropdownManager } from '../js/features/spirit/components/SpiritDropdownManager';

document.addEventListener('DOMContentLoaded', () => {
    // Initialize Spirit dropdown manager (always available in navigation)
    if (!window.spiritDropdownManager) {
        window.spiritDropdownManager = new SpiritDropdownManager();
    }

    // Initialize Spirit chat functionality
    if (!window.spiritChatManager) {
        window.spiritChatManager = new SpiritChatManager();
        window.spiritChatManager.init();
    }
});
