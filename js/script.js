document.addEventListener('DOMContentLoaded', () => {
    const sidebar = document.querySelector('.sidebar');
    const toggle = document.querySelector('.sidebar-toggle');
    if (toggle && sidebar) {
        toggle.addEventListener('click', () => sidebar.classList.toggle('open'));
    }

    document.querySelectorAll('.alert').forEach((el) => {
        setTimeout(() => {
            el.style.transition = 'opacity .4s ease, transform .4s ease';
            el.style.opacity = '0';
            el.style.transform = 'translateY(-10px)';
            setTimeout(() => el.remove(), 450);
        }, 4500);
    });

    document.querySelectorAll('[data-count]').forEach((el) => {
        const target = Number(el.dataset.count || 0);
        const duration = 900;
        const start = performance.now();
        const decimals = (el.dataset.decimals || '').length;
        const prefix = el.dataset.prefix || '';
        const suffix = el.dataset.suffix || '';
        const step = (now) => {
            const progress = Math.min((now - start) / duration, 1);
            const value = target * (1 - Math.pow(1 - progress, 3));
            const formatted = Number(value).toFixed(decimals).replace(/\.0+$/, '');
            el.textContent = `${prefix}${formatted}${suffix}`;
            if (progress < 1) requestAnimationFrame(step);
        };
        requestAnimationFrame(step);
    });

    const observer = new IntersectionObserver((entries) => {
        entries.forEach((entry) => {
            if (entry.isIntersecting) entry.target.classList.add('is-visible');
        });
    }, { threshold: 0.12 });
    document.querySelectorAll('[data-reveal]').forEach((node) => observer.observe(node));

    document.querySelectorAll('.toggle-pwd').forEach((btn) => {
        btn.addEventListener('click', () => {
            const input = document.getElementById(btn.dataset.target || btn.getAttribute('onclick')?.match(/'([^']+)'/)?.[1]);
            if (!input) return;
            input.type = input.type === 'password' ? 'text' : 'password';
            btn.textContent = input.type === 'password' ? 'Show' : 'Hide';
        });
    });

    document.querySelectorAll('form').forEach((form) => {
        form.addEventListener('submit', () => {
            const btn = form.querySelector('button[type="submit"]');
            if (!btn || btn.dataset.noBusy === 'true') return;
            btn.dataset.originalText = btn.innerHTML;
            btn.disabled = true;
            btn.style.opacity = '0.8';
            btn.innerHTML = 'Processing...';
        });
    });

    const searchInput = document.getElementById('student-search');
    if (searchInput) {
        searchInput.addEventListener('input', () => {
            const q = searchInput.value.toLowerCase().trim();
            document.querySelectorAll('table tbody tr').forEach((row) => {
                row.style.display = row.textContent.toLowerCase().includes(q) ? '' : 'none';
            });
        });
    }
});
