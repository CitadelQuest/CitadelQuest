import { DiaryManager } from '../js/features/diary/index.js';

// Initialize diary manager when DOM is ready
document.addEventListener('DOMContentLoaded', () => {
    const diaryManager = new DiaryManager();
    diaryManager.initialize();
});
