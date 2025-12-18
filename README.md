# CitadelQuest

A decentralized platform for AI-human, human-human collaboration with emphasis on personal data sovereignty and private communication.

<img width="75%" alt="CitadelQuest - Dashboard" src="https://github.com/user-attachments/assets/b625b4a7-c444-45f7-8c8c-99c92b6d7413" />


## New Installation
1. Download the [latest release](https://github.com/CitadelQuest/CitadelQuest/releases/latest) **Installation Package** (`citadelquest-installer-vX.Y.Z.zip`)
2. Upload `install.php` to your web server's public directory (`/myserver.com/cq/public/`)
3. Access `install.php` through your web browser (e.g. `http://cq.myserver.com/install.php`)
4. The installer will automatically handle everything!
5. After installation, create a new user (administrator) and login
6. Enjoy!

## Core Features

### Architecture
- Fully **decentralized**, **self-hosted** deployment, **your data stays yours** (not shared with tech-giants)
- One SQLite database per user
- **Updater** component for system updates
- Backup component for auto/manual backups
- **No third-parties monitoring and monetizing your privacy**
- No third-party cookies
- **Free - No ads**

### Connect with your friends
- **CitadelQuest Federation API/protocol** - direct server-to-server connection
- Connect with other CitadelQuest users
- Make friend requests - create **private CQ-CONTACT API key** for safe and secure communication
- **CQ Chat**: one-on-one/group real-time chat
- **No middlemen** - full privacy
- _soon: share data and files, collaborate on projects with other users and Spirits_

### Your Personal Cloud Storage
- CitadelQuest serves as your safe personal cloud storage
- File Browser GUI component (upload, download and manage files)
- _soon: file sharing with other users and Spirits_

### AI Spirits
- **Spirit** - AI agent that can be used to multi-language chat, work with files, generate images, _soon: collaborate on projects with other users and Spirits_
- Go through everyday tasks, long-term projects or experience digital adventures with your trusted personal AI companions, while maintaining data sovereignty
- **Spirit Memory** - spirit-managed memory storage (user, inner thoughts, conversations)
- **Spirit Tools**
  - file access and processing
  - text/image generation and processing

### AI Services
- [CQ AI Gateway](https://cqaigateway.com) for secure and private AI service use 
  - **Zero Data Retention (ZDR)** - no user data collected by AI service providers
  - data is just processed and send back to user, no data stored, no data logs
  - AI models curated by CitadelQuest to provide best quality, performance and security
  - **no subscription required** - pay-as-you-go
  - **free startup credits**
  - **auto-registration** (API key generation and setup) on CitadelQuest user registration **for easy start**
  - _soon: AI service providers integration - use your own AI models_

### Security
- HTTPS required (encryption for all communications)
- Secure user authentication with Symfony's password hasher and CSRF protection
- Per-user database isolation

### User Interface
- Clean, modern Bootstrap-based design
- Responsive layout for all devices
- PWA support - works as **fullscreen mobile app on Android and iOS**
- Dark theme optimized for readability
- Intuitive user registration and login
- Spirit-User/User-User Conversation/Chat interface
- File Browser interface
- Settings interface - manage user settings, AI model, etc.
- Administrators interface - manage users, system updates, etc.

### Internationalization
- Multi-language support for UI and Spirit communication
- Currently supported languages:
  - English (en) - default
  - Czech (cs)
  - Slovak (sk)

## Technical Stack

### Backend Requirements
- PHP 8.4 or higher
- Apache 2.4.63 or higher
- SQLite 3
- Composer 2.x
- SSL/TLS certificate (HTTPS required)

### Frontend Stack
- Symfony 7.3
- Bootstrap 5
- Webpack Encore
- Modern vanilla JavaScript

## Development Guidelines

### Code Structure
- Follow Symfony 7.3 best practices
- Maintain modular, single-responsibility components
- Document all major functionality

### Security Practices
- HTTPS required for all operations
- Implement encryption at rest
- Secure all API endpoints
- Validate all user input
- Follow OWASP security guidelines
- Keep secrets out of version control
- Implement proper CSRF protection

### Frontend Development
- Use modern vanilla JavaScript
- Follow Bootstrap conventions
- Maintain responsive design
- Optimize asset loading

### JavaScript Organization
- Structured directory layout:
  ```
  assets/
  ├── entries/           # Webpack entry points
  ├── js/
      ├── features/      # Feature-specific modules
      ├── shared/        # Reusable utilities
      └── ui/           # UI components
  ```
- One entry point per feature in `assets/entries/`
- Feature-specific code in dedicated directories under `features/`
- Shared utilities (crypto, translations) in `shared/`
- UI components in `ui/`
- Descriptive, purpose-indicating filenames
- Webpack-based bundling with code splitting

### Database
- Use migrations for schema changes
- Implement proper UUID handling
- Follow SQLite best practices
- Maintain data isolation

## Project Setup

1. Clone the repository:
```bash
git clone [repository-url]
cd CitadelQuest
```

2. Install PHP dependencies:
```bash
composer install
```

3. Install frontend dependencies:
```bash
npm install
```

4. Configure your environment:
   - Copy `.env` to `.env.local`
   - Generate a secure APP_SECRET:
   ```bash
   php -r 'echo bin2hex(random_bytes(32)) . PHP_EOL;'
   ```
   - Add the generated secret to `.env.local`:
   ```
   APP_SECRET=your_generated_secret
   ```
   - Configure your web server with SSL/TLS (HTTPS is required)
   - Update your `.env.local` to enforce HTTPS:
   ```
   TRUSTED_PROXIES=127.0.0.1
   TRUSTED_HOSTS=^localhost|example\.com$
   SECURE_SCHEME=https
   ```

5. Initialize the database:
```bash
php bin/console doctrine:database:create
chmod 666 var/main.db
php bin/console app:run-main-db-migrations
php bin/console app:update-user-databases
```

6. Build frontend assets:
```bash
npm run dev
```

7. Start the development server:
```bash
symfony server:start
```

## License

CitadelQuest is free software: you can redistribute it and/or modify it under the terms of the **GNU Affero General Public License v3.0** (AGPL-3.0) as published by the Free Software Foundation.

This program is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the GNU Affero General Public License for more details.

**Why AGPL-3.0?**
- Ensures all hosted versions remain open source
- Protects data sovereignty principles
- Requires sharing of modifications
- Guarantees freedom for all users

See the [LICENSE](LICENSE) file for the full license text.

Copyright (C) 2024-2025 CitadelQuest Development Team
