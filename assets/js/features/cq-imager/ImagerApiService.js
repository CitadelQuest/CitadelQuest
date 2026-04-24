/**
 * CQ Imager API Service — thin fetch wrapper over /api/imager/*.
 *
 * All endpoints are session-authenticated; no auth header needed here.
 */
export class ImagerApiService {
    constructor() {
        this.baseUrl = '/api/imager';
    }

    async _request(method, path, { query = null, body = null } = {}) {
        const url = query
            ? `${this.baseUrl}${path}?${new URLSearchParams(query)}`
            : `${this.baseUrl}${path}`;

        const opts = { method, headers: {} };
        if (body !== null) {
            opts.headers['Content-Type'] = 'application/json';
            opts.body = JSON.stringify(body);
        }

        let response;
        try {
            response = await fetch(url, opts);
        } catch (networkErr) {
            throw new Error(`Network error: ${networkErr.message}`);
        }

        let data;
        try {
            data = await response.json();
        } catch {
            throw new Error(`Invalid JSON response (HTTP ${response.status})`);
        }

        if (!response.ok && !data.success) {
            const msg = data?.error?.message || data?.error || data?.message || `HTTP ${response.status}`;
            const err = new Error(msg);
            err.payload = data;
            err.status = response.status;
            throw err;
        }
        return data;
    }

    /** @param {boolean} refresh - bypass gateway cache */
    async getModels(refresh = false) {
        return this._request('GET', '/models', refresh ? { query: { refresh: 1 } } : {});
    }

    /**
     * @param {string} model      AIR id, e.g. "google:4@3"
     * @param {object} params     Flat params as declared by descriptor
     * @param {object} [opts]     { projectId, outputDir, filename }
     */
    async generate(model, params, opts = {}) {
        return this._request('POST', '/generate', {
            body: {
                model,
                params,
                projectId: opts.projectId || 'general',
                outputDir: opts.outputDir || '/uploads/imager',
                ...(opts.filename ? { filename: opts.filename } : {}),
            },
        });
    }

    async getHistory(filters = {}) {
        const q = {};
        for (const [k, v] of Object.entries(filters)) {
            if (v !== undefined && v !== null && v !== '') q[k] = v;
        }
        return this._request('GET', '/history', Object.keys(q).length ? { query: q } : {});
    }

    async getGeneration(id) {
        return this._request('GET', `/history/${encodeURIComponent(id)}`);
    }

    async deleteGeneration(id, deleteFile = false) {
        return this._request(
            'DELETE',
            `/history/${encodeURIComponent(id)}`,
            deleteFile ? { query: { deleteFile: 1 } } : {}
        );
    }
}
