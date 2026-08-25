(function () {
  function initLucideIcons() {
    if (window.lucide && typeof window.lucide.createIcons === 'function') {
      window.lucide.createIcons();
    }
  }

  function initMobileMenu() {
    document.querySelectorAll('[data-tech-menu-toggle]').forEach(function (button) {
      var targetId = button.getAttribute('aria-controls');
      var target = targetId ? document.getElementById(targetId) : null;
      if (!target) return;

      button.addEventListener('click', function () {
        var isHidden = target.hasAttribute('hidden');
        if (isHidden) {
          target.removeAttribute('hidden');
        } else {
          target.setAttribute('hidden', '');
        }
        button.setAttribute('aria-expanded', isHidden ? 'true' : 'false');
      });
    });
  }

  function initCarousel(rootSelector, slideSelector, dotSelector, intervalMs) {
    document.querySelectorAll(rootSelector).forEach(function (carousel) {
      var slides = Array.from(carousel.querySelectorAll(slideSelector));
      var dots = Array.from(carousel.querySelectorAll(dotSelector));
      if (slides.length <= 1) return;

      var activeIndex = Math.max(0, slides.findIndex(function (slide) {
        return slide.classList.contains('is-active');
      }));

      function activate(index) {
        activeIndex = index;
        slides.forEach(function (slide, slideIndex) {
          slide.classList.toggle('is-active', slideIndex === index);
        });
        dots.forEach(function (dot, dotIndex) {
          dot.classList.toggle('is-active', dotIndex === index);
        });
      }

      dots.forEach(function (dot, index) {
        dot.addEventListener('click', function () {
          activate(index);
        });
      });

      window.setInterval(function () {
        activate((activeIndex + 1) % slides.length);
      }, intervalMs);
    });
  }

  document.addEventListener('DOMContentLoaded', function () {
    initLucideIcons();
    initMobileMenu();
    initCarousel('[data-hot-carousel]', '[data-hot-slide]', '[data-hot-dot]', 4800);
    initCarousel('[data-home-poster-carousel]', '[data-home-poster-slide]', '[data-home-poster-dot]', 9000);
  });
})();
