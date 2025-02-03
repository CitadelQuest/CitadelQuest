# CitadelQuest

A decentralized platform for AI-human collaboration with emphasis on personal data sovereignty.

## Technical Requirements

- PHP 8.2 or higher
- Apache 2.4.63 or higher
- SQLite 3
- Composer

## Project Setup

1. Clone the repository
2. Install dependencies:
```bash
composer install
```

3. Configure your environment:
   - Copy `.env` to `.env.local`
   - Adjust settings in `.env.local` as needed

4. Initialize the database:
```bash
php bin/console doctrine:database:create
php bin/console doctrine:migrations:migrate
```

5. Start the development server:
```bash
symfony server:start
```

## Core Features

- Fully decentralized, self-hosted deployment
- One database per user (not per Citadel)
- End-to-end encryption for all communications
- Simple deployment process
- No external service dependencies

## Security

- Browser-generated RSA-OAEP 2048-bit keys
- End-to-end encryption using Web Crypto API
- Private keys never leave browser
- Message integrity verification
- Protection against replay attacks

## Development Guidelines

1. Code Structure:
   - Follow Symfony best practices
   - Keep code modular and maintainable
   - Document all major components
   - Write unit tests for critical functionality

2. Security:
   - Implement encryption at rest
   - Secure all API endpoints
   - Validate all user input
   - Follow OWASP security guidelines

## License

This project is proprietary software. All rights reserved.
