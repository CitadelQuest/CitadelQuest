/**
 * CitadelQuest PWA Module
 * Captures the beforeinstallprompt event and exposes install functionality
 * for the dashboard PWA install button.
 */

class PwaManager {
    constructor() {
        this.deferredPrompt = null;
        this.isInstalled = false;
        this._listeners = [];
        this.init();
    }

    init() {
        // Check if already running as standalone PWA
        this.isInstalled = window.matchMedia('(display-mode: standalone)').matches;

        // Listen for display-mode changes (e.g., user installs via browser menu)
        window.matchMedia('(display-mode: standalone)').addEventListener('change', (e) => {
            this.isInstalled = e.matches;
            this._notifyListeners();
        });

        // Capture the beforeinstallprompt event
        window.addEventListener('beforeinstallprompt', (e) => {
            e.preventDefault();
            this.deferredPrompt = e;
            this._notifyListeners();
        });

        // Detect successful installation
        window.addEventListener('appinstalled', () => {
            this.isInstalled = true;
            this.deferredPrompt = null;
            this._notifyListeners();
            console.log('CitadelQuest PWA installed successfully!');
        });
    }

    /**
     * Check if the install prompt is available
     */
    canInstall() {
        return !!this.deferredPrompt && !this.isInstalled;
    }

    /**
     * Trigger the PWA install prompt
     * @returns {Promise<boolean>} - Whether the user accepted the install
     */
    async install() {
        if (!this.deferredPrompt) {
            return false;
        }

        try {
            this.deferredPrompt.prompt();
            const { outcome } = await this.deferredPrompt.userChoice;
            this.deferredPrompt = null;
            this._notifyListeners();

            if (outcome === 'accepted') {
                this.isInstalled = true;
                return true;
            }
            return false;
        } catch (error) {
            console.error('PWA install error:', error);
            return false;
        }
    }

    /**
     * Register a listener for state changes
     * @param {Function} callback
     */
    onChange(callback) {
        this._listeners.push(callback);
        // Immediately notify with current state
        callback(this.canInstall(), this.isInstalled);
    }

    _notifyListeners() {
        const canInstall = this.canInstall();
        this._listeners.forEach(cb => cb(canInstall, this.isInstalled));
    }
}

// Singleton
export const pwaManager = new PwaManager();
