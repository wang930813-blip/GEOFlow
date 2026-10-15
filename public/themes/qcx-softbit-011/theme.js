document.querySelectorAll('[data-menu-toggle]').forEach((button)=>button.addEventListener('click',()=>document.querySelector('[data-mobile-nav]')?.classList.toggle('is-open')));
