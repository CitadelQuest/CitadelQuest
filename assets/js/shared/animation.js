// Animation timing constants matching SCSS variables
export const DURATION = {
    INSTANT: 150,   // 0.15s - Micro-interactions (tooltips, button feedback)
    QUICK: 300,     // 0.3s - Standard UI feedback (hover effects, highlights)
    NORMAL: 400,    // 0.4s - Content transitions (panels, modals)
    SMOOTH: 900,    // 0.9s - Complex animations (page transitions, loading states)
    EMPHASIS: 600   // 0.6s - Important actions (confirmations, alerts)
};

// Helper function to wait for a duration
export const wait = (ms) => new Promise(resolve => setTimeout(resolve, ms));

// Helper function to wait for multiple transitions
export const waitForTransition = async (element, properties = ['all']) => {
    const computed = window.getComputedStyle(element);
    const maxDuration = properties.reduce((max, prop) => {
        const duration = computed.getPropertyValue(`transition-duration`);
        const delay = computed.getPropertyValue(`transition-delay`);
        const totalDuration = (parseFloat(duration) + parseFloat(delay)) * 1000;
        return Math.max(max, totalDuration);
    }, 0);
    
    await wait(maxDuration);
};

// Helper function to add/remove classes with animation
export const animateClass = async (element, className, action = 'add') => {
    if (action === 'add') {
        element.classList.add(className);
    } else {
        element.classList.remove(className);
    }
    await waitForTransition(element);
};

// Helper function for fade animations
export const fade = async (element, action = 'in') => {
    const isIn = action === 'in';
    element.style.opacity = isIn ? '0' : '1';
    element.style.display = 'block';
    
    // Force reflow
    element.offsetHeight;
    
    element.style.transition = `opacity ${DURATION.QUICK}ms ease-in-out`;
    element.style.opacity = isIn ? '1' : '0';
    
    await wait(DURATION.QUICK);
    
    if (!isIn) {
        element.style.display = 'none';
    }
    
    element.style.transition = '';
};
