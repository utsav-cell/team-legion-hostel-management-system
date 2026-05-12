// ── Styled confirmation modal (module-scope so inline scripts can call it) ──

function buildConfirmModal() {
    if (document.getElementById('hms-confirm-overlay')) return;
    // Inject one-off styles for the richer modal (idempotent).
    if (!document.getElementById('hms-confirm-styles')) {
        const css = document.createElement('style');
        css.id = 'hms-confirm-styles';
        css.textContent = `
            #hms-confirm-overlay .modal-box {
                max-width: 440px; padding: 1.85rem 1.75rem 1.5rem;
                border-radius: 18px;
                box-shadow: 0 24px 60px -10px rgba(15,23,42,0.28), 0 8px 20px rgba(15,23,42,0.1);
                border: 1.5px solid rgba(99,102,241,0.08);
            }
            #hms-confirm-overlay .hms-cm-icon-wrap {
                width: 56px; height: 56px; border-radius: 50%;
                background: linear-gradient(135deg, rgba(245,158,11,0.16), rgba(245,158,11,0.06));
                border: 1.5px solid rgba(245,158,11,0.22);
                display: flex; align-items: center; justify-content: center;
                margin: 0.25rem 0 1.1rem; flex-shrink: 0;
            }
            #hms-confirm-overlay .hms-cm-icon-wrap svg {
                width: 24px; height: 24px; stroke: #d97706; fill: none; stroke-width: 2.2;
            }
            #hms-confirm-overlay .hms-cm-title {
                font-size: 1.15rem; font-weight: 800; color: var(--text);
                letter-spacing: -0.01em; margin-bottom: 0.4rem;
            }
            #hms-confirm-overlay .hms-cm-body {
                font-size: 0.9rem; color: var(--muted);
                line-height: 1.6; margin-bottom: 1.6rem;
            }
            #hms-confirm-overlay .hms-cm-close {
                position: absolute; top: 0.85rem; right: 0.85rem;
                width: 32px; height: 32px; border-radius: 8px;
                border: none; background: transparent; cursor: pointer;
                color: var(--muted); transition: all 0.15s ease;
                display: inline-flex; align-items: center; justify-content: center;
            }
            #hms-confirm-overlay .hms-cm-close:hover {
                background: var(--panel-alt); color: var(--text);
            }
            #hms-confirm-overlay .hms-cm-close svg { width: 16px; height: 16px; }
            #hms-confirm-overlay .hms-cm-actions {
                display: flex; gap: 0.6rem; justify-content: flex-end;
            }
            #hms-confirm-overlay .hms-cm-actions button {
                font-size: 0.875rem; font-weight: 700;
                padding: 0.6rem 1.2rem; border-radius: 9px;
                cursor: pointer; transition: all 0.12s ease;
                border: 1.5px solid transparent;
            }
            #hms-confirm-overlay .hms-cm-cancel {
                background: #fff; color: var(--text);
                border-color: var(--border);
            }
            #hms-confirm-overlay .hms-cm-cancel:hover {
                background: var(--panel-alt); border-color: var(--muted);
            }
            #hms-confirm-overlay .hms-cm-ok {
                background: linear-gradient(135deg, #6366f1, #4f46e5);
                color: #fff;
                box-shadow: 0 2px 8px rgba(99,102,241,0.3);
            }
            #hms-confirm-overlay .hms-cm-ok:hover {
                transform: translateY(-1px);
                box-shadow: 0 4px 12px rgba(99,102,241,0.4);
            }
        `;
        document.head.appendChild(css);
    }
    const el = document.createElement('div');
    el.id        = 'hms-confirm-overlay';
    el.className = 'modal-overlay';
    el.innerHTML = `
        <div class="modal-box" style="position:relative;">
            <button type="button" class="hms-cm-close" id="hms-cm-close" aria-label="Close">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>
                </svg>
            </button>
            <div class="hms-cm-icon-wrap">
                <svg viewBox="0 0 24 24">
                    <path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/>
                    <line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/>
                </svg>
            </div>
            <div class="hms-cm-title" id="hms-cm-title">Confirm</div>
            <div class="hms-cm-body" id="hms-cm-body"></div>
            <div class="hms-cm-actions">
                <button type="button" class="hms-cm-cancel" id="hms-cm-cancel">Cancel</button>
                <button type="button" class="hms-cm-ok"     id="hms-cm-ok">Confirm</button>
            </div>
        </div>`;
    document.body.appendChild(el);
    const close = () => el.classList.remove('open');
    document.getElementById('hms-cm-close').addEventListener('click', close);
    document.getElementById('hms-cm-cancel').addEventListener('click', close);
    el.addEventListener('click', (e) => { if (e.target === el) close(); });
    // ESC key to close
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && el.classList.contains('open')) close();
    });
}

