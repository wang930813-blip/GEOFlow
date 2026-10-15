(() => {
  const header = document.querySelector('.site-header');
  window.addEventListener('scroll', () => header?.classList.toggle('scrolled', window.scrollY > 16), { passive: true });
  document.querySelector('.menu-toggle')?.addEventListener('click', () => {
    const open = header.classList.toggle('nav-open');
    document.querySelector('.menu-toggle')?.setAttribute('aria-expanded', String(open));
  });
})();
