/**
 * SebaBD — AJAX layer (vanilla JS, no dependencies).
 *
 * Progressive enhancement only: none of this is required. Every feature has a
 * working no-JavaScript fallback — search and category links are ordinary GETs,
 * and every form still posts normally.
 *
 * Server contract: a request carrying `X-Requested-With: fetch` gets JSON back
 * (see json_request() / json_response() in helpers.php).
 *   form POST   {ok:true, redirect}                  or 422 {ok:false, errors:[…]}
 *   api/*.php   {ok:true, …}  (read-only GET endpoints)
 *
 * Modules
 *   forms()   form[data-ajax]            submit without a page reload
 *   search()  form[data-live-search]     suggestions under the header search box
 *   panel()   [data-product-panel]       swap the storefront grid in place
 *   stock()   [data-stock-note]          per-line availability check
 *   account() form[data-check-account]   username / email availability hints
 */
(function () {
    'use strict';

    var JSON_HEADERS = { 'X-Requested-With': 'fetch', 'Accept': 'application/json' };

    /* ------------------------------------------------------------ helpers */

    function getJSON(url) {
        return fetch(url, { headers: JSON_HEADERS, credentials: 'same-origin' }).then(function (response) {
            if (!response.ok) throw new Error('HTTP ' + response.status);
            return response.json();
        });
    }

    function escapeHtml(value) {
        return String(value === null || value === undefined ? '' : value).replace(/[&<>"']/g, function (ch) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ch];
        });
    }

    function debounce(fn, wait) {
        var timer = null;
        return function () {
            var args = arguments, self = this;
            clearTimeout(timer);
            timer = setTimeout(function () { fn.apply(self, args); }, wait);
        };
    }

    /** Build "a=1&b=2", skipping empty values. */
    function queryString(params) {
        var parts = [];
        Object.keys(params || {}).forEach(function (key) {
            var value = params[key];
            if (value === '' || value === null || value === undefined || value === false) return;
            parts.push(encodeURIComponent(key) + '=' + encodeURIComponent(value));
        });
        return parts.join('&');
    }

    /* ------------------------------------------------------- form submits */

    // Error text → the inputs it should highlight. The first keyword found in an
    // error message wins; candidates are tried in order against this form only.
    var FIELD_KEYWORDS = [
        ['full name', ['full_name']],
        ['username', ['username']],
        ['email', ['email']],
        ['phone', ['phone']],
        ['passwords do not match', ['password', 'password_confirm']],
        ['password', ['password']],
        ['company name', ['company_name']],
        ['contact person', ['contact_person']],
        ['branch name', ['branch_name']],
        ['address', ['shipping_address', 'address', 'branch_address']],
        ['subject', ['subject']],
        ['credentials', ['identifier', 'password']],
        ['product', ['product_id[]']]
    ];

    function fieldsForError(form, message) {
        var text = String(message).toLowerCase();
        for (var i = 0; i < FIELD_KEYWORDS.length; i++) {
            if (text.indexOf(FIELD_KEYWORDS[i][0]) === -1) continue;
            return FIELD_KEYWORDS[i][1].filter(function (name) {
                return !!form.elements[name];
            });
        }
        return [];
    }

    function errorBox(form) {
        var box = form.querySelector('[data-ajax-errors]');
        if (!box) {
            box = document.createElement('div');
            box.className = 'ajax-errors';
            box.setAttribute('data-ajax-errors', '');
            form.insertBefore(box, form.firstChild);
        }
        return box;
    }

    function clearFieldStates(form) {
        form.querySelectorAll('.is-invalid, .is-valid').forEach(function (field) {
            field.classList.remove('is-invalid', 'is-valid');
        });
    }

    function showErrors(form, errors) {
        var box = errorBox(form);
        clearFieldStates(form);

        var items = '';
        errors.forEach(function (message) {
            items += '<li>' + escapeHtml(message) + '</li>';
            fieldsForError(form, message).forEach(function (name) {
                var field = form.elements[name];
                if (field && field.classList) field.classList.add('is-invalid');
            });
        });
        box.innerHTML = '<div class="alert alert-danger mb-0"><ul class="mb-0">' + items + '</ul></div>';

        var first = form.querySelector('.is-invalid');
        var target = first || box;
        if (target.scrollIntoView) target.scrollIntoView({ behavior: 'smooth', block: 'center' });
        if (first && first.focus) first.focus({ preventScroll: true });
    }

    function setBusy(form, busy) {
        form.classList.toggle('is-busy', busy);
        form.querySelectorAll('button[type="submit"], input[type="submit"]').forEach(function (button) {
            button.disabled = busy;
        });
    }

    function forms() {
        document.querySelectorAll('form[data-ajax]').forEach(function (form) {
            form.addEventListener('submit', function (event) {
                event.preventDefault();
                setBusy(form, true);
                clearFieldStates(form);

                fetch(form.action, {
                    method: (form.method || 'post').toUpperCase(),
                    headers: JSON_HEADERS,
                    body: new FormData(form),
                    credentials: 'same-origin'
                })
                    .then(function (response) {
                        var isJson = (response.headers.get('content-type') || '').indexOf('application/json') !== -1;
                        if (!isJson) {
                            // The server answered with a page instead of JSON —
                            // typically a login redirect because the session
                            // expired. Follow it like a browser would.
                            return { page: response.url || window.location.href };
                        }
                        return response.json().then(function (data) {
                            return { status: response.status, data: data };
                        });
                    })
                    .then(function (result) {
                        if (result.page) {
                            window.location.assign(result.page);
                            return;
                        }
                        if (result.data && result.data.ok) {
                            // The server already queued the flash message; going
                            // there shows exactly what a normal post would.
                            window.location.assign(result.data.redirect || window.location.href);
                            return;
                        }
                        showErrors(form, (result.data && result.data.errors) || ['Something went wrong — please try again.']);
                        setBusy(form, false);
                    })
                    .catch(function () {
                        showErrors(form, ['Could not reach the server — check your connection and try again.']);
                        setBusy(form, false);
                    });
            });
        });
    }

    /* ------------------------------------------------- storefront panel */

    /** Read ?q / ?category / ?all out of a query string into a params object. */
    function paramsFromSearch(search) {
        var source = new URLSearchParams(search || '');
        var params = {};
        if (source.get('q')) params.q = source.get('q');
        if (source.get('category')) params.category = source.get('category');
        if (source.has('all')) params.all = '1';
        return params;
    }

    function panel() {
        var panel = document.querySelector('[data-product-panel]');
        if (!panel) return;

        function syncSidebar(params) {
            var wanted = '?' + queryString(params);
            document.querySelectorAll('[data-category-list] [data-filter-link]').forEach(function (link) {
                var url = new URL(link.getAttribute('href'), window.location.href);
                link.classList.toggle('active', url.search === wanted || (wanted === '?' && url.search === ''));
            });
        }

        function apply(params, options) {
            options = options || {};
            panel.classList.add('is-loading');

            fetch('index.php?ajax=panel&' + queryString(params), { headers: JSON_HEADERS, credentials: 'same-origin' })
                .then(function (response) {
                    if (!response.ok) throw new Error('HTTP ' + response.status);
                    return response.text();
                })
                .then(function (html) {
                    panel.innerHTML = html;
                    panel.classList.remove('is-loading');
                    syncSidebar(params);
                    if (options.push !== false) {
                        window.history.pushState({ panel: params }, '', 'index.php?' + queryString(params) + '#featured-products');
                    }
                    if (options.scroll) {
                        var anchor = document.getElementById('featured-products');
                        if (anchor && anchor.scrollIntoView) anchor.scrollIntoView({ behavior: 'smooth', block: 'start' });
                    }
                })
                .catch(function () {
                    panel.classList.remove('is-loading');
                    // Fall back to a normal navigation rather than an empty grid.
                    window.location.assign('index.php?' + queryString(params));
                });
        }

        // Category links and the "Clear filter" link.
        document.querySelectorAll('[data-filter-link]').forEach(function (link) {
            link.addEventListener('click', function (event) {
                var url = new URL(link.getAttribute('href'), window.location.href);
                if (url.pathname.split('/').pop() !== 'index.php') return;
                event.preventDefault();
                apply(paramsFromSearch(url.search), { scroll: true });
            });
        });

        // Back / forward buttons restore the grid for the previous filter.
        window.addEventListener('popstate', function (event) {
            var params = (event.state && event.state.panel) || paramsFromSearch(window.location.search);
            apply(params, { push: false });
        });

        panel.applyFilter = apply;
    }

    /* -------------------------------------------------------- live search */

    function search() {
        var form = document.querySelector('form[data-live-search]');
        if (!form) return;
        var input = form.querySelector('input[name="q"]');
        var box = form.querySelector('.search-suggestions');
        if (!input || !box) return;

        var items = [];
        var active = -1;

        function close() {
            box.classList.add('d-none');
            box.innerHTML = '';
            items = [];
            active = -1;
            input.setAttribute('aria-expanded', 'false');
        }

        function highlight(index) {
            if (!items.length) return;
            active = (index + items.length) % items.length;
            items.forEach(function (item, i) {
                item.classList.toggle('is-active', i === active);
            });
        }

        function choose(index) {
            var item = items[index];
            if (!item) return;
            var target = { q: item.dataset.name };
            var panel = document.querySelector('[data-product-panel]');
            close();
            if (panel && panel.applyFilter) {
                panel.applyFilter(target, { scroll: true });
            } else {
                window.location.assign('index.php?' + queryString(target) + '#featured-products');
            }
        }

        function render(data) {
            var products = (data && data.products) || [];
            if (!products.length) {
                box.innerHTML = '<p class="search-suggestion-empty mb-0">No products matched “' + escapeHtml(input.value.trim()) + '”.</p>';
                items = [];
                active = -1;
            } else {
                box.innerHTML = products.map(function (product, index) {
                    var meta = [product.brand, product.category].filter(Boolean).join(' · ');
                    return '<a class="search-suggestion" href="' + escapeHtml(product.url) + '" data-suggestion data-name="' + escapeHtml(product.name) + '" data-index="' + index + '" role="option">'
                        + '<img src="' + escapeHtml(product.image || '') + '" alt="">'
                        + '<span class="search-suggestion-body">'
                        + '<span class="search-suggestion-name">' + escapeHtml(product.name) + '</span>'
                        + '<span class="search-suggestion-meta">' + escapeHtml(meta) + ' — ' + escapeHtml(product.price_formatted) + ' · ' + escapeHtml(product.stock) + '</span>'
                        + '</span></a>';
                }).join('') + (data.more
                    ? '<button type="submit" class="search-suggestion-search">See all matches for “' + escapeHtml(data.query) + '”</button>'
                    : '');
                items = Array.prototype.slice.call(box.querySelectorAll('[data-suggestion]'));
                active = -1;
            }
            box.classList.remove('d-none');
            input.setAttribute('aria-expanded', 'true');
        }

        var lookup = debounce(function () {
            var term = input.value.trim();
            if (term.length < 2) { close(); return; }
            getJSON('api/products.php?limit=6&q=' + encodeURIComponent(term)).then(render).catch(close);
        }, 220);

        input.addEventListener('input', lookup);
        input.addEventListener('focus', function () { if (input.value.trim().length >= 2) lookup(); });

        input.addEventListener('keydown', function (event) {
            if (box.classList.contains('d-none')) return;
            if (event.key === 'ArrowDown') {
                event.preventDefault();
                highlight(active + 1);
            } else if (event.key === 'ArrowUp') {
                event.preventDefault();
                highlight(active - 1);
            } else if (event.key === 'Enter' && active >= 0) {
                event.preventDefault();
                choose(active);
            } else if (event.key === 'Escape') {
                close();
            }
        });

        box.addEventListener('click', function (event) {
            var item = event.target.closest('[data-suggestion]');
            if (!item) return;
            event.preventDefault();
            choose(parseInt(item.dataset.index, 10));
        });

        document.addEventListener('click', function (event) {
            if (!form.contains(event.target)) close();
        });

        form.addEventListener('submit', function (event) {
            var panel = document.querySelector('[data-product-panel]');
            if (!panel || !panel.applyFilter) return; // other pages: normal GET
            event.preventDefault();
            close();
            var term = input.value.trim();
            panel.applyFilter(term ? { q: term } : {}, { scroll: true });
        });
    }

    /* ------------------------------------------------- per-line stock check */

    function stock() {
        var container = document.querySelector('.line-items');
        if (!container) return;

        var timers = new WeakMap();

        function schedule(row, delay) {
            if (!row || !row.querySelector('[data-stock-note]')) return;
            clearTimeout(timers.get(row));
            timers.set(row, setTimeout(function () { check(row); }, delay));
        }

        function check(row) {
            var select = row.querySelector('.line-product');
            var note = row.querySelector('[data-stock-note]');
            if (!select || !note) return;

            var productId = parseInt(select.value, 10);
            if (!productId) {
                note.innerHTML = '';
                note.className = 'line-stock text-muted';
                return;
            }

            var qtyField = row.querySelector('.line-qty');
            var quantity = Math.max(1, parseInt(qtyField && qtyField.value, 10) || 1);

            note.className = 'line-stock text-muted';
            note.innerHTML = '<i class="fa-solid fa-spinner fa-spin me-1"></i>Checking availability…';

            getJSON('api/stock.php?product_id=' + productId + '&quantity=' + quantity)
                .then(function (data) {
                    if (parseInt(select.value, 10) !== productId) return; // row changed meanwhile
                    var tone = data.status === 'ok' ? 'text-success'
                        : (data.status === 'low' ? 'text-warning-emphasis' : 'text-danger');
                    var icon = data.status === 'ok' ? 'fa-circle-check' : 'fa-triangle-exclamation';
                    note.className = 'line-stock ' + tone;
                    note.innerHTML = '<i class="fa-solid ' + icon + ' me-1"></i>' + escapeHtml(data.message);
                })
                .catch(function () {
                    note.innerHTML = '';
                    note.className = 'line-stock text-muted';
                });
        }

        container.addEventListener('change', function (event) {
            var row = event.target.closest('.line-item-row');
            if (row) schedule(row, 0);
        });
        container.addEventListener('input', function (event) {
            var row = event.target.closest('.line-item-row');
            if (row && event.target.classList.contains('line-qty')) schedule(row, 450);
        });

        // Rows that came back from the server with a product already selected
        // (for example after a validation error) get their note immediately.
        container.querySelectorAll('.line-item-row').forEach(function (row) {
            var select = row.querySelector('.line-product');
            if (select && select.value) schedule(row, 0);
        });
    }

    /* ------------------------------------------- username / email availability */

    function account() {
        document.querySelectorAll('form[data-check-account]').forEach(function (form) {
            ['username', 'email'].forEach(function (field) {
                var input = form.elements[field];
                var hint = form.querySelector('[data-field-hint="' + field + '"]');
                if (!input || !hint) return;

                var check = debounce(function () {
                    var value = input.value.trim();
                    if (value.length < (field === 'email' ? 5 : 3) || (field === 'email' && value.indexOf('@') === -1)) {
                        hint.textContent = '';
                        hint.className = 'field-hint';
                        input.classList.remove('is-invalid', 'is-valid');
                        return;
                    }

                    var params = {};
                    params[field] = value;
                    getJSON('api/check-account.php?' + queryString(params)).then(function (data) {
                        var info = data[field];
                        if (!info || input.value.trim() !== info.value) return; // stale reply
                        var ok = info.valid && !info.taken;
                        hint.textContent = info.message;
                        hint.className = 'field-hint ' + (ok ? 'text-success' : 'text-danger');
                        input.classList.toggle('is-invalid', !ok);
                        input.classList.toggle('is-valid', ok);
                    }).catch(function () {
                        hint.textContent = '';
                        hint.className = 'field-hint';
                    });
                }, 420);

                input.addEventListener('input', check);
                input.addEventListener('blur', check);
            });
        });
    }

    /* -------------------------------------------------------------- startup */

    document.addEventListener('DOMContentLoaded', function () {
        forms();
        panel();
        search();
        stock();
        account();
    });
})();
