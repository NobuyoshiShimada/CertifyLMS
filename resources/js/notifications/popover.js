/**
 * TopBar 通知ベルのポップオーバー(素の JS + fetch)。
 *
 * - ベルクリックで開閉(外側クリック / Esc でも閉じる)。開くたびに現在のタブで通知 JSON API を取得して描画する
 * - タブ: 「全件」/「未読のみ」。未読タブのバッジと TopBar のバッジは API の unread_count で同期する
 * - 行クリック: 単一既読化 API → バッジ / 未読タブ -1 → close → 遷移(遷移先が解決できなければ /notifications)
 * - 全件既読: 全件既読化 API → バッジ / 未読タブ 0 → 現在のタブで再取得
 *
 * 認証は Sanctum SPA Cookie(同一オリジンのセッション Cookie)。POST の CSRF 対策として、
 * 最初の API 呼び出し前に 1 度だけ /sanctum/csrf-cookie を叩き、XSRF-TOKEN Cookie を X-XSRF-TOKEN ヘッダで送る。
 * HTML(パネル / 行テンプレート / バッジ)は Blade で提供済みで、本モジュールは data-* 属性経由で DOM を書き換えるだけ。
 */

import { getJson, postJson } from '../utils/fetch-json';

const API_BASE = '/api/v1/notifications';
const CSRF_COOKIE_URL = '/sanctum/csrf-cookie';
const FALLBACK_URL = '/notifications';
const PER_PAGE = 20;
const TABS = { all: '全件', unread: '未読のみ' };

/** 業務 URL へ遷移する通知種別(ChatMessageReceived / Meeting* / QA 系)。 */
const URL_BASED_TYPES = new Set([
    'chat_message_received',
    'meeting_reserved',
    'meeting_canceled',
    'meeting_reminder',
    'qa_reply_received',
]);

let csrfCookiePromise = null;

/** CSRF Cookie は各ページで初回の API 呼び出し前に 1 度だけ取得する(多重呼び出しは同じ Promise を共有)。 */
function ensureCsrfCookie() {
    if (csrfCookiePromise === null) {
        csrfCookiePromise = fetch(CSRF_COOKIE_URL, { credentials: 'same-origin' }).then((response) => {
            if (!response.ok) {
                csrfCookiePromise = null;
                throw new Error(`HTTP ${response.status}`);
            }
        });
    }
    return csrfCookiePromise;
}

function xsrfHeaders() {
    const match = document.cookie.split('; ').find((row) => row.startsWith('XSRF-TOKEN='));
    return match ? { 'X-XSRF-TOKEN': decodeURIComponent(match.slice('XSRF-TOKEN='.length)) } : {};
}

async function apiGet(path) {
    await ensureCsrfCookie();
    return getJson(path, { headers: xsrfHeaders() });
}

async function apiPost(path) {
    await ensureCsrfCookie();
    return postJson(path, null, { headers: xsrfHeaders() });
}

/** 通知の遷移先 URL を解決する。お知らせは通知詳細、業務通知は業務 URL、それ以外は通知一覧へフォールバック。 */
export function resolveNotificationUrl(notification) {
    if (notification.type === 'admin_announcement') {
        return notification.detail_url ?? FALLBACK_URL;
    }
    if (URL_BASED_TYPES.has(notification.type) && notification.url) {
        return notification.url;
    }
    return FALLBACK_URL;
}

function formatBadge(count) {
    return count > 99 ? '99+' : String(count);
}

