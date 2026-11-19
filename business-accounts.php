<?php
/*
| Business Accounts (main business administrators only)
| - Create a new business: it gets its own database with every table, and an
|   administrator login (username + password). Its data never mixes with others.
| - Enable / disable a business, reset its administrator password.
*/
require_once __DIR__ . '/includes/init.php';

if (!is_super_admin()) {
    http_response_code(403);
    exit('Only the main administrator can manage business accounts.');
}

$errors = [];
$form = ['business_name' => '', 'owner_name' => '', 'mobile' => '', 'email' => '', 'username' => ''];

function ba_redirect(string $msg): void
{
    header('Location: business-accounts.php?msg=' . urlencode($msg));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_valid()) {
        $errors[] = 'Session expired. Please refresh the page.';
    } else {
        $action = $_POST['action'] ?? '';

        if ($action === 'create') {
            foreach ($form as $k => $v) {
                $form[$k] = trim((string)($_POST[$k] ?? ''));
            }
            $password = (string)($_POST['password'] ?? '');

            if ($form['business_name'] === '' || mb_strlen($form['business_name']) > 200) {
                $errors[] = 'Enter the business name.';
            }
            if ($form['owner_name'] === '' || mb_strlen($form['owner_name']) > 150) {
                $errors[] = 'Enter the owner name.';
            }
            if (!preg_match('/^[0-9]{10}$/', $form['mobile'])) {
                $errors[] = 'Enter a valid 10-digit mobile number.';
            }
            if ($form['email'] !== '' && !filter_var($form['email'], FILTER_VALIDATE_EMAIL)) {
                $errors[] = 'Enter a valid email address (or leave it empty).';
            }
            if (!preg_match('/^[A-Za-z0-9._-]{3,50}$/', $form['username'])) {
                $errors[] = 'Username: 3-50 characters, letters / numbers / . _ - only (no spaces).';
            }
            if (strlen($password) < 8 || strlen($password) > 72) {
                $errors[] = 'Password must be 8 to 72 characters.';
            }
            if (!$errors && ($biz = login_taken([$form['username'], $form['mobile'], $form['email']]))) {
                $errors[] = "This username, mobile or email is already used to log in (business: $biz). Choose another.";
            }

            if (!$errors) {
                try {
                    business_create($form + ['password' => $password]);
                    log_activity('Business Accounts', 'Created business ' . $form['business_name']);
                    ba_redirect('Business "' . $form['business_name'] . '" created. Login: ' . $form['username']);
                } catch (Throwable $e) {
                    $errors[] = 'Could not create the business: ' . $e->getMessage();
                }
            }
        } else {
            $account = master_one('SELECT * FROM accounts WHERE id = ?', [(int)($_POST['id'] ?? 0)]);
            if (!$account || (int)$account['is_main']) {
                $errors[] = 'Business not found (the main business cannot be changed here).';
            } elseif ($action === 'toggle') {
                $status = $account['status'] === 'active' ? 'disabled' : 'active';
                master()->prepare('UPDATE accounts SET status = ? WHERE id = ?')->execute([$status, $account['id']]);
                log_activity('Business Accounts', ucfirst($status) . ' business ' . $account['business_name']);
                ba_redirect('Business "' . $account['business_name'] . '" is now ' . $status . '.');
            } elseif ($action === 'reset') {
                $password = (string)($_POST['password'] ?? '');
                if (strlen($password) < 8 || strlen($password) > 72) {
                    $errors[] = 'New password must be 8 to 72 characters.';
                } else {
                    $pdo = tenant_pdo($account['db_name']);
                    $pdo->prepare("UPDATE users SET password_hash = ?, enabled = 1 WHERE role = 'admin' ORDER BY id LIMIT 1")
                        ->execute([password_hash($password, PASSWORD_DEFAULT)]);
                    log_activity('Business Accounts', 'Reset admin password of ' . $account['business_name']);
                    ba_redirect('Password reset for "' . $account['business_name'] . '" (login: ' . $account['username'] . ').');
                }
            }
        }
    }
}

