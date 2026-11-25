/**
 * KitchTrack - Live Shopping List Controller
 * Auto-refreshes every 5 seconds via fetch() and handles all tap actions.
 */

(function () {
    const API_URL = 'api_shopping_list.php';
    const REFRESH_MS = 5000;

    const tripBannerEl = document.getElementById('tripBannerContainer');
    const pendingReceiveEl = document.getElementById('pendingReceiveContainer');
    const listEl = document.getElementById('shoppingListContainer');
    const syncStatusEl = document.getElementById('liveSyncStatus');
    const feedbackEl = document.getElementById('jsFeedback');

    let latestState = null;
    let isFetching = false;

    function esc(str) {
        return String(str ?? '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function showFeedback(type, message) {
        if (!feedbackEl) return;
        feedbackEl.className = `alert alert-${type} py-2 small`;
        feedbackEl.textContent = message;
        feedbackEl.classList.remove('d-none');
        clearTimeout(showFeedback._timer);
        showFeedback._timer = setTimeout(() => {
            feedbackEl.classList.add('d-none');
        }, 4000);
    }

    async function postAction(payload) {
        try {
            const res = await fetch(API_URL, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            });
            const data = await res.json();
            if (!res.ok || !data.ok) {
                showFeedback('danger', data.error || 'Action failed.');
                return false;
            }
            if (data.message) {
                showFeedback('success', data.message);
            }
            await fetchState();
            return true;
        } catch (err) {
            showFeedback('danger', 'Network error. Please check your connection.');
            return false;
        }
    }

    function renderTripBanner(state) {
        const trip = state.active_trip;
        const openCash = state.my_open_cash;
        const items = state.items || [];
        const inCartCount = items.filter(i => i.status === 'in_cart').length;

        if (!trip) {
            const cashPill = openCash
                ? `<span class="badge badge-status-pending ms-1">RM ${Number(openCash.cash_given).toFixed(2)} ready</span>`
                : '';

            tripBannerEl.innerHTML = `
                <div class="kt-trip-banner d-flex justify-content-between align-items-center gap-2">
                    <div class="d-flex align-items-center gap-2 min-w-0">
                        <i class="bi bi-bag text-brown fs-5 flex-shrink-0"></i>
                        <div class="small fw-semibold text-brown-dark text-truncate">
                            Ready to shop? ${cashPill}
                        </div>
                    </div>
                    <button type="button" class="btn btn-brown btn-sm px-3 py-2 flex-shrink-0 fw-semibold" id="btnStartTrip">
                        <i class="bi bi-bag-check-fill me-1"></i>Start Shopping
                    </button>
                </div>
            `;
            document.getElementById('btnStartTrip')?.addEventListener('click', () => {
                postAction({ action: 'start_trip' });
            });
            return;
        }

        if (trip.is_mine) {
            const cashBadge = trip.cash_given > 0
                ? `<span class="badge badge-status-pending ms-1">RM ${Number(trip.cash_given).toFixed(2)}</span>`
                : '';

            tripBannerEl.innerHTML = `
                <div class="kt-trip-banner active-mine d-flex flex-wrap justify-content-between align-items-center gap-2">
                    <div class="small fw-semibold text-brown-dark d-flex align-items-center flex-wrap gap-1">
                        <i class="bi bi-cart-check-fill text-brown"></i>
                        <span>Shopping Now</span>
                        <span class="text-muted-warm fw-normal">&middot; ${inCartCount} in cart</span>
                        ${cashBadge}
                    </div>
                    <div class="d-flex align-items-center gap-2">
                        <button type="button" class="btn btn-outline-brown btn-sm py-1 px-2" id="btnCancelTrip">
                            Release
                        </button>
                        <a href="checkout.php" class="btn btn-brown btn-sm py-1 px-3 fw-semibold">
                            <i class="bi bi-receipt me-1"></i>Checkout (${inCartCount})
                        </a>
                    </div>
                </div>
            `;
            document.getElementById('btnCancelTrip')?.addEventListener('click', () => {
                if (confirm('Release this shopping trip so another staff member can take over?')) {
                    postAction({ action: 'cancel_trip' });
                }
            });
        } else {
            tripBannerEl.innerHTML = `
                <div class="kt-trip-banner active-other d-flex align-items-center gap-2">
                    <i class="bi bi-lock-fill text-warning flex-shrink-0"></i>
                    <div class="small text-brown-dark">
                        <strong>Currently shopping: ${esc(trip.shopper_name)}</strong>
                        <span class="text-muted-warm ms-1">(since ${esc(trip.started_at)})</span>
                    </div>
                </div>
            `;
        }
    }

    function renderPendingReceive(state) {
        const trips = state.pending_receive_trips || [];
        const count = trips.length;

        // Keep Receive tab red badge live-updated
        document.querySelectorAll('.js-receive-badge').forEach(badge => {
            badge.textContent = String(count);
            badge.classList.toggle('d-none', count === 0);
        });

        if (count === 0) {
            pendingReceiveEl.innerHTML = '';
            return;
        }

        const label = count === 1
            ? '1 delivery waiting for check — Tap to verify'
            : `${count} deliveries waiting for check — Tap to verify`;

        pendingReceiveEl.innerHTML = `
            <a href="receive.php" class="kt-delivery-strip">
                <span class="d-flex align-items-center gap-2">
                    <i class="bi bi-box-seam-fill"></i>
                    <span>${esc(label)}</span>
                </span>
                <i class="bi bi-chevron-right"></i>
            </a>
        `;
    }

    function renderShoppingList(state) {
        const items = state.items || [];
        const isMyTrip = !!(state.active_trip && state.active_trip.is_mine);

        if (items.length === 0) {
            listEl.innerHTML = `
                <div class="kt-card p-5 text-center">
                    <i class="bi bi-check2-all fs-1 text-brown mb-2 d-block"></i>
                    <h2 class="h6 fw-bold text-brown-dark mb-1">All stocked up</h2>
                    <p class="small text-muted-warm mb-0">No items on the shopping list right now. Tap Add Item or Quick Add to request restock.</p>
                </div>
            `;
            return;
        }

        // Group by Store Category, ordering categories with urgent items first
        const groupsMap = new Map();
        items.forEach(item => {
            if (!groupsMap.has(item.category_name)) {
                groupsMap.set(item.category_name, { name: item.category_name, hasUrgent: false, items: [] });
            }
            const g = groupsMap.get(item.category_name);
            if (item.is_urgent) g.hasUrgent = true;
            g.items.push(item);
        });

        const groups = Array.from(groupsMap.values()).sort((a, b) => {
            if (a.hasUrgent !== b.hasUrgent) return a.hasUrgent ? -1 : 1;
            return a.name.localeCompare(b.name);
        });

        let html = '';
        groups.forEach(group => {
            // Ensure urgent items appear at the top inside every store group
            group.items.sort((a, b) => Number(b.is_urgent) - Number(a.is_urgent));

            const itemCards = group.items.map(item => {
                const cardClass = [
                    'kt-item-card mb-2',
                    item.is_urgent ? 'is-urgent' : '',
                    item.status === 'in_cart' ? 'is-in-cart' : '',
                    item.status === 'out_of_stock' ? 'is-out' : ''
                ].join(' ');

                const urgentBadge = item.is_urgent
                    ? '<span class="badge badge-urgent ms-1">URGENT</span>'
                    : '';

                let statusBadge = '<span class="badge badge-status-pending">Pending</span>';
                if (item.status === 'in_cart') {
                    statusBadge = '<span class="badge badge-status-in-cart"><i class="bi bi-cart-check me-1"></i>In Cart</span>';
                } else if (item.status === 'out_of_stock') {
                    statusBadge = '<span class="badge badge-status-out"><i class="bi bi-x-circle me-1"></i>Out of Stock</span>';
                }

                const currentUser = state.current_user || {};
                const canRemove = (item.status !== 'in_cart') && (
                    item.can_delete === true ||
                    Number(item.requested_by) === Number(currentUser.id) ||
                    currentUser.role === 'owner'
                );

                let actionControls = '';
                if (isMyTrip) {
                    actionControls = `
                        <div class="d-flex flex-wrap gap-2 mt-3 pt-2 border-top">
                            <button type="button" class="btn btn-sm btn-tap flex-fill ${item.status === 'pending' ? 'btn-brown' : 'btn-outline-brown'} btn-status"
                                    data-id="${item.id}" data-status="pending">
                                Pending
                            </button>
                            <button type="button" class="btn btn-sm btn-tap flex-fill ${item.status === 'in_cart' ? 'btn-success' : 'btn-outline-success'} btn-status"
                                    data-id="${item.id}" data-status="in_cart">
                                <i class="bi bi-cart-check"></i> In Cart
                            </button>
                            <button type="button" class="btn btn-sm btn-tap flex-fill ${item.status === 'out_of_stock' ? 'btn-danger' : 'btn-outline-danger'} btn-status"
                                    data-id="${item.id}" data-status="out_of_stock">
                                <i class="bi bi-slash-circle"></i> Out of Stock
                            </button>
                        </div>
                    `;
                }

                if (canRemove) {
                    actionControls += `
                        <div class="mt-2 d-flex justify-content-end">
                            <button type="button" class="btn btn-sm btn-link text-danger text-decoration-none p-0 btn-delete-item" data-id="${item.id}">
                                <i class="bi bi-trash me-1"></i>Remove
                            </button>
                        </div>
                    `;
                }

                return `
                    <div class="${cardClass}">
                        <div class="d-flex justify-content-between align-items-start gap-2">
                            <div>
                                <div class="fw-bold text-brown-dark d-flex align-items-center flex-wrap gap-1">
                                    <span>${esc(item.item_name)}</span>
                                    ${urgentBadge}
                                </div>
                                <div class="small text-muted-warm mt-1">
                                    <i class="bi bi-person me-1"></i>Requested by: <strong>${esc(item.requested_by_name)}</strong> at ${esc(item.requested_time)}
                                </div>
                            </div>
                            <div class="text-end flex-shrink-0">
                                <div class="fw-bold text-brown fs-6">${esc(item.quantity)} ${esc(item.unit)}</div>
                                <div class="mt-1">${statusBadge}</div>
                            </div>
                        </div>
                        ${actionControls}
                    </div>
                `;
            }).join('');

            html += `
                <div class="mb-4">
                    <div class="d-flex justify-content-between align-items-center mb-2 px-1">
                        <h2 class="h6 fw-bold text-brown-dark mb-0">
                            <i class="bi bi-shop me-1 text-brown"></i>${esc(group.name)}
                        </h2>
                        <span class="badge badge-status-pending">${group.items.length} item${group.items.length === 1 ? '' : 's'}</span>
                    </div>
                    ${itemCards}
                </div>
            `;
        });

        listEl.innerHTML = html;

        // Bind status buttons
        listEl.querySelectorAll('.btn-status').forEach(btn => {
            btn.addEventListener('click', () => {
                postAction({
                    action: 'update_status',
                    item_id: Number(btn.getAttribute('data-id')),
                    status: btn.getAttribute('data-status')
                });
            });
        });

        // Bind remove buttons
        listEl.querySelectorAll('.btn-delete-item').forEach(btn => {
            btn.addEventListener('click', () => {
                if (confirm('Remove this item from the shopping list?')) {
                    postAction({
                        action: 'delete_item',
                        item_id: Number(btn.getAttribute('data-id'))
                    });
                }
            });
        });
    }

    async function fetchState() {
        if (isFetching) return;
        isFetching = true;
        try {
            const res = await fetch(API_URL, { cache: 'no-store' });
            if (res.status === 401 || res.status === 403) {
                window.location.href = '../login.php';
                return;
            }
            const data = await res.json();
            if (data.ok) {
                latestState = data;
                renderTripBanner(data);
                renderPendingReceive(data);
                renderShoppingList(data);
                if (syncStatusEl) {
                    syncStatusEl.innerHTML = `<i class="bi bi-check-circle-fill text-success me-1"></i>Live &middot; Updated ${esc(data.server_time)}`;
                }
            }
        } catch (err) {
            if (syncStatusEl) {
                syncStatusEl.innerHTML = `<i class="bi bi-wifi-off text-danger me-1"></i>Connection issue. Retrying...`;
            }
        } finally {
            isFetching = false;
        }
    }

    // Add Item Form Submit
    const addForm = document.getElementById('addItemForm');
    if (addForm) {
        addForm.addEventListener('submit', async (e) => {
            e.preventDefault();
            const ok = await postAction({
                action: 'add_item',
                item_name: document.getElementById('item_name').value.trim(),
                quantity: document.getElementById('quantity').value,
                unit: document.getElementById('unit').value.trim(),
                category_id: document.getElementById('category_id').value,
                is_urgent: document.getElementById('is_urgent').checked ? 1 : 0
            });
            if (ok) {
                addForm.reset();
                document.getElementById('quantity').value = '1';
                document.getElementById('unit').value = 'kg';
                const modalEl = document.getElementById('addItemModal');
                const modal = bootstrap.Modal.getInstance(modalEl);
                if (modal) modal.hide();
            }
        });
    }

    // Quick Add Essential Buttons (1 click)
    document.querySelectorAll('.quick-add-btn').forEach(btn => {
        btn.addEventListener('click', async () => {
            const templateId = Number(btn.getAttribute('data-template-id'));
            const isUrgent = document.getElementById('quickAddUrgent')?.checked ? 1 : 0;
            btn.disabled = true;
            await postAction({
                action: 'quick_add',
                template_id: templateId,
                is_urgent: isUrgent
            });
            btn.disabled = false;
        });
    });

    // Initial load + 5-second live polling
    fetchState();
    setInterval(() => {
        if (!document.hidden) {
            fetchState();
        }
    }, REFRESH_MS);
})();
