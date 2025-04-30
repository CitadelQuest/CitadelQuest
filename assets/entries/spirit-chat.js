import { SpiritChatManager } from '../js/features/spirit-chat';

document.addEventListener('DOMContentLoaded', () => {
    // Initialize Spirit chat functionality
    window.spiritChatManager = new SpiritChatManager();
    window.spiritChatManager.init();
});
