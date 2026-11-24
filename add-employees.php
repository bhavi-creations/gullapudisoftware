<?php
/*
|--------------------------------------------------------------------------
| ADD EMPLOYEE / USER PAGE - saves into MySQL `users`
|--------------------------------------------------------------------------
| The new user can log in with this email + password.
|--------------------------------------------------------------------------
*/
require_once __DIR__ . '/includes/init.php';

$errors = [];

function employeeOld($name) {
    return htmlspecialchars(
        (string)($_POST[$name] ?? ''),
        ENT_QUOTES,
        'UTF-8'
    );
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!csrf_valid()) {
        $errors[] = 'Invalid form session. Please refresh.';
    }

    $firstName = trim($_POST['first_name'] ?? '');
    $mobile = trim($_POST['mobile'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $dob = trim($_POST['dob'] ?? '');
    $password = (string)($_POST['password'] ?? '');

    if ($firstName === '' || mb_strlen($firstName) > 150) {
        $errors[] = 'Enter a valid first name.';
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Enter a valid email address.';
    }

    if ($mobile !== '' && !preg_match('/^[0-9]{10}$/', $mobile)) {
        $errors[] = 'Mobile number must contain 10 digits.';
    }

    if ($dob !== '') {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $dob);

        if (!$date || $date->format('Y-m-d') !== $dob) {
            $errors[] = 'Enter a valid date of birth.';
        } elseif ($dob > date('Y-m-d')) {
            $errors[] = 'Date of birth cannot be in the future.';
        }
    }

    if (strlen($password) < 8) {
        $errors[] = 'Password must contain at least 8 characters.';
    }

    if (strlen($password) > 72) {
        $errors[] = 'Password must not exceed 72 bytes.';
    }

    if (db_value('SELECT COUNT(*) FROM users WHERE email = ?', [$email])) {
        $errors[] = 'This email address already exists.';
    } elseif ($taken = login_taken([$email, $mobile], current_db_name())) {
        $errors[] = 'This email or mobile is already used to log in to another business. Use a different one.';
    }

    // Page access. Only an administrator can create another administrator, and an
    // employee can only give access they have themselves.
    $isAdminUser = (current_user()['role'] ?? '') === 'admin';
    $role = ($isAdminUser && ($_POST['role'] ?? '') === 'admin') ? 'admin' : 'staff';
    $permissions = clean_permissions($_POST['permissions'] ?? []);
    $myPermissions = granted_modules();
    if ($myPermissions !== null) {
        $permissions = array_values(array_intersect($permissions, $myPermissions));
    }
    if ($role === 'staff' && !$permissions) {
        $errors[] = 'Select at least one page this employee can access.';
    }

    if (empty($errors)) {

        db_insert('users', [
            'name' => $firstName,
            'username' => $email,
            'email' => $email,
            'mobile' => $mobile ?: null,
            'dob' => $dob ?: null,
            'role' => $role,
            'permissions' => $role === 'admin' ? null : json_encode(full_permission_map($permissions)),
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'enabled' => 1,
        ]);

        log_activity('Employees', 'Added user ' . $firstName);

        header('Location: employees.php?added=1');
        exit;
    }
}

$isAdminUser = (current_user()['role'] ?? '') === 'admin';
$grantable = granted_modules(); // null = can grant everything
$postedRole = $_POST['role'] ?? 'staff';
$postedPerms = $_SERVER['REQUEST_METHOD'] === 'POST'
    ? clean_permissions($_POST['permissions'] ?? [])
    : ['dashboard', 'quotations', 'customers'];

$pageTitle = 'Add Employee';
include 'header.php';
?>




<!-- ===================================
     ADD EMPLOYEE MAIN SECTION
=================================== -->

<section class="add-employee">

