<?php
/*
|--------------------------------------------------------------------------
| ACCESS - owner chooses what each employee can do
|--------------------------------------------------------------------------
| Pick an employee, tick View / Add / Edit / Delete per page, Save.
| Stored in users.permissions as {"module": ["view","add","edit","delete"]}.
| Only administrators open this page.
|--------------------------------------------------------------------------
*/
require_once __DIR__ . '/includes/init.php';

if ((current_user()['role'] ?? '') !== 'admin') {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        json_response(['success' => false, 'message' => 'Only the owner / administrator can change access.'], 403);
    }
    header('Location: ' . first_allowed_page());
    exit;
}

/* Pages that only have "View" (nothing to add / edit / delete there). */
$viewOnly = ['dashboard', 'reports', 'tally'];

$groups = [
    'Overview'  => ['dashboard', 'reports'],
    'Sales'     => ['quotations', 'master_quotations', 'orders', 'invoices', 'direct_invoice', 'payments', 'returns'],
    'Catalogue' => ['products', 'categories', 'stores', 'taxes'],
    'People'    => ['customers', 'employees'],
    'Tools'     => ['tally', 'whatsapp', 'backup'],
];

function access_employees(): array
{
    $rows = db_all('SELECT id, name, email, mobile, role, permissions, enabled FROM users ORDER BY role = \'admin\' DESC, name');
    foreach ($rows as &$r) {
        $r['id'] = (int)$r['id'];
        $r['enabled'] = (bool)$r['enabled'];
        $r['full'] = $r['role'] === 'admin' || $r['permissions'] === null || $r['permissions'] === '';
        $r['access'] = $r['full'] ? null : (object)clean_permission_map(json_decode((string)$r['permissions'], true));
        unset($r['permissions']);
    }
    return $rows;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf_json();

    if (($_POST['action'] ?? '') !== 'save_access') {
        json_response(['success' => false, 'message' => 'Invalid action.'], 400);
    }
    $id = (int)($_POST['id'] ?? 0);
    $employee = db_one('SELECT id, name, role FROM users WHERE id = ?', [$id]);
    if (!$employee) {
        json_response(['success' => false, 'message' => 'Employee not found.'], 404);
    }
    if ($id === (int)$_SESSION['user_id']) {
        json_response(['success' => false, 'message' => 'You cannot change your own access.'], 422);
    }

    $role = ($_POST['role'] ?? '') === 'admin' ? 'admin' : 'staff';
    $map = clean_permission_map(json_decode((string)($_POST['permissions'] ?? '{}'), true));
    foreach ($viewOnly as $module) {
        if (isset($map[$module])) {
            $map[$module] = ['view'];
        }
    }
    if ($role === 'staff' && !$map) {
        json_response(['success' => false, 'message' => 'Give at least one page (View) to this employee.'], 422);
    }

    db_update('users', [
        'role' => $role,
        'permissions' => $role === 'admin' ? null : json_encode((object)$map),
    ], $id);

    log_activity('Employees', 'Access updated for ' . $employee['name']
        . ($role === 'admin' ? ' (Administrator)' : ' (' . count($map) . ' pages)'));

    json_response(['success' => true, 'message' => 'Access saved for ' . $employee['name'] . '.', 'employees' => access_employees()]);
}

$employees = access_employees();
$modules = app_modules();
$pageTitle = 'Access';
include 'header.php';
?>

<style>
.access-section { color: var(--p-text); }
.access-section .acc-title { font-size: 24px; font-weight: 800; margin: 0 0 4px; }
.access-section .acc-sub { color: var(--p-text-2); margin: 0; font-size: 14px; }

.acc-layout { display: grid; grid-template-columns: 320px 1fr; gap: 18px; align-items: start; }
@media (max-width: 991px) { .acc-layout { grid-template-columns: 1fr; } }

