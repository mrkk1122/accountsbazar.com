// Client Website JavaScript

document.addEventListener('DOMContentLoaded', function() {
    console.log('Client site loaded');

    initHeaderSearch();
    initNotificationCenter();
    initSupportChat();
    
    // Initialize shopping cart
    updateCartCount();
});

function initSupportChat() {
    const visitorStorageKey = 'ab_support_visitor_token';

    function getVisitorToken() {
        let token = localStorage.getItem(visitorStorageKey) || '';
        if (!token) {
            token = 'visitor_' + Math.random().toString(36).slice(2) + Date.now().toString(36);
            localStorage.setItem(visitorStorageKey, token);
        }
        return token;
    }

    const chatButton = document.createElement('button');
    chatButton.type = 'button';
    chatButton.className = 'support-chat-fab';
    chatButton.setAttribute('aria-label', 'Open support chat');
    chatButton.innerHTML = '<span class="support-chat-fab-icon">💬</span>';

    const chatPanel = document.createElement('div');
    chatPanel.className = 'support-chat-panel';
    chatPanel.innerHTML = '' +
        '<div class="support-chat-head">' +
            '<div>' +
                '<div class="support-chat-title">Messages</div>' +
                '<div class="support-chat-subtitle" id="support-chat-status">Chat with support</div>' +
            '</div>' +
            '<button type="button" class="support-chat-close" aria-label="Close">×</button>' +
        '</div>' +
        '<div class="support-chat-messages" id="support-chat-messages">Loading chat...</div>' +
        '<form class="support-chat-form" id="support-chat-form">' +
            '<input type="text" id="support-chat-input" class="support-chat-input" placeholder="Type your message..." maxlength="2000" required>' +
            '<button type="submit" class="support-chat-send">Send</button>' +
        '</form>';

    document.body.appendChild(chatButton);
    document.body.appendChild(chatPanel);

    const closeButton = chatPanel.querySelector('.support-chat-close');
    const messagesEl = chatPanel.querySelector('#support-chat-messages');
    const formEl = chatPanel.querySelector('#support-chat-form');
    const inputEl = chatPanel.querySelector('#support-chat-input');
    const sendButtonEl = chatPanel.querySelector('.support-chat-send');
    const statusEl = chatPanel.querySelector('#support-chat-status');
    const visitorToken = getVisitorToken();

    let chatLoaded = false;
    let chatLocked = false;
    let lastRenderedSignature = '';
    let lastMessageId = 0;
    let liveInterval = null;
    let isFirstLoad = true;

    function escapeHtml(value) {
        return String(value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    function renderMessages(messages, append) {
        if (!append && (!Array.isArray(messages) || messages.length === 0)) {
            messagesEl.innerHTML = '<div class="support-chat-empty">No messages yet. Start the conversation.</div>';
            return;
        }
        if (!Array.isArray(messages) || messages.length === 0) return;

        if (!append) {
            const signature = JSON.stringify(messages);
            if (signature === lastRenderedSignature && messagesEl.children.length > 0) {
                return;
            }
            lastRenderedSignature = signature;
            messagesEl.innerHTML = '';
        }

        messages.forEach(function(item) {
            const senderType = (item.sender_type || 'user').toString();
            const isAdmin = senderType === 'admin';
            const div = document.createElement('div');
            div.className = 'support-chat-bubble ' + (isAdmin ? 'admin' : 'user');
            div.innerHTML =
                '<div class="support-chat-bubble-role">' + (isAdmin ? 'Admin' : 'You') + '</div>' +
                '<div class="support-chat-bubble-text">' + escapeHtml(item.message_text || '') + '</div>' +
                '<div class="support-chat-bubble-time">' + escapeHtml(item.created_at || '') + '</div>';
            messagesEl.appendChild(div);
        });

        messagesEl.scrollTop = messagesEl.scrollHeight;
    }

    function requestChat(action, messageText, sinceId) {
        const fd = new FormData();
        fd.append('action', action);
        fd.append('visitor_token', visitorToken);
        if (messageText) {
            fd.append('message', messageText);
        }
        if (sinceId && sinceId > 0) {
            fd.append('since_id', sinceId);
        }

        return fetch('support-chat-api.php', {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            body: fd
        }).then(function(response) {
            return response.json();
        });
    }

    function loadChat(isLivePoll) {
        const sinceId = isLivePoll ? lastMessageId : 0;
        requestChat('load', null, sinceId)
            .then(function(res) {
                if (!res || !res.success) {
                    if (!isLivePoll) {
                        messagesEl.innerHTML = '<div class="support-chat-empty">Chat unavailable right now.</div>';
                    }
                    return;
                }

                if (res.locked) {
                    chatLoaded = true;
                    chatLocked = true;
                    inputEl.disabled = true;
                    if (sendButtonEl) sendButtonEl.disabled = true;
                    if (statusEl) {
                        statusEl.innerHTML = '<span class="support-chat-presence-dot offline"></span>Locked (Login Required)';
                    }
                    const redirectUrl = encodeURIComponent(window.location.pathname.split('/').pop() || 'index.php');
                    messagesEl.innerHTML = '<div class="support-chat-empty">Please <a href="login.php?redirect=' + redirectUrl + '">login</a> to start support chat.</div>';
                    return;
                }

                chatLoaded = true;
                chatLocked = false;
                inputEl.disabled = false;
                if (sendButtonEl) sendButtonEl.disabled = false;

                if (statusEl) {
                    if (res.admin_active) {
                        statusEl.innerHTML = '<span class="support-chat-presence-dot online"></span>Admin Active';
                    } else {
                        statusEl.innerHTML = '<span class="support-chat-presence-dot offline"></span>Admin Offline';
                    }
                }

                const newMessages = res.messages || [];
                if (res.last_id && res.last_id > 0) {
                    lastMessageId = res.last_id;
                }

                if (isLivePoll) {
                    renderMessages(newMessages, true);
                } else {
                    renderMessages(newMessages, false);
                }
            })
            .catch(function() {
                if (!isLivePoll) {
                    messagesEl.innerHTML = '<div class="support-chat-empty">Chat unavailable right now.</div>';
                }
            });
    }

    function startLivePolling() {
        if (liveInterval) return;
        liveInterval = setInterval(function() {
            if (chatLoaded && !chatLocked) {
                loadChat(true);
            }
        }, 2000);
    }

    function stopLivePolling() {
        if (liveInterval) {
            clearInterval(liveInterval);
            liveInterval = null;
        }
    }

    chatButton.addEventListener('click', function() {
        chatPanel.classList.toggle('open');
        chatButton.classList.toggle('chat-open', chatPanel.classList.contains('open'));
        if (chatPanel.classList.contains('open')) {
            if (!chatLoaded) {
                loadChat(false);
            }
            startLivePolling();
            inputEl.focus();
        } else {
            stopLivePolling();
        }
    });

    closeButton.addEventListener('click', function() {
        chatPanel.classList.remove('open');
        chatButton.classList.remove('chat-open');
        stopLivePolling();
    });

    formEl.addEventListener('submit', function(event) {
        event.preventDefault();
        if (chatLocked) {
            return;
        }
        const messageText = inputEl.value.trim();
        if (!messageText) {
            return;
        }

        inputEl.disabled = true;
        requestChat('send', messageText, lastMessageId)
            .then(function(res) {
                if (!res || !res.success) {
                    alert((res && res.message) || 'Message failed.');
                    return;
                }
                inputEl.value = '';
                if (res.last_id && res.last_id > 0) {
                    lastMessageId = res.last_id;
                }
                renderMessages(res.messages || [], true);
            })
            .catch(function() {
                alert('Message failed.');
            })
            .finally(function() {
                inputEl.disabled = false;
                inputEl.focus();
            });
    });

    document.addEventListener('click', function(event) {
        if (!chatPanel.contains(event.target) && !chatButton.contains(event.target)) {
            chatPanel.classList.remove('open');
            chatButton.classList.remove('chat-open');
        }
    });

    loadChat(false);
}

function initNotificationCenter() {
    let toggleButton = document.querySelector('[data-notification-toggle]');
    let badge = document.querySelector('[data-notif-badge]');

    if (!toggleButton || !badge) {
        const fallbackBtn = document.createElement('button');
        fallbackBtn.type = 'button';
        fallbackBtn.className = 'notif-fab';
        fallbackBtn.setAttribute('data-notification-toggle', '1');
        fallbackBtn.innerHTML = '🔔<span class="notif-badge" data-notif-badge style="display:none;">0</span>';
        document.body.appendChild(fallbackBtn);

        toggleButton = fallbackBtn;
        badge = fallbackBtn.querySelector('[data-notif-badge]');
    }

    if (!toggleButton || !badge) {
        return;
    }

    const panel = document.createElement('div');
    panel.className = 'notif-panel';
    panel.innerHTML = '' +
        '<div class="notif-panel-head">' +
            '<strong>Notifications</strong>' +
            '<button type="button" class="notif-close" aria-label="Close">x</button>' +
        '</div>' +
        '<div class="notif-list" id="notif-list">Loading...</div>';

    document.body.appendChild(panel);

    const closeButton = panel.querySelector('.notif-close');
    const listEl = panel.querySelector('#notif-list');

    function getSeenMap() {
        try {
            const data = localStorage.getItem('ab_seen_notif_uids');
            return data ? JSON.parse(data) : {};
        } catch (e) {
            return {};
        }
    }

    function setSeenMap(map) {
        localStorage.setItem('ab_seen_notif_uids', JSON.stringify(map));
    }

    function getPushedMap() {
        try {
            const data = localStorage.getItem('ab_pushed_notif_uids');
            return data ? JSON.parse(data) : {};
        } catch (e) {
            return {};
        }
    }

    function setPushedMap(map) {
        localStorage.setItem('ab_pushed_notif_uids', JSON.stringify(map));
    }

    function isHomePage() {
        const path = (window.location.pathname || '').toLowerCase();
        return path === '/' || path.endsWith('/index.php');
    }

    function urlBase64ToUint8Array(base64String) {
        const padding = '='.repeat((4 - (base64String.length % 4)) % 4);
        const base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
        const rawData = window.atob(base64);
        const outputArray = new Uint8Array(rawData.length);
        for (let i = 0; i < rawData.length; ++i) {
            outputArray[i] = rawData.charCodeAt(i);
        }
        return outputArray;
    }

    function getVapidPublicKey() {
        var fromWindow = (window.AB_WEBPUSH_PUBLIC_KEY || '').toString().trim();
        if (fromWindow) {
            return fromWindow;
        }

        var meta = document.querySelector('meta[name="ab-webpush-public-key"]');
        if (meta) {
            return (meta.getAttribute('content') || '').toString().trim();
        }

        return '';
    }

    function savePushSubscription(subscription) {
        if (!subscription) {
            return Promise.resolve(false);
        }

        return fetch('push-subscription.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                action: 'subscribe',
                subscription: subscription.toJSON()
            })
        }).then(function(response) {
            return response.json();
        }).then(function(data) {
            return !!(data && data.success);
        }).catch(function() {
            return false;
        });
    }

    function ensurePushSubscription() {
        const vapidKey = getVapidPublicKey();
        if (!vapidKey || !('serviceWorker' in navigator)) {
            return Promise.resolve(false);
        }

        return getServiceWorkerRegistration().then(function(reg) {
            if (!reg || !reg.pushManager) {
                return false;
            }

            return reg.pushManager.getSubscription().then(function(existing) {
                if (existing) {
                    return savePushSubscription(existing);
                }

                return reg.pushManager.subscribe({
                    userVisibleOnly: true,
                    applicationServerKey: urlBase64ToUint8Array(vapidKey)
                }).then(function(subscription) {
                    return savePushSubscription(subscription);
                });
            });
        }).catch(function() {
            return false;
        });
    }

    function ensureHomeNotificationPermission() {
        if (!isHomePage() || !('Notification' in window)) {
            return;
        }

        if (Notification.permission === 'granted') {
            ensurePushSubscription();
            return;
        }

        if (Notification.permission !== 'default') {
            return;
        }

        const askKey = 'ab_notif_permission_asked_once';
        try {
            if (sessionStorage.getItem(askKey) === '1') {
                return;
            }
            sessionStorage.setItem(askKey, '1');
        } catch (e) {
            // continue without session storage gate
        }

        setTimeout(function() {
            Notification.requestPermission().then(function(permission) {
                if (permission === 'granted') {
                    ensurePushSubscription();
                }
            }).catch(function() {
                return 'default';
            });
        }, 900);
    }

    let swRegistrationPromise = null;
    function getServiceWorkerRegistration() {
        if (!('serviceWorker' in navigator)) {
            return Promise.resolve(null);
        }

        if (!swRegistrationPromise) {
            swRegistrationPromise = navigator.serviceWorker.getRegistration().then(function(reg) {
                if (reg) {
                    return reg;
                }
                return navigator.serviceWorker.register('sw.js').catch(function() {
                    return null;
                });
            }).catch(function() {
                return null;
            });
        }

        return swRegistrationPromise;
    }

    function showSystemNotification(item) {
        if (!item || !item.uid || !('Notification' in window) || Notification.permission !== 'granted') {
            return;
        }

        const title = (item.title || 'Accounts Bazar').toString();
        const body = (item.message || '').toString();
        const targetUrl = (item.url || 'index.php').toString();

        const pushedMap = getPushedMap();
        if (pushedMap[item.uid]) {
            return;
        }

        const notificationOptions = {
            body: body,
            icon: 'images/logo.png',
            badge: 'favicon.png',
            tag: 'ab-' + item.uid,
            renotify: false,
            data: {
                url: targetUrl
            }
        };

        getServiceWorkerRegistration().then(function(reg) {
            if (reg && typeof reg.showNotification === 'function') {
                reg.showNotification(title, notificationOptions);
            } else {
                new Notification(title, {
                    body: body,
                    icon: 'images/logo.png'
                });
            }

            pushedMap[item.uid] = 1;
            setPushedMap(pushedMap);
        });
    }

    function renderItems(items, seenMap) {
        if (!Array.isArray(items) || items.length === 0) {
            listEl.innerHTML = '<div class="notif-empty">No notifications yet.</div>';
            return;
        }

        const html = items.map(function(item) {
            const unread = !seenMap[item.uid];
            const title = (item.title || 'Update').toString();
            const message = (item.message || '').toString();
            const url = (item.url || '#').toString();
            const time = (item.created_at || '').toString();
            return '' +
                '<a class="notif-item' + (unread ? ' unread' : '') + '" href="' + url + '">' +
                    '<div class="notif-title">' + title + '</div>' +
                    '<div class="notif-message">' + message + '</div>' +
                    '<div class="notif-time">' + time + '</div>' +
                '</a>';
        }).join('');

        listEl.innerHTML = html;

        listEl.querySelectorAll('.notif-item').forEach(function(link) {
            link.addEventListener('click', function() {
                panel.classList.remove('open');
            });
        });
    }

    function updateBadge(unreadCount) {
        if (unreadCount > 0) {
            badge.style.display = 'inline-flex';
            badge.textContent = unreadCount > 99 ? '99+' : String(unreadCount);
        } else {
            badge.style.display = 'none';
            badge.textContent = '0';
        }
    }

    function markAllSeen(items) {
        const seenMap = getSeenMap();
        items.forEach(function(item) {
            if (item && item.uid) {
                seenMap[item.uid] = 1;
            }
        });
        setSeenMap(seenMap);
    }

    let latestItems = [];

    function loadNotifications() {
        fetch('notifications-feed.php', { cache: 'no-store' })
            .then(function(response) { return response.json(); })
            .then(function(data) {
                if (!data || !data.success || !Array.isArray(data.items)) {
                    return;
                }

                latestItems = data.items;
                const seenMap = getSeenMap();
                const unreadCount = latestItems.filter(function(item) {
                    return item && item.uid && !seenMap[item.uid];
                }).length;

                const bootstrapKey = 'ab_notif_push_bootstrap_done';
                const pushedMap = getPushedMap();
                let shouldBootstrap = false;
                try {
                    shouldBootstrap = localStorage.getItem(bootstrapKey) !== '1';
                } catch (e) {
                    shouldBootstrap = false;
                }

                if (shouldBootstrap) {
                    latestItems.forEach(function(item) {
                        if (item && item.uid) {
                            pushedMap[item.uid] = 1;
                        }
                    });
                    setPushedMap(pushedMap);
                    try {
                        localStorage.setItem(bootstrapKey, '1');
                    } catch (e) {
                        // ignore storage errors
                    }
                } else {
                    latestItems.forEach(function(item) {
                        if (item && item.uid && !pushedMap[item.uid]) {
                            showSystemNotification(item);
                        }
                    });
                }

                updateBadge(unreadCount);
                renderItems(latestItems, seenMap);
            })
            .catch(function() {
                listEl.innerHTML = '<div class="notif-empty">Notification unavailable.</div>';
            });
    }

    toggleButton.addEventListener('click', function(event) {
        event.preventDefault();
        panel.classList.toggle('open');
        if (panel.classList.contains('open')) {
            markAllSeen(latestItems);
            updateBadge(0);
            renderItems(latestItems, getSeenMap());
        }
    });

    closeButton.addEventListener('click', function() {
        panel.classList.remove('open');
    });

    document.addEventListener('click', function(event) {
        if (!panel.contains(event.target) && !toggleButton.contains(event.target)) {
            panel.classList.remove('open');
        }
    });

    loadNotifications();
    ensureHomeNotificationPermission();
    setInterval(loadNotifications, 10000);
}