export function initNotificationPopover() {
    const root = document.querySelector('[data-notification-popover-root]');
    if (!root) return;

    const trigger = root.querySelector('[data-notification-popover-trigger]');
    const panel = root.querySelector('[data-notification-popover-panel]');
    const badge = root.querySelector('[data-notification-popover-badge]');
    const tabButtons = [...root.querySelectorAll('[data-notification-popover-tab]')];
    const unreadTabCount = root.querySelector('[data-notification-popover-unread-count]');
    const markAllButton = root.querySelector('[data-notification-popover-mark-all]');
    const loading = root.querySelector('[data-notification-popover-loading]');
    const empty = root.querySelector('[data-notification-popover-empty]');
    const items = root.querySelector('[data-notification-popover-items]');
    const rowTemplate = root.querySelector('[data-notification-popover-row-template]');
    if (!trigger || !panel || !items || !rowTemplate) return;

    const state = {
        open: false,
        tab: 'all',
        unreadCount: Number.parseInt((badge?.textContent ?? '0').replace('+', ''), 10) || 0,
        requestId: 0,
    };

    function renderUnreadCount(count) {
        state.unreadCount = Math.max(0, count);
        if (badge) {
            badge.textContent = formatBadge(state.unreadCount);
            badge.classList.toggle('hidden', state.unreadCount <= 0);
        }
        if (unreadTabCount) {
            unreadTabCount.textContent = formatBadge(state.unreadCount);
        }
        trigger.setAttribute('aria-label', `通知 (${state.unreadCount} 件未読)`);
    }

    function setLoading(isLoading) {
        loading?.classList.toggle('hidden', !isLoading);
        if (isLoading) {
            empty?.classList.add('hidden');
        }
    }

    function renderRows(notifications) {
        items.replaceChildren();
        empty?.classList.toggle('hidden', notifications.length > 0);

        notifications.forEach((notification) => {
            const fragment = rowTemplate.content.cloneNode(true);
            const row = fragment.querySelector('[data-notification-popover-row]');
            row.href = resolveNotificationUrl(notification);
            row.dataset.unread = notification.is_unread ? 'true' : 'false';
            row.classList.toggle('bg-primary-50/40', notification.is_unread);
            fragment.querySelector('[data-notification-popover-row-dot]')?.classList.toggle('invisible', !notification.is_unread);
            fragment.querySelector('[data-notification-popover-row-title]').textContent = notification.title;
            fragment.querySelector('[data-notification-popover-row-message]').textContent = notification.message;
            fragment.querySelector('[data-notification-popover-row-time]').textContent = notification.created_at_human ?? '';

            row.addEventListener('click', (event) => {
                event.preventDefault();
                openNotification(notification);
            });

            items.appendChild(fragment);
        });
    }

    async function load() {
        const requestId = ++state.requestId;
        setLoading(true);
        try {
            const query = new URLSearchParams({ tab: TABS[state.tab], per_page: String(PER_PAGE) });
            const payload = await apiGet(`${API_BASE}?${query}`);
            if (requestId !== state.requestId) return;
            renderRows(payload.data ?? []);
            renderUnreadCount(payload.meta?.unread_count ?? 0);
        } catch (error) {
            // ポップオーバーは非同期取得のためフラッシュは出さず、表示中のリストを維持する
            console.error('通知の取得に失敗しました', error);
        } finally {
            if (requestId === state.requestId) setLoading(false);
        }
    }

    async function openNotification(notification) {
        const destination = resolveNotificationUrl(notification);
        if (notification.is_unread) {
            try {
                const payload = await apiPost(`${API_BASE}/${encodeURIComponent(notification.id)}/read`);
                renderUnreadCount(payload.meta?.unread_count ?? state.unreadCount - 1);
            } catch (error) {
                console.error('通知の既読化に失敗しました', error);
            }
        }
        close();
        window.location.href = destination;
    }

    async function markAllAsRead() {
        markAllButton.disabled = true;
        try {
            await apiPost(`${API_BASE}/read-all`);
            renderUnreadCount(0);
            await load();
        } catch (error) {
            console.error('通知の一括既読化に失敗しました', error);
        } finally {
            markAllButton.disabled = false;
        }
    }

    function selectTab(tab) {
        state.tab = tab;
        tabButtons.forEach((button) => {
            button.setAttribute('aria-selected', button.dataset.notificationPopoverTab === tab ? 'true' : 'false');
        });
        load();
    }

    function open() {
        state.open = true;
        panel.classList.remove('hidden');
        panel.style.display = 'flex';
        requestAnimationFrame(() => panel.classList.remove('opacity-0', '-translate-y-1'));
        trigger.setAttribute('aria-expanded', 'true');
        load();
    }

    function close() {
        if (!state.open) return;
        state.open = false;
        panel.classList.add('hidden', 'opacity-0', '-translate-y-1');
        panel.style.display = 'none';
        trigger.setAttribute('aria-expanded', 'false');
    }

    trigger.addEventListener('click', (event) => {
        event.preventDefault();
        event.stopPropagation();
        state.open ? close() : open();
    });

    panel.addEventListener('click', (event) => event.stopPropagation());
    document.addEventListener('click', () => close());
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && state.open) {
            close();
            trigger.focus();
        }
    });

    tabButtons.forEach((button) => {
        button.addEventListener('click', () => selectTab(button.dataset.notificationPopoverTab));
    });
    markAllButton?.addEventListener('click', () => markAllAsRead());

    renderUnreadCount(state.unreadCount);
}
