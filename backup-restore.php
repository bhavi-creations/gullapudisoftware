<?php
/*
|--------------------------------------------------------------------------
| BACKUP & RESTORE PAGE
|--------------------------------------------------------------------------
| - Create Backup: full MySQL dump (.sql.gz) saved in /backups
| - Download any backup
| - Restore from a saved backup or an uploaded .sql / .sql.gz file
| History is stored in the `backup_operations` table.
|--------------------------------------------------------------------------
*/
require_once __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/backup.php';

$siteName = DB_NAME;

function backup_operations(): array
{
    $rows = db_all('SELECT * FROM backup_operations ORDER BY id DESC');
    foreach ($rows as &$r) {
        $r['id'] = (int)$r['id'];
        $r['file_size'] = $r['file_size'] !== null ? (int)$r['file_size'] : null;
        $r['downloadable'] = $r['type'] === 'Backup' && $r['status'] === 'Success'
            && $r['file_name'] && is_file(backup_dir() . '/' . basename($r['file_name']));
    }
    return $rows;
}

function backupResponse($data, $status = 200) {
    json_response($data, $status);
}

/* DOWNLOAD A BACKUP FILE */
if (isset($_GET['download'])) {
    $op = db_one("SELECT file_name FROM backup_operations WHERE id = ? AND type = 'Backup'", [(int)$_GET['download']]);
    $file = $op ? backup_dir() . '/' . basename((string)$op['file_name']) : '';
    if (!$op || !is_file($file)) {
        http_response_code(404);
        exit('Backup file not found.');
    }
    header('Content-Type: application/gzip');
    header('Content-Disposition: attachment; filename="' . basename($file) . '"');
    header('Content-Length: ' . filesize($file));
    readfile($file);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    require_csrf_json();

    $action = $_POST['action'] ?? '';
    $by = $_SESSION['user_name'] ?? 'Admin';

    if ($action === 'create_backup') {
        try {
            [$fileName, $size] = backup_create();
            db_insert('backup_operations', [
                'operation' => 'Create Backup',
                'type' => 'Backup',
                'requested_by' => $by,
                'status' => 'Success',
                'site' => $siteName,
                'file_name' => $fileName,
                'file_size' => $size,
                'command' => 'PHP database dump',
            ]);
            log_activity('Backup', 'Database backup created: ' . $fileName);
            backupResponse([
                'success' => true,
                'message' => 'Backup created successfully (' . round($size / 1024, 1) . ' KB). You can download it from the history table.',
                'operations' => backup_operations()
            ]);
        } catch (Throwable $e) {
            db_insert('backup_operations', [
                'operation' => 'Create Backup', 'type' => 'Backup', 'requested_by' => $by,
                'status' => 'Failed', 'site' => $siteName, 'command' => mb_substr($e->getMessage(), 0, 255),
            ]);
            backupResponse(['success' => false, 'message' => 'Backup failed: ' . $e->getMessage(), 'operations' => backup_operations()], 500);
        }
    }

    if ($action === 'restore_backup') {
        $source = '';
        $label = '';
        $tmpUpload = null;

        if (!empty($_FILES['backup_file']) && $_FILES['backup_file']['error'] === UPLOAD_ERR_OK) {
            $orig = $_FILES['backup_file']['name'];
            if (!preg_match('/\.sql(\.gz)?$/i', $orig)) {
                backupResponse(['success' => false, 'message' => 'Upload a .sql or .sql.gz backup file.'], 422);
            }
            $tmpUpload = backup_dir() . '/upload_' . bin2hex(random_bytes(6)) . (preg_match('/\.gz$/i', $orig) ? '.sql.gz' : '.sql');
            move_uploaded_file($_FILES['backup_file']['tmp_name'], $tmpUpload);
            $source = $tmpUpload;
            $label = $orig;
        } elseif (!empty($_POST['backup_id'])) {
            $op = db_one("SELECT file_name FROM backup_operations WHERE id = ? AND type = 'Backup' AND status = 'Success'", [(int)$_POST['backup_id']]);
            $source = $op ? backup_dir() . '/' . basename((string)$op['file_name']) : '';
            $label = $op['file_name'] ?? '';
        }

        if ($source === '' || !is_file($source)) {
            backupResponse(['success' => false, 'message' => 'Choose a backup to restore.'], 422);
        }

        try {
            // Safety copy before overwriting data.
            [$safety] = backup_create();
            db_insert('backup_operations', [
                'operation' => 'Auto Backup (before restore)', 'type' => 'Backup', 'requested_by' => $by,
                'status' => 'Success', 'site' => $siteName, 'file_name' => $safety,
                'file_size' => filesize(backup_dir() . '/' . $safety), 'command' => 'PHP database dump',
            ]);

            $count = backup_restore($source);
            db_insert('backup_operations', [
                'operation' => 'Restore Backup', 'type' => 'Restore', 'requested_by' => $by,
                'status' => 'Success', 'site' => $siteName, 'file_name' => $label,
                'command' => $count . ' SQL statements',
            ]);
            log_activity('Backup', 'Database restored from ' . $label);
            $message = 'Database restored from ' . $label . '. A safety backup was created first.';
            $ok = true;
        } catch (Throwable $e) {
            db_insert('backup_operations', [
                'operation' => 'Restore Backup', 'type' => 'Restore', 'requested_by' => $by,
                'status' => 'Failed', 'site' => $siteName, 'file_name' => $label,
                'command' => mb_substr($e->getMessage(), 0, 255),
            ]);
            $message = 'Restore failed: ' . $e->getMessage();
            $ok = false;
        }

        if ($tmpUpload && is_file($tmpUpload)) {
            unlink($tmpUpload);
        }

        backupResponse(['success' => $ok, 'message' => $message, 'operations' => backup_operations()], $ok ? 200 : 500);
    }

    if ($action === 'refresh_history') {
        backupResponse([
            'success' => true,
            'operations' => backup_operations(),
            'synced_at' => date('Y-m-d H:i:s')
        ]);
    }

    backupResponse(['success' => false, 'message' => 'Invalid action.'], 400);
}

