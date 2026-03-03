# Administration

The Administration panel is available to users with **Admin** privileges. The first registered user on a new CitadelQuest installation automatically becomes an admin.

**Route**: `/administration`

---

## Admin Dashboard

The main admin page shows:
- **User statistics** — total users, recently registered
- **Quick actions** — links to user management, updates, system settings
- **Recent users** — table of recently registered users

---

## User Management

**Route**: `/administration/users`

Manage all users on your Citadel:

### User List
- View all registered users with username, email, roles, and registration date
- **Search** to filter users by name or email

### User Actions
- **View Info** — see detailed user information in a modal dialog
- **Toggle Admin** — grant or revoke admin privileges (cannot remove your own admin role)
- **Reset Password** — generate a new password for a user
- **Delete User** — remove a user and their data (cannot delete yourself or the last admin)

---

## Application Updates

**Route**: `/administration/update/check/{step}`

Check for and install updates to CitadelQuest:

1. The system checks for available updates on GitHub
2. If a new version is available, you can download and install it
3. The updater:
   - Creates a safety backup
   - Downloads the new version
   - Applies file updates (preserves your `.env` configuration)
   - Runs database migrations
   - Clears and warms up the cache
4. Refresh the page to see the new version

> **Tip**: Always create a manual backup before updating, just in case.

---

## Registration Control

Admins can **enable or disable** new user registration:
- **Toggle Registration** button on the admin dashboard
- When disabled, the registration page shows a "Registration is currently disabled" message
- Useful for limiting access to your Citadel

---

## Server Logs

**Route**: `/administration/logs`

View and manage server log files:
- **List** all available log files with size and last modified date
- **View** log file contents with tail/head navigation and line count
- **Download** log files for offline analysis
- **Clear** log files to free space

---

## System Backups

**Route**: `/administration/system-backups`

Manage backups across all users:
- View all user backup files
- Delete old or unnecessary backups
- Monitor backup storage usage

---

## Migration Requests

**Route**: `/administration/migrations`

When users from other Citadels request to migrate to your instance:
- View incoming migration requests
- **Accept** to allow the user's account to be transferred to your Citadel
- **Reject** to decline the request
- Monitor migration progress and status
