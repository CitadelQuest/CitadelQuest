# Settings

The Settings page lets you configure your CitadelQuest experience — from login credentials to AI model selection and account migration.

**Route**: `/settings`

---

## General Settings

### Login Information
- **Username** — displayed (read-only)
- **Change Email** — update your email address via modal dialog
- **Change Password** — update your password via modal dialog (requires current password)

### Theme
- **Background Theme** — switch between different visual background themes
- CitadelQuest uses a dark cyber-themed design with green (#95ec86) accent color

### Database
- **Database Size** — shows your personal database file size
- **Optimize Database** — runs SQLite VACUUM to reclaim unused space and improve performance

### Account Migration
Move your entire account to another CitadelQuest instance:

1. Select a **destination server** from your CQ Contacts (must be an admin on the destination)
2. Choose an existing **backup** or create a new one
3. **Confirm** with your password
4. The migration transfers your complete database, files, and settings
5. Your contacts are automatically notified of your new address

> **Important**: After migration, your account on the current server will be disabled.

> **Large accounts**: For accounts with lots of data (hundreds of MB), creating a backup first and selecting it during migration is recommended for reliability.

---

## AI Services

**Route**: `/settings/ai`

### AI Models
- **Primary AI Model** — the main model used for Spirit conversations
- **Secondary AI Model** — used for image generation and other specialized tasks
- **Update Models List** — sync available models from CQ AI Gateway

Click the model selector button to open the **AI Model Comparison** interface where you can browse all available models with details about:
- Provider and model name
- Capabilities (text, image, code)
- Context window size
- Pricing per token

### CQ AI Gateway Connection
- **API Key** — your CQ AI Gateway API key (auto-configured during registration)
- **Credits** — your current credit balance
- **Add Credits** — purchase additional credits
- **Gateway Dashboard** — seamless login to CQ AI Gateway web interface

---

## Settings Navigation

The settings sidebar provides quick access to:
- **General Settings** — login, theme, database, migration
- **AI Services** — models, gateway, credits
