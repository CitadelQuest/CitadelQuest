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
| **Files** | Any file from your File Browser — images, documents, HTML pages, PDFs, archives, etc. |
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
- **Display style** — how the item is rendered (Full, Preview, or Hidden)
- **Description** — optional markdown text shown alongside the item
- **Actions** — Edit, toggle active/paused, delete

### Search & Filter

The management page includes:
- **Search** — filter shares by title
- **Type filter** — show only Memory Packs or Files
- **Status filter** — show only Active or Paused items

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
- Public shares can be accessed by anyone with the link — displayed inline by default (use `?download` to force download)
- CQ Contact shares require authentication

---

## Display Styles

Each share has a **display style** that controls how it appears on your profile and in CQ Explorer:

| Style | Description |
|-------|-------------|
| **Full** | Content is displayed inline — images shown full-size, HTML rendered, Memory Pack graphs visualized |
| **Preview** | Compact preview — image thumbnails, text excerpts |
| **Hidden** | Item is listed but content is not previewed |

Display style can be set per-share and optionally overridden at the group level (see Share Groups below).

---

## Share Descriptions

Each share can have an optional **description** written in Markdown. The description appears alongside the shared content on your profile and in CQ Explorer.

You can also control the **description position** relative to the content:
- **Above** — description appears above the content
- **Below** — description appears below the content
- **Left** — description on the left, content on the right
- **Right** — description on the right, content on the left

---

## Share Groups (Profile Content)

Share Groups let you organize your shared items into **content groups** — named, ordered collections that appear as tabbed navigation on your public profile and in CQ Explorer.

**Route**: `/settings/share-groups` (in Settings sidebar as **Profile Content**)

### Creating a Group

1. Go to **Settings** → **Profile Content**
2. Click **+ New Group**
3. Configure:
   - **Group Title** — name shown in the tab navigation (e.g. "Introduction", "Projects")
   - **Icon** — choose an icon for the group
   - **Icon Color** — pick a color for the icon
   - **URL Slug** — URL-friendly identifier
   - **Visibility** — Public, CQ Contacts, or specific contacts
   - **Active** — toggle the group on/off
   - **Show header** — toggle whether the group title is shown as a header
4. Click **Save**

### Adding Shares to a Group

1. Open a group in Profile Content settings
2. Click **+** to add existing shares
3. Select from your active shares
4. Drag to reorder items within the group

### Group Display Controls

Each item within a group can have its display style overridden:
- **From share** (inherit) — uses the share's own display style
- **Full** / **Preview** / **Hidden** — override for this group only

### How Groups Appear

- **Public profile page**: Groups appear as tabbed navigation below the profile header. Visitors click tabs to browse different content sections.
- **CQ Explorer**: When exploring a profile, groups appear as tabs in the main content area with the group's icon and title.

---

## Sharing Memory Packs

CQ Memory Pack sharing is especially powerful because of **auto-sync**:

### How It Works

1. You share a Memory Pack via CQ Share
2. A CQ Contact downloads it to their Citadel (see [CQ Explorer — Download to Citadel](07-cq-contacts.md))
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
- **Display style** — Full, Preview, or Hidden
- **Description** — optional markdown text
- **Description position** — Above, Below, Left, or Right

### Pausing a Share

Toggle the **active/paused** status. Paused shares return a 404 — the content is not deleted, just temporarily hidden.

### Deleting a Share

Delete removes the share entry — the original file or memory pack is **not** deleted from your File Browser.

---

## Public Shares on Your Profile

If you have a **CQ Profile** public page enabled (see [CQ Profile](09-cq-profile.md)), your public shares can be displayed on your profile page in two ways:

1. **Share Groups** — organized as tabbed content sections (recommended)
2. **Shared items list** — a flat list of all public shares (toggle in profile settings)

Both can be enabled independently via profile visibility settings.

---

## Viewing Shares in CQ Explorer

When you explore any CQ Profile in CQ Explorer, you can see their public shared items and content groups. You can download files and Memory Packs directly to your Citadel. See [CQ Explorer](07-cq-contacts.md) for details.

