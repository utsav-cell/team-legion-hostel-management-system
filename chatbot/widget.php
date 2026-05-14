<?php
// ─────────────────────────────────────────────────
// chatbot/widget.php
// Mount on landing/student pages: include __DIR__ . '/../chatbot/widget.php';
// FAQ-only — handles general enquiries about the hostel.
// ─────────────────────────────────────────────────

if (empty($_SESSION['chatbot_session_id'])) {
    $_SESSION['chatbot_session_id'] = bin2hex(random_bytes(16));
}
$cb_csrf       = csrf_token();
$cb_session_id = $_SESSION['chatbot_session_id'];

$cb_quick = [
    'Fees'         => 'how much are the fees',
    'Rooms'        => 'tell me about the rooms',
    'Meals'        => 'what about food',
    'WiFi'         => 'wifi and study',
    'Security'     => 'is it safe',
    'Location'     => 'where is the hostel',
    'How to apply' => 'how do I apply',
    'Visit'        => 'can I visit',
    'Contact'      => 'contact details',
];
?>
<style>
    /* FAB - simple round button, no gradient */
    .cb-fab {
        position:fixed; right:24px; bottom:24px; z-index:9998;
        width:48px; height:48px; border-radius:50%; border:1.5px solid rgba(15,23,42,0.12);
        cursor:pointer; background:#fff; color:#0f172a;
        box-shadow:0 4px 16px rgba(15,23,42,0.12);
        transition:box-shadow 0.2s ease, transform 0.15s ease;
        display:flex; align-items:center; justify-content:center;
    }
    .cb-fab:hover { box-shadow:0 6px 20px rgba(15,23,42,0.16); transform:translateY(-1px); }
    .cb-fab svg { width:20px; height:20px; }
    .cb-fab .cb-fab-dot {
        position:absolute; top:4px; right:4px;
        width:9px; height:9px; border-radius:50%;
        background:#10b981; border:2px solid #fff;
    }

    /* Panel */
    .cb-panel {
        position:fixed; right:24px; bottom:80px; z-index:9999;
        width:360px; max-width:calc(100vw - 32px);
        height:520px; max-height:calc(100vh - 110px);
        background:#fff; border-radius:14px;
        border:1.5px solid rgba(15,23,42,0.1);
        box-shadow:0 8px 32px rgba(15,23,42,0.12);
        display:none; flex-direction:column;
        overflow:hidden; font-family:inherit;
    }
    .cb-panel.open { display:flex; }

    /* Header - plain white, no gradient */
    .cb-header {
        background:#fff; border-bottom:1.5px solid rgba(15,23,42,0.08);
        padding:12px 14px;
        display:flex; align-items:center; justify-content:space-between;
    }
    .cb-header-left { display:flex; align-items:center; gap:0.6rem; }
    .cb-header-left h3 { margin:0; font-size:0.9rem; font-weight:700; color:#0f172a; }
    .cb-header-left .cb-sub { font-size:0.72rem; color:#64748b; margin-top:1px; }
    .cb-header-icon {
        width:32px; height:32px; border-radius:8px;
        background:#eef2ff; display:flex; align-items:center; justify-content:center;
        flex-shrink:0;
    }
    .cb-header-icon svg { width:16px; height:16px; color:#6366f1; }
    .cb-header .cb-close {
        background:transparent; border:none; color:#94a3b8;
        font-size:20px; cursor:pointer; line-height:1;
        width:28px; height:28px; border-radius:6px;
        display:flex; align-items:center; justify-content:center;
        transition:background 0.15s ease;
    }
    .cb-header .cb-close:hover { background:#f1f5f9; color:#0f172a; }

    /* Messages */
    .cb-msgs { flex:1; overflow-y:auto; padding:12px; background:#f8fafc; display:flex; flex-direction:column; gap:8px; }
    .cb-msg  { padding:8px 12px; border-radius:12px; max-width:84%; font-size:0.875rem; line-height:1.55; word-wrap:break-word; }
    .cb-msg.user { align-self:flex-end; background:#0f172a; color:#fff; border-bottom-right-radius:3px; }
    .cb-msg.bot  { align-self:flex-start; background:#fff; color:#0f172a; border:1px solid rgba(15,23,42,0.1); border-bottom-left-radius:3px; }
    .cb-rate { display:flex; gap:3px; margin-top:5px; }
    .cb-rate button { background:transparent; border:none; cursor:pointer; font-size:16px; padding:0 2px; color:#cbd5e1; line-height:1; }
    .cb-rate button.active, .cb-rate button:hover { color:#f59e0b; }
    .cb-rate.done button { cursor:default; }

    /* Quick replies */
    .cb-quick { display:flex; flex-wrap:wrap; gap:5px; padding:8px 10px 0; background:#fff; border-top:1px solid rgba(15,23,42,0.08); }
    .cb-quick button {
        background:transparent; color:#334155;
        border:1px solid rgba(15,23,42,0.15);
        padding:5px 10px; border-radius:999px;
        font-size:0.75rem; font-weight:600; cursor:pointer;
        font-family:inherit; transition:background 0.12s ease, border-color 0.12s ease;
    }
    .cb-quick button:hover { background:#f1f5f9; border-color:rgba(15,23,42,0.25); }

    /* Input form */
    .cb-form { display:flex; gap:7px; padding:8px 10px 10px; background:#fff; border-top:1px solid rgba(15,23,42,0.08); }
    .cb-form input {
        flex:1; padding:8px 12px;
        border:1px solid rgba(15,23,42,0.15); border-radius:8px;
        font-family:inherit; font-size:0.875rem; outline:none;
        transition:border-color 0.2s ease;
    }
    .cb-form input:focus { border-color:#6366f1; box-shadow:0 0 0 3px rgba(99,102,241,0.1); }
    .cb-form button {
        background:#0f172a; color:#fff; border:none;
        padding:0 14px; border-radius:8px;
        cursor:pointer; font-weight:700; font-size:0.82rem;
        transition:background 0.15s ease;
    }
    .cb-form button:hover { background:#1e293b; }
    .cb-form button:disabled { opacity:0.45; cursor:not-allowed; }
    .cb-typing { font-style:italic; color:#94a3b8; font-size:0.78rem; padding:0 4px; }
</style>

<button class="cb-fab" id="cbFab" aria-label="Open help chat" type="button">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
        <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>
    </svg>
    <span class="cb-fab-dot"></span>
</button>
<div class="cb-panel" id="cbPanel" role="dialog" aria-label="Help chat">
    <div class="cb-header">
        <div class="cb-header-left">
            <div class="cb-header-icon">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>
                </svg>
            </div>
            <div>
                <h3>HMS Helper</h3>
                <div class="cb-sub">Ask about fees, rooms, meals…</div>
            </div>
        </div>
        <button class="cb-close" id="cbClose" aria-label="Close">&times;</button>
    </div>
    <div class="cb-msgs" id="cbMsgs"></div>
    <div class="cb-quick" id="cbQuick">
        <?php foreach ($cb_quick as $label => $query): ?>
            <button type="button" data-q="<?= htmlspecialchars($query, ENT_QUOTES) ?>"><?= htmlspecialchars($label) ?></button>
        <?php endforeach; ?>
    </div>
    <form class="cb-form" id="cbForm" autocomplete="off" data-no-busy="true">
        <input type="text" id="cbInput" placeholder="Type your question…" maxlength="500">
        <button type="submit" id="cbSend" data-no-busy="true">Send</button>
    </form>
</div>

<script>
(function(){
    const CSRF       = <?= json_encode($cb_csrf) ?>;
    const SESSION_ID = <?= json_encode($cb_session_id) ?>;
    const API_URL    = '../chatbot/api.php';

    const fab   = document.getElementById('cbFab');
    const panel = document.getElementById('cbPanel');
    const close = document.getElementById('cbClose');
    const msgs  = document.getElementById('cbMsgs');
    const form  = document.getElementById('cbForm');
    const input = document.getElementById('cbInput');
    const send  = document.getElementById('cbSend');
    const quick = document.getElementById('cbQuick');

    let greeted = false;
    function escape(s){ return String(s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); }

    function addMsg(role, text, conversationId) {
        const el = document.createElement('div');
        el.className = 'cb-msg ' + role;
        el.innerHTML = escape(text).replace(/\n/g, '<br>');
        if (role === 'bot' && conversationId) {
            const rate = document.createElement('div');
            rate.className = 'cb-rate';
            rate.dataset.id = conversationId;
            for (let i = 1; i <= 5; i++) {
                const b = document.createElement('button');
                b.type = 'button'; b.textContent = '★'; b.dataset.value = i;
                b.title = i + ' star' + (i>1?'s':'');
                b.onclick = () => rateConversation(rate, i);
                rate.appendChild(b);
            }
            el.appendChild(rate);
        }
        msgs.appendChild(el);
        msgs.scrollTop = msgs.scrollHeight;
    }

    async function rateConversation(rateEl, value) {
        if (rateEl.classList.contains('done')) return;
        rateEl.classList.add('done');
        Array.from(rateEl.children).forEach((b, i) => { if (i < value) b.classList.add('active'); });
        try {
            await fetch(API_URL, {
                method: 'POST',
                headers: {'Content-Type':'application/json'},
                body: JSON.stringify({ action:'rate', conversation_id: rateEl.dataset.id, rating: value, csrf_token: CSRF, session_id: SESSION_ID })
            });
        } catch (e) { /* silent */ }
    }

    async function sendMessage(text) {
        send.disabled = true;
        const typing = document.createElement('div');
        typing.className = 'cb-typing'; typing.textContent = 'HMS Helper is typing…';
        msgs.appendChild(typing); msgs.scrollTop = msgs.scrollHeight;
        try {
            const res  = await fetch(API_URL, {
                method: 'POST',
                headers: {'Content-Type':'application/json'},
                body: JSON.stringify({ action:'message', message: text, csrf_token: CSRF, session_id: SESSION_ID })
            });
            const data = await res.json();
            typing.remove();
            if (data.success) addMsg('bot', data.reply, data.conversation_id);
            else              addMsg('bot', 'Sorry — ' + (data.message || 'something went wrong.'));
        } catch (e) {
            typing.remove();
            addMsg('bot', 'Connection error. Please try again.');
        } finally {
            send.disabled = false;
            input.focus();
        }
    }

    fab.addEventListener('click', () => {
        panel.classList.toggle('open');
        if (panel.classList.contains('open')) {
            input.focus();
            if (!greeted) {
                addMsg('bot', 'Namaste! I can help you learn about HMS Hostel — fees, rooms, meals, location, security and how to apply. Tap a topic below or type your question.');
                greeted = true;
            }
        }
    });
    close.addEventListener('click', () => panel.classList.remove('open'));

    quick.addEventListener('click', (e) => {
        const btn = e.target.closest('button[data-q]');
        if (!btn) return;
        const q = btn.dataset.q;
        addMsg('user', btn.textContent);
        sendMessage(q);
    });

    form.addEventListener('submit', (e) => {
        e.preventDefault();
        const text = input.value.trim();
        if (!text) return;
        addMsg('user', text);
        input.value = '';
        sendMessage(text);
    });
})();
</script>
