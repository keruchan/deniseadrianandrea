(() => {
    const placeholderLinks = document.querySelectorAll('a[href="#"]');

    placeholderLinks.forEach((link) => {
        link.addEventListener('click', (event) => {
            event.preventDefault();
        });
    });

    const copyButtons = document.querySelectorAll('[data-copy]');

    copyButtons.forEach((button) => {
        button.addEventListener('click', async () => {
            const value = button.getAttribute('data-copy') || '';
            const originalText = button.innerHTML;

            try {
                if (navigator.clipboard && window.isSecureContext) {
                    await navigator.clipboard.writeText(value);
                } else {
                    const tempInput = document.createElement('textarea');
                    tempInput.value = value;
                    tempInput.setAttribute('readonly', 'readonly');
                    tempInput.style.position = 'absolute';
                    tempInput.style.left = '-9999px';
                    document.body.appendChild(tempInput);
                    tempInput.select();
                    document.execCommand('copy');
                    document.body.removeChild(tempInput);
                }

                button.classList.add('copied');
                button.innerHTML = '<i class="bi bi-check2"></i> Copied';

                window.setTimeout(() => {
                    button.classList.remove('copied');
                    button.innerHTML = originalText;
                }, 1600);
            } catch (error) {
                button.innerHTML = '<i class="bi bi-exclamation-circle"></i> Copy failed';

                window.setTimeout(() => {
                    button.innerHTML = originalText;
                }, 1600);
            }
        });
    });
})();
