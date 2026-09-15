const root = document.documentElement;
const body = document.body;
root.classList.add("js");

const prefersReducedMotion = window.matchMedia?.("(prefers-reduced-motion: reduce)");
const isReducedMotion = () => Boolean(prefersReducedMotion?.matches);

const themeToggle = document.querySelector("[data-theme-toggle]");
const themeStorageKey = "site-template-theme";

function setTheme(theme) {
  root.dataset.theme = theme;
  try {
    window.localStorage.setItem(themeStorageKey, theme);
  } catch {
    // Storage is optional for the visual preference.
  }
  if (themeToggle) {
    const nextTheme = theme === "light" ? "深色" : "浅色";
    themeToggle.setAttribute("aria-label", `切换到${nextTheme}主题`);
    themeToggle.setAttribute("title", `切换到${nextTheme}主题`);
  }
}

function getInitialTheme() {
  try {
    const savedTheme = window.localStorage.getItem(themeStorageKey);
    if (savedTheme === "light" || savedTheme === "dark") return savedTheme;
  } catch {
    // Use the dark template language when storage is unavailable.
  }
  return "dark";
}

setTheme(getInitialTheme());
themeToggle?.addEventListener("click", () => setTheme(root.dataset.theme === "light" ? "dark" : "light"));

const menuToggle = document.querySelector("[data-menu-toggle]");
const menuClose = document.querySelector("[data-menu-close]");
const mobileMenu = document.querySelector("[data-mobile-menu]");
const menuBackdrop = document.querySelector("[data-menu-backdrop]");
let lastMenuTrigger = null;

function setMenu(open) {
  if (!menuToggle || !mobileMenu) return;
  body.classList.toggle("menu-is-open", open);
  body.classList.toggle("drawer-is-open", open);
  menuToggle.setAttribute("aria-expanded", String(open));
  menuToggle.setAttribute("aria-label", open ? "关闭菜单" : "打开菜单");
  mobileMenu.setAttribute("aria-hidden", String(!open));
  menuBackdrop?.setAttribute("aria-hidden", String(!open));
  if (open) {
    lastMenuTrigger = document.activeElement;
    window.setTimeout(() => mobileMenu.focus(), 20);
  } else if (lastMenuTrigger instanceof HTMLElement) {
    lastMenuTrigger.focus();
  }
}

setMenu(false);
menuToggle?.addEventListener("click", () => setMenu(menuToggle.getAttribute("aria-expanded") !== "true"));
menuClose?.addEventListener("click", () => setMenu(false));
menuBackdrop?.addEventListener("click", () => setMenu(false));
mobileMenu?.querySelectorAll("a").forEach((link) => link.addEventListener("click", () => setMenu(false)));

document.addEventListener("keydown", (event) => {
  if (event.key === "Tab" && body.classList.contains("menu-is-open") && mobileMenu) {
    const focusable = [...mobileMenu.querySelectorAll("a[href], button:not([disabled]), [tabindex]:not([tabindex='-1'])")];
    const first = focusable[0];
    const last = focusable.at(-1);
    if (event.shiftKey && document.activeElement === first) {
      event.preventDefault();
      last?.focus();
    } else if (!event.shiftKey && document.activeElement === last) {
      event.preventDefault();
      first?.focus();
    }
  }
  if (event.key === "Escape") {
    setMenu(false);
    document.querySelectorAll("[data-cursor].is-active").forEach((cursor) => cursor.classList.remove("is-active"));
  }
});

const revealItems = document.querySelectorAll(".reveal");
if (isReducedMotion() || !("IntersectionObserver" in window)) {
  revealItems.forEach((item) => item.classList.add("is-visible"));
} else {
  const revealObserver = new IntersectionObserver((entries, observer) => {
    entries.forEach((entry) => {
      if (!entry.isIntersecting) return;
      entry.target.classList.add("is-visible");
      observer.unobserve(entry.target);
    });
  }, { rootMargin: "0px 0px -8% 0px", threshold: 0.12 });
  revealItems.forEach((item) => revealObserver.observe(item));
}

const header = document.querySelector("[data-header]");
const hero = document.querySelector("[data-hero]");
if (header && hero && "IntersectionObserver" in window) {
  const headerObserver = new IntersectionObserver(([entry]) => header.classList.toggle("is-scrolled", !entry.isIntersecting), { threshold: 0.08 });
  headerObserver.observe(hero);
}

document.querySelectorAll("a[href^='#']").forEach((link) => {
  link.addEventListener("click", (event) => {
    const targetId = link.getAttribute("href");
    if (!targetId || targetId === "#") return;
    const target = document.querySelector(targetId);
    if (!target) return;
    event.preventDefault();
    target.scrollIntoView({ behavior: isReducedMotion() ? "auto" : "smooth", block: "start" });
    window.history.replaceState(null, "", targetId);
  });
});

