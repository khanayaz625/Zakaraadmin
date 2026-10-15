/**
 * Zakara Bags Admin Console - Local Backend Server & WordPress Proxy
 * 
 * Solves browser CORS restrictions completely by proxying requests 
 * directly to https://www.zakarabags.com with WooCommerce REST API credentials.
 * 
 * Run with: node server.js
 */

const http = require('http');
const https = require('https');
const fs = require('fs');
const path = require('path');
const url = require('url');

const PORT = process.env.PORT || 5501;
const WP_SITE = process.env.WP_SITE || 'https://www.zakarabags.com';
const CONSUMER_KEY = process.env.CONSUMER_KEY || 'ck_84109e5d48a5a5c104116f05c878b2ea2c34193d';
const CONSUMER_SECRET = process.env.CONSUMER_SECRET || 'cs_23a5f306edfcb5dec50e30f3b9f80fa3b1b6e7f5';

const MIME_TYPES = {
    '.html': 'text/html; charset=UTF-8',
    '.css': 'text/css',
    '.js': 'application/javascript',
    '.json': 'application/json',
    '.png': 'image/png',
    '.jpg': 'image/jpeg',
    '.jpeg': 'image/jpeg',
    '.gif': 'image/gif',
    '.svg': 'image/svg+xml',
    '.ico': 'image/x-icon'
};

const server = http.createServer(async (req, res) => {
    // Enable CORS for all local requests
    res.setHeader('Access-Control-Allow-Origin', '*');
    res.setHeader('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS');
    res.setHeader('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-Requested-With');

    if (req.method === 'OPTIONS') {
        res.writeHead(200);
        res.end();
        return;
    }

    const parsedUrl = url.parse(req.url, true);
    const pathname = parsedUrl.pathname;

    // =========================================================================
    // 1. API PROXY ROUTE: /api/products and /api/categories
    // =========================================================================
    if (pathname.startsWith('/api/')) {
        let wpApiPath = '';

        if (pathname === '/api/products' || pathname === '/api/products/') {
            wpApiPath = '/wp-json/wc/v3/products';
        } else if (pathname.startsWith('/api/products/')) {
            const id = pathname.replace('/api/products/', '').split('/')[0];
            wpApiPath = `/wp-json/wc/v3/products/${id}`;
        } else if (pathname === '/api/categories') {
            wpApiPath = '/wp-json/wc/v3/products/categories';
        } else if (pathname === '/api/health') {
            res.writeHead(200, { 'Content-Type': 'application/json' });
            res.end(JSON.stringify({ status: 'ok', server: 'Zakara Proxy', port: PORT }));
            return;
        }

        if (wpApiPath) {
            // Append auth credentials and any query params
            const targetParams = new URLSearchParams(parsedUrl.query);
            targetParams.set('consumer_key', CONSUMER_KEY);
            targetParams.set('consumer_secret', CONSUMER_SECRET);
            targetParams.set('_t', Date.now());

            const targetUrl = `${WP_SITE}${wpApiPath}?${targetParams.toString()}`;

            // Read request body for POST / PUT
            let bodyData = '';
            req.on('data', chunk => { bodyData += chunk; });
            req.on('end', async () => {
                try {
                    const fetchOptions = {
                        method: req.method,
                        headers: {
                            'Content-Type': 'application/json',
                            'User-Agent': 'ZakaraAdminConsole/1.0'
                        }
                    };

                    if (['POST', 'PUT', 'PATCH'].includes(req.method) && bodyData) {
                        fetchOptions.body = bodyData;
                    }

                    console.log(`[Proxy] ${req.method} ${targetUrl}`);
                    const response = await fetch(targetUrl, fetchOptions);
                    const responseData = await response.text();

                    res.writeHead(response.status, {
                        'Content-Type': response.headers.get('content-type') || 'application/json',
                        'Access-Control-Allow-Origin': '*'
                    });
                    res.end(responseData);

                } catch (err) {
                    console.error('[Proxy Error]', err.message);
                    res.writeHead(500, { 'Content-Type': 'application/json' });
                    res.end(JSON.stringify({ error: true, message: err.message }));
                }
            });
            return;
        }
    }

    // =========================================================================
    // 2. STATIC FILE SERVER (admin.html, allproducts.html, etc.)
    // =========================================================================
    let filePath = path.join(__dirname, pathname === '/' ? 'admin.html' : pathname);

    if (fs.existsSync(filePath) && fs.statSync(filePath).isDirectory()) {
        filePath = path.join(filePath, 'admin.html');
    }

    if (fs.existsSync(filePath) && fs.statSync(filePath).isFile()) {
        const ext = path.extname(filePath).toLowerCase();
        const contentType = MIME_TYPES[ext] || 'application/octet-stream';

        res.writeHead(200, { 'Content-Type': contentType });
        fs.createReadStream(filePath).pipe(res);
    } else {
        res.writeHead(404, { 'Content-Type': 'text/plain' });
        res.end('File not found: ' + pathname);
    }
});

server.listen(PORT, () => {
    console.log('================================================================');
    console.log(`🚀 Zakara Bags Admin Console is RUNNING on: http://localhost:${PORT}`);
    console.log(`🔗 Direct Admin URL: http://localhost:${PORT}/admin.html`);
    console.log(`🌐 Connected WordPress Site: ${WP_SITE}`);
    console.log('✅ CORS bypass active. Requests to WordPress are proxied directly.');
    console.log('================================================================');
});
