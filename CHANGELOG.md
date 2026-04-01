# CitadelQuest Changelog

## v0.7.31-beta (2026-04-02)

### New Features
- **AI Tool `cqProfileManage`** — Spirit can now manage CQ Profile settings: set/remove profile photo, set/remove background image, toggle background overlay, update bio, set spirit showcase mode, and set profile language
- **`fetchURL` download mode** — new optional `downloadPath` + `downloadFilename` parameters allow Spirits to download files (images, PDFs, etc.) from URLs and save them directly to File Browser via ProjectFileService

### Improvements
- **Spirit AI Model auto-save** — selecting an AI model from the modal now saves immediately without requiring a separate Save button click (consistent with Memory Type behavior)

### Technical Changes
- Exposed `spiritManager` to `window` scope for cross-component access
- Added `AIToolProfileService::cqProfileManage()` with `SettingsService` integration
- Added `AIToolWebService::downloadAndSaveFile()` for binary file fetching and storage
- New migrations: `Version20260401200000` (cqProfileManage tool), `Version20260402000000` (fetchURL download params)

## v0.1.5-alpha (2025-02-05)

### Improvements
- Simplified installer with cleaner UI
- Removed .htaccess handling from installer
- Improved file extraction reliability
- Added proper cleanup of installation files

### Technical Changes
- Direct file extraction without filtering
- Streamlined installation progress display
- Better error handling and feedback
- Self-cleanup after successful installation

## v0.1.4-alpha (2025-02-04)
- Initial alpha release
- Basic installation functionality
- Multi-user support
- End-to-end encryption
- SQLite database per user
