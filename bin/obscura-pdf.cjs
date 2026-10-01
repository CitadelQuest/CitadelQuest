#!/usr/bin/env node
/**
 * Obscura PDF Helper for CitadelQuest (createPDF AI Tool — chromium engine)
 *
 * Spawns an Obscura CDP server, connects via Puppeteer, loads an HTML file,
 * and prints it to a PDF via CDP Page.printToPDF. Outputs the PDF to a file
 * path, then shuts down the server and exits.
 *
 * NOTE: Obscura's printToPDF rasterizes the page into an image, so the produced
 * PDF has no selectable/searchable text. For true vector text use the default
 * dompdf engine in AIToolPdfService.
 *
 * Usage:
 *   node obscura-pdf.cjs \
 *     --html-file /tmp/doc.html \
 *     --output /tmp/doc.pdf \
 *     [--format A4] [--landscape] [--margin 15mm] \
 *     [--width 1200] [--timeout 60] [--binary /usr/local/bin/obscura]
 *
 * Exit codes:
 *   0 = success
 *   1 = argument error
 *   2 = obscura failed to start
 *   3 = puppeteer connection / navigation / print error
 *   4 = pdf write error
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

if (!args['html-file'] || !args.output) {
    console.error('Usage: node obscura-pdf.cjs --html-file <PATH> --output <PATH> [options]');
    process.exit(1);
}

const htmlFile   = args['html-file'];
const outputPath = args.output;
const format     = args.format || 'A4';
const landscape  = args.landscape === 'true' || args.landscape === true;
const margin     = args.margin || '15mm';
const width      = parseInt(args.width || '1200', 10);
const timeoutSec = parseInt(args.timeout || '60', 10);
const binaryPath = args.binary || '/usr/local/bin/obscura';

// Target page aspect ratio (height / width). A4 portrait ≈ 1.4143.
const aspect = parseFloat(args.aspect || '1.4142857') || 1.4142857;

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

// ── Best-effort page count from PDF bytes ───────────────────────
function countPdfPages(bytes) {
    const matches = bytes.toString('latin1').match(/\/Type\s*\/Page(?![s])/g);
    return matches ? matches.length : null;
}

// ── Main ────────────────────────────────────────────────────────
(async () => {
    let obscuraProc = null;
    let browser = null;
    let stderrBuf = '';

    try {
        if (!fs.existsSync(htmlFile)) {
            throw new Error('HTML file not found: ' + htmlFile);
        }
        const html = fs.readFileSync(htmlFile, 'utf8');

        // 1. Start Obscura CDP server on a free port
        const port = await findFreePort();
        obscuraProc = spawn(binaryPath, ['serve', '--port', String(port)], {
            stdio: ['ignore', 'pipe', 'pipe'],
            env: { ...process.env },
        });
        obscuraProc.stderr.on('data', (d) => { stderrBuf += d.toString(); });

        // 2. Wait for server to be ready (max 10s)
        await waitForServer(port, 10000);

        // 3. Connect via Puppeteer
        browser = await puppeteer.connect({
            browserWSEndpoint: `ws://127.0.0.1:${port}/devtools/browser`,
        });
        const page = await browser.newPage();
        await page.setViewport({ width, height: Math.max(1, Math.round(width * aspect)), deviceScaleFactor: 1 });

        // 4. Load the HTML via setContent (no file:// access needed)
        await page.setContent(html, {
            waitUntil: 'load',
            timeout: timeoutSec * 1000,
        });

        // 5. Derive the canvas from the browser's ACTUAL layout width.
        // Some Obscura builds use the window width (not the emulated viewport width)
        // for short content, which makes the raster aspect ratio wrong and leaves a
        // white band at the bottom of the page. Aligning the viewport to the real
        // layout width and deriving the height from it makes the raster aspect match
        // the target page whatever width Obscura uses. (Short content captures at the
        // viewport height, so this also fills the page.)
        const layoutWidth = await page.evaluate(() =>
            Math.max(window.innerWidth || 0, document.documentElement.clientWidth || 0, 1)
        );
        const pageHeight = Math.max(1, Math.round(layoutWidth * aspect));

        await page.setViewport({ width: layoutWidth, height: pageHeight, deviceScaleFactor: 1 });

        // 6. Print to PDF
        const marginOpt = { top: margin, right: margin, bottom: margin, left: margin };
        const pdfBuffer = await page.pdf({
            format,
            landscape,
            printBackground: true,
            margin: marginOpt,
        });

        // 7. Write and validate the PDF
        fs.writeFileSync(outputPath, pdfBuffer);

        if (!fs.existsSync(outputPath) || fs.statSync(outputPath).size === 0) {
            throw new Error('PDF file was not created or is empty');
        }

        const bytes = fs.readFileSync(outputPath);
        if (bytes.subarray(0, 5).toString('latin1') !== '%PDF-') {
            throw new Error('Invalid PDF file (bad signature)');
        }

        console.log(JSON.stringify({
            success: true,
            outputPath,
            fileSize: bytes.length,
            pages: countPdfPages(bytes),
            format,
            landscape,
            layoutWidth,
            pageHeight,
            rasterized: true,
        }));

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
            setTimeout(() => {
                try { obscuraProc.kill('SIGKILL'); } catch (_) {}
            }, 2000);
        }
    }
})();
