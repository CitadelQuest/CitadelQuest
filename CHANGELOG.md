# CitadelQuest Changelog

## v0.7.68-beta (2026-07-12)

### Fixes & Improvements
- **Spirit-to-Spirit — full answers, no truncation** — the consulted (callee) Spirit now runs at its model's full output capacity instead of a fixed 2000-token cap, so its answer is returned complete in one turn (previously long answers were cut off, prompting Spirits to fetch the rest in parts)
- **Longer request timeouts** — bumped Apache/PHP timeouts from `900s` to `36000s` (10h) across CitadelQuest and CQ AI Gateway configs. Nested Spirit-to-Spirit consultations (AI request within an AI request) can take significantly longer, and the old 15-min ceiling could cut them off

### Technical Changes
- `AIToolSpiritService`: derive callee max output from `SpiritService::getSpiritAiModel()->getMaxOutput()` (fallback `8192`) instead of the removed `CALLEE_MAX_OUTPUT` constant
- Timeout config bumped in `docker/apache.conf`, `docker/Dockerfile`, `php.ini` (CitadelQuest) and `docker/apache.conf`, `docker/php-custom.ini` (CQ AI Gateway)

## v0.7.67-beta (2026-07-12)

### New Features
- **Spirit-to-Spirit Chat (`callSpirit` / `listSpirits`)** — a Spirit can now consult a fellow Spirit owned by the same user
  - The calling Spirit becomes the "user" of the callee; the callee runs a **full turn** with its own model, system prompt, active tools and CQ Memory recall/enrichment
  - Runs **synchronously in-process** inside the existing detached turn worker via `SpiritConversationService::runTurnSync()` — no extra HTTP request held open, no gateway timeouts
  - `listSpirits` lets a Spirit discover consultable fellow Spirits and their specialties
  - Transparent framing: when a conversation `origin='spirit'`, the callee's system prompt is injected with a "you are being consulted by fellow Spirit «A»" block
  - Full safeguards: depth cap, cycle guard (blocks A→B→A), per-turn call budget, and per-Spirit permission gates
- **S2S tab on the Spirit Detail page** — dedicated 50/50 layout with S2S settings on the left and Outgoing/Incoming consultation conversation lists on the right (styled with the shared `cq-tabs` nav)
- **Consulted-Spirit chat badges** — expandable badges in the caller's chat show who was consulted, the request/answer, and the nested consultation cost (colored per-Spirit `mdi-ghost` icons)

### Improvements
- S2S consultation conversations are now grouped under the S2S tab and hidden from the general Conversations tab and the Spirit Chat modal conversation list
- Unified Spirit color source: backend now exposes `spirit.color` via API/controllers (`SpiritService::getSpiritColor()`), replacing scattered inline `visualState` JSON parsing across `SpiritManager`, `SpiritDropdownManager`, `SpiritChatManager`
- Full English, Czech, and Slovak translations for all new S2S UI strings

### Technical Changes
- New `src/Service/AIToolSpiritService.php` implementing `callSpirit()` + `listSpirits()` (lazy `SpiritConversationService` via service-subscriber locator to break the constructor cycle)
- New `src/Service/SpiritCallContext.php` — per-turn chain/depth/budget guard (`MAX_DEPTH=2`, `MAX_CALLS_PER_TURN=5`)
- Extended `SpiritConversation` entity + `SpiritConversationService` with `origin` / `initiatorSpiritId`, `getOrCreateS2SConversation()`, `runTurnSync()`, and S2S initiated/received conversation queries
- New user migrations: `Version20260711220000` (S2S columns + `callSpirit`/`listSpirits` tools) and `Version20260712150000` (updated `callSpirit` `conversationId` options: `continue-last` / `new` / UUID)
- Added `SpiritService::getSpiritColor()` and exposed color in `SpiritApiController` responses

## v0.7.66-beta (2026-07-11)

### New Features
- **Spirit Skills** — dynamic persistent context documents for Spirits
  - File-based skills living in `/spirit/{spirit}/skill/{active|available}/`
  - Full CRUD UI on the Spirit Detail page with Active/Available skill lists
  - Active skills are automatically injected into the Spirit's system prompt every conversation
  - Spirits can grow and refine active skills using the `fileUpdate` AI tool
  - New skill modal includes a "Start with template" button with a shorter default template

### Improvements
- Full English, Czech, and Slovak translations for the new Spirit Skills UI
- Clarified active skills system prompt note so Spirits use `fileUpdate` directly (skill content is already injected)

### Technical Changes
- Added `SpiritSkillService` for skill directory management, CRUD, state toggling, and prompt injection
- Added `SpiritSkillApiController` with REST endpoints for skill management
- Extended `SpiritConversationService` to inject active skills into the system prompt
- Updated `SpiritManager.js` and `spirit/index.html.twig` for the Skills tab and editor modal

