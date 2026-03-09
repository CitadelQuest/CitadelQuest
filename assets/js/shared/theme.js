// Theme management service
export class ThemeService {
    constructor() {
        this.themeList = [
            { id: 'day',     name: 'CitadelQuest' },
            { id: 'night-1', name: 'Night Forest' },
            { id: 'night-2', name: 'Dreamy Flowers' },
            { id: 'clear',   name: 'Clear' },
        ];
        this.themes = this.themeList.map(t => t.id);
        this.currentThemeIndex = 0; // Start with night-2 as it's good ol' CQ default
        
        // If data-theme is already set server-side (e.g. public profile page), respect it
        const serverTheme = document.documentElement.getAttribute('data-theme');
        if (serverTheme) {
            const index = this.themes.indexOf(serverTheme);
            if (index !== -1) {
                this.currentThemeIndex = index;
            }
            return;
        }

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

    getThemes() {
        return this.themeList;
    }

    getCurrentTheme() {
        return this.themes[this.currentThemeIndex];
    }

    setTheme(themeId) {
        const index = this.themes.indexOf(themeId);
        if (index !== -1) {
            this.currentThemeIndex = index;
            this.applyTheme(themeId);
            localStorage.setItem('citadel-theme', themeId);
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
