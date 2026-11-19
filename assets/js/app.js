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
            holder.className = "toast-container position-fixed top-0 end-0 p-3";
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

    window.App = { api, post, upload, toast, csrf };

    // Show a toast after redirects like page.php?saved=1 / ?added=1
    document.addEventListener("DOMContentLoaded", function () {
        const params = new URLSearchParams(window.location.search);
        const saved = params.get("saved") || params.get("added");
        if (saved && !document.querySelector(".sidebar-page-content .alert-success")) {
            toast(saved === "1" ? "Saved successfully." : saved + " saved successfully.");
        }
    });
})();