function splitHeadline(element) {
  if (element.dataset.splitReady) return;
  const text = element.textContent?.trim() ?? "";
  element.textContent = "";
  element.setAttribute("aria-label", text);
  [...text].forEach((character, index) => {
    const span = document.createElement("span");
    span.className = "headline-char";
    span.setAttribute("aria-hidden", "true");
    span.textContent = character === " " ? "\u00a0" : character;
    span.style.setProperty("--char-index", String(index));
    element.appendChild(span);
  });
  element.dataset.splitReady = "true";
}

const animatedHeadlines = document.querySelectorAll("[data-text-animate]");
if (isReducedMotion()) {
  animatedHeadlines.forEach((element) => element.classList.add("text-anim-ready"));
} else {
  animatedHeadlines.forEach(splitHeadline);
  if ("IntersectionObserver" in window) {
    const textObserver = new IntersectionObserver((entries, observer) => {
      entries.forEach((entry) => {
        if (!entry.isIntersecting) return;
        entry.target.classList.add("text-anim-ready");
        observer.unobserve(entry.target);
      });
    }, { threshold: 0.45 });
    animatedHeadlines.forEach((element) => textObserver.observe(element));
  } else {
    animatedHeadlines.forEach((element) => element.classList.add("text-anim-ready"));
  }
}

const marquee = document.querySelector("[data-marquee]");
const marqueeTrack = document.querySelector("[data-marquee-track]");
if (marquee && marqueeTrack && !isReducedMotion()) {
  let marqueeVisible = false;
  let marqueeOffset = 0;
  let lastFrame = performance.now();
  let previousScrollY = window.scrollY;
  const marqueeObserver = new IntersectionObserver(([entry]) => { marqueeVisible = entry.isIntersecting; }, { rootMargin: "120px" });
  marqueeObserver.observe(marquee);
  const tickMarquee = (now) => {
    const delta = Math.min(now - lastFrame, 48);
    lastFrame = now;
    const currentScrollY = window.scrollY;
    const direction = currentScrollY >= previousScrollY ? 1 : -1;
    previousScrollY = currentScrollY;
    if (marqueeVisible) {
      marqueeOffset -= (delta * 0.035 * direction);
      const width = marqueeTrack.scrollWidth / 2;
      if (width > 0) marqueeOffset = ((marqueeOffset % width) + width) % width;
      marqueeTrack.style.transform = `translate3d(${-marqueeOffset}px, 0, 0)`;
    }
    requestAnimationFrame(tickMarquee);
  };
  requestAnimationFrame(tickMarquee);
}

const parallaxElements = [...document.querySelectorAll("[data-parallax]")];
if (hero && parallaxElements.length && !isReducedMotion()) {
  let heroVisible = false;
  let lastScrollY = window.scrollY;
  const heroObserver = new IntersectionObserver(([entry]) => { heroVisible = entry.isIntersecting; }, { rootMargin: "180px" });
  heroObserver.observe(hero);
  const tickParallax = () => {
    const scrollDelta = Math.max(-220, Math.min(220, window.scrollY - lastScrollY));
    lastScrollY += scrollDelta * 0.08;
    if (heroVisible) {
      const heroRect = hero.getBoundingClientRect();
      const progress = Math.max(-1, Math.min(1, -heroRect.top / Math.max(heroRect.height, 1)));
      parallaxElements.forEach((element) => {
        const depth = Number(element.dataset.parallax) || 0;
        element.style.setProperty("--parallax-y", `${progress * depth * 80}px`);
      });
    }
    requestAnimationFrame(tickParallax);
  };
  requestAnimationFrame(tickParallax);
}

const processButtons = [...document.querySelectorAll("[data-process-step]")];
const processImage = document.querySelector("[data-process-image]");
const processIndex = document.querySelector("[data-process-index]");
const processCaption = document.querySelector("[data-process-caption]");
function activateProcess(button) {
  processButtons.forEach((item) => {
    const active = item === button;
    item.closest(".method-item")?.classList.toggle("is-active", active);
    if (active) item.setAttribute("aria-current", "step");
    else item.removeAttribute("aria-current");
  });
  if (!button || !processImage) return;
  processImage.classList.add("is-changing");
  window.setTimeout(() => {
    processImage.src = button.dataset.image || processImage.src;
    processImage.alt = button.dataset.alt || "";
    processIndex && (processIndex.textContent = `PROCESS / ${button.querySelector(".method-count")?.textContent ?? "01"}`);
    processCaption && (processCaption.textContent = button.dataset.caption || "");
    processImage.classList.remove("is-changing");
  }, isReducedMotion() ? 0 : 170);
}
processButtons.forEach((button) => button.addEventListener("click", () => activateProcess(button)));
processButtons.forEach((button) => button.addEventListener("keydown", (event) => {
  if (event.key !== "ArrowDown" && event.key !== "ArrowUp") return;
  event.preventDefault();
  const index = processButtons.indexOf(button);
  const next = event.key === "ArrowDown" ? (index + 1) % processButtons.length : (index - 1 + processButtons.length) % processButtons.length;
  processButtons[next]?.focus();
  activateProcess(processButtons[next]);
}));

