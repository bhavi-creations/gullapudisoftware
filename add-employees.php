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

    if (empty($errors)) {

        db_insert('users', [
            'name' => $firstName,
            'username' => $email,
            'email' => $email,
            'mobile' => $mobile ?: null,
            'dob' => $dob ?: null,
            'role' => 'staff',
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'enabled' => 1,
        ]);

        log_activity('Employees', 'Added user ' . $firstName);

        header('Location: employees.php?added=1');
        exit;
    }
}

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

    /* FORM SUBMISSION */

    form.addEventListener("submit", function (event) {

        if (!form.reportValidity()) {
            event.preventDefault();
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