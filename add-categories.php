<?php
require_once __DIR__ . '/includes/init.php';
$parentOptions = category_tree();
$selectedParent = (int)($_GET['parent'] ?? 0);
$nextSort = (int)db_value('SELECT COUNT(*) FROM categories');
$pageTitle = 'Add Category';
include 'header.php';
?>



<!-- ADD CATEGORIES MAIN SECTION -->
<section class="add-catogories-section">

<div class="container-fluid p-0">

    <!-- PAGE HEADER -->
    <div class="ac-header">

        <a href="categories.php"
           class="ac-back-btn"
           title="Back to Categories">
            <i class="bi bi-arrow-left"></i>
        </a>

        <div>
            <h1 class="ac-title">Add Categories</h1>

            <p class="ac-subtitle">
                Add a parent category with all its sub-categories at once, or a single category.
            </p>
        </div>

    </div>

    <style>
    .cb-tabs { display: inline-flex; background: #eef2f8; border-radius: 13px; padding: 4px; margin-bottom: 16px; }
    .cb-tabs button { border: 0; background: transparent; border-radius: 10px; padding: 9px 16px; font-weight: 700; font-size: 14px; color: #475569; }
    .cb-tabs button.active { background: #fff; color: #2563eb; box-shadow: 0 2px 6px rgba(15, 23, 42, .1); }
    .cb-card { background: #fff; border: 1px solid #e6ebf2; border-radius: 18px; box-shadow: 0 1px 2px rgba(15,23,42,.04), 0 8px 28px rgba(15,23,42,.06); overflow: hidden; }
    .cb-head { display: flex; align-items: center; gap: 14px; padding: 20px 22px; background: linear-gradient(120deg, #0b1220 0%, #1e3a8a 100%); color: #fff; }
    .cb-head .ic { width: 48px; height: 48px; border-radius: 14px; display: grid; place-items: center; background: rgba(255,255,255,.12); font-size: 22px; }
    .cb-head h2 { font-size: 18px; font-weight: 800; margin: 0; }
    .cb-head p { margin: 2px 0 0; font-size: 13px; color: #c7d4f0; }
    .cb-body { padding: 22px; }
    .cb-step { display: flex; align-items: center; gap: 10px; font-weight: 800; font-size: 14px; margin-bottom: 12px; }
    .cb-step span { width: 26px; height: 26px; border-radius: 50%; display: grid; place-items: center; background: #2563eb; color: #fff; font-size: 13px; }
    .cb-label { font-size: 13px; font-weight: 700; color: #475569; margin-bottom: 6px; display: block; }
    .cb-input { width: 100%; height: 44px; border: 1px solid #d8e0ea; border-radius: 11px; padding: 0 14px; font-size: 15px; outline: none; background: #fff; }
    .cb-input:focus { border-color: #2563eb; box-shadow: 0 0 0 3px rgba(37,99,235,.12); }
    .cb-switch { display: inline-flex; gap: 6px; margin-bottom: 12px; flex-wrap: wrap; }
    .cb-switch label { border: 1px solid #d8e0ea; border-radius: 10px; padding: 7px 12px; font-size: 13px; font-weight: 700; cursor: pointer; color: #475569; }
    .cb-switch input { display: none; }
    .cb-switch input:checked + label { border-color: #2563eb; background: #eef3ff; color: #2563eb; }
    .cb-chipbox { border: 1px solid #d8e0ea; border-radius: 13px; padding: 10px; min-height: 120px; background: #fbfcfe; display: flex; flex-wrap: wrap; gap: 8px; align-content: flex-start; cursor: text; }
    .cb-chipbox:focus-within { border-color: #2563eb; box-shadow: 0 0 0 3px rgba(37,99,235,.12); background: #fff; }
    .cb-chip { display: inline-flex; align-items: center; gap: 6px; background: #eef3ff; color: #1d4ed8; border: 1px solid #cddcff; border-radius: 20px; padding: 6px 8px 6px 12px; font-weight: 700; font-size: 13.5px; }
    .cb-chip.dup { background: #fff4e5; color: #b45309; border-color: #fcd9a8; }
    .cb-chip button { border: 0; background: rgba(29,78,216,.12); color: inherit; width: 20px; height: 20px; border-radius: 50%; display: grid; place-items: center; font-size: 11px; padding: 0; }
    .cb-chipbox input { border: 0; outline: none; background: transparent; flex: 1 1 200px; min-width: 160px; height: 32px; font-size: 14.5px; }
    .cb-hint { font-size: 12.5px; color: #8391a7; margin-top: 6px; }
    .cb-tree { border: 1px dashed #cfd9ea; border-radius: 14px; padding: 14px 16px; background: #f7f9fd; min-height: 120px; }
    .cb-tree .root { font-weight: 800; display: flex; align-items: center; gap: 8px; }
    .cb-tree .root i { color: #f59e0b; }
    .cb-tree ul { list-style: none; margin: 8px 0 0 9px; padding: 0 0 0 14px; border-left: 2px solid #dbe4f3; }
    .cb-tree li { padding: 4px 0; font-size: 14px; position: relative; }
    .cb-tree li::before { content: ""; position: absolute; left: -14px; top: 14px; width: 10px; border-top: 2px solid #dbe4f3; }
    .cb-tree li.dup { color: #b45309; }
    .cb-count { font-size: 12px; font-weight: 800; background: #2563eb; color: #fff; border-radius: 20px; padding: 2px 9px; }
    .cb-foot { display: flex; justify-content: space-between; align-items: center; gap: 10px; flex-wrap: wrap; padding: 16px 22px; border-top: 1px solid #e6ebf2; background: #fbfcfe; }
    .cb-save { border: 0; background: linear-gradient(135deg, #2563eb, #4f46e5); color: #fff; font-weight: 800; border-radius: 12px; padding: 11px 22px; box-shadow: 0 8px 20px rgba(37,99,235,.28); }
    .cb-save:disabled { opacity: .6; }
    .cb-examples button { border: 1px solid #d8e0ea; background: #fff; border-radius: 20px; padding: 3px 10px; font-size: 12px; font-weight: 700; color: #475569; margin: 2px; }
    </style>

    <div class="cb-tabs" role="tablist">
        <button type="button" class="active" data-mode="bulk"><i class="bi bi-diagram-3"></i> Parent + Sub-Categories</button>
        <button type="button" data-mode="single"><i class="bi bi-tag"></i> Single Category</button>
    </div>

    <!-- PARENT + MANY SUB-CATEGORIES -->
    <div class="cb-card" id="bulkCard">
        <div class="cb-head">
            <div class="ic"><i class="bi bi-diagram-3-fill"></i></div>
            <div>
                <h2>Add Parent Category with Sub-Categories</h2>
                <p>Create the parent once and add all its sub-categories together - no need to add them one by one.</p>
            </div>
        </div>

        <div class="cb-body">
            <div class="row g-4">
                <div class="col-lg-7">

                    <div class="cb-step"><span>1</span> Parent Category</div>
                    <div class="cb-switch">
                        <input type="radio" name="cbParentMode" id="cbNew" value="new" checked><label for="cbNew"><i class="bi bi-plus-circle"></i> New parent</label>
                        <input type="radio" name="cbParentMode" id="cbExisting" value="existing"><label for="cbExisting"><i class="bi bi-folder2-open"></i> Existing parent</label>
                    </div>
                    <div id="cbNewBox" class="row g-2">
                        <div class="col-sm-8">
                            <label class="cb-label" for="cbParentName">Parent Category Name <span class="text-danger">*</span></label>
                            <input type="text" class="cb-input" id="cbParentName" maxlength="150" placeholder="e.g. Plywood">
                        </div>
                        <div class="col-sm-4">
                            <label class="cb-label" for="cbColor">Colour</label>
                            <input type="color" class="cb-input p-1" id="cbColor" value="#2563eb">
                        </div>
                    </div>
                    <div id="cbExistingBox" class="d-none">
                        <label class="cb-label" for="cbParentSelect">Choose Parent Category <span class="text-danger">*</span></label>
                        <select class="cb-input" id="cbParentSelect">
                            <option value="">Select parent category</option>
                            <?php foreach ($parentOptions as $opt): ?>
                                <option value="<?php echo (int)$opt['id']; ?>" <?php echo $selectedParent === $opt['id'] ? 'selected' : ''; ?>>
                                    <?php echo str_repeat('&nbsp;&nbsp;&nbsp;', $opt['depth']) . e($opt['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="cb-step mt-4"><span>2</span> Sub-Categories <span class="cb-count" id="cbCount">0</span></div>
                    <div class="cb-chipbox" id="cbChipBox">
                        <input type="text" id="cbChildInput" placeholder="Type a sub-category and press Enter (or paste a list)">
                    </div>
                    <div class="cb-hint">
                        Press <b>Enter</b> or <b>,</b> after each name. You can also paste many names at once (one per line).
                        <span class="cb-examples d-block mt-1">Example:
                            <button type="button" data-example="plywood">Plywood list</button>
                            <button type="button" data-example="glass">Glass list</button>
                        </span>
                    </div>
                </div>

                <div class="col-lg-5">
                    <div class="cb-step"><span>3</span> Preview</div>
                    <div class="cb-tree" id="cbTree"></div>
                    <div class="cb-hint" id="cbDupNote"></div>
                </div>
            </div>
            <div class="alert alert-danger d-none mt-3 mb-0" id="cbError"></div>
        </div>

        <div class="cb-foot">
            <a href="categories.php" class="ac-cancel-btn"><i class="bi bi-x-lg"></i> Cancel</a>
            <button type="button" class="cb-save" id="cbSave"><i class="bi bi-check2-circle"></i> Save Parent &amp; Sub-Categories</button>
        </div>
    </div>

    <script>
    document.addEventListener("DOMContentLoaded", function () {
        "use strict";
        const $ = id => document.getElementById(id);
        const esc = App.esc;
        // Existing names under each parent (to show which are already there).
        const existingChildren = <?php
            $kids = [];
            foreach ($parentOptions as $o) {
                if ($o['parent_id']) {
                    $kids[$o['parent_id']][] = mb_strtolower($o['name']);
                }
            }
            echo json_encode((object)$kids, json_flags());
        ?>;
        const topNames = <?php echo json_encode(array_values(array_map(fn($o) => ['id' => $o['id'], 'name' => mb_strtolower($o['name'])], array_filter($parentOptions, fn($o) => !$o['parent_id']))), json_flags()); ?>;
        const examples = {
            plywood: ["Marine Plywood", "Commercial Plywood", "MR Plywood", "BWR Plywood", "BWP Plywood", "Shuttering Plywood", "Flexible Plywood"],
            glass: ["Clear Glass", "Toughened Glass", "Frosted Glass", "Tinted Glass", "Mirror Glass", "Laminated Glass"]
        };
        let children = [];

        function mode() { return document.querySelector('input[name="cbParentMode"]:checked').value; }

        function parentKey() {
            if (mode() === "existing") return $("cbParentSelect").value;
            const n = $("cbParentName").value.trim().toLowerCase();
            const hit = topNames.find(t => t.name === n);
            return hit ? String(hit.id) : "";
        }

        function isDup(name) {
            const list = existingChildren[parentKey()] || [];
            return list.includes(name.toLowerCase());
        }

        function addNames(text) {
            text.split(/[\n,;]+/).map(s => s.replace(/^[\s\-•*\d.)]+/, "").trim()).filter(Boolean).forEach(n => {
                n = n.slice(0, 150);
                if (!children.some(c => c.toLowerCase() === n.toLowerCase())) children.push(n);
            });
            render();
        }

        function render() {
            const box = $("cbChipBox");
            box.querySelectorAll(".cb-chip").forEach(c => c.remove());
            const input = $("cbChildInput");
            children.forEach((c, i) => {
                const chip = document.createElement("span");
                chip.className = "cb-chip" + (isDup(c) ? " dup" : "");
                chip.title = isDup(c) ? "Already exists under this parent - will be skipped" : "";
                chip.innerHTML = `${esc(c)}<button type="button" data-remove="${i}" aria-label="Remove ${esc(c)}"><i class="bi bi-x"></i></button>`;
                box.insertBefore(chip, input);
            });
            $("cbCount").textContent = children.length;

            const parentName = mode() === "existing"
                ? ($("cbParentSelect").selectedOptions[0] && $("cbParentSelect").value ? $("cbParentSelect").selectedOptions[0].text.trim() : "")
                : $("cbParentName").value.trim();
            const dups = children.filter(isDup);
            $("cbTree").innerHTML = `
                <div class="root"><i class="bi bi-folder-fill"></i>${esc(parentName || "Parent category")}</div>
                ${children.length ? `<ul>${children.map(c => `<li class="${isDup(c) ? "dup" : ""}">${esc(c)}${isDup(c) ? ' <small>(already there)</small>' : ""}</li>`).join("")}</ul>`
                    : '<div class="text-muted small mt-2">Sub-categories you add appear here.</div>'}`;
            $("cbDupNote").textContent = dups.length ? `${dups.length} already exist and will be skipped.` : "";
        }

        $("cbChildInput").addEventListener("keydown", e => {
            if (e.key === "Enter" || e.key === ",") {
                e.preventDefault();
                addNames(e.target.value);
                e.target.value = "";
            } else if (e.key === "Backspace" && !e.target.value && children.length) {
                children.pop();
                render();
            }
        });
        $("cbChildInput").addEventListener("paste", e => {
            const text = (e.clipboardData || window.clipboardData).getData("text");
            if (/[\n,;]/.test(text)) {
                e.preventDefault();
                addNames(text);
            }
        });
        $("cbChildInput").addEventListener("blur", e => { if (e.target.value.trim()) { addNames(e.target.value); e.target.value = ""; } });
        $("cbChipBox").addEventListener("click", e => {
            const b = e.target.closest("[data-remove]");
            if (b) { children.splice(Number(b.dataset.remove), 1); render(); return; }
            $("cbChildInput").focus();
        });
        document.querySelectorAll("[data-example]").forEach(b => b.addEventListener("click", () => {
            if (mode() === "new" && !$("cbParentName").value.trim()) $("cbParentName").value = b.dataset.example === "glass" ? "Glass" : "Plywood";
            addNames(examples[b.dataset.example].join("\n"));
        }));
        document.querySelectorAll('input[name="cbParentMode"]').forEach(r => r.addEventListener("change", () => {
            $("cbNewBox").classList.toggle("d-none", mode() !== "new");
            $("cbExistingBox").classList.toggle("d-none", mode() !== "existing");
            render();
        }));
        $("cbParentName").addEventListener("input", render);
        $("cbParentSelect").addEventListener("change", render);

        // Opened with ?parent=ID -> start on "Existing parent".
        if ($("cbParentSelect").value) {
            $("cbExisting").checked = true;
            $("cbExisting").dispatchEvent(new Event("change"));
        }

        $("cbSave").addEventListener("click", async () => {
            const err = $("cbError");
            err.classList.add("d-none");
            if ($("cbChildInput").value.trim()) { addNames($("cbChildInput").value); $("cbChildInput").value = ""; }
            const data = { children: children, colorCode: $("cbColor").value };
            if (mode() === "existing") {
                if (!$("cbParentSelect").value) { err.textContent = "Choose the parent category."; err.classList.remove("d-none"); return; }
                data.parentId = Number($("cbParentSelect").value);
            } else {
                data.parentName = $("cbParentName").value.trim();
                if (!data.parentName) { err.textContent = "Enter the parent category name."; err.classList.remove("d-none"); $("cbParentName").focus(); return; }
            }
            if (mode() === "existing" && !children.length) {
                err.textContent = "Add at least one sub-category."; err.classList.remove("d-none"); return;
            }
            const btn = $("cbSave");
            btn.disabled = true;
            try {
                const result = await App.api("categories.save_bulk", data);
                App.toast(result.message);
                setTimeout(() => { window.location.href = "categories.php?saved=1"; }, 700);
            } catch (e) {
                err.textContent = e.message;
                err.classList.remove("d-none");
                btn.disabled = false;
            }
        });

        // Tabs: Parent + Sub-Categories / Single Category.
        document.querySelectorAll(".cb-tabs [data-mode]").forEach(t => t.addEventListener("click", () => {
            document.querySelectorAll(".cb-tabs [data-mode]").forEach(x => x.classList.toggle("active", x === t));
            $("bulkCard").classList.toggle("d-none", t.dataset.mode !== "bulk");
            $("addCategoryForm").classList.toggle("d-none", t.dataset.mode !== "single");
        }));
        $("addCategoryForm").classList.add("d-none");

        render();
    });
    </script>

    <!-- CATEGORY FORM -->
    <form id="addCategoryForm">

        <div class="ac-card">

            <!-- CARD HEADER -->
            <div class="ac-card-header">

                <div class="ac-header-icon">
                    <i class="bi bi-tags-fill"></i>
                </div>

                <div>
                    <h2 class="ac-card-title">
                        Category Details
                    </h2>

                    <p class="ac-card-subtitle">
                        Fill the required category information
                    </p>
                </div>

                <span class="ac-new-badge">New</span>

            </div>

            <!-- FORM BODY -->
            <div class="ac-card-body">

                <div class="row gx-4">

                    <!-- LEFT COLUMN -->
                    <div class="col-lg-6">

                        <!-- CATEGORY NAME -->
                        <div class="ac-field">

                            <label for="categoryName"
                                   class="ac-field-label">
                                Category Name
                                <span class="ac-required">*</span>
                            </label>

                            <div class="ac-input-wrapper">

                                <i class="bi bi-tags-fill ac-input-icon"></i>

                                <input
                                    type="text"
                                    class="ac-input"
                                    id="categoryName"
                                    name="category_name"
                                    placeholder="Enter category name"
                                    maxlength="150"
                                    required
                                >

                            </div>
                        </div>

                        <!-- PARENT CATEGORY -->
                        <div class="ac-field">

                            <label for="parentCategory"
                                   class="ac-field-label">
                                Parent Category
                                <small class="text-muted fw-normal">(choose one to add a sub-category)</small>
                            </label>

                            <div class="ac-input-wrapper ac-select-wrapper">

                                <i class="bi bi-chevron-down ac-input-icon"></i>

                                <select
                                    class="ac-input ac-select"
                                    id="parentCategory"
                                    name="parent_category">

                                    <option value="">
                                        None (main category)
                                    </option>

                                    <?php foreach ($parentOptions as $opt): ?>
                                    <option value="<?php echo (int)$opt['id']; ?>" <?php echo $selectedParent === $opt['id'] ? 'selected' : ''; ?>>
                                        <?php echo str_repeat('&nbsp;&nbsp;&nbsp;', $opt['depth']) . e($opt['name']); ?>
                                    </option>
                                    <?php endforeach; ?>

                                </select>

                            </div>
                        </div>

                        <!-- COLOR CODE -->
                        <div class="ac-field">

                            <label for="categoryColor"
                                   class="ac-field-label">
                                Color Code
                            </label>

                            <div class="ac-color-wrapper">

                                <i class="bi bi-brush-fill ac-color-icon"></i>

                                <input
                                    type="color"
                                    id="categoryColor"
                                    name="color_code"
                                    class="ac-color-picker"
                                    value="#ffffff"
                                    title="Choose category color"
                                >

                            </div>
                        </div>

                    </div>

                    <!-- RIGHT COLUMN -->
                    <div class="col-lg-6">

                        <!-- SORT ID -->
                        <div class="ac-field">

                            <label for="sortId"
                                   class="ac-field-label">
                                Sort Id
                            </label>

                            <div class="ac-input-wrapper">

                                <i class="bi bi-sort-numeric-down ac-input-icon"></i>

                                <input
                                    type="number"
                                    class="ac-input"
                                    id="sortId"
                                    name="sort_id"
                                    placeholder="Enter sort ID"
                                    min="0"
                                    step="1"
                                >

                            </div>
                        </div>

                        <!-- CATEGORY IMAGE -->
                        <div class="ac-field">

                            <label for="categoryImageUrl"
                                   class="ac-field-label">
                                Category Image
                            </label>

                            <div class="ac-image-wrapper">

                                <i class="bi bi-image ac-image-icon"></i>

                                <input
                                    type="text"
                                    id="categoryImageUrl"
                                    name="category_image"
                                    class="ac-image-input"
                                    placeholder="Attach image path or URL"
                                >

                                <button
                                    type="button"
                                    id="browseImageBtn"
                                    class="ac-browse-btn">
                                    Browse
                                </button>

                                <input
                                    type="file"
                                    id="categoryImageFile"
                                    accept="image/*"
                                    hidden
                                >

                            </div>

                            <img
                                id="categoryImagePreview"
                                class="ac-image-preview"
                                alt="Category preview"
                            >

                            <div class="form-text mt-2" id="imageHelp"></div>

                        </div>

                    </div>

                </div>

            </div>

            <!-- FORM FOOTER -->
            <div class="ac-card-footer">

                <a href="categories.php"
                   class="ac-cancel-btn">
                    <i class="bi bi-x-lg"></i>
                    Cancel
                </a>

                <button type="submit"
                        id="saveCategoryBtn"
                        class="ac-save-btn">

                    <i class="bi bi-floppy"></i>
                    Save Category

                </button>

            </div>

        </div>

    </form>

</div>

</section>

<!-- BOOTSTRAP JS -->

<script>
(function () {
    "use strict";

    const $ = id => document.getElementById(id);

    const form = $("addCategoryForm");
    const browseButton = $("browseImageBtn");
    const fileInput = $("categoryImageFile");
    const imageUrlInput = $("categoryImageUrl");
    const imagePreview = $("categoryImagePreview");
    const imageHelp = $("imageHelp");
    const saveButton = $("saveCategoryBtn");

    let previewObjectUrl = null;
    let selectedImageFile = null;

    // Open image file chooser.
    browseButton.addEventListener("click", function () {
        fileInput.click();
    });

    // Image selection.
    fileInput.addEventListener("change", function () {

        const file = this.files[0];
        if (!file) return;

        if (!file.type.startsWith("image/")) {
            alert("Please select a valid image.");
            this.value = "";
            return;
        }

        selectedImageFile = file;

        if (previewObjectUrl) {
            URL.revokeObjectURL(previewObjectUrl);
        }

        previewObjectUrl = URL.createObjectURL(file);

        imagePreview.src = previewObjectUrl;
        imagePreview.style.display = "block";

        imageUrlInput.value = file.name;

        imageHelp.textContent =
            "Selected image: " + file.name;
    });

    // If a URL is typed after choosing a file, use the URL.
    imageUrlInput.addEventListener("input", function () {
        if (selectedImageFile &&
            this.value.trim() !== selectedImageFile.name) {

            selectedImageFile = null;
            fileInput.value = "";
        }

        const url = this.value.trim();

        if (/^https?:\/\//i.test(url)) {
            if (previewObjectUrl) {
                URL.revokeObjectURL(previewObjectUrl);
                previewObjectUrl = null;
            }
            imagePreview.src = url;
            imagePreview.style.display = "block";
            imageHelp.textContent = "";
        } else if (!selectedImageFile) {
            imagePreview.removeAttribute("src");
            imagePreview.style.display = "none";
        }
    });

    imagePreview.addEventListener("error", function () {
        this.style.display = "none";
        imageHelp.textContent =
            "Image preview unavailable. Check the URL.";
    });

    // Parent categories are rendered by PHP from the database.

    // Save category.
    form.addEventListener("submit", async function (event) {

        event.preventDefault();

        if (!form.reportValidity()) return;

        const name = $("categoryName").value.trim();
        const sortValue = $("sortId").value.trim();
        const sortId = sortValue === ""
            ? 0
            : Number(sortValue);

        if (!name) {
            $("categoryName").focus();
            return;
        }

        if (!Number.isSafeInteger(sortId) || sortId < 0) {
            alert("Please enter a valid Sort ID.");
            $("sortId").focus();
            return;
        }

        let imageUrl = imageUrlInput.value.trim();

        // Prevent JavaScript URLs from being stored as image links.
        if (!selectedImageFile && imageUrl &&
            !/^https?:\/\/\S+$/i.test(imageUrl) &&
            !/^\/(?!\/)\S*$/.test(imageUrl)) {
            alert("Enter a valid image URL or site-relative image path.");
            imageUrlInput.focus();
            return;
        }

        const parentCategory = $("parentCategory").value;

        saveButton.disabled = true;

        try {
            // Upload selected image file to the server first.
            if (selectedImageFile) {
                imageUrl = (await App.upload(selectedImageFile)).url;
            }

            const category = {
                name: name,
                code: "",
                sort: sortId,
                parentId: parentCategory || null,
                colorCode: $("categoryColor").value,
                imageUrl: imageUrl,
                enabled: true,
                featured: false
            };

            // Save to MySQL database.
            await App.api("categories.save", category);

            // Go back to Categories Listing.
            window.location.href = "categories.php";

        } catch (error) {
            saveButton.disabled = false;
            alert("Unable to save category: " + error.message);
        }
    });

    window.addEventListener("pagehide", function () {
        if (previewObjectUrl) {
            URL.revokeObjectURL(previewObjectUrl);
        }
    });

})();
</script>












<?php include 'footer.php'; ?>