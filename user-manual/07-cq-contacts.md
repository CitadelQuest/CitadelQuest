# CQ Explorer — Profiles, Contacts & Following

CQ Explorer is the unified hub for exploring CQ Profiles, managing your CQ Contacts, and following users across Citadels. It combines profile browsing, contact management, and content discovery in a single two-column layout.

**Route**: `/cq-contacts`

---

## Layout

CQ Explorer uses a two-column layout:

| Main Panel (75%) | Sidebar (25%) |
|-------------------|---------------|
| URL input + **Explore** button | **CQ Contacts** list |
| Profile preview with content | **Following** list |
| | **Followers** list |

---

## Exploring a Profile

1. Enter a CitadelQuest profile URL in the input field (e.g. `https://one.citadelquest.world/Human`)
2. Click **Explore** (or press Enter)
3. The profile is fetched and displayed in the main panel

### What You See

- **Profile photo** — displayed as a thumbnail
- **Username and domain** — with a link to their public profile page
- **Bio** — with "show more" toggle for long bios (600+ characters)
- **Spirit showcase** — their AI Spirits with names, levels, XP, and colors
- **Follower count** — how many users follow this profile
- **Content groups** — tabbed navigation for their public CQ Share groups (e.g. "Introduction", "Projects")
- **Shared items** — publicly shared files and CQ Memory Packs with content previews

### What You Can Do

- **Add Contact** — send a friend request directly from the Explorer
- **Follow / Unfollow** — subscribe to their content updates (see CQ Follow below)
- **Download shared items** — download files and Memory Packs to your Citadel
- **Add to Library** — add Memory Packs to your CQ Memory libraries
- **View Memory Packs** — preview Memory Pack graphs inline

> The Explorer remembers the last profile you viewed (via localStorage) and auto-loads it on your next visit.

---

## Sidebar

The right sidebar consolidates three lists, each in a collapsible glass-panel section:

### CQ Contacts

Your friend connections from other Citadels. Shows:
- **Profile photo**, **username**, and **domain** for each contact
- **Count badge** (green) with total contacts
- Click any contact → loads their profile in the main panel (no page reload)

### Following

CQ Profiles you've subscribed to. Shows:
- **Profile photo**, **username**, and **domain**
- **Count badge** (yellow) with total follows
- **Yellow highlight** on profiles with new content since your last visit
- **Yellow dot** badge on items with unseen updates
- Click → loads profile and marks as visited

### Followers

Users who follow you. Shows:
- **Profile photo**, **username**, and **domain**
- **Count badge** (blue) with total followers
- Click → loads their profile in the main panel

### Sidebar Features

- **Collapsible sections** — click any section header to collapse/expand. State is saved to localStorage.
- **Active item highlighting** — the currently explored profile is highlighted with a cyber-colored left border accent
- **SPA-like navigation** — clicking any sidebar item loads the profile instantly without page reload

---

## CQ Follow

CQ Follow is a one-way, lightweight subscription to any public CQ Profile. It lets you stay informed about new content — without requiring a mutual CQ Contact (friend) relationship.

### Following a Profile

1. Explore any CQ Profile in CQ Explorer
2. Click the **Follow** button (next to Add Contact)
3. The button changes to a check icon — you're now following
4. The profile appears in your **Following** sidebar list

### Unfollowing

1. Explore a profile you're following
2. Click the **Following** button → confirms unfollow
3. The profile is removed from your Following list

### New Content Notifications

When profiles you follow publish new content:
- **Sidebar**: Following items get a yellow background and dot badge
- **Explorer content**: New shares/groups are highlighted with yellow borders
- **Dashboard**: The CQ Explorer tile shows a yellow badge with the count of profiles that have new content
- **Automatic detection**: When you open CQ Explorer, it checks all followed profiles for updates

### How It Works

- Following is **completely separate** from CQ Contacts — you can follow anyone with a public profile
- Both sides are aware: the followed user's Citadel receives a notification and records you as a follower
- Your **follower count** is visible on your public profile page
- Following uses the federation protocol for cross-Citadel communication

---

## CQ Contacts (Friend System)

CQ Contacts is the friend system connecting users across different Citadels. Unlike CQ Follow (one-way), CQ Contacts establishes a **mutual, secure connection**.

### Your Identity

Every CitadelQuest user has a unique identity based on their **domain + username**, e.g.:
- `https://one.citadelquest.world/Human`

Your identity is proved by your Citadel's internet domain ownership.

### Adding a Contact

#### Sending a Friend Request

1. Explore a profile in CQ Explorer
2. Click **Add Contact**
3. A friend request is sent to their Citadel

#### Receiving a Friend Request

When someone sends you a friend request:
- A **notification** appears in your notification bell
- A **red badge** appears on the CQ Explorer card on the Dashboard
- Go to CQ Explorer to **accept** or **reject** the request

#### What Happens on Accept

When a friend request is accepted:
- Both sides exchange **CQ Contact API keys**
- A secure HTTPS communication channel is established
- You can now send messages via CQ Chat
- The contact appears in both users' sidebar contact lists

### Contact Detail Page

Click on any contact in the sidebar to explore their profile. CQ Contacts see additional information based on CQ Contact Visibility settings:

- **Profile photo** — their photo (if they've chosen to share it)
- **Username and domain** — with a link to their Citadel
- **Bio** — their personal description (if shared)
- **Spirit showcase** — their AI Spirits with names, levels, XP, and colors (if shared)
- **Shared items** — files and CQ Memory Packs shared with CQ Contacts scope

### Download to Citadel

Click on any shared item to **download it to your Citadel**:

- **Memory Packs** (`.cqmpack`) are downloaded into a dedicated contact library in your Memory Explorer, with automatic sync — if the contact updates the pack, your copy updates too
- **Files** are downloaded to your File Browser's downloads folder

See [CQ Share](08-cq-share.md) for more about sharing.

---

## How Federation Works

CQ Explorer relies on CitadelQuest's decentralized federation protocol:

1. **No central directory** — you connect directly with people you know
2. **CQ Contact API Keys** — each friendship uses unique keys exchanged during the friend request process, securing all communication
3. **Direct HTTPS** — all communication goes directly between Citadels
4. **Privacy** — no third party can see your contacts or messages
5. **Rich profiles** — contacts exchange profile data (photo, bio, Spirits) based on individual visibility settings
6. **Content sharing** — share files and Memory Packs with contacts via [CQ Share](08-cq-share.md)
7. **Follow notifications** — follow/unfollow actions are communicated via federation with fraud prevention (origin/host validation)

> **Account Migration**: If you move your account to a different Citadel, your contacts and followers are automatically notified and updated with your new address.
