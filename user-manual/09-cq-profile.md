# CQ Profile — Your Public Identity

CQ Profile gives you a beautiful public profile page on your Citadel — a personal landing page where visitors can see who you are, meet your Spirits, and browse your shared content. You control exactly what's visible.

**Route**: `/settings/profile` (settings) | `/{username}` (public page)

---

## Your Public Profile Page

When enabled, your profile is accessible at:

```
https://your-citadel.com/YourUsername
```

For example:
- `https://one.citadelquest.world/Human`
- `https://bad.bois.quest/JuRaj`

The page displays:
- **Profile photo** — your uploaded photo (or a default avatar)
- **Username and domain** — your CitadelQuest identity with a copyable URL link
- **Bio** — your personal description with full Markdown and HTML support
- **Spirit showcase** — your AI Spirit companions with their names, levels, and XP
- **Follower count** — how many users follow your profile
- **Profile Content** — tabbed content groups with your shared items organized by topic (see [CQ Share — Share Groups](08-cq-share.md))
- **Shared items list** — optional flat list of all public CQ Share items
- **Background theme** — a customizable visual theme or custom background image
- **Footer** — "Powered by CitadelQuest" with version info

The page is clean, calm, and ad-free — just your identity, your way.

> **Tip**: A Citadel admin can configure the **Homepage Redirect** to point the root URL (`https://your-citadel.com`) to any user's public profile page. See [Administration](12-administration.md).

---

## Profile Settings

**Route**: `/settings/profile`

Access profile settings from the user menu → **CQ Profile**, or from **Settings** → **CQ Profile**.

The settings page has two tabs:

### Public Profile Page Tab

Controls what visitors see on your public profile:

- **Enable/Disable** — toggle your public profile on or off
- **Profile URL** — your shareable link with a copy button
- **Show profile photo** — toggle photo visibility on the public page
- **Show CQ Profile Content (content groups)** — toggle display of your Share Groups as tabbed content sections
- **Show shared items list** — toggle display of a flat list of all public CQ Share items
- **Spirit showcase** — choose how your Spirits appear:
  - **Off** — no Spirits shown
  - **Primary Spirit only** — shows just your main Spirit (without star icon)
  - **All Spirits** — shows all your Spirits (primary marked with a star when you have multiple)
- **Language** — choose the language for your public profile page (English, Czech, or Slovak), independent of the visitor's language preference
- **Page Theme** — choose a background theme with visual previews:
  - **Default** — uses the visitor's current theme
  - **CitadelQuest** — the signature CQ cityscape
  - **Night Forest** — dark enchanted forest
  - **Dreamy Flowers** — ethereal floral landscape
  - **Clear** — clean minimal look
- **Custom Background Image** — upload your own background image (JPEG, PNG, or WebP, max 5MB, recommended 1920×1080+). Overrides the selected theme.
- **Background Overlay** — toggle a dark overlay on top of the background for better text readability

### CQ Contact Visibility Tab

Controls what your CQ Contacts can see about you (via the federation protocol):

- **Share bio** — allow contacts to see your bio
- **Share photo** — allow contacts to see your profile photo
- **Spirit showcase** — same 3-mode control as the public page (Off / Primary only / All)

---

## Profile Photo

### Uploading

1. Go to **CQ Profile** settings (`/settings/profile`)
2. Click **Upload Photo**
3. Select an image from your device (JPEG, PNG, WebP, or GIF — max 5MB)
4. The photo is saved and immediately visible

### Where It Appears

Your profile photo shows up in several places:
- **Your public profile page** (if enabled)
- **CQ Explorer sidebar** — your contacts and followers see your photo
- **CQ Chat** — your photo appears next to your messages in conversations

### Removing

Click **Remove** to delete your profile photo. A default avatar icon will be shown instead.

---

## Bio

Write a description about yourself in the **Bio** field. Supports **Markdown** and **HTML** for rich formatting — links, lists, bold, code, etc. A character counter is displayed below the field.

Your bio appears on:
- Your **public profile page** (if enabled) — rendered as formatted HTML
- **CQ Explorer** — when others explore your profile
- The **CQ Contact** view when contacts browse your profile

---

## Spirit Showcase

The Spirit showcase displays your AI Spirit companions with:
- **Ghost icon** in the Spirit's color
- **Spirit name**
- **Level and XP** — showing their progression
- **Star icon** — marks the primary Spirit (only when showing all Spirits and you have more than one)

On desktop, Spirits appear on the right side of the profile header. On mobile, they appear below the header in a compact layout.

Your CQ Contacts also see your Spirits in CQ Explorer and on your public page, based on the visibility settings.

---

## Profile Content (Share Groups)

Profile Content organizes your shared items into content groups that appear as **tabbed navigation** on your public profile page and in CQ Explorer.

**Route**: `/settings/share-groups` (in Settings sidebar as **Profile Content**)

For example, a profile might have tabs like "Introduction", "Projects", "Gallery" — each containing different shared files and Memory Packs.

See [CQ Share — Share Groups](08-cq-share.md) for full details on creating and managing content groups.

---

## Themes & Backgrounds

### Built-in Themes

Choose from 5 visual themes in the settings, each shown as a thumbnail preview:
- **Default** — uses the visitor's current theme
- **CitadelQuest** — the signature CQ cityscape
- **Night Forest** — dark enchanted forest
- **Dreamy Flowers** — ethereal floral landscape
- **Clear** — clean minimal look

### Custom Background Image

Upload your own background image to personalize your profile:
1. Go to profile settings → **Custom Background Image**
2. Click **Upload Image**
3. Select a JPEG, PNG, or WebP image (max 5MB, recommended 1920×1080 or larger)
4. The custom image overrides the selected theme

### Background Overlay

Toggle the **Background Overlay** switch to add a dark semi-transparent layer on top of your background. This improves text readability, especially with bright or busy custom images.

The theme you choose is independent of the visitor's personal theme — it only affects how your public page looks.

---

## How Others See You

### Visitors (Public)
Anyone who visits `https://your-citadel.com/YourUsername` sees your public profile page with the settings you've configured. No login required. The page language is set by your **Language** setting, not the visitor's preference.

### CQ Explorer
When other CitadelQuest users explore your profile URL in CQ Explorer, they see your profile card, content groups, shared items, and can follow you or send a friend request.

### CQ Contacts (Federation)
Your CQ Contacts see additional information based on your CQ Contact Visibility settings. Profile data (bio, photo, Spirits) is fetched via the secure federation protocol.

### Followers
Users who follow you can see your public content and receive notifications when you publish new items. Your follower count is displayed on your profile.

### Not Enabled
If you haven't enabled the public profile page, visitors to your URL will see a 404 page. CQ Contacts can still see your federation profile data based on your visibility settings.

