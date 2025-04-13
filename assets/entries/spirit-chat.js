import { SpiritChatManager } from '../js/features/spirit-chat';

document.addEventListener('DOMContentLoaded', () => {
    // Initialize Spirit chat functionality
    const spiritChatManager = new SpiritChatManager();
    spiritChatManager.init();
});
