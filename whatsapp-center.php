<?php
/*
|--------------------------------------------------------------------------
| WHATSAPP CENTER
|--------------------------------------------------------------------------
| Compose messages to customers using templates (payment reminder,
| invoice details, quotation, greetings). "Send" opens WhatsApp
| (wa.me link) with the message ready, and the message is saved in the
| `whatsapp_messages` table as history.
|--------------------------------------------------------------------------
*/
require_once __DIR__ . '/includes/init.php';

function wa_history(): array
{
    return cast_rows(db_all('SELECT id, customer, mobile, template, message, status, sent_by, created_at FROM whatsapp_messages ORDER BY id DESC LIMIT 500'));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf_json();
    $action = $_POST['action'] ?? '';

    if ($action === 'log_message') {
        $mobile = preg_replace('/\D/', '', (string)($_POST['mobile'] ?? ''));
        $message = trim((string)($_POST['message'] ?? ''));
        if (strlen($mobile) === 12 && strpos($mobile, '91') === 0) {
            $mobile = substr($mobile, 2);
        }
        if (!preg_match('/^[0-9]{10}$/', $mobile)) {
            json_response(['success' => false, 'message' => 'Enter a valid 10-digit mobile number.'], 422);
        }
        if ($message === '' || mb_strlen($message) > 4000) {
            json_response(['success' => false, 'message' => 'Message is required (max 4000 characters).'], 422);
        }
        $customerId = (int)($_POST['customer_id'] ?? 0);
        $customer = $customerId ? db_value('SELECT name FROM customers WHERE id = ?', [$customerId]) : null;

        db_insert('whatsapp_messages', [
            'customer_id' => $customerId ?: null,
            'customer' => $customer ?: (mb_substr(trim((string)($_POST['customer_name'] ?? '')), 0, 200) ?: null),
            'mobile' => $mobile,
            'template' => mb_substr((string)($_POST['template'] ?? 'custom'), 0, 50),
            'message' => $message,
            'status' => 'Sent',
            'sent_by' => $_SESSION['user_name'] ?? null,
        ]);
        log_activity('WhatsApp', 'Message sent to ' . ($customer ?: $mobile));
        json_response(['success' => true, 'history' => wa_history()]);
    }

    if ($action === 'delete_message') {
        db_exec('DELETE FROM whatsapp_messages WHERE id = ?', [(int)($_POST['id'] ?? 0)]);
        json_response(['success' => true, 'history' => wa_history()]);
    }

    json_response(['success' => false, 'message' => 'Invalid action.'], 400);
}

$customers = cast_rows(db_all("SELECT id, name, mobile FROM customers WHERE status = 'active' ORDER BY name"));

// Outstanding balance per customer (for payment reminders).
$dues = [];
foreach (db_all(
    "SELECT i.customer_id, i.invoice_number, i.amount, i.due_date,
            i.amount - COALESCE((SELECT SUM(p.amount) FROM payments p WHERE p.invoice = i.invoice_number), 0) AS balance
     FROM invoices i
     WHERE i.status NOT IN ('Draft','Cancelled','Paid')"
) as $row) {
    if ((float)$row['balance'] <= 0 || !$row['customer_id']) {
        continue;
    }
    $dues[(int)$row['customer_id']][] = [
        'invoice' => $row['invoice_number'],
        'balance' => round((float)$row['balance'], 2),
        'due_date' => $row['due_date'],
    ];
}

$history = wa_history();
$sentToday = (int)db_value('SELECT COUNT(*) FROM whatsapp_messages WHERE DATE(created_at) = CURDATE()');

$pageTitle = 'WhatsApp Center';
include 'header.php';
?>

