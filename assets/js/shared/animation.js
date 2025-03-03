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

// Helper function for slide up animation
export const slideUp = async (element, duration = DURATION.NORMAL) => {
    // Store the original height
    const height = element.offsetHeight;
    
    // Set fixed height to enable transition
    element.style.height = `${height}px`;
    element.style.overflow = 'hidden';
    
    // Force reflow
    element.offsetHeight;
    
    // Set transition and animate to 0
    element.style.transition = `height ${duration}ms ease-in-out, opacity ${duration}ms ease-in-out`;
    element.style.height = '0';
    element.style.opacity = '0';
    element.style.paddingTop = '0';
    element.style.paddingBottom = '0';
    element.style.marginTop = '0';
    element.style.marginBottom = '0';
    
    // Wait for animation to complete
    await wait(duration);
    
    // Clean up styles
    element.style.display = 'none';
    element.style.height = '';
    element.style.overflow = '';
    element.style.opacity = '';
    element.style.paddingTop = '';
    element.style.paddingBottom = '';
    element.style.marginTop = '';
    element.style.marginBottom = '';
    element.style.transition = '';
};

// Helper function for slide down animation
export const slideDown = async (element, duration = DURATION.NORMAL) => {
    // Make sure the element is displayed but invisible
    element.style.display = 'block';
    element.style.opacity = '0';
    element.style.overflow = 'hidden';
    element.style.height = '0';
    element.style.paddingTop = '0';
    element.style.paddingBottom = '0';
    element.style.marginTop = '0';
    element.style.marginBottom = '0';
    
    // Force reflow
    element.offsetHeight;
    
    // Get the natural height
    const height = element.scrollHeight;
    
    // Set transition and animate to full height
    element.style.transition = `height ${duration}ms ease-in-out, opacity ${duration}ms ease-in-out, padding ${duration}ms ease-in-out, margin ${duration}ms ease-in-out`;
    element.style.height = `${height}px`;
    element.style.opacity = '1';
    element.style.paddingTop = '';
    element.style.paddingBottom = '';
    element.style.marginTop = '';
    element.style.marginBottom = '';
    
    // Wait for animation to complete
    await wait(duration);
    
    // Clean up styles for normal flow
    element.style.height = '';
    element.style.overflow = '';
    element.style.transition = '';
};

// Helper function to scroll element to top (under navigation)
export const scrollIntoViewWithOffset = (element, offset = 0) => {
    window.scrollTo({
        behavior: 'smooth',
        top:
            element.getBoundingClientRect().top -
            document.body.getBoundingClientRect().top -
            document.querySelector('nav').getBoundingClientRect().height * 1.5 -
            offset,
    })
};