.acc-card { background: #fff; border: 1px solid var(--p-border); border-radius: 16px; box-shadow: var(--p-shadow); }
.acc-card-head { padding: 16px 18px; border-bottom: 1px solid var(--p-border); }
.acc-card-head h2 { font-size: 15px; font-weight: 800; margin: 0; }

/* Employee list */
.acc-search { position: relative; margin-top: 10px; }
.acc-search i { position: absolute; left: 11px; top: 50%; transform: translateY(-50%); color: var(--p-muted); }
.acc-search input { width: 100%; height: 38px; border: 1px solid var(--p-border-strong); border-radius: 10px; padding: 0 12px 0 34px; font-size: 14px; outline: none; }
.acc-search input:focus { border-color: var(--p-primary); box-shadow: 0 0 0 3px rgba(37, 99, 235, .12); }
.acc-emp-list { max-height: 62vh; overflow: auto; padding: 8px; }
.acc-emp {
    display: flex; align-items: center; gap: 11px; width: 100%;
    border: 1px solid transparent; background: transparent; border-radius: 12px;
    padding: 10px; text-align: left; cursor: pointer; transition: .15s;
}
.acc-emp:hover { background: #f6f8fc; }
.acc-emp.active { background: var(--p-primary-soft); border-color: #cddcff; }
.acc-avatar {
    width: 40px; height: 40px; flex: 0 0 40px; border-radius: 12px;
    display: grid; place-items: center; font-weight: 800; font-size: 14px; color: #fff;
    background: linear-gradient(135deg, #3b82f6, #6366f1);
}
.acc-avatar.admin { background: linear-gradient(135deg, #f59e0b, #ea580c); }
.acc-emp-name { font-weight: 700; font-size: 14px; line-height: 1.2; }
.acc-emp-meta { font-size: 12px; color: var(--p-muted); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.acc-pill { font-size: 11px; font-weight: 700; border-radius: 20px; padding: 2px 8px; margin-left: auto; white-space: nowrap; }
.acc-pill.full { background: #fff4e5; color: #b45309; }
.acc-pill.custom { background: #e8f0ff; color: #1d4ed8; }
.acc-pill.off { background: #fde8e8; color: #b91c1c; }

/* Editor */
.acc-empty { padding: 70px 20px; text-align: center; color: var(--p-muted); }
.acc-empty i { font-size: 44px; display: block; margin-bottom: 10px; color: #c3cede; }
.acc-head { display: flex; align-items: center; gap: 14px; flex-wrap: wrap; padding: 18px; border-bottom: 1px solid var(--p-border); }
.acc-head .acc-avatar { width: 52px; height: 52px; flex-basis: 52px; font-size: 18px; border-radius: 14px; }
.acc-head-name { font-size: 18px; font-weight: 800; }
.acc-role { display: inline-flex; background: #f1f4f9; border-radius: 11px; padding: 4px; margin-left: auto; }
.acc-role button { border: 0; background: transparent; border-radius: 8px; padding: 7px 13px; font-size: 13px; font-weight: 700; color: var(--p-text-2); }
.acc-role button.active { background: #fff; color: var(--p-primary); box-shadow: 0 1px 3px rgba(15, 23, 42, .12); }

.acc-tools { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; padding: 12px 18px; background: #fafbfe; border-bottom: 1px solid var(--p-border); }
.acc-tools .label { font-size: 12.5px; font-weight: 700; color: var(--p-text-2); margin-right: 4px; }
.acc-chip { border: 1px solid var(--p-border-strong); background: #fff; border-radius: 20px; padding: 5px 12px; font-size: 12.5px; font-weight: 700; color: var(--p-text-2); }
.acc-chip:hover { border-color: var(--p-primary); color: var(--p-primary); }

.acc-table { width: 100%; border-collapse: separate; border-spacing: 0; }
.acc-table th { font-size: 11.5px; text-transform: uppercase; letter-spacing: .05em; color: var(--p-muted); font-weight: 800; padding: 10px 12px; background: #fff; position: sticky; top: 0; z-index: 1; border-bottom: 1px solid var(--p-border); }
.acc-table th.c, .acc-table td.c { text-align: center; width: 92px; }
.acc-table td { padding: 10px 12px; border-bottom: 1px solid #f0f3f8; vertical-align: middle; }
.acc-table tr.grp td { background: #f7f9fd; font-size: 11.5px; font-weight: 800; color: var(--p-text-2); text-transform: uppercase; letter-spacing: .06em; padding: 8px 12px; }
.acc-mod { display: flex; align-items: center; gap: 10px; font-weight: 700; font-size: 14px; }
.acc-mod i { width: 32px; height: 32px; border-radius: 9px; display: grid; place-items: center; background: var(--p-primary-soft); color: var(--p-primary); font-size: 15px; }
.acc-table tr.on .acc-mod i { background: var(--p-primary); color: #fff; }
.acc-na { color: #c3cede; font-weight: 700; }

/* toggle box */
.acc-tick { position: relative; display: inline-block; width: 26px; height: 26px; cursor: pointer; }
.acc-tick input { position: absolute; opacity: 0; inset: 0; cursor: pointer; margin: 0; }
.acc-tick span { position: absolute; inset: 0; border: 2px solid #cfd8e5; border-radius: 8px; background: #fff; transition: .15s; }
.acc-tick input:checked + span { background: var(--p-primary); border-color: var(--p-primary); }
.acc-tick input:checked + span::after { content: ""; position: absolute; left: 7px; top: 2px; width: 7px; height: 13px; border: solid #fff; border-width: 0 2.5px 2.5px 0; transform: rotate(45deg); }
.acc-tick input:focus-visible + span { box-shadow: 0 0 0 3px rgba(37, 99, 235, .25); }
.acc-tick input:disabled + span { opacity: .45; cursor: not-allowed; }
.acc-tick.v input:checked + span { background: #16a34a; border-color: #16a34a; }
.acc-tick.d input:checked + span { background: #dc2626; border-color: #dc2626; }
.acc-tick.e input:checked + span { background: #ea7a0c; border-color: #ea7a0c; }

.acc-full-note { margin: 18px; padding: 16px; border-radius: 12px; background: #fff8eb; border: 1px solid #fde1b0; color: #92400e; font-size: 14px; }
.acc-foot { display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap; padding: 14px 18px; border-top: 1px solid var(--p-border); background: #fbfcfe; border-radius: 0 0 16px 16px; position: sticky; bottom: 0; }
.acc-summary { font-size: 13px; color: var(--p-text-2); }
.acc-summary b { color: var(--p-text); }
.acc-save { border: 0; background: linear-gradient(135deg, #2563eb, #4f46e5); color: #fff; font-weight: 800; border-radius: 11px; padding: 10px 22px; font-size: 14px; box-shadow: 0 8px 20px rgba(37, 99, 235, .28); }
.acc-save:disabled { opacity: .6; }
.acc-legend { display: flex; gap: 14px; flex-wrap: wrap; font-size: 12.5px; color: var(--p-text-2); }
.acc-legend span::before { content: ""; display: inline-block; width: 10px; height: 10px; border-radius: 3px; margin-right: 5px; vertical-align: -1px; background: var(--c); }
</style>

<section class="access-section">
<div class="container-fluid p-0">

    <div class="row align-items-center g-3 mb-3">
        <div class="col-md-8">
            <h1 class="acc-title"><i class="bi bi-shield-lock text-primary me-1"></i> Access</h1>
            <p class="acc-sub">Select an employee, tick what they can <b>View</b>, <b>Add</b>, <b>Edit</b> or <b>Delete</b> on each page, then Save.</p>
        </div>
        <div class="col-md-4 text-md-end">
            <a href="add-employees.php" class="btn btn-outline-primary fw-semibold"><i class="bi bi-person-plus"></i> Add Employee</a>
        </div>
    </div>

    <div class="acc-layout">

        <!-- EMPLOYEES -->
        <div class="acc-card">
            <div class="acc-card-head">
                <h2>Employees (<span id="accCount">0</span>)</h2>
                <div class="acc-search">
                    <i class="bi bi-search"></i>
                    <input type="search" id="accSearch" placeholder="Search name, email, mobile">
                </div>
            </div>
            <div class="acc-emp-list" id="accList"></div>
        </div>

        <!-- EDITOR -->
        <div class="acc-card" id="accEditor">
            <div class="acc-empty">
                <i class="bi bi-person-check"></i>
                Select an employee on the left to set their access.
            </div>
        </div>

    </div>
</div>
</section>

<script>
(function () {
    "use strict";

    let employees = <?php echo json_encode($employees, json_flags()); ?>;
    const modules = <?php echo json_encode(array_map(fn($m) => ['label' => $m[0], 'icon' => $m[1]], $modules), json_flags()); ?>;
    const groups = <?php echo json_encode($groups, json_flags()); ?>;
    const viewOnly = <?php echo json_encode($viewOnly); ?>;
    const me = <?php echo (int)$_SESSION['user_id']; ?>;
    const ACTIONS = ["view", "add", "edit", "delete"];
    const LABEL = { view: "View", add: "Add", edit: "Edit", delete: "Delete" };
    const CLS = { view: "v", add: "", edit: "e", delete: "d" };

    const $ = id => document.getElementById(id);
    const esc = App.esc;

    let selectedId = null;
    let draft = null; // { role, access: {module: Set} }

    function initials(name) {
        return String(name || "?").trim().split(/\s+/).slice(0, 2).map(p => p[0]).join("").toUpperCase();
    }

    function accessPill(e) {
        if (!e.enabled) return '<span class="acc-pill off">Disabled</span>';
        if (e.role === "admin") return '<span class="acc-pill full">Owner / Admin</span>';
        if (e.full) return '<span class="acc-pill full">Full access</span>';
        return `<span class="acc-pill custom">${Object.keys(e.access || {}).length} pages</span>`;
    }

    function renderList() {
        const q = $("accSearch").value.trim().toLowerCase();
        const list = employees.filter(e => [e.name, e.email, e.mobile].join(" ").toLowerCase().includes(q));
        $("accCount").textContent = employees.length;
        $("accList").innerHTML = list.length ? list.map(e => `
            <button type="button" class="acc-emp ${e.id === selectedId ? "active" : ""}" data-id="${e.id}">
                <span class="acc-avatar ${e.role === "admin" ? "admin" : ""}">${esc(initials(e.name))}</span>
                <span style="min-width:0">
                    <span class="acc-emp-name d-block">${esc(e.name)}${e.id === me ? ' <small class="text-muted">(you)</small>' : ""}</span>
                    <span class="acc-emp-meta d-block">${esc(e.email || e.mobile || "")}</span>
                </span>
                ${accessPill(e)}
            </button>`).join("") : '<div class="p-3 text-muted small">No employees found.</div>';
    }

    function select(id) {
        const e = employees.find(x => x.id === id);
        if (!e) return;
        selectedId = id;
        const access = {};
        Object.keys(modules).forEach(m => {
            const acts = e.full ? (viewOnly.includes(m) ? ["view"] : ACTIONS) : ((e.access || {})[m] || []);
            access[m] = new Set(acts);
        });
        draft = { role: e.role === "admin" ? "admin" : "staff", access };
        renderList();
        renderEditor();
    }

    function countSummary() {
        let pages = 0, actions = 0;
        Object.values(draft.access).forEach(s => { if (s.size) { pages++; actions += s.size; } });
        return { pages, actions };
    }

    function renderEditor() {
        const e = employees.find(x => x.id === selectedId);
        const self = e.id === me;
        const isAdmin = draft.role === "admin";

        let rows = "";
        Object.entries(groups).forEach(([group, keys]) => {
            rows += `<tr class="grp"><td colspan="6">${esc(group)}</td></tr>`;
            keys.forEach(m => {
                const set = draft.access[m];
                const vo = viewOnly.includes(m);
                const cells = ACTIONS.map(a => {
                    if (vo && a !== "view") return '<td class="c"><span class="acc-na" title="Not used on this page">—</span></td>';
                    return `<td class="c"><label class="acc-tick ${CLS[a]}" title="${LABEL[a]} ${esc(modules[m].label)}">
                        <input type="checkbox" data-m="${m}" data-a="${a}" ${set.has(a) ? "checked" : ""} ${self ? "disabled" : ""}>
                        <span></span></label></td>`;
                }).join("");
                const allOn = vo ? set.has("view") : ACTIONS.every(a => set.has(a));
                rows += `<tr class="${set.size ? "on" : ""}">
                    <td><div class="acc-mod"><i class="bi bi-${esc(modules[m].icon)}"></i>${esc(modules[m].label)}</div></td>
                    ${cells}
                    <td class="c"><label class="acc-tick" title="All actions"><input type="checkbox" data-all="${m}" ${allOn ? "checked" : ""} ${self ? "disabled" : ""}><span></span></label></td>
                </tr>`;
            });
        });

        const s = countSummary();
        $("accEditor").innerHTML = `
            <div class="acc-head">
                <span class="acc-avatar ${e.role === "admin" ? "admin" : ""}">${esc(initials(e.name))}</span>
                <div>
                    <div class="acc-head-name">${esc(e.name)}</div>
                    <div class="acc-emp-meta">${esc([e.email, e.mobile].filter(Boolean).join(" · "))}</div>
                </div>
                <div class="acc-role" role="tablist">
                    <button type="button" data-role="staff" class="${!isAdmin ? "active" : ""}" ${self ? "disabled" : ""}><i class="bi bi-sliders"></i> Custom access</button>
                    <button type="button" data-role="admin" class="${isAdmin ? "active" : ""}" ${self ? "disabled" : ""}><i class="bi bi-star-fill"></i> Administrator</button>
                </div>
            </div>
            ${self ? '<div class="acc-full-note"><i class="bi bi-info-circle me-1"></i>This is your own account. You cannot change your own access.</div>' : ""}
            ${isAdmin ? `<div class="acc-full-note"><i class="bi bi-star-fill me-1"></i><b>Administrator</b> can open every page and do everything (view, add, edit, delete), including this Access page.</div>` : `
            <div class="acc-tools">
                <span class="label">Quick set:</span>
                <button type="button" class="acc-chip" data-preset="view">View only (all pages)</button>
                <button type="button" class="acc-chip" data-preset="full">Full access (all pages)</button>
                <button type="button" class="acc-chip" data-preset="sales">Sales staff</button>
                <button type="button" class="acc-chip" data-preset="none">Clear all</button>
                <div class="acc-legend ms-auto">
                    <span style="--c:#16a34a">View</span><span style="--c:#2563eb">Add</span><span style="--c:#ea7a0c">Edit</span><span style="--c:#dc2626">Delete</span>
                </div>
            </div>
            <div class="table-responsive" style="max-height:58vh">
                <table class="acc-table">
                    <thead><tr>
                        <th>Page / Field</th>
                        ${ACTIONS.map(a => `<th class="c">${LABEL[a]}<br><label class="acc-tick ${CLS[a]} mt-1" title="${LABEL[a]} on every page"><input type="checkbox" data-col="${a}" ${self ? "disabled" : ""}><span></span></label></th>`).join("")}
                        <th class="c">All</th>
                    </tr></thead>
                    <tbody>${rows}</tbody>
                </table>
            </div>`}
            <div class="acc-foot">
                <div class="acc-summary" id="accSummary">${isAdmin ? "<b>Full access</b> to every page" : `<b>${s.pages}</b> pages · <b>${s.actions}</b> permissions selected`}</div>
                <button type="button" class="acc-save" id="accSave" ${self ? "disabled" : ""}><i class="bi bi-check2-circle"></i> Save Access</button>
            </div>`;
        syncColumnHeads();
    }

    function syncColumnHeads() {
        document.querySelectorAll("[data-col]").forEach(box => {
            const a = box.dataset.col;
            const keys = Object.keys(modules).filter(m => a === "view" || !viewOnly.includes(m));
            box.checked = keys.every(m => draft.access[m].has(a));
        });
    }

    function setAction(m, a, on) {
        const set = draft.access[m];
        if (viewOnly.includes(m) && a !== "view") return;
        if (on) {
            set.add(a);
            set.add("view"); // any action needs the page itself
        } else {
            set.delete(a);
            if (a === "view") set.clear(); // no view -> nothing else
        }
    }

    function applyPreset(p) {
        const sales = ["dashboard", "quotations", "master_quotations", "orders", "invoices", "direct_invoice", "payments", "customers", "products"];
        Object.keys(modules).forEach(m => {
            const set = draft.access[m];
            set.clear();
            if (p === "view") set.add("view");
            if (p === "full") (viewOnly.includes(m) ? ["view"] : ACTIONS).forEach(a => set.add(a));
            if (p === "sales" && sales.includes(m)) {
                (viewOnly.includes(m) ? ["view"] : (m === "products" ? ["view"] : ["view", "add", "edit"])).forEach(a => set.add(a));
            }
        });
        renderEditor();
    }

    $("accList").addEventListener("click", ev => {
        const b = ev.target.closest(".acc-emp");
        if (b) select(Number(b.dataset.id));
    });
    $("accSearch").addEventListener("input", renderList);

    $("accEditor").addEventListener("click", ev => {
        const role = ev.target.closest("[data-role]");
        if (role && !role.disabled) { draft.role = role.dataset.role; renderEditor(); return; }
        const preset = ev.target.closest("[data-preset]");
        if (preset) { applyPreset(preset.dataset.preset); return; }
        if (ev.target.closest("#accSave")) save();
    });

    $("accEditor").addEventListener("change", ev => {
        const t = ev.target;
        if (t.dataset.m) setAction(t.dataset.m, t.dataset.a, t.checked);
        else if (t.dataset.all) {
            const m = t.dataset.all;
            (viewOnly.includes(m) ? ["view"] : ACTIONS).forEach(a => setAction(m, a, t.checked));
            if (!t.checked) draft.access[m].clear();
        } else if (t.dataset.col) {
            Object.keys(modules).forEach(m => setAction(m, t.dataset.col, t.checked));
        } else return;
        const scroll = document.querySelector("#accEditor .table-responsive");
        const top = scroll ? scroll.scrollTop : 0;
        renderEditor();
        const again = document.querySelector("#accEditor .table-responsive");
        if (again) again.scrollTop = top;
    });

    async function save() {
        const btn = $("accSave");
        const map = {};
        Object.entries(draft.access).forEach(([m, set]) => { if (set.size) map[m] = [...set]; });
        if (draft.role !== "admin" && !Object.keys(map).length) {
            App.toast("Give at least one page (View) to this employee.", "error");
            return;
        }
        btn.disabled = true;
        try {
            const result = await App.post(window.location.pathname, {
                action: "save_access",
                id: selectedId,
                role: draft.role,
                permissions: JSON.stringify(map)
            });
            employees = result.employees;
            App.toast(result.message);
            select(selectedId);
        } catch (err) {
            App.toast(err.message, "error");
            btn.disabled = false;
        }
    }

    renderList();
    const firstStaff = employees.find(e => e.role !== "admin") || employees[0];
    const fromUrl = Number(new URLSearchParams(location.search).get("id"));
    if (fromUrl && employees.some(e => e.id === fromUrl)) select(fromUrl);
    else if (firstStaff) select(firstStaff.id);
})();
</script>

<?php include 'footer.php'; ?>
