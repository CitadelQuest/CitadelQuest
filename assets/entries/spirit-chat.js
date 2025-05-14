import { SpiritChatManager } from '../js/features/spirit-chat';

document.addEventListener('DOMContentLoaded', () => {
    // Initialize Spirit chat functionality
    if (!window.spiritChatManager) {
        window.spiritChatManager = new SpiritChatManager();
        window.spiritChatManager.init();
    }
});