$operations = backup_operations();
$pageTitle = 'Backup & Restore';
include 'header.php';
?>




<!-- =========================================
     BACKUP RESTORE MAIN SECTION
========================================= -->

<section class="backup-restore">

<div class="container-fluid p-0">

    <!-- PAGE HEADER -->
    <div class="row mb-4">

        <div class="col-12">

            <h1 class="br-page-title">
                Backup &amp; Restore
            </h1>

            <p class="br-page-subtitle">
                Secure your data with regular backups
                and restore when needed
            </p>

        </div>

    </div>

    <!-- MAIN GRID -->
    <div class="row g-3 align-items-start">

        <!-- LEFT CONTENT -->
        <div class="col-12 col-xl-8">

            <!-- CREATE BACKUP CARD -->
            <div class="br-card br-create-card">

                <h2 class="br-card-title">
                    Create New Backup
                </h2>

                <p class="br-card-subtitle">
                    Create a full backup of the
                    <?php echo htmlspecialchars($siteName); ?> database
                    (customers, products, orders, invoices, payments and all other data).
                </p>

                <!-- SITE TAGS -->
                <div class="br-site-tags">

                    <span class="br-site-tag">

                        <i class="bi bi-hdd-stack"></i>

                        Database:
                        <?php echo htmlspecialchars($siteName); ?>

                    </span>

                    <span class="br-site-tag">

                        <i class="bi bi-file-zip"></i>

                        .sql.gz compressed

                    </span>

                </div>

                <!-- BACKUP ACTION CONTENT -->
                <div class="br-action-area">

                    <!-- LARGE UPLOAD ICON -->
                    <div class="br-upload-circle">

                        <i class="bi bi-cloud-arrow-up-fill"></i>

                    </div>

                    <!-- BACKUP DETAILS -->
                    <div>

                        <p class="br-backup-description">

                            Backup all business data into a downloadable file.

                        </p>

                        <p class="br-command-text">

                            Saved on the server in the /backups folder.
                            Download a copy and keep it safe.

                        </p>

                        <!-- CREATE BACKUP BUTTON -->
                        <button type="button"
                                class="br-create-btn"
                                id="createBackupBtn">

                            <i class="bi bi-cloud-arrow-up-fill"></i>

                            Create Backup

                        </button>

                    </div>

                </div>

                <!-- RESULT MESSAGE -->
                <div id="backupActionMessage"
                     class="alert d-none mt-3 mb-0"
                     role="status">
                </div>

            </div>

            <!-- RESTORE CARD -->
            <div class="br-card br-create-card">

                <h2 class="br-card-title">
                    Restore Backup
                </h2>

                <p class="br-card-subtitle">
                    Replace current data with a backup file. A safety backup is
                    created automatically before restoring.
                </p>

                <div class="row g-2 align-items-end mt-2">
                    <div class="col-12 col-md-8">
                        <label class="form-label fw-semibold" for="restoreFile">
                            Backup file (.sql or .sql.gz)
                        </label>
                        <input type="file" class="form-control" id="restoreFile" accept=".sql,.gz">
                    </div>
                    <div class="col-12 col-md-4">
                        <button type="button" class="br-create-btn w-100" id="restoreUploadBtn">
                            <i class="bi bi-cloud-arrow-down-fill"></i>
                            Restore
                        </button>
                    </div>
                </div>

            </div>

            <!-- BACKUP HISTORY CARD -->
            <div class="br-card br-history-card">

                <!-- HISTORY HEADER -->
                <div class="br-history-header">

                    <div>

                        <h2 class="br-card-title">
                            Backup History
                        </h2>

                        <p class="br-card-subtitle">
                            View backups and restores for
                            <?php echo htmlspecialchars($siteName); ?>
                        </p>

                    </div>

                    <div class="br-history-actions">

                        <!-- LAST SYNC -->
                        <div>

                            <div class="br-sync-label">
                                Synced
                            </div>

                            <div class="br-sync-time"
                                 id="backupSyncedTime">

                                <?php echo date('d M Y, h:i a'); ?>

                            </div>

                        </div>

                        <!-- REFRESH BUTTON -->
                        <button type="button"
                                class="br-refresh-btn"
                                id="refreshBackupBtn">

                            <i class="bi bi-arrow-clockwise"></i>

                            Refresh

                        </button>

                    </div>

                </div>

                <!-- BACKUP HISTORY TABLE -->
                <div class="table-responsive">

                    <table class="table br-history-table">

                        <thead>

                            <tr>

                                <th>S No</th>

                                <th>Operation</th>

                                <th>Type</th>

                                <th>Requested By</th>

                                <th>Created On</th>

                                <th>Status</th>
                                <th>Action</th>

                            </tr>

                        </thead>

                        <tbody id="backupHistoryBody">

                            <tr>

                                <td colspan="7"
                                    class="br-empty-history">

                                    No backup or restore activity found yet.

                                </td>

                            </tr>

                        </tbody>

                    </table>

                </div>

                <!-- TABLE FOOTER -->
                <div class="br-history-footer"
                     id="backupHistoryFooter">

                    Showing 0 of 0 entries

                </div>

            </div>

        </div>

        <!-- RIGHT BACKUP SUMMARY -->
        <div class="col-12 col-xl-4">

            <div class="br-card br-summary-card">

                <!-- SUMMARY HEADER -->
                <div class="br-summary-heading">

                    <h2 class="br-card-title mb-0">
                        Backup Summary
                    </h2>

                    <i class="bi bi-shield-shaded"></i>

                </div>

                <!-- SUMMARY STATISTICS GRID -->
                <div class="row g-3">

                    <!-- TOTAL ENTRIES -->
                    <div class="col-6">

                        <div class="br-summary-box">

                            <div class="br-summary-icon br-purple">
                                <i class="bi bi-files"></i>
                            </div>

                            <div>

                                <div class="br-summary-label">
                                    Total Entries
                                </div>

                                <div class="br-summary-value"
                                     id="totalBackupEntries">
                                    0
                                </div>

                                <div class="br-summary-note">
                                    All operations
                                </div>

                            </div>

                        </div>

                    </div>

                    <!-- BACKUPS -->
                    <div class="col-6">

                        <div class="br-summary-box">

                            <div class="br-summary-icon br-green">
                                <i class="bi bi-cloud-arrow-up-fill"></i>
                            </div>

                            <div>

                                <div class="br-summary-label">
                                    Backups
                                </div>

                                <div class="br-summary-value"
                                     id="totalBackups">
                                    0
                                </div>

                                <div class="br-summary-note">
                                    Backup requests
                                </div>

                            </div>

                        </div>

                    </div>

                    <!-- RESTORES -->
                    <div class="col-6">

                        <div class="br-summary-box">

                            <div class="br-summary-icon br-blue">
                                <i class="bi bi-download"></i>
                            </div>

                            <div>

                                <div class="br-summary-label">
                                    Restores
                                </div>

                                <div class="br-summary-value"
                                     id="totalRestores">
                                    0
                                </div>

                                <div class="br-summary-note">
                                    Restore requests
                                </div>

                            </div>

                        </div>

                    </div>

                    <!-- SUCCESS -->
                    <div class="col-6">

                        <div class="br-summary-box">

                            <div class="br-summary-icon br-orange">
                                <i class="bi bi-check-circle-fill"></i>
                            </div>

                            <div>

                                <div class="br-summary-label">
                                    Success
                                </div>

                                <div class="br-summary-value"
                                     id="totalSuccessful">
                                    0
                                </div>

                                <div class="br-summary-note"
                                     id="failedBackupCount">
                                    0 failed
                                </div>

                            </div>

                        </div>

                    </div>

                </div>

                <!-- BACKUP STATUS ALERT -->
                <div class="br-summary-alert">

                    <div class="br-summary-alert-icon">

                        <i class="bi bi-check-circle-fill"></i>

                    </div>

                    <div>

                        <div class="br-summary-alert-title"
                             id="backupReadyTitle">

                            Backup History Ready

                        </div>

                        <p class="br-summary-alert-description"
                           id="backupReadyDescription">

                            No successful backup found yet.

                        </p>

                    </div>

                </div>

            </div>

        </div>

    </div>

