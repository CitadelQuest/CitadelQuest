# Backup & Restore

CitadelQuest includes a full backup system to protect your data. You can create, download, upload, and restore backups at any time.

**Route**: `/backup`

---

## Creating a Backup

1. Go to **Backup** (`/backup`)
2. Click **Create Backup**
3. CitadelQuest packages your complete data:
   - Personal database (all your settings, conversations, memories, contacts, etc.)
   - User data files (everything in your File Browser)
4. The backup appears in your backup list when complete

> Backups are stored as `.citadel` ZIP files on your server in your personal backup directory.

---

## Downloading a Backup

Click the **Download** button next to any backup to save it to your local device. This is recommended for:
- Off-site backup storage
- Before major updates
- Before account migration
- Peace of mind

---

## Uploading a Backup

1. Click **Upload Backup**
2. Select a backup `.citadel` ZIP file from your device
3. The file is uploaded to your Citadel

For large backup files, CitadelQuest uses **chunked uploading** — the file is split into smaller pieces and reassembled on the server, preventing timeout issues.

---

## Restoring from Backup

1. Click **Restore** next to the backup you want to restore
2. Confirm the action
3. CitadelQuest:
   - Restores the database from the backup
   - Restores your user data files
4. Refresh the page after restoration completes

---

## Deleting Backups

Click **Delete** next to any backup to remove it and free up storage space.

---

## Best Practices

- **Regular backups** — create backups periodically, especially before updates
- **Download copies** — keep backup copies on your local device or external storage
- **Before migration** — always create a fresh backup before migrating your account
- **Check storage** — monitor backup size on the Dashboard's Storage Usage panel
