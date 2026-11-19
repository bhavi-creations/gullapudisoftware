<?php
require_once __DIR__ . '/includes/init.php';
$parentOptions = db_all('SELECT id, name FROM categories ORDER BY sort_order, name');
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
            <h1 class="ac-title">Add Category</h1>

            <p class="ac-subtitle">
                Create a category for product catalog grouping.
            </p>
        </div>

    </div>

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
                            </label>

                            <div class="ac-input-wrapper ac-select-wrapper">

                                <i class="bi bi-chevron-down ac-input-icon"></i>

                                <select
                                    class="ac-input ac-select"
                                    id="parentCategory"
                                    name="parent_category">

                                    <option value="">
                                        Select parent category
                                    </option>

                                    <?php foreach ($parentOptions as $opt): ?>
                                    <option value="<?php echo (int)$opt['id']; ?>">
                                        <?php echo e($opt['name']); ?>
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