#!/usr/bin/env node

/**
 * Crawl a web page using Playwright and extract interactive elements,
 * forms, navigation, and metadata. Outputs JSON to stdout.
 *
 * Usage: node crawl-page.js <url> [--timeout=30000]
 */

const { chromium } = require('playwright');

const url = process.argv[2];
if (!url) {
    console.error('Usage: node crawl-page.js <url>');
    process.exit(1);
}

const timeout = parseInt(process.argv.find(a => a.startsWith('--timeout='))?.split('=')[1] || '30000', 10);

(async () => {
    const browser = await chromium.launch({ headless: true });
    const context = await browser.newContext({
        userAgent: 'SignalDeckCI/1.0 (Test Builder; +https://signaldeck.com)',
        viewport: { width: 1280, height: 720 },
    });
    const page = await context.newPage();

    try {
        await page.goto(url, { waitUntil: 'networkidle', timeout });
    } catch (e) {
        console.error(`Failed to load ${url}: ${e.message}`);
        await browser.close();
        process.exit(1);
    }

    const result = await page.evaluate(() => {
        const interactiveElements = [];
        const forms = [];
        const navigation = [];

        // Extract interactive elements
        const interactiveSelectors = 'a, button, input, select, textarea, [role="button"], [role="link"], [role="tab"], [onclick]';
        document.querySelectorAll(interactiveSelectors).forEach((el, i) => {
            if (i >= 200) return; // cap to avoid huge payloads

            const rect = el.getBoundingClientRect();
            if (rect.width === 0 && rect.height === 0) return;

            const tag = el.tagName.toLowerCase();
            const type = el.getAttribute('type') || '';
            const role = el.getAttribute('role') || '';
            const text = (el.textContent || '').trim().slice(0, 100);
            const ariaLabel = el.getAttribute('aria-label') || '';
            const name = el.getAttribute('name') || '';
            const id = el.id || '';
            const placeholder = el.getAttribute('placeholder') || '';
            const href = tag === 'a' ? el.getAttribute('href') || '' : '';

            let selector = '';
            if (el.getAttribute('data-testid')) {
                selector = `[data-testid="${el.getAttribute('data-testid')}"]`;
            } else if (id) {
                selector = `#${CSS.escape(id)}`;
            } else if (name && (tag === 'input' || tag === 'select' || tag === 'textarea')) {
                selector = `${tag}[name="${CSS.escape(name)}"]`;
            } else if (ariaLabel) {
                selector = `${tag}[aria-label="${ariaLabel.replace(/"/g, '\\"')}"]`;
            } else if (text && text.length < 50) {
                selector = `${tag}:has-text("${text.slice(0, 40).replace(/"/g, '\\"')}")`;
            }

            interactiveElements.push({
                tag, type, role, text, aria_label: ariaLabel,
                name, id, placeholder, href, selector,
            });
        });

        // Extract forms
        document.querySelectorAll('form').forEach((form, fi) => {
            if (fi >= 20) return;
            const fields = [];
            form.querySelectorAll('input, select, textarea').forEach((field, i) => {
                if (i >= 50) return;
                fields.push({
                    tag: field.tagName.toLowerCase(),
                    type: field.getAttribute('type') || '',
                    name: field.getAttribute('name') || '',
                    id: field.id || '',
                    placeholder: field.getAttribute('placeholder') || '',
                    required: field.hasAttribute('required'),
                    label: field.id
                        ? (document.querySelector(`label[for="${CSS.escape(field.id)}"]`)?.textContent || '').trim().slice(0, 80)
                        : '',
                });
            });

            forms.push({
                action: form.getAttribute('action') || '',
                method: (form.getAttribute('method') || 'get').toUpperCase(),
                id: form.id || '',
                fields,
            });
        });

        // Extract navigation
        document.querySelectorAll('nav a, header a, [role="navigation"] a').forEach((link, i) => {
            if (i >= 50) return;
            navigation.push({
                text: (link.textContent || '').trim().slice(0, 80),
                href: link.getAttribute('href') || '',
            });
        });

        // Page metadata
        const meta = {
            title: document.title || '',
            description: document.querySelector('meta[name="description"]')?.getAttribute('content') || '',
            h1: Array.from(document.querySelectorAll('h1')).map(h => h.textContent.trim().slice(0, 100)).slice(0, 5),
            url: window.location.href,
            has_login_form: !!document.querySelector('input[type="password"]'),
            has_search: !!document.querySelector('input[type="search"], [role="search"]'),
        };

        return {
            url: window.location.href,
            page_title: document.title || '',
            interactive_elements: interactiveElements,
            forms,
            navigation,
            meta,
        };
    });

    console.log(JSON.stringify(result));
    await browser.close();
})();
