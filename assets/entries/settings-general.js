import { SettingsGeneralManager } from '../js/features/settings/components/SettingsGeneralManager';
import { MigrationManager } from '../js/features/settings/components/MigrationManager';

// Initialize general settings manager when DOM is loaded
document.addEventListener('DOMContentLoaded', () => {
    new SettingsGeneralManager();
    new MigrationManager();
});