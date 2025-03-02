# Digital Diary

## Feature Overview

**Digital Diary - "The foundation of personal data sovereignty"**

The Digital Diary is a core feature of CitadelQuest, providing users with a secure, private space to record thoughts, track personal data, and gain insights over time. It serves as the foundation for personal data sovereignty by keeping all user data in their own database with options for encryption and privacy controls.

### Core Features

1. **Structured Data Tracking**
   - Sleep patterns
   - Food/nutrition
   - Exercise/physical activity
   - Work/productivity
   - Social interactions

2. **Free-form Content**
   - Notes/journal entries
   - Photo attachments
   - Personal reflections

3. **AI Companion Integration**
   - Historical context awareness
   - Personal growth insights
   - Mindfulness support

4. **Data Analysis**
   - Pattern recognition
   - Decision-making support
   - Progress tracking

## Current Implementation

### Database Schema

The diary feature uses a dedicated table in each user's SQLite database:

```sql
CREATE TABLE diary_entries (
    id VARCHAR(36) PRIMARY KEY,
    title VARCHAR(255) NOT NULL,
    content TEXT NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    is_encrypted BOOLEAN DEFAULT 0,
    is_favorite BOOLEAN DEFAULT 0,
    tags TEXT DEFAULT NULL,
    mood VARCHAR(50) DEFAULT NULL,
    content_formatted TEXT
);

CREATE INDEX idx_diary_entries_created_at ON diary_entries(created_at);
CREATE INDEX idx_diary_entries_is_favorite ON diary_entries(is_favorite);
```

### Architecture

1. **Backend Components**
   - `DiaryService`: Core service for CRUD operations on diary entries
   - `DiaryController`: Handles web routes for diary pages
   - `DiaryEntryApiController`: RESTful API endpoints for AJAX operations
   - `DiaryEntry`: Entity class representing diary entries

2. **Frontend Components**
   - `assets/js/features/diary/index.js`: Main JavaScript module with DiaryManager class
   - `assets/entries/diary.js`: Webpack entry point
   - Templates:
     - `templates/diary/index.html.twig`: Entry listing page
     - `templates/diary/show.html.twig`: Single entry view
     - `templates/diary/new.html.twig`: Create new entry
     - `templates/diary/edit.html.twig`: Edit existing entry
     - `templates/diary/_rich_editor.html.twig`: Reusable rich text editor component

3. **Key Features Implemented**
   - CRUD operations for diary entries
   - Rich text editor with formatting options
   - Favorite/unfavorite entries
   - Tags support
   - Mood selection
   - Responsive design with cyber theme
   - Interactive UI with animations
   - Client-side form validation

## Changelog

### 2025-02-24: Core Diary Entry System Implementation

#### Database & Backend
- Created user database migration for diary entries table
- Implemented DiaryService for CRUD operations
- Added DiaryEntryApiController for RESTful API endpoints
- Implemented rich text content storage with both plain and formatted versions
- Updated is_encrypted default to false (Version20250224170332.php)

#### Frontend Templates
- Created base diary templates (index, show, new, edit)
- Implemented responsive Bootstrap-based UI with cyber theme
- Added glass-panel styling for consistent look
- Implemented interactive UI with entry expansion/collapse

#### Rich Text Editor
- Created reusable rich text editor component (_rich_editor.html.twig)
- Implemented basic formatting toolbar (bold, italic, underline, lists)
- Added content synchronization between plain text and formatted HTML

#### Features Implemented
- Basic CRUD operations for diary entries
- Title, content, mood, and tags support
- Rich text formatting
- Entry listing and detailed view
- Responsive mobile-friendly design
- Favorite/unfavorite functionality
- Interactive animations and transitions

### 2025-03-02: UI/UX Modernization with AJAX and Animations

#### Frontend Enhancements
- Converted traditional page-based operations to dynamic, AJAX-based interactions
- Implemented single-page application behavior for the diary feature
- Added smooth transitions and animations for all interactions
- Improved user experience with immediate feedback and no page reloads

#### Features Modernized
- Entry viewing: Now loads entry details dynamically within the entry list
- Entry editing: Implemented in-place editing with rich text support
- Entry creation: Added dynamic new entry form with smooth animations
- Entry deletion: Implemented with confirmation and smooth removal animation

#### Technical Implementation
- Enhanced DiaryManager class with comprehensive AJAX functionality
- Added methods for showing/saving/canceling entry forms with animations
- Implemented new entry creation directly in the index view
- Added proper browser history management with pushState
- Improved error handling with toast notifications
- Created renderEntryCard method for consistent entry rendering
- Implemented slideUp/slideDown animations for smooth transitions

#### Animation System
- Developed custom animation utilities in assets/js/shared/animation.js
- Implemented consistent animation durations (DURATION constants)
- Added smooth slide animations for form appearance/disappearance
- Created transitions for entry expansion/collapse
- Implemented opacity and height animations for seamless UX

## Next Steps

1. **Content Enhancement**
   - [ ] Add support for photo attachments
   - [ ] Implement markdown support as alternative to rich text
   - [ ] Add emoji picker for moods
   - [ ] Improve rich text editor with more formatting options
   - [ ] Add support for code blocks and syntax highlighting

2. **Organization**
   - [ ] Add categories/folders for entries
   - [ ] Implement tags management and filtering
   - [ ] Add search functionality with real-time results
   - [ ] Add date-based filtering and calendar view
   - [ ] Implement tag cloud visualization

3. **UI/UX Refinements**
   - [x] Add smooth animations for entry creation and editing
   - [x] Implement AJAX-based operations without page reloads
   - [ ] Add animations for tag filtering
   - [ ] Implement infinite scrolling for entry list
   - [ ] Add drag-and-drop reordering of entries
   - [ ] Implement keyboard shortcuts for common actions
   - [ ] Add dark/light mode toggle with animation

4. **AI Integration**
   - [ ] Add entry analysis for insights
   - [ ] Implement mood pattern recognition
   - [ ] Add AI-powered journaling prompts
   - [ ] Create summary views of journal patterns
   - [ ] Implement AI-suggested tags based on content

5. **Security & Privacy**
   - [ ] Implement entry encryption (foundation exists with is_encrypted flag)
   - [ ] Add private/public entry visibility
   - [ ] Add backup/export functionality
   - [ ] Implement entry locking for sensitive content
   - [ ] Add version history and change tracking

6. **Performance Optimization**
   - [ ] Implement lazy loading for entry content
   - [ ] Add client-side caching for frequently accessed entries
   - [ ] Optimize animations for lower-end devices
   - [ ] Implement progressive loading for large diary collections
