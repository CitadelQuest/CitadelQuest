# CQ Feed — Social Feed

CQ Feed is CitadelQuest's decentralized social feed — a familiar social network experience where you publish posts, follow feeds from other Citadels, react, and comment. All content stays on your server, distributed peer-to-peer via federation.

**Access**: CQ Feed tab in **CQ Explorer** (`/cq-contacts`)

---

## Overview

CQ Feed brings social networking to CitadelQuest without sacrificing privacy or sovereignty:

- **Your posts live on your server** — not on a corporate platform
- **Federation distributes content** — followers on other Citadels receive your posts automatically
- **Markdown support** — format your posts with rich text, links, and more
- **Reactions and comments** — interact with posts across Citadels
- **Multiple feeds** — organize posts into separate feeds with different visibility scopes
- **Real-time updates** — new posts appear automatically via background polling

---

## Accessing CQ Feed

CQ Feed lives inside **CQ Explorer** (`/cq-contacts`) as a tab:

| Tab | Purpose |
|-----|---------|
| **CQ Explorer** | Browse profiles, manage contacts, following/followers |
| **CQ Feed** | Social timeline — create posts, view feed, react, comment |

Click the **CQ Feed** tab to switch to your social timeline. The active tab is remembered between visits.

---

## Timeline

The timeline shows an aggregated, chronological view of posts from all the feeds you're subscribed to, plus your own posts.

Each post displays:
- **Author** — profile photo, username, and domain (clickable — opens their profile in CQ Explorer)
- **Feed badge** — which feed the post belongs to, with scope indicator (green = CQ Contacts, gray = Public)
- **Timestamp** — when the post was published
- **Content** — Markdown-rendered post text
- **Reactions** — like (heart) and dislike counts
- **Comments** — comment count with expandable comment thread

New posts since your last visit are highlighted with a orange left border.

### Loading More Posts

The timeline loads posts in batches. Click **Load more** at the bottom to fetch older posts.

---

## Creating a Post

At the top of the CQ Feed tab:

1. Type your message in the **"What's on your mind?"** textarea
2. Select which **feed** to post to (dropdown — defaults to your "Public" feed)
3. Click **Publish**

Your post is immediately visible in your timeline and distributed to subscribers via federation.

> **Markdown**: Posts support full Markdown formatting — bold, italic, links, lists, code blocks, and more.

> **Tip**: Click the settings icon next to the feed selector to go directly to feed management settings.

---

## Feeds

Each user can have multiple feeds, each with its own scope and audience.

### Default Feed

When you register, a **"Public"** feed is automatically created with **Public** scope. You can rename it, change its scope, or create additional feeds.

### Feed Scopes

| Scope | Who Can See |
|-------|------------|
| **Public** | Anyone — no authentication required |
| **CQ Contacts** | Only your accepted CQ Contacts (authenticated via API keys) |

### Managing Your Feeds

**Route**: Settings → **My Feeds** (`/settings/cq-feed/my-feeds`)

From the feed management settings page you can:
- **Create** new feeds with a title, URL slug, scope, description, and optional cover image
- **Edit** existing feeds — change title, description, scope, or image
- **Toggle** feeds active/inactive
- **Delete** feeds (and all their posts)

### Public Feed Pages

Each feed has a public page accessible at:
```
https://your-citadel.com/YourUsername/view-feed/public
```

These pages show paginated posts with the feed header, and are visible based on the feed's scope setting.

---

## Reactions

React to posts with:
- **Like** (heart icon) — shows in red when active
- **Dislike** (thumbs down) — shows in white when active

### How Reactions Work

- Click a reaction button to toggle it on/off
- You can only have one reaction per post (liking removes a previous dislike, and vice versa)
- Reaction counts update instantly (optimistic UI)
- Reactions on remote posts are sent to the source Citadel via federation
- You cannot react to your own posts

### Stats Refresh

After loading the timeline, reaction and comment counts are refreshed in the background from the source Citadels. If a post has been deleted on the source, it's automatically removed from your cached timeline.

---

## Comments

Click the **comment icon** (with count) on any post to expand the comments thread.

### Reading Comments

- Comments are **lazy-loaded** from the source Citadel when you first open the thread
- Shows commenter's profile photo, username, domain, and timestamp
- Supports nested replies (up to 2 levels deep)

### Writing Comments

1. Click the comment icon to expand the comments panel
2. Type your comment in the textarea at the bottom
3. Press **Enter** to submit (Shift+Enter for newline)
4. Your comment appears immediately

### Replying to Comments

- Click the **Reply** button on any top-level comment
- The textarea placeholder changes to show you're replying
- Your reply appears nested under the parent comment

### Editing & Deleting

- **Edit** your own comments — click the pencil icon for inline editing
- **Delete** your own comments — click the trash icon and confirm

### Comment Moderation

If someone comments on **your** post, you can:
- **Hide/show comments** — click the eye icon to toggle a comment's visibility (the comment is hidden from other readers but not deleted)

### Notifications

When someone comments on your post, you receive a **notification** — clicking the notification navigates to the post and auto-opens the comment thread.

---

## Subscribed Feeds

When you accept a friend request or follow a profile, you automatically subscribe to their feeds. Subscribed feeds appear in your timeline.

### Managing Subscriptions

**Route**: Settings → **Subscribed Feeds** (`/settings/cq-feed/subscribed`)

The subscribed feeds settings page shows all feeds you're subscribed to, grouped by contact:

- **Pause/Resume** — temporarily stop receiving posts from a specific feed
- **Unsubscribe** — permanently remove a feed subscription
- **Sync Subscriptions** — discover and subscribe to new feeds from contacts you've added since your initial subscription

### Feed Badges in CQ Explorer

When exploring a profile in CQ Explorer, you'll see **feed badges** showing their available feeds:
- Green badge = CQ Contacts scope
- Gray badge = Public scope
- Click a badge for quick actions (pause/unsubscribe)

### Feed Badges on Public Profiles

Public feeds are also shown as badges on CQ Profile pages, with links to the feed's public page.

---

## Real-time Updates

CQ Feed integrates with CitadelQuest's global update polling:

- **New post detection** — background polling checks for new posts from subscribed feeds
- **CQ Feed tab badge** — a bell icon with highlight appears on the CQ Feed tab when new posts are available
- **Sidebar indicators** — Following contacts in the sidebar show content-specific update and CQ Chat messages indicators
- **Dashboard badge** — the CQ Explorer dashboard tile reflects new feed content
- **Auto-refresh** — when you click the CQ Feed tab or it's already active, new posts are fetched and displayed automatically

---

## How It Works (Federation)

CQ Feed uses CitadelQuest's decentralized federation protocol:

1. **Posts stay on your server** — you own and control all your content
2. **Subscribers fetch posts** — other Citadels periodically check for new posts via federation endpoints
3. **Incremental sync** — only posts newer than the last check are fetched (efficient bandwidth)
4. **Reactions and comments** travel via federation — when you react to a remote post, the reaction is sent to the source Citadel
5. **No central server** — content flows directly between Citadels over HTTPS
6. **Scope-based access** — public feeds are accessible to anyone; CQ Contacts feeds require authentication

> **Privacy**: Your posts are stored only on your Citadel and cached locally on subscribers' Citadels. There is no central feed server. You can delete your posts at any time — deleted posts are cleaned up from subscribers' caches during the next stats refresh.
