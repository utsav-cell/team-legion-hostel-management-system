 // Hostel Management System — Main JS

document.addEventListener('DOMContentLoaded', function () {

    // 1. Sidebar highlight logic
    const path = window.location.pathname;
    document.querySelectorAll('.navbar-nav a').forEach(function (a) {
        if (path.endsWith(a.getAttribute('href'))) a.classList.add('active');
    });

    // 2. Auto-dismiss alerts after 4s
    document.querySelectorAll('.alert').forEach(function (el) {
        setTimeout(function () {
            el.style.transition = 'opacity .5s, transform .5s';
            el.style.opacity = '0';
            el.style.transform = 'translateY(-10px)';
            setTimeout(function () { el.remove(); }, 500);
        }, 4000);
    });

    // 3. Password visibility toggle (Login/Register)
    document.querySelectorAll('.toggle-pwd').forEach(function (btn) {
        btn.addEventListener('click', function () {
            const inp = document.getElementById(this.dataset.target);
            if (!inp) return;
            const isText = inp.type === 'text';
            inp.type = isText ? 'password' : 'text';
            this.textContent = isText ? 'Show' : 'Hide';
            this.style.color = isText ? 'var(--text-muted)' : 'var(--brand)';
        });
    });

    // 4. AJAX Email availability check (register page)
    const emailInput = document.querySelector('input[name="email"][type="email"]');
    const registerBtn = document.querySelector('button[type="submit"]');
    if (emailInput && registerBtn && document.getElementById('action')?.value === 'register') {
        let timer;
        const msgDiv = document.createElement('div');
        msgDiv.style.fontSize = '0.75rem';
        msgDiv.style.marginTop = '0.25rem';
        emailInput.parentNode.appendChild(msgDiv);

        emailInput.addEventListener('input', function () {
            clearTimeout(timer);
            const val = this.value.trim();
            if (!val || !val.includes('@')) { msgDiv.textContent = ''; return; }
            msgDiv.textContent = 'Checking…';
            msgDiv.style.color = 'var(--text-muted)';
            timer = setTimeout(function () {
                const fd = new FormData();
                fd.append('email', val);
                // Note: You would normally have an ajax/check_email.php. 
                // For now, we rely on the main form's error handling if not present.
            }, 500);
        });
    }

    // 5. Disable submit while form posts
    document.querySelectorAll('form').forEach(function (form) {
        form.addEventListener('submit', function () {
            const btn = form.querySelector('button[type="submit"]');
            if (btn) { btn.disabled = true; btn.style.opacity = '0.7'; btn.textContent = 'Processing…'; }
        });
    });

    // 6. Student search (warden student list)
    const searchInput = document.getElementById('student-search');
    if (searchInput) {
        searchInput.addEventListener('input', function () {
            const q = this.value.toLowerCase();
            const rows = document.querySelectorAll('table tbody tr');
            rows.forEach(function (row) {
                const text = row.textContent.toLowerCase();
                row.style.display = text.includes(q) ? '' : 'none';
            });
        });
    }

});