document.querySelectorAll("[data-current-year]").forEach((node) => {
  node.textContent = String(new Date().getFullYear());
});
const toggle = document.querySelector("[data-menu-toggle]");
const nav = document.querySelector("[data-site-nav]");
toggle?.addEventListener("click", () => {
  const open = toggle.getAttribute("aria-expanded") !== "true";
  toggle.setAttribute("aria-expanded", String(open));
  nav?.classList.toggle("is-open", open);
});
document.addEventListener("keydown", (event) => {
  if (event.key !== "Escape" || !nav?.classList.contains("is-open")) return;
  toggle?.setAttribute("aria-expanded", "false");
  nav.classList.remove("is-open");
  toggle?.focus();
});
