# CQ Contacts — Cross-Citadel Connections

CQ Contacts is the friend system that connects users across different CitadelQuest instances. It establishes secure, direct communication channels between Citadels.

**Route**: `/cq-contact`

---

## Your Identity

Every CitadelQuest user has a unique identity based on their **domain + username**, e.g.:
- `https://one.citadelquest.world/Human`

Your identity is proved by your Citadel's internet domain ownership. Each user also has a **UUID** (unique identifier) for internal reference.

---

## Overview

The CQ Contacts page shows:
- **Your contacts** — list of connected users from other Citadels
- **Contact status** — online/offline, last seen
- **Pending requests** — incoming and outgoing friend requests
- **Search/filter** — find contacts quickly

---

## Adding a Contact

### Sending a Friend Request

1. Go to **CQ Contacts** (`/cq-contact`)
2. Click **Add Contact** or **Send Friend Request**
3. Enter the **Citadel URL** and **username** of the person you want to connect with
4. A friend request is sent to their Citadel

### Receiving a Friend Request

When someone sends you a friend request:
- A **notification** appears in your notification bell
- A **red badge** appears on the CQ Contacts card on the Dashboard
- Go to CQ Contacts to **accept** or **reject** the request

### What Happens on Accept

When a friend request is accepted:
- Both sides exchange **CQ Contact API keys**
- A secure HTTPS communication channel is established
- You can now send messages via CQ Chat
- The contact appears in both users' contact lists

---

## Contacts List

Each contact in the list shows:
- **Profile photo** — fetched from the contact's Citadel (or a default avatar if not shared)
- **Username** and **Citadel domain**
- **Status** — active connection or pending
- **Last interaction** timestamp

---

## Contact Detail Page

Click on any contact to open their detail page. The detail page shows rich profile information fetched from their Citadel via the federation protocol:

### Profile Card
- **Profile photo** — their photo (if they've chosen to share it)
- **Username and domain** — with a link to their Citadel
- **Bio** — their personal description (if shared)
- **Spirit showcase** — their AI Spirits with names, levels, XP, and colors (if shared)

What you see depends on the contact's **CQ Contact Visibility** settings — they control exactly what's shared with their contacts.

### Shared Items

Below the profile card, you'll see the contact's **shared items** — files and CQ Memory Packs they've shared with their CQ Contacts:

- **Memory Packs** — marked with a graph icon and "Memory Pack" badge
- **Files** — marked with a file icon and "File" badge
- Each item shows a **view count**

### Download to Citadel

Click on any shared item to **download it to your Citadel**:

- **Memory Packs** (`.cqmpack`) are downloaded into a dedicated contact library in your Memory Explorer, with automatic sync — if the contact updates the pack, your copy updates too
- **Files** are downloaded to your File Browser's downloads folder

See [CQ Share](08-cq-share.md) for more about sharing.

---

## How Federation Works

CQ Contacts is the foundation of CitadelQuest's decentralized communication:

1. **No central directory** — you connect directly with people you know
2. **CQ Contact API Keys** — each connection uses unique keys exchanged during the friend request process, securing all communication
3. **Direct HTTPS** — all communication goes directly between Citadels using the Federation protocol
4. **Privacy** — no third party can see your contacts or messages
5. **Rich profiles** — contacts exchange profile data (photo, bio, Spirits) based on individual visibility settings
6. **Content sharing** — share files and Memory Packs with contacts via [CQ Share](08-cq-share.md)

> **Account Migration**: If you move your account to a different Citadel, your contacts are automatically notified and updated with your new address.
