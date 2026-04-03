# Spirits — Your AI Companions

Spirits are the heart of CitadelQuest — personal AI companions that learn, remember, and grow alongside you. Each Spirit has its own personality, memory, and capabilities.

---

## Spirits List

**Route**: `/spirits`

The Spirits page shows all your AI companions as cards. Each card displays:
- Spirit icon with custom color
- Spirit name
- Level and experience points (XP)
- Quick access to Spirit chat and profile

From here you can create new Spirits or click on an existing one to manage it.

---

## Spirit Profile

**Route**: `/spirit/{id}`

The Spirit Profile is your Spirit's home page, divided into several sections:

### Identity & Level
- **Spirit icon** with custom color and glow effect
- **Name** and **Level** indicator
- **Experience bar** showing progress to next level (XP earned through interactions)

### AI Model
- **Model selector** — click to compare and select from available AI models
- Models include options from providers like Anthropic (Claude), Google (Gemini), xAI (Grok), and more
- Your selection determines the AI powering this Spirit's responses

### System Prompt
- **Custom instructions** — define how your Spirit should behave, its personality, areas of expertise, and knowledge
- **Advanced Prompt Builder** — opens a detailed editor for fine-tuning the system prompt with:
  - Memory Type selection (Legacy .md files, Reflexes, Memory Agent)
  - Structured sections for personality, expertise, and behavior rules
- Click **Save** to apply changes

### Memory Explorer Preview
- **3D graph visualization** — a miniature preview of your Spirit's knowledge graph
- Shows pack count, node count, and relationship count
- **Explore** button opens the full Memory Explorer filtered to this Spirit
- **Memory Agent** badge shows which memory processing mode is active

### Recent Interactions
- Log of Spirit interactions with timestamps
- Shows XP earned and interaction types (creation, conversation, tool use)

### Conversations
- List of all conversations with this Spirit
- Click the chat button to open a conversation
- Create new conversations with the "+" button

---

## Spirit Chat

Spirit Chat is a **modal overlay** — it opens on top of any page, so you never lose your place. Access it by:
- Clicking the **Spirit icon** in the top navigation bar
- Clicking a **conversation** in the Spirit Profile

### Chat Interface
- **Message input** — type your message and press Enter or click Send
- **AI response** — your Spirit's reply with full Markdown formatting (code blocks, lists, tables, etc.) and AI Tools results
- **Memory synthesis** — when relevant, the chat shows which memories were recalled:
  - Expandable panel with recalled memory nodes
  - Mini 3D graph visualization of memory connections and highlighted activated nodes
  - Synthesis quality indicator (high/medium/low)
  - Tags showing matched keywords
- **Used context window indicator** — top border of input area used as decent yet informative progress bar

### Attachments
- **Image attachments** — attach images to your messages
- **PDF attachments** — attach PDF documents for your Spirit to analyze (and makes it available for memory extraction)

### Controls
- **Temperature slider** — adjust the creativity/randomness of your Spirit's responses
- **Info button** — shows Spirit current AI Model name (context window size)
- **Extract Memory & Close** — extract the conversation into a CQ Memory Pack and close the conversation in one click

### AI Tools

Your Spirit can use various tools during conversation to help you:

| Tool | Description |
|------|-------------|
| **aiToolList** | View all available AI tools |
| **aiToolSetActive** | Enable or disable specific tools for your Spirit |
| **cqProfileManage** | Manage your CQ Profile — update bio, photos, background, language settings |
| **cqProfileManageGroup** | Organize your profile into content groups (for file and memory sharing) |
| **cqProfileManageItem** | Add files, memories, or shares to your profile groups |
| **createSepaEuroPaymentQrCode** | Create QR codes for Euro bank payments |
| **fetchURL** | Read web pages and research online content |
| **fileManage** | Create, edit, copy, move, delete files and directories in your File Browser |
| **fileSearch** | Find files by name or path |
| **fileUpdate** | Edit files precisely — find/replace text or update specific lines |
| **memoryExtract** | Turn documents, conversations, or web pages into organized memory knowledge graphs |
| **memoryForget** | Remove outdated information from memory |
| **memoryRecall** | Search through stored memories |
| **memorySource** | Check original source of any memory (for fact verification) |
| **memoryStore** | Save important facts and insights for future reference |
| **memoryUpdate** | Update existing memories with new information |
| **spiritCreateDiffusionImage** | Generate artistic images, photos, anime, or NSFW content using AI |
| **spiritCreateOrEditImage** | Create or edit images using AI image generation |

> Tools are used automatically when relevant — just ask your Spirit naturally and it will decide which tools to use.

---

## Creating a New Spirit

1. Go to `/spirits`
2. Click **Create Spirit** (or it opens automatically if you have no Spirits)
3. Choose a **name**, **AI model**, and **color**
4. Your Spirit is created with starter memory nodes (identity nodes for both Spirit and user)
5. Start your first conversation!

---

## Spirit Experience & Leveling

Spirits earn **XP** (experience points) through interactions:
- **Conversations** — chatting with your Spirit
- **Tool usage** — when Spirit uses its tools to help you
- **Memory operations** — when Spirit stores or processes memories

As your Spirit levels up, the experience bar fills and the level number increases. This gamification element makes the collaboration journey more engaging.
