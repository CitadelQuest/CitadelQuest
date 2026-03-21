# [CitadelQuest](https://www.citadelquest.com/)

### Free, Open-Source, Self-Hosted Platform for Personal Data Sovereignty and AI-Human Collaboration

---

## Your Digital Fortress

CitadelQuest is your own personal digital fortress — a **Citadel** — where your data, your AI companions, your communication, and your digital life are fully under your control. No Big Tech. No ads. No tracking. No data collection. Just you and your Citadel.

Every CitadelQuest installation is independent and self-hosted. Your personal data lives in your own database on your own server — technologically impossible for anyone else to exploit. Connect with friends across Citadels via direct, encrypted federation — no corporate servers in between.

---

## What You Get

### Personal Space

- **AI Spirits** — Personal AI companions with memory, personality, and tools. Chat naturally, and your Spirit reads files, generates images, manages knowledge, and remembers everything. Multiple AI models to choose from (Claude, Gemini, Grok, and more). Powered by CQ AI Gateway with fair pay-per-use pricing and free starting credits.

- **CQ Memory** — Interactive 3D knowledge graphs. Extract structured knowledge from documents, web pages, and conversations. Your Spirit uses these graphs as persistent memory — context that carries across every conversation. Share knowledge graphs with friends.

- **File Browser** — Your personal cloud. Upload, organize, and manage files on your server. Your Spirit can read, create, and edit files for you. Share files publicly or with contacts.

### Social Network

- **CQ Profile** — Your public identity on the decentralized web. Custom profile page with bio, Spirit showcase, content groups, themes, and background images. Your Citadel's domain is your identity — e.g. `https://one.citadelquest.world/Human`.

- **CQ Explorer** — Discover and connect with people across Citadels. Browse profiles, send friend requests, follow users, and manage your social connections — all in one place.

- **CQ Feed** — A decentralized social feed. Publish posts, react with likes, and comment — just like a classic social network, but your content lives on your server and travels peer-to-peer via federation. Markdown support, multiple feeds with different audiences, real-time updates.

- **CQ Share** — Share files and knowledge graphs with contacts or publicly. Organize shared content into groups that appear as tabbed navigation on your profile — effectively turning your Citadel into a personal website.

- **CQ Chat** — Private, encrypted messaging across Citadels. Direct peer-to-peer communication secured with CQ Contact API keys. Group chats supported. Messages stored only on sender's and receiver's Citadels.

- **CQ Follow** — One-way subscription to any public profile. Stay informed about new content without requiring a mutual friendship. New content notifications with real-time background polling.

### Infrastructure

- **Zero-Click Installer** — Download the 8 KB installer, upload to your server, open in browser. Done. Works on most shared hosting, VPS, or dedicated servers.

- **Built-in Updates** — One-click updates from the admin panel with automatic safety backups.

- **Backup & Restore** — Full backup and restore of your database and files. Chunked upload for large backups. Account migration between Citadels with automatic contact notification.

- **Progressive Web App** — Install CitadelQuest on your phone's home screen for an app-like experience. Works on Android, iOS, and desktop.

- **Multi-language** — English, Español, Čeština, Italiano, Magyar, Norsk, Polski, Slovenčina

---

## Architecture

| Principle | Implementation |
|-----------|---------------|
| **One database per user** | Each user has their own SQLite database — complete data isolation |
| **Decentralized** | Each Citadel is independent. No central authority, no single point of failure |
| **Direct communication** | Citadel-to-Citadel HTTPS federation with CQ Contact API key authentication |
| **Open source** | Full source code on GitHub, auditable by anyone |
| **Self-hosted** | Your server, your rules, your data |
| **No dependencies** | No external services required (AI services optional via CQ AI Gateway) |

### Technology

- **Backend**: Symfony 7.4, PHP 8.4, Apache
- **Database**: SQLite (one file per user)
- **Frontend**: Modern vanilla JavaScript, Bootstrap CSS, Three.js (3D visualizations)
- **AI**: CQ AI Gateway — access to Claude, Gemini, Grok, and many models with zero data retention

---

## The Vision

CitadelQuest is born from a simple belief: **your data is yours**.

In a world where Big Tech corporations harvest personal data, monitor communications, and sell attention — CitadelQuest offers a real alternative. Not just privacy settings and promises, but a fundamentally different architecture where personal data exploitation is technologically impossible.

But CitadelQuest is more than a privacy tool. It's a platform for **Human-AI collaboration** — where AI Spirits are companions, not corporate tools. They remember your conversations, manage your knowledge, and grow alongside you. Your Spirit works for you, not for a corporation.

And it's a foundation for a **new kind of social network** — decentralized, ad-free, where you own your content and connect directly with people you care about. No algorithm deciding what you see. No ads interrupting your feed. No corporate entity profiting from your relationships.

**Your Citadel. Your data. Your rules.**

---

## Get Started

### Try It Now
**[one.citadelquest.world](https://one.citadelquest.world)** — free public instance, register and start exploring

### Install Your Own
**[GitHub Releases](https://github.com/CitadelQuest/CitadelQuest/releases)** — download the 8 KB installer and set up your Citadel in minutes

### Learn More
- **Website**: [www.citadelquest.com](https://www.citadelquest.com)
- **GitHub**: [github.com/CitadelQuest/CitadelQuest](https://github.com/CitadelQuest/CitadelQuest)
- **X**: [@H_CitadelQuest](https://x.com/H_CitadelQuest)
- **Contact**: info@citadelquest.email

### Support Development
CitadelQuest is free and open source forever. Development is supported through **CQ AI Gateway** — fair-priced AI services that power your Spirits. Visit [cqaigateway.com](https://cqaigateway.com).

---

*Made with love by Human and AI — CitadelQuest Dev Team*
*Open Source. Free Forever. Privacy First.*
