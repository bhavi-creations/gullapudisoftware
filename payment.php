<?php
/* ==========================================
   PAYMENTS - customer wise
   - Add Payment: pick the customer, then pay against one of their invoices
     or keep it as an ADVANCE (no invoice yet, e.g. money given while ordering).
   - Customer Ledger: invoices, what was paid on which date, balance, advance.
   - An advance can later be applied to an invoice.
   Saving a payment updates the invoice status (Submitted -> Partially Paid -> Paid).
========================================== */
require_once __DIR__ . '/includes/init.php';

const PAY_MODES = ['Cash', 'UPI', 'Bank Transfer', 'Cheque', 'Card'];

function pay_invoices(): array
{
    $rows = cast_rows(
        db_all("SELECT i.id, i.invoice_number AS number, i.customer_id, i.customer, i.date, i.due_date, i.amount, i.status,
                       COALESCE((SELECT SUM(p.amount) FROM payments p WHERE p.invoice = i.invoice_number), 0) AS paid
                FROM invoices i WHERE i.status NOT IN ('Draft', 'Cancelled') ORDER BY i.date DESC, i.id DESC"),
        [],
        ['amount', 'paid']
    );
    foreach ($rows as &$r) {
        $r['customer_id'] = $r['customer_id'] ? (int)$r['customer_id'] : null;
    }
    unset($r);
    return $rows;
}

function pay_entries(): array
{
    $rows = cast_rows(
        db_all('SELECT p.id, p.date, p.type, p.invoice, p.narration, p.mode, p.amount, p.reference, p.notes, p.created_at,
                       COALESCE(p.customer_id, i.customer_id) AS customer_id,
                       COALESCE(p.customer, i.customer) AS customer
                FROM payments p LEFT JOIN invoices i ON i.invoice_number = p.invoice
                ORDER BY p.date DESC, p.id DESC'),
        [],
        ['amount']
    );
    foreach ($rows as &$r) {
        $r['customer_id'] = $r['customer_id'] ? (int)$r['customer_id'] : null;
        $r['is_advance'] = $r['invoice'] === null || $r['invoice'] === '';
    }
    unset($r);
    return $rows;
}

function pay_customers(): array
{
    return cast_rows(db_all("SELECT id, name, mobile, business_name FROM customers ORDER BY name"));
}

function pay_data(): array
{
    return ['invoices' => pay_invoices(), 'payments' => pay_entries()];
}

function pay_invoice_balance(string $number): float
{
    $amount = (float)db_value('SELECT amount FROM invoices WHERE invoice_number = ?', [$number]);
    $paid = (float)db_value('SELECT COALESCE(SUM(amount), 0) FROM payments WHERE invoice = ?', [$number]);
    return round($amount - $paid, 2);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf_json();
    $action = $_POST['action'] ?? '';

    /* ---------- DELETE ---------- */
    if ($action === 'delete_payment') {
        $payment = db_one('SELECT * FROM payments WHERE id = ?', [(int)($_POST['id'] ?? 0)]);
        if (!$payment) {
            json_response(['success' => false, 'message' => 'Payment not found.'], 404);
        }
        db_exec('DELETE FROM payments WHERE id = ?', [$payment['id']]);
        if ($payment['invoice']) {
            refresh_invoice_status($payment['invoice']);
        }
        log_activity('Payments', 'Deleted payment ' . $payment['narration'] . ' (Rs ' . number_format((float)$payment['amount'], 2) . ')');
        json_response(['success' => true, 'message' => 'Payment deleted.'] + pay_data());
    }

    /* ---------- APPLY AN ADVANCE TO AN INVOICE ---------- */
    if ($action === 'apply_advance') {
        $payment = db_one('SELECT * FROM payments WHERE id = ?', [(int)($_POST['id'] ?? 0)]);
        $number = trim((string)($_POST['invoice'] ?? ''));
        $invoice = db_one("SELECT id, invoice_number, customer_id, customer FROM invoices
                           WHERE invoice_number = ? AND status NOT IN ('Draft','Cancelled')", [$number]);
        if (!$payment || ($payment['invoice'] ?? '') !== '' && $payment['invoice'] !== null) {
            json_response(['success' => false, 'message' => 'This advance was not found or is already applied.'], 422);
        }
        if (!$invoice) {
            json_response(['success' => false, 'message' => 'Choose an invoice.'], 422);
        }
        $balance = pay_invoice_balance($number);
        if ($balance <= 0) {
            json_response(['success' => false, 'message' => 'This invoice is already fully paid.'], 422);
        }
        $amount = (float)$payment['amount'];
        $use = min($amount, $balance);
        db()->beginTransaction();
        db_update('payments', [
            'invoice' => $number,
            'invoice_id' => (int)$invoice['id'],
            'amount' => $use,
            'customer_id' => $payment['customer_id'] ?: $invoice['customer_id'],
            'customer' => $payment['customer'] ?: $invoice['customer'],
            'notes' => trim(($payment['notes'] ?? '') . ' Advance applied to ' . $number),
        ], (int)$payment['id']);
        // Advance bigger than the balance: the rest stays as advance.
        if ($amount - $use > 0.009) {
            db_insert('payments', [
                'date' => $payment['date'],
                'type' => 'Advance',
                'invoice' => null,
                'customer_id' => $payment['customer_id'],
                'customer' => $payment['customer'],
                'narration' => next_number('payments', 'narration', 'PAY-'),
                'mode' => $payment['mode'],
                'amount' => round($amount - $use, 2),
                'reference' => $payment['reference'],
                'notes' => 'Remaining advance from ' . $payment['narration'],
            ]);
        }
        db()->commit();
        refresh_invoice_status($number);
        log_activity('Payments', 'Advance ' . $payment['narration'] . ' applied to ' . $number . ' (Rs ' . number_format($use, 2) . ')');
        json_response(['success' => true, 'message' => 'Rs ' . number_format($use, 2) . ' applied to ' . $number . '.'] + pay_data());
    }

    /* ---------- SAVE PAYMENT / ADVANCE ---------- */
    if ($action !== 'save_payment') {
        json_response(['success' => false, 'message' => 'Invalid action.'], 400);
    }

    $customerId = (int)($_POST['customer_id'] ?? 0);
    $customer = db_one('SELECT id, name FROM customers WHERE id = ?', [$customerId]);
    $target = trim((string)($_POST['invoice'] ?? '')); // invoice number or "advance"
    $date = trim((string)($_POST['date'] ?? ''));
    $amount = $_POST['amount'] ?? '';
    $mode = trim((string)($_POST['mode'] ?? ''));
    $reference = mb_substr(trim((string)($_POST['reference'] ?? '')), 0, 100);
    $notes = mb_substr(trim((string)($_POST['notes'] ?? '')), 0, 255);
    $dateObject = DateTimeImmutable::createFromFormat('!Y-m-d', $date);

    if (!$customer) {
        json_response(['success' => false, 'message' => 'Select the customer.'], 422);
    }
    if (!$dateObject || $dateObject->format('Y-m-d') !== $date) {
        json_response(['success' => false, 'message' => 'Enter a valid payment date.'], 422);
    }
    if (!in_array($mode, PAY_MODES, true)) {
        json_response(['success' => false, 'message' => 'Choose the payment mode.'], 422);
    }
    if (!is_numeric($amount) || !is_finite((float)$amount) || (float)$amount <= 0) {
        json_response(['success' => false, 'message' => 'Enter the amount paid.'], 422);
    }
    $amount = round((float)$amount, 2);

    $invoiceRecord = null;
    if ($target !== 'advance') {
        $invoiceRecord = db_one("SELECT id, invoice_number, customer_id FROM invoices
                                 WHERE invoice_number = ? AND status NOT IN ('Draft', 'Cancelled')", [$target]);
        if (!$invoiceRecord) {
            json_response(['success' => false, 'message' => 'Choose an invoice, or "Advance".'], 422);
        }
        if ((int)$invoiceRecord['customer_id'] && (int)$invoiceRecord['customer_id'] !== $customerId) {
            json_response(['success' => false, 'message' => 'This invoice belongs to another customer.'], 422);
        }
        $balance = pay_invoice_balance($target);
        if ($amount > $balance + 0.009) {
            json_response(['success' => false, 'message' => 'Amount is more than the invoice balance (Rs ' . number_format($balance, 2)
                . '). Save the extra as an Advance.'], 422);
        }
    }

    $narration = next_number('payments', 'narration', 'PAY-');
    db_insert('payments', [
        'date' => $date,
        'type' => $invoiceRecord ? 'Payment Received' : 'Advance',
        'invoice' => $invoiceRecord ? $invoiceRecord['invoice_number'] : null,
        'invoice_id' => $invoiceRecord ? (int)$invoiceRecord['id'] : null,
        'customer_id' => $customerId,
        'customer' => $customer['name'],
        'narration' => $narration,
        'mode' => $mode,
        'amount' => $amount,
        'reference' => $reference ?: null,
        'notes' => $notes ?: null,
    ]);
    if ($invoiceRecord) {
        refresh_invoice_status($invoiceRecord['invoice_number']);
    }
    log_activity('Payments', "Payment $narration of Rs " . number_format($amount, 2) . ' from ' . $customer['name']
        . ($invoiceRecord ? ' for ' . $invoiceRecord['invoice_number'] : ' (advance)'));

    json_response([
        'success' => true,
        'message' => ($invoiceRecord ? 'Payment' : 'Advance') . ' of Rs ' . number_format($amount, 2) . ' saved for ' . $customer['name'] . '.',
    ] + pay_data());
}

$data = pay_data();
$customers = pay_customers();
$pageTitle = 'Payments';
include 'header.php';
?>

<style>
.pm-section .pm-title { font-size: 24px; font-weight: 800; margin: 0 0 4px; }
.pm-section .pm-sub { color: var(--p-text-2); font-size: 14px; margin: 0; }
.pm-btn { border: 0; background: linear-gradient(135deg, #16a34a, #0d9488); color: #fff; font-weight: 800; border-radius: 11px; padding: 10px 18px; box-shadow: 0 8px 20px rgba(22, 163, 74, .25); }
.pm-btn.blue { background: linear-gradient(135deg, #2563eb, #4f46e5); box-shadow: 0 8px 20px rgba(37, 99, 235, .25); }
.pm-stats { display: grid; grid-template-columns: repeat(5, 1fr); gap: 12px; margin-bottom: 16px; }
@media (max-width: 1199px) { .pm-stats { grid-template-columns: repeat(2, 1fr); } }
.pm-stat { background: #fff; border: 1px solid var(--p-border); border-radius: 15px; padding: 14px 16px; box-shadow: var(--p-shadow); display: flex; gap: 12px; align-items: center; }
.pm-stat .ic { width: 42px; height: 42px; border-radius: 12px; display: grid; place-items: center; font-size: 19px; flex: 0 0 42px; }
.pm-stat .l { font-size: 12px; color: var(--p-muted); font-weight: 700; text-transform: uppercase; letter-spacing: .04em; }
.pm-stat .v { font-size: 19px; font-weight: 800; }
.pm-card { background: #fff; border: 1px solid var(--p-border); border-radius: 16px; box-shadow: var(--p-shadow); overflow: hidden; }
.pm-tabs { display: flex; gap: 4px; padding: 10px 12px 0; border-bottom: 1px solid var(--p-border); }
.pm-tabs button { border: 0; background: transparent; padding: 10px 14px; font-weight: 700; color: var(--p-text-2); border-bottom: 3px solid transparent; }
.pm-tabs button.active { color: var(--p-primary); border-bottom-color: var(--p-primary); }
.pm-toolbar { display: flex; gap: 10px; flex-wrap: wrap; align-items: center; padding: 14px 16px; border-bottom: 1px solid var(--p-border); }
.pm-toolbar input, .pm-toolbar select { height: 38px; border: 1px solid var(--p-border-strong); border-radius: 10px; padding: 0 12px; outline: none; background: #fff; }
.pm-table { width: 100%; border-collapse: collapse; }
.pm-table th { font-size: 11.5px; text-transform: uppercase; letter-spacing: .05em; color: var(--p-muted); font-weight: 800; padding: 10px 14px; background: #fafbfe; border-bottom: 1px solid var(--p-border); white-space: nowrap; }
.pm-table td { padding: 11px 14px; border-bottom: 1px solid #f0f3f8; vertical-align: middle; font-size: 14px; }
.pm-table .r { text-align: right; white-space: nowrap; }
.pm-tag { font-size: 12px; font-weight: 800; border-radius: 20px; padding: 3px 10px; white-space: nowrap; }
.pm-tag.adv { background: #fff4e5; color: #b45309; }
.pm-tag.inv { background: #e8f0ff; color: #1d4ed8; }
.pm-tag.Paid { background: #e8f8ee; color: #15803d; }
.pm-tag.Partially { background: #fff4e5; color: #b45309; }
.pm-tag.Submitted, .pm-tag.Overdue { background: #fde8e8; color: #b91c1c; }
.pm-act { border: 1px solid var(--p-border); background: #fff; border-radius: 9px; height: 32px; padding: 0 10px; color: var(--p-text-2); font-size: 13px; font-weight: 700; }
.pm-act:hover { color: var(--p-primary); border-color: var(--p-primary); }
.pm-act.delete:hover { color: #dc2626; border-color: #dc2626; }
.pm-empty { padding: 50px 20px; text-align: center; color: var(--p-muted); }
.pm-empty i { font-size: 40px; display: block; margin-bottom: 8px; color: #c3cede; }

/* Ledger */
.pm-ledger-head { padding: 18px; display: flex; gap: 16px; align-items: center; flex-wrap: wrap; border-bottom: 1px solid var(--p-border); }
.pm-avatar { width: 52px; height: 52px; border-radius: 14px; display: grid; place-items: center; font-weight: 800; font-size: 18px; color: #fff; background: linear-gradient(135deg, #3b82f6, #6366f1); }
.pm-sum { display: grid; grid-template-columns: repeat(4, 1fr); gap: 10px; padding: 16px 18px; border-bottom: 1px solid var(--p-border); background: #fafbfe; }
@media (max-width: 767px) { .pm-sum { grid-template-columns: repeat(2, 1fr); } }
.pm-sum div { background: #fff; border: 1px solid var(--p-border); border-radius: 12px; padding: 10px 12px; }
.pm-sum span { display: block; font-size: 11.5px; font-weight: 800; color: var(--p-muted); text-transform: uppercase; }
.pm-sum b { font-size: 18px; }
.pm-h { font-size: 14px; font-weight: 800; padding: 14px 18px 6px; display: flex; align-items: center; gap: 8px; }
.pm-day { border-left: 3px solid #2563eb; margin: 10px 18px; padding: 2px 0 2px 14px; }
.pm-day-head { display: flex; justify-content: space-between; font-weight: 800; font-size: 14px; }
.pm-day-head small { color: var(--p-muted); font-weight: 700; }
.pm-entry { display: flex; justify-content: space-between; gap: 10px; align-items: center; padding: 8px 12px; border: 1px solid #eef2f8; border-radius: 10px; margin-top: 6px; background: #fff; font-size: 13.5px; flex-wrap: wrap; }
.pm-entry .amt { font-weight: 800; color: #15803d; white-space: nowrap; }
.pm-modal .modal-content { border: 0; border-radius: 18px; }
.pm-choice { display: block; border: 1px solid var(--p-border-strong); border-radius: 12px; padding: 10px 12px; margin-bottom: 8px; cursor: pointer; }
.pm-choice:has(input:checked) { border-color: #2563eb; background: #eef3ff; }
.pm-choice input { margin-right: 8px; }
.pm-choices { max-height: 240px; overflow: auto; }
</style>

<section class="pm-section">
<div class="container-fluid p-0">

    <div class="row align-items-center g-3 mb-3">
        <div class="col-md-7">
            <h1 class="pm-title">Payments</h1>
            <p class="pm-sub">Customer-wise payments: what was paid on which date, advance, and balance.</p>
        </div>
        <div class="col-md-5 text-md-end d-flex gap-2 justify-content-md-end flex-wrap">
            <button type="button" class="pm-btn pay-add-btn" data-open-pay="invoice"><i class="bi bi-plus-lg"></i> Add Payment</button>
            <button type="button" class="pm-btn blue pay-add-btn" data-open-pay="advance"><i class="bi bi-wallet2"></i> Add Advance</button>
        </div>
    </div>

    <div class="pm-stats">
        <div class="pm-stat"><div class="ic" style="background:#e8f0ff;color:#2563eb"><i class="bi bi-receipt"></i></div><div><div class="l">Total Invoiced</div><div class="v" id="sInvoiced">-</div></div></div>
        <div class="pm-stat"><div class="ic" style="background:#e8f8ee;color:#16a34a"><i class="bi bi-check2-circle"></i></div><div><div class="l">Received</div><div class="v" id="sReceived">-</div></div></div>
        <div class="pm-stat"><div class="ic" style="background:#fde8e8;color:#dc2626"><i class="bi bi-hourglass-split"></i></div><div><div class="l">Balance Due</div><div class="v" id="sPending">-</div></div></div>
        <div class="pm-stat"><div class="ic" style="background:#fff4e5;color:#ea7a0c"><i class="bi bi-wallet2"></i></div><div><div class="l">Advance (not applied)</div><div class="v" id="sAdvance">-</div></div></div>
        <div class="pm-stat"><div class="ic" style="background:#f1ebff;color:#7c3aed"><i class="bi bi-calendar-check"></i></div><div><div class="l">Received Today</div><div class="v" id="sToday">-</div></div></div>
    </div>

    <div class="pm-card">
        <div class="pm-tabs">
            <button type="button" class="active" data-tab="ledger"><i class="bi bi-person-lines-fill"></i> Customer Ledger</button>
            <button type="button" data-tab="all"><i class="bi bi-list-ul"></i> All Payments</button>
            <button type="button" data-tab="due"><i class="bi bi-exclamation-circle"></i> Customers with Balance</button>
        </div>

        <!-- LEDGER -->
        <div id="tabLedger">
            <div class="pm-toolbar">
                <label class="fw-bold small text-muted" for="ledgerCustomer">CUSTOMER</label>
                <select id="ledgerCustomer" style="min-width:280px">
                    <option value="">Select customer to see payments</option>
                    <?php foreach ($customers as $c): ?>
                        <option value="<?php echo (int)$c['id']; ?>"><?php echo e($c['name'] . ($c['mobile'] ? ' - ' . $c['mobile'] : '')); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div id="ledgerBody"></div>
        </div>

        <!-- ALL PAYMENTS -->
        <div id="tabAll" class="d-none">
            <div class="pm-toolbar">
                <input type="search" id="allSearch" placeholder="Search customer, invoice, PAY no, mode..." style="min-width:260px">
                <select id="allType"><option value="">All types</option><option value="invoice">Invoice payments</option><option value="advance">Advance</option></select>
                <label class="small text-muted">From</label><input type="date" id="allFrom">
                <label class="small text-muted">To</label><input type="date" id="allTo">
                <span class="ms-auto small">Total shown: <b id="allTotal">-</b></span>
            </div>
            <div class="table-responsive">
                <table class="pm-table">
                    <thead><tr><th>Date</th><th>Pay No</th><th>Customer</th><th>For</th><th>Mode</th><th>Reference / Notes</th><th class="r">Amount</th><th></th></tr></thead>
                    <tbody id="allBody"></tbody>
                </table>
            </div>
        </div>

        <!-- CUSTOMERS WITH BALANCE -->
        <div id="tabDue" class="d-none">
            <div class="table-responsive">
                <table class="pm-table">
                    <thead><tr><th>Customer</th><th class="r">Invoiced</th><th class="r">Paid</th><th class="r">Balance</th><th class="r">Advance</th><th>Last Payment</th><th></th></tr></thead>
                    <tbody id="dueBody"></tbody>
                </table>
            </div>
        </div>
    </div>

</div>
</section>

<!-- ADD PAYMENT MODAL -->
<div class="modal fade pm-modal" id="payModal" tabindex="-1" aria-labelledby="payModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <form class="modal-content" id="payForm">
            <div class="modal-header">
                <h5 class="modal-title fw-bold" id="payModalTitle">Add Payment</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="row g-3">
                    <div class="col-md-7">
                        <label class="form-label fw-semibold" for="payCustomer">1. Customer <span class="text-danger">*</span></label>
                        <select class="form-select" id="payCustomer" required>
                            <option value="">Select customer</option>
                            <?php foreach ($customers as $c): ?>
                                <option value="<?php echo (int)$c['id']; ?>"><?php echo e($c['name'] . ($c['mobile'] ? ' - ' . $c['mobile'] : '')); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <div class="small text-muted mt-1" id="payCustomerInfo"></div>

                        <label class="form-label fw-semibold mt-3">2. Payment For</label>
                        <div class="pm-choices" id="payTargets"><div class="text-muted small">Select the customer first.</div></div>
                    </div>
                    <div class="col-md-5">
                        <label class="form-label fw-semibold" for="payDate">3. Payment Date <span class="text-danger">*</span></label>
                        <input type="date" class="form-control" id="payDate" required>

                        <label class="form-label fw-semibold mt-3" for="payAmount">Amount Paid (Rs) <span class="text-danger">*</span></label>
                        <input type="number" class="form-control form-control-lg" id="payAmount" min="0.01" step="0.01" required placeholder="0.00">
                        <div class="small mt-1" id="payBalanceInfo"></div>

                        <label class="form-label fw-semibold mt-3" for="payMode">Payment Mode</label>
                        <select class="form-select" id="payMode">
                            <?php foreach (PAY_MODES as $m): ?><option><?php echo e($m); ?></option><?php endforeach; ?>
                        </select>

                        <label class="form-label fw-semibold mt-3" for="payReference">Reference (UPI / Cheque No.)</label>
                        <input type="text" class="form-control" id="payReference" maxlength="100" placeholder="Optional">

                        <label class="form-label fw-semibold mt-3" for="payNotes">Notes</label>
                        <input type="text" class="form-control" id="payNotes" maxlength="255" placeholder="e.g. Advance for plywood order">
                    </div>
                </div>
                <div class="alert alert-danger d-none mt-3 mb-0" id="payError"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light border" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-success fw-semibold" id="paySave"><i class="bi bi-check2-circle"></i> Save Payment</button>
            </div>
        </form>
    </div>
</div>

<!-- APPLY ADVANCE MODAL -->
<div class="modal fade pm-modal" id="applyModal" tabindex="-1" aria-labelledby="applyTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form class="modal-content" id="applyForm">
            <div class="modal-header">
                <h5 class="modal-title fw-bold" id="applyTitle">Apply Advance to Invoice</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p class="mb-2" id="applyInfo"></p>
                <div class="pm-choices" id="applyTargets"></div>
                <div class="small text-muted mt-2">If the advance is more than the invoice balance, the extra stays as advance.</div>
                <div class="alert alert-danger d-none mt-3 mb-0" id="applyError"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light border" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary fw-semibold" id="applySave">Apply</button>
            </div>
        </form>
    </div>
</div>

<script>
(function () {
    "use strict";

    let invoices = <?php echo json_encode($data['invoices'], json_flags()); ?>;
    let payments = <?php echo json_encode($data['payments'], json_flags()); ?>;
    const customers = <?php echo json_encode($customers, json_flags()); ?>;

    const $ = id => document.getElementById(id);
    const esc = App.esc;
    const money = v => "Rs " + Number(v || 0).toLocaleString("en-IN", { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    const today = () => new Date(Date.now() - new Date().getTimezoneOffset() * 60000).toISOString().slice(0, 10);
    const fmtDate = v => {
        if (!v) return "-";
        const [y, m, d] = String(v).slice(0, 10).split("-").map(Number);
        return new Date(y, m - 1, d).toLocaleDateString("en-GB", { day: "2-digit", month: "short", year: "numeric" });
    };
    const weekday = v => { const [y, m, d] = v.split("-").map(Number); return new Date(y, m - 1, d).toLocaleDateString("en-GB", { weekday: "long" }); };
    const balance = inv => Math.max(0, Math.round((inv.amount - inv.paid) * 100) / 100);
    const customerName = id => (customers.find(c => c.id === id) || {}).name || "";

    const payModal = bootstrap.Modal.getOrCreateInstance($("payModal"));
    const applyModal = bootstrap.Modal.getOrCreateInstance($("applyModal"));

    function customerSummary(id) {
        const inv = invoices.filter(i => i.customer_id === id);
        const pays = payments.filter(p => p.customer_id === id);
        const invoiced = inv.reduce((s, i) => s + i.amount, 0);
        const paidOnInv = inv.reduce((s, i) => s + i.paid, 0);
        const advance = pays.filter(p => p.is_advance).reduce((s, p) => s + p.amount, 0);
        const due = inv.reduce((s, i) => s + balance(i), 0);
        return { inv, pays, invoiced, paidOnInv, advance, due, last: pays[0] || null };
    }

    /* ---------- Stats ---------- */
    function stats() {
        const t = today();
        $("sInvoiced").textContent = money(invoices.reduce((s, i) => s + i.amount, 0));
        $("sReceived").textContent = money(payments.reduce((s, p) => s + p.amount, 0));
        $("sPending").textContent = money(invoices.reduce((s, i) => s + balance(i), 0));
        $("sAdvance").textContent = money(payments.filter(p => p.is_advance).reduce((s, p) => s + p.amount, 0));
        $("sToday").textContent = money(payments.filter(p => p.date === t).reduce((s, p) => s + p.amount, 0));
    }

    /* ---------- Ledger ---------- */
    function renderLedger() {
        const id = Number($("ledgerCustomer").value);
        const body = $("ledgerBody");
        if (!id) {
            body.innerHTML = '<div class="pm-empty"><i class="bi bi-person-lines-fill"></i>Select a customer to see their invoices and every payment date-wise.</div>';
            return;
        }
        const c = customers.find(x => x.id === id) || { name: "" };
        const s = customerSummary(id);
        const initials = c.name.trim().split(/\s+/).slice(0, 2).map(p => p[0]).join("").toUpperCase();

        // Group payments by date.
        const byDate = {};
        s.pays.forEach(p => { (byDate[p.date] = byDate[p.date] || []).push(p); });
        const days = Object.keys(byDate).sort().reverse();

        body.innerHTML = `
            <div class="pm-ledger-head">
                <div class="pm-avatar">${esc(initials || "?")}</div>
                <div>
                    <div class="fw-bold fs-5">${esc(c.name)}</div>
                    <div class="small text-muted">${esc([c.mobile, c.business_name].filter(Boolean).join(" · "))}</div>
                </div>
                <div class="ms-auto d-flex gap-2 flex-wrap">
                    <button type="button" class="pm-btn pay-add-btn" data-pay-customer="${id}" data-kind="invoice"><i class="bi bi-plus-lg"></i> Receive Payment</button>
                    <button type="button" class="pm-btn blue pay-add-btn" data-pay-customer="${id}" data-kind="advance"><i class="bi bi-wallet2"></i> Advance</button>
                </div>
            </div>
            <div class="pm-sum">
                <div><span>Total Invoiced</span><b>${money(s.invoiced)}</b></div>
                <div><span>Paid on Invoices</span><b style="color:#16a34a">${money(s.paidOnInv)}</b></div>
                <div><span>Balance Due</span><b style="color:#dc2626">${money(s.due)}</b></div>
                <div><span>Advance (not applied)</span><b style="color:#ea7a0c">${money(s.advance)}</b></div>
            </div>

            <div class="pm-h"><i class="bi bi-receipt text-primary"></i> Invoices</div>
            <div class="table-responsive">
                <table class="pm-table">
                    <thead><tr><th>Invoice</th><th>Date</th><th class="r">Amount</th><th class="r">Paid</th><th class="r">Balance</th><th>Status</th><th></th></tr></thead>
                    <tbody>${s.inv.length ? s.inv.map(i => `<tr>
                        <td><a href="invoice-print.php?id=${i.id}" target="_blank" rel="noopener" class="fw-bold text-decoration-none">${esc(i.number)}</a></td>
                        <td>${fmtDate(i.date)}</td>
                        <td class="r">${money(i.amount)}</td>
                        <td class="r" style="color:#16a34a">${money(i.paid)}</td>
                        <td class="r fw-bold" style="color:${balance(i) > 0 ? "#dc2626" : "#16a34a"}">${money(balance(i))}</td>
                        <td><span class="pm-tag ${esc(String(i.status).split(" ")[0])}">${esc(i.status)}</span></td>
                        <td class="r">${balance(i) > 0 ? `<button type="button" class="pm-act pay-add-btn" data-pay-customer="${id}" data-kind="invoice" data-invoice="${esc(i.number)}"><i class="bi bi-cash"></i> Receive</button>` : ""}</td>
                    </tr>`).join("") : '<tr><td colspan="7" class="text-muted text-center py-3">No invoices yet.</td></tr>'}</tbody>
                </table>
            </div>

            <div class="pm-h"><i class="bi bi-calendar3 text-primary"></i> Payments by Date</div>
            ${days.length ? days.map(d => {
                const list = byDate[d];
                const total = list.reduce((sum, p) => sum + p.amount, 0);
                return `<div class="pm-day">
                    <div class="pm-day-head"><span>${fmtDate(d)} <small>${weekday(d)}</small></span><span style="color:#15803d">${money(total)}</span></div>
                    ${list.map(p => `<div class="pm-entry">
                        <span>
                            <b>${esc(p.narration || "")}</b>
                            ${p.is_advance ? '<span class="pm-tag adv ms-1">Advance</span>' : `<span class="pm-tag inv ms-1">${esc(p.invoice)}</span>`}
                            <span class="text-muted ms-1">${esc(p.mode)}${p.reference ? " · " + esc(p.reference) : ""}${p.notes ? " · " + esc(p.notes) : ""}</span>
                        </span>
                        <span class="d-flex gap-2 align-items-center">
                            <span class="amt">${money(p.amount)}</span>
                            ${p.is_advance ? `<button type="button" class="pm-act" data-apply="${p.id}" data-perm="edit"><i class="bi bi-link-45deg"></i> Apply to invoice</button>` : ""}
                            <button type="button" class="pm-act delete" data-action="delete" data-id="${p.id}" title="Delete Payment"><i class="bi bi-trash3"></i></button>
                        </span>
                    </div>`).join("")}
                </div>`;
            }).join("") : '<div class="pm-empty" style="padding:24px"><i class="bi bi-cash-stack"></i>No payments yet.</div>'}
            <div style="height:12px"></div>`;
    }

    /* ---------- All payments ---------- */
    function renderAll() {
        const q = $("allSearch").value.trim().toLowerCase();
        const type = $("allType").value;
        const from = $("allFrom").value, to = $("allTo").value;
        const rows = payments.filter(p => {
            if (type === "advance" && !p.is_advance) return false;
            if (type === "invoice" && p.is_advance) return false;
            if (from && p.date < from) return false;
            if (to && p.date > to) return false;
            return [p.customer, p.invoice, p.narration, p.mode, p.reference, p.notes, p.amount, fmtDate(p.date)].join(" ").toLowerCase().includes(q);
        });
        $("allTotal").textContent = money(rows.reduce((s, p) => s + p.amount, 0));
        $("allBody").innerHTML = rows.length ? rows.map(p => `<tr>
            <td class="text-nowrap">${fmtDate(p.date)}</td>
            <td class="fw-bold">${esc(p.narration || "")}</td>
            <td><a href="#" data-ledger="${p.customer_id || ""}" class="text-decoration-none">${esc(p.customer || "-")}</a></td>
            <td>${p.is_advance ? '<span class="pm-tag adv">Advance</span>' : `<span class="pm-tag inv">${esc(p.invoice)}</span>`}</td>
            <td>${esc(p.mode)}</td>
            <td class="small text-muted">${esc([p.reference, p.notes].filter(Boolean).join(" · ") || "-")}</td>
            <td class="r fw-bold" style="color:#15803d">${money(p.amount)}</td>
            <td class="r text-nowrap">
                ${p.is_advance ? `<button type="button" class="pm-act" data-apply="${p.id}" data-perm="edit" title="Apply to invoice"><i class="bi bi-link-45deg"></i></button>` : ""}
                <button type="button" class="pm-act delete" data-action="delete" data-id="${p.id}" title="Delete Payment"><i class="bi bi-trash3"></i></button>
            </td>
        </tr>`).join("") : '<tr><td colspan="8" class="text-center text-muted py-5">No payments found.</td></tr>';
    }

    /* ---------- Customers with balance ---------- */
    function renderDue() {
        const ids = [...new Set([...invoices.map(i => i.customer_id), ...payments.filter(p => p.is_advance).map(p => p.customer_id)].filter(Boolean))];
        const rows = ids.map(id => ({ id, ...customerSummary(id) }))
            .filter(r => r.due > 0.009 || r.advance > 0.009)
            .sort((a, b) => b.due - a.due);
        $("dueBody").innerHTML = rows.length ? rows.map(r => `<tr>
            <td class="fw-bold">${esc(customerName(r.id) || (r.inv[0] || {}).customer || "-")}</td>
            <td class="r">${money(r.invoiced)}</td>
            <td class="r" style="color:#16a34a">${money(r.paidOnInv)}</td>
            <td class="r fw-bold" style="color:#dc2626">${money(r.due)}</td>
            <td class="r" style="color:#ea7a0c">${money(r.advance)}</td>
            <td class="small">${r.last ? fmtDate(r.last.date) + " · " + money(r.last.amount) : "-"}</td>
            <td class="r"><button type="button" class="pm-act" data-ledger="${r.id}"><i class="bi bi-person-lines-fill"></i> Ledger</button></td>
        </tr>`).join("") : '<tr><td colspan="7" class="text-center text-muted py-5">No pending balances.</td></tr>';
    }

    function renderAllViews() {
        stats();
        renderLedger();
        renderAll();
        renderDue();
    }

    /* ---------- Tabs ---------- */
    function showTab(tab) {
        document.querySelectorAll(".pm-tabs [data-tab]").forEach(b => b.classList.toggle("active", b.dataset.tab === tab));
        $("tabLedger").classList.toggle("d-none", tab !== "ledger");
        $("tabAll").classList.toggle("d-none", tab !== "all");
        $("tabDue").classList.toggle("d-none", tab !== "due");
    }
    document.querySelectorAll(".pm-tabs [data-tab]").forEach(b => b.addEventListener("click", () => showTab(b.dataset.tab)));

    function openLedger(id) {
        if (!id) return;
        $("ledgerCustomer").value = String(id);
        renderLedger();
        showTab("ledger");
    }

    /* ---------- Add payment ---------- */
    function renderTargets(selectedInvoice, kind) {
        const id = Number($("payCustomer").value);
        const box = $("payTargets");
        if (!id) {
            box.innerHTML = '<div class="text-muted small">Select the customer first.</div>';
            $("payCustomerInfo").textContent = "";
            updateBalanceInfo();
            return;
        }
        const s = customerSummary(id);
        $("payCustomerInfo").innerHTML = `Balance due: <b class="text-danger">${money(s.due)}</b> · Advance: <b style="color:#ea7a0c">${money(s.advance)}</b>`;
        const open = s.inv.filter(i => balance(i) > 0);
        const pick = kind === "advance" ? "advance" : (selectedInvoice || (open[0] ? open[0].number : "advance"));
        box.innerHTML = open.map(i => `
            <label class="pm-choice"><input type="radio" name="payTarget" value="${esc(i.number)}" ${pick === i.number ? "checked" : ""}>
                <b>${esc(i.number)}</b> <span class="text-muted small">${fmtDate(i.date)}</span>
                <span class="float-end small">Bill ${money(i.amount)} · <b class="text-danger">Balance ${money(balance(i))}</b></span>
            </label>`).join("") + `
            <label class="pm-choice"><input type="radio" name="payTarget" value="advance" ${pick === "advance" ? "checked" : ""}>
                <b><i class="bi bi-wallet2 text-warning"></i> Advance</b> <span class="text-muted small">- money paid before the invoice (e.g. while ordering). Apply it to an invoice later.</span>
            </label>`;
        updateBalanceInfo();
    }

    function currentTarget() {
        const r = document.querySelector('input[name="payTarget"]:checked');
        return r ? r.value : "";
    }

    function updateBalanceInfo() {
        const t = currentTarget();
        const inv = invoices.find(i => i.number === t);
        const amt = Number($("payAmount").value) || 0;
        if (!inv) {
            $("payBalanceInfo").innerHTML = t === "advance" ? '<span class="text-muted">Saved as advance for this customer.</span>' : "";
            return;
        }
        const after = balance(inv) - amt;
        $("payBalanceInfo").innerHTML = after < -0.009
            ? `<span class="text-danger">More than balance ${money(balance(inv))}. Save the extra as Advance.</span>`
            : `Balance after this payment: <b>${money(Math.max(0, after))}</b>`;
    }

    function openPay(customerId, kind, invoiceNumber) {
        $("payForm").reset();
        $("payError").classList.add("d-none");
        $("payDate").value = today();
        $("payModalTitle").textContent = kind === "advance" ? "Add Advance Payment" : "Add Payment";
        $("payCustomer").value = customerId ? String(customerId) : ($("ledgerCustomer").value || "");
        renderTargets(invoiceNumber, kind);
        payModal.show();
    }

    document.addEventListener("click", ev => {
        const open = ev.target.closest("[data-open-pay]");
        if (open) { openPay(null, open.dataset.openPay); return; }
        const pc = ev.target.closest("[data-pay-customer]");
        if (pc) { openPay(Number(pc.dataset.payCustomer), pc.dataset.kind, pc.dataset.invoice); return; }
        const led = ev.target.closest("[data-ledger]");
        if (led) { ev.preventDefault(); openLedger(Number(led.dataset.ledger)); return; }
        const ap = ev.target.closest("[data-apply]");
        if (ap) { openApply(Number(ap.dataset.apply)); return; }
        const del = ev.target.closest('.pm-section [data-action="delete"]');
        if (del) deletePayment(Number(del.dataset.id));
    });

    $("payCustomer").addEventListener("change", () => renderTargets(null, null));
    $("payTargets").addEventListener("change", updateBalanceInfo);
    $("payAmount").addEventListener("input", updateBalanceInfo);
    $("ledgerCustomer").addEventListener("change", renderLedger);
    ["allSearch", "allType", "allFrom", "allTo"].forEach(id => $(id).addEventListener("input", renderAll));

    $("payForm").addEventListener("submit", async ev => {
        ev.preventDefault();
        if (!ev.target.reportValidity()) return;
        const target = currentTarget();
        if (!target) { $("payError").textContent = "Choose what this payment is for."; $("payError").classList.remove("d-none"); return; }
        const btn = $("paySave");
        btn.disabled = true;
        try {
            const result = await App.post(location.pathname, {
                action: "save_payment",
                customer_id: $("payCustomer").value,
                invoice: target,
                date: $("payDate").value,
                amount: $("payAmount").value,
                mode: $("payMode").value,
                reference: $("payReference").value.trim(),
                notes: $("payNotes").value.trim()
            });
            invoices = result.invoices;
            payments = result.payments;
            $("ledgerCustomer").value = $("payCustomer").value;
            renderAllViews();
            showTab("ledger");
            payModal.hide();
            App.toast(result.message);
        } catch (err) {
            $("payError").textContent = err.message;
            $("payError").classList.remove("d-none");
        } finally {
            btn.disabled = false;
        }
    });

    /* ---------- Apply advance ---------- */
    let applyId = null;
    function openApply(id) {
        const p = payments.find(x => x.id === id);
        if (!p) return;
        applyId = id;
        const open = invoices.filter(i => i.customer_id === p.customer_id && balance(i) > 0);
        $("applyInfo").innerHTML = `Advance <b>${esc(p.narration)}</b> of <b>${money(p.amount)}</b> paid on ${fmtDate(p.date)} by ${esc(p.customer || "")}.`;
        $("applyTargets").innerHTML = open.length ? open.map((i, n) => `
            <label class="pm-choice"><input type="radio" name="applyTarget" value="${esc(i.number)}" ${n === 0 ? "checked" : ""}>
                <b>${esc(i.number)}</b> <span class="text-muted small">${fmtDate(i.date)}</span>
                <span class="float-end small">Balance <b class="text-danger">${money(balance(i))}</b></span></label>`).join("")
            : '<div class="text-muted">This customer has no invoice with balance yet.</div>';
        $("applySave").disabled = !open.length;
        $("applyError").classList.add("d-none");
        applyModal.show();
    }

    $("applyForm").addEventListener("submit", async ev => {
        ev.preventDefault();
        const r = document.querySelector('input[name="applyTarget"]:checked');
        if (!r) return;
        try {
            const result = await App.post(location.pathname, { action: "apply_advance", id: applyId, invoice: r.value });
            invoices = result.invoices;
            payments = result.payments;
            renderAllViews();
            applyModal.hide();
            App.toast(result.message);
        } catch (err) {
            $("applyError").textContent = err.message;
            $("applyError").classList.remove("d-none");
        }
    });

    async function deletePayment(id) {
        const p = payments.find(x => x.id === id);
        if (!p || !confirm(`Delete payment ${p.narration} of ${money(p.amount)}?`)) return;
        try {
            const result = await App.post(location.pathname, { action: "delete_payment", id });
            invoices = result.invoices;
            payments = result.payments;
            renderAllViews();
            App.toast(result.message);
        } catch (err) {
            App.toast(err.message, "error");
        }
    }

    // ?customer=ID opens that customer's ledger.
    const qsCustomer = Number(new URLSearchParams(location.search).get("customer"));
    if (qsCustomer) $("ledgerCustomer").value = String(qsCustomer);
    renderAllViews();
})();
</script>

<?php include 'footer.php'; ?>
