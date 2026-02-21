# CQ Chat — Private Messaging

CQ Chat enables private, encrypted messaging between users across different CitadelQuest instances. Communication is direct — messages travel straight from one Citadel to another, with no corporate servers in between.

**Route**: `/cq-chat`

---

## Accessing CQ Chat

There are two ways to access your conversations:

### Navigation Dropdown
Click the **chat icon** in the top navigation bar to see:
- Recent conversations with preview of last message
- **Unseen message count** badge (green number)
- **New Chat** button to start a conversation
- Click any conversation to open it

### Full Chat Page
Navigate to `/cq-chat` for the complete chat interface with:
- Conversation list on the left
- Active conversation on the right
- Full message history with timestamps

---

## Conversations

### Starting a Conversation
1. Click the **New Chat** button (message+ icon)
2. Select a contact from your CQ Contacts
3. Start typing!

### Group Chats
CQ Chat supports group conversations with multiple participants from different Citadels.

### Message Features
- **Text messages** with real-time delivery
- **Image attachments**
- **Timestamps** showing when messages were sent
- **Read indicators** for message status
- **Unseen count** — badge shows how many unread messages you have

---

## How It Works

CQ Chat uses CitadelQuest's **Federation protocol** for cross-instance communication:
- Messages are sent directly between Citadels over HTTPS, secured with **CQ Contact API Keys**
- Your browser polls for new messages in real-time via the global updates endpoint
- Messages are stored only on the sender's and receiver's Citadels — nowhere else

> **Privacy**: Messages travel directly between Citadels. There is no central server that stores or relays your messages. Each user's messages are stored in their own personal database.
