import MarkdownIt from 'markdown-it';
import DOMPurify from 'dompurify';

const PURIFY_CONFIG = {
    USE_PROFILES: { html: true },
    FORBID_TAGS: ['form', 'input', 'button', 'select', 'option', 'textarea', 'fieldset', 'label', 'base', 'meta', 'link'],
};

const instances = new Map();

/**
 * Sanitize an HTML string before it reaches innerHTML.
 * Strips scripts, event handlers, javascript: URLs and non-HTML content.
 */
export function sanitizeHtml(html) {
    return DOMPurify.sanitize(html ?? '', PURIFY_CONFIG);
}

/**
 * Escape a value for use in HTML text or attribute context.
 */
export function escapeHtml(value) {
    return String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

class SafeMarkdownIt extends MarkdownIt {
    render(src, env) {
        return sanitizeHtml(super.render(src, env));
    }

    renderInline(src, env) {
        return sanitizeHtml(super.renderInline(src, env));
    }
}

/**
 * MarkdownIt factory that sanitizes everything it renders.
 * Instances are cached per option set, so repeated calls are cheap.
 */
export function createMarkdown(options = {}) {
    const key = JSON.stringify(options);
    let md = instances.get(key);
    if (!md) {
        md = new SafeMarkdownIt(options);
        instances.set(key, md);
    }
    return md;
}
