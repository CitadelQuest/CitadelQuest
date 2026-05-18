import { FileBrowserModal } from '../features/file-browser/components/FileBrowserModal';

/**
 * Attach a "Pick file from File Browser" button to a textarea (or input).
 *
 * Opens the shared {@link FileBrowserModal} and inserts a
 * `cqfile://<id>#<name>` token at the current cursor position
 * (or appends to a new line if multiline & non-empty).
 *
 * The backend (CQImagerService, SpiritConversationService, …) is responsible
 * for resolving these tokens to file content / data-URIs at request time.
 *
 * @param {Object}      opts
 * @param {HTMLElement} opts.textarea         The <textarea> or <input type="text"> to insert into.
 * @param {HTMLElement} opts.button           The trigger button.
 * @param {Object}      [opts.translations]   Translations passed to FileBrowserModal.
 * @param {Function}    [opts.tokenFormatter] Optional fn(file) => string. Defaults to `cqfile://<id>#<name>`.
 * @param {Function}    [opts.onInserted]     Optional callback(file, token) after insertion.
 *
 * @returns {{ destroy: () => void }} cleanup handle (removes click listener).
 */
export function attachCqFileButton({
    textarea,
    button,
    translations = {},
    tokenFormatter = (file) => `cqfile://${file.id}${file.name ? `#${file.name}` : ''}`,
    onInserted = null,
} = {}) {
    if (!textarea || !button) {
        console.warn('attachCqFileButton: missing textarea or button');
        return { destroy: () => {} };
    }

    let modalInstance = null;

    const handler = async (ev) => {
        ev.preventDefault();
        if (!modalInstance) {
            modalInstance = new FileBrowserModal({ translations });
        }
        const file = await modalInstance.open();
        if (!file) return;

        const token = tokenFormatter(file);
        insertAtCursor(textarea, token);

        // Notify any oninput / change listeners (e.g. save-button enable).
        textarea.dispatchEvent(new Event('input', { bubbles: true }));
        textarea.dispatchEvent(new Event('change', { bubbles: true }));

        if (typeof onInserted === 'function') {
            onInserted(file, token);
        }
    };

    button.addEventListener('click', handler);

    return {
        destroy() {
            button.removeEventListener('click', handler);
            modalInstance = null;
        },
    };
}

/**
 * Insert text at the current cursor position of a textarea / input.
 * Falls back to appending on a new line if the field is non-empty
 * and the cursor position cannot be determined.
 */
function insertAtCursor(el, text) {
    const isMultiline = el.tagName === 'TEXTAREA';
    const value = el.value ?? '';
    const start = typeof el.selectionStart === 'number' ? el.selectionStart : null;
    const end = typeof el.selectionEnd === 'number' ? el.selectionEnd : null;

    if (start !== null && end !== null) {
        const before = value.slice(0, start);
        const after = value.slice(end);

        // For multiline + non-empty surroundings without trailing whitespace,
        // ensure the token sits on its own line for readability.
        let prefix = '';
        let suffix = '';
        if (isMultiline) {
            if (before.length > 0 && !/\s$/.test(before)) prefix = '\n';
            if (after.length > 0 && !/^\s/.test(after)) suffix = '\n';
        } else if (before.length > 0 && !/\s$/.test(before)) {
            prefix = ' ';
        }

        el.value = before + prefix + text + suffix + after;
        const caret = (before + prefix + text).length;
        try { el.setSelectionRange(caret, caret); } catch (_) { /* ignore */ }
        el.focus();
        return;
    }

    // Fallback — append (with newline for textareas).
    if (!value) {
        el.value = text;
    } else if (isMultiline) {
        el.value = value.replace(/\s*$/, '') + '\n' + text;
    } else {
        el.value = value + ' ' + text;
    }
    el.focus();
}
