<?php
/*
|--------------------------------------------------------------------------
| EMPLOYEE / USERS PAGE - data from MySQL `users`
|--------------------------------------------------------------------------
| Every employee can log in with their email + password.
|--------------------------------------------------------------------------
*/
require_once __DIR__ . '/includes/init.php';

function employees_all(): array
{
    $rows = cast_rows(
        db_all('SELECT id, name, username, email, mobile, dob, role, enabled, last_login FROM users ORDER BY id'),
        ['enabled']
    );
    foreach ($rows as &$r) {
        $r['mobile'] = $r['mobile'] ?? '';
        $r['dob'] = $r['dob'] ?? '';
    }
    return $rows;
}

function employeeJson($data, $status = 200) {
    json_response($data, $status);
}

/* ==========================================
   SAVE / ENABLE / DISABLE EMPLOYEE
========================================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    require_csrf_json();

    $action = $_POST['action'] ?? '';

    if (!in_array($action, ['update', 'toggle', 'delete'], true)) {
        employeeJson(['success' => false, 'message' => 'Invalid action.'], 400);
    }

    $id = (int)($_POST['id'] ?? 0);
    $employee = db_one('SELECT * FROM users WHERE id = ?', [$id]);

    if (!$employee) {
        employeeJson(['success' => false, 'message' => 'Employee not found.'], 404);
    }

    $isSelf = $id === (int)$_SESSION['user_id'];

    if ($action === 'toggle') {
        if ($isSelf) {
            employeeJson(['success' => false, 'message' => 'You cannot disable your own account.'], 422);
        }
        db_exec('UPDATE users SET enabled = 1 - enabled WHERE id = ?', [$id]);
        log_activity('Employees', ($employee['enabled'] ? 'Disabled' : 'Enabled') . ' user ' . $employee['name']);
    } elseif ($action === 'delete') {
        if ($isSelf) {
            employeeJson(['success' => false, 'message' => 'You cannot delete your own account.'], 422);
        }
        db_exec('DELETE FROM users WHERE id = ?', [$id]);
        log_activity('Employees', 'Deleted user ' . $employee['name']);
    } else {
        $name = trim($_POST['name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $username = trim($_POST['username'] ?? '');
        $mobile = trim($_POST['mobile'] ?? '');
        $dob = trim($_POST['dob'] ?? '');
        $enabled = ($_POST['enabled'] ?? '0') === '1';

        if (
            $name === '' ||
            strlen($name) > 150 ||
            !filter_var($email, FILTER_VALIDATE_EMAIL) ||
            strlen($email) > 254 ||
            $username === '' ||
            strlen($username) > 150 ||
            ($mobile !== '' && !preg_match('/^[0-9]{10}$/', $mobile))
        ) {
            employeeJson(['success' => false, 'message' => 'Enter valid employee details.'], 422);
        }

        if ($dob !== '') {
            $date = DateTimeImmutable::createFromFormat('!Y-m-d', $dob);
            if (!$date || $date->format('Y-m-d') !== $dob) {
                employeeJson(['success' => false, 'message' => 'Enter a valid date of birth.'], 422);
            }
        }

        if (db_value('SELECT COUNT(*) FROM users WHERE email = ? AND id <> ?', [$email, $id])) {
            employeeJson(['success' => false, 'message' => 'Email already exists.'], 409);
        }

        if ($isSelf && !$enabled) {
            employeeJson(['success' => false, 'message' => 'You cannot disable your own account.'], 422);
        }

        $update = [
            'name' => $name,
            'email' => $email,
            'username' => $username,
            'mobile' => $mobile ?: null,
            'dob' => $dob ?: null,
            'enabled' => $enabled ? 1 : 0,
        ];

        $newPassword = (string)($_POST['password'] ?? '');
        if ($newPassword !== '') {
            if (strlen($newPassword) < 8 || strlen($newPassword) > 72) {
                employeeJson(['success' => false, 'message' => 'Password must be 8 to 72 characters.'], 422);
            }
            $update['password_hash'] = password_hash($newPassword, PASSWORD_DEFAULT);
        }

        db_update('users', $update, $id);
        log_activity('Employees', 'Updated user ' . $name);

        if ($isSelf) {
            $_SESSION['user_name'] = $name;
            $_SESSION['user_email'] = $email;
        }
    }

    employeeJson([
        'success' => true,
        'message' => 'Employee updated successfully.',
        'employees' => employees_all()
    ]);
}

$employees = employees_all();
$pageTitle = 'Employees';
include 'header.php';
?>



<!-- EMPLOYEE MAIN SECTION -->
<section class="employee-section">

<div class="container-fluid p-0">

    <!-- PAGE HEADER -->
    <div class="row align-items-center g-3 mb-3">

        <div class="col-md-8">
            <h1 class="emp-title">Users</h1>

            <p class="emp-subtitle">
                Manage system users
            </p>
        </div>

        <div class="col-md-4 text-md-end">

            <a href="add-employees.php"
               class="emp-add-btn">

                <i class="bi bi-plus-lg"></i>
                Add Employee

            </a>

        </div>

    </div>

    <!-- STATISTICS CARDS -->
    <div class="row g-3 mb-3">

        <!-- TOTAL USERS -->
        <div class="col-12 col-sm-6 col-xl-3">
            <div class="emp-stat-card">

                <div class="emp-stat-icon emp-blue">
                    <i class="bi bi-people-fill"></i>
                </div>

                <div>
                    <div class="emp-stat-label">
                        Total Users
                    </div>

                    <div class="emp-stat-value"
                         id="totalUsers">
                        2
                    </div>

                    <div class="emp-stat-note emp-text-green">
                        All users
                    </div>
                </div>

            </div>
        </div>

        <!-- ENABLED -->
        <div class="col-12 col-sm-6 col-xl-3">
            <div class="emp-stat-card">

                <div class="emp-stat-icon emp-green">
                    <i class="bi bi-check-circle-fill"></i>
                </div>

                <div>
                    <div class="emp-stat-label">
                        Enabled
                    </div>

                    <div class="emp-stat-value"
                         id="enabledUsers">
                        2
                    </div>

                    <div class="emp-stat-note">
                        Active users
                    </div>
                </div>

            </div>
        </div>

        <!-- DISABLED -->
        <div class="col-12 col-sm-6 col-xl-3">
            <div class="emp-stat-card">

                <div class="emp-stat-icon emp-red">
                    <i class="bi bi-person-circle"></i>
                </div>

                <div>
                    <div class="emp-stat-label">
                        Disabled
                    </div>

                    <div class="emp-stat-value"
                         id="disabledUsers">
                        0
                    </div>

                    <div class="emp-stat-note emp-text-red">
                        Disabled users
                    </div>
                </div>

            </div>
        </div>

        <!-- WITH MOBILE -->
        <div class="col-12 col-sm-6 col-xl-3">
            <div class="emp-stat-card">

                <div class="emp-stat-icon emp-blue">
                    <i class="bi bi-phone"></i>
                </div>

                <div>
                    <div class="emp-stat-label">
                        With Mobile
                    </div>

                    <div class="emp-stat-value"
                         id="mobileUsers">
                        1
                    </div>

                    <div class="emp-stat-note">
                        Has phone number
                    </div>
                </div>

            </div>
        </div>

    </div>

    <!-- EMPLOYEE LIST PANEL -->
    <div class="emp-panel">

        <!-- TABS -->
        <div class="emp-tabs">

            <button type="button"
                    class="emp-tab active"
                    data-tab="all">
                All (<span id="allCount">2</span>)
            </button>

            <button type="button"
                    class="emp-tab"
                    data-tab="enabled">
                Enabled (<span id="enabledCount">2</span>)
            </button>

            <button type="button"
                    class="emp-tab"
                    data-tab="disabled">
                Disabled (<span id="disabledCount">0</span>)
            </button>

        </div>

        <!-- SEARCH AND FILTER -->
        <div class="emp-toolbar">

            <div class="emp-search">

                <i class="bi bi-search"></i>

                <input type="search"
                       id="employeeSearch"
                       placeholder="Search by name, email or username...">

            </div>

            <!-- FILTER -->
            <div class="dropdown">

                <button type="button"
                        class="emp-tool-btn dropdown-toggle"
                        data-bs-toggle="dropdown"
                        data-bs-auto-close="outside">

                    <i class="bi bi-funnel-fill me-2"></i>
                    Filter
                </button>

                <div class="dropdown-menu dropdown-menu-end p-3"
                     style="min-width:250px">

                    <label class="form-label fw-bold">
                        Status
                    </label>

                    <select id="employeeStatusFilter"
                            class="form-select mb-3">

                        <option value="all">All Users</option>
                        <option value="enabled">Enabled</option>
                        <option value="disabled">Disabled</option>

                    </select>

                    <label class="form-label fw-bold">
                        Mobile Number
                    </label>

                    <select id="employeeMobileFilter"
                            class="form-select mb-3">

                        <option value="all">All</option>
                        <option value="yes">With Mobile</option>
                        <option value="no">Without Mobile</option>

                    </select>

                    <button type="button"
                            id="resetEmployeeFilters"
                            class="btn btn-outline-secondary w-100">
                        Reset Filters
                    </button>

                </div>
            </div>

            <!-- RESET -->
            <button type="button"
                    id="resetEmployeeView"
                    class="emp-tool-btn"
                    title="Reset Filters">

                <i class="bi bi-sliders2"></i>
            </button>

        </div>

        <!-- EMPLOYEE TABLE -->
        <div class="table-responsive">

            <table class="table emp-table">

                <thead>
                    <tr>
                        <th class="text-center">S No</th>
                        <th>User Details</th>
                        <th>Email</th>
                        <th>Mobile</th>
                        <th>DOB</th>
                        <th>Status</th>
                        <th class="text-center">Action</th>
                    </tr>
                </thead>

                <tbody id="employeeTableBody"></tbody>

            </table>

        </div>

        <!-- PAGINATION -->
        <div class="emp-footer">

            <span id="employeePaginationInfo">
                Showing 1 to 2 of 2 entries
            </span>

            <div class="emp-pagination">

                <button type="button"
                        class="emp-page-btn"
                        id="empFirstPage">
                    &lt;&lt;
                </button>

                <button type="button"
                        class="emp-page-btn"
                        id="empPrevPage">
                    &lt;
                </button>

                <button type="button"
                        class="emp-page-btn active"
                        id="empCurrentPage">
                    1
                </button>

                <button type="button"
                        class="emp-page-btn"
                        id="empNextPage">
                    &gt;
                </button>

                <button type="button"
                        class="emp-page-btn"
                        id="empLastPage">
                    &gt;&gt;
                </button>

                <select id="employeePageSize"
                        class="emp-page-size">

                    <option value="10">10 / page</option>
                    <option value="20" selected>20 / page</option>
                    <option value="50">50 / page</option>
                    <option value="100">100 / page</option>

                </select>

            </div>
        </div>

    </div>

</div>
</section>

<!-- VIEW EMPLOYEE MODAL -->
<div class="modal fade"
     id="employeeViewModal"
     tabindex="-1"
     aria-labelledby="employeeViewTitle"
     aria-hidden="true">

    <div class="modal-dialog modal-dialog-centered">

        <div class="modal-content">

            <div class="modal-header">

                <h5 class="modal-title fw-bold"
                    id="employeeViewTitle">
                    User Details
                </h5>

                <button type="button"
                        class="btn-close"
                        data-bs-dismiss="modal"
                        aria-label="Close"></button>

            </div>

            <div class="modal-body"
                 id="employeeViewContent"></div>

            <div class="modal-footer">

                <button type="button"
                        class="btn btn-secondary"
                        data-bs-dismiss="modal">
                    Close
                </button>

            </div>

        </div>
    </div>
</div>

<!-- EDIT EMPLOYEE MODAL -->
<div class="modal fade"
     id="employeeEditModal"
     tabindex="-1"
     aria-labelledby="employeeEditTitle"
     aria-hidden="true">

    <div class="modal-dialog modal-dialog-centered modal-lg">

        <div class="modal-content">

            <div class="modal-header">

                <h5 class="modal-title fw-bold"
                    id="employeeEditTitle">
                    Edit Employee
                </h5>

                <button type="button"
                        class="btn-close"
                        data-bs-dismiss="modal"
                        aria-label="Close"></button>

            </div>

            <form id="employeeEditForm">

                <div class="modal-body">

                    <input type="hidden" id="editEmployeeId">

                    <div class="row g-3">

                        <div class="col-md-6">

                            <label for="editEmployeeName"
                                   class="form-label">
                                Full Name *
                            </label>

                            <input type="text"
                                   class="form-control"
                                   id="editEmployeeName"
                                   maxlength="150"
                                   required>

                        </div>

                        <div class="col-md-6">

                            <label for="editEmployeeUsername"
                                   class="form-label">
                                Username *
                            </label>

                            <input type="text"
                                   class="form-control"
                                   id="editEmployeeUsername"
                                   maxlength="150"
                                   required>

                        </div>

                        <div class="col-md-6">

                            <label for="editEmployeeEmail"
                                   class="form-label">
                                Email *
                            </label>

                            <input type="email"
                                   class="form-control"
                                   id="editEmployeeEmail"
                                   required>

                        </div>

                        <div class="col-md-6">

                            <label for="editEmployeeMobile"
                                   class="form-label">
                                Mobile
                            </label>

                            <input type="tel"
                                   class="form-control"
                                   id="editEmployeeMobile"
                                   maxlength="10"
                                   pattern="[0-9]{10}">

                        </div>

                        <div class="col-md-6">

                            <label for="editEmployeeDob"
                                   class="form-label">
                                Date of Birth
                            </label>

                            <input type="date"
                                   class="form-control"
                                   id="editEmployeeDob">

                        </div>

                        <div class="col-md-6">

                            <label class="form-label d-block">
                                Status
                            </label>

                            <div class="form-check form-switch mt-2">

                                <input type="checkbox"
                                       class="form-check-input"
                                       id="editEmployeeEnabled"
                                       role="switch">

                                <label class="form-check-label"
                                       for="editEmployeeEnabled">
                                    Enabled
                                </label>

                            </div>

                        </div>

                    </div>

                    <div id="editEmployeeError"
                         class="alert alert-danger d-none mt-3"
                         role="alert"></div>

                </div>

                <div class="modal-footer">

                    <button type="button"
                            class="btn btn-light border"
                            data-bs-dismiss="modal">
                        Cancel
                    </button>

                    <button type="submit"
                            class="btn btn-primary"
                            id="saveEmployeeChanges">
                        Save Changes
                    </button>

                </div>

            </form>
        </div>

    </div>
</div>

<!-- NOTIFICATION TOAST -->
<div class="toast-container position-fixed bottom-0 end-0 p-3">

    <div class="toast"
         id="employeeToast"
         role="status"
         aria-live="polite">

        <div class="toast-header">

            <i class="bi bi-info-circle me-2"></i>

            <strong class="me-auto">Users</strong>

            <button type="button"
                    class="btn-close"
                    data-bs-dismiss="toast"></button>

        </div>

        <div class="toast-body"
             id="employeeToastMessage"></div>

    </div>
</div>

<!-- BOOTSTRAP JS -->

<script>
(function () {
    "use strict";

    let employees = <?php echo json_encode(
        $employees,
        JSON_HEX_TAG |
        JSON_HEX_AMP |
        JSON_HEX_APOS |
        JSON_HEX_QUOT
    ); ?>;

    const csrfToken = <?php echo json_encode(
        csrf_token()
    ); ?>;

    const $ = id => document.getElementById(id);

    let activeTab = "all";
    let currentPage = 1;
    let perPage = 20;

    const viewModal = bootstrap.Modal.getOrCreateInstance(
        $("employeeViewModal")
    );

    const editModal = bootstrap.Modal.getOrCreateInstance(
        $("employeeEditModal")
    );

    /* SAFE HTML */

    function escapeHTML(value) {
        return String(value ?? "")
            .replace(/&/g, "&amp;")
            .replace(/</g, "&lt;")
            .replace(/>/g, "&gt;")
            .replace(/"/g, "&quot;")
            .replace(/'/g, "&#39;");
    }

    /* DATE FORMAT */

    function formatDate(value) {
        if (!value) return "-";

        const [year, month, day] =
            value.split("-").map(Number);

        return new Date(year, month - 1, day)
            .toLocaleDateString("en-GB", {
                day: "2-digit",
                month: "short",
                year: "numeric"
            });
    }

    /* STATISTICS */

    function updateStatistics() {

        const total = employees.length;

        const enabled = employees.filter(
            employee => employee.enabled
        ).length;

        const disabled = total - enabled;

        const withMobile = employees.filter(
            employee => String(employee.mobile || "").trim() !== ""
        ).length;

        $("totalUsers").textContent = total;
        $("enabledUsers").textContent = enabled;
        $("disabledUsers").textContent = disabled;
        $("mobileUsers").textContent = withMobile;

        $("allCount").textContent = total;
        $("enabledCount").textContent = enabled;
        $("disabledCount").textContent = disabled;
    }

    /* FILTER USERS */

    function getFilteredEmployees() {

        const search = $("employeeSearch")
            .value.toLowerCase().trim();

        const status = $("employeeStatusFilter").value;
        const mobile = $("employeeMobileFilter").value;

        return employees.filter(employee => {

            if (activeTab === "enabled" && !employee.enabled)
                return false;

            if (activeTab === "disabled" && employee.enabled)
                return false;

            if (status === "enabled" && !employee.enabled)
                return false;

            if (status === "disabled" && employee.enabled)
                return false;

            const hasMobile =
                String(employee.mobile || "").trim() !== "";

            if (mobile === "yes" && !hasMobile)
                return false;

            if (mobile === "no" && hasMobile)
                return false;

            const text = [
                employee.name,
                employee.username,
                employee.email,
                employee.mobile
            ].join(" ").toLowerCase();

            return text.includes(search);
        });
    }

    /* TABLE RENDER */

    function renderEmployees() {

        updateStatistics();

        const filtered = getFilteredEmployees();

        const totalPages = Math.max(
            1,
            Math.ceil(filtered.length / perPage)
        );

        currentPage = Math.max(
            1,
            Math.min(currentPage, totalPages)
        );

        const start = (currentPage - 1) * perPage;

        const pageData = filtered.slice(
            start,
            start + perPage
        );

        const tbody = $("employeeTableBody");

        tbody.innerHTML = "";

        if (!pageData.length) {

            tbody.innerHTML = `
                <tr>
                    <td colspan="7"
                        class="text-center py-5 text-muted">
                        No users found.
                    </td>
                </tr>
            `;

        } else {

            pageData.forEach((employee, index) => {

                const row = document.createElement("tr");

                row.innerHTML = `
                    <td class="text-center">
                        ${start + index + 1}
                    </td>

                    <td>
                        <div class="emp-user-name">
                            ${escapeHTML(employee.name)}
                        </div>

                        <div class="emp-username">
                            ${escapeHTML(employee.username)}
                        </div>
                    </td>

                    <td class="emp-email">
                        ${escapeHTML(employee.email)}
                    </td>

                    <td>
                        ${escapeHTML(employee.mobile || "-")}
                    </td>

                    <td>
                        ${escapeHTML(formatDate(employee.dob))}
                    </td>

                    <td>
                        <span class="emp-badge ${employee.enabled ? "" : "disabled"}">
                            ${employee.enabled ? "Enabled" : "Disabled"}
                        </span>
                    </td>

                    <td>
                        <div class="emp-actions">

                            <button type="button"
                                    class="emp-action"
                                    data-action="view"
                                    data-id="${employee.id}"
                                    title="View User">
                                <i class="bi bi-eye"></i>
                            </button>

                            <button type="button"
                                    class="emp-action"
                                    data-action="edit"
                                    data-id="${employee.id}"
                                    title="Edit User">
                                <i class="bi bi-pencil-fill"></i>
                            </button>

                            <div class="dropdown">

                                <button type="button"
                                        class="emp-action more"
                                        data-bs-toggle="dropdown"
                                        aria-label="More Actions">
                                    <i class="bi bi-three-dots-vertical"></i>
                                </button>

                                <ul class="dropdown-menu dropdown-menu-end">

                                    <li>
                                        <button type="button"
                                                class="dropdown-item"
                                                data-action="toggle"
                                                data-id="${employee.id}">
                                            ${employee.enabled ? "Disable User" : "Enable User"}
                                        </button>
                                    </li>

                                    <li>
                                        <button type="button"
                                                class="dropdown-item"
                                                data-action="view"
                                                data-id="${employee.id}">
                                            View Details
                                        </button>
                                    </li>

                                </ul>

                            </div>

                        </div>
                    </td>
                `;

                tbody.appendChild(row);
            });
        }

        const from = filtered.length ? start + 1 : 0;
        const to = Math.min(
            start + perPage,
            filtered.length
        );

        $("employeePaginationInfo").textContent =
            `Showing ${from} to ${to} of ${filtered.length} entries`;

        $("empCurrentPage").textContent = currentPage;

        $("empFirstPage").disabled = currentPage === 1;
        $("empPrevPage").disabled = currentPage === 1;
        $("empNextPage").disabled = currentPage === totalPages;
        $("empLastPage").disabled = currentPage === totalPages;
    }

    /* VIEW USER */

    function viewEmployee(id) {

        const employee = employees.find(
            item => Number(item.id) === id
        );

        if (!employee) return;

        const fields = [
            ["Full Name", employee.name],
            ["Username", employee.username],
            ["Email", employee.email],
            ["Mobile", employee.mobile || "-"],
            ["Date of Birth", formatDate(employee.dob)],
            ["Status", employee.enabled ? "Enabled" : "Disabled"]
        ];

        $("employeeViewContent").innerHTML = fields.map(
            ([label, value]) => `
                <div class="row py-3 border-bottom">
                    <div class="col-5 fw-bold">
                        ${escapeHTML(label)}
                    </div>
                    <div class="col-7">
                        ${escapeHTML(value)}
                    </div>
                </div>
            `
        ).join("");

        viewModal.show();
    }

    /* EDIT USER */

    function editEmployee(id) {

        const employee = employees.find(
            item => Number(item.id) === id
        );

        if (!employee) return;

        $("editEmployeeId").value = employee.id;
        $("editEmployeeName").value = employee.name;
        $("editEmployeeUsername").value = employee.username;
        $("editEmployeeEmail").value = employee.email;
        $("editEmployeeMobile").value = employee.mobile || "";
        $("editEmployeeDob").value = employee.dob || "";
        $("editEmployeeEnabled").checked = employee.enabled;

        $("editEmployeeError").classList.add("d-none");
        $("editEmployeeError").textContent = "";

        editModal.show();
    }

    /* TOAST */

    function showMessage(message) {

        $("employeeToastMessage").textContent = message;

        bootstrap.Toast.getOrCreateInstance(
            $("employeeToast")
        ).show();
    }

    /* PHP AJAX REQUEST */

    async function updateEmployee(action, data) {

        const formData = new FormData();

        formData.append("action", action);
        formData.append("csrf_token", csrfToken);

        Object.entries(data).forEach(([key, value]) => {
            formData.append(key, String(value));
        });

        const response = await fetch(
            window.location.pathname,
            {
                method: "POST",
                body: formData,
                credentials: "same-origin"
            }
        );

        const result = await response.json();

        if (!response.ok || !result.success) {
            throw new Error(
                result.message || "Unable to update employee."
            );
        }

        employees = result.employees;
        renderEmployees();

        return result;
    }

    /* SAVE EDITED EMPLOYEE */

    $("employeeEditForm").addEventListener(
        "submit",
        async function(event) {

            event.preventDefault();

            if (!this.reportValidity()) return;

            const saveButton = $("saveEmployeeChanges");
            const errorBox = $("editEmployeeError");

            errorBox.classList.add("d-none");
            errorBox.textContent = "";

            saveButton.disabled = true;

            try {

                await updateEmployee("update", {
                    id: $("editEmployeeId").value,
                    name: $("editEmployeeName").value.trim(),
                    username: $("editEmployeeUsername").value.trim(),
                    email: $("editEmployeeEmail").value.trim(),
                    mobile: $("editEmployeeMobile").value.trim(),
                    dob: $("editEmployeeDob").value,
                    enabled: $("editEmployeeEnabled").checked ? "1" : "0"
                });

                editModal.hide();
                showMessage("Employee updated successfully.");

            } catch (error) {

                errorBox.textContent = error.message;
                errorBox.classList.remove("d-none");

            } finally {

                saveButton.disabled = false;
            }
        }
    );

    /* TABLE ACTIONS */

    $("employeeTableBody").addEventListener(
        "click",
        async function(event) {

            const button = event.target.closest("[data-action]");

            if (!button) return;

            const id = Number(button.dataset.id);
            const action = button.dataset.action;

            if (action === "view") {
                viewEmployee(id);
                return;
            }

            if (action === "edit") {
                editEmployee(id);
                return;
            }

            if (action === "toggle") {

                const employee = employees.find(
                    item => Number(item.id) === id
                );

                if (!employee) return;

                const newStatus = employee.enabled
                    ? "disable"
                    : "enable";

                if (!confirm(
                    `Are you sure you want to ${newStatus} this user?`
                )) return;

                button.disabled = true;

                try {

                    await updateEmployee("toggle", { id });

                    showMessage("User status updated successfully.");

                } catch (error) {

                    showMessage(error.message);
                    button.disabled = false;
                }
            }
        }
    );

    /* TABS */

    document.querySelectorAll(".employee-section .emp-tab")
        .forEach(tab => {

            tab.addEventListener("click", function() {

                document.querySelectorAll(
                    ".employee-section .emp-tab"
                ).forEach(item => {
                    item.classList.remove("active");
                });

                this.classList.add("active");

                activeTab = this.dataset.tab;
                currentPage = 1;

                renderEmployees();
            });
        });

    /* SEARCH */

    $("employeeSearch").addEventListener("input", () => {
        currentPage = 1;
        renderEmployees();
    });

    /* FILTERS */

    [
        "employeeStatusFilter",
        "employeeMobileFilter"
    ].forEach(id => {

        $(id).addEventListener("change", () => {
            currentPage = 1;
            renderEmployees();
        });
    });

    /* RESET FILTERS */

    function resetFilters() {

        $("employeeSearch").value = "";
        $("employeeStatusFilter").value = "all";
        $("employeeMobileFilter").value = "all";

        activeTab = "all";
        currentPage = 1;

        document.querySelectorAll(
            ".employee-section .emp-tab"
        ).forEach(tab => {

            tab.classList.toggle(
                "active",
                tab.dataset.tab === "all"
            );
        });

        renderEmployees();
    }

    $("resetEmployeeFilters").addEventListener(
        "click",
        resetFilters
    );

    $("resetEmployeeView").addEventListener(
        "click",
        resetFilters
    );

    /* PAGE SIZE */

    $("employeePageSize").addEventListener(
        "change",
        function() {

            perPage = Number(this.value);
            currentPage = 1;

            renderEmployees();
        }
    );

    /* PAGINATION */

    $("empFirstPage").addEventListener("click", () => {
        currentPage = 1;
        renderEmployees();
    });

    $("empPrevPage").addEventListener("click", () => {
        currentPage--;
        renderEmployees();
    });

    $("empNextPage").addEventListener("click", () => {
        currentPage++;
        renderEmployees();
    });

    $("empLastPage").addEventListener("click", () => {

        currentPage = Math.max(
            1,
            Math.ceil(getFilteredEmployees().length / perPage)
        );

        renderEmployees();
    });

    /* INITIAL RENDER */

    renderEmployees();

})();
</script>




<?php include "footer.php"; ?>