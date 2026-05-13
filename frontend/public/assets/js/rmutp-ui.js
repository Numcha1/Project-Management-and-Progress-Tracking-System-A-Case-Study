(function (window, document) {
    'use strict';

    var RMUTP = window.RMUTP || {};
    var LOADER_ID = 'rmutp-global-loader';
    var LOADER_STYLE_ID = 'rmutp-global-loader-style';
    var RESPONSIVE_STYLE_ID = 'rmutp-responsive-style';
    var MOBILE_BREAKPOINT = 1024;

    function ensureViewportMeta() {
        var viewport = document.querySelector('meta[name="viewport"]');
        if (viewport) {
            var content = viewport.getAttribute('content') || '';
            if (content.indexOf('viewport-fit=cover') === -1) {
                viewport.setAttribute('content', 'width=device-width, initial-scale=1.0, viewport-fit=cover');
            }
            return;
        }

        var head = document.head || document.getElementsByTagName('head')[0];
        if (!head) {
            return;
        }

        viewport = document.createElement('meta');
        viewport.setAttribute('name', 'viewport');
        viewport.setAttribute('content', 'width=device-width, initial-scale=1.0, viewport-fit=cover');
        head.appendChild(viewport);
    }

    function ensureResponsiveStyle() {
        if (document.getElementById(RESPONSIVE_STYLE_ID)) {
            return;
        }

        var style = document.createElement('style');
        style.id = RESPONSIVE_STYLE_ID;
        style.textContent = [
            'html,body{max-width:100%;overflow-x:hidden;}',
            '*,*::before,*::after{box-sizing:border-box;}',
            'img,video,canvas,svg,iframe{max-width:100%;height:auto;}',
            '.rmutp-table-wrap{width:100%;overflow-x:auto;overflow-y:hidden;-webkit-overflow-scrolling:touch;scrollbar-width:thin;}',
            '.rmutp-table-wrap>table{min-width:640px;}',
            '.rmutp-nav-toggle{display:none;background:rgba(255,255,255,.18);border:1px solid rgba(255,255,255,.45);color:inherit;padding:.45rem .75rem;border-radius:.5rem;font-weight:600;line-height:1.2;}',
            '.rmutp-nav-links.rmutp-nav-collapsed{display:none !important;}',
            '.rmutp-nav-links.rmutp-nav-expanded{display:flex !important;}',
            '@media (max-width:1024px){',
            '  .container{padding-left:1rem !important;padding-right:1rem !important;}',
            '  .rmutp-nav-shell{flex-wrap:wrap !important;align-items:flex-start !important;gap:.75rem;}',
            '  .rmutp-nav-toggle{display:inline-flex;align-items:center;justify-content:center;gap:.35rem;min-height:2.2rem;}',
            '  .rmutp-nav-links{width:100%;flex-wrap:wrap !important;gap:.5rem !important;margin-top:.25rem;}',
            '  .rmutp-nav-links a,.rmutp-nav-links button{flex:1 1 auto;text-align:center;min-width:140px;}',
            '  .rmutp-modal-shell{padding:1rem;align-items:flex-start;overflow-y:auto;}',
            '}',
            '@media (max-width:640px){',
            '  .rmutp-table-wrap>table{min-width:560px;}',
            '  .rmutp-nav-links a,.rmutp-nav-links button{min-width:120px;font-size:.8rem;padding:.5rem .6rem;}',
            '}'
        ].join('');
        document.head.appendChild(style);
    }

    function isSmallScreen() {
        return window.matchMedia('(max-width:' + MOBILE_BREAKPOINT + 'px)').matches;
    }

    function enhanceTables() {
        document.querySelectorAll('table').forEach(function (table) {
            if (table.closest('.rmutp-table-wrap') || table.closest('.overflow-x-auto')) {
                return;
            }
            var parent = table.parentElement;
            if (!parent) {
                return;
            }
            var wrapper = document.createElement('div');
            wrapper.className = 'rmutp-table-wrap';
            parent.insertBefore(wrapper, table);
            wrapper.appendChild(table);
        });
    }

    function findNavShell(nav) {
        var navCls = nav.className || '';
        if (navCls.indexOf('flex') !== -1 && navCls.indexOf('justify-between') !== -1) {
            return nav;
        }

        var directDivs = Array.prototype.filter.call(nav.children, function (el) {
            return el && el.tagName === 'DIV';
        });
        for (var i = 0; i < directDivs.length; i++) {
            var cls = directDivs[i].className || '';
            if (cls.indexOf('flex') !== -1 && cls.indexOf('justify-between') !== -1) {
                return directDivs[i];
            }
        }
        return directDivs[0] || null;
    }

    function findNavLinks(shell) {
        if (!shell) {
            return null;
        }
        var directDivs = Array.prototype.filter.call(shell.children, function (el) {
            return el && el.tagName === 'DIV';
        });
        var best = null;
        var bestCount = 0;
        directDivs.forEach(function (candidate) {
            var count = candidate.querySelectorAll('a,button').length;
            if (count > bestCount) {
                best = candidate;
                bestCount = count;
            }
        });
        return bestCount > 0 ? best : null;
    }

    function applyNavVisibility(links, toggle) {
        if (!links || !toggle) {
            return;
        }
        var expanded = links.classList.contains('rmutp-nav-expanded');
        toggle.setAttribute('aria-expanded', expanded ? 'true' : 'false');
            toggle.innerHTML = expanded ? '&#10005; Menu' : '&#9776; Menu';
    }

    function enhanceNavs() {
        document.querySelectorAll('nav').forEach(function (nav) {
            if (nav.dataset.rmutpNavEnhanced === '1') {
                return;
            }
            nav.dataset.rmutpNavEnhanced = '1';

            var shell = findNavShell(nav);
            if (!shell) {
                return;
            }

            var links = findNavLinks(shell);
            if (!links) {
                return;
            }

            shell.classList.add('rmutp-nav-shell');
            links.classList.add('rmutp-nav-links');

            var itemsCount = links.querySelectorAll('a,button').length;
            if (itemsCount < 3) {
                return;
            }

            var toggle = document.createElement('button');
            toggle.type = 'button';
            toggle.className = 'rmutp-nav-toggle';
            toggle.setAttribute('aria-expanded', 'false');
            toggle.setAttribute('aria-label', 'Toggle navigation menu');
            toggle.innerHTML = '&#9776; Menu';

            var brandArea = shell.firstElementChild || shell;
            if (brandArea && brandArea.nextSibling) {
                shell.insertBefore(toggle, brandArea.nextSibling);
            } else {
                shell.appendChild(toggle);
            }

            var syncState = function () {
                if (isSmallScreen()) {
                    if (!links.classList.contains('rmutp-nav-expanded')) {
                        links.classList.add('rmutp-nav-collapsed');
                    }
                } else {
                    links.classList.remove('rmutp-nav-collapsed');
                    links.classList.remove('rmutp-nav-expanded');
                }
                applyNavVisibility(links, toggle);
            };

            toggle.addEventListener('click', function () {
                var isCollapsed = links.classList.contains('rmutp-nav-collapsed');
                links.classList.toggle('rmutp-nav-collapsed', !isCollapsed);
                links.classList.toggle('rmutp-nav-expanded', isCollapsed);
                applyNavVisibility(links, toggle);
            });

            links.querySelectorAll('a').forEach(function (link) {
                link.addEventListener('click', function () {
                    if (!isSmallScreen()) {
                        return;
                    }
                    links.classList.add('rmutp-nav-collapsed');
                    links.classList.remove('rmutp-nav-expanded');
                    applyNavVisibility(links, toggle);
                });
            });

            syncState();
            window.addEventListener('resize', syncState);
        });
    }

    function enhanceModals() {
        document.querySelectorAll('.fixed.inset-0').forEach(function (el) {
            el.classList.add('rmutp-modal-shell');
        });
    }

    function initResponsiveEnhancements() {
        ensureViewportMeta();
        ensureResponsiveStyle();
        enhanceTables();
        enhanceNavs();
        enhanceModals();
    }

    function hasSwal() {
        return typeof window.Swal !== 'undefined' && typeof window.Swal.fire === 'function';
    }

    function ensureLoaderStyle() {
        if (document.getElementById(LOADER_STYLE_ID)) {
            return;
        }

        var style = document.createElement('style');
        style.id = LOADER_STYLE_ID;
        style.textContent = [
            '#' + LOADER_ID + '{position:fixed;inset:0;background:rgba(17,24,39,.45);display:none;align-items:center;justify-content:center;z-index:9999;}',
            '#' + LOADER_ID + '.is-active{display:flex;}',
            '#' + LOADER_ID + ' .rmutp-loader-box{background:#111827;color:#f9fafb;padding:14px 18px;border-radius:12px;display:flex;align-items:center;gap:10px;box-shadow:0 8px 30px rgba(0,0,0,.25);font-size:14px;}',
            '#' + LOADER_ID + ' .rmutp-loader-spinner{width:16px;height:16px;border:2px solid rgba(255,255,255,.35);border-top-color:#fff;border-radius:50%;animation:rmutpSpin .75s linear infinite;}',
            '@keyframes rmutpSpin{to{transform:rotate(360deg);}}'
        ].join('');
        document.head.appendChild(style);
    }

    function ensureLoader() {
        ensureLoaderStyle();
        var loader = document.getElementById(LOADER_ID);
        if (loader) {
            return loader;
        }

        loader = document.createElement('div');
        loader.id = LOADER_ID;
        loader.innerHTML = '' +
            '<div class="rmutp-loader-box" role="status" aria-live="polite">' +
            '  <span class="rmutp-loader-spinner" aria-hidden="true"></span>' +
            '  <span class="rmutp-loader-text">กำลังดำเนินการ...</span>' +
            '</div>';
        document.body.appendChild(loader);
        return loader;
    }

    function setLoader(active, message) {
        var loader = ensureLoader();
        var textNode = loader.querySelector('.rmutp-loader-text');
        if (textNode && message) {
            textNode.textContent = message;
        }
        loader.classList.toggle('is-active', !!active);
    }

    function updateTextMany(selector, value) {
        document.querySelectorAll(selector).forEach(function (el) {
            el.textContent = String(value);
        });
    }

    function cleanQueryParam(name) {
        var key = name || 'status';
        var url = new URL(window.location.href);
        if (!url.searchParams.has(key)) {
            return;
        }

        url.searchParams.delete(key);
        var search = url.searchParams.toString();
        var next = url.pathname + (search ? '?' + search : '') + url.hash;
        window.history.replaceState(null, document.title, next);
    }

    function showNotice(config) {
        if (hasSwal()) {
            return window.Swal.fire(config);
        }

        var title = config && config.title ? String(config.title) : 'แจ้งเตือน';
        var text = config && config.text ? '\n' + String(config.text) : '';
        window.alert(title + text);
        return Promise.resolve();
    }

    function showStatusFromQuery(map, options) {
        var opts = options || {};
        var paramName = opts.paramName || 'status';
        var params = new URLSearchParams(window.location.search);
        var status = params.get(paramName);
        if (!status || !map || !map[status]) {
            return null;
        }

        var base = map[status];
        var config = typeof base === 'function' ? base(status) : base;
        var finalConfig = Object.assign({ icon: 'info', title: status }, config || {});

        Promise.resolve(showNotice(finalConfig)).finally(function () {
            cleanQueryParam(paramName);
        });
        return status;
    }

    function navigate(url, message) {
        setLoader(true, message || 'กำลังโหลด...');
        window.location.href = url;
    }

    function confirmAction(options, onConfirm) {
        var cfg = Object.assign({
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#2563eb',
            cancelButtonColor: '#6b7280',
            confirmButtonText: 'ตกลง',
            cancelButtonText: 'ยกเลิก'
        }, options || {});

        if (!hasSwal()) {
            if (window.confirm(cfg.title || 'ยืนยันการทำรายการ?')) {
                onConfirm();
            }
            return;
        }

        window.Swal.fire(cfg).then(function (result) {
            if (result.isConfirmed) {
                onConfirm();
            }
        });
    }

    function confirmAndNavigate(event, options) {
        if (event && typeof event.preventDefault === 'function') {
            event.preventDefault();
        }

        var opts = options || {};
        confirmAction(opts, function () {
            navigate(opts.url, opts.loadingText || 'กำลังดำเนินการ...');
        });
    }

    function attachFormSubmitGuard(selector) {
        var target = selector || 'form';
        document.querySelectorAll(target).forEach(function (form) {
            form.addEventListener('submit', function (event) {
                if (form.dataset.submitting === '1') {
                    event.preventDefault();
                    return;
                }

                form.dataset.submitting = '1';
                form.querySelectorAll('button[type="submit"], input[type="submit"]').forEach(function (btn) {
                    btn.disabled = true;
                    btn.classList.add('opacity-70', 'cursor-not-allowed');
                });
                setLoader(true, 'กำลังบันทึกข้อมูล...');
            });
        });
    }

    function attachActionLinkGuard(selector, message) {
        if (!selector) {
            return;
        }
        document.querySelectorAll(selector).forEach(function (el) {
            el.addEventListener('click', function () {
                setLoader(true, message || 'กำลังดำเนินการ...');
            });
        });
    }

    function openFilePreview(path, options) {
        if (!path) {
            return;
        }

        var opts = options || {};
        var modalId = opts.modalId || 'modal-file';
        var viewerId = opts.viewerId || 'file-viewer';
        var previewable = opts.previewableExtensions || ['.pdf', '.png', '.jpg', '.jpeg', '.gif', '.webp', '.txt'];
        var lower = String(path).toLowerCase();
        var canPreviewInIframe = previewable.some(function (ext) {
            return lower.endsWith(ext);
        });

        if (canPreviewInIframe) {
            var iframe = document.getElementById(viewerId);
            var modal = document.getElementById(modalId);
            if (iframe && modal) {
                iframe.src = path;
                modal.classList.remove('hidden');
                return;
            }
        }

        window.open(path, '_blank', 'noopener');
    }

    function startRealtimePoller(config) {
        var cfg = Object.assign({
            endpoint: 'realtime_status.php',
            scope: '',
            intervalMs: 15000,
            timeoutMs: 9000,
            onData: null,
            onError: null
        }, config || {});

        if (!cfg.scope || typeof cfg.onData !== 'function') {
            return function () {};
        }

        var stopped = false;
        var inFlight = false;
        var timer = null;

        function readJsonSafe(res) {
            return res.json().catch(function () { return null; });
        }

        function poll(forceWhenHidden) {
            if (stopped || inFlight) {
                return;
            }
            if (document.hidden && !forceWhenHidden) {
                return;
            }

            inFlight = true;
            var controller = typeof AbortController !== 'undefined' ? new AbortController() : null;
            var timeoutHandle = window.setTimeout(function () {
                if (controller) {
                    controller.abort();
                }
            }, cfg.timeoutMs);

            fetch(cfg.endpoint + '?scope=' + encodeURIComponent(cfg.scope) + '&t=' + Date.now(), {
                method: 'GET',
                headers: { 'Accept': 'application/json' },
                cache: 'no-store',
                signal: controller ? controller.signal : undefined
            })
                .then(function (res) {
                    if (!res.ok) {
                        return null;
                    }
                    return readJsonSafe(res);
                })
                .then(function (json) {
                    if (!json || !json.ok || !json.data) {
                        return;
                    }
                    cfg.onData(json.data);
                })
                .catch(function (error) {
                    if (typeof cfg.onError === 'function') {
                        cfg.onError(error);
                    }
                })
                .finally(function () {
                    window.clearTimeout(timeoutHandle);
                    inFlight = false;
                });
        }

        function onVisible() {
            if (!document.hidden) {
                poll(true);
            }
        }

        function onOnline() {
            poll(true);
        }

        poll(true);
        timer = window.setInterval(function () {
            poll(false);
        }, cfg.intervalMs);

        document.addEventListener('visibilitychange', onVisible);
        window.addEventListener('online', onOnline);
        window.addEventListener('focus', onVisible);

        return function stop() {
            stopped = true;
            if (timer) {
                window.clearInterval(timer);
            }
            document.removeEventListener('visibilitychange', onVisible);
            window.removeEventListener('online', onOnline);
            window.removeEventListener('focus', onVisible);
        };
    }

    RMUTP.updateTextMany = updateTextMany;
    RMUTP.cleanQueryParam = cleanQueryParam;
    RMUTP.showStatusFromQuery = showStatusFromQuery;
    RMUTP.navigate = navigate;
    RMUTP.confirmAction = confirmAction;
    RMUTP.confirmAndNavigate = confirmAndNavigate;
    RMUTP.attachFormSubmitGuard = attachFormSubmitGuard;
    RMUTP.attachActionLinkGuard = attachActionLinkGuard;
    RMUTP.openFilePreview = openFilePreview;
    RMUTP.startRealtimePoller = startRealtimePoller;
    RMUTP.setLoader = setLoader;
    RMUTP.initResponsiveEnhancements = initResponsiveEnhancements;

    window.RMUTP = RMUTP;

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initResponsiveEnhancements);
    } else {
        initResponsiveEnhancements();
    }
})(window, document);
