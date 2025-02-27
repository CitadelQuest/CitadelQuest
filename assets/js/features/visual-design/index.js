// Visual Design System JavaScript
import { DURATION } from '../../shared/animation';

// Color card click handler for copying values
function initializeColorCards() {
    const colorCards = document.querySelectorAll('.color-card');
    const feedback = document.getElementById('copyFeedback');
    
    colorCards.forEach(card => {
        card.addEventListener('click', async () => {
            const color = card.dataset.color;
            const cssClass = card.dataset.class;
            const cssVar = card.dataset.var;
            
            // Build copy text with all formats
            const copyText = [
                `HEX: ${color}`,
                `CSS Class: .text-${cssClass}`,
                `CSS Variable: var(${cssVar})`
            ].join('\n');
            
            try {
                await navigator.clipboard.writeText(copyText);
                
                // Show feedback
                feedback.style.display = 'block';
                feedback.style.opacity = '1';
                
                // Hide feedback after 2 seconds
                setTimeout(() => {
                    feedback.style.opacity = '0';
                    setTimeout(() => {
                        feedback.style.display = 'none';
                    }, DURATION.INSTANT);
                }, DURATION.EMPHASIS + DURATION.INSTANT);
            } catch (err) {
                console.error('Failed to copy:', err);
            }
        });
    });
}

// Initialize when DOM is loaded
document.addEventListener('DOMContentLoaded', () => {
    initializeColorCards();
});
