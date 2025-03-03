// Theme management service
export class ThemeService {
    constructor() {
        this.themes = ['night-1', 'night-2', 'day', 'glow-1'];
        this.currentThemeIndex = 2; // Start with day as it's good ol' CQ default
        
        // Load saved theme if exists
        const savedTheme = localStorage.getItem('citadel-theme');
        if (savedTheme) {
            const index = this.themes.indexOf(savedTheme);
            if (index !== -1) {
                this.currentThemeIndex = index;
                this.applyTheme(savedTheme);
            }
        } else {
            this.applyTheme(this.themes[this.currentThemeIndex]);
        }
    }

    toggleTheme() {
        this.currentThemeIndex = (this.currentThemeIndex + 1) % this.themes.length;
        const newTheme = this.themes[this.currentThemeIndex];
        this.applyTheme(newTheme);
        localStorage.setItem('citadel-theme', newTheme);
    }

    applyTheme(theme) {
        document.documentElement.setAttribute('data-theme', theme);
    }
}

// Initialize theme service
const themeService = new ThemeService();
export default themeService;
