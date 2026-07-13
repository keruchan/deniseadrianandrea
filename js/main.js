(() => {
    const yearTargets = document.querySelectorAll('[data-year]');
    yearTargets.forEach((target) => {
        target.textContent = new Date().getFullYear().toString();
    });

    if (window.AOS) {
        window.AOS.init({ duration: 650, once: true, easing: 'ease-out-cubic' });
    }
})();
