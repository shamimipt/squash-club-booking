/**
 * Squash Club Booking — Admin Application
 * Version: 1.0.0
 *
 * Architecture:
 *  - SCB.App         — bootstrapper, router, global state
 *  - SCB.Api         — REST API client
 *  - SCB.Toast       — toast notification system
 *  - SCB.Modal       — confirmation & content modals
 *  - SCB.Views       — page view renderers (one per sidebar item)
 *  - SCB.Utils       — shared utilities
 */

/* global scbAdmin, jQuery */
'use strict';

const SCB = (function ($) {

    // ── Config ──────────────────────────────────────────────────────────────
    const _admin = typeof scbAdmin !== 'undefined' ? scbAdmin : {};
    const CONFIG = {
        restUrl: _admin.restUrl || '/wp-json/scb/v1/',
        nonce: _admin.nonce || '',
        restNonce: _admin.restNonce || '',
        pluginUrl: _admin.pluginUrl || '',
        adminUrl: '',
        strings: _admin.strings || {},
        dateFormat: _admin.dateFormat || 'YYYY-MM-DD',
        timeFormat: _admin.timeFormat || 'g:i A',
    };

    // ── Utilities ────────────────────────────────────────────────────────────
    const Utils = {
        /**
         * Format a date string in a human-readable way.
         * @param {string} dateStr
         * @param {boolean} includeTime
         * @returns {string}
         */
        formatDate(dateStr, includeTime = false) {
            if (!dateStr) return '—';
            try {
                let normalized = String(dateStr).trim();
                if (normalized.includes(' ')) {
                    normalized = normalized.replace(' ', 'T');
                } else if (!normalized.includes('T')) {
                    normalized = normalized + 'T00:00:00';
                }
                const d = new Date(normalized);
                if (isNaN(d.getTime())) return dateStr;

                const opts = { year: 'numeric', month: 'short', day: 'numeric' };
                if (includeTime) {
                    opts.hour = '2-digit';
                    opts.minute = '2-digit';
                    return d.toLocaleString('en-US', opts);
                }
                return d.toLocaleDateString('en-US', opts);
            } catch (e) {
                return dateStr;
            }
        },

        /**
         * Format a time string (e.g. "14:30:00" or "09:00") to 12-hour AM/PM format.
         * @param {string} timeStr
         * @returns {string}
         */
        formatTime(timeStr) {
            if (!timeStr) return '';
            try {
                const parts = String(timeStr).trim().split(':');
                if (parts.length >= 2) {
                    let h = parseInt(parts[0], 10);
                    const m = parts[1];
                    if (isNaN(h)) return timeStr;
                    const ampm = h >= 12 ? 'PM' : 'AM';
                    h = h % 12 || 12;
                    return `${h}:${m} ${ampm}`;
                }
                return timeStr;
            } catch (e) {
                return timeStr;
            }
        },

        /**
         * Format a currency value.
         * @param {number|string} amount
         * @param {string} currency
         * @returns {string}
         */
        formatCurrency(amount, currency = 'USD') {
            const n = parseFloat(amount) || 0;
            return new Intl.NumberFormat('en-US', { style: 'currency', currency }).format(n);
        },

        /**
         * Escape HTML special characters.
         * @param {string} str
         * @returns {string}
         */
        escHtml(str) {
            const div = document.createElement('div');
            div.appendChild(document.createTextNode(String(str ?? '')));
            return div.innerHTML;
        },

        /**
         * Debounce a function.
         * @param {Function} fn
         * @param {number} delay
         * @returns {Function}
         */
        debounce(fn, delay = 300) {
            let timer;
            return function (...args) {
                clearTimeout(timer);
                timer = setTimeout(() => fn.apply(this, args), delay);
            };
        },

        /**
         * Generate a UUID v4.
         * @returns {string}
         */
        uuid() {
            return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, c => {
                const r = Math.random() * 16 | 0;
                const v = c === 'x' ? r : (r & 0x3 | 0x8);
                return v.toString(16);
            });
        },

        /**
         * Return a status badge HTML string.
         * @param {string} status
         * @param {string} type  'booking'|'payment'|'camp'
         * @returns {string}
         */
        statusBadge(status, type = 'booking') {
            const bookingMap = {
                pending: { cls: 'pending', label: 'Pending' },
                approved: { cls: 'success', label: 'Approved' },
                rejected: { cls: 'error', label: 'Rejected' },
                rescheduled: { cls: 'info', label: 'Rescheduled' },
                cancelled: { cls: 'muted', label: 'Cancelled' },
                waitlisted: { cls: 'warning', label: 'Waitlisted' },
                completed: { cls: 'success', label: 'Completed' },
            };
            const paymentMap = {
                unpaid: { cls: 'muted', label: 'Unpaid' },
                pending: { cls: 'warning', label: 'Pending' },
                paid: { cls: 'success', label: 'Paid' },
                failed: { cls: 'error', label: 'Failed' },
                refunded: { cls: 'info', label: 'Refunded' },
                partially_refunded: { cls: 'warning', label: 'Part. Refunded' },
            };

            const map = type === 'payment' ? paymentMap : bookingMap;
            const entry = map[status] || { cls: 'muted', label: status };
            return `<span class="scb-badge scb-badge--${entry.cls} scb-badge--dot">${Utils.escHtml(entry.label)}</span>`;
        },

        /**
         * Show or hide a loader inside an element.
         * @param {HTMLElement|string} el
         * @param {boolean} show
         */
        setLoading(el, show) {
            const elem = typeof el === 'string' ? document.querySelector(el) : el;
            if (!elem) return;
            if (show) {
                elem.classList.add('is-loading');
                elem.setAttribute('disabled', 'disabled');
            } else {
                elem.classList.remove('is-loading');
                elem.removeAttribute('disabled');
            }
        },

        /**
         * Build a query string from an object, omitting empty values.
         * @param {Object} params
         * @returns {string}
         */
        buildQuery(params) {
            return Object.entries(params)
                .filter(([, v]) => v !== '' && v !== null && v !== undefined)
                .map(([k, v]) => `${encodeURIComponent(k)}=${encodeURIComponent(v)}`)
                .join('&');
        },

        /**
         * Render a simple skeleton loader row in a table.
         * @param {number} cols
         * @param {number} rows
         * @returns {string}
         */
        skeletonRows(cols = 6, rows = 5) {
            let html = '';
            for (let i = 0; i < rows; i++) {
                html += '<tr>';
                for (let j = 0; j < cols; j++) {
                    html += `<td><div class="scb-skeleton" style="height:14px;width:${70 + Math.random() * 30 | 0}%;"></div></td>`;
                }
                html += '</tr>';
            }
            return html;
        },
    };

    // ── REST API Client ──────────────────────────────────────────────────────
    const Api = {
        /**
         * Make a REST API request.
         * @param {string} endpoint  Relative endpoint (e.g. 'bookings')
         * @param {Object} options
         * @returns {Promise}
         */
        request(endpoint, options = {}) {
            const { method = 'GET', data = null, params = {} } = options;

            let url = CONFIG.restUrl + endpoint;
            const query = Utils.buildQuery(params);
            if (query) url += '?' + query;

            const fetchOpts = {
                method,
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce': CONFIG.restNonce,
                },
                credentials: 'same-origin',
            };

            if (data && method !== 'GET') {
                fetchOpts.body = JSON.stringify(data);
            }

            return fetch(url, fetchOpts)
                .then(async (res) => {
                    let json = null;
                    try {
                        json = await res.json();
                    } catch (e) {
                        json = null;
                    }
                    if (!res.ok) {
                        const msg = json?.message || json?.data?.message || (json?.data && typeof json.data === 'string' ? json.data : null) || `Request failed (HTTP ${res.status})`;
                        throw new Error(msg);
                    }
                    return json || {};
                });
        },

        get: (ep, params) => Api.request(ep, { method: 'GET', params }),
        post: (ep, data) => Api.request(ep, { method: 'POST', data }),
        put: (ep, data) => Api.request(ep, { method: 'PUT', data }),
        patch: (ep, data) => Api.request(ep, { method: 'PATCH', data }),
        delete: (ep) => Api.request(ep, { method: 'DELETE' }),
    };

    // ── Toast System ─────────────────────────────────────────────────────────
    const Toast = {
        container: null,
        _timers: {},

        /**
         * Initialise the toast container element.
         */
        init() {
            this.container = document.getElementById('scb-toast-container');
        },

        /**
         * Show a toast notification.
         * @param {string} title
         * @param {Object} options
         */
        show(title, options = {}) {
            const {
                message = '',
                type = 'info',   // success | error | warning | info
                duration = (type === 'error' ? 7000 : 4000),
                id = Utils.uuid(),
            } = options;

            const icons = {
                success: `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>`,
                error: `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>`,
                warning: `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>`,
                info: `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="8"/><line x1="12" y1="12" x2="12" y2="16"/></svg>`,
            };

            // Prevent duplicate toasts with same ID.
            const existing = document.getElementById(`scb-toast-${id}`);
            if (existing) return;

            if (!this.container) {
                this.container = document.getElementById('scb-toast-container');
                if (!this.container) {
                    this.container = document.createElement('div');
                    this.container.id = 'scb-toast-container';
                    this.container.className = 'scb-toast-container';
                    this.container.setAttribute('role', 'status');
                    this.container.setAttribute('aria-live', 'polite');
                    document.body.appendChild(this.container);
                }
            }

            const toast = document.createElement('div');
            toast.className = `scb-toast scb-toast--${type}`;
            toast.id = `scb-toast-${id}`;
            toast.setAttribute('role', 'alert');
            toast.innerHTML = `
                <span class="scb-toast__icon" aria-hidden="true">${icons[type] || icons.info}</span>
                <div class="scb-toast__body">
                    <div class="scb-toast__title">${Utils.escHtml(title)}</div>
                    ${message ? `<div class="scb-toast__message">${Utils.escHtml(message)}</div>` : ''}
                </div>
                <button class="scb-toast__close" aria-label="Dismiss notification">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                </button>
                <div class="scb-toast__progress" style="animation-duration: ${duration}ms;"></div>
            `;

            this.container.appendChild(toast);

            // Close button.
            toast.querySelector('.scb-toast__close').addEventListener('click', () => {
                this.dismiss(toast);
            });

            // Auto-dismiss.
            if (duration > 0) {
                this._timers[id] = setTimeout(() => this.dismiss(toast), duration);
            }
        },

        success: (title, msg = '') => Toast.show(title, { type: 'success', message: msg }),
        error: (title, msg = '') => Toast.show(title, { type: 'error', message: msg }),
        warning: (title, msg = '') => Toast.show(title, { type: 'warning', message: msg }),
        info: (title, msg = '') => Toast.show(title, { type: 'info', message: msg }),

        /**
         * Dismiss (animate out and remove) a toast element.
         * @param {HTMLElement} toast
         */
        dismiss(toast) {
            if (!toast || toast.classList.contains('is-leaving')) return;
            toast.classList.add('is-leaving');
            toast.addEventListener('animationend', () => toast.remove(), { once: true });
        },
    };

    // ── Modal System ─────────────────────────────────────────────────────────
    const Modal = {
        overlay: null,
        modal: null,
        title: null,
        body: null,
        footer: null,
        _onConfirm: null,

        init() {
            this.overlay = document.getElementById('scb-modal-overlay');
            this.modal = document.getElementById('scb-modal');
            this.title = document.getElementById('scb-modal-title');
            this.body = document.getElementById('scb-modal-body');
            this.footer = document.getElementById('scb-modal-footer');

            document.getElementById('scb-modal-close')?.addEventListener('click', () => this.close());

            this.overlay?.addEventListener('click', (e) => {
                if (e.target === this.overlay || e.target.closest('[data-modal-close], .scb-btn--modal-cancel, .scb-modal-cancel')) {
                    this.close();
                }
            });

            document.addEventListener('keydown', (e) => {
                if (e.key === 'Escape' && !this.overlay.hidden) this.close();
            });
        },

        /**
         * Open the modal with custom content.
         * @param {Object} options
         */
        open({ title = '', body = '', content = '', footer = '', size = '' } = {}) {
            this.title.textContent = title;
            this.body.innerHTML = body || content;
            this.footer.innerHTML = footer;

            this.modal.className = 'scb-modal' + (size ? ` scb-modal--${size}` : '');
            this.overlay.hidden = false;
            this.overlay.removeAttribute('hidden');
            this.overlay.classList.add('is-open');

            // Focus first focusable element.
            setTimeout(() => {
                const first = this.modal.querySelector('button, input, select, textarea, [tabindex]:not([tabindex="-1"])');
                if (first) first.focus();
            }, 50);
        },

        /**
         * Show a confirmation dialog.
         * @param {Object} options
         * @returns {Promise<boolean>}
         */
        confirm({ title = 'Confirm Action', message = '', confirmText = 'Confirm', confirmClass = 'scb-btn--danger', cancelText = 'Cancel', body: extraBody = '', onConfirm = null } = {}) {
            return new Promise((resolve) => {
                const bodyHtml = `
                    <p style="color:var(--scb-text-2);font-size:16px;line-height:1.6;margin-bottom:12px;">${Utils.escHtml(message)}</p>
                    ${extraBody}
                `;
                const footerHtml = `
                    <button class="scb-btn scb-btn--secondary" id="scb-modal-cancel" data-modal-close>${Utils.escHtml(cancelText)}</button>
                    <button class="scb-btn ${confirmClass}" id="scb-modal-confirm">${Utils.escHtml(confirmText)}</button>
                `;

                this.open({ title, body: bodyHtml, footer: footerHtml });

                const cancelBtn = this.footer.querySelector('#scb-modal-cancel');
                const confirmBtn = this.footer.querySelector('#scb-modal-confirm');

                cancelBtn?.addEventListener('click', () => {
                    this.close();
                    resolve(false);
                });

                confirmBtn?.addEventListener('click', async () => {
                    if (confirmBtn) {
                        confirmBtn.disabled = true;
                        confirmBtn.classList.add('is-loading');
                        confirmBtn.textContent = 'Processing…';
                    }
                    let callbackSucceeded = true;
                    if (typeof onConfirm === 'function') {
                        try {
                            await onConfirm();
                        } catch (err) {
                            callbackSucceeded = false;
                            console.error('Modal onConfirm error:', err);
                            Toast.error('Action Failed', err.message || 'An error occurred.');
                        }
                    }
                    this.close();
                    resolve(callbackSucceeded);
                });
            });
        },

        close() {
            if (this.overlay) {
                this.overlay.hidden = true;
                this.overlay.setAttribute('hidden', '');
                this.overlay.classList.remove('is-open');
            }
        },
    };

    // ── View Renderer Helpers ────────────────────────────────────────────────
    const ViewUtils = {
        /**
         * Render pagination controls.
         * @param {number} current
         * @param {number} total
         * @param {number} perPage
         * @param {Function} onPageChange
         * @returns {string}
         */
        renderPagination(current, total, perPage, onPageChange) {
            const pages = Math.ceil(total / perPage) || 1;
            const from = Math.min((current - 1) * perPage + 1, total);
            const to = Math.min(current * perPage, total);

            if (pages <= 1) return '';

            let html = '<div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;padding:12px 16px;">';
            html += `<span class="scb-pagination__info">Showing ${from}–${to} of ${total} results</span>`;
            html += '<div class="scb-pagination">';

            // Prev.
            html += `<button class="scb-pagination__btn" data-page="${current - 1}" ${current <= 1 ? 'disabled' : ''}>‹</button>`;

            // Pages.
            const range = ViewUtils._paginationRange(current, pages);
            for (const p of range) {
                if (p === '...') {
                    html += `<span class="scb-pagination__btn" style="pointer-events:none;">…</span>`;
                } else {
                    html += `<button class="scb-pagination__btn ${p === current ? 'is-active' : ''}" data-page="${p}">${p}</button>`;
                }
            }

            // Next.
            html += `<button class="scb-pagination__btn" data-page="${current + 1}" ${current >= pages ? 'disabled' : ''}>›</button>`;

            html += '</div></div>';
            return html;
        },

        _paginationRange(current, pages) {
            const delta = 2;
            const range = [];
            for (let i = Math.max(2, current - delta); i <= Math.min(pages - 1, current + delta); i++) {
                range.push(i);
            }
            if (current - delta > 2) range.unshift('...');
            if (current + delta < pages - 1) range.push('...');
            range.unshift(1);
            if (pages > 1) range.push(pages);
            return range;
        },

        /**
         * Attach pagination click handlers.
         * @param {HTMLElement} container
         * @param {Function} onPageChange
         */
        attachPagination(container, onPageChange) {
            container.addEventListener('click', (e) => {
                const btn = e.target.closest('[data-page]');
                if (!btn || btn.disabled) return;
                const page = parseInt(btn.dataset.page, 10);
                if (!isNaN(page)) onPageChange(page);
            });
        },

        /**
         * Render a sortable <th> element.
         * @param {string} label Text label to display.
         * @param {string} key Sort key name.
         * @param {string} currentKey Currently active sort key.
         * @param {string} currentOrder 'ASC' | 'DESC'.
         * @param {Object} [options]
         * @returns {string}
         */
        sortHeader(label, key, currentKey, currentOrder, options = {}) {
            const isCurrent = (currentKey || '').toLowerCase() === (key || '').toLowerCase();
            const orderUpper = (currentOrder || 'ASC').toUpperCase();
            const nextOrder = isCurrent && orderUpper === 'ASC' ? 'DESC' : 'ASC';
            const ariaSort = isCurrent ? (orderUpper === 'ASC' ? 'ascending' : 'descending') : 'none';
            const sortClass = isCurrent ? `is-sortable is-sorted sort-${orderUpper.toLowerCase()}` : 'is-sortable';
            const indicator = isCurrent
                ? `<span class="scb-sort-indicator is-active" aria-hidden="true">${orderUpper === 'ASC' ? '↑' : '↓'}</span>`
                : `<span class="scb-sort-indicator is-idle" aria-hidden="true">↕</span>`;
            const styleAttr = options.style ? ` style="${options.style}"` : '';

            return `<th class="${sortClass}" data-sort-key="${Utils.escHtml(key)}" data-sort-next="${nextOrder}" role="columnheader" aria-sort="${ariaSort}" tabindex="0" title="Sort by ${Utils.escHtml(label)}"${styleAttr}><div class="scb-th-inner"><span>${Utils.escHtml(label)}</span>${indicator}</div></th>`;
        },

        /**
         * Bind click & keyboard handlers to sortable <th> elements within a table or container.
         * @param {HTMLElement|string} tableOrContainer
         * @param {Function} onSort Callback (newKey, newOrder)
         */
        bindSortHeaders(tableOrContainer, onSort) {
            const container = typeof tableOrContainer === 'string' ? document.querySelector(tableOrContainer) : tableOrContainer;
            if (!container) return;

            const handleSort = (th) => {
                if (!th) return;
                const key = th.dataset.sortKey;
                const nextOrder = th.dataset.sortNext || 'ASC';
                if (key && typeof onSort === 'function') {
                    onSort(key, nextOrder);
                }
            };

            container.addEventListener('click', (e) => {
                const th = e.target.closest('th.is-sortable[data-sort-key]');
                if (th) handleSort(th);
            });

            container.addEventListener('keydown', (e) => {
                if (e.key === 'Enter' || e.key === ' ') {
                    const th = e.target.closest('th.is-sortable[data-sort-key]');
                    if (th && (e.target === th || th.contains(e.target))) {
                        e.preventDefault();
                        handleSort(th);
                    }
                }
            });
        },

        /**
         * Build an action button.
         * @param {string} label
         * @param {string} action
         * @param {string} variant  'primary'|'success'|'danger'|'ghost'
         * @param {Object} data
         * @returns {string}
         */
        actionBtn(label, action, variant = 'ghost', data = {}) {
            const attrs = Object.entries(data).map(([k, v]) => `data-${k}="${Utils.escHtml(v)}"`).join(' ');
            return `<button class="scb-btn scb-btn--sm scb-btn--${variant}" data-action="${action}" ${attrs}>${Utils.escHtml(label)}</button>`;
        },
    };

    // ── Dashboard View ───────────────────────────────────────────────────────
    const DashboardView = {
        async render(container) {
            const userName = document.getElementById('scb-app')?.dataset?.userName || 'Club Admin';
            const todayFormatted = new Date().toLocaleDateString('en-US', { month: '2-digit', day: '2-digit', year: 'numeric' });

            container.innerHTML = `
                <div class="scb-hero-banner">
                    <div class="scb-hero-banner__left">
                        <h1 class="scb-hero-banner__title">Overview Dashboard</h1>
                        <p class="scb-hero-banner__subtitle">Track your club performance, court bookings, clinics, and revenue insights.</p>
                        <div class="scb-hero-banner__pills">
                            <span class="scb-hero-pill">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                                ${Utils.escHtml(userName)}
                            </span>
                            <span class="scb-hero-pill">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14"><circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/></svg>
                                Active Season
                            </span>
                        </div>
                    </div>
                    <div class="scb-hero-banner__right">
                        <div class="scb-hero-date-controls">
                            <div class="scb-hero-date-pill">
                                <span class="scb-hero-date-label">TODAY</span>
                                <span class="scb-hero-date-val">${todayFormatted}</span>
                            </div>
                            <button class="scb-btn scb-hero-apply-btn" id="scb-dash-refresh">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="23 4 23 10 17 10"/><polyline points="1 20 1 14 7 14"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/></svg>
                                Refresh
                            </button>
                        </div>
                    </div>
                </div>

                <div class="scb-stats-grid" id="scb-stats-grid">
                    ${this._skeletonStats()}
                </div>

                <div style="display:grid;grid-template-columns:1fr 1fr;gap:24px;" id="scb-dash-lower">
                    <div class="scb-card">
                        <div class="scb-card__header">
                            <div style="display:flex;align-items:center;gap:10px;">
                                <span style="font-size:20px;">🏆</span>
                                <span class="scb-card__title">Recent Bookings</span>
                                <span class="scb-badge scb-badge--primary scb-badge--pill">5/5</span>
                            </div>
                            <a href="?page=scb-bookings" class="scb-btn scb-btn--sm scb-btn--secondary">View All</a>
                        </div>
                        <div class="scb-card__body" id="scb-recent-bookings">
                            <div class="scb-loading-overlay"><div class="scb-spinner"></div></div>
                        </div>
                    </div>
                    <div class="scb-card">
                        <div class="scb-card__header">
                            <div style="display:flex;align-items:center;gap:10px;">
                                <span style="font-size:20px;">⏳</span>
                                <span class="scb-card__title">Pending Approvals</span>
                                <span class="scb-badge scb-badge--warning scb-badge--pill" id="scb-pending-counter">Awaiting</span>
                            </div>
                            <a href="?page=scb-bookings&booking_status=pending" class="scb-btn scb-btn--sm scb-btn--warning">Review</a>
                        </div>
                        <div class="scb-card__body" id="scb-pending-approvals">
                            <div class="scb-loading-overlay"><div class="scb-spinner"></div></div>
                        </div>
                    </div>
                </div>
            `;

            document.getElementById('scb-dash-refresh').addEventListener('click', () => this.loadStats());
            await this.loadStats();
            await this.loadRecentBookings();
        },

        _skeletonStats() {
            const labels = ['TOTAL BOOKINGS', 'APPROVED BOOKINGS', 'PENDING APPROVALS', "TODAY'S LESSONS", 'UPCOMING CLINICS', 'WAITLISTED', 'CAMP REGISTRATIONS', 'REVENUE THIS MONTH'];
            return labels.map(l => `
                <div class="scb-stat-card">
                    <div class="scb-stat-card__header">
                        <span class="scb-stat-card__label">${l}</span>
                        <div class="scb-skeleton" style="width:40px;height:40px;border-radius:50%;"></div>
                    </div>
                    <div class="scb-skeleton" style="height:36px;width:55%;margin-bottom:8px;border-radius:6px;"></div>
                    <div class="scb-skeleton" style="height:14px;width:75%;border-radius:4px;"></div>
                </div>
            `).join('');
        },

        async loadStats() {
            try {
                const res = await Api.get('dashboard/stats');
                const data = res.data;

                const cards = [
                    { label: 'TOTAL BOOKINGS', value: data.total_bookings, icon: 'file', color: 'sales', meta: `${data.total_bookings} bookings in system`, emoji: '🎯' },
                    { label: 'APPROVED BOOKINGS', value: data.approved_bookings, icon: 'check', color: 'delivered', meta: `${data.approved_bookings} confirmed & ready`, emoji: '✅' },
                    { label: 'PENDING APPROVALS', value: data.pending_approvals, icon: 'clock', color: 'pending', meta: `${data.pending_approvals} awaiting admin review`, emoji: '⏳' },
                    { label: 'REVENUE THIS MONTH', value: Utils.formatCurrency(data.revenue_this_month), icon: 'credit-card', color: 'revenue', meta: `${data.pending_payments} pending payments`, emoji: '💰' },
                    { label: "TODAY'S LESSONS", value: data.todays_lessons, icon: 'users', color: 'lessons', meta: 'Active coaching sessions today', emoji: '🎾' },
                    { label: 'UPCOMING CLINICS', value: data.upcoming_clinics, icon: 'calendar', color: 'clinics', meta: 'Open clinic slots available', emoji: '📅' },
                    { label: 'WAITLISTED', value: data.waitlisted, icon: 'list', color: 'waitlist', meta: 'Athletes waiting for space', emoji: '📋' },
                    { label: 'CAMP REGISTRATIONS', value: data.camp_registrations, icon: 'home', color: 'camps', meta: 'Junior summer camp students', emoji: '🏕️' },
                ];

                const grid = document.getElementById('scb-stats-grid');
                if (!grid) return;

                grid.innerHTML = cards.map(card => `
                    <div class="scb-stat-card scb-stat-card--${card.color}">
                        <div class="scb-stat-card__header">
                            <span class="scb-stat-card__label">${Utils.escHtml(card.label)}</span>
                            <span class="scb-stat-card__icon">${this._statIcon(card.icon)}</span>
                        </div>
                        <div class="scb-stat-card__value">${Utils.escHtml(String(card.value))}</div>
                        <div class="scb-stat-card__meta">
                            <span class="scb-stat-card__emoji">${card.emoji}</span>
                            ${Utils.escHtml(card.meta)}
                        </div>
                    </div>
                `).join('');

                // Update sidebar badge.
                const badge = document.getElementById('scb-pending-badge');
                if (badge) {
                    badge.textContent = data.pending_approvals;
                    badge.style.display = data.pending_approvals > 0 ? '' : 'none';
                }
            } catch (err) {
                Toast.error('Could not load statistics', err.message);
            }
        },

        async loadRecentBookings() {
            const elRecent = document.getElementById('scb-recent-bookings');
            const elPending = document.getElementById('scb-pending-approvals');

            try {
                // Fetch recent bookings and pending approvals in parallel
                const [recentRes, pendingRes] = await Promise.all([
                    Api.get('bookings', { per_page: 5, orderby: 'created_at', order: 'DESC' }).catch(() => ({ data: { items: [] } })),
                    Api.get('bookings', { booking_status: 'pending', per_page: 5, orderby: 'created_at', order: 'ASC' }).catch(() => ({ data: { items: [] } }))
                ]);

                const bookings = recentRes.data?.items || [];
                const pendingBookings = pendingRes.data?.items || [];

                // 1. Render Recent Bookings
                if (elRecent) {
                    if (!bookings.length) {
                        elRecent.innerHTML = '<div class="scb-empty-state"><div class="scb-empty-state__title">No bookings yet</div></div>';
                    } else {
                        elRecent.innerHTML = bookings.map(b => `
                            <div style="display:flex;align-items:center;justify-content:space-between;padding:10px 0;border-bottom:1px solid var(--scb-border);">
                                <div>
                                    <div style="font-size:13px;font-weight:600;">${Utils.escHtml(b.customer_name)}</div>
                                    <div style="font-size:12px;color:var(--scb-text-muted);">${Utils.escHtml(b.booking_ref)} · ${Utils.formatDate(b.created_at)}</div>
                                </div>
                                <div style="display:flex;align-items:center;gap:8px;">
                                    ${Utils.statusBadge(b.booking_status)}
                                </div>
                            </div>
                        `).join('');
                    }
                }

                // 2. Render Pending Approvals
                if (elPending) {
                    if (!pendingBookings.length) {
                        elPending.innerHTML = '<div class="scb-empty-state"><div class="scb-empty-state__title">No pending approvals</div></div>';
                    } else {
                        elPending.innerHTML = pendingBookings.map(b => `
                            <div style="display:flex;align-items:center;justify-content:space-between;padding:10px 0;border-bottom:1px solid var(--scb-border);">
                                <div>
                                    <div style="font-size:13px;font-weight:600;">${Utils.escHtml(b.customer_name)}</div>
                                    <div style="font-size:12px;color:var(--scb-text-muted);">${Utils.escHtml(b.service_type?.replace(/_/g, ' '))} · ${Utils.formatDate(b.created_at)}</div>
                                </div>
                                <button class="scb-btn scb-btn--sm scb-btn--success" data-action="approve" data-id="${b.id}">Approve</button>
                            </div>
                        `).join('');

                        if (!this._pendingBound) {
                            elPending.addEventListener('click', async (e) => {
                                const btn = e.target.closest('[data-action="approve"]');
                                if (!btn) return;
                                Utils.setLoading(btn, true);
                                try {
                                    await Api.post(`bookings/${btn.dataset.id}/approve`, {});
                                    Toast.success(CONFIG.strings.booking_approved || 'Booking approved successfully.');
                                    await this.loadStats();
                                    await this.loadRecentBookings();
                                } catch (err) {
                                    Toast.error(CONFIG.strings.error || 'Action Failed', err.message);
                                    Utils.setLoading(btn, false);
                                }
                            });
                            this._pendingBound = true;
                        }
                    }
                }

            } catch (err) {
                if (elRecent) elRecent.innerHTML = '<div class="scb-empty-state"><div class="scb-empty-state__title">No bookings yet</div></div>';
                if (elPending) elPending.innerHTML = '<div class="scb-empty-state"><div class="scb-empty-state__title">No pending approvals</div></div>';
                console.error('Could not load dashboard bookings', err);
            }
        },

        _statIcon(name) {
            const icons = {
                file: `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>`,
                clock: `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>`,
                check: `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>`,
                users: `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>`,
                calendar: `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>`,
                list: `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/></svg>`,
                home: `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>`,
                'credit-card': `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="1" y="4" width="22" height="16" rx="2" ry="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg>`,
            };
            return icons[name] || icons.file;
        },
    };

    // ── Bookings View ────────────────────────────────────────────────────────
    const BookingsView = {
        state: { page: 1, perPage: 20, search: '', serviceType: '', bookingStatus: '', paymentStatus: '', orderby: 'created_at', order: 'DESC', total: 0 },

        async render(container) {
            container.innerHTML = `
                <div class="scb-page-header">
                    <div>
                        <h1 class="scb-page-title">All Bookings</h1>
                        <p class="scb-page-subtitle">View and manage all booking records.</p>
                    </div>
                    <div class="scb-page-actions">
                        <button class="scb-btn scb-btn--secondary" id="scb-bookings-export">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                            Export CSV
                        </button>
                    </div>
                </div>

                <div class="scb-toolbar">
                    <div class="scb-search-box">
                        <svg class="scb-search-box__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                        <input type="search" id="scb-bookings-search" placeholder="Search by name, email, ref…" value="${Utils.escHtml(this.state.search)}">
                    </div>
                    <div class="scb-filter-group">
                        <select class="scb-select" id="scb-bookings-service" style="width:auto;">
                            <option value="" ${!this.state.serviceType ? 'selected' : ''}>All Services</option>
                            <option value="clinic" ${this.state.serviceType === 'clinic' ? 'selected' : ''}>Sunday Clinic</option>
                            <option value="private_lesson" ${this.state.serviceType === 'private_lesson' ? 'selected' : ''}>Private Lesson</option>
                            <option value="semi_private_lesson" ${this.state.serviceType === 'semi_private_lesson' ? 'selected' : ''}>Semi-Private Lesson</option>
                        </select>
                        <select class="scb-select" id="scb-bookings-status" style="width:auto;">
                            <option value="">All Statuses</option>
                            <option value="pending">Pending</option>
                            <option value="approved">Approved</option>
                            <option value="rejected">Rejected</option>
                            <option value="rescheduled">Rescheduled</option>
                            <option value="cancelled">Cancelled</option>
                            <option value="waitlisted">Waitlisted</option>
                            <option value="completed">Completed</option>
                        </select>
                        <select class="scb-select" id="scb-bookings-payment" style="width:auto;">
                            <option value="">All Payments</option>
                            <option value="unpaid">Unpaid</option>
                            <option value="pending">Pending</option>
                            <option value="paid">Paid</option>
                            <option value="failed">Failed</option>
                            <option value="refunded">Refunded</option>
                        </select>
                    </div>
                </div>

                <div class="scb-table-wrap">
                    <table class="scb-table" id="scb-bookings-table">
                        <thead>
                            <tr id="scb-bookings-thead-row">
                                ${this._renderThead()}
                            </tr>
                        </thead>
                        <tbody id="scb-bookings-tbody">
                            ${Utils.skeletonRows(9)}
                        </tbody>
                    </table>
                </div>
                <div id="scb-bookings-pagination"></div>
            `;

            this._bindFilters(container);
            ViewUtils.bindSortHeaders(document.getElementById('scb-bookings-table'), (key, nextOrder) => {
                this.state.orderby = key;
                this.state.order = nextOrder;
                this.state.page = 1;
                const theadRow = document.getElementById('scb-bookings-thead-row');
                if (theadRow) theadRow.innerHTML = this._renderThead();
                this.loadBookings();
            });
            ViewUtils.attachPagination(document.getElementById('scb-bookings-pagination'), (p) => {
                this.state.page = p;
                this.loadBookings();
            });
            await this.loadBookings();
        },

        _renderThead() {
            const k = this.state.orderby;
            const o = this.state.order;
            return `
                ${ViewUtils.sortHeader('Reference', 'booking_ref', k, o)}
                ${ViewUtils.sortHeader('Customer', 'customer_name', k, o)}
                ${ViewUtils.sortHeader('Service', 'service_type', k, o)}
                ${ViewUtils.sortHeader('Date/Time', 'booking_date', k, o)}
                ${ViewUtils.sortHeader('Status', 'booking_status', k, o)}
                ${ViewUtils.sortHeader('Payment', 'payment_status', k, o)}
                ${ViewUtils.sortHeader('Amount', 'amount', k, o)}
                ${ViewUtils.sortHeader('Created', 'created_at', k, o)}
                <th>Actions</th>
            `;
        },

        _bindFilters(container) {
            const search = document.getElementById('scb-bookings-search');
            const service = document.getElementById('scb-bookings-service');
            const status = document.getElementById('scb-bookings-status');
            const payment = document.getElementById('scb-bookings-payment');

            const reload = Utils.debounce(() => { this.state.page = 1; this.loadBookings(); }, 300);

            search.addEventListener('input', () => { this.state.search = search.value; reload(); });
            service.addEventListener('change', () => { this.state.serviceType = service.value; reload(); });
            status.addEventListener('change', () => { this.state.bookingStatus = status.value; reload(); });
            payment.addEventListener('change', () => { this.state.paymentStatus = payment.value; reload(); });

            document.getElementById('scb-bookings-export').addEventListener('click', () => this.exportCsv());
        },

        async loadBookings() {
            const tbody = document.getElementById('scb-bookings-tbody');
            if (!tbody) return;
            tbody.innerHTML = Utils.skeletonRows(9);

            try {
                const res = await Api.get('bookings', {
                    page: this.state.page,
                    per_page: this.state.perPage,
                    search: this.state.search,
                    service_type: this.state.serviceType,
                    booking_status: this.state.bookingStatus,
                    payment_status: this.state.paymentStatus,
                    orderby: this.state.orderby,
                    order: this.state.order,
                });

                const items = res.data?.items || [];
                this.state.total = res.data?.total || 0;

                if (!items.length) {
                    tbody.innerHTML = `<tr><td colspan="9"><div class="scb-empty-state">
                        <div class="scb-empty-state__icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg></div>
                        <div class="scb-empty-state__title">No bookings found</div>
                        <div class="scb-empty-state__desc">Try adjusting your search or filters.</div>
                    </div></td></tr>`;
                } else {
                    tbody.innerHTML = items.map(b => this._bookingRow(b)).join('');
                    this._bindRowActions();
                }

                const pagEl = document.getElementById('scb-bookings-pagination');
                if (pagEl) {
                    pagEl.innerHTML = ViewUtils.renderPagination(this.state.page, this.state.total, this.state.perPage, (p) => {
                        this.state.page = p; this.loadBookings();
                    });
                }
            } catch (err) {
                tbody.innerHTML = `<tr><td colspan="9"><div class="scb-empty-state scb-text-error">Error: ${Utils.escHtml(err.message)}</div></td></tr>`;
                Toast.error('Could not load bookings', err.message);
            }
        },

        _bookingRow(b) {
            const serviceLabel = { clinic: 'Clinic', private_lesson: 'Private Lesson', semi_private_lesson: 'Semi-Private' };
            const isClinic = b.service_type === 'clinic' || !!b.clinic_slot_id;
            const bookingDate = isClinic ? (b.clinic_date || b.slot_date) : b.lesson_date;
            const startTime = isClinic ? (b.clinic_start_time || b.start_time) : (b.lesson_start_time || b.start_time);
            const endTime = isClinic ? (b.clinic_end_time || b.end_time) : (b.lesson_end_time || b.end_time);

            let timeStr = '';
            if (startTime) {
                timeStr = Utils.formatTime(startTime);
                if (endTime) {
                    timeStr += ' – ' + Utils.formatTime(endTime);
                }
            }

            const dateDisplay = bookingDate ? Utils.formatDate(bookingDate) : '—';

            return `
                <tr data-booking-id="${b.id}">
                    <td><code style="font-size:11px;">${Utils.escHtml(b.booking_ref)}</code></td>
                    <td>
                        <div style="font-weight:600;">${Utils.escHtml(b.customer_name)}</div>
                        <div style="font-size:12px;color:var(--scb-text-muted);">${Utils.escHtml(b.customer_email)}</div>
                    </td>
                    <td>${Utils.escHtml(serviceLabel[b.service_type] || b.service_type)}</td>
                    <td>
                        <div style="font-weight:500;">${Utils.escHtml(dateDisplay)}</div>
                        ${timeStr ? `<div style="font-size:12px;color:var(--scb-text-muted);">${Utils.escHtml(timeStr)}</div>` : ''}
                    </td>
                    <td>${Utils.statusBadge(b.booking_status)}</td>
                    <td>${Utils.statusBadge(b.payment_status, 'payment')}</td>
                    <td>${Utils.formatCurrency(b.amount)}</td>
                    <td>${Utils.formatDate(b.created_at)}</td>
                    <td>
                        <div class="scb-dropdown">
                            <button class="scb-btn scb-btn--sm scb-btn--secondary scb-dropdown-toggle" data-id="${b.id}">
                                Actions ▾
                            </button>
                            <div class="scb-dropdown__menu" hidden>
                                <button class="scb-dropdown__item" data-action="view" data-id="${b.id}">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                                    View Details
                                </button>
                                ${b.booking_status === 'pending' ? `
                                <button class="scb-dropdown__item" data-action="approve" data-id="${b.id}">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>
                                    Approve
                                </button>
                                <button class="scb-dropdown__item scb-dropdown__item--danger" data-action="reject" data-id="${b.id}">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                                    Reject
                                </button>` : ''}
                                ${['pending', 'approved'].includes(b.booking_status) ? `
                                <button class="scb-dropdown__item" data-action="reschedule" data-id="${b.id}">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="23 4 23 10 17 10"/><polyline points="1 20 1 14 7 14"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/></svg>
                                    Reschedule
                                </button>
                                <div class="scb-dropdown__sep"></div>
                                <button class="scb-dropdown__item scb-dropdown__item--danger" data-action="cancel" data-id="${b.id}">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
                                    Cancel
                                </button>` : ''}
                            </div>
                        </div>
                    </td>
                </tr>
            `;
        },

        _bindRowActions() {
            const tbody = document.getElementById('scb-bookings-tbody');
            if (!tbody) return;

            // Dropdown toggles.
            tbody.querySelectorAll('.scb-dropdown-toggle').forEach(btn => {
                btn.addEventListener('click', (e) => {
                    e.stopPropagation();
                    const menu = btn.nextElementSibling;
                    const isOpen = !menu.hidden;
                    // Close all open menus.
                    document.querySelectorAll('.scb-dropdown__menu').forEach(m => m.hidden = true);
                    if (!isOpen) menu.hidden = false;
                });
            });

            // Close dropdowns on outside click.
            if (!this._dropdownDocBound) {
                document.addEventListener('click', (e) => {
                    if (!e.target.closest('.scb-dropdown')) {
                        document.querySelectorAll('.scb-dropdown__menu').forEach(m => m.hidden = true);
                    }
                });
                this._dropdownDocBound = true;
            }

            // Action handlers.
            tbody.addEventListener('click', (e) => {
                const item = e.target.closest('[data-action]');
                if (!item) return;
                const { action, id } = item.dataset;
                this._handleAction(action, id);
            });
        },

        async _handleAction(action, id) {
            switch (action) {
                case 'view': return this._viewBooking(id);
                case 'approve': return this._approveBooking(id);
                case 'reject': return this._rejectBooking(id);
                case 'cancel': return this._cancelBooking(id);
                case 'reschedule': return this._rescheduleBooking(id);
            }
        },

        async _viewBooking(id) {
            Modal.open({ title: 'Booking Details', body: '<div class="scb-loading-overlay"><div class="scb-spinner"></div></div>', size: 'lg' });
            try {
                const res = await Api.get(`bookings/${id}`);
                const b = res.data;
                const isClinic = b.service_type === 'clinic' || !!b.clinic_slot_id;
                const bookingDate = isClinic ? (b.clinic_date || b.slot_date) : b.lesson_date;
                const startTime = isClinic ? (b.clinic_start_time || b.start_time) : (b.lesson_start_time || b.start_time);
                const endTime = isClinic ? (b.clinic_end_time || b.end_time) : (b.lesson_end_time || b.end_time);

                let timeStr = '';
                if (startTime) {
                    timeStr = Utils.formatTime(startTime);
                    if (endTime) {
                        timeStr += ' – ' + Utils.formatTime(endTime);
                    }
                }
                const dateDisplay = bookingDate ? Utils.formatDate(bookingDate) : '—';
                const dateTimeFull = timeStr ? `${dateDisplay} (${timeStr})` : dateDisplay;

                Modal.body.innerHTML = `
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
                        <div><label class="scb-label">Reference</label><div style="font-weight:600;">${Utils.escHtml(b.booking_ref)}</div></div>
                        <div><label class="scb-label">Service</label><div>${Utils.escHtml(b.service_type?.replace(/_/g, ' '))}</div></div>
                        <div><label class="scb-label">Customer Name</label><div>${Utils.escHtml(b.customer_name)}</div></div>
                        <div><label class="scb-label">Customer Email</label><div>${Utils.escHtml(b.customer_email)}</div></div>
                        <div><label class="scb-label">Date & Time</label><div style="font-weight:600;">${Utils.escHtml(dateTimeFull)}</div></div>
                        <div><label class="scb-label">Amount</label><div style="font-weight:700;">${Utils.formatCurrency(b.amount)}</div></div>
                        <div><label class="scb-label">Booking Status</label><div>${Utils.statusBadge(b.booking_status)}</div></div>
                        <div><label class="scb-label">Payment Status</label><div>${Utils.statusBadge(b.payment_status, 'payment')}</div></div>
                        <div><label class="scb-label">Created</label><div>${Utils.formatDate(b.created_at, true)}</div></div>
                        ${b.coach_first_name ? `<div><label class="scb-label">Coach</label><div>${Utils.escHtml(b.coach_first_name + ' ' + (b.coach_last_name || ''))}</div></div>` : ''}
                        ${b.clinic_category ? `<div><label class="scb-label">Category</label><div style="text-transform:capitalize;">${Utils.escHtml(b.clinic_category)}</div></div>` : ''}
                        ${b.notes ? `<div style="grid-column:span 2;"><label class="scb-label">Notes</label><div>${Utils.escHtml(b.notes)}</div></div>` : ''}
                        ${b.admin_notes ? `<div style="grid-column:span 2;"><label class="scb-label">Admin Notes</label><div>${Utils.escHtml(b.admin_notes)}</div></div>` : ''}
                    </div>
                `;
                Modal.footer.innerHTML = `
                    <button class="scb-btn scb-btn--secondary" id="scb-modal-close-btn" data-modal-close>Close</button>
                    ${b.booking_status === 'pending' ? `
                        <button class="scb-btn scb-btn--danger" id="scb-modal-reject-btn">Reject</button>
                        <button class="scb-btn scb-btn--success" id="scb-modal-approve-btn">Approve</button>
                    ` : ''}
                `;
                document.getElementById('scb-modal-close-btn')?.addEventListener('click', () => Modal.close());
                document.getElementById('scb-modal-approve-btn')?.addEventListener('click', () => {
                    Modal.close();
                    this._approveBooking(id);
                });
                document.getElementById('scb-modal-reject-btn')?.addEventListener('click', () => {
                    Modal.close();
                    this._rejectBooking(id);
                });
            } catch (err) {
                Modal.body.innerHTML = `<div class="scb-alert scb-alert--error">Could not load booking: ${Utils.escHtml(err.message)}</div>`;
            }
        },

        async _approveBooking(id) {
            const confirmed = await Modal.confirm({
                title: 'Approve Booking',
                message: 'Approve this booking? The customer will be notified.',
                confirmText: 'Approve Booking',
                confirmClass: 'scb-btn--success',
            });
            if (!confirmed) return;

            try {
                const res = await Api.post(`bookings/${id}/approve`, {});
                Toast.success(res?.message || CONFIG.strings.booking_approved || 'Booking approved successfully.');
                this.loadBookings();
            } catch (err) {
                Toast.error(CONFIG.strings.error || 'Action Failed', err.message);
            }
        },

        async _rejectBooking(id) {
            const confirmed = await Modal.confirm({
                title: 'Reject Booking',
                message: 'Reject this booking? The customer will be notified.',
                body: `<div class="scb-form-group scb-mt-4">
                    <label class="scb-label">Reason (sent to customer)</label>
                    <textarea class="scb-textarea" id="scb-reject-reason" placeholder="Optional reason…" rows="3"></textarea>
                </div>`,
                confirmText: 'Reject Booking',
                confirmClass: 'scb-btn--danger',
            });
            if (!confirmed) return;

            try {
                const reason = document.getElementById('scb-reject-reason')?.value || '';
                const res = await Api.post(`bookings/${id}/reject`, { reason });
                Toast.success(res?.message || CONFIG.strings.booking_rejected || 'Booking rejected.');
                this.loadBookings();
            } catch (err) {
                Toast.error(CONFIG.strings.error || 'Action Failed', err.message);
            }
        },

        async _cancelBooking(id) {
            const confirmed = await Modal.confirm({
                title: 'Cancel Booking',
                message: 'Cancel this booking? This action will release the reserved capacity.',
                body: `<div class="scb-form-group scb-mt-4">
                    <label class="scb-label">Cancellation Reason</label>
                    <textarea class="scb-textarea" id="scb-cancel-reason" placeholder="Reason for cancellation…" rows="3"></textarea>
                </div>`,
                confirmText: 'Cancel Booking',
                confirmClass: 'scb-btn--danger',
            });
            if (!confirmed) return;

            try {
                const reason = document.getElementById('scb-cancel-reason')?.value || '';
                const res = await Api.post(`bookings/${id}/cancel`, { reason });
                Toast.success(res?.message || CONFIG.strings.booking_cancelled || 'Booking cancelled successfully.');
                this.loadBookings();
            } catch (err) {
                Toast.error(CONFIG.strings.error || 'Action Failed', err.message);
            }
        },

        async _rescheduleBooking(id) {
            Modal.open({
                title: 'Reschedule Booking',
                content: '<div class="scb-loading-overlay"><div class="scb-spinner"></div></div>',
                footer: '<button class="scb-btn scb-btn--secondary" data-modal-close>Cancel</button>'
            });

            try {
                const res = await Api.get(`bookings/${id}`);
                const b = res.data;

                let specificFormFields = '';

                if (b.service_type === 'clinic') {
                    const slotsRes = await Api.get('clinic-slots', { per_page: 50 });
                    const slots = (slotsRes.data?.items || []).filter(s => parseInt(s.id, 10) !== parseInt(b.clinic_slot_id, 10));

                    const curSlotDate = b.clinic_date || b.slot_date;
                    const curStart = b.clinic_start_time || b.start_time;
                    const curEnd = b.clinic_end_time || b.end_time;
                    const curTimeStr = curStart ? `${Utils.formatTime(curStart)}${curEnd ? ' – ' + Utils.formatTime(curEnd) : ''}` : '';

                    specificFormFields = `
                        <div class="scb-form-group">
                            <label class="scb-form-label">Current Slot</label>
                            <div style="font-weight:600;font-size:14px;color:var(--scb-text-2);margin-bottom:8px;">
                                ${Utils.formatDate(curSlotDate)} ${curTimeStr ? `(${curTimeStr})` : ''}
                            </div>
                        </div>
                        <div class="scb-form-group">
                            <label class="scb-form-label">Select New Clinic Slot *</label>
                            <select class="scb-select" id="scb-reschedule-new-slot" required>
                                <option value="">-- Choose target clinic slot --</option>
                                ${slots.map(s => `
                                    <option value="${s.id}" ${s.status === 'full' ? 'disabled' : ''}>
                                        ${Utils.formatDate(s.slot_date)} (${Utils.formatTime(s.start_time)} – ${Utils.formatTime(s.end_time)}) — ${s.category === 'adult' ? 'Adult' : 'Junior'} [${s.booked_count}/${s.capacity}] ${s.status === 'full' ? '(FULL)' : ''}
                                    </option>
                                `).join('')}
                            </select>
                        </div>
                    `;
                } else {
                    const todayStr = new Date().toISOString().split('T')[0];
                    const curStart = b.lesson_start_time || b.start_time;
                    specificFormFields = `
                        <div class="scb-form-group">
                            <label class="scb-form-label">Current Schedule</label>
                            <div style="font-weight:600;font-size:14px;color:var(--scb-text-2);margin-bottom:8px;">
                                ${Utils.formatDate(b.lesson_date)} at ${curStart ? Utils.formatTime(curStart) : '—'}
                            </div>
                        </div>
                        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
                            <div class="scb-form-group">
                                <label class="scb-form-label">New Date *</label>
                                <input type="date" class="scb-input" id="scb-reschedule-new-date" min="${todayStr}" value="${b.lesson_date || ''}" required>
                            </div>
                            <div class="scb-form-group">
                                <label class="scb-form-label">New Start Time *</label>
                                <input type="time" class="scb-input" id="scb-reschedule-new-time" value="${curStart?.substring(0, 5) || '10:00'}" required>
                            </div>
                        </div>
                    `;
                }

                Modal.body.innerHTML = `
                    <div style="font-size:14px;color:var(--scb-text-muted);margin-bottom:14px;">
                        Rescheduling booking for <strong>${Utils.escHtml(b.customer_name)}</strong> (${Utils.escHtml(b.booking_ref)}).
                    </div>
                    ${specificFormFields}
                    <div class="scb-form-group" style="margin-top:12px;">
                        <label class="scb-form-label">Reschedule Reason (Sent to Customer)</label>
                        <textarea class="scb-textarea" id="scb-reschedule-reason" rows="2" placeholder="e.g. Schedule adjustment or coach request…"></textarea>
                    </div>
                `;

                Modal.footer.innerHTML = `
                    <button class="scb-btn scb-btn--secondary" data-modal-close>Cancel</button>
                    <button class="scb-btn scb-btn--primary" id="scb-submit-reschedule-btn">Confirm Reschedule</button>
                `;

                document.getElementById('scb-submit-reschedule-btn')?.addEventListener('click', async () => {
                    const submitBtn = document.getElementById('scb-submit-reschedule-btn');
                    const reason = document.getElementById('scb-reschedule-reason')?.value || '';
                    const payload = { reason };

                    if (b.service_type === 'clinic') {
                        const newSlotId = document.getElementById('scb-reschedule-new-slot')?.value;
                        if (!newSlotId) {
                            Toast.error('Validation Error', 'Please select a new clinic slot.');
                            return;
                        }
                        payload.new_slot_id = newSlotId;
                    } else {
                        const newDate = document.getElementById('scb-reschedule-new-date')?.value;
                        const newTime = document.getElementById('scb-reschedule-new-time')?.value;
                        if (!newDate || !newTime) {
                            Toast.error('Validation Error', 'Please select a new date and time.');
                            return;
                        }
                        payload.new_date = newDate;
                        payload.new_start_time = newTime;
                    }

                    if (submitBtn) {
                        submitBtn.disabled = true;
                        submitBtn.textContent = 'Rescheduling…';
                    }

                    try {
                        const res = await Api.post(`bookings/${id}/reschedule`, payload);
                        Modal.close();
                        Toast.success(res?.message || CONFIG.strings.booking_rescheduled || 'Booking rescheduled successfully.');
                        this.loadBookings();
                    } catch (err) {
                        if (submitBtn) {
                            submitBtn.disabled = false;
                            submitBtn.textContent = 'Confirm Reschedule';
                        }
                        Toast.error('Could not reschedule', err.message);
                    }
                });

            } catch (err) {
                Modal.body.innerHTML = `<div class="scb-alert scb-alert--error">Could not load booking details: ${Utils.escHtml(err.message)}</div>`;
            }
        },

        exportCsv() {
            const params = Utils.buildQuery({
                booking_status: this.state.bookingStatus,
                service_type: this.state.serviceType,
                search: this.state.search,
            });
            const url = CONFIG.restUrl + 'reports/export?' + params + '&type=bookings&_wpnonce=' + CONFIG.restNonce;
            window.open(url, '_blank');
            Toast.info(CONFIG.strings.export_started);
        },
    };

    // ── Sunday Clinics View ──────────────────────────────────────────────────
    const ClinicsView = {
        state: { dateFrom: '', dateTo: '', category: '', status: '', orderby: 'slot_date', order: 'DESC' },

        async render(container) {
            container.innerHTML = `
                <div class="scb-page-header">
                    <div>
                        <h1 class="scb-page-title">Sunday Clinics</h1>
                        <p class="scb-page-subtitle">Manage weekly Sunday clinic time slots, capacity, and slot generation.</p>
                    </div>
                    <div class="scb-page-actions">
                        <button class="scb-btn scb-btn--secondary" id="scb-clinics-generate-btn">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="23 4 23 10 17 10"/><polyline points="1 20 1 14 7 14"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/></svg>
                            Generate Weekly Slots
                        </button>
                        <button class="scb-btn scb-btn--primary" id="scb-clinics-add-btn">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                            Add Clinic Slot
                        </button>
                    </div>
                </div>

                <div class="scb-toolbar">
                    <div class="scb-filter-group">
                        <select class="scb-select" id="scb-clinics-category" style="width:auto;">
                            <option value="">All Categories</option>
                            <option value="junior">Junior Clinic</option>
                            <option value="adult">Adult Clinic</option>
                        </select>
                        <select class="scb-select" id="scb-clinics-status" style="width:auto;">
                            <option value="">All Statuses</option>
                            <option value="open">Open</option>
                            <option value="full">Full</option>
                            <option value="closed">Closed</option>
                            <option value="cancelled">Cancelled</option>
                        </select>
                    </div>
                </div>

                <div class="scb-table-wrap">
                    <table class="scb-table" id="scb-clinics-table">
                        <thead>
                            <tr id="scb-clinics-thead-row">
                                ${this._renderThead()}
                            </tr>
                        </thead>
                        <tbody id="scb-clinics-tbody">
                            ${Utils.skeletonRows(8)}
                        </tbody>
                    </table>
                </div>
            `;

            document.getElementById('scb-clinics-generate-btn').addEventListener('click', () => this.openGenerateModal());
            document.getElementById('scb-clinics-add-btn').addEventListener('click', () => this.openAddModal());
            document.getElementById('scb-clinics-category').addEventListener('change', (e) => { this.state.category = e.target.value; this.loadSlots(); });
            document.getElementById('scb-clinics-status').addEventListener('change', (e) => { this.state.status = e.target.value; this.loadSlots(); });

            ViewUtils.bindSortHeaders(document.getElementById('scb-clinics-table'), (key, nextOrder) => {
                this.state.orderby = key;
                this.state.order = nextOrder;
                const theadRow = document.getElementById('scb-clinics-thead-row');
                if (theadRow) theadRow.innerHTML = this._renderThead();
                this.loadSlots();
            });

            await this.loadSlots();
        },

        _renderThead() {
            const k = this.state.orderby;
            const o = this.state.order;
            return `
                ${ViewUtils.sortHeader('Date', 'slot_date', k, o)}
                ${ViewUtils.sortHeader('Category', 'category', k, o)}
                ${ViewUtils.sortHeader('Time', 'start_time', k, o)}
                ${ViewUtils.sortHeader('Capacity / Booked', 'booked_count', k, o)}
                ${ViewUtils.sortHeader('Waitlist', 'waitlist_count', k, o)}
                ${ViewUtils.sortHeader('Price', 'price', k, o)}
                ${ViewUtils.sortHeader('Status', 'status', k, o)}
                <th>Actions</th>
            `;
        },

        async loadSlots() {
            const tbody = document.getElementById('scb-clinics-tbody');
            if (!tbody) return;

            try {
                const res = await Api.get('clinic-slots', {
                    category: this.state.category,
                    status: this.state.status,
                    orderby: this.state.orderby,
                    order: this.state.order,
                });
                const slots = res.data?.items || [];

                if (!slots.length) {
                    tbody.innerHTML = `<tr><td colspan="8"><div class="scb-empty-state"><div class="scb-empty-state__title">No clinic slots found</div><div class="scb-empty-state__desc">Click "Generate Weekly Slots" above to generate slots for upcoming Sundays.</div></div></td></tr>`;
                    return;
                }

                tbody.innerHTML = slots.map(s => {
                    const booked = parseInt(s.booked_count, 10) || 0;
                    const cap = parseInt(s.capacity, 10) || 15;
                    const pct = Math.min(100, Math.round((booked / cap) * 100));
                    const isFull = booked >= cap || s.status === 'full';

                    return `
                        <tr>
                            <td><strong>${Utils.formatDate(s.slot_date)}</strong></td>
                            <td><span class="scb-badge scb-badge--${s.category === 'adult' ? 'accent' : 'info'}">${s.category === 'adult' ? 'Adult' : 'Junior'}</span></td>
                            <td>${Utils.escHtml(s.start_time.substring(0, 5))} - ${Utils.escHtml(s.end_time.substring(0, 5))}</td>
                            <td>
                                <div style="display:flex;align-items:center;gap:8px;">
                                    <span>${booked} / ${cap}</span>
                                    <div style="flex:1;max-width:80px;height:6px;background:var(--scb-border);border-radius:3px;overflow:hidden;">
                                        <div style="width:${pct}%;height:100%;background:${isFull ? 'var(--scb-error)' : 'var(--scb-primary)'};"></div>
                                    </div>
                                </div>
                            </td>
                            <td>${s.waitlist_count > 0 ? `<span class="scb-badge scb-badge--warning">${s.waitlist_count} waiting</span>` : '<span style="color:var(--scb-text-muted);">0</span>'}</td>
                            <td>${Utils.formatCurrency(s.price)}</td>
                            <td>${Utils.statusBadge(s.status, 'clinic')}</td>
                            <td>
                                <div style="display:flex;gap:4px;">
                                    <button class="scb-btn scb-btn--sm scb-btn--secondary scb-edit-slot-btn" data-id="${s.id}" data-cap="${cap}" data-price="${s.price}" data-status="${s.status}" data-notes="${Utils.escHtml(s.notes || '')}">Edit</button>
                                    <button class="scb-btn scb-btn--sm scb-btn--danger scb-delete-slot-btn" data-id="${s.id}" data-date="${s.slot_date}">Delete</button>
                                </div>
                            </td>
                        </tr>
                    `;
                }).join('');

                // Bind action buttons.
                tbody.querySelectorAll('.scb-edit-slot-btn').forEach(btn => {
                    btn.addEventListener('click', () => {
                        this.openEditModal(btn.dataset.id, btn.dataset.cap, btn.dataset.price, btn.dataset.status, btn.dataset.notes);
                    });
                });

                tbody.querySelectorAll('.scb-delete-slot-btn').forEach(btn => {
                    btn.addEventListener('click', async () => {
                        const slotId = btn.dataset.id;
                        const slotDate = btn.dataset.date;
                        const confirmed = await Modal.confirm({
                            title: 'Delete Clinic Slot',
                            message: `Are you sure you want to delete the slot on ${slotDate}? This cannot be undone.`,
                            confirmText: 'Delete Slot',
                            confirmClass: 'scb-btn--danger',
                        });
                        if (!confirmed) return;

                        try {
                            const res = await Api.delete(`clinic-slots/${slotId}`);
                            Toast.success(res?.message || 'Clinic slot deleted successfully.');
                            this.loadSlots();
                        } catch (err) {
                            Toast.error('Could not delete slot', err.message);
                        }
                    });
                });

            } catch (err) {
                Toast.error('Could not load clinic slots', err.message);
            }
        },

        openGenerateModal() {
            Modal.open({
                title: 'Generate Weekly Sunday Slots',
                content: `
                    <p style="margin-bottom:16px;color:var(--scb-text-muted);font-size:16px;line-height:1.6;">This will automatically generate standard Junior and Adult clinic sessions for upcoming Sundays.</p>
                    <div class="scb-form-group">
                        <label class="scb-form-label" for="scb-gen-weeks">Number of Upcoming Sundays</label>
                        <select class="scb-select" id="scb-gen-weeks">
                            <option value="1" selected>Next Sunday (1 week)</option>
                            <option value="2">Next 2 Sundays</option>
                            <option value="4">Next 4 Sundays</option>
                            <option value="8">Next 8 Sundays</option>
                        </select>
                    </div>
                `,
                footer: `
                    <button class="scb-btn scb-btn--secondary" data-modal-close>Cancel</button>
                    <button class="scb-btn scb-btn--primary" id="scb-submit-gen-btn">Generate Now</button>
                `
            });

            document.getElementById('scb-submit-gen-btn')?.addEventListener('click', async () => {
                const btn = document.getElementById('scb-submit-gen-btn');
                if (btn) {
                    btn.disabled = true;
                    btn.textContent = 'Generating…';
                }
                const weeks = parseInt(document.getElementById('scb-gen-weeks').value, 10) || 1;
                try {
                    const res = await Api.post('clinic-slots/generate', { weeks });
                    Modal.close();
                    Toast.success(res.message || 'Clinic slots generated successfully.');
                    this.loadSlots();
                } catch (err) {
                    if (btn) {
                        btn.disabled = false;
                        btn.textContent = 'Generate Now';
                    }
                    Toast.error('Generation failed', err.message);
                }
            });
        },

        openAddModal() {
            Modal.open({
                title: 'Add Custom Clinic Slot',
                content: `
                    <div class="scb-form-group">
                        <label class="scb-form-label" for="scb-new-slot-date">Date *</label>
                        <input type="date" class="scb-input" id="scb-new-slot-date" required>
                    </div>
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
                        <div class="scb-form-group">
                            <label class="scb-form-label" for="scb-new-slot-start">Start Time *</label>
                            <input type="time" class="scb-input" id="scb-new-slot-start" value="09:00" required>
                        </div>
                        <div class="scb-form-group">
                            <label class="scb-form-label" for="scb-new-slot-end">End Time *</label>
                            <input type="time" class="scb-input" id="scb-new-slot-end" value="10:00" required>
                        </div>
                    </div>
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
                        <div class="scb-form-group">
                            <label class="scb-form-label" for="scb-new-slot-cat">Category *</label>
                            <select class="scb-select" id="scb-new-slot-cat">
                                <option value="junior">Junior Clinic</option>
                                <option value="adult">Adult Clinic</option>
                            </select>
                        </div>
                        <div class="scb-form-group">
                            <label class="scb-form-label" for="scb-new-slot-cap">Capacity *</label>
                            <input type="number" class="scb-input" id="scb-new-slot-cap" value="15" min="1" max="50">
                        </div>
                    </div>
                    <div class="scb-form-group">
                        <label class="scb-form-label" for="scb-new-slot-price">Price ($)</label>
                        <input type="number" step="0.5" class="scb-input" id="scb-new-slot-price" value="25.00">
                    </div>
                `,
                footer: `
                    <button class="scb-btn scb-btn--secondary" data-modal-close>Cancel</button>
                    <button class="scb-btn scb-btn--primary" id="scb-submit-new-slot">Create Slot</button>
                `
            });

            document.getElementById('scb-submit-new-slot')?.addEventListener('click', async () => {
                const date = document.getElementById('scb-new-slot-date')?.value;
                const start = document.getElementById('scb-new-slot-start')?.value;
                const end = document.getElementById('scb-new-slot-end')?.value;
                const cat = document.getElementById('scb-new-slot-cat')?.value;
                const cap = document.getElementById('scb-new-slot-cap')?.value;
                const price = document.getElementById('scb-new-slot-price')?.value;

                if (!date || !start) {
                    Toast.error('Required Field Missing', 'Please specify a valid date and start time.');
                    return;
                }

                const submitBtn = document.getElementById('scb-submit-new-slot');
                if (submitBtn) {
                    submitBtn.disabled = true;
                    submitBtn.textContent = 'Creating…';
                }

                try {
                    const res = await Api.post('clinic-slots', {
                        slot_date: date,
                        start_time: start,
                        end_time: end,
                        category: cat,
                        capacity: cap,
                        price: price,
                    });
                    Modal.close();
                    Toast.success(res?.message || 'Clinic slot created successfully.');
                    this.loadSlots();
                } catch (err) {
                    if (submitBtn) {
                        submitBtn.disabled = false;
                        submitBtn.textContent = 'Create Slot';
                    }
                    Toast.error('Could not create slot', err.message);
                }
            });
        },

        openEditModal(id, cap, price, status, notes) {
            Modal.open({
                title: 'Edit Clinic Slot',
                content: `
                    <div class="scb-form-group">
                        <label class="scb-form-label" for="scb-edit-cap">Capacity</label>
                        <input type="number" class="scb-input" id="scb-edit-cap" value="${cap}" min="1">
                    </div>
                    <div class="scb-form-group">
                        <label class="scb-form-label" for="scb-edit-price">Price ($)</label>
                        <input type="number" step="0.5" class="scb-input" id="scb-edit-price" value="${price}">
                    </div>
                    <div class="scb-form-group">
                        <label class="scb-form-label" for="scb-edit-status">Status</label>
                        <select class="scb-select" id="scb-edit-status">
                            <option value="open" ${status === 'open' ? 'selected' : ''}>Open</option>
                            <option value="full" ${status === 'full' ? 'selected' : ''}>Full</option>
                            <option value="closed" ${status === 'closed' ? 'selected' : ''}>Closed</option>
                            <option value="cancelled" ${status === 'cancelled' ? 'selected' : ''}>Cancelled</option>
                        </select>
                    </div>
                    <div class="scb-form-group">
                        <label class="scb-form-label" for="scb-edit-notes">Notes</label>
                        <textarea class="scb-textarea" id="scb-edit-notes">${notes || ''}</textarea>
                    </div>
                `,
                footer: `
                    <button class="scb-btn scb-btn--secondary" data-modal-close>Cancel</button>
                    <button class="scb-btn scb-btn--primary" id="scb-submit-edit-slot">Save Changes</button>
                `
            });

            document.getElementById('scb-submit-edit-slot')?.addEventListener('click', async () => {
                const submitBtn = document.getElementById('scb-submit-edit-slot');
                if (submitBtn) {
                    submitBtn.disabled = true;
                    submitBtn.textContent = 'Saving…';
                }

                const newCap = document.getElementById('scb-edit-cap')?.value || 15;
                const newPrice = document.getElementById('scb-edit-price')?.value || 25;
                const newStatus = document.getElementById('scb-edit-status')?.value || 'open';
                const newNotes = document.getElementById('scb-edit-notes')?.value || '';

                try {
                    const res = await Api.post(`clinic-slots/${id}`, {
                        capacity: newCap,
                        price: newPrice,
                        status: newStatus,
                        notes: newNotes,
                    });
                    Modal.close();
                    Toast.success(res?.message || 'Clinic slot updated successfully.');
                    this.loadSlots();
                } catch (err) {
                    if (submitBtn) {
                        submitBtn.disabled = false;
                        submitBtn.textContent = 'Save Changes';
                    }
                    Toast.error('Could not update slot', err.message);
                }
            });
        }
    };

    // ── Private Lessons View ──────────────────────────────────────────────────
    const PrivateLessonsView = {
        async render(container) {
            BookingsView.state.serviceType = 'lessons';
            await BookingsView.render(container);
            const title = container.querySelector('.scb-page-title');
            if (title) title.textContent = 'Private & Semi-Private Lessons';
            const sub = container.querySelector('.scb-page-subtitle');
            if (sub) sub.textContent = 'Manage one-on-one and semi-private lessons booked with coaches.';
            const svcSelect = container.querySelector('#scb-bookings-service');
            if (svcSelect) {
                svcSelect.innerHTML = `
                    <option value="lessons" ${BookingsView.state.serviceType === 'lessons' ? 'selected' : ''}>All Lessons (Private & Semi-Private)</option>
                    <option value="private_lesson" ${BookingsView.state.serviceType === 'private_lesson' ? 'selected' : ''}>Private Lessons (1-on-1)</option>
                    <option value="semi_private_lesson" ${BookingsView.state.serviceType === 'semi_private_lesson' ? 'selected' : ''}>Semi-Private Lessons (2-on-1)</option>
                `;
            }
        }
    };

    // ── Coaches View ──────────────────────────────────────────────────────────
    const CoachesView = {
        state: { viewMode: 'cards', orderby: 'display_order', order: 'ASC' },

        async render(container) {
            container.innerHTML = `
                <div class="scb-page-header">
                    <div>
                        <h1 class="scb-page-title">Coaches & Instructors</h1>
                        <p class="scb-page-subtitle">Manage coach profiles, specialties, and weekly lesson schedules.</p>
                    </div>
                    <div class="scb-page-actions">
                        <button class="scb-btn scb-btn--secondary" id="scb-seed-coaches-btn">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:15px;height:15px;"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                            Generate Dummy Data
                        </button>
                        <button class="scb-btn scb-btn--primary" id="scb-add-coach-btn">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                            Add New Coach
                        </button>
                    </div>
                </div>

                <div class="scb-toolbar">
                    <div class="scb-filter-group" style="align-items:center;">
                        <label class="scb-label" style="margin-bottom:0;margin-right:4px;">Sort by:</label>
                        <select class="scb-select" id="scb-coaches-sort" style="width:auto;">
                            <option value="display_order" ${this.state.orderby === 'display_order' ? 'selected' : ''}>Display Order</option>
                            <option value="last_name" ${this.state.orderby === 'last_name' ? 'selected' : ''}>Coach Name (A-Z)</option>
                            <option value="email" ${this.state.orderby === 'email' ? 'selected' : ''}>Email Address</option>
                            <option value="hourly_rate" ${this.state.orderby === 'hourly_rate' ? 'selected' : ''}>Hourly Rate</option>
                            <option value="total_bookings_count" ${this.state.orderby === 'total_bookings_count' ? 'selected' : ''}>Total Lessons</option>
                            <option value="status" ${this.state.orderby === 'status' ? 'selected' : ''}>Status</option>
                        </select>
                        <button class="scb-btn scb-btn--secondary" id="scb-coaches-order-btn" title="Toggle Sort Direction">
                            ${this.state.order === 'ASC' ? '↑ Ascending' : '↓ Descending'}
                        </button>
                    </div>
                    <div style="margin-left:auto;display:flex;gap:4px;">
                        <button class="scb-btn scb-btn--sm ${this.state.viewMode === 'cards' ? 'scb-btn--primary' : 'scb-btn--secondary'}" id="scb-coaches-view-cards" title="Cards View">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:14px;height:14px;"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>
                            Cards
                        </button>
                        <button class="scb-btn scb-btn--sm ${this.state.viewMode === 'table' ? 'scb-btn--primary' : 'scb-btn--secondary'}" id="scb-coaches-view-table" title="Table View">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:14px;height:14px;"><line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/></svg>
                            Table
                        </button>
                    </div>
                </div>

                <div id="scb-coaches-container">
                    <div class="scb-loading-overlay"><div class="scb-spinner"></div></div>
                </div>
            `;

            document.getElementById('scb-seed-coaches-btn')?.addEventListener('click', () => this.openSeedModal());
            document.getElementById('scb-add-coach-btn')?.addEventListener('click', () => this.openAddModal());

            const sortSelect = document.getElementById('scb-coaches-sort');
            const orderBtn = document.getElementById('scb-coaches-order-btn');
            const btnCards = document.getElementById('scb-coaches-view-cards');
            const btnTable = document.getElementById('scb-coaches-view-table');

            sortSelect?.addEventListener('change', () => {
                this.state.orderby = sortSelect.value;
                this.loadCoaches();
            });

            orderBtn?.addEventListener('click', () => {
                this.state.order = this.state.order === 'ASC' ? 'DESC' : 'ASC';
                orderBtn.innerHTML = this.state.order === 'ASC' ? '↑ Ascending' : '↓ Descending';
                this.loadCoaches();
            });

            btnCards?.addEventListener('click', () => {
                this.state.viewMode = 'cards';
                btnCards.className = 'scb-btn scb-btn--sm scb-btn--primary';
                btnTable.className = 'scb-btn scb-btn--sm scb-btn--secondary';
                this.loadCoaches();
            });

            btnTable?.addEventListener('click', () => {
                this.state.viewMode = 'table';
                btnTable.className = 'scb-btn scb-btn--sm scb-btn--primary';
                btnCards.className = 'scb-btn scb-btn--sm scb-btn--secondary';
                this.loadCoaches();
            });

            await this.loadCoaches();
        },

        _renderThead() {
            const k = this.state.orderby;
            const o = this.state.order;
            return `
                ${ViewUtils.sortHeader('Coach', 'last_name', k, o)}
                ${ViewUtils.sortHeader('Email', 'email', k, o)}
                ${ViewUtils.sortHeader('Hourly Rate', 'hourly_rate', k, o)}
                ${ViewUtils.sortHeader('Total Lessons', 'total_bookings_count', k, o)}
                ${ViewUtils.sortHeader('Status', 'status', k, o)}
                ${ViewUtils.sortHeader('Order', 'display_order', k, o)}
                <th>Actions</th>
            `;
        },

        async loadCoaches() {
            const container = document.getElementById('scb-coaches-container');
            if (!container) return;

            // Sync sort controls
            const sortSelect = document.getElementById('scb-coaches-sort');
            if (sortSelect && sortSelect.value !== this.state.orderby) sortSelect.value = this.state.orderby;
            const orderBtn = document.getElementById('scb-coaches-order-btn');
            if (orderBtn) orderBtn.innerHTML = this.state.order === 'ASC' ? '↑ Ascending' : '↓ Descending';

            try {
                const res = await Api.get('coaches', {
                    orderby: this.state.orderby,
                    order: this.state.order,
                });
                const coaches = res.data?.items || [];

                if (!coaches.length) {
                    container.innerHTML = `
                        <div class="scb-empty-state">
                            <div class="scb-empty-state__icon">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                            </div>
                            <div class="scb-empty-state__title">No coaches found</div>
                            <div class="scb-empty-state__desc" style="margin-bottom:16px;">Add your first coach profile or generate demo dummy coaches with complete schedules.</div>
                            <div style="display:flex;gap:10px;justify-content:center;">
                                <button class="scb-btn scb-btn--secondary" id="scb-coaches-empty-seed-btn">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:14px;height:14px;"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                                    Generate Dummy Data
                                </button>
                                <button class="scb-btn scb-btn--primary" id="scb-coaches-empty-add-btn">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:14px;height:14px;"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                                    Add New Coach
                                </button>
                            </div>
                        </div>
                    `;
                    document.getElementById('scb-coaches-empty-seed-btn')?.addEventListener('click', () => this.openSeedModal());
                    document.getElementById('scb-coaches-empty-add-btn')?.addEventListener('click', () => this.openAddModal());
                    return;
                }

                if (this.state.viewMode === 'table') {
                    container.innerHTML = `
                        <div class="scb-table-wrap">
                            <table class="scb-table" id="scb-coaches-table">
                                <thead>
                                    <tr id="scb-coaches-thead-row">
                                        ${this._renderThead()}
                                    </tr>
                                </thead>
                                <tbody>
                                    ${coaches.map(c => `
                                        <tr>
                                            <td>
                                                <div style="display:flex;align-items:center;gap:10px;">
                                                    <img src="${Utils.escHtml(c.photo_url || CONFIG.pluginUrl + 'assets/admin/img/avatar.png')}" alt="${Utils.escHtml(c.first_name)}" style="width:36px;height:36px;border-radius:50%;object-fit:cover;border:1px solid var(--scb-border);">
                                                    <div>
                                                        <strong>${Utils.escHtml(c.first_name)} ${Utils.escHtml(c.last_name)}</strong>
                                                        <div style="font-size:11px;color:var(--scb-text-muted);">${Utils.escHtml(c.specialties || 'All levels')}</div>
                                                    </div>
                                                </div>
                                            </td>
                                            <td>${Utils.escHtml(c.email || '—')}</td>
                                            <td><strong>${Utils.formatCurrency(c.hourly_rate || 60)}/hr</strong></td>
                                            <td><span class="scb-badge scb-badge--info">${c.total_bookings_count || 0} lessons</span></td>
                                            <td>${Utils.statusBadge(c.status, 'coach')}</td>
                                            <td><span class="scb-badge scb-badge--muted">#${c.display_order || 0}</span></td>
                                            <td>
                                                <div class="scb-table__actions">
                                                    <button class="scb-btn scb-btn--sm scb-btn--secondary scb-coach-avail-btn" data-id="${c.id}" data-name="${Utils.escHtml(c.first_name + ' ' + c.last_name)}">Schedule</button>
                                                    <button class="scb-btn scb-btn--sm scb-btn--secondary scb-coach-edit-btn" data-id="${c.id}">Edit</button>
                                                    <button class="scb-btn scb-btn--sm scb-btn--danger scb-coach-del-btn" data-id="${c.id}" data-name="${Utils.escHtml(c.first_name)}">Delete</button>
                                                </div>
                                            </td>
                                        </tr>
                                    `).join('')}
                                </tbody>
                            </table>
                        </div>
                    `;

                    ViewUtils.bindSortHeaders(document.getElementById('scb-coaches-table'), (key, nextOrder) => {
                        this.state.orderby = key;
                        this.state.order = nextOrder;
                        this.loadCoaches();
                    });

                } else {
                    container.innerHTML = `
                        <div id="scb-coaches-grid" style="display:grid;grid-template-columns:repeat(auto-fill, minmax(320px, 1fr));gap:20px;">
                            ${coaches.map(c => `
                                <div class="scb-card" style="display:flex;flex-direction:column;justify-content:space-between;">
                                    <div class="scb-card__body">
                                        <div style="display:flex;align-items:center;gap:14px;margin-bottom:14px;">
                                            <img src="${Utils.escHtml(c.photo_url || CONFIG.pluginUrl + 'assets/admin/img/avatar.png')}" alt="${Utils.escHtml(c.first_name)}" style="width:56px;height:56px;border-radius:50%;object-fit:cover;border:2px solid var(--scb-border);">
                                            <div>
                                                <h3 style="margin:0;font-size:16px;font-weight:700;">${Utils.escHtml(c.first_name)} ${Utils.escHtml(c.last_name)}</h3>
                                                <div style="font-size:12px;color:var(--scb-text-muted);">${Utils.escHtml(c.email || '')}</div>
                                                <div style="margin-top:4px;">${Utils.statusBadge(c.status, 'coach')}</div>
                                            </div>
                                        </div>
                                        <p style="font-size:13px;color:var(--scb-text);line-height:1.5;margin-bottom:12px;display:-webkit-box;-webkit-line-clamp:3;-webkit-box-orient:vertical;overflow:hidden;">${Utils.escHtml(c.bio || 'No bio provided.')}</p>
                                        <div style="font-size:12px;color:var(--scb-text-muted);margin-bottom:6px;"><strong>Specialties:</strong> ${Utils.escHtml(c.specialties || 'All levels')}</div>
                                        <div style="font-size:12px;color:var(--scb-text-muted);margin-bottom:6px;"><strong>Rate:</strong> ${Utils.formatCurrency(c.hourly_rate || 60)}/hr</div>
                                        <div style="font-size:12px;color:var(--scb-text-muted);"><strong>Lessons:</strong> ${c.total_bookings_count || 0} completed</div>
                                    </div>
                                    <div class="scb-card__footer" style="display:flex;gap:8px;border-top:1px solid var(--scb-border);padding:12px 16px;background:var(--scb-surface-subtle);">
                                        <button class="scb-btn scb-btn--sm scb-btn--secondary scb-coach-avail-btn" data-id="${c.id}" data-name="${Utils.escHtml(c.first_name + ' ' + c.last_name)}">Schedule</button>
                                        <button class="scb-btn scb-btn--sm scb-btn--secondary scb-coach-edit-btn" data-id="${c.id}">Edit</button>
                                        <button class="scb-btn scb-btn--sm scb-btn--danger scb-coach-del-btn" data-id="${c.id}" data-name="${Utils.escHtml(c.first_name)}">Delete</button>
                                    </div>
                                </div>
                            `).join('')}
                        </div>
                    `;
                }

                container.querySelectorAll('.scb-coach-edit-btn').forEach(btn => {
                    btn.addEventListener('click', () => this.openEditModal(btn.dataset.id));
                });

                container.querySelectorAll('.scb-coach-avail-btn').forEach(btn => {
                    btn.addEventListener('click', () => this.openAvailabilityModal(btn.dataset.id, btn.dataset.name));
                });

                container.querySelectorAll('.scb-coach-del-btn').forEach(btn => {
                    btn.addEventListener('click', async () => {
                        const coachId = btn.dataset.id;
                        const coachName = btn.dataset.name;
                        const confirmed = await Modal.confirm({
                            title: 'Delete Coach',
                            message: `Are you sure you want to delete coach ${coachName}?`,
                            confirmText: 'Delete Coach',
                            confirmClass: 'scb-btn--danger',
                        });
                        if (!confirmed) return;

                        try {
                            const res = await Api.delete(`coaches/${coachId}`);
                            Toast.success(res?.message || 'Coach deleted successfully.');
                            this.loadCoaches();
                        } catch (err) {
                            Toast.error('Could not delete coach', err.message);
                        }
                    });
                });

            } catch (err) {
                Toast.error('Could not load coaches', err.message);
            }
        },

        openSeedModal() {
            Modal.open({
                title: 'Generate Dummy Coaches',
                content: `
                    <p style="margin-bottom:14px;color:var(--scb-text-muted);font-size:14px;line-height:1.6;">
                        This will generate 3 professional demo coach profiles with photos, bios, specialties, and standard Saturday lesson schedules.
                    </p>
                    <p style="margin-bottom:0;color:var(--scb-text-muted);font-size:13px;">
                        <em>Note: If any sample coaches already exist, they will be safely skipped to prevent duplication.</em>
                    </p>
                `,
                footer: `
                    <button class="scb-btn scb-btn--secondary" data-modal-close>Cancel</button>
                    <button class="scb-btn scb-btn--primary" id="scb-submit-seed-coaches-btn">Generate Now</button>
                `
            });

            document.getElementById('scb-submit-seed-coaches-btn')?.addEventListener('click', async () => {
                const btn = document.getElementById('scb-submit-seed-coaches-btn');
                if (btn) {
                    btn.disabled = true;
                    btn.textContent = 'Generating…';
                }
                try {
                    const res = await Api.post('coaches/seed');
                    Modal.close();
                    Toast.success(res.message || 'Dummy coaches generated successfully.');
                    await this.loadCoaches();
                } catch (err) {
                    if (btn) {
                        btn.disabled = false;
                        btn.textContent = 'Generate Now';
                    }
                    Toast.error('Could not generate dummy coaches', err.message);
                }
            });
        },

        openAddModal() {
            Modal.open({
                title: 'Add New Coach Profile',
                content: `
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
                        <div class="scb-form-group">
                            <label class="scb-form-label">First Name *</label>
                            <input type="text" class="scb-input" id="scb-c-fname" required>
                        </div>
                        <div class="scb-form-group">
                            <label class="scb-form-label">Last Name *</label>
                            <input type="text" class="scb-input" id="scb-c-lname" required>
                        </div>
                    </div>
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
                        <div class="scb-form-group">
                            <label class="scb-form-label">Email Address</label>
                            <input type="email" class="scb-input" id="scb-c-email">
                        </div>
                        <div class="scb-form-group">
                            <label class="scb-form-label">Phone Number</label>
                            <input type="text" class="scb-input" id="scb-c-phone">
                        </div>
                    </div>
                    <div class="scb-form-group">
                        <label class="scb-form-label">Photo URL</label>
                        <input type="url" class="scb-input" id="scb-c-photo" placeholder="https://...">
                    </div>
                    <div class="scb-form-group">
                        <label class="scb-form-label">Specialties</label>
                        <input type="text" class="scb-input" id="scb-c-spec" placeholder="e.g. Deceptive Drops, Footwork">
                    </div>
                    <div class="scb-form-group">
                        <label class="scb-form-label">Biography</label>
                        <textarea class="scb-textarea" id="scb-c-bio" rows="3"></textarea>
                    </div>
                `,
                footer: `
                    <button class="scb-btn scb-btn--secondary" data-modal-close>Cancel</button>
                    <button class="scb-btn scb-btn--primary" id="scb-submit-add-coach">Create Coach</button>
                `
            });

            document.getElementById('scb-submit-add-coach')?.addEventListener('click', async () => {
                const fname = document.getElementById('scb-c-fname')?.value.trim();
                const lname = document.getElementById('scb-c-lname')?.value.trim();
                if (!fname || !lname) {
                    Toast.error('Validation Error', 'First and last name are required.');
                    return;
                }

                const submitBtn = document.getElementById('scb-submit-add-coach');
                if (submitBtn) {
                    submitBtn.disabled = true;
                    submitBtn.textContent = 'Creating…';
                }

                try {
                    const res = await Api.post('coaches', {
                        first_name: fname,
                        last_name: lname,
                        email: document.getElementById('scb-c-email')?.value,
                        phone: document.getElementById('scb-c-phone')?.value,
                        photo_url: document.getElementById('scb-c-photo')?.value,
                        specialties: document.getElementById('scb-c-spec')?.value,
                        bio: document.getElementById('scb-c-bio')?.value,
                    });
                    Modal.close();
                    Toast.success(res?.message || 'Coach profile created successfully.');
                    this.loadCoaches();
                } catch (err) {
                    if (submitBtn) {
                        submitBtn.disabled = false;
                        submitBtn.textContent = 'Create Coach';
                    }
                    Toast.error('Could not create coach', err.message);
                }
            });
        },

        async openEditModal(id) {
            try {
                const res = await Api.get(`coaches/${id}`);
                const c = res.data;

                Modal.open({
                    title: `Edit Coach — ${Utils.escHtml(c.first_name)} ${Utils.escHtml(c.last_name)}`,
                    content: `
                        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
                            <div class="scb-form-group">
                                <label class="scb-form-label">First Name</label>
                                <input type="text" class="scb-input" id="scb-ec-fname" value="${Utils.escHtml(c.first_name)}">
                            </div>
                            <div class="scb-form-group">
                                <label class="scb-form-label">Last Name</label>
                                <input type="text" class="scb-input" id="scb-ec-lname" value="${Utils.escHtml(c.last_name)}">
                            </div>
                        </div>
                        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
                            <div class="scb-form-group">
                                <label class="scb-form-label">Email</label>
                                <input type="email" class="scb-input" id="scb-ec-email" value="${Utils.escHtml(c.email || '')}">
                            </div>
                            <div class="scb-form-group">
                                <label class="scb-form-label">Status</label>
                                <select class="scb-select" id="scb-ec-status">
                                    <option value="active" ${c.status === 'active' ? 'selected' : ''}>Active</option>
                                    <option value="inactive" ${c.status === 'inactive' ? 'selected' : ''}>Inactive</option>
                                    <option value="on_leave" ${c.status === 'on_leave' ? 'selected' : ''}>On Leave</option>
                                </select>
                            </div>
                        </div>
                        <div class="scb-form-group">
                            <label class="scb-form-label">Photo URL</label>
                            <input type="url" class="scb-input" id="scb-ec-photo" value="${Utils.escHtml(c.photo_url || '')}">
                        </div>
                        <div class="scb-form-group">
                            <label class="scb-form-label">Specialties</label>
                            <input type="text" class="scb-input" id="scb-ec-spec" value="${Utils.escHtml(c.specialties || '')}">
                        </div>
                        <div class="scb-form-group">
                            <label class="scb-form-label">Biography</label>
                            <textarea class="scb-textarea" id="scb-ec-bio" rows="3">${Utils.escHtml(c.bio || '')}</textarea>
                        </div>
                    `,
                    footer: `
                        <button class="scb-btn scb-btn--secondary" data-modal-close>Cancel</button>
                        <button class="scb-btn scb-btn--primary" id="scb-submit-edit-coach">Save Changes</button>
                    `
                });

                document.getElementById('scb-submit-edit-coach')?.addEventListener('click', async () => {
                    const submitBtn = document.getElementById('scb-submit-edit-coach');
                    if (submitBtn) {
                        submitBtn.disabled = true;
                        submitBtn.textContent = 'Saving…';
                    }

                    try {
                        const res = await Api.post(`coaches/${id}`, {
                            first_name: document.getElementById('scb-ec-fname')?.value,
                            last_name: document.getElementById('scb-ec-lname')?.value,
                            email: document.getElementById('scb-ec-email')?.value,
                            status: document.getElementById('scb-ec-status')?.value,
                            photo_url: document.getElementById('scb-ec-photo')?.value,
                            specialties: document.getElementById('scb-ec-spec')?.value,
                            bio: document.getElementById('scb-ec-bio')?.value,
                        });
                        Modal.close();
                        Toast.success(res?.message || 'Coach updated successfully.');
                        this.loadCoaches();
                    } catch (err) {
                        if (submitBtn) {
                            submitBtn.disabled = false;
                            submitBtn.textContent = 'Save Changes';
                        }
                        Toast.error('Could not update coach', err.message);
                    }
                });

            } catch (err) {
                Toast.error('Could not load coach details', err.message);
            }
        },

        async openAvailabilityModal(id, name) {
            try {
                const res = await Api.get(`coaches/${id}/availability`);
                const blocks = res.data?.items || [];

                Modal.open({
                    title: `Weekly Lesson Schedule — ${name}`,
                    content: `
                        <p style="margin-bottom:14px;color:var(--scb-text-muted);font-size:13px;">Configure working hours when this coach is available for private/semi-private lessons.</p>
                        <div class="scb-form-group">
                            <label class="scb-form-label">Saturday Working Hours</label>
                            <div style="display:flex;gap:12px;align-items:center;">
                                <input type="time" class="scb-input" id="scb-av-start" value="09:00">
                                <span>to</span>
                                <input type="time" class="scb-input" id="scb-av-end" value="17:00">
                            </div>
                        </div>
                        <div class="scb-form-group">
                            <label class="scb-form-label">Lesson Types Permitted</label>
                            <select class="scb-select" id="scb-av-type">
                                <option value="both">Private & Semi-Private</option>
                                <option value="private">Private Only</option>
                                <option value="semi_private">Semi-Private Only</option>
                            </select>
                        </div>
                    `,
                    footer: `
                        <button class="scb-btn scb-btn--secondary" onclick="document.getElementById('scb-modal-overlay').classList.remove('is-open')">Cancel</button>
                        <button class="scb-btn scb-btn--primary" id="scb-save-avail-btn">Save Schedule</button>
                    `
                });

                document.getElementById('scb-save-avail-btn').addEventListener('click', async () => {
                    const start = document.getElementById('scb-av-start').value;
                    const end = document.getElementById('scb-av-end').value;
                    const type = document.getElementById('scb-av-type').value;
                    Modal.close();
                    try {
                        await Api.post(`coaches/${id}/availability`, {
                            blocks: [
                                { day_of_week: 6, start_time: start, end_time: end, lesson_type: type, is_blocked: 0 }
                            ]
                        });
                        Toast.success('Coach availability saved.');
                    } catch (err) {
                        Toast.error('Could not save availability', err.message);
                    }
                });

            } catch (err) {
                Toast.error('Could not load availability', err.message);
            }
        }
    };

    // ── Schedule & Calendar View ──────────────────────────────────────────────
    const ScheduleView = {
        state: { clinicOrder: 'ASC', lessonSort: 'booking_date', lessonOrder: 'ASC' },

        async render(container) {
            container.innerHTML = `
                <div class="scb-page-header">
                    <div>
                        <h1 class="scb-page-title">Schedule & Calendar</h1>
                        <p class="scb-page-subtitle">Visual overview of upcoming weekend clinic sessions and booked lessons.</p>
                    </div>
                </div>

                <div class="scb-card" style="margin-bottom:24px;">
                    <div class="scb-card__header" style="display:flex;justify-content:space-between;align-items:center;">
                        <span class="scb-card__title">Upcoming Weekend Schedule</span>
                        <div style="font-size:12px;color:var(--scb-text-muted);">Interactive sorted overview</div>
                    </div>
                    <div class="scb-card__body" id="scb-schedule-grid">
                        <div class="scb-loading-overlay"><div class="scb-spinner"></div></div>
                    </div>
                </div>
            `;

            await this.loadSchedule();
        },

        async loadSchedule() {
            const grid = document.getElementById('scb-schedule-grid');
            if (!grid) return;

            try {
                const slotsRes = await Api.get('clinic-slots', {
                    orderby: 'slot_date',
                    order: this.state.clinicOrder,
                });
                const slots = slotsRes.data?.items || [];

                const bookingsRes = await Api.get('bookings', {
                    orderby: this.state.lessonSort,
                    order: this.state.lessonOrder,
                    per_page: 50,
                });
                const bookings = bookingsRes.data?.items || [];

                grid.innerHTML = `
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;">
                        <div style="background:var(--scb-surface-subtle);border:1px solid var(--scb-border);border-radius:8px;padding:16px;">
                            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;">
                                <h3 style="margin:0;font-size:15px;color:var(--scb-primary);display:flex;align-items:center;gap:6px;">
                                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                                    Sunday Clinics
                                </h3>
                                <button class="scb-btn scb-btn--sm scb-btn--secondary" id="scb-sched-clinic-order-btn" title="Toggle chronological order">
                                    ${this.state.clinicOrder === 'ASC' ? 'Date ↑ (Earliest)' : 'Date ↓ (Latest)'}
                                </button>
                            </div>
                            ${slots.slice(0, 8).map(s => `
                                <div style="background:#fff;border:1px solid var(--scb-border);border-radius:6px;padding:10px;margin-bottom:8px;">
                                    <div style="display:flex;justify-content:space-between;font-weight:600;font-size:13px;">
                                        <span>${Utils.formatDate(s.slot_date)}</span>
                                        <span>${s.start_time.substring(0, 5)} - ${s.end_time.substring(0, 5)}</span>
                                    </div>
                                    <div style="display:flex;justify-content:space-between;align-items:center;margin-top:6px;font-size:12px;">
                                        <span class="scb-badge scb-badge--info">${s.category === 'adult' ? 'Adult Clinic' : 'Junior Clinic'}</span>
                                        <span style="color:var(--scb-text-muted);">${s.booked_count} / ${s.capacity} spots</span>
                                    </div>
                                </div>
                            `).join('') || '<p style="color:var(--scb-text-muted);">No clinic slots scheduled.</p>'}
                        </div>

                        <div style="background:var(--scb-surface-subtle);border:1px solid var(--scb-border);border-radius:8px;padding:16px;">
                            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;">
                                <h3 style="margin:0;font-size:15px;color:var(--scb-accent);display:flex;align-items:center;gap:6px;">
                                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/></svg>
                                    Private & Semi-Private Lessons
                                </h3>
                                <div style="display:flex;gap:4px;">
                                    <select class="scb-select" id="scb-sched-lesson-sort" style="width:auto;padding:4px 8px;font-size:11px;">
                                        <option value="booking_date" ${this.state.lessonSort === 'booking_date' ? 'selected' : ''}>By Date</option>
                                        <option value="customer_name" ${this.state.lessonSort === 'customer_name' ? 'selected' : ''}>By Customer</option>
                                        <option value="booking_status" ${this.state.lessonSort === 'booking_status' ? 'selected' : ''}>By Status</option>
                                    </select>
                                    <button class="scb-btn scb-btn--sm scb-btn--secondary" id="scb-sched-lesson-order-btn" title="Toggle Sort Direction">
                                        ${this.state.lessonOrder === 'ASC' ? '↑' : '↓'}
                                    </button>
                                </div>
                            </div>
                            ${bookings.filter(b => b.service_type !== 'clinic').slice(0, 8).map(b => `
                                <div style="background:#fff;border:1px solid var(--scb-border);border-radius:6px;padding:10px;margin-bottom:8px;">
                                    <div style="display:flex;justify-content:space-between;font-weight:600;font-size:13px;">
                                        <span>${Utils.escHtml(b.customer_name)}</span>
                                        <span>${Utils.statusBadge(b.booking_status)}</span>
                                    </div>
                                    <div style="display:flex;justify-content:space-between;margin-top:6px;font-size:12px;color:var(--scb-text-muted);">
                                        <span>${Utils.formatDate(b.lesson_date || b.created_at)}</span>
                                        <span>${b.lesson_start_time ? Utils.formatTime(b.lesson_start_time) : 'Scheduled'}</span>
                                    </div>
                                </div>
                            `).join('') || '<p style="color:var(--scb-text-muted);">No private lesson bookings yet.</p>'}
                        </div>
                    </div>
                `;

                document.getElementById('scb-sched-clinic-order-btn')?.addEventListener('click', () => {
                    this.state.clinicOrder = this.state.clinicOrder === 'ASC' ? 'DESC' : 'ASC';
                    this.loadSchedule();
                });

                document.getElementById('scb-sched-lesson-sort')?.addEventListener('change', (e) => {
                    this.state.lessonSort = e.target.value;
                    this.loadSchedule();
                });

                document.getElementById('scb-sched-lesson-order-btn')?.addEventListener('click', () => {
                    this.state.lessonOrder = this.state.lessonOrder === 'ASC' ? 'DESC' : 'ASC';
                    this.loadSchedule();
                });

            } catch (err) {
                grid.innerHTML = '<p style="color:var(--scb-error);">Failed to load schedule.</p>';
            }
        }
    };

    const WaitlistView = {
        state: { status: '', orderby: 'position', order: 'ASC' },

        async render(container) {
            container.innerHTML = `
                <div class="scb-page-header">
                    <div>
                        <h1 class="scb-page-title">Waitlist</h1>
                        <p class="scb-page-subtitle">Review waitlisted customers and promote them when spots become available.</p>
                    </div>
                </div>

                <div class="scb-toolbar">
                    <div class="scb-filter-group">
                        <select class="scb-select" id="scb-waitlist-status" style="width:auto;">
                            <option value="">All Statuses</option>
                            <option value="waiting" ${this.state.status === 'waiting' ? 'selected' : ''}>Waiting</option>
                            <option value="promoted" ${this.state.status === 'promoted' ? 'selected' : ''}>Promoted</option>
                            <option value="expired" ${this.state.status === 'expired' ? 'selected' : ''}>Expired</option>
                        </select>
                    </div>
                </div>

                <div class="scb-table-wrap">
                    <table class="scb-table" id="scb-waitlist-table">
                        <thead>
                            <tr id="scb-waitlist-thead-row">
                                ${this._renderThead()}
                            </tr>
                        </thead>
                        <tbody id="scb-waitlist-tbody">
                            ${Utils.skeletonRows(7)}
                        </tbody>
                    </table>
                </div>
            `;

            document.getElementById('scb-waitlist-status')?.addEventListener('change', (e) => {
                this.state.status = e.target.value;
                this.loadWaitlist();
            });

            ViewUtils.bindSortHeaders(document.getElementById('scb-waitlist-table'), (key, nextOrder) => {
                this.state.orderby = key;
                this.state.order = nextOrder;
                const theadRow = document.getElementById('scb-waitlist-thead-row');
                if (theadRow) theadRow.innerHTML = this._renderThead();
                this.loadWaitlist();
            });

            await this.loadWaitlist();
        },

        _renderThead() {
            const k = this.state.orderby;
            const o = this.state.order;
            return `
                ${ViewUtils.sortHeader('Position', 'position', k, o)}
                ${ViewUtils.sortHeader('Customer', 'customer_name', k, o)}
                ${ViewUtils.sortHeader('Service', 'service_type', k, o)}
                ${ViewUtils.sortHeader('Session / Date', 'slot_date', k, o)}
                ${ViewUtils.sortHeader('Status', 'status', k, o)}
                ${ViewUtils.sortHeader('Date Joined', 'created_at', k, o)}
                <th>Actions</th>
            `;
        },

        async loadWaitlist() {
            const tbody = document.getElementById('scb-waitlist-tbody');
            if (!tbody) return;

            try {
                const res = await Api.get('waitlist', {
                    status: this.state.status,
                    orderby: this.state.orderby,
                    order: this.state.order,
                });
                const items = res.data?.items || [];

                if (!items.length) {
                    tbody.innerHTML = '<tr><td colspan="7"><div class="scb-empty-state"><div class="scb-empty-state__title">No active waitlist entries</div><div class="scb-empty-state__desc">Customers will automatically appear here when joining full clinic slots or lesson requests.</div></div></td></tr>';
                    return;
                }

                tbody.innerHTML = items.map(w => `
                    <tr>
                        <td><span class="scb-badge scb-badge--warning">#${w.position}</span></td>
                        <td>
                            <strong>${Utils.escHtml(w.customer_name)}</strong>
                            <div style="font-size:12px;color:var(--scb-text-muted);">${Utils.escHtml(w.customer_email)} · ${Utils.escHtml(w.customer_phone || '')}</div>
                        </td>
                        <td><span class="scb-badge scb-badge--info">${w.service_type === 'clinic' ? 'Sunday Clinic' : 'Private Lesson'}</span></td>
                        <td>${w.slot_date ? Utils.formatDate(w.slot_date) + ' (' + (w.slot_category || '') + ')' : (w.coach_first_name ? 'Coach ' + w.coach_first_name : '—')}</td>
                        <td>${Utils.statusBadge(w.status, 'waitlist')}</td>
                        <td>${Utils.formatDate(w.created_at)}</td>
                        <td>
                            ${w.status === 'waiting' ? `
                                <div style="display:flex;gap:6px;">
                                    <button class="scb-btn scb-btn--sm scb-btn--success scb-promote-btn" data-id="${w.id}" data-name="${Utils.escHtml(w.customer_name)}">Promote</button>
                                    <button class="scb-btn scb-btn--sm scb-btn--danger scb-remove-waitlist-btn" data-id="${w.id}" data-name="${Utils.escHtml(w.customer_name)}">Remove</button>
                                </div>
                            ` : '—'}
                        </td>
                    </tr>
                `).join('');

                tbody.querySelectorAll('.scb-promote-btn').forEach(btn => {
                    btn.addEventListener('click', async () => {
                        const entryId = btn.dataset.id;
                        const customerName = btn.dataset.name;
                        const confirmed = await Modal.confirm({
                            title: 'Promote From Waitlist',
                            message: `Promote ${customerName} into an active spot? They will receive an automated email confirmation.`,
                            confirmText: 'Promote Now',
                            confirmClass: 'scb-btn--success',
                        });
                        if (!confirmed) return;

                        try {
                            const res = await Api.post(`waitlist/${entryId}/promote`);
                            Toast.success(res.message || 'Waitlist entry promoted successfully.');
                            this.loadWaitlist();
                        } catch (err) {
                            Toast.error('Could not promote', err.message);
                        }
                    });
                });

                tbody.querySelectorAll('.scb-remove-waitlist-btn').forEach(btn => {
                    btn.addEventListener('click', async () => {
                        const entryId = btn.dataset.id;
                        const customerName = btn.dataset.name;
                        const confirmed = await Modal.confirm({
                            title: 'Remove From Waitlist',
                            message: `Are you sure you want to remove ${customerName} from the waitlist?`,
                            confirmText: 'Remove Entry',
                            confirmClass: 'scb-btn--danger',
                        });
                        if (!confirmed) return;

                        try {
                            const res = await Api.delete(`waitlist/${entryId}`);
                            Toast.success(res.message || 'Waitlist entry removed successfully.');
                            this.loadWaitlist();
                        } catch (err) {
                            Toast.error('Could not remove waitlist entry', err.message);
                        }
                    });
                });

            } catch (err) {
                Toast.error('Could not load waitlist', err.message);
            }
        }
    };

    // ── Summer Camps View ─────────────────────────────────────────────────────
    const CampsView = {
        state: { viewMode: 'cards', orderby: 'start_date', order: 'ASC' },

        async render(container) {
            container.innerHTML = `
                <div class="scb-page-header">
                    <div>
                        <h1 class="scb-page-title">Summer Camps</h1>
                        <p class="scb-page-subtitle">Create and manage holiday squash camps and junior academies.</p>
                    </div>
                    <div class="scb-page-actions">
                        <button class="scb-btn scb-btn--secondary" id="scb-seed-camps-btn">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:15px;height:15px;"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/></svg>
                            Generate Dummy Data
                        </button>
                        <button class="scb-btn scb-btn--primary" id="scb-create-camp-btn">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                            Create Summer Camp
                        </button>
                    </div>
                </div>

                <div class="scb-toolbar">
                    <div class="scb-filter-group" style="align-items:center;">
                        <label class="scb-label" style="margin-bottom:0;margin-right:4px;">Sort by:</label>
                        <select class="scb-select" id="scb-camps-sort" style="width:auto;">
                            <option value="start_date" ${this.state.orderby === 'start_date' ? 'selected' : ''}>Start Date</option>
                            <option value="title" ${this.state.orderby === 'title' ? 'selected' : ''}>Camp Title (A-Z)</option>
                            <option value="price" ${this.state.orderby === 'price' ? 'selected' : ''}>Price</option>
                            <option value="registered_count" ${this.state.orderby === 'registered_count' ? 'selected' : ''}>Registrations</option>
                            <option value="capacity" ${this.state.orderby === 'capacity' ? 'selected' : ''}>Capacity</option>
                            <option value="publication_status" ${this.state.orderby === 'publication_status' ? 'selected' : ''}>Status</option>
                        </select>
                        <button class="scb-btn scb-btn--secondary" id="scb-camps-order-btn" title="Toggle Sort Direction">
                            ${this.state.order === 'ASC' ? '↑ Ascending' : '↓ Descending'}
                        </button>
                    </div>
                    <div style="margin-left:auto;display:flex;gap:4px;">
                        <button class="scb-btn scb-btn--sm ${this.state.viewMode === 'cards' ? 'scb-btn--primary' : 'scb-btn--secondary'}" id="scb-camps-view-cards" title="Cards View">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:14px;height:14px;"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>
                            Cards
                        </button>
                        <button class="scb-btn scb-btn--sm ${this.state.viewMode === 'table' ? 'scb-btn--primary' : 'scb-btn--secondary'}" id="scb-camps-view-table" title="Table View">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:14px;height:14px;"><line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/></svg>
                            Table
                        </button>
                    </div>
                </div>

                <div id="scb-camps-container">
                    <div class="scb-loading-overlay"><div class="scb-spinner"></div></div>
                </div>
            `;

            document.getElementById('scb-seed-camps-btn')?.addEventListener('click', () => this.openSeedModal());
            document.getElementById('scb-create-camp-btn').addEventListener('click', () => this.openCreateModal());

            const sortSelect = document.getElementById('scb-camps-sort');
            const orderBtn = document.getElementById('scb-camps-order-btn');
            const btnCards = document.getElementById('scb-camps-view-cards');
            const btnTable = document.getElementById('scb-camps-view-table');

            sortSelect?.addEventListener('change', () => {
                this.state.orderby = sortSelect.value;
                this.loadCamps();
            });

            orderBtn?.addEventListener('click', () => {
                this.state.order = this.state.order === 'ASC' ? 'DESC' : 'ASC';
                orderBtn.innerHTML = this.state.order === 'ASC' ? '↑ Ascending' : '↓ Descending';
                this.loadCamps();
            });

            btnCards?.addEventListener('click', () => {
                this.state.viewMode = 'cards';
                btnCards.className = 'scb-btn scb-btn--sm scb-btn--primary';
                btnTable.className = 'scb-btn scb-btn--sm scb-btn--secondary';
                this.loadCamps();
            });

            btnTable?.addEventListener('click', () => {
                this.state.viewMode = 'table';
                btnTable.className = 'scb-btn scb-btn--sm scb-btn--primary';
                btnCards.className = 'scb-btn scb-btn--sm scb-btn--secondary';
                this.loadCamps();
            });

            await this.loadCamps();
        },

        _renderThead() {
            const k = this.state.orderby;
            const o = this.state.order;
            return `
                ${ViewUtils.sortHeader('Camp Title', 'title', k, o)}
                ${ViewUtils.sortHeader('Start Date', 'start_date', k, o)}
                ${ViewUtils.sortHeader('End Date', 'end_date', k, o)}
                ${ViewUtils.sortHeader('Ages', 'min_age', k, o)}
                ${ViewUtils.sortHeader('Price', 'price', k, o)}
                ${ViewUtils.sortHeader('Capacity', 'capacity', k, o)}
                ${ViewUtils.sortHeader('Registrations', 'registered_count', k, o)}
                ${ViewUtils.sortHeader('Status', 'publication_status', k, o)}
                <th>Actions</th>
            `;
        },

        async loadCamps() {
            const container = document.getElementById('scb-camps-container');
            if (!container) return;

            // Sync sort controls
            const sortSelect = document.getElementById('scb-camps-sort');
            if (sortSelect && sortSelect.value !== this.state.orderby) sortSelect.value = this.state.orderby;
            const orderBtn = document.getElementById('scb-camps-order-btn');
            if (orderBtn) orderBtn.innerHTML = this.state.order === 'ASC' ? '↑ Ascending' : '↓ Descending';

            try {
                const res = await Api.get('camps', {
                    orderby: this.state.orderby,
                    order: this.state.order,
                });
                const camps = res.data?.items || [];

                if (!camps.length) {
                    container.innerHTML = `
                        <div class="scb-empty-state">
                            <div class="scb-empty-state__icon">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" style="width:48px;height:48px;"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/></svg>
                            </div>
                            <div class="scb-empty-state__title">No summer camps found</div>
                            <div class="scb-empty-state__desc" style="margin-bottom:16px;">Create your first summer camp or import demo summer camps.</div>
                            <div style="display:flex;gap:10px;justify-content:center;">
                                <button class="scb-btn scb-btn--secondary" id="scb-empty-seed-camps-btn">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:14px;height:14px;"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/></svg>
                                    Generate Dummy Data
                                </button>
                                <button class="scb-btn scb-btn--primary" id="scb-empty-create-camp-btn">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:14px;height:14px;"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                                    Create Summer Camp
                                </button>
                            </div>
                        </div>
                    `;
                    document.getElementById('scb-empty-seed-camps-btn')?.addEventListener('click', () => this.openSeedModal());
                    document.getElementById('scb-empty-create-camp-btn')?.addEventListener('click', () => this.openCreateModal());
                    return;
                }

                if (this.state.viewMode === 'table') {
                    container.innerHTML = `
                        <div class="scb-table-wrap">
                            <table class="scb-table" id="scb-camps-table">
                                <thead>
                                    <tr id="scb-camps-thead-row">
                                        ${this._renderThead()}
                                    </tr>
                                </thead>
                                <tbody>
                                    ${camps.map(c => {
                        const regCount = parseInt(c.registered_count, 10) || 0;
                        const cap = parseInt(c.capacity, 10) || 20;
                        return `
                                            <tr>
                                                <td><strong>${Utils.escHtml(c.title)}</strong></td>
                                                <td>${Utils.formatDate(c.start_date)}</td>
                                                <td>${Utils.formatDate(c.end_date)}</td>
                                                <td>${c.min_age || 6}–${c.max_age || 18} yrs</td>
                                                <td><strong>${Utils.formatCurrency(c.price)}</strong></td>
                                                <td>${cap} spots</td>
                                                <td><span class="scb-badge scb-badge--info">${regCount} registered</span></td>
                                                <td><span class="scb-badge scb-badge--${c.publication_status === 'published' ? 'success' : 'warning'}">${c.publication_status}</span></td>
                                                <td>
                                                    <div class="scb-table__actions">
                                                        <button class="scb-btn scb-btn--sm scb-btn--secondary scb-edit-camp-btn" data-id="${c.id}">Edit</button>
                                                        <button class="scb-btn scb-btn--sm scb-btn--danger scb-delete-camp-btn" data-id="${c.id}" data-title="${Utils.escHtml(c.title)}">Delete</button>
                                                    </div>
                                                </td>
                                            </tr>
                                        `;
                    }).join('')}
                                </tbody>
                            </table>
                        </div>
                    `;

                    ViewUtils.bindSortHeaders(document.getElementById('scb-camps-table'), (key, nextOrder) => {
                        this.state.orderby = key;
                        this.state.order = nextOrder;
                        this.loadCamps();
                    });

                } else {
                    container.innerHTML = `
                        <div id="scb-camps-grid" style="display:grid;grid-template-columns:repeat(auto-fill, minmax(340px, 1fr));gap:20px;">
                            ${camps.map(c => {
                        const regCount = parseInt(c.registered_count, 10) || 0;
                        const cap = parseInt(c.capacity, 10) || 20;
                        const pct = Math.min(100, Math.round((regCount / cap) * 100));

                        return `
                                    <div class="scb-card" style="display:flex;flex-direction:column;justify-content:space-between;overflow:hidden;">
                                        ${c.image_url ? `<img src="${Utils.escHtml(c.image_url)}" alt="${Utils.escHtml(c.title)}" style="width:100%;height:160px;object-fit:cover;">` : ''}
                                        <div class="scb-card__body">
                                            <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:8px;">
                                                <h3 style="margin:0;font-size:16px;font-weight:700;">${Utils.escHtml(c.title)}</h3>
                                                <span class="scb-badge scb-badge--${c.publication_status === 'published' ? 'success' : 'warning'}">${c.publication_status}</span>
                                            </div>
                                            <div style="font-size:13px;color:var(--scb-text-muted);margin-bottom:10px;">
                                                <strong>Dates:</strong> ${Utils.formatDate(c.start_date)} – ${Utils.formatDate(c.end_date)}
                                            </div>
                                            <div style="font-size:13px;color:var(--scb-text-muted);margin-bottom:10px;">
                                                <strong>Ages:</strong> ${c.min_age || 6} to ${c.max_age || 18} years
                                            </div>
                                            <div style="font-size:14px;font-weight:700;color:var(--scb-primary);margin-bottom:12px;">
                                                ${Utils.formatCurrency(c.price)} per student
                                            </div>
                                            <div style="margin-bottom:8px;">
                                                <div style="display:flex;justify-content:space-between;font-size:12px;margin-bottom:4px;">
                                                    <span>Registrations</span>
                                                    <strong>${regCount} / ${cap}</strong>
                                                </div>
                                                <div style="width:100%;height:6px;background:var(--scb-border);border-radius:3px;overflow:hidden;">
                                                    <div style="width:${pct}%;height:100%;background:var(--scb-primary);"></div>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="scb-card__footer" style="display:flex;gap:8px;padding:12px 16px;border-top:1px solid var(--scb-border);background:var(--scb-surface-subtle);">
                                            <button class="scb-btn scb-btn--sm scb-btn--secondary scb-edit-camp-btn" data-id="${c.id}">Edit</button>
                                            <button class="scb-btn scb-btn--sm scb-btn--danger scb-delete-camp-btn" data-id="${c.id}" data-title="${Utils.escHtml(c.title)}">Delete</button>
                                        </div>
                                    </div>
                                `;
                    }).join('')}
                        </div>
                    `;
                }

                container.querySelectorAll('.scb-edit-camp-btn').forEach(btn => {
                    btn.addEventListener('click', () => this.openEditModal(btn.dataset.id));
                });

                container.querySelectorAll('.scb-delete-camp-btn').forEach(btn => {
                    btn.addEventListener('click', async () => {
                        const campId = btn.dataset.id;
                        const campTitle = btn.dataset.title;
                        const confirmed = await Modal.confirm({
                            title: 'Delete Camp',
                            message: `Are you sure you want to delete "${campTitle}"?`,
                            confirmText: 'Delete Camp',
                            confirmClass: 'scb-btn--danger',
                        });
                        if (!confirmed) return;

                        try {
                            const res = await Api.delete(`camps/${campId}`);
                            Toast.success(res?.message || 'Summer camp deleted successfully.');
                            this.loadCamps();
                        } catch (err) {
                            Toast.error('Could not delete camp', err.message);
                        }
                    });
                });

            } catch (err) {
                Toast.error('Could not load camps', err.message);
            }
        },

        openSeedModal() {
            Modal.open({
                title: 'Generate Dummy Summer Camps',
                content: `
                    <p style="margin-bottom:14px;color:var(--scb-text-muted);font-size:14px;line-height:1.6;">
                        This will generate 3 professional demo summer camps with schedules, age brackets, pricing, descriptions, and banners.
                    </p>
                    <p style="margin-bottom:0;color:var(--scb-text-muted);font-size:13px;">
                        <em>Note: If any sample camps already exist, they will be safely skipped to avoid duplicates.</em>
                    </p>
                `,
                footer: `
                    <button class="scb-btn scb-btn--secondary" data-modal-close>Cancel</button>
                    <button class="scb-btn scb-btn--primary" id="scb-submit-seed-camps-btn">Generate Now</button>
                `
            });

            document.getElementById('scb-submit-seed-camps-btn')?.addEventListener('click', async () => {
                const btn = document.getElementById('scb-submit-seed-camps-btn');
                if (btn) {
                    btn.disabled = true;
                    btn.textContent = 'Generating…';
                }
                try {
                    const res = await Api.post('camps/seed');
                    Modal.close();
                    Toast.success(res.message || 'Dummy summer camps generated successfully.');
                    await this.loadCamps();
                } catch (err) {
                    if (btn) {
                        btn.disabled = false;
                        btn.textContent = 'Generate Now';
                    }
                    Toast.error('Could not generate dummy camps', err.message);
                }
            });
        },

        openCreateModal() {
            Modal.open({
                title: 'Create Summer Camp',
                content: `
                    <div class="scb-form-group">
                        <label class="scb-form-label">Camp Title *</label>
                        <input type="text" class="scb-input" id="scb-camp-title" required placeholder="e.g. Summer Squash Intensive Academy">
                    </div>
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
                        <div class="scb-form-group">
                            <label class="scb-form-label">Start Date *</label>
                            <input type="date" class="scb-input" id="scb-camp-start" required>
                        </div>
                        <div class="scb-form-group">
                            <label class="scb-form-label">End Date *</label>
                            <input type="date" class="scb-input" id="scb-camp-end" required>
                        </div>
                    </div>
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
                        <div class="scb-form-group">
                            <label class="scb-form-label">Min Age</label>
                            <input type="number" class="scb-input" id="scb-camp-min-age" value="8">
                        </div>
                        <div class="scb-form-group">
                            <label class="scb-form-label">Max Age</label>
                            <input type="number" class="scb-input" id="scb-camp-max-age" value="16">
                        </div>
                    </div>
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
                        <div class="scb-form-group">
                            <label class="scb-form-label">Price ($)</label>
                            <input type="number" step="0.5" class="scb-input" id="scb-camp-price" value="350.00">
                        </div>
                        <div class="scb-form-group">
                            <label class="scb-form-label">Capacity</label>
                            <input type="number" class="scb-input" id="scb-camp-cap" value="20">
                        </div>
                    </div>
                    <div class="scb-form-group">
                        <label class="scb-form-label">Image URL</label>
                        <input type="url" class="scb-input" id="scb-camp-img" placeholder="https://...">
                    </div>
                    <div class="scb-form-group">
                        <label class="scb-form-label">Description</label>
                        <textarea class="scb-textarea" id="scb-camp-desc" rows="3"></textarea>
                    </div>
                `,
                footer: `
                    <button class="scb-btn scb-btn--secondary" data-modal-close>Cancel</button>
                    <button class="scb-btn scb-btn--primary" id="scb-submit-camp-btn">Publish Camp</button>
                `
            });

            document.getElementById('scb-submit-camp-btn')?.addEventListener('click', async () => {
                const title = document.getElementById('scb-camp-title')?.value.trim();
                const start = document.getElementById('scb-camp-start')?.value;
                const end = document.getElementById('scb-camp-end')?.value;

                if (!title || !start || !end) {
                    Toast.error('Validation Error', 'Please fill in the camp title, start date, and end date.');
                    return;
                }

                const submitBtn = document.getElementById('scb-submit-camp-btn');
                if (submitBtn) {
                    submitBtn.disabled = true;
                    submitBtn.textContent = 'Publishing…';
                }

                try {
                    const res = await Api.post('camps', {
                        title: title,
                        start_date: start,
                        end_date: end,
                        min_age: document.getElementById('scb-camp-min-age')?.value,
                        max_age: document.getElementById('scb-camp-max-age')?.value,
                        price: document.getElementById('scb-camp-price')?.value,
                        capacity: document.getElementById('scb-camp-cap')?.value,
                        image_url: document.getElementById('scb-camp-img')?.value,
                        description: document.getElementById('scb-camp-desc')?.value,
                        publication_status: 'published',
                    });
                    Modal.close();
                    Toast.success(res?.message || 'Summer camp created successfully.');
                    this.loadCamps();
                } catch (err) {
                    if (submitBtn) {
                        submitBtn.disabled = false;
                        submitBtn.textContent = 'Publish Camp';
                    }
                    Toast.error('Could not create camp', err.message);
                }
            });
        },

        async openEditModal(id) {
            try {
                const res = await Api.get(`camps/${id}`);
                const c = res.data;

                Modal.open({
                    title: `Edit Camp — ${Utils.escHtml(c.title)}`,
                    content: `
                        <div class="scb-form-group">
                            <label class="scb-form-label">Title</label>
                            <input type="text" class="scb-input" id="scb-ecamp-title" value="${Utils.escHtml(c.title)}">
                        </div>
                        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
                            <div class="scb-form-group">
                                <label class="scb-form-label">Price ($)</label>
                                <input type="number" step="0.5" class="scb-input" id="scb-ecamp-price" value="${c.price}">
                            </div>
                            <div class="scb-form-group">
                                <label class="scb-form-label">Capacity</label>
                                <input type="number" class="scb-input" id="scb-ecamp-cap" value="${c.capacity}">
                            </div>
                        </div>
                        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
                            <div class="scb-form-group">
                                <label class="scb-form-label">Registration Status</label>
                                <select class="scb-select" id="scb-ecamp-reg-status">
                                    <option value="open" ${c.registration_status === 'open' ? 'selected' : ''}>Open</option>
                                    <option value="closed" ${c.registration_status === 'closed' ? 'selected' : ''}>Closed</option>
                                    <option value="full" ${c.registration_status === 'full' ? 'selected' : ''}>Full</option>
                                </select>
                            </div>
                            <div class="scb-form-group">
                                <label class="scb-form-label">Publication Status</label>
                                <select class="scb-select" id="scb-ecamp-pub-status">
                                    <option value="published" ${c.publication_status === 'published' ? 'selected' : ''}>Published</option>
                                    <option value="draft" ${c.publication_status === 'draft' ? 'selected' : ''}>Draft</option>
                                    <option value="archived" ${c.publication_status === 'archived' ? 'selected' : ''}>Archived</option>
                                </select>
                            </div>
                        </div>
                        <div class="scb-form-group">
                            <label class="scb-form-label">Description</label>
                            <textarea class="scb-textarea" id="scb-ecamp-desc" rows="3">${Utils.escHtml(c.description || '')}</textarea>
                        </div>
                    `,
                    footer: `
                        <button class="scb-btn scb-btn--secondary" data-modal-close>Cancel</button>
                        <button class="scb-btn scb-btn--primary" id="scb-submit-ecamp">Save Changes</button>
                    `
                });

                document.getElementById('scb-submit-ecamp')?.addEventListener('click', async () => {
                    const submitBtn = document.getElementById('scb-submit-ecamp');
                    if (submitBtn) {
                        submitBtn.disabled = true;
                        submitBtn.textContent = 'Saving…';
                    }

                    try {
                        const res = await Api.post(`camps/${id}`, {
                            title: document.getElementById('scb-ecamp-title')?.value,
                            price: document.getElementById('scb-ecamp-price')?.value,
                            capacity: document.getElementById('scb-ecamp-cap')?.value,
                            registration_status: document.getElementById('scb-ecamp-reg-status')?.value,
                            publication_status: document.getElementById('scb-ecamp-pub-status')?.value,
                            description: document.getElementById('scb-ecamp-desc')?.value,
                        });
                        Modal.close();
                        Toast.success(res?.message || 'Camp updated successfully.');
                        this.loadCamps();
                    } catch (err) {
                        if (submitBtn) {
                            submitBtn.disabled = false;
                            submitBtn.textContent = 'Save Changes';
                        }
                        Toast.error('Could not update camp', err.message);
                    }
                });

            } catch (err) {
                Toast.error('Could not load camp details', err.message);
            }
        }
    };

    // ── Camp Registrations View ───────────────────────────────────────────────
    const RegistrationsView = {
        state: { orderby: 'created_at', order: 'DESC' },

        async render(container) {
            container.innerHTML = `
                <div class="scb-page-header">
                    <div>
                        <h1 class="scb-page-title">Camp Registrations</h1>
                        <p class="scb-page-subtitle">View student registrations, parental contacts, and payment status.</p>
                    </div>
                </div>

                <div class="scb-table-wrap">
                    <table class="scb-table" id="scb-registrations-table">
                        <thead>
                            <tr id="scb-registrations-thead-row">
                                ${this._renderThead()}
                            </tr>
                        </thead>
                        <tbody id="scb-camp-reg-tbody">
                            ${Utils.skeletonRows(8)}
                        </tbody>
                    </table>
                </div>
            `;

            ViewUtils.bindSortHeaders(document.getElementById('scb-registrations-table'), (key, nextOrder) => {
                this.state.orderby = key;
                this.state.order = nextOrder;
                const theadRow = document.getElementById('scb-registrations-thead-row');
                if (theadRow) theadRow.innerHTML = this._renderThead();
                this.loadRegistrations();
            });

            await this.loadRegistrations();
        },

        _renderThead() {
            const k = this.state.orderby;
            const o = this.state.order;
            return `
                ${ViewUtils.sortHeader('Reference', 'registration_ref', k, o)}
                ${ViewUtils.sortHeader('Student', 'student_name', k, o)}
                ${ViewUtils.sortHeader('Camp', 'camp_title', k, o)}
                ${ViewUtils.sortHeader('Parent / Contact', 'parent_name', k, o)}
                ${ViewUtils.sortHeader('Status', 'registration_status', k, o)}
                ${ViewUtils.sortHeader('Payment', 'payment_status', k, o)}
                ${ViewUtils.sortHeader('Amount', 'amount', k, o)}
                ${ViewUtils.sortHeader('Date', 'created_at', k, o)}
            `;
        },

        async loadRegistrations() {
            const tbody = document.getElementById('scb-camp-reg-tbody');
            if (!tbody) return;

            try {
                const res = await Api.get('camp-registrations', {
                    orderby: this.state.orderby,
                    order: this.state.order,
                });
                const items = res.data?.items || [];

                if (!items.length) {
                    tbody.innerHTML = '<tr><td colspan="8"><div class="scb-empty-state"><div class="scb-empty-state__title">No camp registrations found</div></div></td></tr>';
                    return;
                }

                tbody.innerHTML = items.map(r => `
                    <tr>
                        <td><strong>${Utils.escHtml(r.registration_ref)}</strong></td>
                        <td>
                            <strong>${Utils.escHtml(r.student_name)}</strong>
                            ${r.student_age ? `<div style="font-size:12px;color:var(--scb-text-muted);">Age ${r.student_age}</div>` : ''}
                        </td>
                        <td>${Utils.escHtml(r.camp_title || 'Summer Camp')}</td>
                        <td>
                            <div>${Utils.escHtml(r.parent_name)}</div>
                            <div style="font-size:12px;color:var(--scb-text-muted);">${Utils.escHtml(r.parent_email)} · ${Utils.escHtml(r.parent_phone || '')}</div>
                        </td>
                        <td>${Utils.statusBadge(r.registration_status, 'camp')}</td>
                        <td>${Utils.statusBadge(r.payment_status, 'payment')}</td>
                        <td>${Utils.formatCurrency(r.amount)}</td>
                        <td>${Utils.formatDate(r.created_at)}</td>
                    </tr>
                `).join('');

            } catch (err) {
                Toast.error('Could not load registrations', err.message);
            }
        }
    };

    // ── Payments View ─────────────────────────────────────────────────────────
    const PaymentsView = {
        state: { orderby: 'created_at', order: 'DESC' },

        async render(container) {
            container.innerHTML = `
                <div class="scb-page-header">
                    <div>
                        <h1 class="scb-page-title">Payments & Orders</h1>
                        <p class="scb-page-subtitle">Audit payment transactions and linked WooCommerce orders.</p>
                    </div>
                    <div style="display:flex;gap:10px;">
                        <button class="scb-btn scb-btn--secondary" id="scb-sync-wc-btn">🔄 Sync with WooCommerce</button>
                    </div>
                </div>

                <div class="scb-table-wrap">
                    <table class="scb-table" id="scb-payments-table">
                        <thead>
                            <tr id="scb-payments-thead-row">
                                ${this._renderThead()}
                            </tr>
                        </thead>
                        <tbody id="scb-payments-tbody">
                            ${Utils.skeletonRows(7)}
                        </tbody>
                    </table>
                </div>
            `;

            const syncBtn = document.getElementById('scb-sync-wc-btn');
            if (syncBtn) {
                syncBtn.addEventListener('click', async () => {
                    Utils.setLoading(syncBtn, true);
                    try {
                        const res = await Api.post('payments/sync');
                        Toast.success('Sync Complete', res.message || 'WooCommerce orders and payments synchronized.');
                        await this.loadPayments();
                    } catch (err) {
                        Toast.error('Sync Failed', err.message);
                    } finally {
                        Utils.setLoading(syncBtn, false);
                    }
                });
            }

            ViewUtils.bindSortHeaders(document.getElementById('scb-payments-table'), (key, nextOrder) => {
                this.state.orderby = key;
                this.state.order = nextOrder;
                const theadRow = document.getElementById('scb-payments-thead-row');
                if (theadRow) theadRow.innerHTML = this._renderThead();
                this.loadPayments();
            });

            await this.loadPayments();
        },

        _renderThead() {
            const k = this.state.orderby;
            const o = this.state.order;
            return `
                ${ViewUtils.sortHeader('Reference', 'reference', k, o)}
                ${ViewUtils.sortHeader('Customer', 'customer_name', k, o)}
                ${ViewUtils.sortHeader('Service', 'service_type', k, o)}
                ${ViewUtils.sortHeader('Amount', 'amount', k, o)}
                ${ViewUtils.sortHeader('Order ID', 'wc_order_id', k, o)}
                ${ViewUtils.sortHeader('Payment Status', 'payment_status', k, o)}
                ${ViewUtils.sortHeader('Date', 'created_at', k, o)}
            `;
        },

        async loadPayments() {
            const tbody = document.getElementById('scb-payments-tbody');
            if (!tbody) return;

            try {
                const res = await Api.get('payments', {
                    orderby: this.state.orderby,
                    order: this.state.order,
                });
                const items = res.data?.items || [];

                if (!items.length) {
                    tbody.innerHTML = '<tr><td colspan="7"><div class="scb-empty-state"><div class="scb-empty-state__title">No payment records found</div></div></td></tr>';
                    return;
                }

                tbody.innerHTML = items.map(p => `
                    <tr>
                        <td><strong>${Utils.escHtml(p.reference)}</strong></td>
                        <td>
                            <div>${Utils.escHtml(p.customer_name)}</div>
                            <div style="font-size:12px;color:var(--scb-text-muted);">${Utils.escHtml(p.customer_email)}</div>
                        </td>
                        <td><span class="scb-badge scb-badge--info">${Utils.escHtml(p.service_type)}</span></td>
                        <td><strong>${Utils.formatCurrency(p.amount, p.currency || 'USD')}</strong></td>
                        <td>
                            ${p.wc_order_id ? `
                                <a href="${p.wc_order_url || ('post.php?post=' + p.wc_order_id + '&action=edit')}" target="_blank" class="scb-btn scb-btn--sm scb-btn--secondary">Order #${p.wc_order_id} ↗</a>
                                ${p.wc_status ? `<span style="margin-left:5px;font-size:11px;" class="scb-badge scb-badge--info">WC: ${Utils.escHtml(p.wc_status)}</span>` : ''}
                            ` : '—'}
                        </td>
                        <td>${Utils.statusBadge(p.payment_status, 'payment')}</td>
                        <td>${Utils.formatDate(p.created_at, true)}</td>
                    </tr>
                `).join('');

            } catch (err) {
                Toast.error('Could not load payments', err.message);
            }
        }
    };

    // ── Customers View ────────────────────────────────────────────────────────
    const CustomersView = {
        state: { orderby: 'last_booking_date', order: 'DESC' },

        async render(container) {
            container.innerHTML = `
                <div class="scb-page-header">
                    <div>
                        <h1 class="scb-page-title">Customers Directory</h1>
                        <p class="scb-page-subtitle">Directory of players and parents who have made bookings.</p>
                    </div>
                </div>

                <div class="scb-table-wrap">
                    <table class="scb-table" id="scb-customers-table">
                        <thead>
                            <tr id="scb-customers-thead-row">
                                ${this._renderThead()}
                            </tr>
                        </thead>
                        <tbody id="scb-customers-tbody">
                            ${Utils.skeletonRows(6)}
                        </tbody>
                    </table>
                </div>
            `;

            ViewUtils.bindSortHeaders(document.getElementById('scb-customers-table'), (key, nextOrder) => {
                this.state.orderby = key;
                this.state.order = nextOrder;
                const theadRow = document.getElementById('scb-customers-thead-row');
                if (theadRow) theadRow.innerHTML = this._renderThead();
                this.loadCustomers();
            });

            await this.loadCustomers();
        },

        _renderThead() {
            const k = this.state.orderby;
            const o = this.state.order;
            return `
                ${ViewUtils.sortHeader('Customer Name', 'customer_name', k, o)}
                ${ViewUtils.sortHeader('Email Address', 'customer_email', k, o)}
                ${ViewUtils.sortHeader('Phone Number', 'customer_phone', k, o)}
                ${ViewUtils.sortHeader('Total Bookings', 'total_bookings', k, o)}
                ${ViewUtils.sortHeader('Total Spent', 'total_spent', k, o)}
                ${ViewUtils.sortHeader('Last Active', 'last_booking_date', k, o)}
            `;
        },

        async loadCustomers() {
            const tbody = document.getElementById('scb-customers-tbody');
            if (!tbody) return;

            try {
                const res = await Api.get('customers', {
                    orderby: this.state.orderby,
                    order: this.state.order,
                });
                const items = res.data?.items || [];

                if (!items.length) {
                    tbody.innerHTML = '<tr><td colspan="6"><div class="scb-empty-state"><div class="scb-empty-state__title">No customers found</div></div></td></tr>';
                    return;
                }

                tbody.innerHTML = items.map(c => `
                    <tr>
                        <td><strong>${Utils.escHtml(c.customer_name)}</strong></td>
                        <td>${Utils.escHtml(c.customer_email)}</td>
                        <td>${Utils.escHtml(c.customer_phone || '—')}</td>
                        <td><span class="scb-badge scb-badge--info">${c.total_bookings} bookings</span></td>
                        <td><strong>${Utils.formatCurrency(c.total_spent)}</strong></td>
                        <td>${Utils.formatDate(c.last_booking_date)}</td>
                    </tr>
                `).join('');

            } catch (err) {
                Toast.error('Could not load customers', err.message);
            }
        }
    };

    // ── Notifications Log View ────────────────────────────────────────────────
    const NotificationsView = {
        state: { orderby: 'sent_at', order: 'DESC' },

        async render(container) {
            container.innerHTML = `
                <div class="scb-page-header">
                    <div>
                        <h1 class="scb-page-title">Notification Logs</h1>
                        <p class="scb-page-subtitle">Audit trail of automated email notifications dispatched by the system.</p>
                    </div>
                </div>

                <div class="scb-table-wrap">
                    <table class="scb-table" id="scb-notifications-table">
                        <thead>
                            <tr id="scb-notifications-thead-row">
                                ${this._renderThead()}
                            </tr>
                        </thead>
                        <tbody id="scb-notif-tbody">
                            ${Utils.skeletonRows(5)}
                        </tbody>
                    </table>
                </div>
            `;

            ViewUtils.bindSortHeaders(document.getElementById('scb-notifications-table'), (key, nextOrder) => {
                this.state.orderby = key;
                this.state.order = nextOrder;
                const theadRow = document.getElementById('scb-notifications-thead-row');
                if (theadRow) theadRow.innerHTML = this._renderThead();
                this.loadNotifications();
            });

            await this.loadNotifications();
        },

        _renderThead() {
            const k = this.state.orderby;
            const o = this.state.order;
            return `
                ${ViewUtils.sortHeader('Subject', 'subject', k, o)}
                ${ViewUtils.sortHeader('Recipient', 'recipient_name', k, o)}
                ${ViewUtils.sortHeader('Notification Key', 'notification_key', k, o)}
                ${ViewUtils.sortHeader('Status', 'status', k, o)}
                ${ViewUtils.sortHeader('Sent Date', 'sent_at', k, o)}
            `;
        },

        async loadNotifications() {
            const tbody = document.getElementById('scb-notif-tbody');
            if (!tbody) return;

            try {
                const res = await Api.get('notifications', {
                    orderby: this.state.orderby,
                    order: this.state.order,
                });
                const items = res.data?.items || [];

                if (!items.length) {
                    tbody.innerHTML = '<tr><td colspan="5"><div class="scb-empty-state"><div class="scb-empty-state__title">No notification records yet</div></div></td></tr>';
                    return;
                }

                tbody.innerHTML = items.map(n => `
                    <tr>
                        <td><strong>${Utils.escHtml(n.subject)}</strong></td>
                        <td>
                            <div>${Utils.escHtml(n.recipient_name || 'Customer')}</div>
                            <div style="font-size:12px;color:var(--scb-text-muted);">${Utils.escHtml(n.recipient_email)}</div>
                        </td>
                        <td><code>${Utils.escHtml(n.notification_key)}</code></td>
                        <td><span class="scb-badge scb-badge--${n.status === 'sent' ? 'success' : 'danger'}">${n.status}</span></td>
                        <td>${Utils.formatDate(n.sent_at, true)}</td>
                    </tr>
                `).join('');

            } catch (err) {
                Toast.error('Could not load notifications', err.message);
            }
        }
    };

    // ── Reports View ──────────────────────────────────────────────────────────
    const ReportsView = {
        state: { coachSort: 'revenue', coachOrder: 'DESC', serviceSort: 'total_amount', serviceOrder: 'DESC' },

        async render(container) {
            container.innerHTML = `
                <div class="scb-page-header">
                    <div>
                        <h1 class="scb-page-title">Reports & Data Export</h1>
                        <p class="scb-page-subtitle">Summary metrics, service breakdowns, and CSV data export.</p>
                    </div>
                    <div class="scb-page-actions">
                        <button class="scb-btn scb-btn--secondary" id="scb-exp-bookings">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                            Export Bookings CSV
                        </button>
                        <button class="scb-btn scb-btn--secondary" id="scb-exp-camps">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                            Export Camps CSV
                        </button>
                        <button class="scb-btn scb-btn--secondary" id="scb-exp-customers">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                            Export Customers CSV
                        </button>
                    </div>
                </div>

                <div id="scb-reports-content">
                    <div class="scb-loading-overlay"><div class="scb-spinner"></div></div>
                </div>
            `;

            document.getElementById('scb-exp-bookings').addEventListener('click', () => {
                window.open(`${CONFIG.restUrl}reports/export?type=bookings&_wpnonce=${CONFIG.restNonce}`, '_blank');
            });
            document.getElementById('scb-exp-camps').addEventListener('click', () => {
                window.open(`${CONFIG.restUrl}reports/export?type=camps&_wpnonce=${CONFIG.restNonce}`, '_blank');
            });
            document.getElementById('scb-exp-customers').addEventListener('click', () => {
                window.open(`${CONFIG.restUrl}reports/export?type=customers&_wpnonce=${CONFIG.restNonce}`, '_blank');
            });

            await this.loadSummary();
        },

        async loadSummary() {
            const el = document.getElementById('scb-reports-content');
            if (!el) return;

            try {
                const res = await Api.get('reports/summary');
                const data = res.data;

                // Sort datasets client-side according to state
                let services = (data.by_service || []).slice();
                services.sort((a, b) => {
                    let valA = a[this.state.serviceSort] || 0;
                    let valB = b[this.state.serviceSort] || 0;
                    if (this.state.serviceSort === 'service_type') {
                        return this.state.serviceOrder === 'ASC'
                            ? (a.service_type || '').localeCompare(b.service_type || '')
                            : (b.service_type || '').localeCompare(a.service_type || '');
                    }
                    valA = parseFloat(valA) || 0;
                    valB = parseFloat(valB) || 0;
                    return this.state.serviceOrder === 'ASC' ? valA - valB : valB - valA;
                });

                let coaches = (data.top_coaches || []).slice();
                coaches.sort((a, b) => {
                    if (this.state.coachSort === 'name') {
                        const nameA = `${a.first_name || ''} ${a.last_name || ''}`;
                        const nameB = `${b.first_name || ''} ${b.last_name || ''}`;
                        return this.state.coachOrder === 'ASC' ? nameA.localeCompare(nameB) : nameB.localeCompare(nameA);
                    }
                    const valA = parseFloat(a[this.state.coachSort] || 0);
                    const valB = parseFloat(b[this.state.coachSort] || 0);
                    return this.state.coachOrder === 'ASC' ? valA - valB : valB - valA;
                });

                el.innerHTML = `
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-bottom:24px;">
                        <div class="scb-card">
                            <div class="scb-card__header"><span class="scb-card__title">Bookings by Service</span></div>
                            <div class="scb-card__body" style="padding:0;">
                                <table class="scb-table" id="scb-report-service-table">
                                    <thead>
                                        <tr>
                                            ${ViewUtils.sortHeader('Service', 'service_type', this.state.serviceSort, this.state.serviceOrder)}
                                            ${ViewUtils.sortHeader('Bookings', 'count', this.state.serviceSort, this.state.serviceOrder)}
                                            ${ViewUtils.sortHeader('Total Revenue', 'total_amount', this.state.serviceSort, this.state.serviceOrder)}
                                        </tr>
                                    </thead>
                                    <tbody>
                                        ${services.length ? services.map(s => `
                                            <tr>
                                                <td><strong>${(s.service_type || '').replace(/_/g, ' ').toUpperCase()}</strong></td>
                                                <td><span class="scb-badge scb-badge--info">${s.count} bookings</span></td>
                                                <td><strong>${Utils.formatCurrency(s.total_amount)}</strong></td>
                                            </tr>
                                        `).join('') : '<tr><td colspan="3" style="text-align:center;color:var(--scb-text-muted);padding:16px;">No booking data.</td></tr>'}
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        <div class="scb-card">
                            <div class="scb-card__header"><span class="scb-card__title">Top Coach Performance</span></div>
                            <div class="scb-card__body" style="padding:0;">
                                <table class="scb-table" id="scb-report-coach-table">
                                    <thead>
                                        <tr>
                                            ${ViewUtils.sortHeader('Coach', 'name', this.state.coachSort, this.state.coachOrder)}
                                            ${ViewUtils.sortHeader('Lessons', 'bookings_count', this.state.coachSort, this.state.coachOrder)}
                                            ${ViewUtils.sortHeader('Revenue', 'revenue', this.state.coachSort, this.state.coachOrder)}
                                        </tr>
                                    </thead>
                                    <tbody>
                                        ${coaches.length ? coaches.map(c => `
                                            <tr>
                                                <td><strong>Coach ${Utils.escHtml(c.first_name)} ${Utils.escHtml(c.last_name)}</strong></td>
                                                <td><span class="scb-badge scb-badge--info">${c.bookings_count} lessons</span></td>
                                                <td><strong>${Utils.formatCurrency(c.revenue)}</strong></td>
                                            </tr>
                                        `).join('') : '<tr><td colspan="3" style="text-align:center;color:var(--scb-text-muted);padding:16px;">No coach booking data yet.</td></tr>'}
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                `;

                ViewUtils.bindSortHeaders('#scb-report-service-table', (key, nextOrder) => {
                    this.state.serviceSort = key;
                    this.state.serviceOrder = nextOrder;
                    this.loadSummary();
                });

                ViewUtils.bindSortHeaders('#scb-report-coach-table', (key, nextOrder) => {
                    this.state.coachSort = key;
                    this.state.coachOrder = nextOrder;
                    this.loadSummary();
                });

            } catch (err) {
                el.innerHTML = '<p style="color:var(--scb-error);padding:16px;">Failed to load report summary.</p>';
            }
        }
    };

    // ── Settings View ─────────────────────────────────────────────────────────
    const SettingsView = {
        async render(container) {
            container.innerHTML = `
                <div class="scb-page-header">
                    <div>
                        <h1 class="scb-page-title">Plugin Settings</h1>
                        <p class="scb-page-subtitle">Configure frontend display shortcodes, pricing rules, weekly slot automation, and notifications.</p>
                    </div>
                    <div class="scb-page-actions">
                        <button class="scb-btn scb-btn--primary" id="scb-save-settings-btn">
                            Save All Settings
                        </button>
                    </div>
                </div>

                <!-- General Configuration Card (Moved to top) -->
                <div class="scb-card" style="margin-bottom:24px;">
                    <div class="scb-card__header" style="padding:18px 20px;border-bottom:1px solid var(--scb-border);">
                        <h2 class="scb-card__title" style="margin:0;font-size:16px;">General Configuration & Pricing Rules</h2>
                    </div>
                    <div class="scb-card__body" id="scb-settings-form-body">
                        <div class="scb-loading-overlay"><div class="scb-spinner"></div></div>
                    </div>
                </div>

                <!-- Frontend Display Shortcodes Card -->
                <div class="scb-card" style="margin-bottom:24px;">
                    <div class="scb-card__header" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;padding:18px 20px;border-bottom:1px solid var(--scb-border);">
                        <div>
                            <h2 class="scb-card__title" style="margin:0;display:flex;align-items:center;gap:8px;font-size:16px;">
                                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="16 18 22 12 16 6"/><polyline points="8 6 2 12 8 18"/></svg>
                                Frontend Display Shortcodes
                            </h2>
                            <p class="scb-card__subtitle" style="margin:4px 0 0;font-size:13px;color:var(--scb-text-muted);">
                                Use these shortcodes in any WordPress Page or Post (Pages &gt; Add New) to display the customer-facing booking forms.
                            </p>
                        </div>
                        <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
                            <button class="scb-btn scb-btn--secondary scb-btn--sm" id="scb-auto-create-pages-btn" type="button" style="display:inline-flex;align-items:center;gap:6px;">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
                                Auto-Create All Booking Pages
                            </button>
                            <a href="post-new.php?post_type=page" target="_blank" class="scb-btn scb-btn--primary scb-btn--sm" style="display:inline-flex;align-items:center;gap:6px;">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                                Add New Page
                            </a>
                        </div>
                    </div>
                    <div class="scb-card__body" id="scb-shortcodes-body" style="padding:0;">
                        <div class="scb-loading-overlay"><div class="scb-spinner"></div></div>
                    </div>
                </div>

                <!-- Reset Table Data Card -->
                <div class="scb-card" style="border:1px solid #fecaca;margin-bottom:24px;overflow:hidden;">
                    <div class="scb-card__header" style="padding:18px 20px;border-bottom:1px solid #fee2e2;background:#fff5f5;">
                        <h2 class="scb-card__title" style="margin:0;color:#dc2626;display:flex;align-items:center;gap:8px;font-size:16px;">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                            Reset Table Data
                        </h2>
                        <p class="scb-card__subtitle" style="margin:4px 0 0;font-size:13px;color:#991b1b;">
                            Permanently wipe all plugin data from the database and return the plugin to its original fresh-installation state.
                        </p>
                    </div>
                    <div class="scb-card__body" style="padding:20px;">
                        <div style="background:#fff1f2;border:1px solid #ffe4e6;border-radius:8px;padding:16px;margin-bottom:20px;display:flex;align-items:flex-start;gap:12px;">
                            <div style="color:#e11d48;margin-top:2px;">
                                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                            </div>
                            <div style="font-size:13px;color:#881337;line-height:1.6;">
                                <strong>Warning: Danger Zone!</strong> Clicking this button will truncate and delete <strong>all records</strong> across all plugin tables:
                                <ul style="margin:6px 0 0 16px;padding:0;list-style-type:disc;">
                                    <li>All customer bookings and participant lists</li>
                                    <li>All Sunday clinic sessions and waitlists</li>
                                    <li>All coach profiles and weekly availability schedules</li>
                                    <li>All summer camps and camp registrations</li>
                                    <li>All payment logs and admin audit history</li>
                                </ul>
                                All pricing and club configurations will be reset to factory defaults. <strong>This action cannot be undone!</strong>
                            </div>
                        </div>

                        <button type="button" class="scb-btn scb-btn--danger" id="scb-reset-tables-btn" style="background:#dc2626;color:#fff;border:none;padding:10px 20px;font-weight:600;display:inline-flex;align-items:center;gap:8px;border-radius:6px;cursor:pointer;">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/><line x1="10" y1="11" x2="10" y2="17"/><line x1="14" y1="11" x2="14" y2="17"/></svg>
                            Reset All Table Data
                        </button>
                    </div>
                </div>
            `;

            document.getElementById('scb-save-settings-btn').addEventListener('click', () => this.saveSettings());
            document.getElementById('scb-reset-tables-btn')?.addEventListener('click', () => this.openResetModal());
            await this.loadSettings();
        },

        async loadSettings() {
            const shortcodesBody = document.getElementById('scb-shortcodes-body');
            const body = document.getElementById('scb-settings-form-body');
            if (!body) return;

            try {
                const res = await Api.get('settings');
                const s = res.data;

                // Render Shortcodes Table
                if (shortcodesBody && s.shortcodes) {
                    shortcodesBody.innerHTML = `
                        <div class="scb-table-container">
                            <table class="scb-table">
                                <thead>
                                    <tr>
                                        <th style="min-width:200px;">Service / Feature</th>
                                        <th style="min-width:230px;">Shortcode</th>
                                        <th style="min-width:180px;">Frontend Page Status</th>
                                        <th>Description & How to Use</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    ${s.shortcodes.map(sc => `
                                        <tr>
                                            <td>
                                                <div style="font-weight:600;color:var(--scb-text-dark);">${Utils.escHtml(sc.title)}</div>
                                                <span class="scb-badge scb-badge--info" style="margin-top:4px;font-size:11px;">${Utils.escHtml(sc.category)}</span>
                                            </td>
                                            <td>
                                                <div style="display:flex;align-items:center;gap:8px;">
                                                    <code class="scb-code-badge">${Utils.escHtml(sc.shortcode)}</code>
                                                    <button type="button" class="scb-copy-btn" data-shortcode="${Utils.escHtml(sc.shortcode)}" title="Copy shortcode to clipboard">
                                                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>
                                                        Copy
                                                    </button>
                                                </div>
                                            </td>
                                            <td>
                                                ${sc.has_page ? `
                                                    <span class="scb-badge scb-badge--success">✓ Page Created</span>
                                                    <div style="margin-top:5px;">
                                                        <a href="${Utils.escHtml(sc.page_url)}" target="_blank" style="color:var(--scb-primary);font-weight:500;font-size:12px;text-decoration:none;display:inline-flex;align-items:center;gap:4px;">
                                                            ${Utils.escHtml(sc.page_title)}
                                                            <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>
                                                        </a>
                                                    </div>
                                                ` : `
                                                    <span class="scb-badge scb-badge--warning">Not Created</span>
                                                    <div style="font-size:12px;color:var(--scb-text-muted);margin-top:4px;">Rec. Page: <em>${Utils.escHtml(sc.recommended_page)}</em></div>
                                                `}
                                            </td>
                                            <td style="font-size:16px;color:var(--scb-text-muted);line-height:1.6;">
                                                ${Utils.escHtml(sc.description)}
                                            </td>
                                        </tr>
                                    `).join('')}
                                </tbody>
                            </table>
                        </div>
                    `;

                    // Attach Copy Event Listeners
                    shortcodesBody.querySelectorAll('.scb-copy-btn').forEach(btn => {
                        btn.addEventListener('click', () => {
                            const code = btn.getAttribute('data-shortcode');
                            if (!code) return;
                            navigator.clipboard.writeText(code).then(() => {
                                btn.classList.add('copied');
                                btn.innerHTML = `<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg> Copied!`;
                                Toast.success(`Shortcode ${code} copied to clipboard!`);
                                setTimeout(() => {
                                    btn.classList.remove('copied');
                                    btn.innerHTML = `<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg> Copy`;
                                }, 2000);
                            }).catch(() => {
                                Toast.error('Could not copy', 'Please copy manually: ' + code);
                            });
                        });
                    });

                    // Attach Auto-create Pages Listener
                    const autoBtn = document.getElementById('scb-auto-create-pages-btn');
                    if (autoBtn) {
                        autoBtn.onclick = async () => {
                            if (!confirm('This will automatically create and publish WordPress pages for any missing booking shortcodes. Continue?')) {
                                return;
                            }
                            autoBtn.disabled = true;
                            autoBtn.innerHTML = '<span class="scb-spinner" style="width:14px;height:14px;display:inline-block;vertical-align:middle;margin-right:6px;"></span> Creating…';
                            try {
                                const createRes = await Api.post('settings/create-pages', {});
                                Toast.success(createRes.message || 'Pages created successfully!');
                                await this.loadSettings();
                            } catch (err) {
                                Toast.error('Failed to create pages', err.message);
                                autoBtn.disabled = false;
                                autoBtn.innerText = 'Auto-Create All Booking Pages';
                            }
                        };
                    }
                }

                body.innerHTML = `
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:24px;">
                        <div>
                            <h3 style="margin-top:0;font-size:15px;color:var(--scb-primary);border-bottom:1px solid var(--scb-border);padding-bottom:8px;">General Club Settings</h3>
                            <div class="scb-form-group">
                                <label class="scb-form-label">Club Name</label>
                                <input type="text" class="scb-input" id="scb-set-club-name" value="${Utils.escHtml(s.club_name || 'Squash Club')}">
                            </div>
                            <div class="scb-form-group">
                                <label class="scb-form-label">Currency</label>
                                <input type="text" class="scb-input" id="scb-set-currency" value="${Utils.escHtml(s.currency || 'USD')}">
                            </div>
                            <div class="scb-form-group">
                                <label class="scb-form-label">Require Admin Approval Before Booking Confirmation</label>
                                <select class="scb-select" id="scb-set-approval">
                                    <option value="0" ${!s.require_admin_approval ? 'selected' : ''}>No — Auto-approve bookings immediately</option>
                                    <option value="1" ${s.require_admin_approval ? 'selected' : ''}>Yes — Keep bookings as Pending until admin review</option>
                                </select>
                            </div>

                            <h3 style="margin-top:24px;font-size:15px;color:var(--scb-primary);border-bottom:1px solid var(--scb-border);padding-bottom:8px;">Sunday Clinics</h3>
                            <div class="scb-form-group">
                                <label class="scb-form-label">Default Slot Capacity</label>
                                <input type="number" class="scb-input" id="scb-set-clinic-cap" value="${s.clinic_capacity_default || 15}">
                            </div>
                            <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
                                <div class="scb-form-group">
                                    <label class="scb-form-label">Junior Price ($)</label>
                                    <input type="number" step="0.5" class="scb-input" id="scb-set-clinic-jr-price" value="${s.clinic_price_junior || 25.00}">
                                </div>
                                <div class="scb-form-group">
                                    <label class="scb-form-label">Adult Price ($)</label>
                                    <input type="number" step="0.5" class="scb-input" id="scb-set-clinic-ad-price" value="${s.clinic_price_adult || 30.00}">
                                </div>
                            </div>
                        </div>

                        <div>
                            <h3 style="margin-top:0;font-size:15px;color:var(--scb-primary);border-bottom:1px solid var(--scb-border);padding-bottom:8px;">Private & Semi-Private Lessons</h3>
                            <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
                                <div class="scb-form-group">
                                    <label class="scb-form-label">Private Lesson Price ($)</label>
                                    <input type="number" step="0.5" class="scb-input" id="scb-set-les-priv-price" value="${s.lesson_price_private || 80.00}">
                                </div>
                                <div class="scb-form-group">
                                    <label class="scb-form-label">Semi-Private Price ($)</label>
                                    <input type="number" step="0.5" class="scb-input" id="scb-set-les-semi-price" value="${s.lesson_price_semi_private || 40.00}">
                                </div>
                            </div>
                            <div class="scb-form-group">
                                <label class="scb-form-label">Lesson Duration (Minutes)</label>
                                <input type="number" class="scb-input" id="scb-set-les-dur" value="${s.lesson_duration_default || 60}">
                            </div>

                            <h3 style="margin-top:24px;font-size:15px;color:var(--scb-primary);border-bottom:1px solid var(--scb-border);padding-bottom:8px;">Notifications</h3>
                            <div class="scb-form-group">
                                <label class="scb-form-label">Enable Automated Email Notifications</label>
                                <select class="scb-select" id="scb-set-notif-en">
                                    <option value="1" ${s.enable_notifications ? 'selected' : ''}>Yes — Send emails to customers & admins</option>
                                    <option value="0" ${!s.enable_notifications ? 'selected' : ''}>No — Disable outgoing emails</option>
                                </select>
                            </div>
                            <div class="scb-form-group">
                                <label class="scb-form-label">Admin Notification Recipient Email</label>
                                <input type="email" class="scb-input" id="scb-set-admin-email" value="${Utils.escHtml(s.admin_notification_email || '')}">
                            </div>
                        </div>
                    </div>
                `;

            } catch (err) {
                body.innerHTML = '<p style="color:var(--scb-error);">Failed to load settings.</p>';
            }
        },

        async saveSettings() {
            Toast.info('Saving settings…');
            try {
                await Api.post('settings', {
                    club_name: document.getElementById('scb-set-club-name')?.value,
                    currency: document.getElementById('scb-set-currency')?.value,
                    require_admin_approval: document.getElementById('scb-set-approval')?.value === '1',
                    clinic_capacity_default: parseInt(document.getElementById('scb-set-clinic-cap')?.value, 10),
                    clinic_price_junior: parseFloat(document.getElementById('scb-set-clinic-jr-price')?.value),
                    clinic_price_adult: parseFloat(document.getElementById('scb-set-clinic-ad-price')?.value),
                    lesson_price_private: parseFloat(document.getElementById('scb-set-les-priv-price')?.value),
                    lesson_price_semi_private: parseFloat(document.getElementById('scb-set-les-semi-price')?.value),
                    lesson_duration_default: parseInt(document.getElementById('scb-set-les-dur')?.value, 10),
                    enable_notifications: document.getElementById('scb-set-notif-en')?.value === '1',
                    admin_notification_email: document.getElementById('scb-set-admin-email')?.value,
                });
                Toast.success('Settings saved successfully.');
            } catch (err) {
                Toast.error('Could not save settings', err.message);
            }
        },

        openResetModal() {
            Modal.open({
                title: '⚠️ Confirm Database Table Reset',
                content: `
                    <div style="padding:4px 0;">
                        <p style="margin:0 0 14px;color:#dc2626;font-weight:600;font-size:15px;line-height:1.5;">
                            Are you absolutely sure you want to erase all plugin data?
                        </p>
                        <p style="margin:0 0 14px;color:var(--scb-text-muted);font-size:13px;line-height:1.6;">
                            This will permanently truncate and delete all bookings, clinic slots, coaches, waitlists, camps, and audit records from the database. The plugin will be returned to a completely fresh installation state.
                        </p>
                        <p style="margin:0;color:#991b1b;background:#fee2e2;padding:10px 12px;border-radius:6px;font-size:12px;font-weight:600;">
                            ⚠️ Warning: This action is permanent and cannot be undone!
                        </p>
                    </div>
                `,
                footer: `
                    <button class="scb-btn scb-btn--secondary" data-modal-close>Cancel</button>
                    <button class="scb-btn" id="scb-confirm-reset-btn" style="background:#dc2626;color:#fff;border:none;font-weight:600;padding:8px 16px;border-radius:6px;cursor:pointer;">
                        Yes, Reset All Data
                    </button>
                `
            });

            document.getElementById('scb-confirm-reset-btn')?.addEventListener('click', async () => {
                const btn = document.getElementById('scb-confirm-reset-btn');
                if (btn) {
                    btn.disabled = true;
                    btn.textContent = 'Resetting Database…';
                }
                try {
                    const res = await Api.post('settings/reset-tables', {});
                    Modal.close();
                    Toast.success(res.message || 'All plugin data has been reset to fresh install.');
                    setTimeout(() => {
                        window.location.hash = '#dashboard';
                        window.location.reload();
                    }, 1200);
                } catch (err) {
                    if (btn) {
                        btn.disabled = false;
                        btn.textContent = 'Yes, Reset All Data';
                    }
                    Toast.error('Reset failed', err.message);
                }
            });
        }
    };

    // ── View Router ──────────────────────────────────────────────────────────
    const views = {
        dashboard: DashboardView,
        bookings: BookingsView,
        clinics: ClinicsView,
        'private-lessons': PrivateLessonsView,
        coaches: CoachesView,
        schedule: ScheduleView,
        waitlist: WaitlistView,
        camps: CampsView,
        registrations: RegistrationsView,
        payments: PaymentsView,
        customers: CustomersView,
        notifications: NotificationsView,
        reports: ReportsView,
        settings: SettingsView,
    };

    // ── Application Bootstrap ────────────────────────────────────────────────
    const App = {
        currentView: null,

        init() {
            this.app = document.getElementById('scb-app');
            if (!this.app) return;

            Toast.init();
            Modal.init();

            this._initSidebar();
            this._initTopbar();
            this._initNotices();
            this._loadCurrentView();
        },

        _initNotices() {
            this._relocateNotices();

            // Observe #scb-app for notices injected dynamically
            if (window.MutationObserver && this.app) {
                const observer = new MutationObserver((mutations) => {
                    for (const mutation of mutations) {
                        for (const node of mutation.addedNodes) {
                            if (node.nodeType === 1) {
                                if (node.matches?.('.notice, div.error, div.updated, .elementor-message') ||
                                    node.querySelector?.('.notice, div.error, div.updated, .elementor-message')) {
                                    this._relocateNotices();
                                    return;
                                }
                            }
                        }
                    }
                });
                observer.observe(this.app, { childList: true, subtree: true });
            }

            // Delayed checks for late-running scripts (e.g. WP common.js, Elementor)
            setTimeout(() => this._relocateNotices(), 150);
            setTimeout(() => this._relocateNotices(), 600);
        },

        _relocateNotices() {
            const target = document.getElementById('scb-admin-notices');
            const app = document.getElementById('scb-app');
            if (!target || !app) return;

            // Relocate notices trapped inside #scb-app
            const trapped = app.querySelectorAll('.notice, div.error, div.updated, .elementor-message');
            trapped.forEach(n => {
                target.appendChild(n);
            });

            // Relocate stray notices from #wpbody-content into #scb-admin-notices
            const wpbody = document.getElementById('wpbody-content');
            if (wpbody) {
                Array.from(wpbody.children).forEach(child => {
                    if (child.id !== 'scb-app' && !child.classList.contains('scb-admin-wrap') &&
                        (child.classList.contains('notice') || child.classList.contains('error') || child.classList.contains('updated') || child.classList.contains('elementor-message'))) {
                        target.appendChild(child);
                    }
                });
            }
        },

        _initSidebar() {
            // Collapse toggle.
            document.getElementById('scb-sidebar-toggle')?.addEventListener('click', () => {
                this.app.classList.toggle('scb-sidebar-collapsed');
                localStorage.setItem('scb_sidebar_collapsed', this.app.classList.contains('scb-sidebar-collapsed') ? '1' : '');
            });

            // Restore collapse state.
            if (localStorage.getItem('scb_sidebar_collapsed')) {
                this.app.classList.add('scb-sidebar-collapsed');
            }

            // Active state.
            const currentPage = this.app.dataset.page;
            document.querySelectorAll('.scb-sidebar__link').forEach(link => {
                if (link.dataset.page === currentPage) {
                    link.classList.add('is-active');
                    link.closest('.scb-sidebar__item')?.classList.add('is-active');
                }
            });
        },

        _initTopbar() {
            // Mobile menu toggle.
            document.getElementById('scb-mobile-menu-toggle')?.addEventListener('click', () => {
                this.app.classList.toggle('scb-mobile-menu-open');
            });

            // Refresh button.
            document.getElementById('scb-refresh-btn')?.addEventListener('click', () => {
                this._loadCurrentView();
            });
        },

        async _loadCurrentView() {
            const viewName = this.app.dataset.view || 'dashboard';
            const view = views[viewName];
            const content = document.getElementById('scb-content');

            // Hide initial loader.
            const loader = document.getElementById('scb-initial-loader');
            if (loader) loader.style.display = 'none';

            if (!view || !content) {
                content.innerHTML = `<div class="scb-alert scb-alert--error">View not found: ${Utils.escHtml(viewName)}</div>`;
                return;
            }

            // Show inline spinner.
            content.innerHTML = '<div class="scb-loading-overlay"><div class="scb-spinner scb-spinner--lg"></div></div>';

            try {
                await view.render(content);
                this._relocateNotices();
                this._updateBreadcrumb(viewName);
                this.currentView = view;
            } catch (err) {
                content.innerHTML = `<div class="scb-alert scb-alert--error">Error loading view: ${Utils.escHtml(err.message)}</div>`;
                console.error('[SCB] View error:', err);
            }
        },

        _updateBreadcrumb(viewName) {
            const breadcrumbLabels = {
                dashboard: 'Overview',
                bookings: 'All Bookings',
                clinics: 'Sunday Clinics',
                'private-lessons': 'Private Lessons',
                coaches: 'Coaches',
                schedule: 'Schedule & Calendar',
                waitlist: 'Waitlist',
                camps: 'Summer Camps',
                registrations: 'Registrations',
                payments: 'Payments',
                customers: 'Customers',
                notifications: 'Notifications',
                reports: 'Reports',
                settings: 'Settings',
            };

            const bc = document.getElementById('scb-breadcrumbs');
            if (!bc) return;

            const label = breadcrumbLabels[viewName] || viewName;
            bc.innerHTML = `
                <span class="scb-breadcrumb">Squash Club Booking</span>
                <span class="scb-breadcrumb-sep">›</span>
                <span class="scb-breadcrumb">${Utils.escHtml(label)}</span>
            `;
        },
    };

    // ── Public API ───────────────────────────────────────────────────────────
    return { App, Toast, Modal, Api, Utils, ViewUtils };

})(jQuery);

// Boot on DOM ready.
document.addEventListener('DOMContentLoaded', () => SCB.App.init());
