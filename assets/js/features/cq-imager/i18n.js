/**
 * Tiny translation helper for CQ Imager JS components.
 *
 * Reads from `window.cqImagerTranslations` (populated by the Twig template)
 * via dot-path. Always returns a string: if the key is missing, the
 * supplied `fallback` is returned — so the UI keeps working during
 * template/locale mismatches and early boot.
 *
 * Usage:
 *     import { t } from './i18n';
 *     t('canvas.use_as_input', 'Use as input')
 *     t('toasts.generated', 'Generated {count} image(s) — {cost} credits',
 *        { count: 2, cost: '0.10' })
 */

/**
 * @param {string} path     dot-separated key path (e.g. "canvas.seed")
 * @param {string} fallback returned when the key is missing
 * @param {object} [vars]   optional `{key}` placeholder map
 * @returns {string}
 */
export function t(path, fallback, vars) {
    let cur = typeof window !== 'undefined' ? window.cqImagerTranslations : null;
    if (cur && typeof cur === 'object') {
        for (const segment of String(path).split('.')) {
            if (cur && typeof cur === 'object' && segment in cur) {
                cur = cur[segment];
            } else {
                cur = undefined;
                break;
            }
        }
    } else {
        cur = undefined;
    }

    let out = (typeof cur === 'string' && cur !== '') ? cur : fallback;
    if (vars && typeof vars === 'object') {
        for (const [k, v] of Object.entries(vars)) {
            out = String(out).split(`{${k}}`).join(String(v));
        }
    }
    return String(out);
}
