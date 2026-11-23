<?php $pageTitle = 'Customers'; include 'header.php'; ?>



<section class="customers-section">
<div class="container-fluid p-0">

  <!-- PAGE HEADER -->
  <div class="row align-items-center g-3 mb-3">
    <div class="col-md-7">
      <h1 class="cs-title">Customers</h1>
      <p class="cs-subtitle">
        Manage all your customers and their details
      </p>
    </div>

    <div class="col-md-5">
      <div class="d-flex justify-content-md-end gap-2">
        <!-- <button class="btn cs-btn cs-btn-primary"
                data-bs-toggle="modal"
                data-bs-target="#customerModal"
                id="addCustomerBtn">
          <i class="bi bi-plus-lg me-1"></i>
          Add Customer
        </button> -->

        <a href="add-customer.php"
   class="btn cs-btn cs-btn-primary">
  <i class="bi bi-plus-lg me-1"></i>
  Add Customer
</a>

        <button class="btn cs-btn btn-light border"
                id="importCustomerBtn">
          <i class="bi bi-funnel-fill me-1"></i>
          Import
        </button>
        <input type="file" id="csvFileInput"
               accept=".csv,text/csv" hidden>
      </div>
    </div>
  </div>

  <!-- CUSTOMER STATISTICS -->
  <div class="row g-2 mb-2">

    <div class="col-12 col-sm-6 col-xl-3">
      <div class="cs-card cs-stat">
        <div class="cs-stat-icon cs-blue">
          <i class="bi bi-people-fill"></i>
        </div>
        <div>
          <div class="cs-stat-label">Total Customers</div>
          <div class="cs-stat-value" id="totalCount">2</div>
          <div class="cs-stat-note cs-note-green">
            All customer records
          </div>
        </div>
      </div>
    </div>

    <div class="col-12 col-sm-6 col-xl-3">
      <div class="cs-card cs-stat">
        <div class="cs-stat-icon cs-green">
          <i class="bi bi-check-circle-fill"></i>
        </div>
        <div>
          <div class="cs-stat-label">Active Customers</div>
          <div class="cs-stat-value" id="activeCount">2</div>
          <div class="cs-stat-note">Currently enabled</div>
        </div>
      </div>
    </div>

    <div class="col-12 col-sm-6 col-xl-3">
      <div class="cs-card cs-stat">
        <div class="cs-stat-icon cs-orange">
          <i class="bi bi-slash-circle"></i>
        </div>
        <div>
          <div class="cs-stat-label">Inactive Customers</div>
          <div class="cs-stat-value" id="inactiveCount">0</div>
          <div class="cs-stat-note cs-note-orange">
            Currently disabled
          </div>
        </div>
      </div>
    </div>

    <div class="col-12 col-sm-6 col-xl-3">
      <div class="cs-card cs-stat">
        <div class="cs-stat-icon cs-purple">
          <i class="bi bi-envelope"></i>
        </div>
        <div>
          <div class="cs-stat-label">Newsletter Opt-ins</div>
          <div class="cs-stat-value" id="newsletterCount">0</div>
          <div class="cs-stat-note">Subscribed to updates</div>
        </div>
      </div>
    </div>

  </div>

  <!-- CUSTOMER LIST PANEL -->
  <div class="cs-card cs-panel">

    <!-- TABS -->
    <div class="cs-tabs">
      <button class="cs-tab active" data-tab="all">
        All Customers (<span id="allTabCount">2</span>)
      </button>
      <button class="cs-tab" data-tab="active">
        Active (<span id="activeTabCount">2</span>)
      </button>
      <button class="cs-tab" data-tab="inactive">
        Inactive (<span id="inactiveTabCount">0</span>)
      </button>
    </div>

    <!-- SEARCH + FILTER -->
    <div class="cs-search-row">
      <div class="cs-search-box">
        <i class="bi bi-search"></i>
        <input type="search" id="customerSearch"
               placeholder="Search by name, mobile or code...">
      </div>

      <div class="dropdown">
        <button class="cs-outline-btn dropdown-toggle"
                data-bs-toggle="dropdown">
          <i class="bi bi-funnel-fill me-1"></i>
          Filter
        </button>
        <div class="dropdown-menu dropdown-menu-end p-3"
             style="min-width:220px">
          <label class="cs-form-label">Status</label>
          <select class="form-select mb-3" id="statusFilter">
            <option value="all">All</option>
            <option value="active">Active</option>
            <option value="inactive">Inactive</option>
          </select>
          <button class="btn btn-outline-secondary w-100 btn-sm"
                  id="resetFilterBtn">
            Reset Filters
          </button>
        </div>
      </div>

      <button class="cs-outline-btn"
              id="refreshCustomerBtn"
              title="Refresh">
        <i class="bi bi-sliders2"></i>
      </button>
    </div>

    <!-- TABLE -->
    <div class="table-responsive">
      <table class="table cs-table">
        <thead>
          <tr>
            <th class="text-center">S No</th>
            <th>Customer</th>
            <th>Mobile</th>
            <th>Email</th>
            <th>Platform</th>
            <th>Store ID</th>
            <th>Status</th>
            <th>Action</th>
          </tr>
        </thead>

        <tbody id="customerTableBody"></tbody>
      </table>
    </div>

    <!-- FOOTER + PAGINATION -->
    <div class="cs-footer">
      <span id="paginationInfo">
        Showing 1 to 2 of 2 entries Page 1 of 1
      </span>

      <div class="d-flex gap-2 align-items-center">
        <button class="cs-page-btn" id="firstPage">&lt;&lt;</button>
        <button class="cs-page-btn" id="prevPage">&lt;</button>
        <button class="cs-page-btn active" id="currentPage">1</button>
        <button class="cs-page-btn" id="nextPage">&gt;</button>
        <button class="cs-page-btn" id="lastPage">&gt;&gt;</button>

        <select class="cs-page-size" id="pageSize">
          <option value="10">10 / page</option>
          <option value="20" selected>20 / page</option>
          <option value="50">50 / page</option>
          <option value="100">100 / page</option>
        </select>
      </div>
    </div>

  </div>
