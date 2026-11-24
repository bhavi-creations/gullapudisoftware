<?php
/*
|--------------------------------------------------------------------------
| TAXES (GST RATES) - MySQL `taxes` table
|--------------------------------------------------------------------------
| Add ONE GST rate (e.g. 18%). It is applied automatically:
|   Inside the state  (same state as the business) -> CGST 9% + SGST 9%
|   Outside the state (other state customer)       -> IGST 18%
| The rate is picked on each product (Products > Tax) and comes into
| quotations and invoices from there.
|--------------------------------------------------------------------------
*/
require_once __DIR__ . '/includes/init.php';

function taxes_all(): array
{
    $rows = db_all('SELECT t.id, t.name, t.code, t.amount, t.enabled,
                           (SELECT COUNT(*) FROM products p WHERE p.tax_id = t.id) AS products
                    FROM taxes t ORDER BY t.amount, t.id');
    foreach ($rows as &$r) {
        $r['id'] = (int)$r['id'];
        $r['rate'] = (float)$r['amount'];
        $r['enabled'] = (bool)$r['enabled'];
        $r['products'] = (int)$r['products'];
        unset($r['amount']);
    }
    return $rows;
}

function rate_label(float $v): string
{
    return rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf_json();
    $action = $_POST['action'] ?? '';
    $id = (int)($_POST['id'] ?? 0);

    if ($action === 'toggle_tax') {
        db_exec('UPDATE taxes SET enabled = 1 - enabled WHERE id = ?', [$id]);
        json_response(['success' => true, 'taxes' => taxes_all()]);
    }

    if ($action === 'delete_tax') {
        $name = db_value('SELECT name FROM taxes WHERE id = ?', [$id]);
        db_exec('UPDATE products SET tax_id = NULL WHERE tax_id = ?', [$id]);
        db_exec('DELETE FROM taxes WHERE id = ?', [$id]);
        log_activity('Taxes', 'Deleted tax ' . ($name ?: '#' . $id));
        json_response(['success' => true, 'taxes' => taxes_all()]);
    }

    if ($action !== 'save_tax') {
        json_response(['success' => false, 'message' => 'Invalid action.'], 400);
    }

    $rate = $_POST['rate'] ?? '';
    if (!is_numeric($rate) || (float)$rate < 0 || (float)$rate > 100) {
        json_response(['success' => false, 'message' => 'Enter a GST rate between 0 and 100.'], 422);
    }
    $rate = round((float)$rate, 2);
    $name = mb_substr(trim((string)($_POST['name'] ?? '')), 0, 150) ?: 'GST ' . rate_label($rate) . '%';
    $enabled = ($_POST['enabled'] ?? '1') === '1' ? 1 : 0;

    if ($id && !db_value('SELECT COUNT(*) FROM taxes WHERE id = ?', [$id])) {
        json_response(['success' => false, 'message' => 'Tax not found. Refresh the page.'], 404);
    }
    if (db_value('SELECT COUNT(*) FROM taxes WHERE amount = ? AND id <> ?', [$rate, $id])) {
        json_response(['success' => false, 'message' => 'GST ' . rate_label($rate) . '% already exists.'], 409);
    }

    $data = ['name' => $name, 'amount' => $rate, 'type' => 'Percentage', 'enabled' => $enabled];
    $code = 'GST-' . rate_label($rate);
    if (db_value('SELECT COUNT(*) FROM taxes WHERE code = ? AND id <> ?', [$code, $id])) {
        $code .= '-' . substr(bin2hex(random_bytes(2)), 0, 4);
    }
    $data['code'] = $code;

    if ($id) {
        db_update('taxes', $data, $id);
        // Products that use this tax get the new rate.
        db_exec('UPDATE products SET tax_rate = ? WHERE tax_id = ?', [$rate, $id]);
        log_activity('Taxes', 'Updated tax ' . $name);
        $message = 'Tax updated. Products using it now use ' . rate_label($rate) . '%.';
    } else {
        db_insert('taxes', $data + ['platforms' => 'All platforms']);
        log_activity('Taxes', 'Added tax ' . $name);
        $message = 'Tax added successfully.';
    }

    json_response(['success' => true, 'message' => $message, 'taxes' => taxes_all()]);
}

$taxes = taxes_all();
$pageTitle = 'Taxes';
include 'header.php';
?>

<style>
.gst-section .gst-title { font-size: 24px; font-weight: 800; margin: 0 0 4px; }
.gst-section .gst-sub { color: var(--p-text-2); margin: 0; font-size: 14px; }
.gst-add { border: 0; background: linear-gradient(135deg, #2563eb, #4f46e5); color: #fff; font-weight: 800; border-radius: 11px; padding: 10px 18px; box-shadow: 0 8px 20px rgba(37, 99, 235, .28); }

.gst-explain { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; margin-bottom: 18px; }
@media (max-width: 767px) { .gst-explain { grid-template-columns: 1fr; } }
.gst-box { border-radius: 16px; padding: 18px; border: 1px solid var(--p-border); background: #fff; box-shadow: var(--p-shadow); display: flex; gap: 14px; }
.gst-box .ic { width: 46px; height: 46px; flex: 0 0 46px; border-radius: 13px; display: grid; place-items: center; font-size: 22px; color: #fff; }
.gst-box.in .ic { background: linear-gradient(135deg, #16a34a, #0d9488); }
.gst-box.out .ic { background: linear-gradient(135deg, #7c3aed, #2563eb); }
.gst-box h3 { font-size: 15px; font-weight: 800; margin: 0 0 3px; }
.gst-box p { margin: 0; font-size: 13px; color: var(--p-text-2); }
.gst-box .eg { margin-top: 8px; font-size: 13px; font-weight: 700; }
.gst-box.in .eg { color: #15803d; }
.gst-box.out .eg { color: #6d28d9; }

.gst-panel { background: #fff; border: 1px solid var(--p-border); border-radius: 16px; box-shadow: var(--p-shadow); overflow: hidden; }
.gst-toolbar { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; padding: 14px 16px; border-bottom: 1px solid var(--p-border); }
.gst-toolbar input { height: 38px; border: 1px solid var(--p-border-strong); border-radius: 10px; padding: 0 12px; min-width: 240px; outline: none; }
.gst-calc { display: flex; align-items: center; gap: 8px; margin-left: auto; font-size: 13px; color: var(--p-text-2); }
.gst-calc input { min-width: 0; width: 130px; }
.gst-table { width: 100%; border-collapse: collapse; }
.gst-table th { font-size: 11.5px; text-transform: uppercase; letter-spacing: .05em; color: var(--p-muted); font-weight: 800; padding: 11px 14px; background: #fafbfe; border-bottom: 1px solid var(--p-border); white-space: nowrap; }
.gst-table td { padding: 13px 14px; border-bottom: 1px solid #f0f3f8; vertical-align: middle; }
.gst-rate { display: inline-flex; align-items: center; justify-content: center; min-width: 62px; height: 36px; border-radius: 10px; background: var(--p-primary-soft); color: var(--p-primary); font-weight: 800; font-size: 15px; }
.gst-name { font-weight: 700; }
.gst-split { display: inline-flex; gap: 6px; flex-wrap: wrap; }
.gst-tag { font-size: 12.5px; font-weight: 700; border-radius: 8px; padding: 4px 9px; white-space: nowrap; }
.gst-tag.c { background: #e8f8ee; color: #15803d; }
.gst-tag.s { background: #e6f6f4; color: #0f766e; }
.gst-tag.i { background: #f1ebff; color: #6d28d9; }
.gst-amt { display: block; font-size: 11.5px; color: var(--p-muted); margin-top: 3px; }
.gst-status { border: 0; border-radius: 20px; padding: 4px 11px; font-size: 12px; font-weight: 800; background: #e8f8ee; color: #15803d; }
.gst-status.off { background: #fde8e8; color: #b91c1c; }
.gst-act { border: 1px solid var(--p-border); background: #fff; border-radius: 9px; width: 34px; height: 34px; color: var(--p-text-2); }
.gst-act:hover { color: var(--p-primary); border-color: var(--p-primary); }
.gst-act.delete:hover { color: #dc2626; border-color: #dc2626; }

.gst-modal .modal-content { border: 0; border-radius: 18px; overflow: hidden; }
.gst-preview { border-radius: 12px; background: #f7f9fd; border: 1px dashed #cfd9ea; padding: 14px; }
.gst-preview .row-l { display: flex; justify-content: space-between; font-size: 14px; padding: 4px 0; }
.gst-chips { display: flex; gap: 6px; flex-wrap: wrap; }
.gst-chips button { border: 1px solid var(--p-border-strong); background: #fff; border-radius: 20px; padding: 4px 12px; font-weight: 700; font-size: 13px; }
.gst-chips button:hover { border-color: var(--p-primary); color: var(--p-primary); }
</style>

<section class="gst-section">
<div class="container-fluid p-0">

    <div class="row align-items-center g-3 mb-3">
        <div class="col-md-8">
            <h1 class="gst-title">Taxes (GST)</h1>
            <p class="gst-sub">Add one GST rate. The split is done automatically on quotations and invoices.</p>
        </div>
        <div class="col-md-4 text-md-end">
            <button type="button" class="gst-add tax-add-btn" id="addTaxBtn"><i class="bi bi-plus-lg"></i> Add GST Rate</button>
        </div>
    </div>

    <div class="gst-explain">
        <div class="gst-box in">
            <div class="ic"><i class="bi bi-geo-alt-fill"></i></div>
            <div>
                <h3>Inside State <small class="text-muted fw-semibold">(<?php echo e(COMPANY_STATE); ?> customer)</small></h3>
                <p>GST is split in half: <b>CGST</b> + <b>SGST</b>.</p>
                <div class="eg">Example: GST 10% → CGST 5% + SGST 5%</div>
            </div>
        </div>
        <div class="gst-box out">
            <div class="ic"><i class="bi bi-truck"></i></div>
            <div>
                <h3>Outside State <small class="text-muted fw-semibold">(other state customer)</small></h3>
                <p>Full GST is charged as <b>IGST</b>.</p>
                <div class="eg">Example: GST 10% → IGST 10%</div>
            </div>
        </div>
    </div>

    <div class="gst-panel">
        <div class="gst-toolbar">
            <input type="search" id="taxSearch" placeholder="Search GST rate...">
            <div class="gst-calc">
                <i class="bi bi-calculator"></i> Check on amount Rs
                <input type="number" id="calcAmount" min="0" step="0.01" value="1000">
            </div>
        </div>
        <div class="table-responsive">
            <table class="gst-table">
                <thead>
                    <tr>
                        <th>GST Rate</th>
                        <th>Name</th>
                        <th>Inside State (CGST + SGST)</th>
                        <th>Outside State (IGST)</th>
                        <th>Products</th>
                        <th>Status</th>
                        <th class="text-center">Actions</th>
                    </tr>
                </thead>
                <tbody id="taxTableBody"></tbody>
            </table>
        </div>
    </div>

</div>
</section>

<!-- ADD / EDIT MODAL -->
<div class="modal fade gst-modal" id="taxModal" tabindex="-1" aria-labelledby="taxModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form class="modal-content" id="taxForm">
            <div class="modal-header">
                <h5 class="modal-title fw-bold" id="taxModalTitle">Add GST Rate</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <label class="form-label fw-semibold" for="taxRate">GST Rate (%) <span class="text-danger">*</span></label>
                <input type="number" class="form-control form-control-lg" id="taxRate" min="0" max="100" step="0.01" required placeholder="e.g. 18">
                <div class="gst-chips mt-2">
                    <?php foreach ([0, 5, 12, 18, 28] as $r): ?>
                        <button type="button" data-rate="<?php echo $r; ?>"><?php echo $r; ?>%</button>
                    <?php endforeach; ?>
                </div>

                <label class="form-label fw-semibold mt-3" for="taxName">Name</label>
                <input type="text" class="form-control" id="taxName" maxlength="150" placeholder="GST 18% (filled automatically)">

                <div class="gst-preview mt-3">
                    <div class="fw-bold mb-1 small text-muted">HOW IT WILL APPLY</div>
                    <div class="row-l"><span><i class="bi bi-geo-alt-fill text-success"></i> Inside state</span><b id="pvIn">-</b></div>
                    <div class="row-l"><span><i class="bi bi-truck text-primary"></i> Outside state</span><b id="pvOut">-</b></div>
                </div>

                <div class="form-check form-switch mt-3">
                    <input class="form-check-input" type="checkbox" role="switch" id="taxEnabled" checked>
                    <label class="form-check-label" for="taxEnabled">Enabled (can be selected on products)</label>
                </div>
                <div class="alert alert-danger d-none mt-3 mb-0" id="taxError"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light border" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary fw-semibold" id="taxSave">Save</button>
            </div>
        </form>
    </div>
</div>

<script>
(function () {
    "use strict";

    let taxes = <?php echo json_encode($taxes, json_flags()); ?>;
    const $ = id => document.getElementById(id);
    const esc = App.esc;
    const modal = bootstrap.Modal.getOrCreateInstance($("taxModal"));
    let editing = null;

    const pct = v => (Math.round(Number(v) * 100) / 100).toString();
    const money = v => "Rs " + Number(v || 0).toLocaleString("en-IN", { minimumFractionDigits: 2, maximumFractionDigits: 2 });

    function render() {
        const q = $("taxSearch").value.trim().toLowerCase();
        const amt = Number($("calcAmount").value) || 0;
        const rows = taxes.filter(t => [t.name, t.rate + "%"].join(" ").toLowerCase().includes(q));
        $("taxTableBody").innerHTML = rows.length ? rows.map(t => {
            const half = t.rate / 2;
            return `<tr>
                <td><span class="gst-rate">${pct(t.rate)}%</span></td>
                <td><div class="gst-name">${esc(t.name)}</div></td>
                <td>
                    <span class="gst-split"><span class="gst-tag c">CGST ${pct(half)}%</span><span class="gst-tag s">SGST ${pct(half)}%</span></span>
                    <span class="gst-amt">On ${money(amt)}: ${money(amt * half / 100)} + ${money(amt * half / 100)}</span>
                </td>
                <td>
                    <span class="gst-tag i">IGST ${pct(t.rate)}%</span>
                    <span class="gst-amt">On ${money(amt)}: ${money(amt * t.rate / 100)}</span>
                </td>
                <td>${t.products ? `<b>${t.products}</b> <span class="text-muted small">products</span>` : '<span class="text-muted small">-</span>'}</td>
                <td><button type="button" class="gst-status ${t.enabled ? "" : "off"}" data-toggle-tax="${t.id}" title="Click to ${t.enabled ? "disable" : "enable"}">${t.enabled ? "Enabled" : "Disabled"}</button></td>
                <td class="text-center text-nowrap">
                    <button type="button" class="gst-act" data-action="edit" data-id="${t.id}" title="Edit Tax"><i class="bi bi-pencil"></i></button>
                    <button type="button" class="gst-act delete" data-action="delete" data-id="${t.id}" title="Delete Tax"><i class="bi bi-trash3"></i></button>
                </td>
            </tr>`;
        }).join("") : '<tr><td colspan="7" class="text-center text-muted py-5">No GST rates. Click "Add GST Rate".</td></tr>';
    }

    function preview() {
        const r = Number($("taxRate").value);
        const ok = $("taxRate").value !== "" && r >= 0 && r <= 100;
        $("pvIn").textContent = ok ? `CGST ${pct(r / 2)}% + SGST ${pct(r / 2)}%` : "-";
        $("pvOut").textContent = ok ? `IGST ${pct(r)}%` : "-";
        $("taxName").placeholder = ok ? `GST ${pct(r)}% (filled automatically)` : "GST 18% (filled automatically)";
    }

    function open(tax) {
        editing = tax || null;
        $("taxModalTitle").textContent = tax ? "Edit GST Rate" : "Add GST Rate";
        $("taxRate").value = tax ? tax.rate : "";
        $("taxName").value = tax ? tax.name : "";
        // A name like "GST 18%" follows the rate; a custom name is kept.
        $("taxName").dataset.typed = tax && tax.name !== `GST ${pct(tax.rate)}%` ? "1" : "";
        $("taxEnabled").checked = tax ? tax.enabled : true;
        $("taxError").classList.add("d-none");
        preview();
        modal.show();
        setTimeout(() => $("taxRate").focus(), 300);
    }

    $("addTaxBtn").addEventListener("click", () => open(null));
    $("taxRate").addEventListener("input", () => {
        // Auto name follows the rate unless typed by hand.
        if (!$("taxName").dataset.typed) $("taxName").value = "";
        preview();
    });
    $("taxName").addEventListener("input", function () { this.dataset.typed = this.value ? "1" : ""; });
    document.querySelectorAll("[data-rate]").forEach(b => b.addEventListener("click", () => {
        $("taxRate").value = b.dataset.rate;
        $("taxRate").dispatchEvent(new Event("input"));
    }));
    $("taxSearch").addEventListener("input", render);
    $("calcAmount").addEventListener("input", render);

    $("taxForm").addEventListener("submit", async ev => {
        ev.preventDefault();
        if (!ev.target.reportValidity()) return;
        const btn = $("taxSave");
        btn.disabled = true;
        try {
            const result = await App.post(location.pathname, {
                action: "save_tax",
                id: editing ? editing.id : "",
                rate: $("taxRate").value,
                name: $("taxName").value.trim(),
                enabled: $("taxEnabled").checked ? "1" : "0"
            });
            taxes = result.taxes;
            render();
            modal.hide();
            App.toast(result.message);
        } catch (err) {
            $("taxError").textContent = err.message;
            $("taxError").classList.remove("d-none");
        } finally {
            btn.disabled = false;
        }
    });

    $("taxTableBody").addEventListener("click", async ev => {
        const toggle = ev.target.closest("[data-toggle-tax]");
        const btn = ev.target.closest("[data-action]");
        try {
            if (toggle) {
                taxes = (await App.post(location.pathname, { action: "toggle_tax", id: toggle.dataset.toggleTax })).taxes;
                render();
            } else if (btn && btn.dataset.action === "edit") {
                open(taxes.find(t => t.id === Number(btn.dataset.id)));
            } else if (btn && btn.dataset.action === "delete") {
                const t = taxes.find(x => x.id === Number(btn.dataset.id));
                const note = t.products ? `\n${t.products} products use it; they keep ${pct(t.rate)}% until you pick another tax.` : "";
                if (!confirm(`Delete ${t.name}?${note}`)) return;
                taxes = (await App.post(location.pathname, { action: "delete_tax", id: t.id })).taxes;
                render();
                App.toast("Tax deleted.");
            }
        } catch (err) {
            App.toast(err.message, "error");
        }
    });

    render();
})();
</script>

<?php include 'footer.php'; ?>