document.querySelectorAll("[data-pointer-card]").forEach((card) => {
  card.addEventListener("pointermove", (event) => {
    const rect = card.getBoundingClientRect();
    card.style.setProperty("--pointer-x", `${event.clientX - rect.left}px`);
    card.style.setProperty("--pointer-y", `${event.clientY - rect.top}px`);
  });
});

document.querySelectorAll(".faq-list details").forEach((detail) => {
  const answer = detail.querySelector("p");
  if (!answer) return;
  const syncAnswer = () => {
    answer.style.maxHeight = detail.open ? `${answer.scrollHeight}px` : "0px";
    answer.style.opacity = detail.open ? "1" : "0";
  };
  detail.addEventListener("toggle", syncAnswer);
  syncAnswer();
});

const finePointer = window.matchMedia?.("(pointer: fine) and (hover: hover)")?.matches;
if (finePointer && !isReducedMotion()) {
  document.querySelectorAll("[data-magnetic]").forEach((button) => {
    const wrap = button.closest(".magnetic-wrap") || button;
    wrap.addEventListener("pointermove", (event) => {
      const rect = wrap.getBoundingClientRect();
      const x = (event.clientX - rect.left - rect.width / 2) * 0.38;
      const y = (event.clientY - rect.top - rect.height / 2) * 0.38;
      button.style.transform = `translate3d(${x}px, ${y}px, 0)`;
    });
    wrap.addEventListener("pointerleave", () => { button.style.transform = "translate3d(0, 0, 0)"; });
  });

  const cursor = document.querySelector("[data-cursor]");
  const cursorText = cursor?.querySelector("[data-cursor-text]");
  let cursorX = -100;
  let cursorY = -100;
  let targetX = cursorX;
  let targetY = cursorY;
  window.addEventListener("pointermove", (event) => { targetX = event.clientX; targetY = event.clientY; });
  const moveCursor = () => {
    cursorX += (targetX - cursorX) * 0.18;
    cursorY += (targetY - cursorY) * 0.18;
    if (cursor) cursor.style.transform = `translate3d(${cursorX}px, ${cursorY}px, 0)`;
    requestAnimationFrame(moveCursor);
  };
  requestAnimationFrame(moveCursor);
  document.querySelectorAll("[data-cursor-label]").forEach((card) => {
    const activateCursor = () => { cursor?.classList.add("is-active"); if (cursorText) cursorText.textContent = card.dataset.cursorLabel || "查看"; };
    const clearCursor = () => cursor?.classList.remove("is-active");
    card.addEventListener("pointerenter", activateCursor);
    card.addEventListener("pointerleave", clearCursor);
    card.addEventListener("focus", activateCursor);
    card.addEventListener("blur", clearCursor);
  });
}

const backToTop = document.querySelector("[data-back-to-top]");
if (backToTop && hero && "IntersectionObserver" in window) {
  const topObserver = new IntersectionObserver(([entry]) => backToTop.classList.toggle("is-visible", !entry.isIntersecting), { threshold: 0.05 });
  topObserver.observe(hero);
  backToTop.addEventListener("click", () => window.scrollTo({ top: 0, behavior: isReducedMotion() ? "auto" : "smooth" }));
}

const preloader = document.querySelector("[data-preloader]");
if (preloader) {
  const finishLoading = () => {
    preloader.classList.add("is-hidden");
    window.setTimeout(() => preloader.remove(), isReducedMotion() ? 0 : 520);
  };
  if (document.readyState === "complete") window.setTimeout(finishLoading, 260);
  else window.addEventListener("load", () => window.setTimeout(finishLoading, 260), { once: true });
  window.setTimeout(finishLoading, 8000);
  document.addEventListener("keydown", (event) => { if (event.key === "Escape") finishLoading(); }, { once: true });
}

const year = document.querySelector("[data-current-year]");
if (year) year.textContent = String(new Date().getFullYear());