</div>
</section>

<!-- ADD / EDIT CUSTOMER MODAL -->
<div class="modal fade" id="customerModal" tabindex="-1">
  <div class="modal-dialog modal-lg modal-dialog-centered">
    <div class="modal-content">

      <div class="modal-header">
        <h5 class="modal-title" id="customerModalTitle">
          Add Customer
        </h5>
        <button class="btn-close"
                data-bs-dismiss="modal"></button>
      </div>

      <form id="customerForm">
        <div class="modal-body">
          <input type="hidden" id="editCustomerId">

          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label">Customer Name *</label>
              <input class="form-control" id="customerName"
                     required maxlength="100">
            </div>

            <div class="col-md-6">
              <label class="form-label">Mobile *</label>
              <input class="form-control" id="customerMobile"
                     inputmode="numeric"
                     pattern="[0-9]{10}" maxlength="10" required>
            </div>

            <div class="col-md-6">
              <label class="form-label">Email</label>
              <input type="email" class="form-control"
                     id="customerEmail">
            </div>

            <div class="col-md-6">
              <label class="form-label">Customer Code</label>
              <input class="form-control" id="customerCode">
            </div>

            <div class="col-md-6">
              <label class="form-label">Platform</label>
              <input class="form-control" id="customerPlatform">
            </div>

            <div class="col-md-6">
              <label class="form-label">Store ID</label>
              <input class="form-control" id="customerStore">
            </div>

            <div class="col-md-6">
              <label class="form-label">Status</label>
              <select class="form-select" id="customerStatus">
                <option value="active">Active</option>
                <option value="inactive">Inactive</option>
              </select>
            </div>

            <div class="col-md-6">
              <label class="form-label">Newsletter</label>
              <select class="form-select" id="customerNewsletter">
                <option value="no">Not Subscribed</option>
                <option value="yes">Subscribed</option>
              </select>
            </div>
          </div>
        </div>

        <div class="modal-footer">
          <button type="button" class="btn btn-light border"
                  data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary">
            Save Customer
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- VIEW CUSTOMER MODAL -->
<div class="modal fade" id="viewCustomerModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Customer Details</h5>
        <button class="btn-close"
                data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body" id="viewCustomerDetails"></div>
    </div>
  </div>
</div>


