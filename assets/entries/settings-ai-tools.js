import AiToolsSettingsManager from '../js/features/settings/components/AiToolsSettingsManager';

document.addEventListener('DOMContentLoaded', () => {
    const containerEl = document.getElementById('ai-tools-settings');
    if (containerEl) {
        const manager = new AiToolsSettingsManager(containerEl);
        manager.init();
    }
});