<section class="app-page">
<div class="container-fluid p-0">

    <div class="mb-4">
        <h1 class="app-title">WhatsApp Center</h1>
        <p class="app-subtitle">Send payment reminders, invoice details and offers to customers on WhatsApp</p>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-6 col-xl-3">
            <div class="app-card app-stat">
                <div class="app-stat-icon" style="background:#dcfce7;color:#15803d"><i class="bi bi-whatsapp"></i></div>
                <div><div class="app-stat-label">Total Sent</div><div class="app-stat-value" id="waTotal">0</div></div>
            </div>
        </div>
        <div class="col-6 col-xl-3">
            <div class="app-card app-stat">
                <div class="app-stat-icon" style="background:#e0edff;color:#0b5ed7"><i class="bi bi-calendar-check"></i></div>
                <div><div class="app-stat-label">Sent Today</div><div class="app-stat-value" id="waToday"><?php echo $sentToday; ?></div></div>
            </div>
        </div>
        <div class="col-6 col-xl-3">
            <div class="app-card app-stat">
                <div class="app-stat-icon" style="background:#ffedd5;color:#c2410c"><i class="bi bi-people"></i></div>
                <div><div class="app-stat-label">Customers</div><div class="app-stat-value"><?php echo count($customers); ?></div></div>
            </div>
        </div>
        <div class="col-6 col-xl-3">
            <div class="app-card app-stat">
                <div class="app-stat-icon" style="background:#fee2e2;color:#b91c1c"><i class="bi bi-cash-coin"></i></div>
                <div><div class="app-stat-label">With Dues</div><div class="app-stat-value"><?php echo count($dues); ?></div></div>
            </div>
        </div>
    </div>

    <div class="row g-3">

        <!-- COMPOSE -->
        <div class="col-12 col-xl-5">
            <div class="app-card">
                <h2 class="h5 fw-bold mb-3"><i class="bi bi-pencil-square me-1"></i> Compose Message</h2>

                <form id="waForm" novalidate>
                    <div class="mb-3">
                        <label class="form-label fw-semibold" for="waCustomer">Customer</label>
                        <select class="form-select" id="waCustomer">
                            <option value="">-- Type a number manually --</option>
                            <?php foreach ($customers as $c): ?>
                                <option value="<?php echo (int)$c['id']; ?>" data-mobile="<?php echo e($c['mobile']); ?>">
                                    <?php echo e($c['name'] . ' (' . $c['mobile'] . ')'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold" for="waMobile">Mobile Number *</label>
                        <div class="input-group">
                            <span class="input-group-text">+91</span>
                            <input type="tel" class="form-control" id="waMobile" maxlength="10" pattern="[0-9]{10}" required placeholder="10-digit mobile">
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold" for="waTemplate">Template</label>
                        <select class="form-select" id="waTemplate">
                            <option value="custom">Custom message</option>
                            <option value="payment_reminder">Payment reminder</option>
                            <option value="thank_you">Thank you for purchase</option>
                            <option value="quotation">Quotation follow-up</option>
                            <option value="greeting">Festival greeting / offer</option>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold" for="waMessage">Message *</label>
                        <textarea class="form-control" id="waMessage" rows="8" maxlength="4000" required></textarea>
                        <div class="form-text"><span id="waCount">0</span> / 4000</div>
                    </div>

                    <div class="alert alert-danger d-none py-2" id="waError"></div>

                    <button type="submit" class="btn btn-success w-100 fw-bold py-2">
                        <i class="bi bi-whatsapp me-1"></i> Send on WhatsApp
                    </button>
                    <div class="form-text text-center mt-2">
                        Opens WhatsApp with the message ready. The message is saved in history.
                    </div>
                </form>
            </div>
        </div>

        <!-- HISTORY -->
        <div class="col-12 col-xl-7">
            <div class="app-card">
                <div class="d-flex flex-wrap gap-2 justify-content-between align-items-center mb-3">
                    <h2 class="h5 fw-bold mb-0"><i class="bi bi-clock-history me-1"></i> Message History</h2>
                    <input type="search" class="form-control" id="waSearch" placeholder="Search..." style="max-width:240px">
                </div>
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Customer</th>
                                <th>Template</th>
                                <th>Message</th>
                                <th class="text-center">Action</th>
                            </tr>
                        </thead>
                        <tbody id="waHistoryBody"></tbody>
                    </table>
                </div>
            </div>
        </div>

    </div>

</div>
</section>

<script>
(function () {
    "use strict";

    const $ = id => document.getElementById(id);
    const customers = <?php echo json_encode($customers, json_flags()); ?>;
    const dues = <?php echo json_encode((object)$dues, json_flags()); ?>;
    let history = <?php echo json_encode($history, json_flags()); ?>;
    const company = <?php echo json_encode(APP_NAME); ?>;

    const esc = v => String(v ?? "").replace(/[&<>"']/g, c => ({"&":"&amp;","<":"&lt;",">":"&gt;",'"':"&quot;","'":"&#39;"}[c]));
    const money = v => "Rs " + Number(v || 0).toLocaleString("en-IN", { maximumFractionDigits: 2 });

    function selectedCustomer() {
        const id = Number($("waCustomer").value);
        return customers.find(c => c.id === id) || null;
    }

    function buildTemplate() {
        const t = $("waTemplate").value;
        const c = selectedCustomer();
        const name = c ? c.name : "Customer";
        let text = "";

        if (t === "payment_reminder") {
            const list = c && dues[c.id] ? dues[c.id] : [];
            const total = list.reduce((s, d) => s + d.balance, 0);
            text = `Dear ${name},\n\nThis is a friendly reminder from ${company}.\n` +
                (list.length
                    ? `Pending amount: ${money(total)}\n` + list.map(d => `- ${d.invoice}: ${money(d.balance)}${d.due_date ? " (due " + d.due_date + ")" : ""}`).join("\n")
                    : "Please clear your pending dues at the earliest.") +
                `\n\nKindly make the payment at your earliest convenience.\nThank you!`;
        } else if (t === "thank_you") {
            text = `Dear ${name},\n\nThank you for shopping with ${company}! We truly appreciate your business.\nFor any help, reply to this message.`;
        } else if (t === "quotation") {
            text = `Dear ${name},\n\nFollowing up on the quotation shared by ${company}. Please let us know if you would like to proceed or need any changes.\n\nThank you!`;
        } else if (t === "greeting") {
            text = `Dear ${name},\n\nWarm greetings from ${company}! Visit our store for the latest plywood and glass collections and special offers.`;
        }

        if (t !== "custom") $("waMessage").value = text;
        $("waCount").textContent = $("waMessage").value.length;
    }

    $("waCustomer").addEventListener("change", function () {
        const opt = this.options[this.selectedIndex];
        $("waMobile").value = opt.dataset.mobile || "";
        buildTemplate();
    });
    $("waTemplate").addEventListener("change", buildTemplate);
    $("waMessage").addEventListener("input", () => { $("waCount").textContent = $("waMessage").value.length; });
    $("waMobile").addEventListener("input", function () { this.value = this.value.replace(/\D/g, "").slice(0, 10); });

    function renderHistory() {
        $("waTotal").textContent = history.length;
        const q = $("waSearch").value.trim().toLowerCase();
        const rows = history.filter(h => [h.customer, h.mobile, h.template, h.message].join(" ").toLowerCase().includes(q));
        if (!rows.length) {
            $("waHistoryBody").innerHTML = '<tr><td colspan="5" class="text-center text-muted py-5">No messages sent yet.</td></tr>';
            return;
        }
        $("waHistoryBody").innerHTML = rows.map(h => `
            <tr>
                <td class="text-nowrap small">${esc(new Date(h.created_at.replace(" ", "T")).toLocaleString("en-IN", { day: "2-digit", month: "short", hour: "2-digit", minute: "2-digit" }))}</td>
                <td><div class="fw-semibold">${esc(h.customer || "-")}</div><div class="small text-muted">${esc(h.mobile)}</div></td>
                <td><span class="app-badge green">${esc((h.template || "custom").replace("_", " "))}</span></td>
                <td style="max-width:280px"><div class="text-truncate" title="${esc(h.message)}">${esc(h.message)}</div></td>
                <td class="text-center text-nowrap">
                    <a class="btn btn-sm btn-light border" target="_blank" rel="noopener"
                       href="https://wa.me/91${esc(h.mobile)}?text=${encodeURIComponent(h.message)}" title="Send again">
                        <i class="bi bi-whatsapp text-success"></i></a>
                    <button class="btn btn-sm btn-light border" data-delete="${h.id}" title="Delete"><i class="bi bi-trash3 text-danger"></i></button>
                </td>
            </tr>`).join("");
    }

    $("waSearch").addEventListener("input", renderHistory);

    $("waHistoryBody").addEventListener("click", async e => {
        const btn = e.target.closest("[data-delete]");
        if (!btn || !confirm("Delete this message from history?")) return;
        try {
            history = (await App.post("whatsapp-center.php", { action: "delete_message", id: btn.dataset.delete })).history;
            renderHistory();
        } catch (err) { App.toast(err.message, "error"); }
    });

    $("waForm").addEventListener("submit", async function (e) {
        e.preventDefault();
        const mobile = $("waMobile").value.trim();
        const message = $("waMessage").value.trim();
        $("waError").classList.add("d-none");

        if (!/^[0-9]{10}$/.test(mobile) || !message) {
            $("waError").textContent = "Enter a 10-digit mobile number and a message.";
            $("waError").classList.remove("d-none");
            return;
        }

        // Open WhatsApp first (must happen in the click to avoid popup blockers).
        window.open("https://wa.me/91" + mobile + "?text=" + encodeURIComponent(message), "_blank", "noopener");

        try {
            const c = selectedCustomer();
            history = (await App.post("whatsapp-center.php", {
                action: "log_message",
                customer_id: c ? c.id : "",
                mobile,
                message,
                template: $("waTemplate").value
            })).history;
            $("waToday").textContent = Number($("waToday").textContent) + 1;
            renderHistory();
            App.toast("Message saved in history.");
        } catch (err) {
            $("waError").textContent = err.message;
            $("waError").classList.remove("d-none");
        }
    });

    renderHistory();
})();
</script>

<?php include 'footer.php'; ?>
