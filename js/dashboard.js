(() => {
    const placeholderLinks = document.querySelectorAll('a[href="#"]');

    placeholderLinks.forEach((link) => {
        link.addEventListener('click', (event) => {
            event.preventDefault();
        });
    });
})();
