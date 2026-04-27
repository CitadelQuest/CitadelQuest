# CitadelQuest Changelog

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