<div class="container-fluid p-0">

    <!-- PAGE HEADER -->
    <div class="ae-header">

        <!-- BACK BUTTON -->
        <a href="employees.php"
           class="ae-back-btn"
           title="Back to Users">

            <i class="bi bi-arrow-left"></i>

        </a>

        <div>

            <h1 class="ae-title">
                Add User
            </h1>

            <p class="ae-subtitle">
                Create a new user account.
            </p>

        </div>

    </div>

    <!-- VALIDATION ERRORS -->
    <?php if (!empty($errors)): ?>

        <div class="alert alert-danger mb-3" role="alert">

            <?php foreach ($errors as $error): ?>

                <div>
                    <?php echo htmlspecialchars(
                        $error,
                        ENT_QUOTES,
                        'UTF-8'
                    ); ?>
                </div>

            <?php endforeach; ?>

        </div>

    <?php endif; ?>

    <!-- ADD EMPLOYEE FORM -->
    <form id="addEmployeeForm"
          method="POST"
          action="add-employees.php">

        <input type="hidden"
               name="csrf_token"
               value="<?php echo htmlspecialchars(
                   csrf_token(),
                   ENT_QUOTES,
                   'UTF-8'
               ); ?>">

        <div class="ae-card">

            <!-- CARD HEADER -->
            <div class="ae-card-header">

                <div class="ae-card-icon">
                    <i class="bi bi-person-fill"></i>
                </div>

                <div>

                    <h2 class="ae-card-title">
                        User Details
                    </h2>

                    <p class="ae-card-subtitle">
                        Fill user information
                    </p>

                </div>

                <span class="ae-new-badge">
                    New
                </span>

            </div>

            <!-- FORM BODY -->
            <div class="ae-card-body">

                <div class="row gx-4">

                    <!-- LEFT COLUMN -->
                    <div class="col-lg-6">

                        <!-- FIRST NAME -->
                        <div class="ae-field">

                            <label for="employeeFirstName"
                                   class="ae-label">

                                First Name
                                <span class="ae-required">*</span>

                            </label>

                            <div class="ae-input-wrapper">

                                <i class="bi bi-person-fill ae-input-icon"></i>

                                <input
                                    type="text"
                                    id="employeeFirstName"
                                    name="first_name"
                                    class="ae-input"
                                    placeholder="Enter first name"
                                    maxlength="150"
                                    value="<?php echo employeeOld('first_name'); ?>"
                                    autocomplete="given-name"
                                    required
                                >

                            </div>

                        </div>

                        <!-- EMAIL -->
                        <div class="ae-field">

                            <label for="employeeEmail"
                                   class="ae-label">

                                Email
                                <span class="ae-required">*</span>

                            </label>

                            <div class="ae-input-wrapper">

                                <i class="bi bi-envelope ae-input-icon"></i>

                                <input
                                    type="email"
                                    id="employeeEmail"
                                    name="email"
                                    class="ae-input"
                                    placeholder="Enter email address"
                                    maxlength="254"
                                    value="<?php echo employeeOld('email'); ?>"
                                    autocomplete="email"
                                    required
                                >

                            </div>

                        </div>

                        <!-- PASSWORD -->
                        <div class="ae-field">

                            <label for="employeePassword"
                                   class="ae-label">

                                Password
                                <span class="ae-required">*</span>

                            </label>

                            <div class="ae-input-wrapper">

                                <i class="bi bi-lock-fill ae-input-icon"></i>

                                <input
                                    type="password"
                                    id="employeePassword"
                                    name="password"
                                    class="ae-input ae-password-input"
                                    placeholder="Enter password"
                                    minlength="8"
                                    autocomplete="new-password"
                                    required
                                >

                                <button type="button"
                                        class="ae-password-toggle"
                                        id="toggleEmployeePassword"
                                        aria-label="Show password"
                                        aria-pressed="false">

                                    <i class="bi bi-eye"
                                       id="passwordToggleIcon"></i>

                                </button>

                            </div>

                            <div class="ae-helper">
                                Minimum 8 characters.
                            </div>

                        </div>

                    </div>

                    <!-- RIGHT COLUMN -->
                    <div class="col-lg-6">

                        <!-- MOBILE NUMBER -->
                        <div class="ae-field">

                            <label for="employeeMobile"
                                   class="ae-label">

                                Mobile No

                            </label>

                            <div class="ae-input-wrapper">

                                <i class="bi bi-telephone-fill ae-input-icon"></i>

                                <input
                                    type="tel"
                                    id="employeeMobile"
                                    name="mobile"
                                    class="ae-input"
                                    placeholder="Enter mobile number"
                                    maxlength="10"
                                    pattern="[0-9]{10}"
                                    inputmode="numeric"
                                    value="<?php echo employeeOld('mobile'); ?>"
                                    autocomplete="tel-national"
                                >

                            </div>

                        </div>

                        <!-- DATE OF BIRTH -->
                        <div class="ae-field">

                            <label for="employeeDob"
                                   class="ae-label">

                                Date of Birth

                            </label>

                            <div class="ae-input-wrapper">

                                <i class="bi bi-calendar3 ae-input-icon"></i>

                                <input
                                    type="date"
                                    id="employeeDob"
                                    name="dob"
                                    class="ae-input"
                                    max="<?php echo date('Y-m-d'); ?>"
                                    value="<?php echo employeeOld('dob'); ?>"
                                    autocomplete="bday"
                                >

                            </div>

                        </div>

                    </div>

                </div>

                <!-- ACCESS / PERMISSIONS -->
                <div class="ae-field mt-2">

                    <label class="ae-label" for="employeeRole">
                        Access
                    </label>

                    <div class="ae-input-wrapper" style="max-width:420px">
                        <i class="bi bi-shield-lock-fill ae-input-icon"></i>
                        <select id="employeeRole" name="role" class="ae-input">
                            <option value="staff" <?php echo $postedRole !== 'admin' ? 'selected' : ''; ?>>
                                Employee - only selected pages
                            </option>
                            <?php if ($isAdminUser): ?>
                            <option value="admin" <?php echo $postedRole === 'admin' ? 'selected' : ''; ?>>
                                Administrator - full access
                            </option>
                            <?php endif; ?>
                        </select>
                    </div>

                    <div id="permissionBox" class="mt-3">
                        <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-2">
                            <div class="ae-helper m-0">Tick the pages this employee can open:</div>
                            <div class="d-flex gap-2">
                                <button type="button" class="btn btn-sm btn-outline-primary" id="permAll">Select all</button>
                                <button type="button" class="btn btn-sm btn-outline-secondary" id="permNone">Clear</button>
                            </div>
                        </div>
                        <div class="row g-2">
                            <?php foreach (app_modules() as $key => [$label, $icon]): ?>
                                <?php if ($grantable !== null && !in_array($key, $grantable, true)) continue; ?>
                                <div class="col-sm-6 col-lg-4 col-xxl-3">
                                    <label class="d-flex align-items-center gap-2 border rounded-3 px-3 py-2 h-100" style="cursor:pointer">
                                        <input type="checkbox" class="form-check-input m-0 perm-check"
                                               name="permissions[]" value="<?php echo e($key); ?>"
                                               <?php echo in_array($key, $postedPerms, true) ? 'checked' : ''; ?>>
                                        <i class="bi bi-<?php echo e($icon); ?> text-primary"></i>
                                        <span><?php echo e($label); ?></span>
                                    </label>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                </div>

            </div>

            <!-- FORM FOOTER -->
            <div class="ae-card-footer">

                <!-- CANCEL -->
                <a href="employees.php"
                   class="ae-cancel-btn">

                    <i class="bi bi-x-lg"></i>
                    Cancel

                </a>

                <!-- SAVE EMPLOYEE -->
                <button type="submit"
                        class="ae-save-btn"
                        id="saveEmployeeBtn">

                    <i class="bi bi-floppy"></i>
                    Save Employee

                </button>

            </div>

        </div>

    </form>

