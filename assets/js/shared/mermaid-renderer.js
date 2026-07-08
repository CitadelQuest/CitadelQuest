import mermaid from 'mermaid';

let initialized = false;

/**
 * Initialize Mermaid with a one-time configuration.
 * startOnLoad is disabled because we render diagrams manually after dynamic DOM insertion.
 */
export function initMermaid(theme = 'dark') {
    if (initialized) return;

    mermaid.initialize({
        startOnLoad: false,
        theme: theme,
        securityLevel: 'strict',
        fontFamily: 'inherit',
        // Render at natural size; CSS controls overflow/scrolling.
        flowchart: { useMaxWidth: false, htmlLabels: true },
        sequence: { useMaxWidth: false },
        gantt: { useMaxWidth: false },
        class: { useMaxWidth: false },
        state: { useMaxWidth: false },
        er: { useMaxWidth: false },
        journey: { useMaxWidth: false },
        gitgraph: { useMaxWidth: false },
        pie: { useMaxWidth: false },
        mindmap: { useMaxWidth: false },
        timeline: { useMaxWidth: false },
        requirement: { useMaxWidth: false },
        sankey: { useMaxWidth: false },
        xychart: { useMaxWidth: false },
        block: { useMaxWidth: false },
        packet: { useMaxWidth: false },
        kanban: { useMaxWidth: false },
    });

    initialized = true;
}

/**
 * Render every non-rendered .mermaid element inside a container.
 * Safe to call repeatedly; already rendered elements are skipped.
 */
export async function renderMermaidInContainer(container = document, theme = 'dark') {
    initMermaid(theme);

    const elements = container.querySelectorAll('.mermaid:not([data-mermaid-rendered])');
    if (elements.length === 0) return;

    for (const el of elements) {
        try {
            const id = `mermaid-${Math.random().toString(36).substring(2, 11)}`;
            const source = el.textContent.trim();
            if (!source) continue;

            const { svg } = await mermaid.render(id, source);
            el.innerHTML = svg;
            el.setAttribute('data-mermaid-rendered', 'true');
        } catch (error) {
            console.error('Mermaid render failed:', error, el.textContent);
            el.classList.add('mermaid-error');
            el.setAttribute('title', `Mermaid render error: ${error.message || error}`);
        }
    }
}

/**
 * Transform MarkdownIt-generated <pre><code class="language-mermaid"> blocks
 * into <div class="mermaid"> elements so Mermaid can render them.
 */
export function prepareMermaidCodeBlocks(htmlString) {
    if (!htmlString || !htmlString.includes('language-mermaid')) {
        return htmlString;
    }

    const parser = new DOMParser();
    const doc = parser.parseFromString(htmlString, 'text/html');
    const blocks = doc.querySelectorAll('pre > code.language-mermaid');

    if (blocks.length === 0) {
        return htmlString;
    }

    blocks.forEach(code => {
        const pre = code.parentElement;
        if (!pre || !pre.parentElement) return;

        const div = document.createElement('div');
        div.className = 'mermaid';
        div.textContent = code.textContent;
        pre.parentElement.replaceChild(div, pre);
    });

    return doc.body.innerHTML;
}
