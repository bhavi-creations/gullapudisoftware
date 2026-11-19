/*
|--------------------------------------------------------------------------
| TOP BAR: Quick Search (Ctrl+K), notifications bell, ?search= prefill
|--------------------------------------------------------------------------
*/
(function () {
    "use strict";

    const $ = id => document.getElementById(id);
    const esc = v => String(v ?? "").replace(/[&<>"']/g, c => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#039;" }[c]));
    const getJSON = url => fetch(url, { headers: { "X-Requested-With": "fetch" }, credentials: "same-origin" })
        .then(r => r.json());

    /* ================= QUICK SEARCH ================= */
    const box = $("cmdk");
    const input = $("cmdkInput");
    const results = $("cmdkResults");
    const pages = window.APP_PAGES || [];
    let items = [];      // flat list of rendered result links
    let active = 0;
    let timer = null;
    let lastQuery = "";

    function openSearch() {
        box.hidden = false;
        document.body.classList.add("cmdk-open");
        input.value = "";
        render(defaultGroups());
        setTimeout(() => input.focus(), 10);
    }

    function closeSearch() {
        box.hidden = true;
        document.body.classList.remove("cmdk-open");
    }

    function defaultGroups() {
        const pick = ["Dashboard", "New Invoice", "New Quotation", "Invoices", "Customers", "Products", "Payments", "Reports"];
        return [{
            title: "Quick links",
            items: pick.map(t => pages.find(p => p.title === t)).filter(Boolean)
                .map(p => ({ icon: p.icon, title: p.title, sub: p.group, url: p.url }))
        }];
    }

    function pageMatches(q) {
        q = q.toLowerCase();
        return pages.filter(p => p.title.toLowerCase().includes(q))
            .slice(0, 5)
            .map(p => ({ icon: p.icon, title: p.title, sub: "Go to · " + p.group, url: p.url }));
    }

    function highlight(text, q) {
        const safe = esc(text);
        if (!q) return safe;
        const i = text.toLowerCase().indexOf(q.toLowerCase());
        if (i < 0) return safe;
        return esc(text.slice(0, i)) + "<mark>" + esc(text.slice(i, i + q.length)) + "</mark>" + esc(text.slice(i + q.length));
    }

    function render(groups, q = "", note = "") {
        const html = groups.filter(g => g.items.length).map(g => `
            <div class="cmdk-group">${esc(g.title)}</div>
            ${g.items.map(it => `
                <a class="cmdk-item" href="${esc(it.url)}" role="option">
                    <span class="cmdk-icon"><i class="bi bi-${esc(it.icon)}"></i></span>
                    <span class="cmdk-text">
                        <span class="cmdk-title">${highlight(it.title, q)}</span>
                        ${it.sub ? `<span class="cmdk-sub">${esc(it.sub)}</span>` : ""}
                    </span>
                    <i class="bi bi-arrow-return-left cmdk-enter"></i>
                </a>`).join("")}`).join("");
        results.innerHTML = html || `<div class="cmdk-empty"><i class="bi bi-search"></i>${note || "No results for “" + esc(q) + "”"}</div>`;
        items = [...results.querySelectorAll(".cmdk-item")];
        setActive(0);
    }

    function setActive(i) {
        if (!items.length) return;
        active = (i + items.length) % items.length;
        items.forEach((el, n) => el.classList.toggle("active", n === active));
        items[active].scrollIntoView({ block: "nearest" });
    }

    function search(q) {
        lastQuery = q;
        const local = pageMatches(q);
        if (q.length < 2) {
            render(q ? [{ title: "Pages", items: local }] : defaultGroups(), q);
            return;
        }
        render([{ title: "Pages", items: local }], q, "Searching…");
        clearTimeout(timer);
        timer = setTimeout(() => {
            getJSON("topbar-api.php?action=search&q=" + encodeURIComponent(q)).then(data => {
                if (q !== lastQuery) return; // a newer search is running
                render([...(data.groups || []), { title: "Pages", items: local }], q);
            }).catch(() => render([{ title: "Pages", items: local }], q));
        }, 180);
    }

    if (box) {
        $("tbSearchOpen").addEventListener("click", openSearch);
        box.querySelectorAll("[data-cmdk-close]").forEach(el => el.addEventListener("click", closeSearch));
        input.addEventListener("input", () => search(input.value.trim()));
        input.addEventListener("keydown", e => {
            if (e.key === "ArrowDown") { e.preventDefault(); setActive(active + 1); }
            else if (e.key === "ArrowUp") { e.preventDefault(); setActive(active - 1); }
            else if (e.key === "Enter" && items[active]) { e.preventDefault(); window.location.href = items[active].getAttribute("href"); }
        });
        results.addEventListener("mousemove", e => {
            const el = e.target.closest(".cmdk-item");
            if (el) setActive(items.indexOf(el));
        });
        document.addEventListener("keydown", e => {
            const typing = /^(INPUT|TEXTAREA|SELECT)$/.test(document.activeElement?.tagName) || document.activeElement?.isContentEditable;
            if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === "k") {
                e.preventDefault();
                box.hidden ? openSearch() : closeSearch();
            } else if (e.key === "/" && !typing && box.hidden) {
                e.preventDefault();
                openSearch();
            } else if (e.key === "Escape" && !box.hidden) {
                closeSearch();
            }
        });
    }

    /* ================= NOTIFICATIONS ================= */
    const bellCount = $("tbBellCount");
    const alertsBody = $("tbAlertsBody");
    const alertsSummary = $("tbAlertsSummary");

    function loadAlerts() {
        if (!alertsBody) return;
        getJSON("topbar-api.php?action=alerts").then(data => {
            const total = data.total || 0;
            bellCount.textContent = total > 99 ? "99+" : total;
            bellCount.classList.toggle("d-none", total === 0);
            alertsSummary.textContent = total ? total + " need attention" : "All clear";
            if (!total) {
                alertsBody.innerHTML = `<div class="tb-empty"><i class="bi bi-check2-circle"></i>You're all caught up.<small>No low stock, dues or expiring quotations.</small></div>`;
                return;
            }
            alertsBody.innerHTML = data.groups.map(g => `
                <div class="tb-alert-group">
                    <span class="tb-dot ${esc(g.tone)}"></span>${esc(g.title)}
                    <span class="tb-alert-count">${g.count}</span>
                    <a href="${esc(g.more)}" class="tb-alert-more">View all</a>
                </div>
                ${g.items.map(it => `
                    <a class="tb-alert ${esc(g.tone)}" href="${esc(it.url)}">
                        <span class="tb-alert-icon"><i class="bi bi-${esc(it.icon)}"></i></span>
                        <span class="tb-alert-text">
                            <span class="tb-alert-title">${esc(it.title)}</span>
                            <span class="tb-alert-sub">${esc(it.sub)}</span>
                        </span>
                    </a>`).join("")}`).join("");
        }).catch(() => {
            alertsSummary.textContent = "";
            alertsBody.innerHTML = `<div class="tb-empty">Could not load notifications.</div>`;
        });
    }
    loadAlerts();
    // Refresh when the bell is opened (data may have changed on this page).
    $("tbBell")?.addEventListener("show.bs.dropdown", loadAlerts);

    /* ================= ?search= PREFILL ================= */
    // Quick Search opens list pages as page.php?search=..., so filter the page's own search box.
    const term = new URLSearchParams(location.search).get("search");
    if (term) {
        const field = document.querySelector('.sidebar-page-content [class*="-search"] input, .sidebar-page-content input[type="search"], .sidebar-page-content input[id*="earch"]');
        if (field) {
            setTimeout(() => {
                field.value = term;
                ["input", "keyup", "change"].forEach(type => field.dispatchEvent(new Event(type, { bubbles: true })));
            }, 400);
        }
    }
})();
