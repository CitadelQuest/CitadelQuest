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
- **Username and domain** — your CitadelQuest identity
- **Bio** — a short description about yourself
- **Spirit showcase** — your AI Spirit companions with their names, levels, and XP
- **Shared items** — your public CQ Share items (files and Memory Packs)
- **Background theme** — a customizable visual theme for the page
- **Footer** — "Powered by CitadelQuest" with a link

The page is clean, calm, and ad-free — just your identity, your way.

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
- **Show shared items** — toggle display of your public CQ Share items
- **Spirit showcase** — choose how your Spirits appear:
  - **Off** — no Spirits shown
  - **Primary Spirit only** — shows just your main Spirit (without star icon)
  - **All Spirits** — shows all your Spirits (primary marked with a star when you have multiple)
- **Page Theme** — choose a background theme with visual previews:
  - **Default** — uses the visitor's current theme
  - **CitadelQuest** — the signature CQ cityscape
  - **Night Forest** — dark enchanted forest
  - **Dreamy Flowers** — ethereal floral landscape
  - **Clear** — clean minimal look

### CQ Contact Visibility Tab

Controls what your CQ Contacts can see about you (via the federation protocol):

- **Share bio** — allow contacts to see your bio on the Contact Detail page
- **Share photo** — allow contacts to see your profile photo
- **Spirit showcase** — same 3-mode control as the public page (Off / Primary only / All)

---

## Profile Photo

### Uploading

1. Go to **CQ Profile** settings (`/settings/profile`)
2. Click the photo area or **Upload Photo** button
3. Select an image from your device (JPEG, PNG, WebP, or GIF — max 5MB)
4. The photo is saved and immediately visible

### Where It Appears

Your profile photo shows up in several places:
- **Your public profile page** (if enabled)
- **CQ Contacts** — your contacts see your photo on their contact list and detail page
- **CQ Chat** — your photo appears next to your messages in conversations

### Removing

Click **Remove** to delete your profile photo. A default avatar icon will be shown instead.

---

## Bio

Write a short description about yourself in the **Bio** field. Your bio appears on:
- Your **public profile page** (if enabled)
- The **CQ Contact detail page** when contacts view your profile

---

## Spirit Showcase

The Spirit showcase displays your AI Spirit companions with:
- **Ghost icon** in the Spirit's color
- **Spirit name**
- **Level and XP** — showing their progression
- **Star icon** — marks the primary Spirit (only when showing all Spirits and you have more than one)

On desktop, Spirits appear on the right side of the profile header. On mobile, they appear below the header in a compact layout.

Your CQ Contacts also see your Spirits on their Contact Detail page, based on the CQ Contact Visibility settings.

---

## Themes

The theme you choose for your public profile page is independent of the visitor's personal theme — it only affects how your public page looks. Visitors' personal CitadelQuest themes are not changed.

Choose from 5 visual themes in the settings, each shown as a thumbnail preview so you can see what it looks like before saving.

---

## How Others See You

### Visitors (Public)
Anyone who visits `https://your-citadel.com/YourUsername` sees your public profile page with the settings you've configured. No login required.

### CQ Contacts (Federation)
Your CQ Contacts see additional information based on your CQ Contact Visibility settings. When they visit your Contact Detail page in their Citadel, it fetches your profile data (bio, photo, Spirits) via the secure federation protocol.

### Not Enabled
If you haven't enabled the public profile page, visitors to your URL will see a 404 page. CQ Contacts can still see your federation profile data based on your visibility settings.