</div>

</section>

<!-- BOOTSTRAP JS -->

<script>
document.addEventListener("DOMContentLoaded", function () {

    "use strict";

    const form =
        document.getElementById("addEmployeeForm");

    const saveButton =
        document.getElementById("saveEmployeeBtn");

    const passwordInput =
        document.getElementById("employeePassword");

    const togglePassword =
        document.getElementById("toggleEmployeePassword");

    const passwordIcon =
        document.getElementById("passwordToggleIcon");

    const mobileInput =
        document.getElementById("employeeMobile");

    /* SHOW / HIDE PASSWORD */

    togglePassword.addEventListener("click", function () {

        const isPassword =
            passwordInput.type === "password";

        passwordInput.type = isPassword
            ? "text"
            : "password";

        passwordIcon.className = isPassword
            ? "bi bi-eye-slash"
            : "bi bi-eye";

        this.setAttribute(
            "aria-label",
            isPassword ? "Hide password" : "Show password"
        );

        this.setAttribute(
            "aria-pressed",
            String(isPassword)
        );

    });

    /* MOBILE NUMBER VALIDATION */

    mobileInput.addEventListener("input", function () {

        this.value = this.value.replace(/\D/g, "").slice(0, 10);

    });

    /* ACCESS: page checkboxes only matter for employees (not administrators) */

    const roleSelect = document.getElementById("employeeRole");
    const permissionBox = document.getElementById("permissionBox");
    const permChecks = () => permissionBox.querySelectorAll(".perm-check");

    function togglePermissionBox() {
        permissionBox.classList.toggle("d-none", roleSelect.value === "admin");
    }
    roleSelect.addEventListener("change", togglePermissionBox);
    togglePermissionBox();

    document.getElementById("permAll").addEventListener("click", () => permChecks().forEach(c => c.checked = true));
    document.getElementById("permNone").addEventListener("click", () => permChecks().forEach(c => c.checked = false));

    /* FORM SUBMISSION */

    form.addEventListener("submit", function (event) {

        if (!form.reportValidity()) {
            event.preventDefault();
            return;
        }

        if (roleSelect.value !== "admin" &&
            ![...permChecks()].some(c => c.checked)) {
            event.preventDefault();
            alert("Select at least one page this employee can access.");
            return;
        }

        if (saveButton.disabled) {
            event.preventDefault();
            return;
        }

        saveButton.disabled = true;

        saveButton.innerHTML = `
            <span class="spinner-border spinner-border-sm"
                  aria-hidden="true"></span>
            Saving...
        `;

    });

});
</script>




<?php include 'footer.php'; ?>