/*
|--------------------------------------------------------------------------
| GULLAPUDI - SHARED FRONTEND HELPERS
|--------------------------------------------------------------------------
| App.api(action, data)   -> POST JSON to api.php, returns result
| App.post(url, formData) -> POST FormData (with CSRF) to any PHP page
| App.toast(message, type)
|--------------------------------------------------------------------------
*/
(function () {
    "use strict";

    function csrf() {
        const meta = document.querySelector('meta[name="csrf-token"]');
        return meta ? meta.content : "";
    }

    async function parse(response) {
        let result;
        try {
            result = await response.json();
        } catch (e) {
            throw new Error("Server returned an invalid response.");
        }
        if (response.status === 401) {
            window.location.href = "login.php";
        }
        if (!response.ok || !result.success) {
            throw new Error(result.message || "Request failed.");
        }
        return result;
    }

    async function api(action, data = {}) {
        const response = await fetch("api.php?action=" + encodeURIComponent(action), {
            method: "POST",
            credentials: "same-origin",
            headers: {
                "Content-Type": "application/json",
                "X-CSRF-Token": csrf(),
                "X-Requested-With": "fetch"
            },
            body: JSON.stringify(data)
        });
        return parse(response);
    }

    async function post(url, formData) {
        if (!(formData instanceof FormData)) {
            const fd = new FormData();
            Object.entries(formData || {}).forEach(([k, v]) => fd.append(k, v));
            formData = fd;
        }
        if (!formData.has("csrf_token")) formData.append("csrf_token", csrf());
        const response = await fetch(url, {
            method: "POST",
            credentials: "same-origin",
            headers: { "X-Requested-With": "fetch" },
            body: formData
        });
        return parse(response);
    }

    async function upload(file) {
        const fd = new FormData();
        fd.append("file", file);
        fd.append("csrf_token", csrf());
        const response = await fetch("api.php?action=upload", {
            method: "POST",
            credentials: "same-origin",
            headers: { "X-Requested-With": "fetch" },
            body: fd
        });
        return parse(response);
    }

    function toast(message, type = "success") {
        let holder = document.getElementById("appToastHolder");
        if (!holder) {
            holder = document.createElement("div");
            holder.id = "appToastHolder";
            holder.className = "toast-container position-fixed bottom-0 end-0 p-3";
            holder.style.zIndex = 2000;
            document.body.appendChild(holder);
        }
        const el = document.createElement("div");
        el.className = "toast align-items-center border-0 text-white bg-" +
            (type === "error" ? "danger" : type);
        el.setAttribute("role", "alert");
        el.innerHTML = '<div class="d-flex"><div class="toast-body"></div>' +
            '<button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button></div>';
        el.querySelector(".toast-body").textContent = message;
        holder.appendChild(el);
        const t = new bootstrap.Toast(el, { delay: 3500 });
        el.addEventListener("hidden.bs.toast", () => el.remove());
        t.show();
    }

    /*
    | App.details({...}) -> right-side "details" panel used by every eye (view) icon.
    |   title:    "Category Details"
    |   icon:     bootstrap icon name, e.g. "folder2-open"
    |   image:    optional image URL (shown instead of the icon)
    |   name:     main heading            subtitle: small grey line
    |   badge:    { text: "Enabled", tone: "green|red|orange|blue|grey" }
    |   rows:     [["Label", "Value"], ...]   (values are escaped)
    |   html:     optional extra HTML appended under the rows (caller escapes)
    |   actions:  [{ label, icon, tone: "primary|danger|neutral", onClick, href }]
    */
    function esc(v) {
        return String(v ?? "").replace(/[&<>"']/g, c =>
            ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c]));
    }

    let detailsPanel = null;

    function details(opts) {
        if (!detailsPanel) {
            const el = document.createElement("div");
            el.className = "offcanvas offcanvas-end app-details";
            el.tabIndex = -1;
            el.id = "appDetailsPanel";
            el.setAttribute("aria-labelledby", "appDetailsTitle");
            el.innerHTML =
                '<div class="app-details-head">' +
                '<h5 class="app-details-title" id="appDetailsTitle"></h5>' +
                '<button type="button" class="app-details-close" data-bs-dismiss="offcanvas" aria-label="Close">' +
                '<i class="bi bi-x-lg"></i></button></div>' +
                '<div class="app-details-body"></div>';
            document.body.appendChild(el);
            detailsPanel = { el, oc: new bootstrap.Offcanvas(el) };
        }

        const o = opts || {};
        const tone = (o.badge && o.badge.tone) || "green";
        const media = o.image
            ? `<img src="${esc(o.image)}" alt="">`
            : `<i class="bi bi-${esc(o.icon || "info-circle")}"></i>`;

        const rows = (o.rows || []).map(([label, value]) => `
            <div class="app-details-row">
                <span>${esc(label)}</span>
                <strong>${esc(value === null || value === undefined || value === "" ? "-" : value)}</strong>
            </div>`).join("");

        const actions = (o.actions || []).map((a, i) => {
            const cls = "app-details-btn " + (a.tone || "primary");
            const inner = (a.icon ? `<i class="bi bi-${esc(a.icon)}"></i>` : "") + esc(a.label);
            return a.href
                ? `<a class="${cls}" href="${esc(a.href)}"${a.newTab ? ' target="_blank" rel="noopener"' : ""}>${inner}</a>`
                : `<button type="button" class="${cls}" data-i="${i}">${inner}</button>`;
        }).join("");

        detailsPanel.el.querySelector(".app-details-title").textContent = o.title || "Details";
        const body = detailsPanel.el.querySelector(".app-details-body");
        body.innerHTML = `
            <div class="app-details-hero">
                <div class="app-details-media">${media}</div>
                <div class="app-details-hero-text">
                    <div class="app-details-name">${esc(o.name || "")}</div>
                    ${o.subtitle ? `<div class="app-details-sub">${esc(o.subtitle)}</div>` : ""}
                    ${o.badge ? `<span class="app-details-badge ${esc(tone)}">${esc(o.badge.text)}</span>` : ""}
                </div>
            </div>
            <div class="app-details-rows">${rows}</div>
            ${o.html ? `<div class="app-details-extra">${o.html}</div>` : ""}
            ${actions ? `<div class="app-details-actions">${actions}</div>` : ""}`;

        body.querySelectorAll("button[data-i]").forEach(btn => {
            btn.addEventListener("click", () => {
                const a = o.actions[Number(btn.dataset.i)];
                if (a.close !== false) detailsPanel.oc.hide();
                if (a.onClick) a.onClick();
            });
        });

        detailsPanel.oc.show();
        return body;
    }

    function hideDetails() {
        if (detailsPanel) detailsPanel.oc.hide();
    }

    window.App = { api, post, upload, toast, csrf, details, hideDetails, esc };

    // Show a toast after redirects like page.php?saved=1 / ?added=1
    document.addEventListener("DOMContentLoaded", function () {
        const params = new URLSearchParams(window.location.search);
        const saved = params.get("saved") || params.get("added");
        if (saved && !document.querySelector(".sidebar-page-content .alert-success")) {
            toast(saved === "1" ? "Saved successfully." : saved + " saved successfully.");
        }
    });
})();
