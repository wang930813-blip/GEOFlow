/**
 * Created by Codex.
 * @Date: 2026-10-22
 * @Time: 17:05:42
 * @Author: cdkay
 * @Email: network@iyuanma.net
 *
 * @File： theme.js
 * @Description: 提供响应式导航、页头状态与无请求的滚动显现增强。
 */

const header = document.querySelector(".site-header");
const menuToggle = document.querySelector("[data-menu-toggle]");
const siteNav = document.querySelector("[data-site-nav]");

/**
 * @Name: closeMenu
 * @Description: 关闭移动端导航，并在键盘退出时将焦点还给菜单控制按钮。
 *
 * @Author: cdkay
 * @CreateTime: 2026-10-22 17:05:42
 * @UpdateTime: 2026-10-22 17:05:42
 *
 * @Param: boolean restoreFocus 是否将键盘焦点还给菜单按钮
 * @Return void 更新导航状态
 */
function closeMenu(restoreFocus = false) {
  header?.classList.remove("nav-open");
  menuToggle?.setAttribute("aria-expanded", "false");
  menuToggle?.setAttribute("aria-label", "打开导航");
  if (restoreFocus) menuToggle?.focus();
}

menuToggle?.addEventListener("click", () => {
  const isOpen = header?.classList.toggle("nav-open") ?? false;
  menuToggle.setAttribute("aria-expanded", String(isOpen));
  menuToggle.setAttribute("aria-label", isOpen ? "关闭导航" : "打开导航");
});

siteNav?.querySelectorAll("a").forEach((link) => {
  link.addEventListener("click", () => closeMenu());
});

document.addEventListener("keydown", (event) => {
  if (event.key === "Escape" && header?.classList.contains("nav-open")) closeMenu(true);
});

const updateHeader = () => header?.classList.toggle("is-scrolled", window.scrollY > 12);
updateHeader();
window.addEventListener("scroll", updateHeader, { passive: true });

const motionPreference = window.matchMedia?.("(prefers-reduced-motion: reduce)");
const revealNodes = document.querySelectorAll(".reveal:not(.is-visible)");
if (motionPreference?.matches || !("IntersectionObserver" in window)) {
  revealNodes.forEach((node) => node.classList.add("is-visible"));
} else {
  const revealObserver = new IntersectionObserver((entries) => {
    entries.forEach((entry) => {
      if (!entry.isIntersecting) return;
      entry.target.classList.add("is-visible");
      revealObserver.unobserve(entry.target);
    });
  }, { threshold: 0.14 });
  revealNodes.forEach((node) => revealObserver.observe(node));
}
document.documentElement.classList.add("site-ready");
