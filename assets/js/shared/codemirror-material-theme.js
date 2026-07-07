import { EditorView } from '@codemirror/view';
import { HighlightStyle, syntaxHighlighting } from '@codemirror/language';
import { tags } from '@lezer/highlight';

// Material (dark) theme palette for CodeMirror 6.
// Based on the classic CodeMirror 5 material theme.
const colors = {
    //background: '#263238',
    //surface: '#263238',
    foreground: '#eeffff',
    caret: '#ffcc00',
    selection: '#2f3d45',
    lineHighlight: '#2f3d45',
    gutterBackground: '#263238',
    gutterForeground: '#546e7a',
    gutterBorder: '#37474f',
    keyword: '#c792ea',
    operator: '#89ddff',
    variable: '#eeffff',
    function: '#82aaff',
    string: '#95ec86',
    number: '#f78c6c',
    comment: '#546e7a',
    className: '#ffcb6b',
    tagName: '#c0528d',
    attributeName: '#7fceff',
    propertyName: '#82aaff',
    typeName: '#c792ea',
    namespace: '#d0609c',
    invalid: '#ffffff',
    punctuation: '#89ddff',
    regexp: '#89ddff',
    link: '#80cbc4',
    emphasis: '#eeffff',
    strong: '#eeffff',
    heading: '#89ddff',
    list: '#eeffff',
    quote: '#546e7a',
    meta: '#546e7a',
    inserted: '#c3e88d',
    deleted: '#f07178',
    changed: '#ffcb6b'
};

/**
 * Base editor chrome styles for the Material theme.
 */
const materialBaseTheme = EditorView.theme({
    '&': {
        color: colors.foreground,
        backgroundColor: colors.background,
        fontFamily: "'Fira Code', 'Consolas', 'Monaco', 'Courier New', monospace",
        fontSize: '14px',
        lineHeight: '1.5'
    },
    '.cm-content': {
        caretColor: colors.caret
    },
    '.cm-cursor, .cm-dropCursor': {
        borderLeftColor: colors.caret
    },
    '&.cm-focused .cm-selectionBackground, .cm-selectionBackground, .cm-content ::selection': {
        backgroundColor: colors.selection
    },
    '.cm-panels, .cm-panels.cm-panel': {
        backgroundColor: colors.surface,
        color: colors.foreground
    },
    '.cm-panels.cm-panel': {
        border: `1px solid ${colors.gutterBorder}`
    },
    '.cm-gutters': {
        backgroundColor: colors.gutterBackground,
        color: colors.gutterForeground,
        borderRight: `1px solid ${colors.gutterBorder}`
    },
    '.cm-activeLineGutter, .cm-activeLine': {
        backgroundColor: colors.lineHighlight
    },
    '.cm-matchingBracket': {
        backgroundColor: 'rgba(255, 255, 255, 0.1)',
        outline: `1px solid rgba(255, 255, 255, 0.2)`
    },
    '.cm-nonmatchingBracket': {
        backgroundColor: 'rgba(240, 113, 120, 0.2)',
        outline: `1px solid rgba(240, 113, 120, 0.4)`
    },
    '.cm-tooltip': {
        backgroundColor: colors.surface,
        color: colors.foreground,
        border: `1px solid ${colors.gutterBorder}`
    },
    '.cm-tooltip.cm-tooltip-autocomplete > ul > li[aria-selected]': {
        backgroundColor: colors.selection,
        color: colors.foreground
    },
    '.cm-search input': {
        backgroundColor: colors.background,
        color: colors.foreground,
        border: `1px solid ${colors.gutterBorder}`
    },
    '.cm-search button': {
        backgroundColor: colors.surface,
        color: colors.foreground,
        border: `1px solid ${colors.gutterBorder}`
    }
}, { dark: true });

/**
 * Syntax highlighting for the Material theme.
 */
const materialHighlightStyle = HighlightStyle.define([
    { tag: tags.keyword, color: colors.keyword },
    { tag: [tags.name, tags.deleted, tags.character, tags.macroName], color: colors.variable },
    { tag: [tags.propertyName, tags.attributeName], color: colors.propertyName },
    { tag: [tags.function(tags.variableName), tags.labelName], color: colors.function },
    { tag: [tags.color, tags.constant(tags.name), tags.standard(tags.name)], color: colors.variable },
    { tag: [tags.definition(tags.name), tags.separator], color: colors.variable },
    { tag: [tags.typeName, tags.className, tags.number, tags.changed, tags.annotation, tags.modifier, tags.self, tags.namespace], color: colors.className },
    { tag: [tags.operator, tags.operatorKeyword, tags.url, tags.escape, tags.regexp, tags.link, tags.special(tags.string)], color: colors.operator },
    { tag: [tags.meta, tags.comment], color: colors.comment },
    { tag: tags.strong, fontWeight: 'bold' },
    { tag: tags.emphasis, fontStyle: 'italic' },
    { tag: tags.strikethrough, textDecoration: 'line-through' },
    { tag: tags.link, color: colors.link, textDecoration: 'underline' },
    { tag: [tags.string, tags.inserted], color: colors.string },
    { tag: [tags.punctuation, tags.bracket], color: colors.punctuation },
    { tag: [tags.regexp, tags.special(tags.string)], color: colors.regexp },
    { tag: [tags.heading, tags.heading1, tags.heading2, tags.heading3, tags.heading4, tags.heading5, tags.heading6], color: colors.heading, fontWeight: 'bold' },
    { tag: [tags.atom, tags.bool, tags.special(tags.variableName)], color: colors.number },
    { tag: [tags.processingInstruction, tags.string, tags.inserted], color: colors.string },
    { tag: [tags.invalid, tags.deleted], color: colors.invalid },
    { tag: [tags.tagName], color: colors.tagName },
    { tag: [tags.attributeName], color: colors.attributeName },
    { tag: [tags.attributeValue], color: colors.string },
    { tag: [tags.typeName], color: colors.typeName },
    { tag: [tags.namespace], color: colors.namespace }
]);

/**
 * Full Material theme extension for CodeMirror 6.
 * Drop-in replacement for oneDark.
 */
export const materialTheme = [
    materialBaseTheme,
    syntaxHighlighting(materialHighlightStyle)
];

export default materialTheme;
