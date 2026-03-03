# Installing Your Own CitadelQuest

Running your own Citadel means complete control over your data — your server, your rules, your privacy.

---

## Server Requirements

- **PHP** 8.4 or higher
- **Apache** with `mod_rewrite` enabled
- **SQLite3** PHP extension
- **HTTPS** required
- Standard PHP extensions: `mbstring`, `intl`, `json`, `zip`

CitadelQuest works on most shared web hosting plans, VPS, or dedicated servers.

---

## Installation — Zero-Click Installer

CitadelQuest features an incredibly simple installation process:

### Step 1: Download
Go to [GitHub Releases](https://github.com/CitadelQuest/CitadelQuest/releases) and download the **Installer Package** (`citadelquest-installer-*.zip`, ~8 KB).

### Step 2: Upload
Extract the ZIP and upload `install.php` to your web server's `/public` (sub)directory.

### Step 3: Run
Open `install.php` in your web browser (e.g., `https://yourdomain.com/install.php`).

The installer automatically:
- Downloads the latest pre-built release package
- Extracts all files
- Sets up the directory structure
- Cleans up after itself

### Step 4: Register
Visit your domain and register the first user account. This account automatically receives **Admin** privileges.

### Step 5: Onboard
Follow the onboarding wizard to connect CQ AI Gateway and create your first Spirit.

**That's it!** Your Citadel is ready.

---

## Alternative: Docker

A `Dockerfile` is available for containerized deployment. Build and run the container to deploy your Citadel in an isolated environment. See the `docker/README.md` in the repository for detailed instructions.

---

## Configuration

### Environment File (`.env`)

The `.env` file in your installation root contains key settings:
- `APP_ENV` — `prod` for production
- `APP_SECRET` — unique secret key for your installation
- `DATABASE_URL` — path to the main database (default: `sqlite:///%kernel.project_dir%/var/main.db`)

> The `.env` file is preserved during updates — your configuration won't be overwritten.

### Domain Setup

For the best experience:
1. Point your domain to the CitadelQuest installation directory
2. Ensure HTTPS is configured (Let's Encrypt provides free certificates)
3. Apache's `mod_rewrite` should route all requests through `public/index.php`
4. On Cloudflare use Full SSL mode!

---

## Updating

Updates are managed from the **Admin Dashboard**:

1. Go to **Administration** → check for updates
2. The updater downloads and applies the new version
3. A safety backup is created before every update
4. Database migrations run automatically
5. Cache is cleared and rebuilt

---

## PWA Support

CitadelQuest works as a **Progressive Web App** (PWA):
- Install it on your phone's home screen for an app-like experience
- Works on Android and iOS
- Desktop fullscreen mode available
- Offline-capable for cached content

---

## Multiple Users

A single CitadelQuest installation supports multiple users:
- Each user gets their own SQLite database
- Each user's data is completely isolated
- Admins can manage users, reset passwords, enable/disable registration
- Storage limits can be configured per Citadel

---

## CQ AI Gateway

To power your Spirits with AI, connect to CQ AI Gateway:
- **Automatic**: If you select "Auto-create CQ AI Gateway accounts" during user registration, accounts are created automatically
- **Manual**: Enter your API key in Settings → AI Services
- **Credits**: New users receive free starting credits; additional credits available via the Add Credits button

Visit [cqaigateway.com](https://cqaigateway.com) for more information.
