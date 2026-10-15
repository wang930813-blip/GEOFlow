const currentPage = window.location.pathname.split("/").pop() || "index.html";
const activePage = currentPage === "article" ? "news" : currentPage;
document.querySelectorAll("[data-nav]").forEach((link) => {
  if (link.dataset.nav === activePage) link.setAttribute("aria-current", "page");
});
document.querySelectorAll("[data-current-year]").forEach((node) => {
  node.textContent = String(new Date().getFullYear());
});
const menuButton = document.querySelector("[data-menu-toggle]");
const nav = document.querySelector("[data-site-nav]");
if (menuButton && nav) {
  const closeMenu = () => { nav.classList.remove("is-open"); menuButton.setAttribute("aria-expanded", "false"); };
  menuButton.addEventListener("click", () => { const open = nav.classList.toggle("is-open"); menuButton.setAttribute("aria-expanded", String(open)); });
  nav.addEventListener("click", (event) => { if (event.target instanceof HTMLAnchorElement) closeMenu(); });
  window.addEventListener("keydown", (event) => { if (event.key === "Escape") closeMenu(); });
}
const revealNodes = document.querySelectorAll("[data-reveal]");
if (window.matchMedia?.("(prefers-reduced-motion: reduce)")?.matches) revealNodes.forEach((node) => node.classList.add("is-visible"));
else if ("IntersectionObserver" in window) { const observer = new IntersectionObserver((entries, currentObserver) => { entries.forEach((entry) => { if (!entry.isIntersecting) return; entry.target.classList.add("is-visible"); currentObserver.unobserve(entry.target); }); }, { threshold: 0.12 }); revealNodes.forEach((node) => observer.observe(node)); }
else revealNodes.forEach((node) => node.classList.add("is-visible"));

