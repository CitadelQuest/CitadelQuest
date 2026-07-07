import * as bootstrap from 'bootstrap';
import { EditorView } from '@codemirror/view';
import { basicSetup } from 'codemirror';
import { materialTheme } from './codemirror-material-theme';
import { javascript } from '@codemirror/lang-javascript';
import { php } from '@codemirror/lang-php';
import { css } from '@codemirror/lang-css';
import { sass } from '@codemirror/lang-sass';
import { html } from '@codemirror/lang-html';
import { markdown } from '@codemirror/lang-markdown';
import { json } from '@codemirror/lang-json';

/**
 * Shared File Edit Modal
 *
 * Single source of truth for the "edit a text/code file" modal used across
 * CitadelQuest: File Browser, Profile Content (Share Groups) and Spirit Chat
 * AI Tools frontend cards.
 *
 * File operations are fileId-based via the stable global Project File API:
 *   GET  /api/project-file/{id}          → { file }
 *   GET  /api/project-file/{id}/content  → { content }
 *   PUT  /api/project-file/{id}/content  → { success }
 *
 * Usage:
 *   import { getFileEditModal } from '../../shared/file-edit-modal';
 *   getFileEditModal().open(fileId, {
 *       translations: { cancel, save, file_saved, error },
 *       onSaved: (fileId) => { ... }   // optional callback after successful save
 *   });
 *
 * Or declaratively, from any injected HTML (e.g. AI tool frontend data):
 *   <a data-action="cq-edit-file" data-file-id="UUID"><i class="mdi mdi-file-edit"></i></a>
 * A document-level delegated listener opens the modal automatically.
 */
export class FileEditModal {
    constructor() {
        this.apiBase = '/api/project-file';
        this.modalId = 'fileEditModal';
        this.editor = null;
    }

    /**
     * Open the edit modal for a given file.
     * @param {string} fileId
     * @param {Object} [options]
     * @param {Object} [options.translations] - { cancel, save, file_saved, error }
     * @param {Function} [options.onSaved] - async callback(fileId) after save
     */
    async open(fileId, options = {}) {
        const t = this._resolveTranslations(options.translations);

        try {
            const [metaResp, contentResp] = await Promise.all([
                fetch(`${this.apiBase}/${fileId}`),
                fetch(`${this.apiBase}/${fileId}/content`)
            ]);
            const metaData = await metaResp.json();
            const contentData = await contentResp.json();

            const file = metaData.file;
            if (!file) {
                window.toast?.error(t.error);
                return;
            }
            const content = contentData.content || '';

            const modal = this._ensureModal(t);

            modal.querySelector('#fileEditModalTitle').textContent = file.name;
            modal.dataset.fileId = fileId;

            // Replace save button to clear any previous listeners
            const saveBtn = modal.querySelector('#fileEditSaveBtn');
            const newSaveBtn = saveBtn.cloneNode(true);
            saveBtn.parentNode.replaceChild(newSaveBtn, saveBtn);
            newSaveBtn.addEventListener('click', async () => {
                await this.save(modal, options.onSaved, t);
            });

            const bsModal = bootstrap.Modal.getOrCreateInstance(modal);
            bsModal.show();

            modal.addEventListener('shown.bs.modal', () => {
                this._mountEditor(modal, file.name, content);
            }, { once: true });
        } catch (error) {
            console.error('Error opening file edit modal:', error);
            window.toast?.error(error.message || t.error);
        }
    }

    /**
     * Persist the edited content.
     * @param {HTMLElement} modal
     * @param {Function} [onSaved]
     * @param {Object} t - resolved translations
     */
    async save(modal, onSaved, t) {
        const fileId = modal.dataset.fileId;
        const content = this.editor ? this.editor.state.doc.toString()
            : modal.querySelector('#fileEditTextarea').value;

        try {
            const resp = await fetch(`${this.apiBase}/${fileId}/content`, {
                method: 'PUT',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ content })
            });
            const data = await resp.json().catch(() => ({}));

