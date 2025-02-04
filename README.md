# CitadelQuest

A decentralized platform for AI-human collaboration with emphasis on personal data sovereignty. Built with modern web technologies and a security-first approach.

## Technical Stack

### Backend Requirements
- PHP 8.2 or higher
- Apache 2.4.63 or higher
- SQLite 3
- Composer 2.x

### Frontend Stack
- Symfony 7.2
- Bootstrap 5
- Webpack Encore
- Modern vanilla JavaScript

## Project Setup

1. Clone the repository:
```bash
git clone [repository-url]
cd citadel
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

5. Initialize the database:
```bash
php bin/console doctrine:database:create
php bin/console doctrine:migrations:migrate
```

6. Build frontend assets:
```bash
npm run dev
```

7. Start the development server:
```bash
symfony server:start
```

## Core Features

### Architecture
- Fully decentralized, self-hosted deployment
- One SQLite database per user (not per Citadel)
- UUID-based identification system
- No external service dependencies

### Security
- End-to-end encryption for all communications
- Secure user authentication system
- Per-user database isolation
- Environment-based configuration

### User Interface
- Clean, modern Bootstrap-based design
- Responsive layout for all devices
- Dark theme optimized for readability
- Intuitive user registration and login

## Security Implementation

### Encryption
- Browser-generated RSA-OAEP 2048-bit keys
- End-to-end encryption using Web Crypto API
- Private keys never leave browser
- Message integrity verification
- Protection against replay attacks

### Data Protection
- Individual SQLite databases per user
- UUID-based identification (no sequential IDs)
- Secure password hashing
- CSRF protection
- Environment-specific secrets management

## Development Guidelines

### Code Structure
- Follow Symfony 7.2 best practices
- Maintain modular, single-responsibility components
- Document all major functionality
- Write unit tests for critical features

### Security Practices
- Implement encryption at rest
- Secure all API endpoints
- Validate all user input
- Follow OWASP security guidelines
- Keep secrets out of version control

### Frontend Development
- Use modern vanilla JavaScript
- Follow Bootstrap conventions
- Maintain responsive design
- Optimize asset loading

### Database
- Use migrations for schema changes
- Implement proper UUID handling
- Follow SQLite best practices
- Maintain data isolation

## License

This project is proprietary software. All rights reserved.
