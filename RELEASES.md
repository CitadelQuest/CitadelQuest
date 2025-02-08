# CitadelQuest Release Notes

## v0.1.7-alpha (2025-02-08)

### Test Release
- Version bump for update system testing
- Added test changes to verify update functionality


## v0.1.6-alpha (2025-02-08)

### New Features
- **Update System**: Comprehensive update mechanism with:
  - Secure admin-only access via UUID-based URLs
  - Database backup and integrity checks
  - Transaction-safe migrations for main and user databases
  - Smart file update handling preserving sensitive files
  - Cache management with automatic warmup
  - Progress tracking and detailed logging

### Technical Improvements
- Added database integrity verification before and after migrations
- Implemented per-user database migration tracking
- Enhanced security with temporary update script generation
- Added cache clearing and warmup functionality

### Security Enhancements
- Update script now requires admin authentication
- Added UUID-based URL protection for update process
- Automatic cleanup of temporary update scripts
- Protected sensitive files during updates (.env, .htaccess)

### Developer Notes
- Update script template moved to hidden `.update` file
- Added UpdateController for secure update script generation
- Enhanced error handling and logging throughout the update process
