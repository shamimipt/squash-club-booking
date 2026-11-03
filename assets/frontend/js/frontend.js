/**
 * Squash Club Booking — Frontend Interactive App
 * Version 1.0.0
 */

/* global scbFrontend, jQuery */
'use strict';

(function ($) {
    const CONFIG = {
        restUrl: (typeof scbFrontend !== 'undefined' && scbFrontend.restUrl) ? scbFrontend.restUrl : '/wp-json/scb/v1/',
        nonce: (typeof scbFrontend !== 'undefined' && scbFrontend.nonce) ? scbFrontend.nonce : '',
        restNonce: (typeof scbFrontend !== 'undefined' && scbFrontend.restNonce) ? scbFrontend.restNonce : '',
        isLoggedIn: (typeof scbFrontend !== 'undefined' && scbFrontend.isLoggedIn) ? Boolean(scbFrontend.isLoggedIn) : false,
        currentUser: (typeof scbFrontend !== 'undefined' && scbFrontend.currentUser) ? scbFrontend.currentUser : null,
        loginUrl: (typeof scbFrontend !== 'undefined' && scbFrontend.loginUrl) ? scbFrontend.loginUrl : '/my-account/',
        registerUrl: (typeof scbFrontend !== 'undefined' && scbFrontend.registerUrl) ? scbFrontend.registerUrl : '/my-account/',
        privatePrice: (typeof scbFrontend !== 'undefined' && scbFrontend.privatePrice) ? scbFrontend.privatePrice : 80,
        semiPrivatePrice: (typeof scbFrontend !== 'undefined' && scbFrontend.semiPrivatePrice) ? scbFrontend.semiPrivatePrice : 40,
        clinicPriceJunior: (typeof scbFrontend !== 'undefined' && scbFrontend.clinicPriceJunior) ? scbFrontend.clinicPriceJunior : 25,
        clinicPriceAdult: (typeof scbFrontend !== 'undefined' && scbFrontend.clinicPriceAdult) ? scbFrontend.clinicPriceAdult : 30,
    };

    /**
     * Safely construct a full REST URL handling both pretty and plain permalinks.
     */
    function buildRestUrl(endpoint) {
        let base = CONFIG.restUrl || '/wp-json/scb/v1/';
        let clean = String(endpoint || '').replace(/^\/+/, '');
        let path = clean;
        let query = '';
        const qIndex = clean.indexOf('?');
        if (qIndex !== -1) {
            path = clean.substring(0, qIndex);
            query = clean.substring(qIndex + 1);
        }
        let url = base + path;
        if (query) {
            url += (url.includes('?') ? '&' : '?') + query;
        }
        return url;
    }

    /**
     * Enforce login requirement: no guest bookings allowed.
     * Displays a clean Auth modal prompt if user is not logged in.
     */
    function requireAuth(actionLabel = 'make a booking') {
        if (CONFIG.isLoggedIn) {
            return true;
        }

        let modal = document.getElementById('scb-auth-modal-overlay');
        if (!modal) {
            modal = document.createElement('div');
            modal.id = 'scb-auth-modal-overlay';
            modal.className = 'scb-fe-modal-overlay';
            modal.innerHTML = `
                <div class="scb-fe-modal" style="max-width:440px;text-align:center;padding:36px 28px;position:relative;">
                    <button type="button" class="scb-fe-modal__close" id="scb-auth-modal-close" style="position:absolute;top:16px;right:16px;background:none;border:none;font-size:24px;cursor:pointer;">&times;</button>
                    <div style="font-size:44px;margin-bottom:12px;">🔒</div>
                    <h3 style="margin:0 0 10px;font-size:22px;font-weight:700;color:var(--scb-fe-navy);">Account Required</h3>
                    <p style="color:#64748b;font-size:15px;line-height:1.5;margin-bottom:24px;">
                        Guest bookings are not allowed. You must log in or create an account to ${actionLabel} and confirm your spot.
                    </p>
                    <div style="display:flex;flex-direction:column;gap:12px;">
                        <a href="${CONFIG.loginUrl}" class="scb-fe-btn scb-fe-btn--primary" style="display:block;text-align:center;padding:12px;font-weight:700;text-decoration:none;border-radius:8px;">
                            Log In to Your Account
                        </a>
                        <a href="${CONFIG.registerUrl}" class="scb-fe-btn scb-fe-btn--navy" style="display:block;text-align:center;padding:12px;font-weight:600;text-decoration:none;border-radius:8px;">
                            Create New Account
                        </a>
                    </div>
                </div>
            `;
            document.body.appendChild(modal);

            document.getElementById('scb-auth-modal-close').addEventListener('click', () => {
                modal.classList.remove('is-active');
            });
            modal.addEventListener('click', (e) => {
                if (e.target === modal) modal.classList.remove('is-active');
            });
        }

        modal.classList.add('is-active');
        return false;
    }

    const Utils = {
        formatDate(dateStr) {
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
                return d.toLocaleDateString('en-US', { weekday: 'short', month: 'short', day: 'numeric', year: 'numeric' });
            } catch (e) {
                return dateStr;
            }
        },
        formatCurrency(amount) {
            return '$' + (parseFloat(amount) || 0).toFixed(2);
        },
        escHtml(str) {
            const div = document.createElement('div');
            div.appendChild(document.createTextNode(String(str ?? '')));
            return div.innerHTML;
        }
    };

    // ── Toast Notification System ────────────────────────────────────────────
    const Toast = {
        container: null,
        show(title, msg = '', type = 'info') {
            if (!this.container) {
                this.container = document.getElementById('scb-fe-toast-container');
                if (!this.container) {
                    this.container = document.createElement('div');
                    this.container.id = 'scb-fe-toast-container';
                    this.container.className = 'scb-fe-toast-container';
                    document.body.appendChild(this.container);
                }
            }
            const el = document.createElement('div');
            el.className = `scb-fe-toast scb-fe-toast--${type}`;
            el.innerHTML = `
                <div class="scb-fe-toast__icon">
                    ${type === 'success' ? '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>' :
                    type === 'error' ? '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>' :
                        '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="8"/><line x1="12" y1="12" x2="12" y2="16"/></svg>'}
                </div>
                <div class="scb-fe-toast__content">
                    <div class="scb-fe-toast__title">${Utils.escHtml(title)}</div>
                    ${msg ? `<div class="scb-fe-toast__msg">${Utils.escHtml(msg)}</div>` : ''}
                </div>
                <button class="scb-fe-toast__close" aria-label="Close notification">&times;</button>
            `;
            el.querySelector('.scb-fe-toast__close')?.addEventListener('click', () => {
                el.classList.add('is-leaving');
                el.addEventListener('animationend', () => el.remove(), { once: true });
            });
            this.container.appendChild(el);
            setTimeout(() => {
                if (el.parentElement) {
                    el.classList.add('is-leaving');
                    el.addEventListener('animationend', () => el.remove(), { once: true });
                }
            }, 5000);
        },
        success(title, msg = '') { this.show(title, msg, 'success'); },
        error(title, msg = '') { this.show(title, msg, 'error'); },
        warning(title, msg = '') { this.show(title, msg, 'warning'); },
        info(title, msg = '') { this.show(title, msg, 'info'); },
    };

    // ── 1. Sunday Clinic Booking Widget ──────────────────────────────────────
    const ClinicBooking = {
        slots: [],
        selectedDate: '',

        async init() {
            const el = document.getElementById('scb-clinic-booking');
            if (!el) return;

            el.innerHTML = '<div style="text-align:center;padding:40px;color:#64748b;">Loading available clinic sessions…</div>';

            try {
                const res = await fetch(CONFIG.restUrl + 'clinic-slots/public');
                const data = await res.json();
                this.slots = data?.data?.slots || [];

                if (!this.slots.length) {
                    el.innerHTML = `
                        <div class="scb-status-card">
                            <h3 style="margin-top:0;">No Upcoming Clinic Slots Available</h3>
                            <p style="color:#64748b;">New Sunday clinic sessions are generated every week. Please check back soon or contact club administration.</p>
                        </div>
                    `;
                    return;
                }

                // Get distinct dates.
                const dates = [...new Set(this.slots.map(s => s.slot_date))].sort();
                this.selectedDate = dates[0];

                this.render(el, dates);

            } catch (err) {
                el.innerHTML = `<div class="scb-status-card"><p style="color:#ef4444;">Could not load clinic slots. Please refresh the page.</p></div>`;
            }
        },

        render(container, dates) {
            container.innerHTML = `
                <div class="scb-date-pills" id="scb-fe-clinic-dates">
                    ${dates.map((d, idx) => `
                        <button class="scb-date-pill ${idx === 0 ? 'is-active' : ''}" data-date="${d}">
                            ${Utils.formatDate(d)}
                        </button>
                    `).join('')}
                </div>

                <div class="scb-grid-cards" id="scb-fe-slots-container"></div>

                <div class="scb-fe-modal-overlay" id="scb-clinic-modal-overlay">
                    <div class="scb-fe-modal">
                        <div class="scb-fe-modal__header">
                            <h3 class="scb-fe-modal__title" id="scb-clinic-modal-title">Book Clinic Session</h3>
                            <button class="scb-fe-modal__close" id="scb-clinic-modal-close">&times;</button>
                        </div>
                        <form id="scb-clinic-form">
                            <input type="hidden" id="scb-form-slot-id">
                            <input type="hidden" id="scb-form-join-waitlist" value="0">

                            <div class="scb-fe-form-group">
                                <label class="scb-fe-label">Your Name (Parent or Player) *</label>
                                <input type="text" class="scb-fe-input" id="scb-c-name" required placeholder="John Doe">
                            </div>
                            <div class="scb-fe-form-group">
                                <label class="scb-fe-label">Email Address *</label>
                                <input type="email" class="scb-fe-input" id="scb-c-email" required placeholder="john@example.com">
                            </div>
                            <div class="scb-fe-form-group">
                                <label class="scb-fe-label">Phone Number *</label>
                                <input type="tel" class="scb-fe-input" id="scb-c-phone" required placeholder="(555) 000-0000">
                            </div>
                            <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
                                <div class="scb-fe-form-group">
                                    <label class="scb-fe-label">Participant Name</label>
                                    <input type="text" class="scb-fe-input" id="scb-p-name" placeholder="Same as above if yourself">
                                </div>
                                <div class="scb-fe-form-group">
                                    <label class="scb-fe-label">Skill Level</label>
                                    <select class="scb-fe-select" id="scb-p-skill">
                                        <option value="beginner">Beginner</option>
                                        <option value="intermediate" selected>Intermediate</option>
                                        <option value="advanced">Advanced</option>
                                    </select>
                                </div>
                            </div>
                            <div class="scb-fe-form-group">
                                <label class="scb-fe-label">Notes or Requests (Optional)</label>
                                <textarea class="scb-fe-textarea" id="scb-c-notes" rows="2" placeholder="Any special requests or racket hire needs?"></textarea>
                            </div>

                            <div id="scb-clinic-fee-box" style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:12px;margin-bottom:16px;display:flex;justify-content:space-between;align-items:center;">
                                <span style="font-weight:600;">Total Fee:</span>
                                <span style="font-size:18px;font-weight:800;color:var(--scb-fe-teal);" id="scb-clinic-modal-fee">$25.00</span>
                            </div>

                            <button type="submit" class="scb-fe-btn scb-fe-btn--primary scb-fe-btn--full" id="scb-clinic-submit-btn">
                                Complete Booking
                            </button>
                        </form>
                    </div>
                </div>
            `;

            // Bind date pills.
            const pills = container.querySelectorAll('.scb-date-pill');
            pills.forEach(p => {
                p.addEventListener('click', () => {
                    pills.forEach(x => x.classList.remove('is-active'));
                    p.classList.add('is-active');
                    this.selectedDate = p.dataset.date;
                    this.renderSlotsForDate();
                });
            });

            // Modal close.
            const overlay = document.getElementById('scb-clinic-modal-overlay');
            document.getElementById('scb-clinic-modal-close').addEventListener('click', () => overlay.classList.remove('is-active'));
            overlay.addEventListener('click', (e) => {
                if (e.target === overlay) overlay.classList.remove('is-active');
            });

            // Form submission.
            document.getElementById('scb-clinic-form').addEventListener('submit', (e) => this.submitBooking(e));

            this.renderSlotsForDate();
        },

        renderSlotsForDate() {
            const container = document.getElementById('scb-fe-slots-container');
            if (!container) return;

            const dateSlots = this.slots.filter(s => s.slot_date === this.selectedDate);

            if (!dateSlots.length) {
                container.innerHTML = `<p style="grid-column:1/-1;text-align:center;color:#64748b;">No sessions available for this Sunday.</p>`;
                return;
            }

            container.innerHTML = dateSlots.map(s => {
                const booked = parseInt(s.booked_count, 10) || 0;
                const cap = parseInt(s.capacity, 10) || 15;
                const isFull = booked >= cap || s.status === 'full';
                const spotsLeft = Math.max(0, cap - booked);

                return `
                    <div class="scb-fe-card">
                        <div>
                            <div class="scb-fe-card__badge-bar">
                                <span class="scb-fe-badge scb-fe-badge--${s.category}">${s.category === 'adult' ? 'Adult Clinic' : 'Junior Clinic'}</span>
                                <span class="scb-fe-badge scb-fe-badge--${isFull ? 'full' : 'open'}">${isFull ? 'Waitlist Open' : spotsLeft + ' spots left'}</span>
                            </div>
                            <div class="scb-fe-card__time">${s.start_time.substring(0, 5)} - ${s.end_time.substring(0, 5)}</div>
                            <div class="scb-fe-card__title">${Utils.escHtml(s.notes || (s.category === 'adult' ? 'Adult Masterclass' : 'Junior Academy Session'))}</div>
                            <div class="scb-fe-card__meta">
                                <div>Capacity: 15 players maximum</div>
                                ${s.waitlist_count > 0 ? `<div style="color:var(--scb-fe-warning);font-weight:600;margin-top:4px;">${s.waitlist_count} on waitlist</div>` : ''}
                            </div>
                        </div>

                        <div>
                            <div class="scb-fe-card__price-row">
                                <span style="font-size:13px;color:var(--scb-fe-text-muted);">Session Fee</span>
                                <span class="scb-fe-card__price">${Utils.formatCurrency(s.price)}</span>
                            </div>
                            <button class="scb-fe-btn ${isFull ? 'scb-fe-btn--warning' : 'scb-fe-btn--primary'} scb-fe-btn--full scb-open-clinic-modal" data-id="${s.id}" data-price="${s.price}" data-full="${isFull ? '1' : '0'}" data-title="${s.category === 'adult' ? 'Adult Clinic' : 'Junior Clinic'} — ${s.start_time.substring(0, 5)}" style="margin-top:12px;">
                                ${isFull ? 'Join Waitlist' : 'Book Spot'}
                            </button>
                        </div>
                    </div>
                `;
            }).join('');

            container.querySelectorAll('.scb-open-clinic-modal').forEach(btn => {
                btn.addEventListener('click', () => {
                    if (!requireAuth('book a clinic session')) {
                        return;
                    }

                    const id = btn.dataset.id;
                    const price = btn.dataset.price;
                    const isFull = btn.dataset.full === '1';
                    const title = btn.dataset.title;

                    document.getElementById('scb-form-slot-id').value = id;
                    document.getElementById('scb-form-join-waitlist').value = isFull ? '1' : '0';
                    document.getElementById('scb-clinic-modal-title').textContent = isFull ? 'Join Waitlist — ' + title : 'Book Spot — ' + title;
                    document.getElementById('scb-clinic-modal-fee').textContent = Utils.formatCurrency(price);
                    document.getElementById('scb-clinic-submit-btn').textContent = parseFloat(price) > 0 
                        ? (isFull ? 'Pay & Join Waitlist' : 'Proceed to Payment') 
                        : (isFull ? 'Join Waitlist' : 'Proceed to Confirmation');

                    if (CONFIG.currentUser) {
                        if (document.getElementById('scb-c-name')) document.getElementById('scb-c-name').value = CONFIG.currentUser.name || '';
                        if (document.getElementById('scb-c-email')) {
                            document.getElementById('scb-c-email').value = CONFIG.currentUser.email || '';
                            document.getElementById('scb-c-email').readOnly = true;
                        }
                        if (document.getElementById('scb-c-phone')) document.getElementById('scb-c-phone').value = CONFIG.currentUser.phone || '';
                        if (document.getElementById('scb-p-name')) document.getElementById('scb-p-name').value = CONFIG.currentUser.name || '';
                    }

                    document.getElementById('scb-clinic-modal-overlay').classList.add('is-active');
                });
            });
        },

        async submitBooking(e) {
            e.preventDefault();
            const btn = document.getElementById('scb-clinic-submit-btn');
            btn.disabled = true;
            btn.textContent = 'Processing…';

            const slotId = document.getElementById('scb-form-slot-id').value;
            const isWaitlist = document.getElementById('scb-form-join-waitlist').value === '1';

            try {
                const headers = { 'Content-Type': 'application/json' };
                if (CONFIG.restNonce) {
                    headers['X-WP-Nonce'] = CONFIG.restNonce;
                }
                const res = await fetch(CONFIG.restUrl + 'book/clinic', {
                    method: 'POST',
                    headers: headers,
                    body: JSON.stringify({
                        slot_id: slotId,
                        customer_name: document.getElementById('scb-c-name').value,
                        customer_email: document.getElementById('scb-c-email').value,
                        customer_phone: document.getElementById('scb-c-phone').value,
                        participant_first_name: document.getElementById('scb-p-name').value,
                        skill_level: document.getElementById('scb-p-skill').value,
                        notes: document.getElementById('scb-c-notes').value,
                        join_waitlist: isWaitlist,
                    })
                });

                let data = null;
                try {
                    data = await res.json();
                } catch (jsonErr) {
                    data = null;
                }

                if (!res.ok || !data?.success) {
                    const errorMsg = data?.message || data?.data?.message || 'Booking submission failed. Please try again.';
                    Toast.error('Booking Error', errorMsg);
                    btn.disabled = false;
                    btn.textContent = isWaitlist ? 'Pay & Join Waitlist' : 'Proceed to Payment';
                    return;
                }

                document.getElementById('scb-clinic-modal-overlay').classList.remove('is-active');
                Toast.success(isWaitlist ? 'Waitlist Confirmed' : 'Booking Confirmed!', data.message || 'Your booking has been received.');

                if (data.checkout_url) {
                    window.location.href = data.checkout_url;
                    return;
                }

                const el = document.getElementById('scb-clinic-booking');
                el.innerHTML = `
                    <div class="scb-status-card">
                        <div class="scb-status-card__icon scb-status-card__icon--success">
                            <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
                        </div>
                        <h2 style="font-size:24px;margin-bottom:8px;">${data.is_waitlist ? 'Added to Waitlist!' : 'Booking Confirmed!'}</h2>
                        <p style="color:var(--scb-fe-text-muted);font-size:16px;line-height:1.6;margin-bottom:20px;">${Utils.escHtml(data.message)}</p>
                        <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:16px;max-width:360px;margin:0 auto 24px;">
                            <div style="font-size:12px;color:#64748b;">Booking Reference</div>
                            <div style="font-size:20px;font-weight:800;color:var(--scb-fe-navy);">${Utils.escHtml(data.booking_ref)}</div>
                        </div>
                        <a href="javascript:location.reload()" class="scb-fe-btn scb-fe-btn--navy">Book Another Session</a>
                    </div>
                `;

            } catch (err) {
                Toast.error('Connection Error', err.message || 'Booking submission failed. Please try again.');
                btn.disabled = false;
                btn.textContent = isWaitlist ? 'Join Waitlist' : 'Proceed to Confirmation';
            }
        }
    };

    // ── 2. Private & Semi-Private Lessons Widget ─────────────────────────────
    const LessonBooking = {
        coaches: [],
        selectedCoachId: null,
        selectedDate: '',
        selectedType: 'private',
        selectedTime: '',

        async init() {
            const el = document.getElementById('scb-lesson-booking');
            if (!el) return;

            el.innerHTML = '<div style="text-align:center;padding:40px;color:#64748b;">Loading coach availability…</div>';

            try {
                const res = await fetch(CONFIG.restUrl + 'coaches/public');
                const data = await res.json();
                this.coaches = data?.data?.coaches || [];

                if (!this.coaches.length) {
                    el.innerHTML = '<div class="scb-status-card"><p>No coaches are currently available for online booking.</p></div>';
                    return;
                }

                this.selectedCoachId = this.coaches[0].id;
                this.render(el);

            } catch (err) {
                el.innerHTML = '<div class="scb-status-card"><p style="color:#ef4444;">Could not load coaches. Please refresh.</p></div>';
            }
        },

        render(container) {
            container.innerHTML = `
                <div style="margin-bottom:28px;">
                    <h3 style="font-size:16px;font-weight:700;margin-bottom:14px;">Step 1: Choose Your Coach</h3>
                    <div class="scb-coaches-grid" id="scb-coaches-list">
                        ${this.coaches.map(c => `
                            <div class="scb-coach-card ${c.id === this.selectedCoachId ? 'is-selected' : ''}" data-id="${c.id}">
                                ${c.photo_url 
                                    ? `<img src="${Utils.escHtml(c.photo_url)}" alt="${Utils.escHtml(c.first_name)}" class="scb-coach-card__avatar">`
                                    : `<div class="scb-coach-card__avatar-placeholder">${Utils.escHtml((c.first_name?.[0] || 'C') + (c.last_name?.[0] || ''))}</div>`
                                }
                                <div class="scb-coach-card__content">
                                    <span class="scb-coach-card__badge">Coach</span>
                                    <div class="scb-coach-card__name">${Utils.escHtml(c.first_name)} ${Utils.escHtml(c.last_name)}</div>
                                    <div class="scb-coach-card__specialties">${Utils.escHtml(c.specialties || '')}</div>
                                    <div class="scb-coach-card__rate" style="font-size:11px;font-weight:700;color:var(--scb-fe-navy, #0f172a);margin-top:4px;">1-on-1: $${(c.hourly_rate && parseFloat(c.hourly_rate) > 0) ? parseFloat(c.hourly_rate) : CONFIG.privatePrice}/hr · Semi: $${(c.semi_private_rate && parseFloat(c.semi_private_rate) > 0) ? parseFloat(c.semi_private_rate) : CONFIG.semiPrivatePrice}/hr</div>
                                </div>
                            </div>
                        `).join('')}
                    </div>
                </div>

                <div style="background:#ffffff;border:1px solid #e2e8f0;border-radius:12px;padding:24px;box-shadow:var(--scb-fe-shadow);margin-bottom:28px;">
                    <h3 style="font-size:16px;font-weight:700;margin-top:0;margin-bottom:16px;">Step 2: Lesson Type & Date</h3>
                    
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:20px;">
                        <div>
                            <label class="scb-fe-label">Lesson Format</label>
                            <select class="scb-fe-select" id="scb-lesson-type-select">
                                <option value="private" selected>Private Lesson (1-on-1) — $${CONFIG.privatePrice}/hr</option>
                                <option value="semi_private">Semi-Private Lesson (2-on-1) — $${CONFIG.semiPrivatePrice}/hr</option>
                            </select>
                        </div>
                        <div>
                            <label class="scb-fe-label">Select Date (Saturdays)</label>
                            <input type="date" class="scb-fe-input" id="scb-lesson-date-input">
                        </div>
                    </div>

                    <h4 style="font-size:14px;font-weight:700;margin-bottom:8px;">Available Time Slots:</h4>
                    <div class="scb-time-slots-grid" id="scb-slots-picker">
                        <div style="color:#64748b;font-size:13px;">Pick a date to check coach availability.</div>
                    </div>
                </div>

                <div id="scb-lesson-booking-form-wrap" style="display:none;background:#ffffff;border:1px solid #e2e8f0;border-radius:12px;padding:24px;box-shadow:var(--scb-fe-shadow);">
                    <h3 style="font-size:16px;font-weight:700;margin-top:0;margin-bottom:16px;">Step 3: Player Information</h3>
                    <form id="scb-lesson-form">
                        <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;">
                            <div class="scb-fe-form-group">
                                <label class="scb-fe-label">Your Full Name *</label>
                                <input type="text" class="scb-fe-input" id="scb-les-name" required>
                            </div>
                            <div class="scb-fe-form-group">
                                <label class="scb-fe-label">Email Address *</label>
                                <input type="email" class="scb-fe-input" id="scb-les-email" required>
                            </div>
                        </div>
                        <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;">
                            <div class="scb-fe-form-group">
                                <label class="scb-fe-label">Phone Number *</label>
                                <input type="tel" class="scb-fe-input" id="scb-les-phone" required>
                            </div>
                            <div class="scb-fe-form-group">
                                <label class="scb-fe-label">Skill Level</label>
                                <select class="scb-fe-select" id="scb-les-skill">
                                    <option value="beginner">Beginner</option>
                                    <option value="intermediate" selected>Intermediate</option>
                                    <option value="advanced">Advanced Contender</option>
                                </select>
                            </div>
                        </div>
                        <div class="scb-fe-form-group" id="scb-part2-group" style="display:none;">
                            <label class="scb-fe-label">Second Player Name (Semi-Private) *</label>
                            <input type="text" class="scb-fe-input" id="scb-les-part2">
                        </div>
                        <div class="scb-fe-form-group">
                            <label class="scb-fe-label">Special Focus or Goals for this Session</label>
                            <textarea class="scb-fe-textarea" id="scb-les-notes" rows="2" placeholder="e.g., Focus on backhand boast technique and movement"></textarea>
                        </div>
                        <button type="submit" class="scb-fe-btn scb-fe-btn--primary scb-fe-btn--full" id="scb-les-submit-btn">Confirm Lesson Booking</button>
                    </form>
                </div>
            `;

            // Default date to next Saturday.
            const d = new Date();
            d.setDate(d.getDate() + ((6 - d.getDay() + 7) % 7 || 7));
            const yyyy = d.getFullYear();
            const mm = String(d.getMonth() + 1).padStart(2, '0');
            const dd = String(d.getDate()).padStart(2, '0');
            this.selectedDate = `${yyyy}-${mm}-${dd}`;

            const dateInput = document.getElementById('scb-lesson-date-input');
            dateInput.value = this.selectedDate;
            this.updatePriceOptions();

            // Bind coaches.
            container.querySelectorAll('.scb-coach-card').forEach(card => {
                card.addEventListener('click', () => {
                    container.querySelectorAll('.scb-coach-card').forEach(c => c.classList.remove('is-selected'));
                    card.classList.add('is-selected');
                    this.selectedCoachId = parseInt(card.dataset.id, 10);
                    this.updatePriceOptions();
                    this.loadCoachSlots();
                });
            });

            // Bind type & date change.
            document.getElementById('scb-lesson-type-select').addEventListener('change', (e) => {
                this.selectedType = e.target.value;
                document.getElementById('scb-part2-group').style.display = this.selectedType === 'semi_private' ? 'block' : 'none';
                this.loadCoachSlots();
            });

            dateInput.addEventListener('change', (e) => {
                this.selectedDate = e.target.value;
                this.loadCoachSlots();
            });

            document.getElementById('scb-lesson-form').addEventListener('submit', (e) => this.submitLesson(e));

            this.loadCoachSlots();
        },

        updatePriceOptions() {
            const select = document.getElementById('scb-lesson-type-select');
            if (!select) return;
            const coach = this.coaches.find(c => c.id == this.selectedCoachId);
            const privRate = (coach && coach.hourly_rate && parseFloat(coach.hourly_rate) > 0)
                ? parseFloat(coach.hourly_rate)
                : CONFIG.privatePrice;
            const semiRate = (coach && coach.semi_private_rate && parseFloat(coach.semi_private_rate) > 0)
                ? parseFloat(coach.semi_private_rate)
                : ((coach && coach.hourly_rate && parseFloat(coach.hourly_rate) > 0)
                    ? Math.round(privRate * 1.38)
                    : CONFIG.semiPrivatePrice);

            select.innerHTML = `
                <option value="private" ${this.selectedType === 'private' ? 'selected' : ''}>Private Lesson (1-on-1) — $${privRate}/hr</option>
                <option value="semi_private" ${this.selectedType === 'semi_private' ? 'selected' : ''}>Semi-Private Lesson (2-on-1) — $${semiRate}/hr</option>
            `;
        },

        async loadCoachSlots() {
            const picker = document.getElementById('scb-slots-picker');
            if (!picker) return;

            picker.innerHTML = '<div style="color:#64748b;">Checking availability…</div>';

            try {
                const res = await fetch(buildRestUrl(`coaches/${this.selectedCoachId}/available-slots?date=${this.selectedDate}&lesson_type=${this.selectedType}`));
                const data = await res.json();
                const slots = data?.data?.slots || [];

                if (!slots.length) {
                    picker.innerHTML = '<div style="color:#ef4444;font-size:13px;">No available hours for this coach on the selected date. Please pick another date or coach.</div>';
                    document.getElementById('scb-lesson-booking-form-wrap').style.display = 'none';
                    return;
                }

                picker.innerHTML = slots.map(s => `
                    <button type="button" class="scb-time-slot-btn" ${!s.available ? 'disabled' : ''} data-time="${s.time}">
                        ${s.label}
                    </button>
                `).join('');

                picker.querySelectorAll('.scb-time-slot-btn:not(:disabled)').forEach(btn => {
                    btn.addEventListener('click', () => {
                        if (!requireAuth('book a private lesson')) {
                            return;
                        }

                        picker.querySelectorAll('.scb-time-slot-btn').forEach(b => b.classList.remove('is-selected'));
                        btn.classList.add('is-selected');
                        this.selectedTime = btn.dataset.time;

                        if (CONFIG.currentUser) {
                            if (document.getElementById('scb-les-name')) document.getElementById('scb-les-name').value = CONFIG.currentUser.name || '';
                            if (document.getElementById('scb-les-email')) {
                                document.getElementById('scb-les-email').value = CONFIG.currentUser.email || '';
                                document.getElementById('scb-les-email').readOnly = true;
                            }
                            if (document.getElementById('scb-les-phone')) document.getElementById('scb-les-phone').value = CONFIG.currentUser.phone || '';
                            if (document.getElementById('scb-les-part-1')) document.getElementById('scb-les-part-1').value = CONFIG.currentUser.name || '';
                        }

                        document.getElementById('scb-lesson-booking-form-wrap').style.display = 'block';
                        document.getElementById('scb-lesson-booking-form-wrap').scrollIntoView({ behavior: 'smooth' });
                    });
                });

            } catch (err) {
                picker.innerHTML = '<div style="color:#ef4444;">Could not load slots.</div>';
            }
        },

        async submitLesson(e) {
            e.preventDefault();
            const btn = document.getElementById('scb-les-submit-btn');
            btn.disabled = true;
            btn.textContent = 'Reserving Lesson…';

            try {
                const headers = { 'Content-Type': 'application/json' };
                if (CONFIG.restNonce) {
                    headers['X-WP-Nonce'] = CONFIG.restNonce;
                }
                const res = await fetch(CONFIG.restUrl + 'book/lesson', {
                    method: 'POST',
                    headers: headers,
                    body: JSON.stringify({
                        coach_id: this.selectedCoachId,
                        lesson_type: this.selectedType,
                        lesson_date: this.selectedDate,
                        start_time: this.selectedTime,
                        customer_name: document.getElementById('scb-les-name').value,
                        customer_email: document.getElementById('scb-les-email').value,
                        customer_phone: document.getElementById('scb-les-phone').value,
                        participant_1: document.getElementById('scb-les-name').value,
                        participant_2: document.getElementById('scb-les-part2')?.value || '',
                        skill_level: document.getElementById('scb-les-skill').value,
                        notes: document.getElementById('scb-les-notes').value,
                    })
                });

                let data = null;
                try {
                    data = await res.json();
                } catch (jsonErr) {
                    data = null;
                }

                if (!res.ok || !data?.success) {
                    const errorMsg = data?.message || data?.data?.message || 'Could not complete booking.';
                    Toast.error('Booking Error', errorMsg);
                    btn.disabled = false;
                    btn.textContent = 'Confirm Lesson Booking';
                    return;
                }

                Toast.success('Lesson Reserved!', data.message || 'Your private lesson has been reserved.');

                if (data.checkout_url) {
                    window.location.href = data.checkout_url;
                    return;
                }

                const el = document.getElementById('scb-lesson-booking');
                el.innerHTML = `
                    <div class="scb-status-card">
                        <div class="scb-status-card__icon scb-status-card__icon--success">
                            <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
                        </div>
                        <h2 style="font-size:24px;margin-bottom:8px;">Lesson Reserved!</h2>
                        <p style="color:var(--scb-fe-text-muted);font-size:16px;line-height:1.6;margin-bottom:20px;">${Utils.escHtml(data.message)}</p>
                        <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:16px;max-width:360px;margin:0 auto 24px;">
                            <div style="font-size:12px;color:#64748b;">Booking Reference</div>
                            <div style="font-size:20px;font-weight:800;color:var(--scb-fe-navy);">${Utils.escHtml(data.booking_ref)}</div>
                        </div>
                        <a href="javascript:location.reload()" class="scb-fe-btn scb-fe-btn--navy">Book Another Lesson</a>
                    </div>
                `;

            } catch (err) {
                Toast.error('Connection Error', err.message || 'Lesson booking error. Please try again.');
                btn.disabled = false;
                btn.textContent = 'Confirm Lesson Booking';
            }
        }
    };

    // ── 3. Summer Camp Registration Widget ───────────────────────────────────
    const CampRegistration = {
        camps: [],

        async init() {
            const el = document.getElementById('scb-summer-camp') || document.getElementById('scb-camp-registration');
            if (!el) return;

            el.innerHTML = '<div style="text-align:center;padding:40px;color:#64748b;">Loading summer camp programs…</div>';

            try {
                const res = await fetch(CONFIG.restUrl + 'camps');
                const data = await res.json();
                this.camps = (data?.data?.items || []).filter(c => c.publication_status === 'published');

                if (!this.camps.length) {
                    el.innerHTML = '<div class="scb-status-card"><p>No summer camps are currently open for public registration.</p></div>';
                    return;
                }

                this.render(el);

            } catch (err) {
                el.innerHTML = '<div class="scb-status-card"><p style="color:#ef4444;">Failed to load summer camps.</p></div>';
            }
        },

        render(container) {
            container.innerHTML = `
                <div class="scb-grid-cards">
                    ${this.camps.map(c => `
                        <div class="scb-fe-card" style="padding:0;overflow:hidden;">
                            ${c.image_url ? `<img src="${Utils.escHtml(c.image_url)}" alt="${Utils.escHtml(c.title)}" style="width:100%;height:180px;object-fit:cover;">` : ''}
                            <div style="padding:22px;display:flex;flex-direction:column;flex:1;justify-content:space-between;">
                                <div>
                                    <div class="scb-fe-card__badge-bar">
                                        <span class="scb-fe-badge scb-fe-badge--junior">Ages ${c.min_age || 6}-${c.max_age || 18}</span>
                                        <span class="scb-fe-badge scb-fe-badge--open">${c.registered_count || 0} / ${c.capacity || 20} Registered</span>
                                    </div>
                                    <h3 style="margin:0 0 8px 0;font-size:18px;font-weight:700;">${Utils.escHtml(c.title)}</h3>
                                    <p style="font-size:16px;color:var(--scb-fe-text-muted);line-height:1.6;margin-bottom:14px;">${Utils.escHtml(c.description || '')}</p>
                                    <div style="font-size:13px;color:#475569;margin-bottom:6px;">
                                        <strong>Dates:</strong> ${Utils.formatDate(c.start_date)} – ${Utils.formatDate(c.end_date)}
                                    </div>
                                    <div style="font-size:13px;color:#475569;margin-bottom:14px;">
                                        <strong>Location:</strong> ${Utils.escHtml(c.location || 'Squash Club Arena')}
                                    </div>
                                </div>
                                <div>
                                    <div class="scb-fe-card__price-row" style="margin-bottom:12px;">
                                        <span style="font-size:13px;color:#64748b;">Camp Fee</span>
                                        <span class="scb-fe-card__price">${Utils.formatCurrency(c.price)}</span>
                                    </div>
                                    <button class="scb-fe-btn scb-fe-btn--primary scb-fe-btn--full scb-register-camp-btn" data-id="${c.id}" data-title="${Utils.escHtml(c.title)}" data-price="${c.price}">
                                        Register Student
                                    </button>
                                </div>
                            </div>
                        </div>
                    `).join('')}
                </div>

                <div class="scb-fe-modal-overlay" id="scb-camp-modal-overlay">
                    <div class="scb-fe-modal">
                        <div class="scb-fe-modal__header">
                            <h3 class="scb-fe-modal__title" id="scb-camp-modal-title">Summer Camp Registration</h3>
                            <button class="scb-fe-modal__close" id="scb-camp-modal-close">&times;</button>
                        </div>
                        <form id="scb-camp-form">
                            <input type="hidden" id="scb-form-camp-id">

                            <h4 style="font-size:14px;font-weight:700;margin-top:0;margin-bottom:10px;color:var(--scb-fe-primary);">Student Information</h4>
                            <div class="scb-fe-form-group">
                                <label class="scb-fe-label">Student Full Name *</label>
                                <input type="text" class="scb-fe-input" id="scb-st-name" required placeholder="Student Name">
                            </div>
                            <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
                                <div class="scb-fe-form-group">
                                    <label class="scb-fe-label">Date of Birth</label>
                                    <input type="date" class="scb-fe-input" id="scb-st-dob">
                                </div>
                                <div class="scb-fe-form-group">
                                    <label class="scb-fe-label">Age *</label>
                                    <input type="number" class="scb-fe-input" id="scb-st-age" required min="4" max="20" value="10">
                                </div>
                            </div>

                            <h4 style="font-size:14px;font-weight:700;margin-top:16px;margin-bottom:10px;color:var(--scb-fe-primary);">Parent / Guardian Information</h4>
                            <div class="scb-fe-form-group">
                                <label class="scb-fe-label">Parent Name *</label>
                                <input type="text" class="scb-fe-input" id="scb-pt-name" required>
                            </div>
                            <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
                                <div class="scb-fe-form-group">
                                    <label class="scb-fe-label">Parent Email *</label>
                                    <input type="email" class="scb-fe-input" id="scb-pt-email" required>
                                </div>
                                <div class="scb-fe-form-group">
                                    <label class="scb-fe-label">Parent Phone *</label>
                                    <input type="tel" class="scb-fe-input" id="scb-pt-phone" required>
                                </div>
                            </div>
                            <div class="scb-fe-form-group">
                                <label class="scb-fe-label">Emergency Contact (Name & Phone) *</label>
                                <input type="text" class="scb-fe-input" id="scb-pt-emerg" required placeholder="e.g. Grandma Mary - (555) 999-1234">
                            </div>
                            <div class="scb-fe-form-group">
                                <label class="scb-fe-label">Medical, Allergy or Dietary Notes</label>
                                <textarea class="scb-fe-textarea" id="scb-st-notes" rows="2" placeholder="List any medical conditions or dietary requirements"></textarea>
                            </div>

                            <div style="margin:16px 0;padding:12px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;font-size:12px;color:#475569;">
                                <label style="display:flex;gap:8px;align-items:flex-start;cursor:pointer;">
                                    <input type="checkbox" required style="margin-top:3px;">
                                    <span>I agree to the club rules, consent to medical treatment in an emergency, and acknowledge the squash program policies.</span>
                                </label>
                            </div>

                            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;">
                                <span style="font-weight:600;">Camp Registration Fee:</span>
                                <span style="font-size:20px;font-weight:800;color:var(--scb-fe-teal);" id="scb-camp-modal-fee">$0.00</span>
                            </div>

                            <button type="submit" class="scb-fe-btn scb-fe-btn--primary scb-fe-btn--full" id="scb-camp-submit-btn">
                                Complete Registration
                            </button>
                        </form>
                    </div>
                </div>
            `;

            const overlay = document.getElementById('scb-camp-modal-overlay');
            document.getElementById('scb-camp-modal-close').addEventListener('click', () => overlay.classList.remove('is-active'));
            overlay.addEventListener('click', (e) => {
                if (e.target === overlay) overlay.classList.remove('is-active');
            });

            container.querySelectorAll('.scb-register-camp-btn').forEach(btn => {
                btn.addEventListener('click', () => {
                    if (!requireAuth('register for summer camp')) {
                        return;
                    }

                    document.getElementById('scb-form-camp-id').value = btn.dataset.id;
                    document.getElementById('scb-camp-modal-title').textContent = btn.dataset.title;
                    document.getElementById('scb-camp-modal-fee').textContent = Utils.formatCurrency(btn.dataset.price);

                    if (CONFIG.currentUser) {
                        if (document.getElementById('scb-pt-name')) document.getElementById('scb-pt-name').value = CONFIG.currentUser.name || '';
                        if (document.getElementById('scb-pt-email')) {
                            document.getElementById('scb-pt-email').value = CONFIG.currentUser.email || '';
                            document.getElementById('scb-pt-email').readOnly = true;
                        }
                        if (document.getElementById('scb-pt-phone')) document.getElementById('scb-pt-phone').value = CONFIG.currentUser.phone || '';
                    }

                    overlay.classList.add('is-active');
                });
            });

            document.getElementById('scb-camp-form').addEventListener('submit', (e) => this.submitRegistration(e));
        },

        async submitRegistration(e) {
            e.preventDefault();
            const btn = document.getElementById('scb-camp-submit-btn');
            btn.disabled = true;
            btn.textContent = 'Registering…';

            const campId = document.getElementById('scb-form-camp-id').value;

            try {
                const headers = { 'Content-Type': 'application/json' };
                if (CONFIG.restNonce) {
                    headers['X-WP-Nonce'] = CONFIG.restNonce;
                }
                const res = await fetch(CONFIG.restUrl + 'register/camp', {
                    method: 'POST',
                    headers: headers,
                    body: JSON.stringify({
                        camp_id: campId,
                        student_name: document.getElementById('scb-st-name').value,
                        student_dob: document.getElementById('scb-st-dob').value,
                        student_age: document.getElementById('scb-st-age').value,
                        parent_name: document.getElementById('scb-pt-name').value,
                        parent_email: document.getElementById('scb-pt-email').value,
                        parent_phone: document.getElementById('scb-pt-phone').value,
                        emergency_contact: document.getElementById('scb-pt-emerg').value,
                        additional_notes: document.getElementById('scb-st-notes').value,
                        consents: ['rules_accepted', 'medical_consent'],
                    })
                });

                let data = null;
                try {
                    data = await res.json();
                } catch (jsonErr) {
                    data = null;
                }

                if (!res.ok || !data?.success) {
                    const errorMsg = data?.message || data?.data?.message || 'Registration failed. Please try again.';
                    Toast.error('Registration Error', errorMsg);
                    btn.disabled = false;
                    btn.textContent = 'Complete Registration';
                    return;
                }

                document.getElementById('scb-camp-modal-overlay').classList.remove('is-active');
                Toast.success('Registration Submitted!', data.message || 'Camp registration received.');

                if (data.checkout_url) {
                    window.location.href = data.checkout_url;
                    return;
                }

                const el = document.getElementById('scb-summer-camp') || document.getElementById('scb-camp-registration');
                el.innerHTML = `
                    <div class="scb-status-card">
                        <div class="scb-status-card__icon scb-status-card__icon--success">
                            <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
                        </div>
                        <h2 style="font-size:24px;margin-bottom:8px;">Registration Submitted!</h2>
                        <p style="color:var(--scb-fe-text-muted);font-size:16px;line-height:1.6;margin-bottom:20px;">${Utils.escHtml(data.message)}</p>
                        <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:16px;max-width:360px;margin:0 auto 24px;">
                            <div style="font-size:12px;color:#64748b;">Registration Reference</div>
                            <div style="font-size:20px;font-weight:800;color:var(--scb-fe-navy);">${Utils.escHtml(data.registration_ref)}</div>
                        </div>
                        <a href="javascript:location.reload()" class="scb-fe-btn scb-fe-btn--navy">View All Camps</a>
                    </div>
                `;

            } catch (err) {
                Toast.error('Connection Error', err.message || 'Registration error. Please try again.');
                btn.disabled = false;
                btn.textContent = 'Complete Registration';
            }
        }
    };

    // ── 4. My Bookings Widget ────────────────────────────────────────────────
    const MyBookings = {
        async init() {
            const el = document.getElementById('scb-my-bookings');
            if (!el) return;

            if (!CONFIG.isLoggedIn) {
                el.innerHTML = `
                    <div class="scb-status-card" style="text-align:center;max-width:460px;margin:30px auto;padding:36px 24px;">
                        <div style="font-size:42px;margin-bottom:12px;">🔒</div>
                        <h3 style="margin:0 0 10px;font-size:20px;font-weight:700;">Account Login Required</h3>
                        <p style="color:#64748b;font-size:14px;line-height:1.5;margin-bottom:22px;">
                            You must be logged in to view your bookings and manage pending reservations.
                        </p>
                        <a href="${CONFIG.loginUrl}" class="scb-fe-btn scb-fe-btn--primary" style="display:inline-block;padding:11px 26px;font-weight:700;text-decoration:none;">Log In / Register</a>
                    </div>
                `;
                return;
            }

            el.innerHTML = '<div style="text-align:center;padding:30px;color:#64748b;">Loading your squash club records…</div>';

            try {
                const headers = {};
                if (CONFIG.restNonce) {
                    headers['X-WP-Nonce'] = CONFIG.restNonce;
                }
                const res = await fetch(CONFIG.restUrl + 'my-bookings', { headers: headers });
                const data = await res.json();
                const bookings = data?.data?.bookings || [];
                const camps = data?.data?.camps || [];

                if (!bookings.length && !camps.length) {
                    el.innerHTML = `
                        <div class="scb-status-card">
                            <h3>No Active Bookings Found</h3>
                            <p style="color:#64748b;">You don't have any recorded bookings or camp registrations yet.</p>
                        </div>
                    `;
                    return;
                }

                el.innerHTML = `
                    ${bookings.length ? `
                        <h3 style="font-size:18px;font-weight:700;margin-bottom:12px;">Clinic & Private Lesson Bookings</h3>
                        <div class="scb-grid-cards" style="margin-bottom:32px;">
                            ${bookings.map(b => `
                                <div class="scb-fe-card">
                                    <div>
                                        <div class="scb-fe-card__badge-bar">
                                            <span class="scb-fe-badge scb-fe-badge--junior">${b.service_type.replace('_', ' ')}</span>
                                            <span class="scb-fe-badge scb-fe-badge--open">${b.booking_status}</span>
                                        </div>
                                        <div class="scb-fe-card__time">${b.lesson_date ? Utils.formatDate(b.lesson_date) : Utils.formatDate(b.slot_date)}</div>
                                        <div class="scb-fe-card__title">${b.coach_first ? 'Coach: ' + b.coach_first + ' ' + b.coach_last : (b.slot_category ? ucfirst(b.slot_category) + ' Clinic' : 'Squash Session')}</div>
                                    </div>
                                    <div class="scb-fe-card__price-row">
                                        <span style="font-size:12px;color:#64748b;">Ref: ${Utils.escHtml(b.booking_ref)}</span>
                                        <span class="scb-fe-card__price">${Utils.formatCurrency(b.amount)}</span>
                                    </div>
                                </div>
                            `).join('')}
                        </div>
                    ` : ''}

                    ${camps.length ? `
                        <h3 style="font-size:18px;font-weight:700;margin-bottom:12px;">Summer Camp Registrations</h3>
                        <div class="scb-grid-cards">
                            ${camps.map(c => `
                                <div class="scb-fe-card">
                                    <div class="scb-fe-card__badge-bar">
                                        <span class="scb-fe-badge scb-fe-badge--adult">Camp</span>
                                        <span class="scb-fe-badge scb-fe-badge--open">${c.registration_status}</span>
                                    </div>
                                    <div class="scb-fe-card__time">${Utils.escHtml(c.camp_title)}</div>
                                    <div class="scb-fe-card__meta">Student: ${Utils.escHtml(c.student_name)}</div>
                                    <div class="scb-fe-card__price-row">
                                        <span style="font-size:12px;color:#64748b;">Ref: ${Utils.escHtml(c.registration_ref)}</span>
                                        <span class="scb-fe-card__price">${Utils.formatCurrency(c.amount)}</span>
                                    </div>
                                </div>
                            `).join('')}
                        </div>
                    ` : ''}
                `;

            } catch (err) {
                el.innerHTML = '<div class="scb-status-card"><p style="color:#ef4444;">Please log in to view your bookings.</p></div>';
            }
        }
    };

    // ── 5. Booking Status Lookup Widget ──────────────────────────────────────
    const BookingStatus = {
        async init() {
            const el = document.getElementById('scb-booking-status');
            if (!el) return;

            const urlParams = new URLSearchParams(window.location.search);
            const refFromUrl = urlParams.get('ref') || el.dataset.ref || '';

            if (refFromUrl) {
                this.lookup(el, refFromUrl);
            } else {
                this.renderSearch(el);
            }
        },

        renderSearch(container) {
            container.innerHTML = `
                <div class="scb-status-card">
                    <h3 style="margin-top:0;">Look Up Booking Status</h3>
                    <p style="color:var(--scb-fe-text-muted);font-size:16px;line-height:1.6;">Enter your unique booking or camp registration reference code (e.g. SCB-CLN-... or SCB-CMP-...).</p>
                    <form id="scb-status-lookup-form" style="display:flex;gap:8px;max-width:400px;margin:20px auto 0;">
                        <input type="text" class="scb-fe-input" id="scb-lookup-input" required placeholder="SCB-2026-..." style="text-transform:uppercase;">
                        <button type="submit" class="scb-fe-btn scb-fe-btn--primary">Search</button>
                    </form>
                </div>
            `;

            document.getElementById('scb-status-lookup-form').addEventListener('submit', (e) => {
                e.preventDefault();
                const ref = document.getElementById('scb-lookup-input').value.trim();
                if (ref) this.lookup(container, ref);
            });
        },

        async lookup(container, ref) {
            container.innerHTML = '<div style="text-align:center;padding:40px;color:#64748b;">Searching booking records…</div>';

            try {
                const headers = {};
                if (CONFIG.restNonce) {
                    headers['X-WP-Nonce'] = CONFIG.restNonce;
                }
                const res = await fetch(buildRestUrl(`booking/status?ref=${encodeURIComponent(ref)}`), { headers: headers });
                const result = await res.json();

                if (!result.success || !result.data) {
                    container.innerHTML = `
                        <div class="scb-status-card">
                            <h3 style="color:#ef4444;">Reference Not Found</h3>
                            <p style="color:#64748b;">No booking or registration was found with reference <strong>${Utils.escHtml(ref)}</strong>.</p>
                            <a href="javascript:location.reload()" class="scb-fe-btn scb-fe-btn--navy" style="margin-top:14px;">Try Another Reference</a>
                        </div>
                    `;
                    return;
                }

                const d = result.data;
                const isCamp = result.type === 'camp';

                container.innerHTML = `
                    <div class="scb-status-card">
                        <div class="scb-status-card__icon scb-status-card__icon--success">
                            <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
                        </div>
                        <h2 style="font-size:22px;margin:0 0 4px 0;">${isCamp ? 'Camp Registration' : 'Booking Details'}</h2>
                        <div style="font-size:14px;color:#64748b;margin-bottom:20px;">Ref: <strong>${Utils.escHtml(d.booking_ref || d.registration_ref)}</strong></div>

                        <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:20px;text-align:left;margin-bottom:20px;">
                            <div style="display:flex;justify-content:space-between;padding:8px 0;border-bottom:1px solid #e2e8f0;">
                                <span style="color:#64748b;">Status:</span>
                                <strong><span class="scb-fe-badge scb-fe-badge--open">${d.booking_status || d.registration_status}</span></strong>
                            </div>
                            <div style="display:flex;justify-content:space-between;padding:8px 0;border-bottom:1px solid #e2e8f0;">
                                <span style="color:#64748b;">Customer / Student:</span>
                                <strong>${Utils.escHtml(d.customer_name || d.student_name)}</strong>
                            </div>
                            <div style="display:flex;justify-content:space-between;padding:8px 0;border-bottom:1px solid #e2e8f0;">
                                <span style="color:#64748b;">Service:</span>
                                <strong>${isCamp ? Utils.escHtml(d.camp_title) : (d.coach_first ? 'Private Lesson with ' + d.coach_first + ' ' + d.coach_last : 'Sunday Clinic')}</strong>
                            </div>
                            <div style="display:flex;justify-content:space-between;padding:8px 0;">
                                <span style="color:#64748b;">Amount:</span>
                                <strong style="color:var(--scb-fe-teal);">${Utils.formatCurrency(d.amount)} (${d.payment_status || 'unpaid'})</strong>
                            </div>
                        </div>

                        <a href="javascript:location.reload()" class="scb-fe-btn scb-fe-btn--navy">Search Another Reference</a>
                    </div>
                `;

            } catch (err) {
                container.innerHTML = '<div class="scb-status-card"><p style="color:#ef4444;">Lookup failed. Please try again.</p></div>';
            }
        }
    };

    // Helper: capitalize string.
    function ucfirst(str) {
        if (!str) return '';
        return str.charAt(0).toUpperCase() + str.slice(1);
    }

    // Auto-boot on DOM ready.
    document.addEventListener('DOMContentLoaded', () => {
        ClinicBooking.init();
        LessonBooking.init();
        CampRegistration.init();
        MyBookings.init();
        BookingStatus.init();
    });

})(jQuery);
