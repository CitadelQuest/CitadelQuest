# CQ Share — Sharing Files & Knowledge

CQ Share lets you share files and CQ Memory Packs with your CQ Contacts or make them publicly accessible via a clean URL. It's the bridge between your personal data and the people you choose to share it with.

**Route**: `/share`

---

## Overview

CQ Share gives you two ways to share:

- **Public sharing** (scope: Public) — anyone with the link can access the shared item, no login required
- **CQ Contact sharing** (scope: CQ Contacts) — only your CQ Contacts can access, authenticated via their CQ Contact API keys

You can share two types of content:

| Type | Description |
|------|-------------|
| **Files** | Any file from your File Browser — images, documents, archives, etc. |
| **CQ Memory Packs** | Knowledge graphs (`.cqmpack` files) — shareable and auto-syncable |

---

## CQ Share Management Page

The Share management page shows all your shared items with:
- **Title** — display name for the shared item
- **Type** — File or Memory Pack
- **Scope** — Public or CQ Contacts
- **URL** — the share link (click to copy)
- **Views** — download/access counter
- **Status** — Active or Paused
- **Actions** — Edit, toggle active/paused, delete

---

## Creating a Share

### From File Browser

1. Select a file in the **File Browser**
2. Click the **Share** button in the file actions
3. Choose a **title** and **scope** (Public or CQ Contacts)
4. The share URL is generated automatically

### From Memory Explorer

1. Open **Memory Explorer** (`/memory`)
2. Select a Memory Pack
3. Use the pack management dropdown menu and click **Share**
4. Choose a **title** and **scope**

### From CQ Share Page

1. Go to **CQ Share** (`/share`)
2. Click **Create New Share**
3. Select the file or memory pack from your File Browser
4. Configure title, scope, and URL slug
5. Save — the share is now live

---

## Share URLs

Each shared item gets a clean, readable URL:

```
https://your-citadel.com/YourUsername/share/My-Shared-Document
```

- The URL slug is auto-generated from the title but can be customized
- URLs are unique per user
- Public shares can be accessed by anyone with the link
- CQ Contact shares require authentication

---

## Sharing Memory Packs

CQ Memory Pack sharing is especially powerful because of **auto-sync**:

### How It Works

1. You share a Memory Pack via CQ Share
2. A CQ Contact downloads it to their Citadel (see [CQ Contacts — Contact Detail](07-cq-contacts.md))
3. The downloaded pack remembers where it came from (the source URL)
4. Whenever the contact opens their Memory Explorer, the pack automatically checks for updates
5. If you've added new knowledge to the original pack, it syncs automatically

### Use Cases

- **Shared knowledge bases** — maintain a pack and let contacts always have the latest version
- **Team documentation** — share project knowledge that stays up to date
- **Learning materials** — share curated knowledge graphs with friends or students

---

## Managing Shares

### Editing a Share

Click the edit icon on any share to change:
- **Title** — the display name
- **URL slug** — the URL path
- **Scope** — switch between Public and CQ Contacts

### Pausing a Share

Toggle the **active/paused** status. Paused shares return a 404 — the content is not deleted, just temporarily hidden.

### Deleting a Share

Delete removes the share entry — the original file or memory pack is **not** deleted from your File Browser.

---

## Public Shares on Your Profile

If you have a **CQ Profile** public page enabled (see [CQ Profile](09-cq-profile.md)), your public shares (scope: Public) can be displayed on your profile page for visitors to browse and access.

---

## Viewing Contact Shares

When you visit a CQ Contact's detail page, you can see their shared items and download them to your Citadel. See [CQ Contacts — Contact Detail](07-cq-contacts.md) for details.

