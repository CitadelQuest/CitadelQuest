/**
 * CitadelQuest Date/Time Formatting Utilities
 * 
 * Reads the citadel_locale cookie for locale-aware formatting.
 * Uses the browser's local timezone (no hardcoded timezone).
 */

/**
 * Get the current locale from the citadel_locale cookie.
 * Falls back to 'en' if not set.
 */
export function getCitadelLocale() {
    const match = document.cookie.match(/(?:^|;\s*)citadel_locale=([^;]*)/);
    return match ? decodeURIComponent(match[1]) : 'en';
}

/**
 * Format a date string or Date object as a localized date.
 * Default: year, 2-digit month, 2-digit day (e.g. "19. 03. 2026" for sk)
 */
export function formatDate(dateInput, options) {
    const date = dateInput instanceof Date ? dateInput : new Date(dateInput);
    const locale = getCitadelLocale();
    const defaults = { year: 'numeric', month: '2-digit', day: '2-digit' };
    return date.toLocaleDateString(locale, options || defaults);
}

/**
 * Format a date as a short date (month + day only).
 * E.g. "03. 19." for sk, "03/19" for en
 */
export function formatShortDate(dateInput) {
    const date = dateInput instanceof Date ? dateInput : new Date(dateInput);
    const locale = getCitadelLocale();
    return date.toLocaleDateString(locale, { month: '2-digit', day: '2-digit' });
}

/**
 * Format a date string or Date object as a localized time.
 * Default: 2-digit hour and minute (e.g. "14:30" for sk, "2:30 PM" for en)
 */
export function formatTime(dateInput, options) {
    const date = dateInput instanceof Date ? dateInput : new Date(dateInput);
    const locale = getCitadelLocale();
    const defaults = { hour: '2-digit', minute: '2-digit' };
    return date.toLocaleTimeString(locale, options || defaults);
}

/**
 * Format a date string or Date object as a full localized date+time string.
 */
export function formatDateTime(dateInput, options) {
    const date = dateInput instanceof Date ? dateInput : new Date(dateInput);
    const locale = getCitadelLocale();
    return date.toLocaleString(locale, options || undefined);
}

/**
 * Format a date with separate date and time parts joined by a separator.
 * Returns "DD.MM.YYYY <sep> HH:MM" style based on locale.
 */
export function formatDateTimeSplit(dateInput, separator = ' <span class="text-cyber opacity-75">/</span> ') {
    return formatDate(dateInput) + separator + formatTime(dateInput);
}

/**
 * Format a date with short date + time (no year).
 * E.g. "03. 19. <sep> 14:30"
 */
export function formatShortDateTimeSplit(dateInput, separator = ' <span class="text-cyber opacity-75">/</span> ') {
    return formatShortDate(dateInput) + separator + formatTime(dateInput);
}

/**
 * Get a localized relative time string.
 * Falls back to a localized short date for older dates (>7 days).
 */
export function getRelativeTime(dateInput) {
    const date = dateInput instanceof Date ? dateInput : new Date(dateInput);
    const now = new Date();
    const diff = Math.floor((now - date) / 1000);

    if (diff < 60) return _relativeUnit(diff, 's');
    if (diff < 3600) return _relativeUnit(Math.floor(diff / 60), 'min');
    if (diff < 86400) return _relativeUnit(Math.floor(diff / 3600), 'h');
    if (diff < 604800) return _relativeUnit(Math.floor(diff / 86400), 'd');

    return formatDate(date, { month: 'short', day: 'numeric' });
}

function _relativeUnit(value, unit) {
    // Try Intl.RelativeTimeFormat if available
    try {
        const locale = getCitadelLocale();
        const unitMap = { 's': 'second', 'min': 'minute', 'h': 'hour', 'd': 'day' };
        const rtf = new Intl.RelativeTimeFormat(locale, { numeric: 'auto', style: 'narrow' });
        return rtf.format(-value, unitMap[unit]);
    } catch (e) {
        // Fallback: compact format
        return `${value}${unit}`;
    }
}
