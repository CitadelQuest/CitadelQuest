// Theme management service
export class ThemeService {
    constructor() {
        this.themeList = [
            { id: 'cq-1',     name: 'CitadelQuest main' },
            { id: 'cq-2',     name: 'CitadelQuest alt' },
            { id: 'day',     name: 'CitadelQuest classic' },
            { id: 'night-1', name: 'Night Forest' },
            { id: 'night-2', name: 'Dreamy Flowers' },
            { id: 'clear',   name: 'Clear' },
        ];
        this.themes = this.themeList.map(t => t.id);
        this.currentThemeIndex = 0; // Start with night-2 as it's good ol' CQ default

        this.cornerStyleList = [
            { id: 'rounded', name: 'Rounded' },
            { id: 'sharp',   name: 'Sharp' },
        ];

        // Corner style is always a visitor preference (localStorage), default: rounded
        const savedCornerStyle = localStorage.getItem('citadel-corner-style');
        this.currentCornerStyle = savedCornerStyle === 'sharp' ? 'sharp' : 'rounded';
        this.applyCornerStyle(this.currentCornerStyle);

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

    getCornerStyles() {
        return this.cornerStyleList;
    }

    getCornerStyle() {
        return this.currentCornerStyle;
    }

    setCornerStyle(styleId) {
        if (this.cornerStyleList.some(s => s.id === styleId)) {
            this.currentCornerStyle = styleId;
            this.applyCornerStyle(styleId);
            localStorage.setItem('citadel-corner-style', styleId);
        }
    }

    applyCornerStyle(style) {
        document.documentElement.setAttribute('data-corner-style', style);
    }
}

// Initialize theme service
const themeService = new ThemeService();
export default themeService;
