<?php
/*
| Business Settings - this business's own name, address, GSTIN, bank details and logo.
| They appear on the sidebar, the Direct Invoice letterhead and the printed / PDF
| invoices and quotations. Every business edits only its own settings.
*/
require_once __DIR__ . '/includes/init.php';

if ((current_user()['role'] ?? '') !== 'admin') {
    http_response_code(403);
    exit('Only an administrator can change business settings.');
}

$errors = [];
$values = company_settings();

$fields = [
    'Business' => [
        ['company_name', 'Business / Firm Name *', 200, 'Printed at the top of invoices'],
        ['company_short', 'Short Name (sidebar)', 40, 'e.g. GULLAPUDI'],
        ['company_tagline', 'Tagline (sidebar)', 80, 'e.g. GLASS & PLYWOOD CENTRE'],
        ['company_prop', 'Proprietor', 150, ''],
        ['company_phone', 'Phone / Cell', 30, ''],
        ['company_email', 'Email', 150, ''],
        ['company_address', 'Address', 500, ''],
    ],
    'GST' => [
        ['company_gstin', 'GSTIN / UIN', 20, ''],
        ['company_state', 'State Name', 60, 'e.g. Andhra Pradesh'],
        ['company_state_code', 'State Code', 20, 'e.g. IN-AP'],
    ],
    'Bank (printed on invoices)' => [
        ['bank_holder', "A/c Holder's Name", 150, ''],
        ['bank_name', 'Bank Name', 150, ''],
        ['bank_account', 'A/c No.', 40, ''],
        ['bank_branch_ifsc', 'Branch & IFS Code', 150, ''],
    ],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_valid()) {
        $errors[] = 'Session expired. Please refresh the page.';
    }

    $new = [];
    foreach ($fields as $group) {
        foreach ($group as [$key, , $max]) {
            $new[$key] = mb_substr(trim((string)($_POST[$key] ?? '')), 0, $max);
        }
    }
    $new['company_gstin'] = strtoupper($new['company_gstin']);
    if ($new['company_name'] === '') {
        $errors[] = 'Enter the business name.';
    }
    if ($new['company_email'] !== '' && !filter_var($new['company_email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Enter a valid email address.';
    }

    // Logo upload / removal (stored per business in uploads/logos).
    $logo = $values['company_logo'];
    if (!empty($_POST['remove_logo'])) {
        $logo = '';
    }
    if (!empty($_FILES['logo']['name'])) {
        $file = $_FILES['logo'];
        $info = $file['error'] === UPLOAD_ERR_OK ? @getimagesize($file['tmp_name']) : false;
        $types = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp'];
        if (!$info || !isset($types[$info[2]])) {
            $errors[] = 'Logo must be a JPG, PNG or WEBP image.';
        } elseif ($file['size'] > 2 * 1024 * 1024) {
            $errors[] = 'Logo must be smaller than 2 MB.';
        } elseif (!$errors) {
            $dir = UPLOAD_DIR . '/logos';
            if (!is_dir($dir)) {
                mkdir($dir, 0775, true);
            }
            $name = current_db_name() . '_' . bin2hex(random_bytes(6)) . '.' . $types[$info[2]];
            if (move_uploaded_file($file['tmp_name'], $dir . '/' . $name)) {
                $logo = 'uploads/logos/' . $name;
            } else {
                $errors[] = 'Could not save the logo. Check folder permissions for /uploads.';
            }
        }
    }
    $new['company_logo'] = $logo;

    if (!$errors) {
        company_save($new);
        // Keep the account list (Business Accounts page) in step with the name.
        master()->prepare('UPDATE accounts SET business_name = ? WHERE db_name = ?')
            ->execute([$new['company_name'], current_db_name()]);
        log_activity('Settings', 'Business settings updated');
        header('Location: business-settings.php?saved=1');
        exit;
    }
    $values = $new + $values;
}

$pageTitle = 'Business Settings';
include 'header.php';
$logoShown = $values['company_logo'] !== '' && is_file(__DIR__ . '/' . $values['company_logo']) ? $values['company_logo'] : '';
?>

<style>
.bs-group { font-size: 12.5px; font-weight: 800; letter-spacing: .6px; text-transform: uppercase; color: #64748b; margin: 22px 0 10px; }
.bs-group:first-of-type { margin-top: 0; }
.bs-hint { font-size: 12px; color: #8a97a8; margin-top: 3px; }
.bs-logo { display: flex; align-items: center; gap: 14px; flex-wrap: wrap; }
.bs-logo-box { width: 84px; height: 84px; border: 1px dashed #c9d6e8; border-radius: 12px; display: grid; place-items: center; background: #f8fafd; color: #94a3b8; font-size: 26px; overflow: hidden; }
.bs-logo-box img { max-width: 100%; max-height: 100%; object-fit: contain; }
</style>

<section class="app-page">
<div class="container-fluid p-0">

    <div class="mb-4">
        <h1 class="app-title">Business Settings</h1>
        <p class="app-subtitle">Your business details for the sidebar, invoices and quotations. Only your business sees these.</p>
    </div>

    <div class="row g-3">
        <div class="col-12 col-xl-9">
            <div class="app-card">

                <?php if (isset($_GET['saved'])): ?>
                    <div class="alert alert-success">Business settings saved.</div>
                <?php endif; ?>
                <?php foreach ($errors as $err): ?>
                    <div class="alert alert-danger py-2"><?php echo e($err); ?></div>
                <?php endforeach; ?>

                <form method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="csrf_token" value="<?php echo e(csrf_token()); ?>">

                    <?php foreach ($fields as $group => $items): ?>
                        <div class="bs-group"><?php echo e($group); ?></div>
                        <div class="row g-3">
                            <?php foreach ($items as [$key, $label, $max, $hint]): ?>
                                <div class="<?php echo $key === 'company_address' ? 'col-12' : 'col-md-6 col-lg-4'; ?>">
                                    <label class="form-label fw-semibold" for="<?php echo $key; ?>"><?php echo e($label); ?></label>
                                    <input type="text" class="form-control" id="<?php echo $key; ?>" name="<?php echo $key; ?>"
                                           maxlength="<?php echo $max; ?>" value="<?php echo e($values[$key]); ?>"
                                           <?php echo $key === 'company_name' ? 'required' : ''; ?>>
                                    <?php if ($hint): ?><div class="bs-hint"><?php echo e($hint); ?></div><?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endforeach; ?>

                    <div class="bs-group">Logo (printed on invoices)</div>
                    <div class="bs-logo">
                        <div class="bs-logo-box">
                            <?php if ($logoShown): ?><img src="<?php echo e($logoShown); ?>" alt="Logo"><?php else: ?><i class="bi bi-image"></i><?php endif; ?>
                        </div>
                        <div>
                            <input type="file" name="logo" class="form-control" accept="image/png,image/jpeg,image/webp">
                            <div class="bs-hint">JPG, PNG or WEBP, up to 2 MB.</div>
                            <?php if ($logoShown): ?>
                                <div class="form-check mt-2">
                                    <input class="form-check-input" type="checkbox" name="remove_logo" value="1" id="removeLogo">
                                    <label class="form-check-label" for="removeLogo">Remove logo</label>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="d-flex gap-2 mt-4">
                        <button type="submit" class="btn-app"><i class="bi bi-floppy me-1"></i> Save Settings</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

</div>
</section>

<?php include 'footer.php'; ?>
</main>

</body>
</html>
