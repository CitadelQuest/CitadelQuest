#!/usr/bin/env node
/**
 * Obscura Screenshot Helper for CitadelQuest
 *
 * Spawns an Obscura CDP server, connects via Puppeteer, sets viewport,
 * navigates to the target URL, and captures a screenshot (full-page or
 * viewport-only).  Outputs the PNG to a file path, then shuts down the
 * server and exits.
 *
 * Usage:
 *   node obscura-screenshot.cjs \
 *     --url https://example.com \
 *     --output /tmp/screenshot.png \
 *     [--width 1440] [--height 1000] \
 *     [--full-page] [--wait-until networkidle0] \
 *     [--timeout 30] [--binary /usr/local/bin/obscura]
 *
 * Exit codes:
 *   0 = success
 *   1 = argument error
 *   2 = obscura failed to start
 *   3 = puppeteer connection / navigation error
 *   4 = screenshot write error
 */

'use strict';

const { spawn } = require('child_process');
const puppeteer = require('puppeteer-core');
const fs = require('fs');
const net = require('net');

// ── Parse CLI args ──────────────────────────────────────────────
function parseArgs() {
    const args = {};
    const raw = process.argv.slice(2);
    for (let i = 0; i < raw.length; i++) {
        const key = raw[i].replace(/^--/, '');
        const val = raw[i + 1] && !raw[i + 1].startsWith('--') ? raw[++i] : 'true';
        args[key] = val;
    }
    return args;
}

const args = parseArgs();

if (!args.url || !args.output) {
    console.error('Usage: node obscura-screenshot.cjs --url <URL> --output <PATH> [options]');
    process.exit(1);
}

const url          = args.url;
const outputPath   = args.output;
const width        = parseInt(args.width  || '1440', 10);
const height       = parseInt(args.height || '1000', 10);
const fullPage     = args['full-page'] === 'true' || args['full-page'] === true;
const waitUntil    = args['wait-until'] || 'networkidle0';
const timeoutSec   = parseInt(args.timeout || '30', 10);
const binaryPath   = args.binary || '/usr/local/bin/obscura';

// ── Find a free TCP port ────────────────────────────────────────
function findFreePort() {
    return new Promise((resolve, reject) => {
        const srv = net.createServer();
        srv.listen(0, '127.0.0.1', () => {
            const port = srv.address().port;
            srv.close(() => resolve(port));
        });
        srv.on('error', reject);
    });
}

// ── Wait for Obscura CDP server to be ready ─────────────────────
function waitForServer(port, maxWaitMs) {
    const start = Date.now();
    return new Promise((resolve, reject) => {
        function tryConnect() {
            if (Date.now() - start > maxWaitMs) {
                reject(new Error('Obscura server did not become ready in time'));
                return;
            }
            const sock = net.connect(port, '127.0.0.1', () => {
                sock.destroy();
                resolve();
            });
            sock.on('error', () => setTimeout(tryConnect, 200));
        }
        tryConnect();
    });
}

// ── Main ────────────────────────────────────────────────────────
(async () => {
    let obscuraProc = null;
    let browser = null;

    try {
        // 1. Start Obscura CDP server on a free port
        const port = await findFreePort();
        obscuraProc = spawn(binaryPath, ['serve', '--port', String(port)], {
            stdio: ['ignore', 'pipe', 'pipe'],
            env: { ...process.env },
        });

        // Capture stderr for debugging
        let stderrBuf = '';
        obscuraProc.stderr.on('data', (d) => { stderrBuf += d.toString(); });

        // 2. Wait for server to be ready (max 10s)
        await waitForServer(port, 10000);

        // 3. Connect via Puppeteer
        browser = await puppeteer.connect({
            browserWSEndpoint: `ws://127.0.0.1:${port}/devtools/browser`,
        });

        const page = await browser.newPage();

        // 4. Set viewport dimensions
        await page.setViewport({ width, height, deviceScaleFactor: 1 });

        // 5. Navigate with waitUntil and timeout
        await page.goto(url, {
            waitUntil: waitUntil,
            timeout: timeoutSec * 1000,
        });

        // 6. Capture screenshot
        const screenshotOpts = {
            path: outputPath,
            type: 'png',
        };

        if (fullPage) {
            screenshotOpts.fullPage = true;
        } else {
            screenshotOpts.clip = { x: 0, y: 0, width, height };
        }

        await page.screenshot(screenshotOpts);

        // 7. Verify the file was written
        if (!fs.existsSync(outputPath) || fs.statSync(outputPath).size === 0) {
            throw new Error('Screenshot file was not created or is empty');
        }

        const actualSize = fs.statSync(outputPath).size;

        // Output JSON result to stdout for PHP to parse
        const result = {
            success: true,
            outputPath,
            fileSize: actualSize,
            width,
            height,
            fullPage,
        };
        console.log(JSON.stringify(result));

    } catch (err) {
        console.error(JSON.stringify({
            success: false,
            error: err.message,
            stderr: stderrBuf || '',
        }));
        process.exitCode = 3;
    } finally {
        // 8. Cleanup: disconnect Puppeteer, kill Obscura
        if (browser) {
            try { await browser.disconnect(); } catch (_) {}
        }
        if (obscuraProc) {
            try { obscuraProc.kill('SIGTERM'); } catch (_) {}
            // Force kill after 2s
            setTimeout(() => {
                try { obscuraProc.kill('SIGKILL'); } catch (_) {}
            }, 2000);
        }
    }
})();
