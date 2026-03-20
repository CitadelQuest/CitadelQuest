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

## CQ Profile

**Route**: `/settings/profile`

Manage your public identity and control what others see. See [CQ Profile](09-cq-profile.md) for the full guide.

### Public Profile Page
- **Enable/disable** your public profile at `/{username}`
- **Profile photo** — upload, remove
- **Bio** — personal description with Markdown/HTML support and character counter
- **Show/hide** photo, profile content groups, shared items list on public page
- **Spirit showcase** — Off / Primary Spirit only / All Spirits
- **Language** — choose language for the public profile page
- **Theme** — choose a background theme for your public page
- **Custom background image** — upload your own background
- **Background overlay** — dark overlay for text readability

### CQ Contact Visibility
- **Share bio** with contacts
- **Share photo** with contacts
- **Spirit showcase** — same 3-mode control for what contacts see

---

## Profile Content

**Route**: `/settings/share-groups`

Organize your shared items into content groups that appear as tabbed navigation on your public profile and in CQ Explorer. See [CQ Share — Share Groups](08-cq-share.md) for the full guide.

- **Create groups** with custom title, icon, color, and visibility
- **Add shares** to groups and reorder them
- **Override display styles** per group item
- Groups appear as tabs on your public profile page and in CQ Explorer

---

## My Feeds

**Route**: `/settings/cq-feed/my-feeds`

Manage your CQ Feed channels — the feeds where you publish posts. See [CQ Feed](14-cq-feed.md) for the full guide.

- **Create feeds** with title, URL slug, scope (Public or CQ Contacts), description, and optional cover image
- **Edit** existing feeds — change title, description, scope, or cover image
- **Toggle active/inactive** — temporarily disable a feed without deleting it
- **Delete feeds** — removes the feed and all its posts

> A default "General" feed is auto-created for every new user.

---

## Subscribed Feeds

**Route**: `/settings/cq-feed/subscribed`

Manage feeds you're subscribed to from other Citadels, grouped by contact:

- **Pause/Resume** — temporarily stop receiving posts from a specific feed
- **Unsubscribe** — permanently remove a feed subscription
- **Sync Subscriptions** — discover and subscribe to new feeds from contacts added after your initial subscription

Subscriptions are created automatically when you accept a friend request or follow a profile.

---

## Settings Navigation

The settings sidebar provides quick access to:
- **General Settings** — login, theme, database, migration
- **CQ Profile** — public profile page, contact visibility
- **Profile Content** — share groups for profile page
- **My Feeds** — manage your CQ Feed channels
- **Subscribed Feeds** — manage feed subscriptions from other Citadels
- **AI Services** — models, gateway, credits
