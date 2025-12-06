# CitadelQuest Docker Deployment

This directory contains everything needed to deploy CitadelQuest using Docker, optimized for Coolify deployment.

## Quick Start (Coolify)

1. **Create New Resource** in Coolify
2. Select **"Dockerfile"** under Docker Based
3. Point to this `docker/` directory
4. Configure environment variables (see below)
5. Deploy!

## Files

| File | Purpose |
|------|---------|
| `Dockerfile` | PHP 8.2 + Apache base image with required extensions |
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

## Persistent Data

The `citadelquest_app` volume persists the entire `/var/www/html/` directory:
- Complete application code (for in-app updates)
- `/var/main.db` - Main application database
- `/var/user_databases/` - Per-user SQLite databases
- `/var/user_backups/` - User backup files
- `/var/cache/` - Symfony cache
- `/var/log/` - Application logs

**Important**: This volume must persist across container restarts!

> **Note**: Each Coolify application runs in its own isolated container with its own `/var/www/html/`. 
> Coolify/Traefik handles routing by domain, so no port conflicts with other applications.

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
- Check Cloudflare SSL mode is set to "Full"

### Database errors
- Verify `/var/www/html/var` is writable
- Check SQLite extension is loaded: `php -m | grep sqlite`

## Architecture

```
┌─────────────────────────────────────────────────────┐
│                    Cloudflare                        │
│                  (SSL + CDN)                         │
└─────────────────────┬───────────────────────────────┘
                      │ HTTPS
┌─────────────────────▼───────────────────────────────┐
│                 Coolify/Traefik                      │
│              (Reverse Proxy + SSL)                   │
└─────────────────────┬───────────────────────────────┘
                      │ HTTP
┌─────────────────────▼───────────────────────────────┐
│              Docker Container                        │
│  ┌─────────────────────────────────────────────┐    │
│  │              Apache + PHP 8.2                │    │
│  │  ┌─────────────────────────────────────┐    │    │
│  │  │         CitadelQuest App            │    │    │
│  │  │  (Symfony 7.2 + SQLite)             │    │    │
│  │  └─────────────────────────────────────┘    │    │
│  └─────────────────────────────────────────────┘    │
│                       │                              │
│  ┌────────────────────▼────────────────────────┐    │
│  │           Persistent Volume                  │    │
│  │  /var/www/html/var (databases, logs, etc)   │    │
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
