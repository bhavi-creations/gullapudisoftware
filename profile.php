<?php
require_once __DIR__ . '/includes/init.php';

$user = current_user();
$errors = [];
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_valid()) {
        $errors[] = 'Session expired. Please try again.';
    }

    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $mobile = trim($_POST['mobile'] ?? '');
    $dob = trim($_POST['dob'] ?? '');

    if ($name === '' || mb_strlen($name) > 150) {
        $errors[] = 'Enter a valid name.';
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Enter a valid email address.';
    }
    if ($mobile !== '' && !preg_match('/^[0-9]{10}$/', $mobile)) {
        $errors[] = 'Mobile number must contain 10 digits.';
    }
    if ($dob !== '' && !DateTimeImmutable::createFromFormat('!Y-m-d', $dob)) {
        $errors[] = 'Enter a valid date of birth.';
    }
    if (db_value('SELECT COUNT(*) FROM users WHERE email = ? AND id <> ?', [$email, $user['id']])) {
        $errors[] = 'This email is used by another user.';
    }

    if (!$errors) {
        db_update('users', [
            'name' => $name,
            'email' => $email,
            'mobile' => $mobile ?: null,
            'dob' => $dob ?: null,
        ], (int)$user['id']);
        $_SESSION['user_name'] = $name;
        $_SESSION['user_email'] = $email;
        log_activity('Profile', 'Profile updated');
        header('Location: profile.php?saved=1');
        exit;
    }

    $user = array_merge($user, compact('name', 'email', 'mobile', 'dob'));
}

if (isset($_GET['saved'])) {
    $success = 'Profile updated successfully.';
}

$pageTitle = 'My Profile';
include 'header.php';
?>

<section class="app-page">
<div class="container-fluid p-0">

    <div class="mb-4">
        <h1 class="app-title">My Profile</h1>
        <p class="app-subtitle">Update your account details</p>
    </div>

    <div class="row g-3">
        <div class="col-12 col-lg-8 col-xl-6">
            <div class="app-card">

                <?php if ($success): ?>
                    <div class="alert alert-success"><?php echo e($success); ?></div>
                <?php endif; ?>
                <?php foreach ($errors as $err): ?>
                    <div class="alert alert-danger py-2"><?php echo e($err); ?></div>
                <?php endforeach; ?>

                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo e(csrf_token()); ?>">

                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Name *</label>
                            <input type="text" name="name" class="form-control" required maxlength="150"
                                   value="<?php echo e($user['name']); ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Email *</label>
                            <input type="email" name="email" class="form-control" required
                                   value="<?php echo e($user['email']); ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Mobile</label>
                            <input type="tel" name="mobile" class="form-control" maxlength="10" pattern="[0-9]{10}"
                                   value="<?php echo e($user['mobile']); ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Date of Birth</label>
                            <input type="date" name="dob" class="form-control"
                                   value="<?php echo e($user['dob']); ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Role</label>
                            <input type="text" class="form-control" disabled value="<?php echo e(ucfirst($user['role'])); ?>">
                        </div>
                    </div>

                    <div class="d-flex gap-2 mt-4 flex-wrap">
                        <button type="submit" class="btn-app"><i class="bi bi-floppy me-1"></i> Save Profile</button>
                        <a href="change-password.php" class="btn btn-light border">Change Password</a>
                    </div>
                </form>
            </div>
        </div>
    </div>

</div>
</section>

<?php include 'footer.php'; ?>
