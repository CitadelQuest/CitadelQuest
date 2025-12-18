# CitadelQuest Docker Deployment

This directory contains everything needed to deploy CitadelQuest using Docker, optimized for Coolify deployment.

## Quick Start (Coolify)

1. **Create New Resource** in Coolify → Select destination (same network as other apps)
2. Select **"Public Repository"** → Enter `https://github.com/CitadelQuest/CitadelQuest`
3. Set **Branch**: `main`, **Build Pack**: `Dockerfile`, **Base Directory**: `/docker`
4. Set **Port**: `80`
5. Configure **Domain** with `https://` prefix (e.g., `https://your-domain.com`)
6. **⚠️ IMPORTANT**: Go to **Persistent Storage** → Add volume:
   - Volume Name: *(auto-generated is fine)*
   - Destination Path: `/var/www/html`
7. Configure **Healthcheck** (see below)
8. Deploy!

## Files

| File | Purpose |
|------|---------|
| `Dockerfile` | PHP 8.4 + Apache base image with required extensions |
| `docker-compose.yaml` | Coolify-compatible compose configuration |
| `apache.conf` | Symfony-optimized Apache virtual host |
| `entrypoint.sh` | Container startup script with auto-installation |

## How It Works

1. **First Run**: The entrypoint script detects CitadelQuest isn't installed
2. **Auto-Install**: Downloads the pre-built release from GitHub
3. **Setup**: Extracts files, configures environment, sets permissions
4. **Ready**: Apache starts serving the application

On subsequent runs, it skips installation and just starts Apache.

## Environment Variables

### Required for Coolify

```env
# Domain configuration (Coolify usually handles this)
TRUSTED_HOSTS=^(localhost|your-domain\.com)$
```

### Optional

```env
# Auto-generated if not provided
APP_SECRET=your-32-character-secret

# Debug mode (keep false in production)
APP_DEBUG=0
```

## Persistent Storage (CRITICAL!)

**⚠️ You MUST configure this in Coolify BEFORE first deploy, or data will be lost on redeploy!**

Go to **Persistent Storage** in Coolify and add:

| Volume Name | Source Path     | Destination Path |
|-------------|-----------------|------------------|
| *(auto)*    | *(leave empty)* | `/var/www/html`  |

This persists the entire application directory:
- Complete application code (for in-app updates)
- `/var/main.db` - Main application database
- `/var/user_databases/` - Per-user SQLite databases
- `/var/user_backups/` - User backup files
- `/var/cache/` - Symfony cache
- `/var/log/` - Application logs

> **Note**: Each Coolify application runs in its own isolated container. Coolify/Traefik handles routing by domain, so no port conflicts with other applications.

## Healthcheck Configuration

In Coolify → **Healthcheck** settings:

| Setting         | Value       | Notes 
|-----------------|-------------|-------
| Method          | `GET`       |
| Scheme          | `http`      | Internal check, not HTTPS
| Host            | `localhost` |
| Port            | `80`        |
| Path            | `/`         |
| **Return Code** | `301`       | ⚠️ Not 200! (redirects to /login)
| Interval        | `60`        | seconds
| Timeout         | `5`         | seconds
| Retries         | `10`        |
| Start Period    | `12`        | seconds (allow time for first install)

> **Why 301?** CitadelQuest redirects `/` → `/login` for unauthenticated users, returning 301.

## Updating CitadelQuest

### In-App Update
1. Use CitadelQuest's built-in update feature
2. The update persists in the volume

## Local Testing

```bash
# Build and run locally
cd docker/
docker-compose up --build

# Access at http://localhost
```

## Troubleshooting

### Container won't start
- Check logs: `docker logs <container_id>`
- Verify GitHub release URL is accessible
- Ensure volume permissions are correct

### HTTPS not working
- Coolify/Traefik handles SSL termination
- Ensure `TRUSTED_PROXIES` includes your proxy IPs
- Check Cloudflare SSL mode is set to **"Full"** (not "Flexible"!)
- Domain in Coolify must include `https://` prefix

### Data lost after redeploy
- **Persistent Storage was not configured!**
- Always add volume `/var/www/html` BEFORE first deploy
- Without it, each redeploy creates fresh container with no data

### Database errors
- Verify `/var/www/html/var` is writable
- Check SQLite extension is loaded: `php -m | grep sqlite`

## Architecture

```
┌─────────────────────────────────────────────────────┐
│                  Cloudflare                         │
│                  (SSL + CDN)                        │
└─────────────────────┬───────────────────────────────┘
                      │ HTTPS
┌─────────────────────▼───────────────────────────────┐
│                 Coolify/Traefik                     │
│              (Reverse Proxy + SSL)                  │
└─────────────────────┬───────────────────────────────┘
                      │ HTTP
┌─────────────────────▼───────────────────────────────┐
│               Docker Container                      │
│  ┌─────────────────────────────────────────────┐    │
│  │              Apache + PHP 8.4               │    │
│  │  ┌─────────────────────────────────────┐    │    │
│  │  │         CitadelQuest App            │    │    │
│  │  │      (Symfony 7.3 + SQLite)         │    │    │
│  │  └─────────────────────────────────────┘    │    │
│  └────────────────────┬────────────────────────┘    │
│                       │                             │
│  ┌────────────────────▼────────────────────────┐    │
│  │           Persistent Volume                 │    │
│  │            (/var/www/html)                  │    │
│  └─────────────────────────────────────────────┘    │
└─────────────────────────────────────────────────────┘
```

## Version Management

The entrypoint script automatically detects the **latest version** from GitHub releases:

```
https://github.com/CitadelQuest/CitadelQuest/releases/latest
  → redirects to → /releases/tag/v0.5.12-beta
  → extracts version → v0.5.12-beta
```

**No manual version management needed!** 🎉

On first container start, it always installs the latest available release. For updates, use CitadelQuest's built-in update feature.
