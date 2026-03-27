/**
 * Shared share preview rendering for CQ Explorer and ContactDetailManager.
 * Renders description + content preview block (image, PDF, HTML, text, graph).
 * 
 * @param {Object} share - Share data object
 * @param {Object} options
 * @param {boolean} options.showContent - Whether to show content preview (from profile setting)
 * @param {Object} options.md - MarkdownIt instance for rendering markdown
 * @param {Function} options.t - Translation function (key, fallback) => string
 * @param {string} [options.contactId] - CQ Contact ID for proxying CQ_CONTACT scoped remote content
 * @returns {string} HTML string for the preview block
 */
export function renderSharePreviewBlock(share, { showContent, md, t, displayStyleOverride, descriptionDisplayStyleOverride, contactId }) {
    const ds = parseInt(displayStyleOverride ?? share.display_style ?? 1);
    const desc = (share.description || '').trim();
    const dds = parseInt(descriptionDisplayStyleOverride ?? share.description_display_style ?? 1);
    const hasPreview = showContent && share.preview_type && ds > 0;
    const hasDesc = desc.length > 0;
    const isColumn = dds === 2 || dds === 3;

    if (!hasDesc && !hasPreview) return '';

    let html = '';
    const descRendered = md.render(desc);
    const descHtml = `<div class="p-3 rounded text-light small" style="background: rgba(0,0,0,0.15); word-break: break-word; overflow-wrap: break-word;">${descRendered}</div>`;
    const colWidth = isColumn && hasDesc && hasPreview ? ' style="width: 40%; min-width: 120px;"' : '';
    const wrapClass = isColumn && hasDesc && hasPreview ? ' d-flex flex-column flex-md-row gap-3' : '';

    html += `<div class="mt-3${wrapClass}">`;

    // Description above (0) or left (2)
    if (hasDesc && (dds === 0 || dds === 2)) {
        html += `<div class="${isColumn && hasPreview ? 'flex-shrink-0' : ''} mb-${isColumn ? '0' : '3'}"${colWidth}>${descHtml}</div>`;
    }

    // Content preview block
    if (hasPreview) {
        html += `<div class="${isColumn && hasDesc ? 'flex-grow-1' : ''}" style="min-width: 0;">`;

        if (share.preview_type === 'image' && share.preview_url) {
            const imgStyle = ds === 1
                ? 'max-height: 500px; object-fit: contain; background: rgba(0,0,0,0.2);'
                : 'background: rgba(0,0,0,0.2);';
            const imgSrc = (contactId && share.preview_url.startsWith('http'))
                ? `/api/feed/attachment-proxy?url=${encodeURIComponent(share.preview_url)}&contact_id=${encodeURIComponent(contactId)}`
                : share.preview_url;
            html += `<div><img src="${imgSrc}" alt="${share.title || ''}" class="rounded w-100" style="${imgStyle}"></div>`;
        }

        if (share.preview_type === 'pdf' && share.preview_url) {
            const pdfHeight = ds === 1 ? '500px' : '90vh';
            let pdfSrc;
            if (contactId && share.preview_url.startsWith('http')) {
                pdfSrc = `/api/feed/attachment-proxy?url=${encodeURIComponent(share.preview_url)}&contact_id=${encodeURIComponent(contactId)}`;
            } else if (share.preview_url.startsWith('http')) {
                pdfSrc = `/api/citadel-explorer/share-content?url=${encodeURIComponent(share.preview_url)}`;
            } else {
                pdfSrc = share.preview_url;
            }
            html += `<div class="rounded" style="background: rgba(0,0,0,0.2);"><iframe src="${pdfSrc}" class="w-100 rounded border-0" style="height: ${pdfHeight};"></iframe></div>`;
        }

        if (share.preview_type === 'html' && share.preview_content) {
            if (ds === 1) {
                const escaped = share.preview_content.replace(/</g, '&lt;').replace(/>/g, '&gt;');
                html += `<div class="p-3 rounded share-preview-scroll" style="background: rgba(0,0,0,0.2); max-height: 300px; overflow-y: auto;"><pre class="mb-0 text-light small" style="white-space: pre-wrap; word-break: break-word;">${escaped}</pre></div>`;
            } else if (ds === 2) {
                html += `<div class="rounded" style="background: rgba(0,0,0,0.1);">${share.preview_content}</div>`;
            }
        }

        if (share.preview_type === 'text' && share.preview_content) {
            const ext = share.preview_ext || '';
            const scrollStyle = ds === 1
                ? 'background: rgba(0,0,0,0.2); max-height: 300px; overflow-y: auto;'
                : 'background: rgba(0,0,0,0.2);';
            if (['md', 'markdown'].includes(ext)) {
                const rendered = md.render(share.preview_content);
                html += `<div class="p-3 rounded share-preview-scroll" style="${scrollStyle}"><div class="text-light small">${rendered}</div></div>`;
            } else {
                const escaped = share.preview_content.replace(/</g, '&lt;').replace(/>/g, '&gt;');
                html += `<div class="p-3 rounded share-preview-scroll" style="${scrollStyle}"><pre class="mb-0 text-light small" style="white-space: pre-wrap; word-break: break-word;">${escaped}</pre></div>`;
            }
        }

        if (share.preview_type === 'graph' && share.preview_graph_url) {
            const graphUrl = (contactId && share.preview_graph_url.startsWith('http'))
                ? `/api/feed/attachment-proxy?url=${encodeURIComponent(share.preview_graph_url)}&contact_id=${encodeURIComponent(contactId)}`
                : share.preview_graph_url;
            html += `
                <div>
                    <div class="share-graph-preview memory-graph-preview rounded"
                         data-graph-url="${graphUrl}"
                         style="height: 250px; background: rgba(10, 10, 15, 0.6); position: relative;">
                        <div class="graph-loading position-absolute top-50 start-50 translate-middle text-center" style="z-index: 10;">
                            <div class="spinner-border spinner-border-sm text-cyber" role="status">
                                <span class="visually-hidden">Loading...</span>
                            </div>
                        </div>
                        <canvas class="rounded" style="position: absolute; top: 0; left: 0; width: 100%; height: 100%;"></canvas>
                    </div>
                    <div class="d-flex justify-content-center align-items-center mt-2 share-graph-stats">
                        <small class="text-secondary">
                            <i class="mdi mdi-circle-multiple text-cyber opacity-25"></i>
                            <span class="stat-nodes">0</span>x ${t('nodes', 'nodes')} &nbsp; · &nbsp;
                            <i class="mdi mdi-link-variant text-cyber opacity-25"></i>
                            <span class="stat-edges">0</span>x ${t('relationships', 'relationships')}
                        </small>
                    </div>
                </div>`;
        }

        html += `</div>`;
    }

    // Description below (1) or right (3)
    if (hasDesc && (dds === 1 || dds === 3)) {
        html += `<div class="${isColumn && hasPreview ? 'flex-shrink-0' : ''} mt-${isColumn ? '0' : '3'}"${colWidth}>${descHtml}</div>`;
    }

    html += `</div>`;
    return html;
}