function initHeaderSearch() {
    const toggleButton = document.querySelector('.header-search-toggle');
    const searchForm = document.querySelector('.header-search-form');
    const searchInput = document.querySelector('.header-search-input');

    if (!toggleButton || !searchForm || !searchInput) {
        return;
    }

    toggleButton.addEventListener('click', function() {
        const isOpen = searchForm.classList.toggle('open');
        if (isOpen) {
            searchInput.focus();
        }
    });

    document.addEventListener('click', function(event) {
        if (!searchForm.contains(event.target) && !toggleButton.contains(event.target)) {
            searchForm.classList.remove('open');
        }
    });

    searchInput.addEventListener('keydown', function(event) {
        if (event.key === 'Escape') {
            searchForm.classList.remove('open');
            toggleButton.focus();
        }
    });
}

function updateCartCount() {
    // Get cart count from localStorage
    const cart = JSON.parse(localStorage.getItem('cart')) || [];
    const cartCount = cart.length;
    
    const cartLink = document.querySelector('a[href="cart.php"]');
    if (cartLink) {
        cartLink.textContent = 'Cart (' + cartCount + ')';
    }
}

function addToCart(productId) {
    const cart = JSON.parse(localStorage.getItem('cart')) || [];
    
    if (!cart.includes(productId)) {
        cart.push(productId);
        localStorage.setItem('cart', JSON.stringify(cart));
        updateCartCount();
        alert('Product added to cart!');
    } else {
        alert('Product already in cart!');
    }
}

function removeFromCart(productId) {
    let cart = JSON.parse(localStorage.getItem('cart')) || [];
    cart = cart.filter(id => id !== productId);
    localStorage.setItem('cart', JSON.stringify(cart));
    updateCartCount();
}