            if (resp.ok && data.success !== false) {
                window.toast?.success(t.file_saved);
                bootstrap.Modal.getInstance(modal)?.hide();
                if (typeof onSaved === 'function') {
                    await onSaved(fileId);
                }
            } else {
                window.toast?.error(data.message || data.error || t.error);
            }
        } catch (error) {
            console.error('Error saving file:', error);
            window.toast?.error(error.message || t.error);
        }
    }

    /**
     * Create the modal element once and reuse it.
     */
    _ensureModal(t) {
        let modal = document.getElementById(this.modalId);
        if (modal) {
            return modal;
        }

        modal = document.createElement('div');
        modal.id = this.modalId;
        modal.className = 'modal fade';
        modal.tabIndex = -1;
        modal.innerHTML = `
            <div class="modal-dialog modal-fullscreen">
                <div class="modal-content bg-dark text-light">
                    <div class="modal-header border-secondary py-2 px-3">
                        <h6 class="modal-title">
                            <i class="mdi mdi-pencil text-cyber me-2"></i>
                            <span id="fileEditModalTitle"></span>
                        </h6>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body p-0">
                        <div id="fileEditEditorContainer" class="h-100 w-100"></div>
                        <textarea id="fileEditTextarea" class="d-none"></textarea>
                    </div>
                    <div class="modal-footer border-secondary justify-content-between">
                        <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">
                            <i class="mdi mdi-close me-1"></i>${t.cancel}
                        </button>
                        <button type="button" class="btn btn-sm btn-cyber" id="fileEditSaveBtn">
                            <i class="mdi mdi-content-save me-1"></i>${t.save}
                        </button>
                    </div>
                </div>
            </div>
        `;
        document.body.appendChild(modal);
        return modal;
    }

    /**
     * Mount (or re-mount) a CodeMirror 6 editor into the modal.
     * @param {HTMLElement} modal
     * @param {string} fileName
     * @param {string} content
     */
    _mountEditor(modal, fileName, content) {
        const container = modal.querySelector('#fileEditEditorContainer');
        const textarea = modal.querySelector('#fileEditTextarea');

        if (this.editor) {
            this.editor.destroy();
            this.editor = null;
        }

        const extensions = [
            basicSetup,
            materialTheme,
            EditorView.updateListener.of((update) => {
                if (update.docChanged) {
                    textarea.value = this.editor.state.doc.toString();
                }
            })
        ];

        const languageExtension = this._detectLanguage(fileName);
        if (languageExtension) {
            extensions.push(languageExtension);
        }

        this.editor = new EditorView({
            doc: content,
            extensions,
            parent: container
        });

        textarea.value = content;
        this.editor.focus();
    }

    /**
     * Pick a CodeMirror language extension based on the file extension.
     * @param {string} fileName
     * @returns {import('@codemirror/state').Extension|null}
     */
    _detectLanguage(fileName) {
        const ext = (fileName.split('.').pop() || '').toLowerCase();
        const langMap = {
            php: () => php(),
            phtml: () => php(),
            js: () => javascript(),
            mjs: () => javascript(),
            cjs: () => javascript(),
            jsx: () => javascript({ jsx: true }),
            ts: () => javascript({ typescript: true }),
            tsx: () => javascript({ jsx: true, typescript: true }),
            vue: () => javascript(),
            svelte: () => javascript(),
            css: () => css(),
            less: () => css(),
            styl: () => css(),
            scss: () => sass(),
            sass: () => sass(),
            html: () => html(),
            htm: () => html(),
            twig: () => html(),
            xml: () => html(),
            svg: () => html(),
            rss: () => html(),
            atom: () => html(),
            md: () => markdown(),
            markdown: () => markdown(),
            json: () => json(),
            json5: () => json(),
            jsonc: () => json()
        };
        return langMap[ext] ? langMap[ext]() : null;
    }

    /**
     * Merge caller-provided translations with global + sensible defaults.
     */
    _resolveTranslations(overrides = {}) {
        const g = (typeof window !== 'undefined' && window.translations) ? window.translations : {};
        return {
            cancel: overrides.cancel || g.cancel || 'Cancel',
            save: overrides.save || g.save || 'Save',
            file_saved: overrides.file_saved || g.file_saved || 'File saved successfully',
            error: overrides.error || g.error || 'An error occurred',
        };
    }
}

let _instance = null;

/**
 * Get the shared FileEditModal singleton (also exposed as window.cqFileEditModal).
 * @returns {FileEditModal}
 */
export function getFileEditModal() {
    if (!_instance) {
        _instance = new FileEditModal();
        if (typeof window !== 'undefined') {
            window.cqFileEditModal = _instance;
            _registerDelegatedClicks();
        }
    }
    return _instance;
}

let _delegatedRegistered = false;

/**
 * Register a single document-level click listener so any injected HTML carrying
 * `data-action="cq-edit-file"` + `data-file-id` opens the shared modal.
 * Used by Spirit Chat AI Tool frontend cards.
 */
function _registerDelegatedClicks() {
    if (_delegatedRegistered || typeof document === 'undefined') {
        return;
    }
    _delegatedRegistered = true;
    document.addEventListener('click', (e) => {
        const trigger = e.target.closest('[data-action="cq-edit-file"]');
        if (!trigger) return;
        e.preventDefault();
        const fileId = trigger.dataset.fileId;
        if (fileId) {
            getFileEditModal().open(fileId);
        }
    });
}

// Eagerly expose the singleton + delegated listener on import.
getFileEditModal();