## v0.7.38-beta (2026-04-27)

### New Features
- **CQ Imager — pair-aware dimensions** — Width/Height now render as dropdowns when the model schema constrains them to fixed pairs (e.g. Grok Imagine, Nano-Banana). Picking one axis cascade-filters the other so users can't submit unsupported combinations; invalid options are greyed out with an ✕ marker instead of hidden.
- **CQ Imager — condensed Output group** — Number of Results (1..4 select) + Output Format on one row; Output Quality rendered as a styled range slider matching the Chat Settings temperature slider.
- **CQ Imager — 3-state boolean fields** — Advanced boolean params (e.g. `checkContent`, `includeNSFWContent`) render as a 3-state select (blank / true / false) instead of a 2-state switch; blank means "use descriptor default".
- **CQ Imager — param values persist across model switches** — Single global snapshot in localStorage so the same prompt / quality / number-of-results carries over when you change models. Re-Use Params bypasses restore.
- **Spirit Chat — rich frontend data for file tools** — Styled result cards for all `fileManage` operations (create, copy, rename_move, delete, createDirectory, list, read) + `fileSearch` (query + match list) + `fileUpdate` (per-op details). `fileUpdate` details are collapsible diffs (replace shows -removed / +added blocks; lineRange/append/prepend/insertAtLine show inserted content). `fileManage:read` content block is now collapsible too — clean feed by default, expand on demand.
- **Spirit Chat — visible failure cards** — Red "Failed" cards on all error paths so silent tool failures are now visible to both user and AI.

### Improvements
- **CQ AI Gateway — human-readable image-gen errors** — `RunwareApiClient` now extracts the provider's actual error message (e.g. "Generated image rejected by content moderation.") from nested `responseContent.error` instead of dumping the whole JSON errors array into the toast.
- **CQ AI Gateway — dimension enums from schema** — `RunwareModelCatalog` walks `allOf[*].oneOf[*].properties.{width,height}.const` and attaches per-axis enum lists plus the full pair list, feeding the UI cascade filter above.
- **Primary font switched to Geist** — Better readability for code/UI surfaces. Loaded from Google Fonts (weights 100..900); Nunito kept commented out for easy revert.

### Technical Changes
- `DynamicParamsForm._normalizeType()` maps gateway type aliases (`bool`/`int`/`float`/`text`) and JSON-Schema aliases (`boolean`/`integer`/`number`) — fixes silent fallback to text inputs for non-string typed fields.
- `DynamicParamsForm._installDimensionPairFilter()` bidirectional cascade on enum selects given a `[[w,h], ...]` pairs array.
- `ImagerControlPanel` snapshots/restores form state via `DynamicParamsForm.getRawValues()` around `selectModel()`; `skipRestore` opt for Re-Use Params flow.
- `AIToolFileService::buildContentFrontendData()` wraps text content in `renderCollapsible()` with word-wrap + max-height 480px scroll.
- `RunwareApiClient::formatRunwareErrors()` static helper replaces 5 call sites that previously `json_encode`'d the full errors array.

## v0.7.31-beta (2026-04-02)

### New Features
- **AI Tool `cqProfileManage`** — Spirit can now manage CQ Profile settings: set/remove profile photo, set/remove background image, toggle background overlay, update bio, set spirit showcase mode, and set profile language
- **`fetchURL` download mode** — new optional `downloadPath` + `downloadFilename` parameters allow Spirits to download files (images, PDFs, etc.) from URLs and save them directly to File Browser via ProjectFileService

### Improvements
- **Spirit AI Model auto-save** — selecting an AI model from the modal now saves immediately without requiring a separate Save button click (consistent with Memory Type behavior)

### Technical Changes
- Exposed `spiritManager` to `window` scope for cross-component access
- Added `AIToolProfileService::cqProfileManage()` with `SettingsService` integration
- Added `AIToolWebService::downloadAndSaveFile()` for binary file fetching and storage
- New migrations: `Version20260401200000` (cqProfileManage tool), `Version20260402000000` (fetchURL download params)

## v0.1.5-alpha (2025-02-05)

### Improvements
- Simplified installer with cleaner UI
- Removed .htaccess handling from installer
- Improved file extraction reliability
- Added proper cleanup of installation files

### Technical Changes
- Direct file extraction without filtering
- Streamlined installation progress display
- Better error handling and feedback
- Self-cleanup after successful installation

## v0.1.4-alpha (2025-02-04)
- Initial alpha release
- Basic installation functionality
- Multi-user support
- End-to-end encryption
- SQLite database per user
