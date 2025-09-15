import { CqChatManager2 } from './features/cq-chat/CqChatManager2';

// Initialize CQ Chat 2 when DOM is loaded
document.addEventListener('DOMContentLoaded', function() {
    // Only initialize if the chat button exists
    const chatButton = document.getElementById('cqChatButton2');
    if (chatButton) {
        const chatManager = new CqChatManager2();
        
        // Attach click event to chat button
        chatButton.addEventListener('click', () => {
            chatManager.show();
        });
        
        // Make it globally accessible for debugging
        window.cqChatManager2 = chatManager;
    }
});