</div>

</section>

<!-- BOOTSTRAP JS -->

<script>
(function () {
    "use strict";

    let operations = <?php echo json_encode(
        $operations,
        JSON_HEX_TAG |
        JSON_HEX_AMP |
        JSON_HEX_APOS |
        JSON_HEX_QUOT
    ); ?>;

    const csrfToken = <?php echo json_encode(
        csrf_token()
    ); ?>;

    const $ = id => document.getElementById(id);

    /*
     * SAFE HTML
     */

    function escapeHTML(value) {

        return String(value ?? "")
            .replace(/&/g, "&amp;")
            .replace(/</g, "&lt;")
            .replace(/>/g, "&gt;")
            .replace(/"/g, "&quot;")
            .replace(/'/g, "&#39;");
    }

    /*
     * FORMAT DATE
     */

    function formatDateTime(value) {

        if (!value) return "-";

        const parsed = value.replace(" ", "T");
        const date = new Date(parsed);

        if (Number.isNaN(date.getTime())) {
            return value;
        }

        return date.toLocaleString("en-IN", {
            day: "2-digit",
            month: "short",
            year: "numeric",
            hour: "2-digit",
            minute: "2-digit",
            hour12: true
        });
    }

    /*
     * DISPLAY ACTION MESSAGE
     */

    function showMessage(message, type = "info") {

        const box = $("backupActionMessage");

        box.className = "alert alert-" + type + " mt-3 mb-0";

        box.textContent = message;

        box.classList.remove("d-none");
    }

    /*
     * UPDATE STATISTICS
     */

    function updateSummary() {

        const total = operations.length;

        const backups = operations.filter(
            item => item.type === "Backup"
        ).length;

        const restores = operations.filter(
            item => item.type === "Restore"
        ).length;

        const success = operations.filter(
            item => item.status === "Success"
        ).length;

        const failed = operations.filter(
            item => item.status === "Failed"
        ).length;

        $("totalBackupEntries").textContent = total;

        $("totalBackups").textContent = backups;

        $("totalRestores").textContent = restores;

        $("totalSuccessful").textContent = success;

        $("failedBackupCount").textContent =
            failed + " failed";

        const successfulBackups = operations.filter(
            item =>
                item.type === "Backup" &&
                item.status === "Success"
        );

        if (successfulBackups.length > 0) {

            $("backupReadyTitle").textContent =
                "Backup Available";

            $("backupReadyDescription").textContent =
                successfulBackups.length +
                " successful backup(s) available.";

        } else {

            $("backupReadyTitle").textContent =
                "Backup History Ready";

            $("backupReadyDescription").textContent =
                "No successful backup found yet.";
        }
    }

    /*
     * RENDER BACKUP HISTORY
     */

    function renderHistory() {

        updateSummary();

        const tbody = $("backupHistoryBody");

        tbody.innerHTML = "";

        if (!operations.length) {

            tbody.innerHTML = `
                <tr>
                    <td colspan="7" class="br-empty-history">
                        No backup or restore activity found yet.
                    </td>
                </tr>
            `;

            $("backupHistoryFooter").textContent =
                "Showing 0 of 0 entries";

            return;
        }

        const sorted = [...operations].sort(
            (a, b) =>
                Number(b.id) - Number(a.id)
        );

        sorted.forEach((operation, index) => {

            const row = document.createElement("tr");

            row.innerHTML = `
                <td>
                    ${index + 1}
                </td>

                <td>
                    ${escapeHTML(operation.operation)}
                </td>

                <td>
                    ${escapeHTML(operation.type)}
                </td>

                <td>
                    ${escapeHTML(operation.requested_by)}
                </td>

                <td>
                    ${escapeHTML(
                        formatDateTime(operation.created_on)
                    )}
                </td>

                <td>
                    <span class="br-status-badge">
                        ${escapeHTML(operation.status)}
                    </span>
                </td>

                <td class="text-nowrap">
                    ${operation.downloadable ? `
                        <a class="btn btn-sm btn-light border"
                           href="backup-restore.php?download=${operation.id}"
                           title="Download ${escapeHTML(operation.file_name)}">
                            <i class="bi bi-download"></i>
                        </a>
                        <button type="button"
                                class="btn btn-sm btn-light border"
                                data-restore-id="${operation.id}"
                                title="Restore this backup">
                            <i class="bi bi-arrow-counterclockwise"></i>
                        </button>` : "-"}
                </td>
            `;

            tbody.appendChild(row);
        });

        $("backupHistoryFooter").textContent =
            "Showing " +
            operations.length +
            " of " +
            operations.length +
            " entries";
    }

    /*
     * SERVER REQUEST
     */

    async function sendBackupRequest(action) {

        const formData = new FormData();

        formData.append("action", action);

        formData.append(
            "csrf_token",
            csrfToken
        );

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
                result.message || "Request failed."
            );
        }

        return result;
    }

    /*
     * CREATE BACKUP REQUEST
     */

    $("createBackupBtn").addEventListener(
        "click",
        async function () {

            const confirmed = confirm(
                "Create a full database backup now?"
            );

            if (!confirmed) return;

            const button = this;

            button.disabled = true;

            button.innerHTML = `
                <span class="spinner-border spinner-border-sm"
                      aria-hidden="true"></span>
                Creating backup...
            `;

            try {

                const result = await sendBackupRequest(
                    "create_backup"
                );

                operations = result.operations;

                renderHistory();

                showMessage(
                    result.message,
                    "success"
                );

            } catch (error) {

                showMessage(
                    error.message,
                    "danger"
                );

            } finally {

                button.disabled = false;

                button.innerHTML = `
                    <i class="bi bi-cloud-arrow-up-fill"></i>
                    Create Backup
                `;
            }
        }
    );

    /*
     * REFRESH HISTORY
     */

    $("refreshBackupBtn").addEventListener(
        "click",
        async function () {

            const button = this;

            button.disabled = true;

            try {

                const result = await sendBackupRequest(
                    "refresh_history"
                );

                operations = result.operations;

                renderHistory();

                $("backupSyncedTime").textContent =
                    formatDateTime(result.synced_at);

            } catch (error) {

                showMessage(
                    error.message,
                    "danger"
                );

            } finally {

                button.disabled = false;
            }
        }
    );

    /*
     * RESTORE (uploaded file or saved backup)
     */

    async function runRestore(formData, label) {
        if (!confirm(
            "Restore " + label + "?\n\n" +
            "Current data will be REPLACED by the backup.\n" +
            "A safety backup of the current data is created first."
        )) return;

        showMessage("Restoring database, please wait...", "info");

        try {
            const result = await App.post(window.location.pathname, formData);
            operations = result.operations;
            renderHistory();
            showMessage(result.message, "success");
        } catch (error) {
            showMessage(error.message, "danger");
        }
    }

    $("restoreUploadBtn").addEventListener("click", function () {
        const file = $("restoreFile").files[0];
        if (!file) {
            showMessage("Choose a .sql or .sql.gz backup file first.", "warning");
            return;
        }
        const fd = new FormData();
        fd.append("action", "restore_backup");
        fd.append("backup_file", file);
        runRestore(fd, file.name).then(() => { $("restoreFile").value = ""; });
    });

    $("backupHistoryBody").addEventListener("click", function (event) {
        const button = event.target.closest("[data-restore-id]");
        if (!button) return;
        const op = operations.find(o => o.id === Number(button.dataset.restoreId));
        const fd = new FormData();
        fd.append("action", "restore_backup");
        fd.append("backup_id", button.dataset.restoreId);
        runRestore(fd, op ? op.file_name : "this backup");
    });

    /*
     * INITIAL RENDER
     */

    renderHistory();

})();
</script>




<?php include 'footer.php'; ?>