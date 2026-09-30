/**
 * The workspace client.
 *
 * Long-polls WordPress for commands, runs them against same-origin iframes, and
 * posts the answers back. Same-origin is what makes this work at all: the agent
 * gets to read and drive real rendered pages rather than guess at HTML, and no
 * local bridge has to be installed for it.
 */
(function () {
    'use strict';

    var __ = wp.i18n.__, _n = wp.i18n._n, _x = wp.i18n._x, sprintf = wp.i18n.sprintf;

    // What the person reads is translated; what goes back to the agent —
    // results, errors, notes — stays English, because the agent reads it as
    // instructions and the server does not know the locale. Prompts to paste
    // are the person's own words, so they translate, but the workflow, skill
    // and ability names inside them stay exactly as the agent will look them up.

    var cfg = window.nibwpVisual || {};
    var stage = document.getElementById('nw-vs-stage');
    var tabsEl = document.getElementById('nw-vs-tabs');
    var emptyEl = document.getElementById('nw-vs-empty');
    var statusEl = document.getElementById('nw-vs-status');
    var logEl = document.getElementById('nw-vs-log');
    var askEl = document.getElementById('nw-vs-ask');
    var askWhat = document.getElementById('nw-vs-ask-what');

    // This tab's identity. Opening the workspace takes it over from any older
    // tab, so two open copies cannot split the agent's commands between them.
    var session = Math.random().toString(36).slice(2, 12);
    var standDown = false;

    var pages = new Map();
    var startEl = document.getElementById('nw-vs-start');
    var startBack = document.getElementById('nw-vs-start-back');
    var startPinned = false;
    var activeId = null;
    var seq = 0;
    var pending = null;

    /* ------------------------------------------------------------ helpers -- */

    /**
     * admin-ajax, not REST. A REST request boots the MCP adapter and registers
     * every ability on the site before it will answer 'anything for me?', and
     * this asks that question all day.
     */
    // The long poll holds a PHP worker for up to ten seconds, so anything the
    // user clicks meanwhile queues behind it and looks frozen. Interactive
    // calls cut the poll short first; it restarts immediately afterwards.
    var pollAbort = null;

    function yieldPoll() {
        if (pollAbort) {
            try { pollAbort.abort(); } catch (e) { /* already gone */ }
            pollAbort = null;
        }
    }

    function api(action, fields, signal) {
        var body = new URLSearchParams();
        body.set('action', 'nibwp_visual_' + action);
        body.set('nonce', cfg.nonce);
        Object.keys(fields || {}).forEach(function (k) { body.set(k, fields[k]); });

        return fetch(cfg.ajax, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body.toString(),
            signal: signal || undefined
        });
    }

    function setStatus(text, busy) {
        if (!statusEl) { return; }
        statusEl.querySelector('.nw-vs-status__text').textContent = text;
        statusEl.classList.toggle('is-busy', !!busy);
        // Busy outranks the resting colour, but the resting colour has to come
        // back afterwards rather than being lost on the first action.
        if (!busy) {
            statusEl.classList.toggle('is-ready', !!cfg.connected);
            statusEl.classList.toggle('is-off', !cfg.connected);
        }
    }

    function log(action, detail, tone) {
        if (!logEl) { return; }
        var row = document.createElement('div');
        row.className = 'nw-vs-log__row' + (tone ? ' is-' + tone : '');
        var t = new Date();
        row.innerHTML = '<span class="nw-vs-log__time"></span>'
            + '<span class="nw-vs-log__act"></span>'
            + '<span class="nw-vs-log__det" title=""></span>';
        row.children[0].textContent = t.toTimeString().slice(0, 8);
        row.children[1].textContent = action;
        row.children[2].textContent = detail || '';
        row.children[2].title = detail || '';
        logEl.prepend(row);
        while (logEl.children.length > 200) { logEl.removeChild(logEl.lastChild); }
        if (typeof noteActivity === 'function') { noteActivity(); }
    }

    /**
     * Only this site — but judged by host, not by exact origin.
     *
     * An agent builds URLs from whatever it was told, and http vs https, or a
     * port that is present in one and not the other, made an identical site
     * look like a different one. Every open was then refused for a page that
     * was plainly this site. Matching the host and rebuilding the URL on the
     * site's own scheme and port keeps the guard — another host is still
     * refused — without failing on a detail the agent had no way to know.
     */
    function sameSite(url) {
        try {
            var target = new URL(url, cfg.home);
            var home = new URL(cfg.home);

            if (target.hostname.toLowerCase() !== home.hostname.toLowerCase()) {
                return null;
            }

            target.protocol = home.protocol;
            target.port = home.port;

            return target.href;
        } catch (e) {
            return null;
        }
    }

    function activePage() {
        return activeId ? pages.get(activeId) : null;
    }

    function doc(page) {
        try {
            return page.frame.contentDocument;
        } catch (e) {
            return null;
        }
    }

    /* -------------------------------------------------------------- tabs -- */

    function renderTabs() {
        tabsEl.innerHTML = '';
        pages.forEach(function (page) {
            var tab = document.createElement('button');
            tab.type = 'button';
            tab.className = 'nw-vs-tab' + (page.id === activeId ? ' is-active' : '') + (page.blocked ? ' is-blocked' : '');
            tab.setAttribute('role', 'tab');
            tab.setAttribute('aria-selected', page.id === activeId ? 'true' : 'false');

            var label = document.createElement('span');
            label.className = 'nw-vs-tab__label';
            label.textContent = page.title || page.url;
            tab.appendChild(label);

            var close = document.createElement('span');
            close.className = 'nw-vs-tab__x';
            close.textContent = '×';
            close.setAttribute('aria-hidden', 'true');
            tab.appendChild(close);

            tab.addEventListener('click', function (e) {
                if (e.target === close) { closePage(page.id); return; }
                focusPage(page.id);
            });

            tabsEl.appendChild(tab);
        });

        tabsEl.hidden = pages.size === 0;
        // Three things can own the stage: an open page, the "appears here"
        // note, and the connect screen. Exactly one of them at a time.
        emptyEl.hidden = pages.size > 0 || !cfg.connected;
        if (pages.size > 0 && !startPinned) { showStart(false); }
        if (typeof syncStartBack === 'function') { syncStartBack(); }
    }

    function openPage(url, title) {
        var safe = sameSite(url);
        if (!safe) {
            // Name what was rejected. 'Not on this site' with no URL sends the
            // agent guessing, and sent me guessing too.
            return Promise.reject(new Error(
                'Refused to open "' + url + '" — the workspace only opens pages on ' + cfg.home
            ));
        }

        var id = 'p' + (++seq);
        var frame = document.createElement('iframe');
        frame.className = 'nw-vs-frame';
        // The headless runner addresses frames from outside the document, where
        // the pages map does not exist — this attribute is the only handle it has.
        frame.setAttribute('data-vs-frame', id);
        frame.src = safe;
        // No allow-top-navigation: a page must not be able to replace the
        // workspace around it.
        frame.setAttribute('sandbox', 'allow-same-origin allow-scripts allow-forms allow-popups allow-modals');
        stage.appendChild(frame);

        var page = { id: id, url: safe, title: title || safe, frame: frame, errors: [] };
        pages.set(id, page);
        activeId = id;
        if (!panelChosen && pages.size === 1) { setPanel(false, false); }
        renderTabs();
        showActive();

        return new Promise(function (resolve, reject) {
            var done = false;
            function settle() {
                if (done) { return; }
                done = true;
                var d = null;
                try {
                    d = doc(page);
                } catch (e) { d = null; }

                // A frame that loaded but exposes no document was refused by the
                // server, almost always X-Frame-Options DENY from a security
                // plugin. Left alone this is a blank tab and no explanation, so
                // say exactly what happened and what to change.
                if (!d || !d.body) {
                    page.blocked = true;
                    renderTabs();
                    showBlocked(page);
                    reject(new Error(
                        'This site refused to be displayed in a frame, so the workspace cannot show ' + page.url
                        + '. A security plugin is sending X-Frame-Options: DENY or a frame-ancestors rule. '
                        + 'Allow same-origin framing for this site and try again.'
                    ));
                    return;
                }

                page.title = d.title || page.title;
                page.url = d.location.href;
                watchErrors(page);
                renderTabs();
                persistTabs();
                resolve(summarise(page));
            }
            frame.addEventListener('load', settle);
            setTimeout(settle, 15000);

            // Every load after the first one. A tab that followed a link or
            // submitted a form was still reporting the address it was opened
            // with, so an agent asking where it is got an answer that was true
            // several actions ago — and the error listeners it had attached
            // died with the document they were attached to.
            frame.addEventListener('load', function () {
                if (!done) { return; }
                var now = doc(page);
                if (!now || !now.body) { return; }
                page.title = now.title || page.title;
                page.url = now.location.href;
                page.watched = null;
                watchErrors(page);
                renderTabs();
                persistTabs();
            });
        });
    }

    function focusPage(id, silent) {
        if (!pages.has(id)) { return null; }
        activeId = id;
        renderTabs();
        showActive();
        persistTabs();
        if (!silent) { log('focus', pages.get(id).title); }
        return summarise(pages.get(id));
    }

    function closePage(id) {
        var page = pages.get(id);
        if (!page) { return; }
        page.frame.remove();
        if (page.panel) { page.panel.remove(); }
        pages.delete(id);
        if (activeId === id) { activeId = pages.size ? pages.keys().next().value : null; }
        renderTabs();
        showActive();
        persistTabs();
    }

    function showActive() {
        pages.forEach(function (page) {
            var el = page.blocked ? page.panel : page.frame;
            if (el) { el.style.display = page.id === activeId ? 'block' : 'none'; }
        });
    }

    /**
     * Explain a refused frame in place of the blank tab it would otherwise be.
     */
    function showBlocked(page) {
        page.frame.remove();
        var panel = document.createElement('div');
        panel.className = 'nw-vs-blocked';
        panel.innerHTML = '<h2></h2><p></p><p class="nw-vs-blocked__url"></p>';
        panel.children[0].textContent = __('This page refused to be framed', 'nibwp');
        panel.children[1].textContent = __('A security plugin or server rule is sending X-Frame-Options: DENY, or a Content-Security-Policy frame-ancestors that excludes this site. Allow same-origin framing and reopen the page.', 'nibwp');
        panel.children[2].textContent = page.url;
        page.panel = panel;
        stage.appendChild(panel);
        showActive();
    }

    /* -------------------------------------------------------- persistence -- */

    var STORE = 'nibwp-visual-tabs';

    /** Reloading the workspace should not lose where the agent had got to. */
    function persistTabs() {
        try {
            var open = [];
            pages.forEach(function (p) {
                if (!p.blocked) { open.push({ url: p.url, title: p.title }); }
            });
            window.localStorage.setItem(STORE, JSON.stringify({ pages: open.slice(0, 12) }));
        } catch (e) { /* private mode, or the quota is full; not worth failing over */ }
    }

    function restoreTabs() {
        var saved;
        try {
            saved = JSON.parse(window.localStorage.getItem(STORE) || 'null');
        } catch (e) { return; }
        if (!saved || !Array.isArray(saved.pages)) { return; }

        saved.pages.forEach(function (p) {
            if (p && typeof p.url === 'string') {
                openPage(p.url, p.title).catch(function () { /* it may have gone */ });
            }
        });
    }

    /* ------------------------------------------------------------ errors -- */

    /**
     * Errors the page throws are what the agent needs when something misbehaves.
     *
     * Re-attached after every navigation, because listeners belong to a window
     * and a new document is a new window. Attaching once at open meant that the
     * moment a link was followed or a form submitted, the workspace stopped
     * hearing anything the page said — and reported an empty console for a page
     * that was throwing on every load.
     */
    function watchErrors(page) {
        var win = page.frame.contentWindow;
        if (!win || page.watched === win) { return; }
        page.watched = win;

        win.addEventListener('error', function (e) {
            page.errors.push({ type: 'error', message: String(e.message), source: String(e.filename || ''), line: e.lineno || 0 });
        });
        win.addEventListener('unhandledrejection', function (e) {
            page.errors.push({ type: 'rejection', message: String((e.reason && e.reason.message) || e.reason || '') });
        });
    }

    /* ----------------------------------------------------------- reading -- */

    function cssPath(el) {
        if (el.id) { return '#' + CSS.escape(el.id); }
        var parts = [];
        var node = el;
        while (node && node.nodeType === 1 && parts.length < 5) {
            var part = node.tagName.toLowerCase();
            if (node.classList.length) {
                part += '.' + Array.from(node.classList).slice(0, 2).map(function (c) { return CSS.escape(c); }).join('.');
            }
            var parent = node.parentElement;
            if (parent) {
                var same = Array.from(parent.children).filter(function (c) { return c.tagName === node.tagName; });
                if (same.length > 1) { part += ':nth-of-type(' + (same.indexOf(node) + 1) + ')'; }
            }
            parts.unshift(part);
            if (node.id) { parts[0] = '#' + CSS.escape(node.id); break; }
            node = parent;
        }
        return parts.join(' > ');
    }

    function visible(el) {
        var r = el.getBoundingClientRect();
        if (r.width === 0 && r.height === 0) { return false; }
        var s = el.ownerDocument.defaultView.getComputedStyle(el);
        return s.visibility !== 'hidden' && s.display !== 'none' && s.opacity !== '0';
    }

    function summarise(page) {
        var d = doc(page);
        if (!d) {
            return { id: page.id, url: page.url, title: page.title, note: 'The page is on another origin, so it cannot be read.' };
        }
        return { id: page.id, url: d.location.href, title: d.title, ready: d.readyState };
    }

    /* Page coordinates rather than viewport ones. A click scrolls its target
       into view first, so viewport boxes from two reads either side of it
       would disagree about where the same element is. */
    function boxOf(el) {
        var r = el.getBoundingClientRect();
        var win = el.ownerDocument.defaultView;
        return {
            x: Math.round(r.left + win.scrollX),
            y: Math.round(r.top + win.scrollY),
            w: Math.round(r.width),
            h: Math.round(r.height)
        };
    }

    /* The handful of computed properties that decide whether something looks
       the way it was meant to. Not the whole computed style: an agent handed
       three hundred properties per element diffs none of them. */
    function styleOf(el) {
        var cs = el.ownerDocument.defaultView.getComputedStyle(el);
        var out = { color: cs.color };

        // A transparent background says nothing about what the element sits
        // on, and "transparent" is what almost every element reports. What
        // the agent needs then is the colour actually showing through.
        var own = readColor(cs.backgroundColor);
        if (own && own.a > 0) {
            out.background = cs.backgroundColor;
        } else {
            out.surface = colorText(surfaceFrom(el.parentElement));
        }

        out['font-size'] = cs.fontSize;
        out['font-weight'] = cs.fontWeight;

        var radius = cs.borderRadius || cs.borderTopLeftRadius;
        if (radius && radius !== '0px') { out['border-radius'] = radius; }

        // Only borders that paint. Every element has four border colours in
        // its computed style, most of them on a zero-width or none border.
        var sides = bordersOf(cs);
        if (sides.length) {
            out.border = { width: sides[0].width, style: sides[0].style, color: sides[0].color };
            if (sides.length < 4) {
                out.border.sides = sides.map(function (s) { return s.side; });
            }
        }

        return out;
    }

    // Styles make each entry several times larger, and a read too big for the
    // agent's context is a read it never sees. The ceiling drops when they are
    // asked for, and the answer says when it was reached.
    var STYLED_CAP = 100;

    function readPage(payload) {
        var page = activePage();
        if (!page) { throw new Error('No page is open. Use visual-open first.'); }
        var d = doc(page);
        if (!d) { throw new Error('The active page is on another origin and cannot be read.'); }

        var root = payload.selector ? d.querySelector(payload.selector) : d.body;
        if (!root) { throw new Error('Nothing matches the selector ' + payload.selector); }

        var styles = !!payload.styles;
        // max_elements as well, because a batch step carries the ability's own
        // argument names rather than the ones the ability translates them to.
        var cap = Math.max(10, Math.min(payload.maxElements || payload.max_elements || 150, styles ? STYLED_CAP : 400));
        var out = { url: d.location.href, title: d.title, headings: [], elements: [], text: '' };
        var dropped = 0;

        // The region itself is often the thing in question — a card whose
        // border cannot be seen has no interactive element to carry that.
        if (styles && payload.selector) {
            out.region = { selector: payload.selector, box: boxOf(root), style: styleOf(root) };
        }

        root.querySelectorAll('h1, h2, h3, h4').forEach(function (h) {
            if (!visible(h)) { return; }
            if (styles && out.headings.length >= cap) { dropped++; return; }
            var heading = { level: Number(h.tagName[1]), text: h.textContent.trim().slice(0, 160) };
            if (styles) {
                heading.box = boxOf(h);
                heading.style = styleOf(h);
            }
            out.headings.push(heading);
        });

        var selector = 'a[href], button, input, select, textarea, [role="button"], [contenteditable="true"]';
        var seen = 0;
        root.querySelectorAll(selector).forEach(function (el) {
            if (!visible(el)) { return; }
            if (seen >= cap) { dropped++; return; }
            seen++;
            var item = {
                tag: el.tagName.toLowerCase(),
                selector: cssPath(el),
                label: (el.getAttribute('aria-label') || el.value || el.textContent || el.getAttribute('placeholder') || '').trim().slice(0, 120)
            };
            if (el.type) { item.type = el.type; }
            if (el.name) { item.name = el.name; }
            if (el.href) { item.href = el.href; }
            if (el.disabled) { item.disabled = true; }
            if (styles) {
                item.box = boxOf(el);
                item.style = styleOf(el);
            }
            out.elements.push(item);
        });

        out.text = (root.innerText || '').replace(/\s+\n/g, '\n').trim().slice(0, 6000);
        out.truncated = dropped > 0;
        if (out.truncated && styles) {
            out.note = 'Styles were requested, so at most ' + cap + ' headings and ' + cap
                + ' elements are returned. Pass a selector to read one section at a time.';
        }

        return out;
    }

    /* ------------------------------------------------------- interaction -- */

    /** Show the person what is about to be touched before touching it. */
    function spotlight(el) {
        var d = el.ownerDocument;
        el.scrollIntoView({ block: 'center', behavior: 'smooth' });
        var mark = d.createElement('div');
        var r = el.getBoundingClientRect();
        mark.style.cssText = [
            'position:fixed', 'z-index:2147483647', 'pointer-events:none',
            'border:2px solid #00a32a', 'border-radius:4px',
            'box-shadow:0 0 0 4px rgba(0,163,42,.25)',
            'left:' + (r.left - 2) + 'px', 'top:' + (r.top - 2) + 'px',
            'width:' + r.width + 'px', 'height:' + r.height + 'px',
            'transition:opacity .3s ease'
        ].join(';');
        d.body.appendChild(mark);
        setTimeout(function () { mark.style.opacity = '0'; }, 500);
        setTimeout(function () { mark.remove(); }, 900);
    }

    function pick(payload) {
        var page = activePage();
        if (!page) { throw new Error('No page is open. Use visual-open first.'); }
        var d = doc(page);
        if (!d) { throw new Error('The active page is on another origin.'); }
        var el = d.querySelector(payload.selector);
        if (!el) { throw new Error('Nothing matches the selector ' + payload.selector); }
        return { page: page, doc: d, el: el };
    }

    function clickIt(payload) {
        var found = pick(payload);
        spotlight(found.el);
        var before = found.doc.location.href;
        found.el.click();

        return new Promise(function (resolve) {
            setTimeout(function () {
                var d = doc(found.page);
                resolve({
                    clicked: payload.selector,
                    navigated: d ? d.location.href !== before : true,
                    url: d ? d.location.href : found.page.url,
                    title: d ? d.title : found.page.title
                });
            }, 700);
        });
    }

    /* Styles worth comparing across a hover. Kept short on purpose: the point
       is to answer "did the hover state change, and to what", not to dump a
       full computed-style object the agent then has to diff itself. */
    var HOVER_PROPS = [
        'color', 'background-color', 'border-color', 'outline-color',
        'text-decoration-line', 'opacity', 'visibility', 'transform', 'box-shadow'
    ];

    function styleSnapshot(win, el) {
        var cs = win.getComputedStyle(el);
        var out = {};
        HOVER_PROPS.forEach(function (prop) { out[prop] = cs.getPropertyValue(prop); });
        return out;
    }

    /* A hover cannot be read from the stylesheet — :hover rules can come from
       anywhere in the cascade, and a menu that only appears on hover is not in
       the DOM's visible set until it does. So dispatch a real pointer sequence
       and read what the page actually became. */
    function hoverIt(payload) {
        var found = pick(payload);
        var el = found.el;
        var win = found.doc.defaultView;
        spotlight(el);

        var before = styleSnapshot(win, el);
        var visibleBefore = found.doc.querySelectorAll('*').length;

        var r = el.getBoundingClientRect();
        var at = {
            bubbles: true,
            cancelable: true,
            clientX: r.left + r.width / 2,
            clientY: r.top + r.height / 2,
            view: win
        };
        // pointerenter/mouseenter do not bubble, so they are dispatched on the
        // element itself rather than relied on to propagate from a parent.
        ['pointerover', 'pointerenter', 'mouseover', 'mouseenter', 'mousemove'].forEach(function (type) {
            var Ctor = type.indexOf('pointer') === 0 && win.PointerEvent ? win.PointerEvent : win.MouseEvent;
            el.dispatchEvent(new Ctor(type, at));
        });

        var settle = typeof payload.settle === 'number' ? Math.min(Math.max(payload.settle, 0), 3000) : 400;

        return new Promise(function (resolve) {
            setTimeout(function () {
                var after = styleSnapshot(win, el);
                var changed = {};
                HOVER_PROPS.forEach(function (prop) {
                    if (before[prop] !== after[prop]) {
                        changed[prop] = { from: before[prop], to: after[prop] };
                    }
                });

                // Something that appears on hover — a submenu, a tooltip — is
                // the other half of what a hover check is looking for, and it
                // shows up as nodes the page did not have a moment ago.
                var appeared = found.doc.querySelectorAll('*').length - visibleBefore;

                if (!payload.hold) {
                    ['mouseout', 'mouseleave', 'pointerout', 'pointerleave'].forEach(function (type) {
                        var Ctor = type.indexOf('pointer') === 0 && win.PointerEvent ? win.PointerEvent : win.MouseEvent;
                        el.dispatchEvent(new Ctor(type, at));
                    });
                }

                resolve({
                    hovered: payload.selector,
                    changed: changed,
                    unchanged: Object.keys(changed).length === 0,
                    before: before,
                    after: after,
                    nodes_added: appeared,
                    held: !!payload.hold,
                    url: found.page.url
                });
            }, settle);
        });
    }

    /* A page cannot photograph itself. Rasterising the DOM in JavaScript looks
       like an answer and is not — it re-renders rather than captures, so canvas,
       WebGL and cross-origin images come out wrong, and a screenshot that is not
       what the browser drew is worse than none for spotting a visual regression.

       So the pixels come from outside the page: the headless runner drives this
       workspace through a real browser and hands it a binding. When that binding
       is absent this is a human-attended tab, and saying so plainly beats
       returning an approximation nobody can trust. */
    function shootIt(payload) {
        var page = activePage();
        if (!page) { throw new Error('No page is open. Use visual-open first.'); }

        if (typeof window.__nibwpShot !== 'function') {
            throw new Error(
                'Screenshots need the headless runner. This workspace is a browser tab, '
                + 'which cannot capture its own pixels. Start the runner (npx nibwp-runner) '
                + 'and it will serve this workspace instead.'
            );
        }

        return Promise.resolve(window.__nibwpShot({
            frame: page.id,
            selector: payload.selector || '',
            fullPage: !!payload.full_page
        })).then(function (shot) {
            if (!shot || shot.error) {
                throw new Error(shot && shot.error ? shot.error : 'The runner returned no image.');
            }

            return {
                url: shot.url || null,
                media_id: shot.media_id || null,
                width: shot.width || null,
                height: shot.height || null,
                bytes: shot.bytes || null,
                page_url: page.url,
                selector: payload.selector || null,
                full_page: !!payload.full_page
            };
        });
    }

    function fillIt(payload) {
        var found = pick(payload);
        var el = found.el;
        spotlight(el);

        var setter = Object.getOwnPropertyDescriptor(
            el.tagName === 'TEXTAREA' ? window.HTMLTextAreaElement.prototype
                : el.tagName === 'SELECT' ? window.HTMLSelectElement.prototype
                    : window.HTMLInputElement.prototype,
            'value'
        );
        // Assign through the prototype setter so frameworks watching the field
        // see the change; a plain el.value = x is invisible to React and friends.
        if (setter && setter.set) { setter.set.call(el, payload.value); } else { el.value = payload.value; }

        el.dispatchEvent(new Event('input', { bubbles: true }));
        el.dispatchEvent(new Event('change', { bubbles: true }));

        if (payload.submit && el.form) {
            el.form.submit();
            return { filled: payload.selector, submitted: true };
        }

        return { filled: payload.selector, value: el.value, submitted: false };
    }

    /* ------------------------------------------------------------ colour -- */

    /* The colour maths touches no DOM, so it can be checked without a browser
       (tests/visual-border-contrast-check.js lifts these by name). Computed
       styles do not come back in one shape: legacy rgba() with commas, rgb()
       with a slash, or color(srgb …) when a token was built with color-mix(). */
    function parseColor(value) {
        var s = String(value || '').trim().toLowerCase();
        if (s === 'transparent') { return { r: 0, g: 0, b: 0, a: 0 }; }

        var hex = s.match(/^#([0-9a-f]{3,4}|[0-9a-f]{6}|[0-9a-f]{8})$/);
        if (hex) {
            var h = hex[1].length < 6
                ? hex[1].split('').map(function (c) { return c + c; }).join('')
                : hex[1];
            return {
                r: parseInt(h.slice(0, 2), 16),
                g: parseInt(h.slice(2, 4), 16),
                b: parseInt(h.slice(4, 6), 16),
                a: h.length === 8 ? parseInt(h.slice(6, 8), 16) / 255 : 1
            };
        }

        var fn = s.match(/^(rgba?|color)\(\s*(.*?)\s*\)$/);
        if (!fn) { return null; }

        var body = fn[2];
        // color() is only read in sRGB, where channels run 0-1. Wider spaces
        // need gamut mapping this does not attempt; readColor asks the browser.
        var unit = 1;
        if (fn[1] === 'color') {
            if (body.indexOf('srgb ') !== 0) { return null; }
            body = body.slice(5);
            unit = 255;
        }

        var alpha = '1';
        var halves = body.split('/');
        if (halves.length > 2) { return null; }
        if (halves.length === 2) {
            alpha = halves[1].trim();
            body = halves[0];
        }

        var parts = body.split(/[\s,]+/).filter(Boolean);
        if (parts.length === 4 && halves.length === 1 && fn[1] !== 'color') { alpha = parts.pop(); }
        if (parts.length !== 3) { return null; }

        function channel(raw) {
            var n = raw === 'none' ? 0 : parseFloat(raw);
            n = raw.slice(-1) === '%' ? n * 2.55 : n * unit;
            return Math.min(255, Math.max(0, n));
        }

        var a = alpha === 'none' ? 0 : parseFloat(alpha);
        if (alpha.slice(-1) === '%') { a = a / 100; }

        var color = { r: channel(parts[0]), g: channel(parts[1]), b: channel(parts[2]), a: Math.min(1, Math.max(0, a)) };
        // NaN survives min/max, so one bad channel poisons the sum. Better no
        // answer than a confident ratio computed from garbage.
        return isNaN(color.r + color.g + color.b + color.a) ? null : color;
    }

    /* Source-over, the way the browser paints one colour on another. A border
       at 20% is not the colour its token names: it is mostly whatever lies
       underneath, and that blend is what the eye actually compares. */
    function blendOver(top, bottom) {
        var a = top.a + bottom.a * (1 - top.a);
        if (a <= 0) { return { r: 0, g: 0, b: 0, a: 0 }; }
        function mix(t, b) { return (t * top.a + b * bottom.a * (1 - top.a)) / a; }
        return { r: mix(top.r, bottom.r), g: mix(top.g, bottom.g), b: mix(top.b, bottom.b), a: a };
    }

    /* WCAG relative luminance. Alpha is ignored on purpose: callers blend
       first, because a translucent colour has no luminance of its own. */
    function relativeLuminance(c) {
        var lin = [c.r, c.g, c.b].map(function (v) {
            var x = v / 255;
            return x <= 0.03928 ? x / 12.92 : Math.pow((x + 0.055) / 1.055, 2.4);
        });
        return 0.2126 * lin[0] + 0.7152 * lin[1] + 0.0722 * lin[2];
    }

    function contrastRatio(x, y) {
        var a = relativeLuminance(x), b = relativeLuminance(y);
        return (Math.max(a, b) + 0.05) / (Math.min(a, b) + 0.05);
    }

    // Below this a border separates nothing. It is not a WCAG number — WCAG's
    // 3:1 for non-text UI would flag every hairline divider on the web — it is
    // the point under which a border stops being visible at all.
    var BORDER_FLOOR = 1.5;

    /* How far a border stands out, as the better of its two edges: against
       the surface outside the element and against the element's own fill.
       Judging the outside alone flagged a border that matched the page but
       plainly framed a dark card; judging the inside alone missed a white
       card on a white page, which is exactly the build that shipped with
       invisible borders. Painted over the fill when the background runs under
       the border (background-clip: border-box, the default), else the surface. */
    function borderContrast(border, fill, surface, underBorder) {
        var inside = blendOver(fill, surface);
        var drawn = blendOver(border, underBorder ? inside : surface);
        return Math.max(contrastRatio(drawn, surface), contrastRatio(drawn, inside));
    }

    var colorCache = {};
    var colorCanvas = null;

    /* parseColor first; anything it cannot read — oklch(), lab(), display-p3 —
       the browser can, so let it paint one pixel and read back what it drew.
       Tokens built in those spaces were otherwise skipped by every check. */
    function readColor(value) {
        var key = String(value || '');
        if (Object.prototype.hasOwnProperty.call(colorCache, key)) { return colorCache[key]; }

        var color = parseColor(key);
        if (!color && key) {
            try {
                if (!colorCanvas) {
                    colorCanvas = document.createElement('canvas');
                    colorCanvas.width = 1;
                    colorCanvas.height = 1;
                }
                var ctx = colorCanvas.getContext('2d');
                // An invalid colour leaves fillStyle untouched, so a sentinel
                // that survives the assignment means the browser refused it.
                ctx.fillStyle = '#010203';
                ctx.fillStyle = key;
                if (ctx.fillStyle !== '#010203') {
                    ctx.clearRect(0, 0, 1, 1);
                    ctx.fillRect(0, 0, 1, 1);
                    var px = ctx.getImageData(0, 0, 1, 1).data;
                    color = { r: px[0], g: px[1], b: px[2], a: px[3] / 255 };
                }
            } catch (e) { color = null; }
        }

        // A page has a few dozen distinct colours; a workspace left open all
        // day visits many pages, so the cache is dropped rather than grown.
        if (Object.keys(colorCache).length > 500) { colorCache = {}; }
        colorCache[key] = color;
        return color;
    }

    function colorText(c) {
        var rgb = Math.round(c.r) + ', ' + Math.round(c.g) + ', ' + Math.round(c.b);
        return c.a < 1 ? 'rgba(' + rgb + ', ' + Math.round(c.a * 100) / 100 + ')' : 'rgb(' + rgb + ')';
    }

    /* The opaque colour showing behind a node: every translucent background up
       to the first solid one, blended in paint order. Taking the nearest
       non-transparent background alone reported a 5% tint as the surface and
       lost the dark section it sat on. Background images are not seen. */
    function surfaceFrom(node) {
        var layers = [];
        while (node && node.nodeType === 1) {
            var c = readColor(node.ownerDocument.defaultView.getComputedStyle(node).backgroundColor);
            if (c && c.a > 0) {
                layers.push(c);
                if (c.a >= 1) { break; }
            }
            node = node.parentElement;
        }
        // Nothing painted at all is the browser's white canvas.
        var surface = { r: 255, g: 255, b: 255, a: 1 };
        for (var i = layers.length - 1; i >= 0; i--) { surface = blendOver(layers[i], surface); }
        return surface;
    }

    var BORDER_SIDES = ['top', 'right', 'bottom', 'left'];

    /* Sides whose border actually paints. Under 1px is left out: it renders as
       a hairline or not at all depending on the screen, and is not a border
       anyone designed to be seen. */
    function bordersOf(cs) {
        var out = [];
        BORDER_SIDES.forEach(function (side) {
            var width = cs.getPropertyValue('border-' + side + '-width');
            var kind = cs.getPropertyValue('border-' + side + '-style');
            if ((parseFloat(width) || 0) >= 1 && kind !== 'none' && kind !== 'hidden') {
                out.push({ side: side, width: width, style: kind, color: cs.getPropertyValue('border-' + side + '-color') });
            }
        });
        return out;
    }

    /* ------------------------------------------------------------ audits -- */

    function contrast(fg, bg) {
        var a = readColor(fg), b = readColor(bg);
        if (!a || !b) { return null; }
        return contrastRatio(a, b);
    }

    function backdrop(el) {
        var node = el;
        while (node && node.nodeType === 1) {
            var bg = node.ownerDocument.defaultView.getComputedStyle(node).backgroundColor;
            if (bg && bg !== 'transparent' && !bg.startsWith('rgba(0, 0, 0, 0')) { return bg; }
            node = node.parentElement;
        }
        return 'rgb(255, 255, 255)';
    }

    function auditPage(payload) {
        var page = activePage();
        if (!page) { throw new Error('No page is open. Use visual-open first.'); }
        var d = doc(page);
        if (!d) { throw new Error('The active page is on another origin.'); }

        var want = (payload.checks && payload.checks.length) ? payload.checks
            : ['contrast', 'alt', 'labels', 'headings', 'overflow', 'borders'];
        var findings = [];
        var win = d.defaultView;

        if (want.indexOf('contrast') !== -1) {
            var texts = d.querySelectorAll('p, span, a, li, td, th, h1, h2, h3, h4, h5, h6, button, label');
            var checked = 0;
            texts.forEach(function (el) {
                if (checked >= 300 || !visible(el) || !el.textContent.trim()) { return; }
                checked++;
                var s = win.getComputedStyle(el);
                var ratio = contrast(s.color, backdrop(el));
                if (ratio === null) { return; }
                var size = parseFloat(s.fontSize);
                var large = size >= 24 || (size >= 18.66 && Number(s.fontWeight) >= 700);
                var floor = large ? 3 : 4.5;
                if (ratio < floor) {
                    findings.push({
                        check: 'contrast',
                        selector: cssPath(el),
                        detail: ratio.toFixed(2) + ':1 against a floor of ' + floor + ':1',
                        text: el.textContent.trim().slice(0, 60)
                    });
                }
            });
        }

        if (want.indexOf('alt') !== -1) {
            d.querySelectorAll('img').forEach(function (img) {
                if (!img.hasAttribute('alt')) {
                    findings.push({ check: 'alt', selector: cssPath(img), detail: 'No alt attribute at all. Use alt="" if it is decorative.', text: img.currentSrc || img.src });
                }
            });
        }

        if (want.indexOf('labels') !== -1) {
            d.querySelectorAll('input, select, textarea').forEach(function (el) {
                if (el.type === 'hidden' || el.type === 'submit' || el.type === 'button') { return; }
                var labelled = el.getAttribute('aria-label')
                    || el.getAttribute('aria-labelledby')
                    || (el.id && d.querySelector('label[for="' + CSS.escape(el.id) + '"]'))
                    || el.closest('label');
                if (!labelled) {
                    findings.push({ check: 'labels', selector: cssPath(el), detail: 'No label a screen reader can announce.', text: el.name || el.type });
                }
            });
        }

        if (want.indexOf('headings') !== -1) {
            var last = 0;
            d.querySelectorAll('h1, h2, h3, h4, h5, h6').forEach(function (h) {
                if (!visible(h)) { return; }
                var level = Number(h.tagName[1]);
                if (last && level > last + 1) {
                    findings.push({ check: 'headings', selector: cssPath(h), detail: 'Jumps from h' + last + ' to h' + level + '.', text: h.textContent.trim().slice(0, 60) });
                }
                last = level;
            });
        }

        if (want.indexOf('overflow') !== -1) {
            var width = d.documentElement.clientWidth;
            d.querySelectorAll('body *').forEach(function (el) {
                if (findings.length > 200 || !visible(el)) { return; }
                var r = el.getBoundingClientRect();
                if (r.width > 0 && r.right > width + 1) {
                    findings.push({ check: 'overflow', selector: cssPath(el), detail: 'Extends ' + Math.round(r.right - width) + 'px past the right edge.', text: (el.textContent || '').trim().slice(0, 40) });
                }
            });
        }

        // A border can be set, sized and styled and still not be there: its
        // token resolved to white at 20% on a white page, and nothing in the
        // markup, the stylesheet or a text read says so. Only the rendered
        // colours, blended the way the browser paints them, show it.
        if (want.indexOf('borders') !== -1) {
            var faint = 0;
            d.querySelectorAll('body *').forEach(function (el) {
                if (faint >= 200) { return; }
                var s = win.getComputedStyle(el);
                var sides = bordersOf(s);
                if (!sides.length || !visible(el)) { return; }

                var surface = surfaceFrom(el.parentElement);
                var fill = readColor(s.backgroundColor) || { r: 0, g: 0, b: 0, a: 0 };
                // The background colour is clipped by the bottom-most layer,
                // which is the last entry in the list.
                var clip = String(s.backgroundClip || 'border-box').split(',').pop().trim();

                for (var i = 0; i < sides.length; i++) {
                    var border = readColor(sides[i].color);
                    if (!border) { continue; }
                    var ratio = borderContrast(border, fill, surface, clip === 'border-box');
                    if (ratio < BORDER_FLOOR) {
                        faint++;
                        findings.push({
                            check: 'borders',
                            selector: cssPath(el),
                            detail: 'Border is effectively invisible against its background: '
                                + ratio.toFixed(2) + ':1 against a floor of ' + BORDER_FLOOR + ':1.',
                            text: (el.textContent || '').trim().slice(0, 40),
                            border: sides[i].color,
                            surface: colorText(surface),
                            ratio: Math.round(ratio * 100) / 100
                        });
                        // One finding per element; four identical sides are
                        // one mistake, not four.
                        break;
                    }
                }
            });
        }

        return {
            url: d.location.href,
            viewport: d.documentElement.clientWidth,
            checked: want,
            issues: findings.slice(0, 100),
            total: findings.length
        };
    }



    /**
     * A post was just written. Put it in front of the person watching.
     *
     * If a tab is already showing that post, reload it — the builder skills
     * write through their own abilities, so the tab holds a render from before
     * the change and would sit there looking finished and wrong. Otherwise open
     * it, which is what makes "build me a page" something you can watch rather
     * than something you are told about afterwards.
     */
    function followPost(p) {
        var url = p.view || p.edit;
        if (!url) { return { followed: false }; }

        var existing = null;
        pages.forEach(function (page) {
            if (existing) { return; }
            // Match on the post id in the URL, or on the address itself, so an
            // editor tab and a preview tab both count as showing this post.
            var hay = page.url || '';
            if (hay.indexOf('post=' + p.postId) !== -1 || hay.indexOf('p=' + p.postId) !== -1 || hay === url) {
                existing = page;
            }
        });

        if (existing) {
            focusPage(existing.id, true);

            // An editor tab is the thing that saved. Reloading it throws away
            // whatever the agent is in the middle of — every block gets a new
            // clientId, so the next edit addresses a block that no longer
            // exists. Measured: an insert-then-delete sequence failed with
            // "No block with clientId" because the save between them reloaded
            // the editor. Bring it forward, leave it alone.
            if (editorIn(existing)) {
                /* translators: %s: title or address of the post being shown */
                log('follow', sprintf(__('showing %s', 'nibwp'), p.title || url), 'ok');
                return { followed: true, focused: true, url: existing.url };
            }

            try {
                existing.frame.contentWindow.location.reload();
            } catch (e) {
                existing.frame.src = existing.frame.src;
            }
            /* translators: %s: title or address of the post that was reloaded */
            log('follow', sprintf(__('reloaded %s', 'nibwp'), p.title || url), 'ok');
            return { followed: true, reloaded: true, url: existing.url };
        }

        /* translators: %s: title or address of the post being opened */
        log('follow', sprintf(__('opening %s', 'nibwp'), p.title || url), 'ok');
        return openPage(url, p.title || url).then(function (page) {
            return { followed: true, opened: true, url: page.url };
        });
    }

    /* ------------------------------------------------------------- blocks -- */

    /**
     * The block editor's own data store, from the frame it is running in.
     *
     * Same origin, so this is a property access — no bundle has to be enqueued
     * on every editor screen in the site to let an agent edit blocks, and
     * nothing loads at all for people who never open a workspace.
     */
    function editorIn(page) {
        if (!page || page.blocked) { return null; }
        try {
            var win = page.frame && page.frame.contentWindow;
            var data = win && win.wp && win.wp.data;
            if (data && data.select('core/block-editor') && win.wp.blocks) {
                return { win: win, data: data, blocks: win.wp.blocks, page: page };
            }
        } catch (e) { /* another origin, or not booted yet */ }
        return null;
    }

    /**
     * Find the block editor among the open tabs, preferring the active one.
     *
     * Looking only at the active tab meant a stray tab opened afterwards — or
     * restored from a previous session — silently took the block commands and
     * the agent was told there was no editor, while the editor sat one tab away.
     */
    function editor() {
        if (!pages.size) { throw new Error('No page is open. Use visual-open on a post or page editor first.'); }

        var found = editorIn(activePage());
        if (found) { return found; }

        var other = null;
        pages.forEach(function (page) {
            if (!other) { other = editorIn(page); }
        });

        if (other) {
            // Bring it forward so the person watching sees what is being edited.
            focusPage(other.page.id, true);
            return other;
        }

        throw new Error('None of the open tabs is a block editor. Open a post or page in the editor first.');
    }

    /**
     * The words inside a scrap of block markup, without running any of it.
     *
     * The obvious way — innerHTML on a detached div — still belongs to a live
     * document, so `<img src=x onerror=…>` in a block attribute would fetch and
     * fire while we were only trying to read a preview. DOMParser builds an
     * inert document instead: nothing loads, nothing executes.
     */
    function plainText(html, win) {
        try {
            var doc = new (win.DOMParser || DOMParser)().parseFromString(String(html), 'text/html');
            return doc.body ? (doc.body.textContent || '') : String(html);
        } catch (e) {
            // Older engine, or a document that refused to parse: fall back to
            // dropping tags rather than to executing them.
            return String(html).replace(/<[^>]*>/g, '');
        }
    }

    function blockText(block, win) {
        var bits = [];
        Object.keys(block.attributes || {}).forEach(function (k) {
            var v = block.attributes[k];
            // Rich text is a RichTextData object in current Gutenberg, not a
            // string, so a typeof check alone finds nothing. Which shape it
            // takes depends on how the block was made: created blocks carry the
            // object, blocks whose attributes were set later carry a string.
            if (v && typeof v === 'object' && typeof v.toString === 'function') {
                var asText = String(v);
                if (asText !== '[object Object]') { v = asText; }
            }
            if (typeof v === 'string' && v && bits.join(' ').length < 160) {
                bits.push(plainText(v, win));
            }
        });
        return bits.join(' ').replace(/\s+/g, ' ').trim().slice(0, 160);
    }

    function mapBlocks(list, e, opts, depth, inside) {
        var out = [];
        (list || []).forEach(function (b) {
            // Once a block matches, everything under it is part of the answer.
            // Re-testing children against the filter dropped the contents of
            // the very block that was asked for.
            var matches = inside || !opts.name || b.name.toLowerCase().indexOf(opts.name) !== -1;
            if (!matches) {
                // Keep walking: the match may be nested inside a group.
                out = out.concat(mapBlocks(b.innerBlocks, e, opts, depth + 1, false));
                return;
            }
            var item = { name: b.name, clientId: b.clientId, attributes: b.attributes || {} };
            if (opts.text) { item.text = blockText(b, e.win); }
            if ((b.innerBlocks || []).length) {
                if (depth + 1 < opts.depth) {
                    item.innerBlocks = mapBlocks(b.innerBlocks, e, opts, depth + 1, true);
                } else {
                    item.innerBlocks_count = b.innerBlocks.length;
                }
            }
            out.push(item);
        });
        return out;
    }

    function readBlocks(p) {
        var e = editor();
        var opts = {
            depth: Math.max(1, Math.min(p.depth || 3, 8)),
            text: p.text !== false,
            name: (p.name || '').toLowerCase()
        };
        var top = e.data.select('core/block-editor').getBlocks();
        var ed = e.data.select('core/editor');
        return {
            count: top.length,
            blocks: mapBlocks(top, e, opts, 0, false),
            postTitle: ed ? ed.getEditedPostAttribute('title') : ''
        };
    }

    /** Turn the agent's plain objects into real blocks, children and all. */
    function buildBlock(spec, e) {
        var name = spec.blockName || spec.block_name;
        if (!name) { throw new Error('Every block needs a block_name.'); }
        if (!e.blocks.getBlockType(name)) {
            throw new Error('No block type "' + name + '" is registered here. Use visual-block-schema to see what is.');
        }
        var kids = (spec.innerBlocks || spec.inner_blocks || []).map(function (k) { return buildBlock(k, e); });
        return e.blocks.createBlock(name, spec.attributes || {}, kids);
    }

    /**
     * Let the editor store settle before answering.
     *
     * A dispatch returns before the block tree has re-rendered, so an agent
     * that inserts and immediately reads back saw its own change missing.
     */
    function settled(value) {
        return new Promise(function (resolve) {
            setTimeout(function () { resolve(value); }, 300);
        });
    }

    function insertBlock(p) {
        var e = editor();
        var block = buildBlock(p, e);
        var at = (typeof p.position === 'number' && p.position >= 0) ? p.position : undefined;
        var parent = p.parent || undefined;

        e.data.dispatch('core/block-editor').insertBlock(block, at, parent);

        return settled({
            inserted: block.name,
            clientId: block.clientId,
            parent: parent || null,
            note: 'Inserted in the editor, not saved. Saving is a separate step.'
        });
    }

    function updateBlock(p) {
        var e = editor();
        var existing = e.data.select('core/block-editor').getBlock(p.clientId);
        if (!existing) { throw new Error('No block with clientId ' + p.clientId + ' is on this page.'); }

        e.data.dispatch('core/block-editor').updateBlockAttributes(p.clientId, p.attributes || {});

        return settled({
            updated: existing.name,
            clientId: p.clientId,
            changed: Object.keys(p.attributes || {}),
            note: 'Changed in the editor, not saved.'
        });
    }

    function deleteBlock(p) {
        var e = editor();
        var existing = e.data.select('core/block-editor').getBlock(p.clientId);
        if (!existing) { throw new Error('No block with clientId ' + p.clientId + ' is on this page.'); }

        e.data.dispatch('core/block-editor').removeBlock(p.clientId);

        return settled({ removed: existing.name, clientId: p.clientId, note: 'Removed in the editor, not saved.' });
    }

    function blockSchema(p) {
        var e = editor();
        var types = e.blocks.getBlockTypes();

        if (p.blockName) {
            var t = e.blocks.getBlockType(p.blockName);
            if (!t) { throw new Error('No block type "' + p.blockName + '" is registered here.'); }
            return {
                name: t.name,
                title: t.title,
                category: t.category,
                description: t.description,
                attributes: t.attributes || {},
                supports: t.supports || {},
                parent: t.parent || null
            };
        }

        var q = (p.search || '').toLowerCase();
        var list = types.filter(function (t) {
            return !q || t.name.toLowerCase().indexOf(q) !== -1 || String(t.title).toLowerCase().indexOf(q) !== -1;
        }).map(function (t) { return { name: t.name, title: t.title, category: t.category }; });

        return { total: types.length, matched: list.length, blocks: list.slice(0, 200) };
    }

    /* ---------------------------------------------------------- viewport -- */

    var WIDTHS = { mobile: 390, tablet: 768, laptop: 1280, desktop: 1600, full: 0 };

    function setViewport(payload) {
        var width = payload.width > 0 ? payload.width : WIDTHS[payload.preset];
        if (width === undefined) { width = 0; }
        stage.style.setProperty('--nw-vs-width', width ? width + 'px' : '100%');
        stage.classList.toggle('is-constrained', width > 0);

        document.querySelectorAll('[data-vs-width]').forEach(function (b) {
            b.classList.toggle('is-active', b.getAttribute('data-vs-width') === (payload.preset || 'full'));
        });

        return { width: width || 'full' };
    }

    /* --------------------------------------------------------- approvals -- */

    function ask(command, payload) {
        // Nobody is watching a headless run, so a prompt here would hang until
        // the command timed out and report it as "no response" — which reads as
        // a broken workspace rather than a refusal. Decline at once instead.
        // The gate itself stays exactly as strict: what needs a person still
        // needs a person, it just fails fast and says why.
        if (window.__nibwpHeadless) {
            return Promise.resolve(false);
        }

        return new Promise(function (resolve) {
            askWhat.textContent = describe(command, payload);
            askEl.hidden = false;
            pending = function (allowed) {
                askEl.hidden = true;
                pending = null;
                resolve(allowed);
            };
        });
    }

    function describe(command, payload) {
        switch (command) {
            case 'click': return payload.selector
                /* translators: %s: CSS selector of the element to click */
                ? sprintf(__('Click %s on this page.', 'nibwp'), payload.selector)
                : __('Click an element on this page.', 'nibwp');
            case 'hover': return payload.selector
                /* translators: %s: CSS selector of the element to hover over */
                ? sprintf(__('Hover over %s and report what changes.', 'nibwp'), payload.selector)
                : __('Hover over an element and report what changes.', 'nibwp');
            case 'screenshot': return payload.selector
                /* translators: %s: CSS selector of the element to capture */
                ? sprintf(__('Take a screenshot of %s.', 'nibwp'), payload.selector)
                : __('Take a screenshot of this page.', 'nibwp');
            case 'fill': {
                var field = payload.selector || __('a field', 'nibwp');
                return payload.submit
                    /* translators: 1: the text to type, 2: CSS selector of the field */
                    ? sprintf(__('Type “%1$s” into %2$s, then submit the form.', 'nibwp'), payload.value || '', field)
                    /* translators: 1: the text to type, 2: CSS selector of the field */
                    : sprintf(__('Type “%1$s” into %2$s.', 'nibwp'), payload.value || '', field);
            }
            case 'batch': {
                var steps = (payload.steps || []).map(function (st) { return describe(st.command, st.payload || {}); });
                /* translators: %d: number of steps */
                return sprintf(_n('%d step in one go:', '%d steps in one go:', steps.length, 'nibwp'), steps.length)
                    + String.fromCharCode(10, 10) + steps.join(String.fromCharCode(10));
            }
            case 'block-insert': return payload.blockName
                /* translators: %s: block name, e.g. core/heading */
                ? sprintf(__('Insert a %s block into the page being edited.', 'nibwp'), payload.blockName)
                : __('Insert a block into the page being edited.', 'nibwp');
            /* translators: 1: comma-separated attribute names, 2: block client id */
            case 'block-update': return sprintf(__('Change %1$s on block %2$s.', 'nibwp'), Object.keys(payload.attributes || {}).join(', '), payload.clientId);
            /* translators: %s: block client id */
            case 'block-delete': return sprintf(__('Delete block %s and everything inside it.', 'nibwp'), payload.clientId);
            case 'open': return payload.url
                /* translators: %s: address of the page to open */
                ? sprintf(__('Open %s in the workspace.', 'nibwp'), payload.url)
                : __('Open a page in the workspace.', 'nibwp');
            case 'read': return __('Read the current page.', 'nibwp');
            case 'audit': return __('Check the current page for accessibility and layout problems.', 'nibwp');
            default: return command + ' ' + JSON.stringify(payload);
        }
    }

    document.getElementById('nw-vs-allow').addEventListener('click', function () { if (pending) { pending(true); } });

    // Allow this one, and stop asking. Flips the same switch the bar shows, so
    // the change is visible rather than a quiet loss of the gate.
    var allowAll = document.getElementById('nw-vs-allow-all');
    if (allowAll) {
        allowAll.addEventListener('click', function () {
            var box = document.getElementById('nw-vs-approval');
            if (box) { box.checked = false; }
            api('state', { approval: '0' });
            log('setting', __('Approval off — turn it back on in the bar', 'nibwp'));
            if (pending) { pending(true); }
        });
    }
    document.getElementById('nw-vs-deny').addEventListener('click', function () { if (pending) { pending(false); } });

    document.getElementById('nw-vs-approval').addEventListener('change', function (e) {
        api('state', { approval: e.target.checked ? '1' : '0' });
        log('setting', e.target.checked ? __('Approval required', 'nibwp') : __('Approval off', 'nibwp'));
    });

    // The panel earns its space while you are setting up and stops earning it
    // the moment there is a page to look at, so the first page puts it away.
    var shell = document.getElementById('nw-vs');
    var toggle = document.getElementById('nw-vs-toggle');
    var PANEL_KEY = 'nibwp-visual-panel';
    var panelChosen = false;

    function setPanel(open, remember) {
        shell.classList.toggle('is-collapsed', !open);
        if (toggle) { toggle.setAttribute('aria-expanded', open ? 'true' : 'false'); }
        if (remember) {
            panelChosen = true;
            try { window.localStorage.setItem(PANEL_KEY, open ? 'open' : 'shut'); } catch (e) { /* private mode */ }
        }
    }

    if (toggle) {
        toggle.addEventListener('click', function () {
            setPanel(shell.classList.contains('is-collapsed'), true);
        });
    }

    // First visit, the panel is the screen: it is where connecting and the
    // starting points live. Every visit after that it starts out of the way,
    // because by then the site is the thing worth looking at — unless someone
    // has said which they prefer, in which case that wins forever.
    var SEEN_KEY = 'nibwp-visual-seen';

    try {
        var saved = window.localStorage.getItem(PANEL_KEY);
        if (saved) {
            panelChosen = true;
            setPanel(saved === 'open', false);
        } else if (window.localStorage.getItem(SEEN_KEY)) {
            setPanel(false, false);
        }
        window.localStorage.setItem(SEEN_KEY, '1');
    } catch (e) { /* private mode: the panel opens every time, which is safe */ }

    /* ------------------------------------------------------------ search -- */

    // The box used to filter the prompts that happened to be on screen — on a
    // site with 289 abilities, a search that cannot find almost anything. It
    // answers from the whole catalogue now: abilities, workflows, and the
    // starting tasks, ranked so a name that starts with what you typed wins.
    var findEl = document.getElementById('nw-vs-find');
    var catalogue = cfg.catalogue || [];

    function promptFor(item) {
        if (item.k === 'task') { return item.n; }
        if (item.k === 'skill') {
            /* translators: %s: the skill name, kept exactly as written */
            return sprintf(__('Load the %s skill with nibwp/get-skill and follow it for this task. Work in the NibWP visual workspace so I can watch each step.', 'nibwp'), item.n);
        }
        if (item.k === 'workflow') {
            /* translators: %s: the workflow name, kept exactly as written */
            return sprintf(__('Run the NibWP workflow "%s" on this site. Work in the NibWP visual workspace so I can watch each step, and ask me before anything that changes something.', 'nibwp'), item.n);
        }
        /* translators: %s: the ability name, e.g. nibwp/visual-audit */
        return sprintf(__('Use %s on this site, in the NibWP visual workspace so I can watch, and tell me what you find.', 'nibwp'), item.n);
    }

    // The kind of each search result, in words. The keys are the catalogue's
    // own and never shown.
    var KINDS = {
        ability: _x('ability', 'search result kind', 'nibwp'),
        workflow: _x('workflow', 'search result kind', 'nibwp'),
        skill: _x('skill', 'search result kind', 'nibwp'),
        task: _x('task', 'search result kind', 'nibwp')
    };

    function rank(item, q) {
        var name = (item.n || '').toLowerCase();
        var label = (item.l || '').toLowerCase();
        if (name === q || label === q) { return 0; }
        if (name.indexOf(q) === 0 || label.indexOf(q) === 0) { return 1; }
        if (name.indexOf(q) !== -1 || label.indexOf(q) !== -1) { return 2; }
        return (item.d || '').toLowerCase().indexOf(q) !== -1 ? 3 : -1;
    }

    function drawFind(q) {
        if (!findEl) { return false; }

        var hits = [];
        for (var i = 0; i < catalogue.length; i++) {
            var score = rank(catalogue[i], q);
            if (score >= 0) { hits.push({ item: catalogue[i], score: score }); }
        }

        if (hits.length === 0) {
            findEl.innerHTML = '<p class="nw-vs-find__none"></p>';
            /* translators: %s: what was typed into the search box */
            findEl.firstChild.textContent = sprintf(__('Nothing matches “%s”.', 'nibwp'), q);
            findEl.hidden = false;
            return true;
        }

        hits.sort(function (a, b) { return a.score - b.score; });
        var total = hits.length;
        hits = hits.slice(0, 40);

        findEl.innerHTML = '';
        hits.forEach(function (hit) {
            var row = document.createElement('button');
            row.type = 'button';
            row.className = 'nw-vs-find__row';
            row.setAttribute('data-vs-prompt', promptFor(hit.item));
            row.setAttribute('data-copy-label', __('Copy', 'nibwp'));
            row.setAttribute('data-copied-label', __('Copied', 'nibwp'));
            row.innerHTML = '<span class="nw-vs-find__kind"></span>'
                + '<span class="nw-vs-find__body"><span class="nw-vs-find__label"></span>'
                + '<span class="nw-vs-find__note"></span></span>';
            row.querySelector('.nw-vs-find__kind').textContent = KINDS[hit.item.k] || hit.item.k;
            row.querySelector('.nw-vs-find__label').textContent = hit.item.l || hit.item.n;
            row.querySelector('.nw-vs-find__note').textContent = hit.item.d || hit.item.n;
            row.addEventListener('click', function () { copyText(row.getAttribute('data-vs-prompt'), row); });
            findEl.appendChild(row);
        });

        // Say what was left out rather than quietly showing forty of three
        // hundred as though that were all of them.
        if (total > hits.length) {
            var more = document.createElement('p');
            more.className = 'nw-vs-find__none';
            /* translators: %d: number of further results not shown */
            more.textContent = sprintf(_n('%d more match. Type a little further.', '%d more matches. Type a little further.', total - hits.length, 'nibwp'), total - hits.length);
            findEl.appendChild(more);
        }

        findEl.hidden = false;
        return true;
    }

    // Type to filter, or use a slash command to jump to one group. The list is
    // short enough that a filter beats a menu.
    var cmd = document.getElementById('nw-vs-cmd');
    if (cmd) {
        var groups = {
            '/workflows': 'workflows',
            '/skills': 'skills',
            '/abilities': 'abilities',
            '/tasks': 'tasks'
        };

        cmd.addEventListener('input', function () {
            var q = cmd.value.trim().toLowerCase();
            var only = null;

            Object.keys(groups).forEach(function (slash) {
                if (q === slash || q.indexOf(slash + ' ') === 0) {
                    only = groups[slash];
                    q = q.slice(slash.length).trim();
                }
            });

            // A bare slash lists the commands rather than matching nothing.
            if (q === '/') { q = ''; }

            // Two characters is where a search over three hundred things starts
            // being an answer rather than a dump of everything.
            if (only === null && q.length >= 2 && drawFind(q)) {
                document.querySelectorAll('[data-vs-group]').forEach(function (section) {
                    section.style.display = 'none';
                });
                return;
            }
            if (findEl) { findEl.hidden = true; }

            document.querySelectorAll('[data-vs-group]').forEach(function (section) {
                var group = section.getAttribute('data-vs-group');
                var wanted = only === null || only === group;
                var shown = 0;

                section.querySelectorAll('.nw-vs-job').forEach(function (job) {
                    var text = job.textContent.toLowerCase();
                    var hit = wanted && (q === '' || text.indexOf(q) !== -1);
                    job.style.display = hit ? '' : 'none';
                    if (hit) { shown++; }
                });

                // Keep a group with no buttons visible when it was asked for by
                // name, so /workflows on a free site still explains itself.
                var hasJobs = section.querySelectorAll('.nw-vs-job').length > 0;
                section.style.display = (wanted && (shown > 0 || !hasJobs)) ? '' : 'none';

                // Matches inside a folded section are matches nobody can see.
                if (shown > 0 && q !== '') { foldSection(section, true, false); }
            });
        });

        cmd.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') { cmd.value = ''; cmd.dispatchEvent(new Event('input')); }
        });
    }

    // A single verdict would hide which of the three things is missing, and
    // they all look the same from here: nothing happens.
    var checkBtn = document.getElementById('nw-vs-check');
    var checkOut = document.getElementById('nw-vs-checkout');
    if (checkBtn && checkOut) {
        checkBtn.addEventListener('click', function () {
            yieldPoll();
            checkOut.textContent = __('Checking…', 'nibwp');
            checkOut.className = 'nw-vs-checkout';
            api('check', {})
                .then(function (r) { return r.json(); })
                .then(function (d) {
                    // Whether this screen is listening is something this screen
                    // knows. Asking the server invited a race where a poll that
                    // had just been cut short read as a broken connection.
                    // A poll in flight counts. The first one holds for ten
                    // seconds before it returns, and "has not answered yet" is
                    // not the same as "cannot reach WordPress".
                    var listening = !standDown && (polling || (Date.now() - lastPoll) < 30000);
                    var rows = [
                        [listening, __('This screen is listening', 'nibwp'), __('This screen is not reaching WordPress', 'nibwp')],
                        [d.abilities, __('AI Abilities are on', 'nibwp'), __('AI Abilities are switched off', 'nibwp')],
                        /* translators: %s: comma-separated names of the connected AI clients */
                        [d.connected, sprintf(__('%s can reach this site', 'nibwp'), (d.clients || []).join(', ')), __('No AI client is connected', 'nibwp')]
                    ];
                    checkOut.innerHTML = '';
                    rows.forEach(function (row) {
                        var li = document.createElement('div');
                        li.className = 'nw-vs-checkrow ' + (row[0] ? 'is-ok' : 'is-bad');
                        li.textContent = (row[0] ? '\u2713 ' : '\u2717 ') + (row[0] ? row[1] : row[2]);
                        checkOut.appendChild(li);
                    });
                    if (!d.connected || !d.abilities) {
                        var a = document.createElement('a');
                        a.className = 'nw-vs-more';
                        a.href = d.connectUrl;
                        a.textContent = __('Fix this on the Connect screen', 'nibwp');
                        checkOut.appendChild(a);
                    }
                })
                .catch(function () {
                    checkOut.textContent = __('Could not reach WordPress from this screen.', 'nibwp');
                    checkOut.className = 'nw-vs-checkout is-bad';
                });
        });
    }

    /* --------------------------------------------- pairing a headless runner -- */
    // The key is made here rather than handed out to whatever authenticates to
    // the API, and that is the guard rather than a convenience: this screen is
    // behind a logged-in cookie, which only a real sign-in produces, so asking
    // for a key is something a person does and an application password cannot.
    // Without it the runner's stored credential would be enough on its own to
    // mint browser cookies for the account.
    var pairBtn = document.getElementById('nw-vs-pair');
    var pairOut = document.getElementById('nw-vs-pair-out');
    var pairSched = document.getElementById('nw-vs-pair-sched');
    var pairRevoke = document.getElementById('nw-vs-pair-revoke');

    function pairFailed() {
        if (!pairOut) { return; }
        pairOut.textContent = __('Could not reach WordPress from this screen.', 'nibwp');
        pairOut.className = 'nw-vs-checkout is-bad';
    }

    /**
     * Show a key once.
     *
     * Written with textContent and a node per part, never innerHTML: the value
     * is a credential, and the one place it is ever displayed is not the place
     * to start concatenating it into markup.
     */
    function showPass(d) {
        pairOut.className = 'nw-vs-checkout';
        pairOut.textContent = '';

        var key = document.createElement('code');
        key.className = 'nw-vs-pair__key';
        key.textContent = d.pass;
        pairOut.appendChild(key);

        var copy = document.createElement('button');
        copy.type = 'button';
        copy.className = 'nw-vs-more nw-vs-more--btn';
        copy.textContent = __('Copy the key', 'nibwp');
        copy.addEventListener('click', function () {
            var done = function () {
                copy.textContent = __('Copied', 'nibwp');
                setTimeout(function () { copy.textContent = __('Copy the key', 'nibwp'); }, 1600);
            };
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(d.pass).then(done, done);
            } else {
                // Plain http, and older browsers, where the async clipboard is
                // unavailable. Selecting it is the fallback that always works.
                var range = document.createRange();
                range.selectNodeContents(key);
                var sel = window.getSelection();
                sel.removeAllRanges();
                sel.addRange(range);
            }
        });
        pairOut.appendChild(copy);

        // Two facts a person needs before they walk away from this screen: it
        // will not be shown again, and it does not last forever.
        var when = document.createElement('span');
        when.className = 'nw-vs-pair__when';
        when.textContent = d.scheduled
            ? sprintf(
                /* translators: %s: a date and time */
                __('Shown once. Reusable for scheduled runs until you revoke it, or until %s.', 'nibwp'),
                new Date(d.expires * 1000).toLocaleString()
            )
            : sprintf(
                /* translators: %s: a date and time */
                __('Shown once, good for one run, and only until %s.', 'nibwp'),
                new Date(d.expires * 1000).toLocaleString()
            );
        pairOut.appendChild(when);

        if (pairRevoke) { pairRevoke.hidden = false; }
    }

    if (pairBtn && pairOut) {
        pairBtn.addEventListener('click', function () {
            yieldPoll();
            pairOut.className = 'nw-vs-checkout';
            pairOut.textContent = __('Creating…', 'nibwp');

            api('pair', { scheduled: pairSched && pairSched.checked ? '1' : '0' })
                .then(function (r) { return r.json(); })
                .then(function (d) {
                    if (!d || !d.pass) { throw new Error('no key'); }
                    showPass(d);
                })
                .catch(pairFailed);
        });
    }

    if (pairRevoke && pairOut) {
        pairRevoke.addEventListener('click', function () {
            yieldPoll();
            pairOut.className = 'nw-vs-checkout';
            pairOut.textContent = __('Revoking…', 'nibwp');

            api('pair', { revoke: '1' })
                .then(function (r) { return r.json(); })
                .then(function (d) {
                    if (!d || typeof d.revoked !== 'number') { throw new Error('no answer'); }
                    // Revoking ends the sessions those keys opened, so say so:
                    // a runner that goes quiet a minute later is otherwise a
                    // mystery rather than the thing that was just asked for.
                    pairOut.textContent = d.revoked > 0
                        ? __('Revoked. Any runner signed in with one of those keys is now signed out.', 'nibwp')
                        : __('There was nothing to revoke.', 'nibwp');
                    pairRevoke.hidden = true;
                })
                .catch(pairFailed);
        });
    }

    // Starter prompts: the whole point is pasting one into an assistant.
    document.querySelectorAll('[data-vs-prompt]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var text = btn.getAttribute('data-vs-prompt');
            var done = function () {
                btn.classList.add('is-copied');
                var tag = btn.querySelector('.nw-vs-starter__copy');
                var was = tag ? tag.textContent : '';
                if (tag) { tag.textContent = __('Copied', 'nibwp'); }
                setTimeout(function () {
                    btn.classList.remove('is-copied');
                    if (tag) { tag.textContent = was; }
                }, 1600);
            };
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(text).then(done, done);
            } else {
                // Older browsers, and any page served over plain http, where
                // the async clipboard API is unavailable.
                var ta = document.createElement('textarea');
                ta.value = text;
                document.body.appendChild(ta);
                ta.select();
                try { document.execCommand('copy'); } catch (e) { /* nothing else to try */ }
                ta.remove();
                done();
            }
        });
    });

    document.querySelectorAll('[data-vs-width]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            setViewport({ preset: btn.getAttribute('data-vs-width'), width: 0 });
        });
    });



    /* ------------------------------------------------------------- panel -- */

    // Sections fold, and stay folded across visits. A panel that resets every
    // time is one people stop tidying.
    var FOLD_KEY = 'nibwp-visual-folded';
    var folded = {};
    try { folded = JSON.parse(window.localStorage.getItem(FOLD_KEY) || '{}') || {}; } catch (e) { folded = {}; }

    function sectionName(section) {
        var title = section.querySelector('.nw-vs-panel__title');
        return title ? title.textContent.trim() : '';
    }

    function foldSection(section, open, remember) {
        var fold = section.querySelector('.nw-vs-panel__fold');
        var body = section.querySelector('.nw-vs-panel__body');
        if (!fold || !body) { return; }
        body.hidden = !open;
        fold.setAttribute('aria-expanded', open ? 'true' : 'false');
        if (remember) {
            folded[sectionName(section)] = !open;
            try { window.localStorage.setItem(FOLD_KEY, JSON.stringify(folded)); } catch (e) { /* private mode */ }
        }
    }

    document.querySelectorAll('.nw-vs-panel__fold').forEach(function (fold) {
        var section = fold.closest('.nw-vs-panel');
        if (!section) { return; }
        if (folded[sectionName(section)]) { foldSection(section, false, false); }
        fold.addEventListener('click', function () {
            foldSection(section, fold.getAttribute('aria-expanded') === 'false', true);
        });
    });

    // The fade at the bottom of a capped list is a lie when nothing is clipped.
    function markClipped() {
        document.querySelectorAll('.nw-vs-jobs--cage').forEach(function (cage) {
            cage.classList.toggle('is-clipped', cage.scrollHeight > cage.clientHeight + 2);
        });
    }

    markClipped();
    window.addEventListener('resize', markClipped);

    /* ---------------------------------------------------------- appearance -- */

    // Light or dark, remembered. With nothing stored the system decides, which
    // is why this only ever writes an explicit choice: clearing it back to
    // "whatever the machine says" has to remain possible.
    var THEME_KEY = 'nibwp-visual-theme';
    var themeBtn = document.getElementById('nw-vs-theme');

    // Dark unless someone has said otherwise, matching the stylesheet. Reading
    // the system preference here instead would make the first press of the
    // toggle do nothing on a light machine: it would "switch" to what was
    // already on screen.
    function currentTheme() {
        var set = document.documentElement.getAttribute('data-theme');
        return set === 'light' ? 'light' : 'dark';
    }

    if (themeBtn) {
        themeBtn.addEventListener('click', function () {
            var next = currentTheme() === 'dark' ? 'light' : 'dark';
            document.documentElement.setAttribute('data-theme', next);
            try { window.localStorage.setItem(THEME_KEY, next); } catch (e) { /* private mode */ }
            var says = next === 'dark' ? __('Switch to light', 'nibwp') : __('Switch to dark', 'nibwp');
            themeBtn.setAttribute('aria-label', says);
            themeBtn.setAttribute('data-tip', says);
        });
    }

    /* --------------------------------------------------------------- dock -- */

    // The log, docked along the bottom. It used to be a column inside the panel,
    // where every line was clipped to a handful of characters and reading it
    // meant keeping the panel open — so it was either in the way or unreadable.
    var dock = document.getElementById('nw-vs-dock');
    var dockBtn = document.getElementById('nw-vs-dock-btn');
    var dockCount = document.getElementById('nw-vs-dock-count');
    var dockWho = document.getElementById('nw-vs-dock-who');
    var DOCK_KEY = 'nibwp-visual-dock';
    var DOCK_H_KEY = 'nibwp-visual-dock-h';
    var unread = 0;

    function dockOpen() { return !!dock && !dock.hidden; }

    function setDock(open, remember) {
        if (!dock) { return; }
        dock.hidden = !open;
        if (dockBtn) {
            dockBtn.setAttribute('aria-expanded', open ? 'true' : 'false');
            dockBtn.classList.toggle('is-on', open);
        }
        if (open) {
            unread = 0;
            if (dockCount) { dockCount.hidden = true; }
        }
        if (remember) {
            try { window.localStorage.setItem(DOCK_KEY, open ? 'open' : 'shut'); } catch (e) { /* private mode */ }
        }
    }

    // Something happened while the dock was shut. A count on the icon is the
    // whole point of putting the log behind one — otherwise closing it means
    // losing track of whether the agent is doing anything at all.
    function noteActivity() {
        if (dockOpen()) { return; }
        unread++;
        if (dockCount) {
            dockCount.textContent = unread > 99 ? '99+' : String(unread);
            dockCount.hidden = false;
        }
    }

    if (dockBtn) { dockBtn.addEventListener('click', function () { setDock(!dockOpen(), true); }); }

    var dockClose = document.getElementById('nw-vs-dock-close');
    if (dockClose) { dockClose.addEventListener('click', function () { setDock(false, true); }); }

    var logClear = document.getElementById('nw-vs-log-clear');
    if (logClear && logEl) {
        logClear.addEventListener('click', function () { logEl.innerHTML = ''; });
    }

    // Drag the top edge. An inspector you cannot resize is a fixed panel with a
    // handle drawn on it.
    var grip = document.getElementById('nw-vs-dock-grip');
    if (grip && dock) {
        var dragFrom = 0;
        var dragH = 0;

        function sizeTo(px) {
            var h = Math.max(120, Math.min(px, window.innerHeight - 200));
            dock.style.setProperty('--nw-vs-dock-h', h + 'px');
            return h;
        }

        function onMove(e) { sizeTo(dragH + (dragFrom - e.clientY)); }

        function onUp() {
            document.removeEventListener('mousemove', onMove);
            document.removeEventListener('mouseup', onUp);
            document.body.style.userSelect = '';
            try { window.localStorage.setItem(DOCK_H_KEY, String(dock.getBoundingClientRect().height)); } catch (e) { /* private mode */ }
        }

        grip.addEventListener('mousedown', function (e) {
            dragFrom = e.clientY;
            dragH = dock.getBoundingClientRect().height;
            document.body.style.userSelect = 'none';
            document.addEventListener('mousemove', onMove);
            document.addEventListener('mouseup', onUp);
            e.preventDefault();
        });

        // Keyboard equivalent, because a drag handle is unusable without one.
        grip.addEventListener('keydown', function (e) {
            if (e.key !== 'ArrowUp' && e.key !== 'ArrowDown') { return; }
            var h = sizeTo(dock.getBoundingClientRect().height + (e.key === 'ArrowUp' ? 40 : -40));
            try { window.localStorage.setItem(DOCK_H_KEY, String(h)); } catch (e2) { /* private mode */ }
            e.preventDefault();
        });

        try {
            var savedH = parseInt(window.localStorage.getItem(DOCK_H_KEY) || '', 10);
            if (savedH > 0) { sizeTo(savedH); }
        } catch (e) { /* private mode */ }
    }

    try {
        setDock(window.localStorage.getItem(DOCK_KEY) === 'open', false);
    } catch (e) { /* private mode: shut, which is the quieter default */ }

    /* ------------------------------------------------------------ go to -- */

    // Shortcuts that open inside the workspace rather than navigating away from
    // it. Leaving to look at the Pages list and coming back is three
    // navigations for one glance.
    var menu = document.getElementById('nw-vs-menu');
    var menuBtn = document.getElementById('nw-vs-menu-btn');
    var menuList = document.getElementById('nw-vs-menu-list');

    function setMenu(open) {
        if (!menuList) { return; }
        menuList.hidden = !open;
        if (menuBtn) { menuBtn.setAttribute('aria-expanded', open ? 'true' : 'false'); }
    }

    // Type to narrow it. A site with twenty plugins puts two hundred entries in
    // here, and scrolling a list that long to reach "Orders" is worse than the
    // trip to wp-admin this replaced.
    var menuFind = document.getElementById('nw-vs-menu-find');
    var menuNone = document.getElementById('nw-vs-menu-none');

    function filterMenu() {
        if (!menuList) { return; }
        var q = (menuFind ? menuFind.value : '').trim().toLowerCase();
        var shown = 0;
        var heads = [];
        var pending = null;

        Array.prototype.forEach.call(menuList.children, function (el) {
            if (el === menuFind || el === menuNone) { return; }

            if (el.classList.contains('nw-vs-menu__h')) {
                // A heading is only worth showing if something under it is.
                if (pending) { pending.hidden = true; }
                pending = el;
                heads.push(el);
                el.hidden = true;
                return;
            }

            var hit = q === '' || el.textContent.toLowerCase().indexOf(q) !== -1;
            el.hidden = !hit;
            if (hit) {
                shown++;
                if (pending) { pending.hidden = false; pending = null; }
            }
        });

        if (menuNone) { menuNone.hidden = shown > 0; }
    }

    if (menuFind) {
        menuFind.addEventListener('input', filterMenu);
        menuFind.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && menuFind.value !== '') {
                // First Escape clears the filter, second closes the menu.
                e.stopPropagation();
                menuFind.value = '';
                filterMenu();
            }
        });
    }

    if (menuBtn && menuList) {
        menuBtn.addEventListener('click', function (e) {
            e.stopPropagation();
            var opening = menuList.hidden;
            setMenu(opening);
            if (opening && menuFind) {
                menuFind.value = '';
                filterMenu();
                menuFind.focus();
            }
        });

        document.addEventListener('click', function (e) {
            if (menu && !menu.contains(e.target)) { setMenu(false); }
        });

        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') { setMenu(false); }
        });
    }

    document.querySelectorAll('[data-vs-goto]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            setMenu(false);
            var url = btn.getAttribute('data-vs-goto');
            var title = btn.getAttribute('data-vs-title') || url;
            yieldPoll();
            Promise.resolve()
                .then(function () { return openPage(url, title); })
                .then(function () { log('open', title, 'ok'); })
                .catch(function (err) { log('open', err.message, 'bad'); });
        });
    });

    /* ---------------------------------------------------------- upgrade -- */

    // Named at the moment of reaching for it, with what it would do on this
    // site — not a banner in the corner that everybody stops seeing on day two.
    var locked = cfg.locked || {};
    var up = document.getElementById('nw-vs-up');

    function showLocked(key) {
        var item = locked[key];
        if (!up || !item) { return; }

        // A skill or integration brings its own icon; a feature like workflows
        // has none, and an empty tile beside the title read as a broken image.
        document.getElementById('nw-vs-up-icon').innerHTML = item.icon
            || '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"'
                + ' stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
                + '<rect x="3" y="11" width="18" height="10" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>';
        document.getElementById('nw-vs-up-eyebrow').textContent = item.ready && item.kind === 'skill'
            ? __('Ready to switch on', 'nibwp')
            : __('Not included in your plan', 'nibwp');
        document.getElementById('nw-vs-up-title').textContent = item.title || '';
        document.getElementById('nw-vs-up-line').textContent = item.line || '';

        var list = document.getElementById('nw-vs-up-list');
        list.innerHTML = '';
        (item.features || []).forEach(function (f) {
            var li = document.createElement('li');
            li.innerHTML = '<span class="nw-vs-up__tick" aria-hidden="true">'
                + '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6"'
                + ' stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg></span>'
                + '<span></span>';
            li.lastChild.textContent = f;
            list.appendChild(li);
        });

        var go = document.getElementById('nw-vs-up-go');
        go.href = item.url || '#';
        /* translators: 1: call to action, e.g. "Unlock Etch", 2: price, e.g. $49 */
        go.textContent = item.price ? sprintf(__('%1$s — %2$s', 'nibwp'), item.cta, item.price) : item.cta;

        up.hidden = false;
        document.getElementById('nw-vs-up-not').focus();
    }

    function hideLocked() {
        if (up) { up.hidden = true; }
    }

    if (up) {
        ['nw-vs-up-close', 'nw-vs-up-not'].forEach(function (id) {
            var el = document.getElementById(id);
            if (el) { el.addEventListener('click', hideLocked); }
        });

        // Dismissible every time, and dismissing means dismissing: clicking the
        // backdrop or pressing Escape closes it, and nothing is remembered so
        // the next deliberate click still explains itself.
        up.addEventListener('click', function (e) { if (e.target === up) { hideLocked(); } });
        document.addEventListener('keydown', function (e) { if (e.key === 'Escape') { hideLocked(); } });
    }

    document.querySelectorAll('[data-vs-locked]').forEach(function (btn) {
        btn.addEventListener('click', function () { showLocked(btn.getAttribute('data-vs-locked')); });
    });

    /* ----------------------------------------------------------- connect -- */

    // The connect screen lives on the stage now, so its buttons live here.
    function flash(el, word) {
        el.classList.add('is-copied');

        // An icon-only button says it with its icon; replacing its contents
        // would throw both marks away and leave the word "Copied" in a 42px
        // square. The class is enough where there is nothing to swap.
        var swaps = el.children.length === 0;
        var was = swaps ? el.textContent : null;
        if (swaps) { el.textContent = word; }

        setTimeout(function () {
            el.classList.remove('is-copied');
            if (swaps) { el.textContent = was; }
        }, 1600);
    }

    function copyText(text, el) {
        var done = function () { if (el) { flash(el, __('Copied', 'nibwp')); } };
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text).then(done, done);
            return;
        }
        // Plain http, where the async clipboard API does not exist — which is
        // most local sites, and exactly where people try this first.
        var ta = document.createElement('textarea');
        ta.value = text;
        document.body.appendChild(ta);
        ta.select();
        try { document.execCommand('copy'); } catch (e) { /* nothing else to try */ }
        ta.remove();
        done();
    }

    document.querySelectorAll('[data-vs-copy]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var field = document.getElementById(btn.getAttribute('data-vs-copy'));
            if (field) { copyText(field.value, btn); }
        });
    });

    document.querySelectorAll('[data-vs-copytext]').forEach(function (btn) {
        btn.addEventListener('click', function () { copyText(btn.getAttribute('data-vs-copytext'), btn); });
    });

    // Bring the connect screen back on demand. Once connected it is rendered
    // and tucked away rather than dropped, because "how do I connect another
    // one" and "this one stopped working" both send people looking for a screen
    // that used to be here and is not any more.
    var reconnect = document.getElementById('nw-vs-reconnect');

    /** Going back needs somewhere to go back to. */
    function syncStartBack() {
        if (!startBack) { return; }
        startBack.hidden = pages.size === 0 && !cfg.connected;
    }

    function showStart(on) {
        if (!startEl) { return; }
        startEl.hidden = !on;
        stage.classList.toggle('is-connecting', on);
        if (reconnect) {
            reconnect.setAttribute('aria-expanded', on ? 'true' : 'false');
            reconnect.classList.toggle('is-on', on);
        }
        if (!on) { startPinned = false; }
        syncStartBack();
        if (on && startBack && !startBack.hidden) { startBack.focus(); }
    }

    if (reconnect) {
        reconnect.addEventListener('click', function () {
            if (!startEl) { return; }

            // Hiding it needs somewhere to hide it behind. With nothing
            // connected and nothing open, turning it off leaves a blank stage
            // and the button that did it looking broken — so instead of
            // toggling into nothing, put the cursor on the thing to copy.
            if (!startEl.hidden && pages.size === 0 && !cfg.connected) {
                var url = document.getElementById('nw-vs-start-url');
                if (url) { url.focus(); url.select(); }
                return;
            }

            // Pinned means a person asked for it, so an incoming page does not
            // sweep it away mid-sentence.
            startPinned = startEl.hidden;
            showStart(startEl.hidden);
        });
    }

    if (startBack) { startBack.addEventListener('click', function () { showStart(false); }); }
    syncStartBack();

    var recheck = document.getElementById('nw-vs-start-recheck');
    var said = document.getElementById('nw-vs-start-said');
    if (recheck && said) {
        recheck.addEventListener('click', function () {
            yieldPoll();
            said.className = 'nw-vs-start__said';
            said.textContent = __('Checking…', 'nibwp');
            api('check', {})
                .then(function (r) { return r.json(); })
                .then(function (d) {
                    if (d.connected) {
                        said.className = 'nw-vs-start__said is-ok';
                        /* translators: %s: comma-separated names of the connected AI clients */
                        said.textContent = sprintf(__('%s can reach this site. Loading the workspace…', 'nibwp'), (d.clients || []).join(', '));
                        // Reload rather than patching the screen: the panel, the
                        // status and the starting points are all rendered
                        // against "connected", and half of them updating is
                        // worse than a one-second wait.
                        setTimeout(function () { window.location.reload(); }, 900);
                        return;
                    }
                    said.className = 'nw-vs-start__said is-bad';
                    said.textContent = d.abilities
                        ? __('Nothing has connected yet. Finish the sign-in in your assistant, then check again.', 'nibwp')
                        : __('AI Abilities are switched off on this site, so nothing can connect until they are on.', 'nibwp');
                })
                .catch(function () {
                    said.className = 'nw-vs-start__said is-bad';
                    said.textContent = __('Could not reach WordPress from this screen.', 'nibwp');
                });
        });
    }


    // "It is connected" — for when the site says otherwise. The bar's state was
    // decided when this page rendered, so a connection made a moment later is
    // invisible to it, and there is no way to tell that from a broken one. This
    // asks the server, and reloads past every cache if the answer has changed.
    var recheckBar = document.getElementById('nw-vs-recheck');
    if (recheckBar) {
        recheckBar.addEventListener('click', function () {
            var says = recheckBar.querySelector('.nw-vs-ibtn__text');
            if (says) { says.textContent = __('Checking…', 'nibwp'); }
            recheckBar.disabled = true;
            yieldPoll();

            var hardReload = function () {
                // A plain reload can be answered from the back/forward cache,
                // which is the same stale page this button exists to escape.
                var url = new URL(window.location.href);
                url.searchParams.set('nibwp-fresh', String(Date.now()));
                window.location.replace(url.href);
            };

            api('check', {})
                .then(function (r) { return r.json(); })
                .then(function (d) {
                    if (d.connected) { hardReload(); return; }
                    if (says) { says.textContent = __('Still nothing', 'nibwp'); }
                    log('check', (d.abilities ? __('No client has connected yet', 'nibwp') : __('AI Abilities are switched off', 'nibwp')), 'bad');
                    setTimeout(function () {
                        if (says) { says.textContent = __('It is connected', 'nibwp'); }
                        recheckBar.disabled = false;
                    }, 2200);
                })
                .catch(function () {
                    // Could not ask. Reload anyway: a stale page is the thing
                    // being escaped, and the reload costs a second.
                    hardReload();
                });
        });
    }

    /* ------------------------------------------------------------- pump -- */

    function run(cmd) {
        var p = cmd.payload || {};
        switch (cmd.command) {
            case 'open': return openPage(p.url, p.title);
            case 'read': return readPage(p);
            case 'click': return clickIt(p);
            case 'hover': return hoverIt(p);
            case 'screenshot': return shootIt(p);
            case 'fill': return fillIt(p);
            case 'audit': return auditPage(p);
            case 'viewport': return setViewport(p);
            case 'console': {
                var page = activePage();
                return { errors: page ? page.errors.slice(-50) : [], url: page ? page.url : null };
            }
            case 'tabs': {
                if (p.close) { closePage(p.close); }
                if (p.focus) { focusPage(p.focus); }
                if (p.reload) {
                    var a = activePage();
                    if (a) { a.frame.contentWindow.location.reload(); }
                }
                var list = [];
                pages.forEach(function (pg) { list.push({ id: pg.id, title: pg.title, url: pg.url, active: pg.id === activeId }); });
                return { tabs: list, active: activeId };
            }
            case 'follow': return followPost(p);
            case 'blocks': return readBlocks(p);
            case 'block-insert': return insertBlock(p);
            case 'block-update': return updateBlock(p);
            case 'block-delete': return deleteBlock(p);
            case 'block-schema': return blockSchema(p);
            case 'batch': {
                // Several steps for one round trip. Stops at the first failure
                // rather than plowing on: step three usually assumes step two
                // worked, and running it anyway is how a batch does damage.
                var steps = Array.isArray(p.steps) ? p.steps : [];
                var results = [];
                var chain = Promise.resolve();
                steps.forEach(function (step, i) {
                    chain = chain.then(function () {
                        if (results.some(function (r) { return r.error; })) { return; }
                        return Promise.resolve()
                            .then(function () { return run({ command: step.command, payload: step.payload || {} }); })
                            .then(function (data) { results.push({ step: i, command: step.command, data: data }); })
                            .catch(function (e) { results.push({ step: i, command: step.command, error: e.message }); });
                    });
                });
                return chain.then(function () {
                    var failed = results.filter(function (r) { return r.error; }).length;
                    return { ran: results.length, of: steps.length, failed: failed, results: results };
                });
            }
            default: throw new Error('Unknown command: ' + cmd.command);
        }
    }

    function handle(cmd) {
        // A note is the site telling the workspace what the agent just did
        // somewhere else. It is not an action: no approval, no result, and no
        // "…" in the status bar for something that has already happened.
        if (cmd.command === 'note') {
            var n = cmd.payload || {};
            log(n.label || 'step', n.detail || '', n.tone || 'ok');
            return Promise.resolve();
        }

        var gate = cmd.needs_approval ? ask(cmd.command, cmd.payload || {}) : Promise.resolve(true);

        return gate.then(function (allowed) {
            if (!allowed) {
                log(cmd.command, __('refused', 'nibwp'), 'bad');
                return {
                    error: window.__nibwpHeadless
                        ? 'This workspace is running headless, so there is nobody to approve "' + cmd.command
                            + '". Run it with Agent View open, or turn the approval gate off if this site is meant to run unattended.'
                        : 'The person watching refused this action.'
                };
            }
            setStatus(cmd.command + '…', true);
            return Promise.resolve()
                .then(function () { return run(cmd); })
                .then(function (data) {
                    log(cmd.command, summaryOf(cmd, data), 'ok');
                    return { data: data };
                })
                .catch(function (e) {
                    log(cmd.command, e.message, 'bad');
                    return { error: e.message };
                });
        }).then(function (result) {
            setStatus(IDLE, false);
            if (cmd.fireAndForget) { return; }
            return api('result', {
                id: cmd.id,
                data: JSON.stringify(result.data || null),
                error: result.error || ''
            });
        });
    }

    function summaryOf(cmd, data) {
        if (!data) { return ''; }
        if (cmd.command === 'audit') {
            /* translators: %d: number of problems the audit found */
            return sprintf(_n('%d issue', '%d issues', data.total, 'nibwp'), data.total);
        }
        if (cmd.command === 'read') {
            var count = (data.elements || []).length;
            /* translators: %d: number of elements read from the page */
            return sprintf(_n('%d element', '%d elements', count, 'nibwp'), count);
        }
        if (data.url) { return data.url; }
        return '';
    }

    // Idle is not a problem, and "Waiting for the agent" read as one. What the
    // bar should distinguish is whether anything can reach the site at all.
    var IDLE = cfg.connected ? __('Ready', 'nibwp') : __('No AI client connected', 'nibwp');

    var backoff = 250;
    var lastPoll = 0;
    var polling = false;

    function pump() {
        polling = true;
        pollAbort = (typeof AbortController !== 'undefined') ? new AbortController() : null;
        api('poll', { session: session }, pollAbort ? pollAbort.signal : null)
            .then(function (r) {
                if (!r.ok) { throw new Error('poll ' + r.status); }
                backoff = 250;
                lastPoll = Date.now();
                return r.json();
            })
            .then(function (res) {
                if (res && res.standDown) {
                    standDown = true;
                    showTakenOver();
                    return;
                }
                var chain = Promise.resolve();
                (res.commands || []).forEach(function (cmd) {
                    chain = chain.then(function () { return handle(cmd); });
                });
                return chain;
            })
            .catch(function () {
                // Back off rather than hammering a server that just refused us —
                // a tab retrying every 250ms through an outage makes it worse.
                setStatus(__('Reconnecting…', 'nibwp'), false);
                backoff = Math.min(backoff * 2, 15000);
            })
            .then(function () {
                polling = false;
                if (!standDown) { setTimeout(pump, backoff); }
            });
    }

    function showTakenOver() {
        setStatus(__('This workspace was opened somewhere else', 'nibwp'), false);

        var panel = document.createElement('div');
        panel.className = 'nw-vs-stood';
        panel.innerHTML =
            '<span class="nw-vs-stood__mark" aria-hidden="true">'
            + '<svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"'
            + ' stroke-linecap="round" stroke-linejoin="round">'
            + '<rect x="2" y="4" width="14" height="10" rx="2"/><path d="M8 20h8M12 14v6"/>'
            + '<path d="M18 8h4v8a2 2 0 0 1-2 2h-2"/></svg></span>'
            + '<h2></h2><p></p>'
            + '<div class="nw-vs-stood__acts">'
            + '<button type="button" class="nw-vs-stood__go"></button>'
            + '<button type="button" class="nw-vs-stood__alt"></button>'
            + '</div>';

        panel.children[1].textContent = __('This workspace is open in another tab', 'nibwp');
        panel.children[2].textContent = __('Only one workspace can take the agent’s commands at a time, so this one stopped listening.', 'nibwp');
        panel.querySelector('.nw-vs-stood__go').textContent = __('Take it back', 'nibwp');
        panel.querySelector('.nw-vs-stood__alt').textContent = __('Connect another assistant', 'nibwp');

        // Reloading is what takes it back — the claim goes to whoever asks last.
        panel.querySelector('.nw-vs-stood__go').addEventListener('click', function () {
            window.location.reload();
        });

        // And a way onward that is not "reload": connecting another assistant
        // needs nothing from the bus. standDown stays true — resuming the poll
        // here would put this tab back in the race it just lost, and the two
        // would split the agent's commands between them, which is the whole
        // thing standing down exists to prevent.
        panel.querySelector('.nw-vs-stood__alt').addEventListener('click', function () {
            panel.remove();
            stage.classList.remove('is-stood');
            startPinned = true;
            showStart(true);
        });

        // Hide what is on the stage rather than emptying it: the connect screen
        // and the empty note live there, and destroying them means the button
        // above has nothing left to show.
        stage.classList.add('is-stood');
        stage.appendChild(panel);
        tabsEl.hidden = true;
    }

    renderTabs();
    restoreTabs();
    setStatus(IDLE, false);

    // Claim first, then poll. Polling before the claim lands means this tab
    // asks whether it holds a workspace it has not claimed yet, and stands
    // itself down on the answer.
    api('state', { claim: session })
        .catch(function () { /* claim is best effort; the poll still works */ })
        .then(function () { pump(); });
})();
