<?php $pageTitle = 'Add Customer'; include 'header.php'; ?>




<!-- ADD CUSTOMER PAGE -->
<section class="add-customer-section">

<div class="container-fluid p-0">

    <!-- PAGE HEADER -->
    <div class="ac-header">

        <a href="customers.php" class="ac-back"
           aria-label="Back to Customers">
            <i class="bi bi-arrow-left"></i>
        </a>

        <div>
            <h1 class="ac-title">Add Customer</h1>
            <p class="ac-subtitle">
                Create a customer profile for sales and support.
            </p>
        </div>

    </div>

    <!-- CUSTOMER FORM -->
    <form id="addCustomerForm" novalidate>

        <div class="ac-card">

            <!-- CARD HEADER -->
            <div class="ac-card-header">

                <div class="ac-header-icon">
                    <i class="bi bi-person-plus-fill"></i>
                </div>

                <div>
                    <h2 class="ac-card-title">
                        Customer Details
                    </h2>

                    <p class="ac-card-subtitle">
                        Fill the required customer information
                    </p>
                </div>

                <span class="ac-new-badge">New</span>

            </div>

            <!-- CARD BODY -->
            <div class="ac-card-body">

                <div class="row g-4">

                    <!-- LEFT COLUMN -->
                    <div class="col-lg-6">

                        <!-- ACCOUNT TYPE -->
                        <div class="ac-field">
                            <label for="accountType"
                                   class="ac-form-label">
                                Account Type
                            </label>

                            <select id="accountType"
                                    name="account_type"
                                    class="ac-form-select"
                                    required>
                                <option value="" selected disabled>
                                    Select account type
                                </option>
                                <option value="individual">
                                    Individual
                                </option>
                                <option value="business">
                                    Business
                                </option>
                            </select>
                        </div>

                        <!-- BUSINESS NAME (Business only) -->
                        <div class="ac-field d-none" id="businessNameField">
                            <label for="businessName"
                                   class="ac-form-label">
                                Business Name
                            </label>

                            <div class="ac-input-group">
                                <i class="bi bi-building ac-input-icon"></i>

                                <input type="text"
                                       id="businessName"
                                       name="business_name"
                                       class="ac-form-control"
                                       placeholder="Enter business name"
                                       maxlength="200"
                                       disabled>
                            </div>
                        </div>

                        <!-- GST NUMBER (Business only) -->
                        <div class="ac-field d-none" id="gstField">
                            <label for="customerGstin"
                                   class="ac-form-label">
                                GST Number (GSTIN)
                            </label>

                            <div class="ac-input-group">
                                <i class="bi bi-receipt ac-input-icon"></i>

                                <input type="text"
                                       id="customerGstin"
                                       name="gstin"
                                       class="ac-form-control"
                                       placeholder="e.g. 37ABCDE1234F1Z5"
                                       maxlength="15"
                                       style="text-transform: uppercase;"
                                       disabled>
                            </div>
                        </div>

                        <!-- FIRST NAME -->
                        <div class="ac-field">
                            <label for="firstName"
                                   class="ac-form-label">
                                First Name
                            </label>

                            <div class="ac-input-group">
                                <i class="bi bi-person ac-input-icon"></i>

                                <input type="text"
                                       id="firstName"
                                       name="first_name"
                                       class="ac-form-control"
                                       placeholder="Enter first name"
                                       maxlength="100"
                                       required>
                            </div>
                        </div>

                        <!-- LAST NAME -->
                        <div class="ac-field">
                            <label for="lastName"
                                   class="ac-form-label">
                                Last Name
                            </label>

                            <div class="ac-input-group">
                                <i class="bi bi-person ac-input-icon"></i>

                                <input type="text"
                                       id="lastName"
                                       name="last_name"
                                       class="ac-form-control"
                                       placeholder="Enter last name"
                                       maxlength="100">
                            </div>
                        </div>

                        <!-- EMAIL -->
                        <div class="ac-field">
                            <label for="customerEmail"
                                   class="ac-form-label">
                                Email
                            </label>

                            <div class="ac-input-group">
                                <i class="bi bi-envelope ac-input-icon"></i>

                                <input type="email"
                                       id="customerEmail"
                                       name="email"
                                       class="ac-form-control"
                                       placeholder="name@example.com">
                            </div>
                        </div>

                        <!-- MOBILE -->
                        <div class="ac-field">
                            <label for="customerMobile"
                                   class="ac-form-label">
                                Mobile
                            </label>

                            <div class="ac-input-group">
                                <i class="bi bi-phone ac-input-icon"></i>

                                <input type="tel"
                                       id="customerMobile"
                                       name="mobile"
                                       class="ac-form-control"
                                       placeholder="Enter mobile number"
                                       inputmode="numeric"
                                       pattern="[0-9]{10}"
                                       maxlength="10"
                                       required>
                            </div>
                        </div>

                    </div>

                    <!-- RIGHT COLUMN -->
                    <div class="col-lg-6">

                        <!-- ADDRESSES HEADER -->
                        <div class="ac-address-heading">
                            <h3 class="ac-address-title">
                                Addresses
                            </h3>

                            <button type="button"
                                    id="addAddressBtn"
                                    class="ac-add-address-btn">
                                <i class="bi bi-plus-lg me-1"></i>
                                Add Address
                            </button>
                        </div>

                        <!-- DYNAMIC ADDRESS CONTAINER -->
                        <div id="addressContainer"></div>

                        <!-- ACTIVE STATUS -->
                        <div class="ac-status-box">

                            <input type="checkbox"
                                   id="customerActive"
                                   name="active"
                                   class="ac-status-check"
                                   checked>

                            <label for="customerActive"
                                   class="mb-0 flex-grow-1">

                                <div class="ac-status-title">
                                    Active
                                </div>

                                <div class="ac-status-subtitle"
                                     id="activeDescription">
                                    Customer is active
                                </div>

                            </label>

                        </div>

                    </div>

                </div>

            </div>

            <!-- FOOTER BUTTONS -->
            <div class="ac-card-footer">

                <a href="customers.php"
                   class="ac-cancel-btn">
                    Cancel
                </a>

                <button type="submit"
                        id="saveCustomerBtn"
                        class="ac-save-btn">

                    <i class="bi bi-check-lg me-1"></i>
                    Save Customer

                </button>

            </div>

        </div>

    </form>