function hmsConfirm(message, onConfirm, title) {
    buildConfirmModal();
    const overlay = document.getElementById('hms-confirm-overlay');
    document.getElementById('hms-cm-title').textContent = title || 'Confirm';
    document.getElementById('hms-cm-body').textContent  = message;
    const okBtn = document.getElementById('hms-cm-ok');
    const fresh = okBtn.cloneNode(true);
    okBtn.parentNode.replaceChild(fresh, okBtn);
    fresh.addEventListener('click', () => {
        overlay.classList.remove('open');
        onConfirm();
    });
    overlay.classList.add('open');
}

// Also expose on window for inline scripts
window.hmsConfirm = hmsConfirm;


// ── Programmatic toast (card-style popup, auto-dismiss) ─────────────────────
function hmsToast(message, type) {
    type = type || 'info';
    let container = document.getElementById('toast-container');
    if (!container) {
        container = document.createElement('div');
        container.id = 'toast-container';
        document.body.appendChild(container);
    }
    const cls = (type === 'error' || type === 'warning' || type === 'success' || type === 'info')
        ? 'alert-' + type
        : 'alert-info';
    const el = document.createElement('div');
    el.className = 'alert ' + cls;
    el.style.margin = '0';
    el.textContent = message;

    const btn = document.createElement('button');
    btn.className = 'toast-close';
    btn.innerHTML = '&times;';
    btn.setAttribute('aria-label', 'Dismiss');
    btn.addEventListener('click', () => dismiss());
    el.appendChild(btn);

    container.appendChild(el);

    el.style.opacity   = '0';
    el.style.transform = 'translateX(16px)';
    requestAnimationFrame(() => {
        requestAnimationFrame(() => {
            el.style.transition = 'opacity 0.25s ease, transform 0.25s ease';
            el.style.opacity    = '1';
            el.style.transform  = 'translateX(0)';
        });
    });

    function dismiss() {
        el.style.transition = 'opacity 0.22s ease, transform 0.22s ease';
        el.style.opacity    = '0';
        el.style.transform  = 'translateX(16px)';
        setTimeout(() => el.remove(), 240);
    }
    setTimeout(dismiss, 4500);
}
window.hmsToast = hmsToast;


