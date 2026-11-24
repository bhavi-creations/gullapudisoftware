<?php
/*
|--------------------------------------------------------------------------
| STORES - MySQL `stores` table
|--------------------------------------------------------------------------
| Shops / godowns of the business. The names saved here are the choices of
| the "Store" field on Add Product, Quotations and Orders.
| Renaming a store also renames it on the products / quotations / orders
| that use it, so old records stay linked.
|--------------------------------------------------------------------------
*/
require_once __DIR__ . '/includes/init.php';

function stores_all(): array
{
    store_names(); // makes sure at least one store exists
    $rows = db_all('SELECT s.id, s.name, s.address, s.phone, s.enabled,
                           (SELECT COUNT(*) FROM products p WHERE p.store = s.name) AS products,
                           (SELECT COUNT(*) FROM quotations q WHERE q.store = s.name) AS quotations,
                           (SELECT COUNT(*) FROM orders o WHERE o.store = s.name) AS orders
                    FROM stores s ORDER BY s.id');
    foreach ($rows as &$r) {
        $r['id'] = (int)$r['id'];
        $r['enabled'] = (bool)$r['enabled'];
        $r['products'] = (int)$r['products'];
        $r['quotations'] = (int)$r['quotations'];
        $r['orders'] = (int)$r['orders'];
    }
    unset($r);
    return $rows;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf_json();
    $action = $_POST['action'] ?? '';
    $id = (int)($_POST['id'] ?? 0);
    $store = $id ? db_one('SELECT * FROM stores WHERE id = ?', [$id]) : null;

    if ($action === 'toggle_store') {
        if (!$store) {
            json_response(['success' => false, 'message' => 'Store not found.'], 404);
        }
        if ($store['enabled'] && (int)db_value('SELECT COUNT(*) FROM stores WHERE enabled = 1') <= 1) {
            json_response(['success' => false, 'message' => 'Keep at least one store enabled.'], 422);
        }
        db_update('stores', ['enabled' => $store['enabled'] ? 0 : 1], $id);
        log_activity('Stores', ($store['enabled'] ? 'Disabled' : 'Enabled') . ' store ' . $store['name']);
        json_response(['success' => true, 'stores' => stores_all()]);
    }

    if ($action === 'delete_store') {
        if (!$store) {
            json_response(['success' => false, 'message' => 'Store not found.'], 404);
        }
        if ((int)db_value('SELECT COUNT(*) FROM stores') <= 1) {
            json_response(['success' => false, 'message' => 'Keep at least one store.'], 422);
        }
        db_exec('DELETE FROM stores WHERE id = ?', [$id]);
        log_activity('Stores', 'Deleted store ' . $store['name']);
        json_response(['success' => true, 'stores' => stores_all()]);
    }

    if ($action === 'save_store') {
        $name = mb_substr(trim((string)($_POST['name'] ?? '')), 0, 100);
        $address = mb_substr(trim((string)($_POST['address'] ?? '')), 0, 255);
        $phone = mb_substr(preg_replace('/[^0-9+ \-]/', '', (string)($_POST['phone'] ?? '')), 0, 20);
        $enabled = ($_POST['enabled'] ?? '1') === '1' ? 1 : 0;

        if ($name === '') {
            json_response(['success' => false, 'message' => 'Enter the store name.'], 422);
        }
        if ($id && !$store) {
            json_response(['success' => false, 'message' => 'Store not found.'], 404);
        }
        if (db_value('SELECT COUNT(*) FROM stores WHERE LOWER(name) = LOWER(?) AND id <> ?', [$name, $id])) {
            json_response(['success' => false, 'message' => 'A store with this name already exists.'], 409);
        }
        if (!$enabled && (!$store || $store['enabled'])
            && (int)db_value('SELECT COUNT(*) FROM stores WHERE enabled = 1 AND id <> ?', [$id]) === 0) {
            json_response(['success' => false, 'message' => 'Keep at least one store enabled.'], 422);
        }

        $data = ['name' => $name, 'address' => $address ?: null, 'phone' => $phone ?: null, 'enabled' => $enabled];
        $pdo = db();
        $pdo->beginTransaction();
        if ($store) {
            db_update('stores', $data, $id);
            if ($store['name'] !== $name) {
                // Keep saved records linked to the renamed store.
                foreach (['products', 'quotations', 'orders'] as $table) {
                    db_exec("UPDATE `$table` SET store = ? WHERE store = ?", [$name, $store['name']]);
                }
            }
            $message = 'Store updated.';
            log_activity('Stores', 'Updated store ' . $name);
        } else {
            db_insert('stores', $data);
            $message = 'Store added.';
            log_activity('Stores', 'Added store ' . $name);
        }
        $pdo->commit();
        json_response(['success' => true, 'message' => $message, 'stores' => stores_all()]);
    }

    json_response(['success' => false, 'message' => 'Invalid action.'], 400);
}

$stores = stores_all();
$pageTitle = 'Stores';
include 'header.php';
?>

<style>
.st-section .st-title { font-size: 24px; font-weight: 800; margin: 0 0 4px; }
.st-section .st-sub { color: var(--p-text-2); margin: 0; font-size: 14px; }
.st-add { border: 0; background: linear-gradient(135deg, #2563eb, #4f46e5); color: #fff; font-weight: 800; border-radius: 11px; padding: 10px 18px; box-shadow: 0 8px 20px rgba(37, 99, 235, .28); }
.st-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 16px; }
.st-card { background: #fff; border: 1px solid var(--p-border); border-radius: 16px; box-shadow: var(--p-shadow); padding: 18px; display: flex; flex-direction: column; gap: 12px; }
.st-card.off { opacity: .7; }
.st-head { display: flex; gap: 12px; align-items: flex-start; }
.st-icon { width: 46px; height: 46px; flex: 0 0 46px; border-radius: 13px; display: grid; place-items: center; font-size: 21px; color: #fff; background: linear-gradient(135deg, #0ea5e9, #2563eb); }
.st-card.off .st-icon { background: #94a3b8; }
.st-name { font-weight: 800; font-size: 16px; margin: 0; word-break: break-word; }
.st-meta { font-size: 13px; color: var(--p-text-2); margin-top: 2px; }
.st-status { margin-left: auto; border: 0; border-radius: 20px; padding: 4px 11px; font-size: 12px; font-weight: 800; background: #e8f8ee; color: #15803d; white-space: nowrap; }
.st-status.off { background: #fde8e8; color: #b91c1c; }
.st-used { display: grid; grid-template-columns: repeat(3, 1fr); gap: 8px; }
.st-used div { background: #f7f9fd; border-radius: 10px; padding: 8px; text-align: center; }
.st-used b { display: block; font-size: 17px; }
.st-used span { font-size: 11px; color: var(--p-muted); text-transform: uppercase; font-weight: 700; letter-spacing: .03em; }
.st-actions { display: flex; gap: 8px; }
.st-actions button { flex: 1; border: 1px solid var(--p-border); background: #fff; border-radius: 9px; padding: 7px; font-weight: 700; font-size: 13px; color: var(--p-text-2); }
.st-actions button:hover { color: var(--p-primary); border-color: var(--p-primary); }
.st-actions .del:hover { color: #dc2626; border-color: #dc2626; }
.st-empty { text-align: center; color: var(--p-muted); padding: 50px 10px; }
.st-modal .modal-content { border: 0; border-radius: 18px; overflow: hidden; }
</style>

<section class="st-section">
<div class="container-fluid p-0">

    <div class="row align-items-center g-3 mb-4">
        <div class="col-md-8">
            <h1 class="st-title">Stores</h1>
            <p class="st-sub">Your shops and godowns. These names appear in the Store field of Products, Quotations and Orders.</p>
        </div>
        <div class="col-md-4 text-md-end">
            <button type="button" class="st-add store-add-btn" id="addStoreBtn" data-perm="add"><i class="bi bi-plus-lg"></i> Add Store</button>
        </div>
    </div>

    <div class="mb-3" style="max-width:340px">
        <input type="search" class="form-control" id="storeSearch" placeholder="Search store...">
    </div>

    <div class="st-grid" id="storeGrid"></div>

</div>
</section>

<!-- ADD / EDIT MODAL -->
<div class="modal fade st-modal" id="storeModal" tabindex="-1" aria-labelledby="storeModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form class="modal-content" id="storeForm" autocomplete="off">
            <div class="modal-header">
                <h5 class="modal-title fw-bold" id="storeModalTitle">Add Store</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <label class="form-label fw-semibold" for="storeName">Store Name <span class="text-danger">*</span></label>
                <input type="text" class="form-control" id="storeName" maxlength="100" required placeholder="e.g. Main Shop, Godown">

                <label class="form-label fw-semibold mt-3" for="storeAddress">Address</label>
                <input type="text" class="form-control" id="storeAddress" maxlength="255" placeholder="Optional">

                <label class="form-label fw-semibold mt-3" for="storePhone">Phone</label>
                <input type="text" class="form-control" id="storePhone" maxlength="20" placeholder="Optional">

                <div class="form-check form-switch mt-3">
                    <input class="form-check-input" type="checkbox" role="switch" id="storeEnabled" checked>
                    <label class="form-check-label" for="storeEnabled">Enabled (can be selected on products, quotations and orders)</label>
                </div>
                <p class="small text-muted mt-2 mb-0" id="storeRenameNote"></p>
                <div class="alert alert-danger d-none mt-3 mb-0" id="storeError"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light border" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary fw-semibold" id="storeSave">Save</button>
            </div>
        </form>
    </div>
</div>

<script>
(function () {
    "use strict";

    let stores = <?php echo json_encode($stores, json_flags()); ?>;
    const $ = id => document.getElementById(id);
    const esc = App.esc;
    const modal = bootstrap.Modal.getOrCreateInstance($("storeModal"));
    let editing = null;

    function render() {
        const q = $("storeSearch").value.trim().toLowerCase();
        const rows = stores.filter(s => [s.name, s.address, s.phone].join(" ").toLowerCase().includes(q));
        $("storeGrid").innerHTML = rows.length ? rows.map(s => `
            <div class="st-card ${s.enabled ? "" : "off"}">
                <div class="st-head">
                    <div class="st-icon"><i class="bi bi-shop"></i></div>
                    <div>
                        <h3 class="st-name">${esc(s.name)}</h3>
                        <div class="st-meta">${s.address ? '<i class="bi bi-geo-alt"></i> ' + esc(s.address) : "No address"}</div>
                        ${s.phone ? `<div class="st-meta"><i class="bi bi-telephone"></i> ${esc(s.phone)}</div>` : ""}
                    </div>
                    <button type="button" class="st-status ${s.enabled ? "" : "off"}" data-perm="edit" data-toggle="${s.id}" title="Click to ${s.enabled ? "disable" : "enable"}">${s.enabled ? "Enabled" : "Disabled"}</button>
                </div>
                <div class="st-used">
                    <div><b>${s.products}</b><span>Products</span></div>
                    <div><b>${s.quotations}</b><span>Quotations</span></div>
                    <div><b>${s.orders}</b><span>Orders</span></div>
                </div>
                <div class="st-actions">
                    <button type="button" data-edit="${s.id}" title="Edit Store"><i class="bi bi-pencil"></i> Edit</button>
                    <button type="button" class="del" data-delete="${s.id}" title="Delete Store"><i class="bi bi-trash3"></i> Delete</button>
                </div>
            </div>`).join("")
            : '<div class="st-empty">No stores found. Click "Add Store".</div>';
    }

    function open(store) {
        editing = store || null;
        $("storeModalTitle").textContent = store ? "Edit Store" : "Add Store";
        $("storeName").value = store ? store.name : "";
        $("storeAddress").value = store ? (store.address || "") : "";
        $("storePhone").value = store ? (store.phone || "") : "";
        $("storeEnabled").checked = store ? store.enabled : true;
        const used = store ? store.products + store.quotations + store.orders : 0;
        $("storeRenameNote").textContent = used ? "Renaming also updates the products, quotations and orders that use this store." : "";
        $("storeError").classList.add("d-none");
        modal.show();
        setTimeout(() => $("storeName").focus(), 300);
    }

    $("addStoreBtn").addEventListener("click", () => open(null));
    $("storeSearch").addEventListener("input", render);

    $("storeForm").addEventListener("submit", async ev => {
        ev.preventDefault();
        if (!ev.target.reportValidity()) return;
        const btn = $("storeSave");
        btn.disabled = true;
        try {
            const r = await App.post(location.pathname, {
                action: "save_store",
                id: editing ? editing.id : "",
                name: $("storeName").value.trim(),
                address: $("storeAddress").value.trim(),
                phone: $("storePhone").value.trim(),
                enabled: $("storeEnabled").checked ? "1" : "0"
            });
            stores = r.stores;
            render();
            modal.hide();
            App.toast(r.message);
        } catch (err) {
            $("storeError").textContent = err.message;
            $("storeError").classList.remove("d-none");
        } finally {
            btn.disabled = false;
        }
    });

    $("storeGrid").addEventListener("click", async ev => {
        const t = ev.target.closest("[data-toggle]");
        const e = ev.target.closest("[data-edit]");
        const d = ev.target.closest("[data-delete]");
        try {
            if (t) {
                stores = (await App.post(location.pathname, { action: "toggle_store", id: t.dataset.toggle })).stores;
                render();
            } else if (e) {
                open(stores.find(s => s.id === Number(e.dataset.edit)));
            } else if (d) {
                const s = stores.find(x => x.id === Number(d.dataset.delete));
                const used = s.products + s.quotations + s.orders;
                const note = used ? `\nIt is used on ${used} saved records; they keep the name "${s.name}".` : "";
                if (!confirm(`Delete store "${s.name}"?${note}`)) return;
                stores = (await App.post(location.pathname, { action: "delete_store", id: s.id })).stores;
                render();
                App.toast("Store deleted.");
            }
        } catch (err) {
            App.toast(err.message, "error");
        }
    });

    render();
})();
</script>

<?php include 'footer.php'; ?>