</div>

</section>

<!-- ADDRESS TEMPLATE -->
<template id="addressTemplate">

    <div class="ac-address-card">

        <div class="d-flex justify-content-between align-items-center mb-3">

            <h4 class="ac-address-card-title mb-0">
                Address 1
            </h4>

            <button type="button"
                    class="ac-remove-btn">
                <i class="bi bi-trash3 me-1"></i>
                Remove
            </button>

        </div>

        <div class="row g-3">

            <!-- ADDRESS LABEL -->
            <div class="col-md-5">

                <label class="ac-form-label">
                    Address Label
                </label>

                <div class="ac-input-group">
                    <i class="bi bi-tag-fill ac-input-icon"></i>

                    <input type="text"
                           class="ac-form-control address-label"
                           placeholder="Billing, Shipping..."
                           maxlength="100">
                </div>

            </div>

            <!-- FULL ADDRESS -->
            <div class="col-md-7">

                <label class="ac-form-label">
                    Address
                </label>

                <div class="ac-textarea-wrap">
                    <i class="bi bi-geo-alt-fill ac-textarea-icon"></i>

                    <textarea
                        class="ac-address-textarea address-value"
                        placeholder="Enter full address"
                        rows="3"></textarea>
                </div>

            </div>

        </div>

    </div>

</template>

<!-- SUCCESS TOAST -->
<div class="toast-container position-fixed bottom-0 end-0 p-3">
    <div id="successToast"
         class="toast"
         role="status"
         aria-live="polite">

        <div class="toast-header">
            <i class="bi bi-check-circle-fill text-success me-2"></i>
            <strong class="me-auto">Customer Saved</strong>
            <button type="button"
                    class="btn-close"
                    data-bs-dismiss="toast"></button>
        </div>

        <div class="toast-body">
            Customer details saved successfully.
        </div>
    </div>
</div>


