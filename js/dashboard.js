(() => {
    const sidebarGroups = document.querySelectorAll('[data-sidebar-group]');

    const readStoredGroupState = (storageKey) => {
        if (!storageKey) {
            return null;
        }

        try {
            return window.localStorage.getItem(storageKey);
        } catch (error) {
            return null;
        }
    };

    const writeStoredGroupState = (storageKey, isExpanded) => {
        if (!storageKey) {
            return;
        }

        try {
            window.localStorage.setItem(storageKey, isExpanded ? 'expanded' : 'collapsed');
        } catch (error) {
            // Ignore storage errors so navigation still works.
        }
    };

    const setGroupExpanded = (groupName, isExpanded) => {
        sidebarGroups.forEach((group) => {
            if (group.getAttribute('data-sidebar-group') !== groupName) {
                return;
            }

            const trigger = group.querySelector('.nav-group-trigger');
            group.classList.toggle('is-expanded', isExpanded);

            if (trigger) {
                trigger.setAttribute('aria-expanded', isExpanded ? 'true' : 'false');
            }
        });
    };

    sidebarGroups.forEach((group) => {
        const groupName = group.getAttribute('data-sidebar-group') || '';
        const storageKey = group.getAttribute('data-sidebar-storage-key') || '';
        const defaultExpanded = group.getAttribute('data-default-expanded') === 'true';
        const storedState = readStoredGroupState(storageKey);
        const isExpanded = storedState === 'expanded' || (storedState !== 'collapsed' && defaultExpanded);
        const trigger = group.querySelector('.nav-group-trigger');

        setGroupExpanded(groupName, isExpanded);

        if (!trigger) {
            return;
        }

        trigger.addEventListener('click', (event) => {
            const shouldExpand = !group.classList.contains('is-expanded');
            const targetUrl = trigger.href.split('#')[0];
            const currentUrl = window.location.href.split('#')[0];

            writeStoredGroupState(storageKey, shouldExpand);
            setGroupExpanded(groupName, shouldExpand);

            if (targetUrl === currentUrl) {
                event.preventDefault();
            }
        });
    });

    const placeholderLinks = document.querySelectorAll('a[href="#"]');

    placeholderLinks.forEach((link) => {
        link.addEventListener('click', (event) => {
            event.preventDefault();
        });
    });

    const confirmForms = document.querySelectorAll('form[data-confirm-action]');

    confirmForms.forEach((form) => {
        form.addEventListener('submit', (event) => {
            const message = form.getAttribute('data-confirm-action') || 'Continue with this action?';

            if (!window.confirm(message)) {
                event.preventDefault();
            }
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

    const gradingForm = document.querySelector('[data-grading-settings-form]');

    if (gradingForm) {
        const categoryList = gradingForm.querySelector('[data-grading-category-list]');
        const totalOutput = gradingForm.querySelector('[data-grading-total]');
        const statusPanel = gradingForm.querySelector('[data-grading-status]');
        const messageOutput = gradingForm.querySelector('[data-grading-message]');
        const saveButton = gradingForm.querySelector('[data-save-grading]');
        let categoryCounter = 1;

        const buildSubcategory = () => {
            const row = document.createElement('div');
            row.className = 'grading-subcategory';
            row.setAttribute('data-grading-subcategory', '');
            row.innerHTML = `
                <div class="grading-name-field">
                    <label class="form-label">Subcategory</label>
                    <input type="text" class="form-control" value="New subcategory" data-subcategory-name>
                </div>
                <div class="grading-weight-field">
                    <label class="form-label">Weight</label>
                    <div>
                        <input type="number" class="form-control" value="0" min="0" max="100" step="1" data-subcategory-weight>
                        <span>%</span>
                    </div>
                </div>
                <button class="btn btn-copy btn-danger-soft" type="button" data-delete-subcategory aria-label="Delete subcategory"><i class="bi bi-trash"></i></button>
            `;

            return row;
        };

        const buildCategory = () => {
            const category = document.createElement('div');
            category.className = 'grading-category';
            category.setAttribute('data-grading-category', '');
            category.innerHTML = `
                <div class="grading-category-row">
                    <div class="grading-name-field">
                        <label class="form-label">Category</label>
                        <input type="text" class="form-control" value="New Category ${categoryCounter}" data-category-name>
                    </div>
                    <div class="grading-weight-field">
                        <label class="form-label">Weight</label>
                        <div>
                            <input type="number" class="form-control" value="0" min="0" max="100" step="1" data-category-weight>
                            <span>%</span>
                        </div>
                    </div>
                    <div class="grading-row-actions">
                        <button class="btn btn-copy" type="button" data-add-subcategory><i class="bi bi-plus-circle"></i> Subcategory</button>
                        <button class="btn btn-copy btn-danger-soft" type="button" data-delete-category><i class="bi bi-trash"></i> Delete</button>
                    </div>
                </div>
                <div class="grading-subcategory-list" data-grading-subcategory-list></div>
                <p class="grading-category-error" data-category-error></p>
            `;
            categoryCounter += 1;

            return category;
        };

        const numberFromInput = (input) => {
            const value = Number.parseFloat(input.value);

            return Number.isFinite(value) ? value : 0;
        };

        const validateGrading = () => {
            const categories = [...gradingForm.querySelectorAll('[data-grading-category]')];
            const total = categories.reduce((sum, category) => {
                const input = category.querySelector('[data-category-weight]');
                return sum + (input ? numberFromInput(input) : 0);
            }, 0);
            const messages = [];
            let hasInvalidChildTotals = false;

            categories.forEach((category) => {
                const categoryName = category.querySelector('[data-category-name]')?.value || 'Category';
                const parentWeight = numberFromInput(category.querySelector('[data-category-weight]'));
                const subcategoryInputs = [...category.querySelectorAll('[data-subcategory-weight]')];
                const subcategoryTotal = subcategoryInputs.reduce((sum, input) => sum + numberFromInput(input), 0);
                const error = category.querySelector('[data-category-error]');
                const hasMismatch = subcategoryInputs.length > 0 && subcategoryTotal !== parentWeight;

                category.classList.toggle('is-invalid', hasMismatch);

                if (error) {
                    error.textContent = hasMismatch
                        ? `${categoryName} subcategories total ${subcategoryTotal}%, but the category weight is ${parentWeight}%.`
                        : '';
                }

                if (hasMismatch) {
                    hasInvalidChildTotals = true;
                }
            });

            if (total !== 100) {
                messages.push(`Top-level categories total ${total}%. Adjust weights to equal 100%.`);
            }

            if (hasInvalidChildTotals) {
                messages.push('Subcategory totals must match their parent category weights.');
            }

            const isValid = messages.length === 0;

            if (totalOutput) {
                totalOutput.textContent = String(total);
            }

            if (statusPanel) {
                statusPanel.classList.toggle('is-invalid', !isValid);
            }

            if (messageOutput) {
                messageOutput.textContent = isValid
                    ? 'Weights are valid. Total grading weight is 100%.'
                    : messages.join(' ');
            }

            if (saveButton) {
                saveButton.disabled = !isValid;
            }

            return isValid;
        };

        gradingForm.addEventListener('input', validateGrading);

        gradingForm.addEventListener('click', (event) => {
            const target = event.target.closest('button');

            if (!target) {
                return;
            }

            if (target.matches('[data-add-category]')) {
                categoryList.appendChild(buildCategory());
                validateGrading();
            }

            if (target.matches('[data-add-subcategory]')) {
                const category = target.closest('[data-grading-category]');
                const list = category?.querySelector('[data-grading-subcategory-list]');

                if (list) {
                    list.appendChild(buildSubcategory());
                    validateGrading();
                }
            }

            if (target.matches('[data-delete-category]')) {
                target.closest('[data-grading-category]')?.remove();
                validateGrading();
            }

            if (target.matches('[data-delete-subcategory]')) {
                target.closest('[data-grading-subcategory]')?.remove();
                validateGrading();
            }
        });

        gradingForm.addEventListener('submit', (event) => {
            event.preventDefault();
            validateGrading();
        });

        validateGrading();
    }

    const autoOpenModals = document.querySelectorAll('[data-auto-open-modal]');

    autoOpenModals.forEach((marker) => {
        const selector = marker.getAttribute('data-auto-open-modal');
        const modalElement = selector ? document.querySelector(selector) : null;

        if (modalElement && window.bootstrap) {
            window.bootstrap.Modal.getOrCreateInstance(modalElement).show();
        }
    });
})();