// Account list with a few live numbers from each business's own database.
$accounts = master_all('SELECT * FROM accounts ORDER BY is_main DESC, id');
foreach ($accounts as &$a) {
    $a['stats'] = null;
    try {
        $pdo = $a['db_name'] === current_db_name() ? db() : tenant_pdo($a['db_name']);
        $a['stats'] = [
            'users' => (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn(),
            'customers' => (int)$pdo->query('SELECT COUNT(*) FROM customers')->fetchColumn(),
            'invoices' => (int)$pdo->query('SELECT COUNT(*) FROM invoices')->fetchColumn(),
        ];
    } catch (Throwable $e) {
    }
}
unset($a);

$pageTitle = 'Business Accounts';
include 'header.php';
?>

<style>
.ba-table td, .ba-table th { vertical-align: middle; }
.ba-name { font-weight: 750; color: #142238; }
.ba-sub { font-size: 12px; color: #8a97a8; }
.ba-badge { display: inline-block; padding: 3px 10px; border-radius: 999px; font-size: 12px; font-weight: 700; }
.ba-badge.active { background: #e8f8ef; color: #15803d; }
.ba-badge.disabled { background: #fdecec; color: #b91c1c; }
.ba-badge.main { background: #eaf2ff; color: #1d4ed8; }
.ba-stat { font-size: 12.5px; color: #4b5a72; white-space: nowrap; }
.ba-actions { display: flex; gap: 6px; justify-content: flex-end; flex-wrap: wrap; }
.ba-actions .btn { font-size: 12.5px; padding: 4px 10px; }
.ba-note { font-size: 12.5px; color: #64748b; }
</style>

<section class="app-page">
<div class="container-fluid p-0">

    <div class="row align-items-center g-3 mb-3">
        <div class="col-md-8">
            <h1 class="app-title">Business Accounts</h1>
            <p class="app-subtitle">Every business gets its own login and its own separate data. One business can never see another's.</p>
        </div>
        <div class="col-md-4 text-md-end">
            <button type="button" class="btn-app" data-bs-toggle="modal" data-bs-target="#createModal">
                <i class="bi bi-plus-lg me-1"></i> New Business
            </button>
        </div>
    </div>

    <?php if (!empty($_GET['msg'])): ?>
        <div class="alert alert-success"><?php echo e($_GET['msg']); ?></div>
    <?php endif; ?>
    <?php foreach ($errors as $err): ?>
        <div class="alert alert-danger py-2"><?php echo e($err); ?></div>
    <?php endforeach; ?>

    <div class="app-card p-0">
        <div class="table-responsive">
            <table class="table ba-table mb-0">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Business</th>
                        <th>Login Username</th>
                        <th>Mobile</th>
                        <th>Data</th>
                        <th>Status</th>
                        <th>Created</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($accounts as $i => $a): ?>
                    <tr>
                        <td><?php echo $i + 1; ?></td>
                        <td>
                            <div class="ba-name"><?php echo e($a['business_name']); ?></div>
                            <div class="ba-sub"><?php echo e($a['owner_name']); ?></div>
                        </td>
                        <td><?php echo e($a['username'] ?: '-'); ?></td>
                        <td><?php echo e($a['mobile'] ?: '-'); ?></td>
                        <td class="ba-stat">
                            <?php if ($a['stats']): ?>
                                <?php echo $a['stats']['users']; ?> users ·
                                <?php echo $a['stats']['customers']; ?> customers ·
                                <?php echo $a['stats']['invoices']; ?> invoices
                            <?php else: ?>
                                <span class="text-danger">Database missing</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ((int)$a['is_main']): ?>
                                <span class="ba-badge main">Main</span>
                            <?php else: ?>
                                <span class="ba-badge <?php echo $a['status'] === 'active' ? 'active' : 'disabled'; ?>"><?php echo e(ucfirst($a['status'])); ?></span>
                            <?php endif; ?>
                        </td>
                        <td class="ba-stat"><?php echo e(date('d-m-Y', strtotime($a['created_at']))); ?></td>
                        <td>
                            <?php if (!(int)$a['is_main']): ?>
                            <div class="ba-actions">
                                <button type="button" class="btn btn-light border" data-reset="<?php echo (int)$a['id']; ?>"
                                        data-name="<?php echo e($a['business_name']); ?>">
                                    <i class="bi bi-key"></i> Reset Password
                                </button>
                                <form method="POST" onsubmit="return confirm(<?php echo e(json_encode(($a['status'] === 'active' ? 'Disable ' : 'Enable ') . $a['business_name'] . '?')); ?>);">
                                    <input type="hidden" name="csrf_token" value="<?php echo e(csrf_token()); ?>">
                                    <input type="hidden" name="action" value="toggle">
                                    <input type="hidden" name="id" value="<?php echo (int)$a['id']; ?>">
                                    <?php if ($a['status'] === 'active'): ?>
                                        <button class="btn btn-outline-danger"><i class="bi bi-slash-circle"></i> Disable</button>
                                    <?php else: ?>
                                        <button class="btn btn-outline-success"><i class="bi bi-check-circle"></i> Enable</button>
                                    <?php endif; ?>
                                </form>
                            </div>
                            <?php else: ?>
                                <div class="ba-note text-end">Your business</div>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <p class="ba-note mt-3 mb-0">
        <i class="bi bi-info-circle me-1"></i>
        Each business logs in on the same login page with its own username / mobile and password,
        and can add its own employees from Employees. Disabling a business logs out all its users.
    </p>

</div>
</section>

<!-- CREATE BUSINESS -->
<div class="modal fade" id="createModal" tabindex="-1" aria-labelledby="createModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <form method="POST" class="modal-content" autocomplete="off">
            <div class="modal-header">
                <h5 class="modal-title fw-bold" id="createModalTitle">New Business</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" name="csrf_token" value="<?php echo e(csrf_token()); ?>">
                <input type="hidden" name="action" value="create">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label fw-semibold" for="bName">Business Name *</label>
                        <input type="text" class="form-control" id="bName" name="business_name" maxlength="200" required value="<?php echo e($form['business_name']); ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold" for="bOwner">Owner Name *</label>
                        <input type="text" class="form-control" id="bOwner" name="owner_name" maxlength="150" required value="<?php echo e($form['owner_name']); ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold" for="bMobile">Mobile * <span class="ba-sub">(can also be used to log in)</span></label>
                        <input type="tel" class="form-control" id="bMobile" name="mobile" maxlength="10" pattern="[0-9]{10}" inputmode="numeric" required value="<?php echo e($form['mobile']); ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold" for="bEmail">Email</label>
                        <input type="email" class="form-control" id="bEmail" name="email" maxlength="150" value="<?php echo e($form['email']); ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold" for="bUser">Login Username *</label>
                        <input type="text" class="form-control" id="bUser" name="username" maxlength="50" pattern="[A-Za-z0-9._\-]{3,50}" required value="<?php echo e($form['username']); ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold" for="bPass">Password * <span class="ba-sub">(min 8 characters)</span></label>
                        <input type="text" class="form-control" id="bPass" name="password" minlength="8" maxlength="72" required>
                    </div>
                </div>
                <p class="ba-note mt-3 mb-0">A new empty software (same pages and fields) is created for this business. Share the username and password with the owner.</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light border" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn-app"><i class="bi bi-plus-lg me-1"></i> Create Business</button>
            </div>
        </form>
    </div>
</div>

<!-- RESET PASSWORD -->
<div class="modal fade" id="resetModal" tabindex="-1" aria-labelledby="resetModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form method="POST" class="modal-content" autocomplete="off">
            <div class="modal-header">
                <h5 class="modal-title fw-bold" id="resetModalTitle">Reset Password</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" name="csrf_token" value="<?php echo e(csrf_token()); ?>">
                <input type="hidden" name="action" value="reset">
                <input type="hidden" name="id" id="resetId">
                <p class="mb-2">New administrator password for <strong id="resetName"></strong>:</p>
                <input type="text" class="form-control" name="password" minlength="8" maxlength="72" required>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light border" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn-app">Reset Password</button>
            </div>
        </form>
    </div>
</div>

<script>
document.querySelectorAll("[data-reset]").forEach(btn => btn.addEventListener("click", () => {
    document.getElementById("resetId").value = btn.dataset.reset;
    document.getElementById("resetName").textContent = btn.dataset.name;
    bootstrap.Modal.getOrCreateInstance(document.getElementById("resetModal")).show();
}));
<?php if ($errors && ($_POST['action'] ?? '') === 'create'): ?>
bootstrap.Modal.getOrCreateInstance(document.getElementById("createModal")).show();
<?php endif; ?>
</script>

<?php include 'footer.php'; ?>
</main>

</body>
</html>
