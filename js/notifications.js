/**
 * notifications.js — the topbar notification bell (all roles).
 * Facebook-style: unread badge, a dropdown panel with read/unread items, delete,
 * relative timestamps, "load more" + infinite scroll, and background unread polling.
 * Talks to ajax/notifications.php; markup is in includes/dashboard_layout.php.
 */
(function () {
    'use strict';

    var PAGE_SIZE = 10;

    function escapeHtml(value) {
        return String(value == null ? '' : value).replace(/[&<>"']/g, function (ch) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ch];
        });
    }

    function setupBell(root) {
        var url = root.getAttribute('data-ajax-url');
        var csrf = root.getAttribute('data-csrf') || '';
        var toggle = root.querySelector('[data-notif-toggle]');
        var panel = root.querySelector('[data-notif-panel]');
        var listEl = root.querySelector('[data-notif-list]');
        var badge = root.querySelector('[data-notif-badge]');
        var emptyEl = root.querySelector('[data-notif-empty]');
        var footEl = root.querySelector('[data-notif-foot]');
        var loadMoreBtn = root.querySelector('[data-notif-loadmore]');
        var markAllBtn = root.querySelector('[data-notif-markall]');

        if (!url || !toggle || !panel || !listEl) {
            return;
        }

        var state = { loaded: false, loading: false, hasMore: false, oldestId: 0, unread: 0 };

        function post(params) {
            var body = new URLSearchParams(Object.assign({ csrf_token: csrf }, params));
            return fetch(url, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: body.toString()
            }).then(function (r) { return r.json().catch(function () { return { ok: false }; }); });
        }

        function setBadge(count) {
            state.unread = count;
            if (!badge) {
                return;
            }
            if (count > 0) {
                badge.textContent = count > 99 ? '99+' : String(count);
                badge.hidden = false;
            } else {
                badge.hidden = true;
            }
        }

        function itemMarkup(n) {
            var unreadClass = n.is_read ? '' : ' is-unread';
            var body = n.body ? '<p class="notification-item-body">' + escapeHtml(n.body) + '</p>' : '';
            return ''
                + '<div class="notification-item' + unreadClass + '" data-notif-item data-id="' + n.id + '"'
                + ' data-link="' + escapeHtml(n.link) + '" data-read="' + (n.is_read ? '1' : '0') + '" role="button" tabindex="0">'
                + '  <span class="notification-item-icon"><i class="bi ' + escapeHtml(n.icon) + '"></i></span>'
                + '  <div class="notification-item-main">'
                + '    <p class="notification-item-title">' + escapeHtml(n.title) + '</p>'
                + body
                + '    <span class="notification-item-time">' + escapeHtml(n.time_ago) + '</span>'
                + '  </div>'
                + '  <span class="notification-item-dot" aria-hidden="true"></span>'
                + '  <button type="button" class="notification-item-delete" data-notif-delete aria-label="Delete notification"><i class="bi bi-x-lg"></i></button>'
                + '</div>';
        }

        function renderItems(items, append) {
            if (!append) {
                listEl.innerHTML = '';
            }
            items.forEach(function (n) {
                listEl.insertAdjacentHTML('beforeend', itemMarkup(n));
            });
            updateEmpty();
        }

        function updateEmpty() {
            var has = listEl.querySelector('[data-notif-item]');
            if (emptyEl) { emptyEl.hidden = !!has; }
            if (footEl) { footEl.hidden = !state.hasMore; }
        }

        function load(reset) {
            if (state.loading) {
                return;
            }
            state.loading = true;
            if (loadMoreBtn) { loadMoreBtn.disabled = true; }
            return post({ action: 'list', limit: PAGE_SIZE, before_id: reset ? 0 : state.oldestId }).then(function (res) {
                state.loading = false;
                if (loadMoreBtn) { loadMoreBtn.disabled = false; }
                var loading = listEl.querySelector('[data-notif-loading]');
                if (loading) { loading.remove(); }
                if (!res || !res.ok) {
                    return;
                }
                state.loaded = true;
                state.hasMore = !!res.has_more;
                if (res.items.length) {
                    state.oldestId = res.items[res.items.length - 1].id;
                }
                renderItems(res.items, !reset);
                setBadge(res.unread_count);
            });
        }

        function markRead(id, itemEl) {
            if (itemEl.getAttribute('data-read') === '1') {
                return;
            }
            itemEl.setAttribute('data-read', '1');
            itemEl.classList.remove('is-unread');
            post({ action: 'mark_read', id: id }).then(function (res) {
                if (res && res.ok) { setBadge(res.unread_count); }
            });
        }

        function removeItem(id, itemEl) {
            post({ action: 'delete', id: id }).then(function (res) {
                if (res && res.ok) {
                    itemEl.remove();
                    setBadge(res.unread_count);
                    updateEmpty();
                }
            });
        }

        function markAll() {
            post({ action: 'mark_all_read' }).then(function (res) {
                if (res && res.ok) {
                    listEl.querySelectorAll('[data-notif-item]').forEach(function (el) {
                        el.setAttribute('data-read', '1');
                        el.classList.remove('is-unread');
                    });
                    setBadge(0);
                }
            });
        }

        function openPanel() {
            panel.hidden = false;
            toggle.setAttribute('aria-expanded', 'true');
            root.classList.add('is-open');
            if (!state.loaded) {
                load(true);
            }
        }

        function closePanel() {
            panel.hidden = true;
            toggle.setAttribute('aria-expanded', 'false');
            root.classList.remove('is-open');
        }

        toggle.addEventListener('click', function (e) {
            e.stopPropagation();
            if (panel.hidden) { openPanel(); } else { closePanel(); }
        });

        // Item interactions (event-delegated).
        listEl.addEventListener('click', function (e) {
            var del = e.target.closest('[data-notif-delete]');
            var item = e.target.closest('[data-notif-item]');
            if (!item) {
                return;
            }
            var id = parseInt(item.getAttribute('data-id'), 10);
            if (del) {
                e.stopPropagation();
                removeItem(id, item);
                return;
            }
            markRead(id, item);
            var link = item.getAttribute('data-link');
            if (link) {
                window.location.href = link;
            }
        });

        listEl.addEventListener('keydown', function (e) {
            if ((e.key === 'Enter' || e.key === ' ') && e.target.closest('[data-notif-item]')) {
                e.preventDefault();
                e.target.closest('[data-notif-item]').click();
            }
        });

        // Infinite scroll within the panel list.
        listEl.addEventListener('scroll', function () {
            if (state.hasMore && !state.loading && listEl.scrollTop + listEl.clientHeight >= listEl.scrollHeight - 40) {
                load(false);
            }
        });

        if (loadMoreBtn) {
            loadMoreBtn.addEventListener('click', function () { load(false); });
        }
        if (markAllBtn) {
            markAllBtn.addEventListener('click', markAll);
        }

        // Close on outside click / Escape.
        document.addEventListener('click', function (e) {
            if (!root.contains(e.target) && !panel.hidden) {
                closePanel();
            }
        });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && !panel.hidden) {
                closePanel();
            }
        });

        // Background unread polling (light — count only).
        function refreshCount() {
            post({ action: 'unread_count' }).then(function (res) {
                if (res && res.ok) {
                    setBadge(res.unread_count);
                    // If the panel is open and new items likely arrived, refresh the top.
                    if (!panel.hidden && res.unread_count > state.unread) {
                        load(true);
                    }
                }
            });
        }
        window.setInterval(refreshCount, 60000);
    }

    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('[data-notifications]').forEach(setupBell);
    });
})();