<script>
(function () {
    "use strict";

    const addressContainer =
        document.getElementById("addressContainer");

    const addressTemplate =
        document.getElementById("addressTemplate");

    const addAddressBtn =
        document.getElementById("addAddressBtn");

    const addCustomerForm =
        document.getElementById("addCustomerForm");

    const activeCheckbox =
        document.getElementById("customerActive");

    const activeDescription =
        document.getElementById("activeDescription");

    const saveButton =
        document.getElementById("saveCustomerBtn");

    // Refresh address numbering and field names.
    function updateAddressNumbers() {

        const cards =
            addressContainer.querySelectorAll(".ac-address-card");

        cards.forEach((card, index) => {

            card.querySelector(".ac-address-card-title")
                .textContent = "Address " + (index + 1);

            card.querySelector(".address-label")
                .name = `addresses[${index}][label]`;

            card.querySelector(".address-value")
                .name = `addresses[${index}][address]`;

            const removeButton =
                card.querySelector(".ac-remove-btn");

            removeButton.disabled = cards.length === 1;
        });
    }

    // Add new address.
    function addAddress() {

        const newCard =
            addressTemplate.content.firstElementChild.cloneNode(true);

        addressContainer.appendChild(newCard);

        updateAddressNumbers();
    }

    // Add address button.
    addAddressBtn.addEventListener("click", function () {
        addAddress();
    });

    // Remove address button.
    addressContainer.addEventListener("click", function (event) {

        const removeButton =
            event.target.closest(".ac-remove-btn");

        if (!removeButton) return;

        const cards =
            addressContainer.querySelectorAll(".ac-address-card");

        if (cards.length <= 1) return;

        removeButton.closest(".ac-address-card").remove();

        updateAddressNumbers();
    });

    // Active / Inactive label.
    activeCheckbox.addEventListener("change", function () {

        activeDescription.textContent = this.checked
            ? "Customer is active"
            : "Customer is inactive";
    });

    // Show GST field only for Business accounts.
    const accountTypeSelect = document.getElementById("accountType");
    const gstField = document.getElementById("gstField");
    const gstInput = document.getElementById("customerGstin");
    const businessNameField = document.getElementById("businessNameField");
    const businessNameInput = document.getElementById("businessName");

    function toggleGstField() {
        const isBusiness = accountTypeSelect.value === "business";
        [gstField, businessNameField].forEach(f => f.classList.toggle("d-none", !isBusiness));
        [gstInput, businessNameInput].forEach(i => {
            i.disabled = !isBusiness;
            if (!isBusiness) i.value = "";
        });
    }

    accountTypeSelect.addEventListener("change", toggleGstField);
    gstInput.addEventListener("input", function () {
        this.value = this.value.toUpperCase().replace(/[^0-9A-Z]/g, "");
    });
    toggleGstField();

    // Initial address.
    addAddress();

    // Save Customer.
    addCustomerForm.addEventListener("submit", async function (event) {

        event.preventDefault();

        if (!addCustomerForm.checkValidity()) {
            addCustomerForm.reportValidity();
            return;
        }

        const firstName =
            document.getElementById("firstName").value.trim();

        const lastName =
            document.getElementById("lastName").value.trim();

        const mobile =
            document.getElementById("customerMobile").value.trim();

        if (!firstName) {
            document.getElementById("firstName").focus();
            return;
        }

        if (!/^[0-9]{10}$/.test(mobile)) {
            document.getElementById("customerMobile").focus();
            alert("Please enter a valid 10-digit mobile number.");
            return;
        }

        const isBusiness = accountTypeSelect.value === "business";
        const gstin = isBusiness ? gstInput.value.trim().toUpperCase() : "";

        if (gstin && !/^[0-9]{2}[A-Z0-9]{13}$/.test(gstin)) {
            gstInput.focus();
            alert("Please enter a valid 15-character GST number.");
            return;
        }

        const addresses = [];

        addressContainer.querySelectorAll(".ac-address-card")
            .forEach(card => {

                const label = card.querySelector(
                    ".address-label"
                ).value.trim();

                const address = card.querySelector(
                    ".address-value"
                ).value.trim();

                if (label || address) {
                    addresses.push({
                        label: label,
                        address: address
                    });
                }
            });

        const customer = {

            account_type:
                isBusiness ? "Business" : "Individual",

            gstin: gstin,

            business_name: isBusiness ? businessNameInput.value.trim() : "",

            first_name: firstName,

            last_name: lastName,

            name: (firstName + " " + lastName).trim(),

            email:
                document.getElementById("customerEmail").value.trim(),

            mobile: mobile,

            status: activeCheckbox.checked
                ? "active"
                : "inactive",

            newsletter: "no",

            platform: "-",

            store: "-",

            code: "",

            addresses: addresses
        };

        saveButton.disabled = true;

        try {

            // Save to MySQL database.
            await App.api("customers.save", customer);

            const toast = bootstrap.Toast.getOrCreateInstance(
                document.getElementById("successToast")
            );

            toast.show();

            // Return to customer list after successful storage.
            setTimeout(() => { window.location.href = "customers.php"; }, 600);

        } catch (error) {

            alert("Unable to save customer: " + error.message);
            saveButton.disabled = false;
        }

    });

})();
</script>



<?php include 'footer.php'; ?>