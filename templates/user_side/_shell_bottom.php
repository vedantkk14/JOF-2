        </main>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const shell = document.getElementById('shell');
            const railToggle = document.getElementById('railToggle');
            const hamburgerBtn = document.getElementById('hamburgerBtn');
            const overlay = document.getElementById('overlay');

            const isMobile = () => window.matchMedia('(max-width: 860px)').matches;

            function openDrawer() {
                shell.classList.add('drawer-open');
                overlay.classList.add('show');
                document.body.style.overflow = 'hidden';
            }
            function closeDrawer() {
                shell.classList.remove('drawer-open');
                overlay.classList.remove('show');
                document.body.style.overflow = '';
            }

            if (hamburgerBtn) hamburgerBtn.addEventListener('click', openDrawer);
            if (overlay) overlay.addEventListener('click', closeDrawer);
            if (railToggle) railToggle.addEventListener('click', () => {
                if (isMobile()) closeDrawer();
                else shell.classList.toggle('collapsed');
            });

            document.addEventListener('keydown', (e) => {
                if (e.key === 'Escape' && shell.classList.contains('drawer-open')) closeDrawer();
            });
            window.addEventListener('resize', () => { if (!isMobile()) closeDrawer(); });

            document.querySelectorAll('.nav-item').forEach(item => {
                item.addEventListener('click', () => { if (isMobile()) closeDrawer(); });
            });

            // ══════════════════════════════════════════════════
            //  MESSAGES BELL (trainer replies on diet plan chats)
            // ══════════════════════════════════════════════════
            let msgOpen = false;
            const msgBtn = document.getElementById('msgBellBtn');
            const msgDrop = document.getElementById('msgDropdown');
            const msgDot = document.getElementById('msgUnreadDot');
            const msgList = document.getElementById('msgDropdownList');
            const msgClose = document.getElementById('msgDropdownClose');

            function escHtml(str) {
                return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
            }

            function timeAgo(dateStr) {
                const past = new Date(dateStr.replace(' ', 'T'));
                if (isNaN(past.getTime())) return '';
                let diff = Math.floor((Date.now() - past.getTime()) / 1000);
                if (diff < 0) diff = 0;
                if (diff < 60) return diff + 's ago';
                if (diff < 3600) return Math.floor(diff / 60) + 'm ago';
                if (diff < 86400) return Math.floor(diff / 3600) + 'h ago';
                return Math.floor(diff / 86400) + 'd ago';
            }

            if (msgBtn) {
                msgBtn.addEventListener('click', function (e) {
                    e.stopPropagation();
                    msgOpen = !msgOpen;
                    msgDrop.classList.toggle('active', msgOpen);
                    if (msgOpen) openMsgPanel();
                });
            }
            if (msgClose) {
                msgClose.addEventListener('click', function (e) {
                    e.stopPropagation();
                    msgOpen = false;
                    msgDrop.classList.remove('active');
                });
            }
            document.addEventListener('click', function (e) {
                if (msgOpen && msgDrop && !msgDrop.contains(e.target) && e.target !== msgBtn) {
                    msgOpen = false;
                    msgDrop.classList.remove('active');
                }
            });

            function renderMsgList(items) {
                if (!items || !items.length) {
                    msgList.innerHTML = '<div class="shell-dropdown-empty">No messages yet.<br>Ask a question from your Diet Plans page!</div>';
                    return;
                }
                msgList.innerHTML = items.map(n => `
                    <a class="shell-dropdown-item" href="user_diet_plans.php?open_plan=${n.plan_id}">
                        <div class="n">${escHtml(n.phase)}</div>
                        <div class="m">${escHtml((n.message || '').slice(0, 70))}${n.message.length > 70 ? '…' : ''}</div>
                        <div class="t">${timeAgo(n.created_at)}</div>
                    </a>
                `).join('');
            }

            function openMsgPanel() {
                msgList.innerHTML = '<div class="shell-dropdown-empty">Loading…</div>';
                fetch('../../handlers/get_user_diet_notifications.php', { cache: 'no-store' })
                    .then(res => res.json())
                    .then(data => {
                        if (data.status === 'success') {
                            renderMsgList(data.notifications);
                            fetch('../../handlers/mark_user_diet_messages_seen.php', { method: 'POST', cache: 'no-store' });
                            updateMsgDot(0);
                        }
                    })
                    .catch(() => { msgList.innerHTML = '<div class="shell-dropdown-empty">Could not load messages.</div>'; });
            }

            function updateMsgDot(count) {
                if (!msgDot) return;
                msgDot.style.display = count > 0 ? 'block' : 'none';
            }

            function pollMsgBadge() {
                fetch('../../handlers/get_user_diet_notifications.php', { cache: 'no-store' })
                    .then(res => res.json())
                    .then(data => {
                        if (data.status === 'success' && !msgOpen) updateMsgDot(data.unread_count);
                    })
                    .catch(() => { });
            }
            pollMsgBadge();
            setInterval(pollMsgBadge, 10000);
        });
    </script>
</body>

</html>