// ── Everything else needs DOM ready ─────────────────────────────────────────
document.addEventListener('DOMContentLoaded', () => {

    // Sidebar toggle
    const sidebar = document.querySelector('.sidebar');
    const toggle  = document.querySelector('.sidebar-toggle');
    if (toggle && sidebar) {
        toggle.addEventListener('click', () => sidebar.classList.toggle('open'));
    }

    // Convert .alert elements to floating toasts
    (function initToasts() {
        const alerts = document.querySelectorAll('.alert');
        if (!alerts.length) return;

        let container = document.getElementById('toast-container');
        if (!container) {
            container = document.createElement('div');
            container.id = 'toast-container';
            document.body.appendChild(container);
        }

        function dismissToast(el) {
            el.style.transition = 'opacity 0.22s ease, transform 0.22s ease';
            el.style.opacity    = '0';
            el.style.transform  = 'translateX(16px)';
            setTimeout(() => el.remove(), 240);
        }

        alerts.forEach((el) => {
            container.appendChild(el);
            el.style.margin = '0';

            const btn = document.createElement('button');
            btn.className = 'toast-close';
            btn.innerHTML = '&times;';
            btn.setAttribute('aria-label', 'Dismiss');
            btn.addEventListener('click', () => dismissToast(el));
            el.appendChild(btn);

            el.style.opacity   = '0';
            el.style.transform = 'translateX(16px)';
            requestAnimationFrame(() => {
                requestAnimationFrame(() => {
                    el.style.transition = 'opacity 0.25s ease, transform 0.25s ease';
                    el.style.opacity    = '1';
                    el.style.transform  = 'translateX(0)';
                });
            });

            setTimeout(() => dismissToast(el), 4500);
        });
    })();

    // Count-up animation
    document.querySelectorAll('[data-count]').forEach((el) => {
        const target   = Number(el.dataset.count || 0);
        const duration = 900;
        const start    = performance.now();
        const decimals = (el.dataset.decimals || '').length;
        const prefix   = el.dataset.prefix || '';
        const suffix   = el.dataset.suffix || '';
        const step = (now) => {
            const progress  = Math.min((now - start) / duration, 1);
            const value     = target * (1 - Math.pow(1 - progress, 3));
            const formatted = Number(value).toFixed(decimals).replace(/\.0+$/, '');
            el.textContent  = `${prefix}${formatted}${suffix}`;
            if (progress < 1) requestAnimationFrame(step);
        };
        requestAnimationFrame(step);
    });

    // Scroll-reveal
    const observer = new IntersectionObserver((entries) => {
        entries.forEach((entry) => {
            if (entry.isIntersecting) entry.target.classList.add('is-visible');
        });
    }, { threshold: 0.12 });
    document.querySelectorAll('[data-reveal]').forEach((node) => observer.observe(node));

    // Password show/hide
    document.querySelectorAll('.toggle-pwd').forEach((btn) => {
        btn.addEventListener('click', () => {
            const input = document.getElementById(btn.dataset.target || btn.getAttribute('onclick')?.match(/'([^']+)'/)?.[1]);
            if (!input) return;
            input.type  = input.type === 'password' ? 'text' : 'password';
            btn.textContent = input.type === 'password' ? 'Show' : 'Hide';
        });
    });

    // Submit busy-state (skip forms with data-confirm — those are handled below)
    document.querySelectorAll('form').forEach((form) => {
        form.addEventListener('submit', (e) => {
            if (form.dataset.confirm) return;
            if (form.dataset.noBusy === 'true') return;
            const btn = form.querySelector('button[type="submit"]');
            if (!btn || btn.dataset.noBusy === 'true') return;
            btn.dataset.originalText = btn.innerHTML;
            btn.disabled        = true;
            btn.style.opacity   = '0.8';
            btn.innerHTML       = 'Processing...';
        });
    });

    // Table search
    const searchInput = document.getElementById('student-search');
    if (searchInput) {
        searchInput.addEventListener('input', () => {
            const q = searchInput.value.toLowerCase().trim();
            document.querySelectorAll('table tbody tr').forEach((row) => {
                row.style.display = row.textContent.toLowerCase().includes(q) ? '' : 'none';
            });
        });
    }

    // Wire forms with data-confirm
    document.querySelectorAll('form[data-confirm]').forEach((form) => {
        form.addEventListener('submit', (e) => {
            e.preventDefault();
            hmsConfirm(form.dataset.confirm, () => {
                form.removeAttribute('data-confirm');
                const btn = form.querySelector('button[type="submit"]');
                if (btn) { btn.disabled = true; btn.style.opacity = '0.8'; btn.innerHTML = 'Processing...'; }
                form.submit();
            });
        });
    });

    // Wire standalone buttons/links with data-confirm
    document.querySelectorAll('button[data-confirm], a[data-confirm]').forEach((el) => {
        if (el.closest('form[data-confirm]')) return;
        el.addEventListener('click', (e) => {
            e.preventDefault();
            const href = el.getAttribute('href');
            hmsConfirm(el.dataset.confirm, () => {
                if (href) { window.location.href = href; }
                else {
                    const form = el.closest('form');
                    if (form) { el.removeAttribute('data-confirm'); form.submit(); }
                }
            });
        });
    });
});