<script>
(function () {
  "use strict";

  // Customers are loaded from the MySQL database (api.php).
  let customers = [];

  let activeTab = "all";
  let currentPage = 1;
  let perPage = 20;

  async function loadCustomers() {
    try {
      const result = await App.api("customers.list");
      customers = result.customers;
      renderCustomers();
    } catch (error) {
      App.toast(error.message, "error");
    }
  }

  const $ = id => document.getElementById(id);

  const customerModal = new bootstrap.Modal($("customerModal"));
  const viewModal = new bootstrap.Modal($("viewCustomerModal"));

  const escapeHTML = value => String(value ?? "")
    .replace(/&/g, "&amp;")
    .replace(/</g, "&lt;")
    .replace(/>/g, "&gt;")
    .replace(/"/g, "&quot;")
    .replace(/'/g, "&#39;");

  function getFilteredCustomers() {
    const search = $("customerSearch").value.trim().toLowerCase();
    const statusFilter = $("statusFilter").value;

    return customers.filter(customer => {
      if (activeTab !== "all" && customer.status !== activeTab) {
        return false;
      }

      if (statusFilter !== "all" && customer.status !== statusFilter) {
        return false;
      }

      const searchable = [
        customer.name,
        customer.mobile,
        customer.email,
        customer.code,
        customer.platform,
        customer.store
      ].join(" ").toLowerCase();

      return searchable.includes(search);
    });
  }

  function updateCounts() {
    const total = customers.length;
    const active = customers.filter(
      customer => customer.status === "active"
    ).length;
    const inactive = total - active;
    const newsletter = customers.filter(
      customer => customer.newsletter === "yes"
    ).length;

    $("totalCount").textContent = total;
    $("activeCount").textContent = active;
    $("inactiveCount").textContent = inactive;
    $("newsletterCount").textContent = newsletter;

    $("allTabCount").textContent = total;
    $("activeTabCount").textContent = active;
    $("inactiveTabCount").textContent = inactive;
  }

  function renderCustomers() {
    updateCounts();

    const filtered = getFilteredCustomers();
    const totalPages = Math.max(
      1, Math.ceil(filtered.length / perPage)
    );

    currentPage = Math.max(
      1, Math.min(currentPage, totalPages)
    );

    const start = (currentPage - 1) * perPage;
    const pageCustomers = filtered.slice(start, start + perPage);

    const tbody = $("customerTableBody");
    tbody.innerHTML = "";

    if (!pageCustomers.length) {
      tbody.innerHTML = `
        <tr>
          <td colspan="8" class="text-center py-5 text-muted">
            No customers found.
          </td>
        </tr>
      `;
    } else {
      pageCustomers.forEach((customer, index) => {
        const tr = document.createElement("tr");
        const statusClass = customer.status === "active"
          ? ""
          : "inactive";

        tr.innerHTML = `
          <td class="text-center">${start + index + 1}</td>
          <td>${escapeHTML(customer.name)}</td>
          <td>${escapeHTML(customer.mobile)}</td>
          <td>${escapeHTML(customer.email || "-")}</td>
          <td>${escapeHTML(customer.platform || "-")}</td>
          <td>${escapeHTML(customer.store || "-")}</td>
          <td>
            <span class="cs-status ${statusClass}">
              ${escapeHTML(
                customer.status === "active" ? "Active" : "Inactive"
              )}
            </span>
          </td>
          <td>
            <div class="cs-actions">
              <button class="cs-action" data-action="view"
                      data-id="${customer.id}" title="View">
                <i class="bi bi-eye"></i>
              </button>
              <button class="cs-action" data-action="edit"
                      data-id="${customer.id}" title="Edit">
                <i class="bi bi-pencil-square"></i>
              </button>
              <button class="cs-action delete" data-action="delete"
                      data-id="${customer.id}" title="Delete">
                <i class="bi bi-trash3"></i>
              </button>
              <button class="cs-action more" data-action="toggle"
                      data-id="${customer.id}"
                      title="Toggle Status">
                <i class="bi bi-three-dots"></i>
              </button>
            </div>
          </td>
        `;

        tbody.appendChild(tr);
      });
    }

    const from = filtered.length ? start + 1 : 0;
    const to = Math.min(start + perPage, filtered.length);

    $("paginationInfo").textContent =
      `Showing ${from} to ${to} of ${filtered.length} ` +
      `entries Page ${currentPage} of ${totalPages}`;

    $("currentPage").textContent = currentPage;
    $("firstPage").disabled = currentPage === 1;
    $("prevPage").disabled = currentPage === 1;
    $("nextPage").disabled = currentPage === totalPages;
    $("lastPage").disabled = currentPage === totalPages;
  }

  function resetForm() {
    $("customerForm").reset();
    $("editCustomerId").value = "";
    $("customerModalTitle").textContent = "Add Customer";
  }

  function editCustomer(id) {
    const customer = customers.find(c => c.id === id);
    if (!customer) return;

    $("editCustomerId").value = customer.id;
    $("customerName").value = customer.name;
    $("customerMobile").value = customer.mobile;
    $("customerEmail").value = customer.email;
    $("customerCode").value = customer.code;
    $("customerPlatform").value =
      customer.platform === "-" ? "" : customer.platform;
    $("customerStore").value =
      customer.store === "-" ? "" : customer.store;
    $("customerStatus").value = customer.status;
    $("customerNewsletter").value = customer.newsletter;

    $("customerModalTitle").textContent = "Edit Customer";
    customerModal.show();
  }

  function viewCustomer(id) {
    const customer = customers.find(c => c.id === id);
    if (!customer) return;

    const active = customer.status === "active";
    const address = (customer.addresses || [])
      .map(a => (a.label ? a.label + ": " : "") + (a.address || ""))
      .filter(Boolean)
      .join(" | ");

    App.details({
      title: "Customer Details",
      icon: "person-fill",
      name: customer.name,
      subtitle: customer.mobile || customer.code || "",
      badge: active ? { text: "Active", tone: "green" } : { text: "Inactive", tone: "red" },
      rows: [
        ["Customer Name", customer.name],
        ["Account Type", customer.account_type],
        ["Business Name", customer.business_name],
        ["Mobile", customer.mobile],
        ["Email", customer.email],
        ["GSTIN", customer.gstin],
        ["Customer Code", customer.code],
        ["Platform", customer.platform],
        ["Store ID", customer.store],
        ["Address", address],
        ["Newsletter", customer.newsletter === "yes" ? "Yes" : "No"]
      ],
      actions: [
        { label: "Edit Customer", icon: "pencil-fill", tone: "primary", onClick: () => editCustomer(id) },
        { label: "Delete Customer", icon: "trash3", tone: "danger", onClick: () => deleteCustomer(id) }
      ]
    });
  }

  async function deleteCustomer(id) {
    if (!confirm("Are you sure you want to delete this customer?")) return;
    try {
      customers = (await App.api("customers.delete", { id })).customers;
      renderCustomers();
      App.toast("Customer deleted.");
    } catch (error) {
      App.toast(error.message, "error");
    }
  }

  if ($("addCustomerBtn")) {
    $("addCustomerBtn").addEventListener("click", resetForm);
  }

  $("customerForm").addEventListener("submit", async function (event) {
    event.preventDefault();

    if (!this.reportValidity()) return;

    const editId = Number($("editCustomerId").value);

    const customerData = {
      id: editId || 0,
      name: $("customerName").value.trim(),
      mobile: $("customerMobile").value.trim(),
      email: $("customerEmail").value.trim(),
      code: $("customerCode").value.trim(),
      platform: $("customerPlatform").value.trim() || "-",
      store: $("customerStore").value.trim() || "-",
      status: $("customerStatus").value,
      newsletter: $("customerNewsletter").value
    };

    try {
      const result = await App.api("customers.save", customerData);
      customers = result.customers;
      customerModal.hide();
      renderCustomers();
      App.toast(editId ? "Customer updated." : "Customer added.");
    } catch (error) {
      App.toast(error.message, "error");
    }
  });

  $("customerTableBody").addEventListener("click", async function (event) {
    const button = event.target.closest("[data-action]");
    if (!button) return;

    const id = Number(button.dataset.id);
    const action = button.dataset.action;

    if (action === "view") viewCustomer(id);
    if (action === "edit") editCustomer(id);

    if (action === "delete") deleteCustomer(id);

    try {
      if (action === "toggle") {
        customers = (await App.api("customers.toggle", { id })).customers;
        renderCustomers();
      }
    } catch (error) {
      App.toast(error.message, "error");
    }
  });

  document.querySelectorAll(".customers-section .cs-tab")
    .forEach(tab => {
      tab.addEventListener("click", function () {
        document.querySelectorAll(".customers-section .cs-tab")
          .forEach(item => item.classList.remove("active"));

        this.classList.add("active");
        activeTab = this.dataset.tab;
        currentPage = 1;
        renderCustomers();
      });
    });

  $("customerSearch").addEventListener("input", () => {
    currentPage = 1;
    renderCustomers();
  });

  $("statusFilter").addEventListener("change", () => {
    currentPage = 1;
    renderCustomers();
  });

  $("resetFilterBtn").addEventListener("click", () => {
    $("customerSearch").value = "";
    $("statusFilter").value = "all";
    activeTab = "all";
    currentPage = 1;

    document.querySelectorAll(".customers-section .cs-tab")
      .forEach(tab => {
        tab.classList.toggle(
          "active",
          tab.dataset.tab === "all"
        );
      });

    renderCustomers();
  });

  $("refreshCustomerBtn").addEventListener(
    "click", loadCustomers
  );

  $("pageSize").addEventListener("change", function () {
    perPage = Number(this.value);
    currentPage = 1;
    renderCustomers();
  });

  $("firstPage").addEventListener("click", () => {
    currentPage = 1;
    renderCustomers();
  });

  $("prevPage").addEventListener("click", () => {
    currentPage--;
    renderCustomers();
  });

  $("nextPage").addEventListener("click", () => {
    currentPage++;
    renderCustomers();
  });

  $("lastPage").addEventListener("click", () => {
    currentPage = Math.max(
      1, Math.ceil(getFilteredCustomers().length / perPage)
    );
    renderCustomers();
  });

  // Basic CSV importer with support for quoted fields.
  // Headers: name,mobile,email,code,platform,store,status,newsletter
  function parseCSV(text) {
    const rows = [];
    let row = [];
    let cell = "";
    let quoted = false;

    text = text.replace(/^\uFEFF/, "");

    for (let i = 0; i < text.length; i++) {
      const char = text[i];

      if (char === '"') {
        if (quoted && text[i + 1] === '"') {
          cell += '"';
          i++;
        } else {
          quoted = !quoted;
        }
      } else if (char === "," && !quoted) {
        row.push(cell);
        cell = "";
      } else if ((char === "\n" || char === "\r") && !quoted) {
        if (char === "\r" && text[i + 1] === "\n") i++;

        row.push(cell);
        if (row.some(value => value.trim())) rows.push(row);
        row = [];
        cell = "";
      } else {
        cell += char;
      }
    }

    if (quoted) throw new Error("Invalid CSV quoting.");

    row.push(cell);
    if (row.some(value => value.trim())) rows.push(row);

    return rows;
  }

  $("importCustomerBtn").addEventListener(
    "click",
    () => $("csvFileInput").click()
  );

  $("csvFileInput").addEventListener("change", async function () {
    const file = this.files[0];
    if (!file) return;

    try {
      const rows = parseCSV(await file.text());
      if (rows.length < 2) {
        alert("CSV file has no customer records.");
        return;
      }

      const headers = rows[0].map(
        heading => heading.trim().toLowerCase()
      );

      const getValue = (row, field) => {
        const index = headers.indexOf(field);
        return index < 0 ? "" : (row[index] || "").trim();
      };

      if (!headers.includes("name") || !headers.includes("mobile")) {
        alert("CSV must include name and mobile columns.");
        return;
      }

      const importRows = [];

      for (const row of rows.slice(1)) {
        const name = getValue(row, "name");
        const mobile = getValue(row, "mobile");

        if (!name || !/^[0-9]{10}$/.test(mobile)) continue;

        const status = getValue(row, "status").toLowerCase();
        const newsletter = getValue(row, "newsletter").toLowerCase();

        importRows.push({
          name,
          mobile,
          email: getValue(row, "email"),
          code: getValue(row, "code"),
          platform: getValue(row, "platform") || "-",
          store: getValue(row, "store") || "-",
          status: status === "inactive" ? "inactive" : "active",
          newsletter: ["yes", "true", "1"].includes(newsletter)
            ? "yes"
            : "no"
        });
      }

      const result = await App.api("customers.import", { customers: importRows });
      customers = result.customers;
      currentPage = 1;
      renderCustomers();
      alert(`${result.imported} customers imported successfully.`);
    } catch (error) {
      alert("Unable to import CSV: " + error.message);
    } finally {
      this.value = "";
    }
  });

  renderCustomers();
  loadCustomers();
})();
</script>





<?php include 'footer.php'; ?>