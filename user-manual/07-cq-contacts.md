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

## Contact Information

Each contact shows:
- **Username** and **Citadel domain**
- **Status** — active connection or pending
- **Last interaction** timestamp

---

## How Federation Works

CQ Contacts is the foundation of CitadelQuest's decentralized communication:

1. **No central directory** — you connect directly with people you know
2. **CQ Contact API Keys** — each connection uses unique keys exchanged during the friend request process, securing all communication
3. **Direct HTTPS** — all communication goes directly between Citadels using the Federation protocol
4. **Privacy** — no third party can see your contacts or messages
5. **Future-ready** — the CQ Contact system is the foundation for upcoming features like CQ Share (file/memory sharing)

> **Account Migration**: If you move your account to a different Citadel, your contacts are automatically notified and updated with your new address.
