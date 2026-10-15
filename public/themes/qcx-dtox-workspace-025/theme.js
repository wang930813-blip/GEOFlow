const path = window.location.pathname.replace(/\/+$/, "") || "/";
const activePage = path === "/" ? "home" : path.includes("/about") ? "about" : path.includes("/products") ? "products" : path.includes("/contact") ? "contact" : "news";
document.querySelectorAll("[data-nav]").forEach((link) => { if (link.dataset.nav === activePage) link.setAttribute("aria-current", "page"); });
