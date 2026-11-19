<?php
/*
| Business Accounts (main business administrators only)
| - Create a new business: it gets its own database with every table, and an
|   administrator login (username + password). Its data never mixes with others.
| - Enable / disable a business, reset its administrator password.
*/
require_once __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/backup.php';

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
            $hasLogo = !empty($_FILES['logo']['name']);
            if ($hasLogo && ($logoError = logo_check($_FILES['logo']))) {
                $errors[] = $logoError;
            }
            if (!$errors && ($biz = login_taken([$form['username'], $form['mobile'], $form['email']]))) {
                $errors[] = "This username, mobile or email is already used to log in (business: $biz). Choose another.";
            }

            if (!$errors) {
                try {
                    $newId = business_create($form + ['password' => $password]);
                    if ($hasLogo) {
                        $dbName = TENANT_DB_PREFIX . $newId;
                        try {
                            company_save(['company_logo' => logo_store($_FILES['logo'], $dbName)], tenant_pdo($dbName));
                        } catch (Throwable $e) {
                            // The business is created; the logo can be added later from Business Settings.
                        }
                    }
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
            } elseif ($action === 'update') {
                $upd = [];
                foreach (['business_name', 'owner_name', 'mobile', 'email', 'username'] as $k) {
                    $upd[$k] = trim((string)($_POST[$k] ?? ''));
                }
                if ($upd['business_name'] === '' || mb_strlen($upd['business_name']) > 200) {
                    $errors[] = 'Enter the business name.';
                }
                if ($upd['owner_name'] === '' || mb_strlen($upd['owner_name']) > 150) {
                    $errors[] = 'Enter the owner name.';
                }
                if (!preg_match('/^[0-9]{10}$/', $upd['mobile'])) {
                    $errors[] = 'Enter a valid 10-digit mobile number.';
                }
                if ($upd['email'] !== '' && !filter_var($upd['email'], FILTER_VALIDATE_EMAIL)) {
                    $errors[] = 'Enter a valid email address (or leave it empty).';
                }
                if (!preg_match('/^[A-Za-z0-9._-]{3,50}$/', $upd['username'])) {
                    $errors[] = 'Username: 3-50 characters, letters / numbers / . _ - only (no spaces).';
                }
                $hasLogo = !empty($_FILES['logo']['name']);
                if ($hasLogo && ($logoError = logo_check($_FILES['logo']))) {
                    $errors[] = $logoError;
                }

                $pdo = null;
                $admin = null;
                if (!$errors) {
                    $pdo = tenant_pdo($account['db_name']);
                    $admin = $pdo->query("SELECT * FROM users WHERE role = 'admin' ORDER BY id LIMIT 1")->fetch();
                    $logins = [$upd['username'], $upd['mobile'], $upd['email']];
                    if (($biz = login_taken($logins, $account['db_name']))) {
                        $errors[] = "This username, mobile or email is already used to log in (business: $biz). Choose another.";
                    } else {
                        // Also unique inside this business (its other employees).
                        $in = implode(',', array_fill(0, 3, '?'));
                        $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE id <> ? AND (username IN ($in) OR email IN ($in) OR mobile IN ($in))");
                        $vals = array_map(fn($v) => $v === '' ? "\0" : $v, $logins);
                        $stmt->execute(array_merge([(int)($admin['id'] ?? 0)], $vals, $vals, $vals));
                        if ((int)$stmt->fetchColumn()) {
                            $errors[] = 'One of this business\'s employees already uses that username, mobile or email.';
                        }
                    }
                }

                if (!$errors) {
                    $email = $upd['email'] !== '' ? $upd['email'] : $upd['username'] . '@business-' . $account['id'] . '.local';
                    if ($admin) {
                        $pdo->prepare('UPDATE users SET name = ?, username = ?, email = ?, mobile = ? WHERE id = ?')
                            ->execute([$upd['owner_name'], $upd['username'], $email, $upd['mobile'], $admin['id']]);
                    }

                    // Invoice / sidebar name follows the new business name unless it was customised.
                    $stmt = $pdo->query("SELECT setting_key, setting_value FROM settings WHERE setting_key IN ('company_name', 'company_short', 'company_logo')");
                    $current = array_column($stmt->fetchAll(), 'setting_value', 'setting_key');
                    $company = [];
                    if (($current['company_name'] ?? '') === '' || $current['company_name'] === $account['business_name']) {
                        $company['company_name'] = $upd['business_name'];
                    }
                    if (($current['company_short'] ?? '') === '' || $current['company_short'] === mb_strtoupper($account['business_name'])) {
                        $company['company_short'] = mb_strtoupper($upd['business_name']);
                    }
                    if (!empty($_POST['remove_logo'])) {
                        $company['company_logo'] = '';
                    }
                    if ($hasLogo) {
                        try {
                            $company['company_logo'] = logo_store($_FILES['logo'], $account['db_name']);
                        } catch (Throwable $e) {
                            $errors[] = $e->getMessage();
                        }
                    }
                    if ($company) {
                        company_save($company, $pdo);
                    }

                    master()->prepare('UPDATE accounts SET business_name = ?, owner_name = ?, mobile = ?, username = ? WHERE id = ?')
                        ->execute([$upd['business_name'], $upd['owner_name'], $upd['mobile'], $upd['username'], $account['id']]);
                    log_activity('Business Accounts', 'Updated business ' . $upd['business_name']);
                    if (!$errors) {
                        ba_redirect('Business "' . $upd['business_name'] . '" updated.');
                    }
                }
            } elseif ($action === 'delete') {
                if (trim((string)($_POST['confirm_name'] ?? '')) !== $account['business_name']) {
                    $errors[] = 'Business not deleted: the name you typed does not match "' . $account['business_name'] . '".';
                } else {
                    try {
                        // Keep a final backup of the business's data before removing it.
                        $backupDir = rtrim(realpath(BACKUP_DIR) ?: BACKUP_DIR, '/\\') . '/deleted-businesses';
                        $backupFile = '';
                        try {
                            [$backupFile] = backup_create(tenant_pdo($account['db_name']), $backupDir);
                            rename($backupDir . '/' . $backupFile, $backupDir . '/' . $account['db_name'] . '_' . $backupFile);
                            $backupFile = $account['db_name'] . '_' . $backupFile;
                        } catch (Throwable $e) {
                            // Database already missing - nothing to back up.
                        }
                        master()->exec('DROP DATABASE IF EXISTS `' . $account['db_name'] . '`');
                        master()->prepare('DELETE FROM accounts WHERE id = ?')->execute([$account['id']]);
                        foreach (glob(UPLOAD_DIR . '/logos/' . $account['db_name'] . '_*') ?: [] as $f) {
                            @unlink($f);
                        }
                        log_activity('Business Accounts', 'Deleted business ' . $account['business_name']);
                        ba_redirect('Business "' . $account['business_name'] . '" deleted.' . ($backupFile ? ' A final backup was kept in backups/deleted-businesses.' : ''));
                    } catch (Throwable $e) {
                        $errors[] = 'Could not delete the business: ' . $e->getMessage();
                    }
                }
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
    $a['logo'] = '';
    $a['email'] = '';
    try {
        $pdo = $a['db_name'] === current_db_name() ? db() : tenant_pdo($a['db_name']);
        $a['stats'] = [
            'users' => (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn(),
            'customers' => (int)$pdo->query('SELECT COUNT(*) FROM customers')->fetchColumn(),
            'invoices' => (int)$pdo->query('SELECT COUNT(*) FROM invoices')->fetchColumn(),
        ];
        $logo = (string)$pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'company_logo'")->fetchColumn();
        $a['logo'] = $logo !== '' && is_file(__DIR__ . '/' . $logo) ? $logo : '';
        $adminEmail = (string)$pdo->query("SELECT email FROM users WHERE role = 'admin' ORDER BY id LIMIT 1")->fetchColumn();
        $a['email'] = substr($adminEmail, -6) === '.local' ? '' : $adminEmail;
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
.ba-actions form { margin: 0; }
.ba-act { width: 34px; height: 34px; display: inline-grid; place-items: center; border-radius: 8px; border: 1px solid; background: #fff; font-size: 15px; padding: 0; text-decoration: none; transition: background .15s; }
.ba-act.edit { color: #1d4ed8; border-color: #cfe0ff; }
.ba-act.edit:hover { background: #eef4ff; }
.ba-act.key { color: #b45309; border-color: #fde3b5; }
.ba-act.key:hover { background: #fff7e8; }
.ba-act.off { color: #c2410c; border-color: #fed7c3; }
.ba-act.off:hover { background: #fff3ec; }
.ba-act.on { color: #15803d; border-color: #bfe8cf; }
.ba-act.on:hover { background: #ecfaf1; }
.ba-act.del { color: #dc2626; border-color: #f8caca; }
.ba-act.del:hover { background: #fdecec; }
.ba-legend { font-size: 12px; color: #8a97a8; display: flex; gap: 14px; flex-wrap: wrap; }
.ba-legend i { margin-right: 4px; }
.ba-note { font-size: 12.5px; color: #64748b; }
.ba-biz { display: flex; align-items: center; gap: 10px; }
.ba-logo { width: 38px; height: 38px; border-radius: 9px; border: 1px solid #e6ebf2; background: #fff; object-fit: contain; flex: 0 0 38px; }
.ba-logo.ph { display: grid; place-items: center; background: #eef3fa; color: #1d4ed8; font-weight: 800; font-size: 15px; }
.ba-logo-pick { display: flex; align-items: center; gap: 12px; }
.ba-logo-box { width: 64px; height: 64px; flex: 0 0 64px; border: 1px dashed #c9d6e8; border-radius: 12px; display: grid; place-items: center; background: #f8fafd; color: #94a3b8; font-size: 22px; overflow: hidden; }
.ba-logo-box img { max-width: 100%; max-height: 100%; object-fit: contain; }
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

    <?php if (!empty($_GET['msg']) && !$errors): ?>
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
                            <div class="ba-biz">
                                <?php if ($a['logo']): ?>
                                    <img class="ba-logo" src="<?php echo e($a['logo']); ?>" alt="">
                                <?php else: ?>
                                    <span class="ba-logo ph"><?php echo e(mb_strtoupper(mb_substr($a['business_name'], 0, 1))); ?></span>
                                <?php endif; ?>
                                <div>
                                    <div class="ba-name"><?php echo e($a['business_name']); ?></div>
                                    <div class="ba-sub"><?php echo e($a['owner_name']); ?></div>
                                </div>
                            </div>
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
                                <button type="button" class="ba-act edit" title="Edit" aria-label="Edit"
                                        data-edit="<?php echo e(json_encode(['id' => (int)$a['id'], 'business_name' => $a['business_name'], 'owner_name' => $a['owner_name'], 'mobile' => (string)$a['mobile'], 'email' => $a['email'], 'username' => (string)$a['username'], 'logo' => $a['logo']])); ?>">
                                    <i class="bi bi-pencil-square"></i>
                                </button>
                                <button type="button" class="ba-act key" title="Reset Password" aria-label="Reset Password" data-reset="<?php echo (int)$a['id']; ?>"
                                        data-name="<?php echo e($a['business_name']); ?>">
                                    <i class="bi bi-key"></i>
                                </button>
                                <form method="POST" onsubmit="return confirm(<?php echo e(json_encode(($a['status'] === 'active' ? 'Disable ' : 'Enable ') . $a['business_name'] . '?')); ?>);">
                                    <input type="hidden" name="csrf_token" value="<?php echo e(csrf_token()); ?>">
                                    <input type="hidden" name="action" value="toggle">
                                    <input type="hidden" name="id" value="<?php echo (int)$a['id']; ?>">
                                    <?php if ($a['status'] === 'active'): ?>
                                        <button class="ba-act off" title="Disable" aria-label="Disable"><i class="bi bi-slash-circle"></i></button>
                                    <?php else: ?>
                                        <button class="ba-act on" title="Enable" aria-label="Enable"><i class="bi bi-check-circle"></i></button>
                                    <?php endif; ?>
                                </form>
                                <button type="button" class="ba-act del" title="Delete" aria-label="Delete"
                                        data-delete="<?php echo (int)$a['id']; ?>" data-name="<?php echo e($a['business_name']); ?>"
                                        data-stats="<?php echo e($a['stats'] ? $a['stats']['users'] . ' users, ' . $a['stats']['customers'] . ' customers, ' . $a['stats']['invoices'] . ' invoices' : 'no data'); ?>">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </div>
                            <?php else: ?>
                                <div class="ba-actions">
                                    <a href="business-settings.php" class="ba-act edit" title="Edit (Business Settings)" aria-label="Edit"><i class="bi bi-pencil-square"></i></a>
                                </div>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="ba-legend mt-3">
        <span><i class="bi bi-pencil-square text-primary"></i>Edit</span>
        <span><i class="bi bi-key" style="color:#b45309"></i>Reset Password</span>
        <span><i class="bi bi-slash-circle" style="color:#c2410c"></i>Disable / <i class="bi bi-check-circle text-success"></i>Enable</span>
        <span><i class="bi bi-trash text-danger"></i>Delete</span>
    </div>

    <p class="ba-note mt-2 mb-0">
        <i class="bi bi-info-circle me-1"></i>
        Each business logs in on the same login page with its own username / mobile and password,
        and can add its own employees from Employees. Disabling a business logs out all its users.
    </p>

</div>
</section>

<!-- CREATE BUSINESS -->
<div class="modal fade" id="createModal" tabindex="-1" aria-labelledby="createModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <form method="POST" class="modal-content" autocomplete="off" enctype="multipart/form-data">
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
                    <div class="col-12">
                        <label class="form-label fw-semibold" for="bLogo">Business Logo <span class="ba-sub">(optional - printed on invoices)</span></label>
                        <div class="ba-logo-pick">
                            <div class="ba-logo-box" id="bLogoPreview"><i class="bi bi-image"></i></div>
                            <div class="flex-grow-1">
                                <input type="file" class="form-control" id="bLogo" name="logo" accept="image/png,image/jpeg,image/webp">
                                <div class="ba-sub mt-1">JPG, PNG or WEBP, up to 2 MB. Can also be added later from Business Settings.</div>
                            </div>
                        </div>
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

<!-- EDIT BUSINESS -->
<div class="modal fade" id="editModal" tabindex="-1" aria-labelledby="editModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <form method="POST" class="modal-content" autocomplete="off" enctype="multipart/form-data">
            <div class="modal-header">
                <h5 class="modal-title fw-bold" id="editModalTitle">Edit Business</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" name="csrf_token" value="<?php echo e(csrf_token()); ?>">
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="id" id="eId">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label fw-semibold" for="eName">Business Name *</label>
                        <input type="text" class="form-control" id="eName" name="business_name" maxlength="200" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold" for="eOwner">Owner Name *</label>
                        <input type="text" class="form-control" id="eOwner" name="owner_name" maxlength="150" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold" for="eMobile">Mobile *</label>
                        <input type="tel" class="form-control" id="eMobile" name="mobile" maxlength="10" pattern="[0-9]{10}" inputmode="numeric" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold" for="eEmail">Email</label>
                        <input type="email" class="form-control" id="eEmail" name="email" maxlength="150">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold" for="eUser">Login Username *</label>
                        <input type="text" class="form-control" id="eUser" name="username" maxlength="50" pattern="[A-Za-z0-9._\-]{3,50}" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Password</label>
                        <div class="form-control bg-light text-muted">Use <i class="bi bi-key"></i> Reset Password to change it</div>
                    </div>
                    <div class="col-12">
                        <label class="form-label fw-semibold" for="eLogo">Business Logo</label>
                        <div class="ba-logo-pick">
                            <div class="ba-logo-box" id="eLogoPreview"><i class="bi bi-image"></i></div>
                            <div class="flex-grow-1">
                                <input type="file" class="form-control" id="eLogo" name="logo" accept="image/png,image/jpeg,image/webp">
                                <div class="ba-sub mt-1">Choose a file only to replace the logo. JPG, PNG or WEBP, up to 2 MB.</div>
                                <div class="form-check mt-1 d-none" id="eRemoveWrap">
                                    <input class="form-check-input" type="checkbox" name="remove_logo" value="1" id="eRemoveLogo">
                                    <label class="form-check-label" for="eRemoveLogo">Remove current logo</label>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light border" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn-app"><i class="bi bi-floppy me-1"></i> Save Changes</button>
            </div>
        </form>
    </div>
</div>

<!-- DELETE BUSINESS -->
<div class="modal fade" id="deleteModal" tabindex="-1" aria-labelledby="deleteModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form method="POST" class="modal-content" autocomplete="off">
            <div class="modal-header">
                <h5 class="modal-title fw-bold text-danger" id="deleteModalTitle"><i class="bi bi-trash me-1"></i> Delete Business</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" name="csrf_token" value="<?php echo e(csrf_token()); ?>">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" id="dId">
                <div class="alert alert-danger py-2 small mb-3">
                    This permanently deletes <strong id="dName"></strong> and all its data
                    (<span id="dStats"></span>). Its users can no longer log in.
                    A final backup is kept in <code>backups/deleted-businesses</code>.
                </div>
                <label class="form-label fw-semibold" for="dConfirm">Type the business name to confirm</label>
                <input type="text" class="form-control" id="dConfirm" name="confirm_name" required>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light border" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-danger fw-bold" id="dSubmit" disabled><i class="bi bi-trash me-1"></i> Delete Permanently</button>
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
document.getElementById("bLogo").addEventListener("change", function () {
    const box = document.getElementById("bLogoPreview");
    const file = this.files[0];
    box.innerHTML = file ? "" : '<i class="bi bi-image"></i>';
    if (file) {
        const img = document.createElement("img");
        img.src = URL.createObjectURL(file);
        box.appendChild(img);
    }
});
function logoPreview(box, src) {
    box.innerHTML = "";
    if (src) {
        const img = document.createElement("img");
        img.src = src;
        box.appendChild(img);
    } else {
        box.innerHTML = '<i class="bi bi-image"></i>';
    }
}
document.querySelectorAll("[data-edit]").forEach(btn => btn.addEventListener("click", () => {
    const d = JSON.parse(btn.dataset.edit);
    document.getElementById("eId").value = d.id;
    document.getElementById("eName").value = d.business_name;
    document.getElementById("eOwner").value = d.owner_name;
    document.getElementById("eMobile").value = d.mobile;
    document.getElementById("eEmail").value = d.email;
    document.getElementById("eUser").value = d.username;
    document.getElementById("eLogo").value = "";
    document.getElementById("eRemoveLogo").checked = false;
    document.getElementById("eRemoveWrap").classList.toggle("d-none", !d.logo);
    logoPreview(document.getElementById("eLogoPreview"), d.logo);
    bootstrap.Modal.getOrCreateInstance(document.getElementById("editModal")).show();
}));
document.getElementById("eLogo").addEventListener("change", function () {
    if (this.files[0]) logoPreview(document.getElementById("eLogoPreview"), URL.createObjectURL(this.files[0]));
});
document.querySelectorAll("[data-delete]").forEach(btn => btn.addEventListener("click", () => {
    document.getElementById("dId").value = btn.dataset.delete;
    document.getElementById("dName").textContent = btn.dataset.name;
    document.getElementById("dStats").textContent = btn.dataset.stats;
    const input = document.getElementById("dConfirm");
    input.value = "";
    input.placeholder = btn.dataset.name;
    input.oninput = () => { document.getElementById("dSubmit").disabled = input.value.trim() !== btn.dataset.name; };
    document.getElementById("dSubmit").disabled = true;
    bootstrap.Modal.getOrCreateInstance(document.getElementById("deleteModal")).show();
}));
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
