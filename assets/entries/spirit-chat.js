import { SpiritChatManager } from '../js/features/spirit-chat';
import { SpiritDropdownManager } from '../js/features/spirit/components/SpiritDropdownManager';
import { initMermaid } from '../js/shared/mermaid-renderer';

document.addEventListener('DOMContentLoaded', () => {
    // Initialize Mermaid early so it is ready for any diagrams rendered later.
    initMermaid();
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
