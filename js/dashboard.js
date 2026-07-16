(() => {
    // Preserve scroll position when opening or switching attendance/participation sheets.
    // Sheet navigation is a full-page load to the same path with a different ?meeting_id=,
    // so keying by pathname restores position across switches without leaking to other pages.
    (() => {
        if (!window.sessionStorage) {
            return;
        }

        const scrollKey = 'edupredict:sheetScroll:' + window.location.pathname;

        if ('scrollRestoration' in window.history) {
            window.history.scrollRestoration = 'manual';
        }

        const saved = window.sessionStorage.getItem(scrollKey);
        if (saved !== null) {
            window.sessionStorage.removeItem(scrollKey);
            const y = parseInt(saved, 10);

            if (!Number.isNaN(y)) {
                // Run after the synchronous setup below (e.g. week windowing) settles the height.
                const restore = () => window.scrollTo(0, y);
                window.requestAnimationFrame(() => {
                    restore();
                    window.requestAnimationFrame(restore);
                });
            }
        }

        const saveScroll = () => {
            try {
                window.sessionStorage.setItem(scrollKey, String(window.scrollY || window.pageYOffset || 0));
            } catch (error) {
                // Ignore storage errors so navigation still works.
            }
        };

        document.addEventListener('click', (event) => {
            if (event.target.closest('a[href*="meeting_id="]')) {
                saveScroll();
            }
        });

        document.querySelectorAll('[data-attendance-sheet], [data-participation-sheet]').forEach((form) => {
            form.addEventListener('submit', saveScroll);
        });
    })();

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

    const weekdayOptions = [
        ['1', 'Monday'],
        ['2', 'Tuesday'],
        ['3', 'Wednesday'],
        ['4', 'Thursday'],
        ['5', 'Friday'],
        ['6', 'Saturday'],
        ['7', 'Sunday'],
    ];

    const buildScheduleSlotRow = () => {
        const row = document.createElement('div');
        row.className = 'schedule-slot-row';
        row.setAttribute('data-schedule-slot', '');
        row.innerHTML = `
            <div class="field">
                <label class="form-label">Day of week</label>
                <select class="form-control" name="meeting_day[]">
                    ${weekdayOptions.map(([value, label]) => `<option value="${value}">${label}</option>`).join('')}
                </select>
            </div>
            <div class="field">
                <label class="form-label">Meeting type</label>
                <input type="text" class="form-control" name="meeting_type[]" value="Lecture" maxlength="80" placeholder="Lecture">
            </div>
            <button class="btn btn-copy btn-danger-soft" type="button" data-remove-schedule-slot aria-label="Remove meeting"><i class="bi bi-trash"></i></button>
        `;

        return row;
    };

    document.querySelectorAll('.teaching-schedule-block').forEach((block) => {
        const list = block.querySelector('[data-schedule-slot-list]');
        const meetingCount = block.querySelector('[data-meetings-per-week]');

        const syncMeetingCount = () => {
            if (meetingCount && list) {
                meetingCount.value = String(list.querySelectorAll('[data-schedule-slot]').length);
            }
        };

        block.addEventListener('click', (event) => {
            const target = event.target.closest('button');

            if (!target || !list) {
                return;
            }

            if (target.matches('[data-add-schedule-slot]')) {
                list.appendChild(buildScheduleSlotRow());
                syncMeetingCount();
            }

            if (target.matches('[data-remove-schedule-slot]')) {
                const rows = list.querySelectorAll('[data-schedule-slot]');

                if (rows.length > 1) {
                    target.closest('[data-schedule-slot]')?.remove();
                    syncMeetingCount();
                }
            }
        });
    });

    // Attendance status selects: color the single control by its selected value.
    const attendanceStatuses = ['present', 'absent', 'late', 'excused'];

    const applyAttendanceStatusColor = (select) => {
        attendanceStatuses.forEach((status) => select.classList.remove('status-' + status));
        select.classList.add('status-' + select.value);
    };

    document.querySelectorAll('.attendance-status-select').forEach((select) => {
        applyAttendanceStatusColor(select);
        select.addEventListener('change', () => applyAttendanceStatusColor(select));
    });

    document.querySelectorAll('[data-attendance-sheet]').forEach((form) => {
        const markAllButton = form.querySelector('[data-mark-all-present]');

        if (!markAllButton) {
            return;
        }

        markAllButton.addEventListener('click', () => {
            form.querySelectorAll('.attendance-status-select').forEach((select) => {
                select.value = 'present';
                applyAttendanceStatusColor(select);
            });
        });
    });

    // Real-time client-side student search for every student list (sheets + history modals).
    document.querySelectorAll('[data-student-search-scope]').forEach((scope) => {
        const input = scope.querySelector('[data-student-search]');
        const list = scope.querySelector('[data-student-search-list]');
        const emptyMessage = scope.querySelector('[data-student-search-empty]');

        if (!input || !list) {
            return;
        }

        const rows = Array.from(list.querySelectorAll('[data-search-terms]'));

        const filterRows = () => {
            const query = input.value.trim().toLowerCase();
            let visibleCount = 0;

            rows.forEach((row) => {
                const matches = query === '' || (row.getAttribute('data-search-terms') || '').indexOf(query) !== -1;
                row.style.display = matches ? '' : 'none';

                if (matches) {
                    visibleCount += 1;
                }
            });

            if (emptyMessage) {
                emptyMessage.hidden = visibleCount !== 0;
            }
        };

        input.addEventListener('input', filterRows);
    });

    document.querySelectorAll('[data-meeting-weeks]').forEach((container) => {
        const weekBlocks = Array.from(container.querySelectorAll('[data-week-index]'));

        if (!weekBlocks.length) {
            return;
        }

        const nav = container.previousElementSibling;
        const prevBtn = nav ? nav.querySelector('[data-load-previous-weeks]') : null;
        const moreBtn = nav ? nav.querySelector('[data-load-more-weeks]') : null;
        const rangeLabel = nav ? nav.querySelector('[data-week-range-label]') : null;
        const weekCount = weekBlocks.length;

        let visibleStart = parseInt(container.getAttribute('data-visible-start'), 10) || 0;
        let visibleEnd = parseInt(container.getAttribute('data-visible-end'), 10);
        if (Number.isNaN(visibleEnd)) {
            visibleEnd = weekCount - 1;
        }

        const applyVisibility = () => {
            weekBlocks.forEach((block) => {
                const idx = parseInt(block.getAttribute('data-week-index'), 10);
                block.classList.toggle('is-hidden', idx < visibleStart || idx > visibleEnd);
            });

            if (prevBtn) {
                prevBtn.disabled = visibleStart <= 0;
            }

            if (moreBtn) {
                moreBtn.disabled = visibleEnd >= weekCount - 1;
            }

            if (rangeLabel) {
                const startWeek = weekBlocks[visibleStart].getAttribute('data-week-number');
                const endWeek = weekBlocks[visibleEnd].getAttribute('data-week-number');
                rangeLabel.textContent = startWeek === endWeek
                    ? `Week ${startWeek}`
                    : `Weeks ${startWeek}–${endWeek}`;
            }
        };

        applyVisibility();

        if (prevBtn) {
            prevBtn.addEventListener('click', () => {
                visibleStart = Math.max(0, visibleStart - 1);
                applyVisibility();
            });
        }

        if (moreBtn) {
            moreBtn.addEventListener('click', () => {
                visibleEnd = Math.min(weekCount - 1, visibleEnd + 1);
                applyVisibility();
            });
        }
    });

    // Student history modals (attendance, participation, and each assessment type
    // share this list/detail pattern via the data-student-history-modal hook).
    document.querySelectorAll('[data-student-history-modal]').forEach((modal) => {
        const showPanel = (target) => {
            modal.querySelectorAll('[data-student-panel]').forEach((panel) => {
                panel.classList.remove('is-active');
            });

            target.classList.add('is-active');
        };

        const listPanel = modal.querySelector('[data-student-panel="list"]');

        modal.querySelectorAll('[data-student-select]').forEach((row) => {
            row.addEventListener('click', () => {
                const studentId = row.getAttribute('data-student-select');
                const detailPanel = modal.querySelector(`[data-student-panel="detail"][data-student-id="${studentId}"]`);

                if (detailPanel) {
                    showPanel(detailPanel);
                }
            });
        });

        modal.querySelectorAll('[data-student-back]').forEach((button) => {
            button.addEventListener('click', () => {
                if (listPanel) {
                    showPanel(listPanel);
                }
            });
        });

        modal.addEventListener('hidden.bs.modal', () => {
            if (listPanel) {
                showPanel(listPanel);
            }
        });
    });

    // Recitation student picker.
    document.querySelectorAll('[data-student-picker]').forEach((picker) => {
        let students = [];
        try {
            students = JSON.parse(picker.getAttribute('data-picker-students') || '[]');
        } catch (error) {
            students = [];
        }

        const maxScore = parseFloat(picker.getAttribute('data-picker-max')) || 100;
        const sheetForm = document.querySelector('[data-participation-sheet]');
        const modeInputs = picker.querySelectorAll('[data-picker-mode]');
        const criteriaWrap = picker.querySelector('[data-picker-criteria]');
        const optionInputs = {
            preventDuplicates: picker.querySelector('[data-picker-option="preventDuplicates"]'),
            excludeAbsent: picker.querySelector('[data-picker-option="excludeAbsent"]'),
            excludeGraded: picker.querySelector('[data-picker-option="excludeGraded"]'),
        };
        const emptyState = picker.querySelector('[data-picker-empty]');
        const resultState = picker.querySelector('[data-picker-result]');
        const avatar = picker.querySelector('[data-picker-avatar]');
        const nameOut = picker.querySelector('[data-picker-name]');
        const noOut = picker.querySelector('[data-picker-no]');
        const scoreInput = picker.querySelector('[data-picker-score]');
        const markButton = picker.querySelector('[data-picker-mark]');
        const markNote = picker.querySelector('[data-picker-marknote]');
        const poolNote = picker.querySelector('[data-picker-poolnote]');
        const pickButton = picker.querySelector('[data-picker-pick]');
        const refreshButton = picker.querySelector('[data-picker-refresh]');

        const pickedThisSession = new Set();
        let currentStudent = null;

        const currentMode = () => {
            const checked = picker.querySelector('[data-picker-mode]:checked');
            return checked ? checked.value : 'random';
        };

        const isGraded = (student) => {
            if (sheetForm) {
                const input = sheetForm.querySelector(`[data-participation-score][name="participation[${student.id}]"]`);
                if (input && input.value.trim() !== '') {
                    return true;
                }
            }
            return Boolean(student.graded);
        };

        const eligiblePool = () => {
            const mode = currentMode();
            return students.filter((student) => {
                if (optionInputs.preventDuplicates && optionInputs.preventDuplicates.checked && pickedThisSession.has(student.id)) {
                    return false;
                }

                if (mode === 'criteria') {
                    if (optionInputs.excludeAbsent && optionInputs.excludeAbsent.checked && student.absent) {
                        return false;
                    }
                    if (optionInputs.excludeGraded && optionInputs.excludeGraded.checked && isGraded(student)) {
                        return false;
                    }
                }

                return true;
            });
        };

        const initials = (name) => name.split(/\s+/).filter(Boolean).slice(0, 2).map((part) => part.charAt(0).toUpperCase()).join('');

        const updatePoolNote = () => {
            const pool = eligiblePool();
            poolNote.textContent = `${pool.length} student${pool.length === 1 ? '' : 's'} eligible`;
            if (pickButton) {
                pickButton.disabled = pool.length === 0;
            }
        };

        const syncModeUi = () => {
            if (criteriaWrap) {
                criteriaWrap.classList.toggle('is-random', currentMode() === 'random');
            }
            if (currentMode() === 'criteria') {
                if (optionInputs.excludeAbsent) optionInputs.excludeAbsent.checked = true;
                if (optionInputs.excludeGraded) optionInputs.excludeGraded.checked = true;
            } else {
                if (optionInputs.excludeAbsent) optionInputs.excludeAbsent.checked = false;
                if (optionInputs.excludeGraded) optionInputs.excludeGraded.checked = false;
            }
            updatePoolNote();
        };

        const showResult = (student) => {
            currentStudent = student;
            emptyState.hidden = true;
            resultState.hidden = false;
            avatar.textContent = initials(student.name) || '?';
            nameOut.textContent = student.name;
            noOut.textContent = student.student_no || '';
            if (markNote) {
                markNote.hidden = true;
            }
            if (scoreInput) {
                scoreInput.value = '';
            }
            resultState.classList.remove('is-animating');
            void resultState.offsetWidth;
            resultState.classList.add('is-animating');
        };

        const pick = () => {
            const pool = eligiblePool();
            if (!pool.length) {
                updatePoolNote();
                return;
            }
            const choice = pool[Math.floor(Math.random() * pool.length)];
            pickedThisSession.add(choice.id);
            showResult(choice);
            updatePoolNote();
        };

        modeInputs.forEach((input) => input.addEventListener('change', syncModeUi));
        Object.values(optionInputs).forEach((input) => {
            if (input) {
                input.addEventListener('change', updatePoolNote);
            }
        });

        if (pickButton) {
            pickButton.addEventListener('click', pick);
        }

        // Re-evaluate eligibility against current criteria and clear this session's
        // temporary "already picked" flags. Saved grades in the sheet are untouched,
        // so picked-but-ungraded students return to the pool while graded ones stay
        // excluded when "exclude already graded" is on.
        if (refreshButton) {
            refreshButton.addEventListener('click', () => {
                pickedThisSession.clear();
                currentStudent = null;
                resultState.hidden = true;
                emptyState.hidden = false;

                if (markNote) {
                    markNote.hidden = true;
                }

                updatePoolNote();
            });
        }

        if (markButton) {
            markButton.addEventListener('click', () => {
                if (!currentStudent || !sheetForm) {
                    return;
                }
                let value = scoreInput ? scoreInput.value.trim() : '';
                if (value === '') {
                    value = String(maxScore);
                }
                let numeric = parseFloat(value);
                if (Number.isNaN(numeric)) {
                    numeric = maxScore;
                }
                numeric = Math.max(0, Math.min(maxScore, numeric));

                const scoreField = sheetForm.querySelector(`[data-participation-score][name="participation[${currentStudent.id}]"]`);
                if (scoreField) {
                    scoreField.value = String(numeric);
                }
                const row = sheetForm.querySelector(`[data-participation-student="${currentStudent.id}"]`);
                if (row) {
                    row.setAttribute('data-just-marked', '1');
                }

                if (markNote) {
                    markNote.hidden = false;
                    markNote.textContent = `Marked ${currentStudent.name} with ${numeric}. Save the sheet to keep it.`;
                }
                updatePoolNote();
            });
        }

        picker.closest('.modal').addEventListener('shown.bs.modal', () => {
            syncModeUi();
        });

        syncModeUi();
    });

    // Assessment setup: toggle group grouping options by mode + grouping source.
    document.querySelectorAll('[data-assessment-item-form]').forEach((form) => {
        const groupingBlock = form.querySelector('[data-assessment-grouping]');
        const existingBlock = form.querySelector('[data-grouping-existing]');
        const newBlock = form.querySelector('[data-grouping-new]');

        const syncMode = () => {
            const checked = form.querySelector('[data-assessment-mode]:checked');
            if (groupingBlock) {
                groupingBlock.hidden = !(checked && checked.value === 'group');
            }
        };

        const syncSource = () => {
            const checked = form.querySelector('[data-grouping-source]:checked');
            const isNew = checked && checked.value === 'new';
            if (existingBlock) {
                existingBlock.hidden = isNew;
            }
            if (newBlock) {
                newBlock.hidden = !isNew;
            }
        };

        form.querySelectorAll('[data-assessment-mode]').forEach((input) => input.addEventListener('change', syncMode));
        form.querySelectorAll('[data-grouping-source]').forEach((input) => input.addEventListener('change', syncSource));

        syncMode();
        syncSource();
    });

    // Standalone grouping-source toggle (grading screen "assign grouping" form).
    document.querySelectorAll('[data-grouping-assign]').forEach((form) => {
        const existingBlock = form.querySelector('[data-grouping-existing]');
        const newBlock = form.querySelector('[data-grouping-new]');

        const sync = () => {
            const checked = form.querySelector('[data-grouping-source]:checked');
            const isNew = checked && checked.value === 'new';
            if (existingBlock) {
                existingBlock.hidden = isNew;
            }
            if (newBlock) {
                newBlock.hidden = !isNew;
            }
        };

        form.querySelectorAll('[data-grouping-source]').forEach((input) => input.addEventListener('change', sync));
        sync();
    });

    // Group grading: the group score fills every member as the default grade.
    document.querySelectorAll('[data-group-grade]').forEach((card) => {
        const groupScore = card.querySelector('[data-group-score]');
        const members = card.querySelectorAll('[data-group-member-score]');

        if (!groupScore) {
            return;
        }

        groupScore.addEventListener('input', () => {
            members.forEach((member) => {
                member.value = groupScore.value;
            });
        });
    });

    // Live grouping editor: group/grouping names, leaders, and student assignments
    // persist instantly via AJAX (no submit button). Used by the class Groupings
    // module and activity-specific groupings alike.
    document.querySelectorAll('[data-grouping-editor]').forEach((editor) => {
        const groupingId = editor.getAttribute('data-grouping-id');
        const csrf = editor.getAttribute('data-csrf');
        const ajaxUrl = editor.getAttribute('data-ajax-url');

        const post = (params) => {
            const body = new URLSearchParams(Object.assign({ csrf_token: csrf }, params));
            return fetch(ajaxUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: body.toString(),
            }).then((response) => response.json().catch(() => ({ ok: false, error: 'Unexpected response.' })));
        };

        const escapeHtml = (value) => String(value).replace(/[&<>"']/g, (ch) => (
            { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ch]
        ));

        // Transient "Saved" toast.
        const toast = document.createElement('div');
        toast.className = 'grouping-toast';
        toast.setAttribute('role', 'status');
        toast.hidden = true;
        editor.appendChild(toast);
        let toastTimer = null;
        const showToast = (message, ok) => {
            toast.textContent = message;
            toast.classList.toggle('is-error', !ok);
            toast.hidden = false;
            // reflow so the transition replays
            void toast.offsetWidth;
            toast.classList.add('is-visible');
            window.clearTimeout(toastTimer);
            toastTimer = window.setTimeout(() => toast.classList.remove('is-visible'), 1600);
        };

        // Re-render each group card's member pills + leader options from the server
        // snapshot, and re-sync the roster dropdowns so the group list AND the member
        // list always reflect exactly what was saved.
        const renderGroups = (groups) => {
            const studentGroup = {}; // student_id -> group_id from server truth
            groups.forEach((group) => {
                const card = editor.querySelector('[data-group-card][data-group-id="' + group.id + '"]');
                if (card) {
                    const membersEl = card.querySelector('[data-group-members]');
                    if (membersEl) {
                        membersEl.innerHTML = group.members.length
                            ? group.members.map((m) => '<span class="status-pill small tone-slate">' + escapeHtml(m.name) + '</span>').join('')
                            : '<span class="text-secondary small" data-empty-members>No members yet &mdash; assign below.</span>';
                    }

                    const leaderSel = card.querySelector('[data-group-leader]');
                    if (leaderSel) {
                        const current = String(group.leader || 0);
                        leaderSel.innerHTML = '<option value="0">No leader</option>'
                            + group.members.map((m) => '<option value="' + m.id + '"' + (String(m.id) === current ? ' selected' : '') + '>' + escapeHtml(m.name) + '</option>').join('');
                    }
                }

                group.members.forEach((m) => { studentGroup[String(m.id)] = String(group.id); });
            });

            // Keep every roster row's dropdown in step with the persisted assignment.
            editor.querySelectorAll('[data-assign-student]').forEach((sel) => {
                const sid = sel.getAttribute('data-assign-student');
                const gid = studentGroup[sid] || '0';
                sel.value = gid;
                sel.setAttribute('data-prev', gid);
            });
        };

        const groupingNameInput = editor.querySelector('[data-grouping-rename]');
        if (groupingNameInput) {
            groupingNameInput.addEventListener('change', () => {
                const name = groupingNameInput.value.trim();
                if (name === '') {
                    showToast('Grouping name is required.', false);
                    return;
                }
                post({ action: 'rename_grouping', grouping_id: groupingId, name }).then((res) => {
                    showToast(res.ok ? 'Saved' : (res.error || 'Could not save.'), res.ok);
                });
            });
        }

        editor.querySelectorAll('[data-group-rename]').forEach((input) => {
            input.addEventListener('change', () => {
                const card = input.closest('[data-group-card]');
                const gid = card.getAttribute('data-group-id');
                const name = input.value.trim();
                post({ action: 'rename_group', group_id: gid, name }).then((res) => {
                    showToast(res.ok ? 'Saved' : (res.error || 'Could not save.'), res.ok);
                    if (res.ok && name !== '') {
                        editor.querySelectorAll('[data-assign-student] option[value="' + gid + '"]').forEach((opt) => {
                            opt.textContent = name;
                        });
                    }
                });
            });
        });

        editor.querySelectorAll('[data-group-leader]').forEach((sel) => {
            sel.addEventListener('change', () => {
                const card = sel.closest('[data-group-card]');
                const gid = card.getAttribute('data-group-id');
                post({ action: 'set_leader', group_id: gid, student_id: sel.value }).then((res) => {
                    showToast(res.ok ? 'Saved' : (res.error || 'Could not save.'), res.ok);
                });
            });
        });

        editor.querySelectorAll('[data-assign-student]').forEach((sel) => {
            sel.setAttribute('data-prev', sel.value);
            sel.addEventListener('change', () => {
                const studentId = sel.getAttribute('data-assign-student');
                const groupId = sel.value;
                const previous = sel.getAttribute('data-prev') || '0';

                post({ action: 'assign_member', grouping_id: groupingId, student_id: studentId, group_id: groupId })
                    .then((res) => {
                        if (res.ok) {
                            sel.setAttribute('data-prev', groupId);
                            if (res.groups) {
                                renderGroups(res.groups);
                            }
                            showToast('Saved', true);
                        } else {
                            sel.value = previous;
                            showToast(res.error || 'Could not save.', false);
                        }
                    })
                    .catch(() => {
                        sel.value = previous;
                        showToast('Network error.', false);
                    });
            });
        });
    });

    // Grading Settings: live sum-to-100 total + dirty/saved feedback for the fixed
    // 6-weight form.
    const gradingForm = document.querySelector('[data-grading-weights-form]');

    if (gradingForm) {
        const trackedInputs = [...gradingForm.querySelectorAll('[data-grading-weight-input], [name="passing_grade"]')];
        const weightInputs = [...gradingForm.querySelectorAll('[data-grading-weight-input]')];
        const totalOutput = gradingForm.querySelector('[data-grading-total]');
        const statusPanel = gradingForm.querySelector('[data-grading-status]');
        const messageOutput = gradingForm.querySelector('[data-grading-message]');
        const saveButton = gradingForm.querySelector('[data-grading-save]');
        const dirtyIndicator = gradingForm.querySelector('[data-grading-dirty]');
        const cleanIndicator = gradingForm.querySelector('[data-grading-clean]');

        // Snapshot the initial values so we can tell when the form is dirty.
        const baseline = trackedInputs.map((input) => input.value.trim());
        let submitted = false;

        const numberFromInput = (input) => {
            const value = Number.parseFloat(input.value);
            return Number.isFinite(value) ? value : 0;
        };

        const isDirty = () => trackedInputs.some((input, i) => input.value.trim() !== baseline[i]);

        const updateDirtyState = () => {
            if (submitted) {
                return;
            }
            const dirty = isDirty();
            if (dirtyIndicator) {
                dirtyIndicator.hidden = !dirty;
            }
            if (cleanIndicator) {
                cleanIndicator.hidden = dirty;
            }
        };

        const validateGrading = () => {
            const total = weightInputs.reduce((sum, input) => sum + numberFromInput(input), 0);
            const rounded = Math.round(total * 100) / 100;
            const isValid = rounded === 100;

            if (totalOutput) {
                totalOutput.textContent = String(rounded);
            }
            if (statusPanel) {
                statusPanel.classList.toggle('is-invalid', !isValid);
            }
            if (messageOutput) {
                messageOutput.textContent = isValid
                    ? 'Weights total exactly 100%. Ready to save.'
                    : `Component weights total ${rounded}%. Adjust them to equal 100%.`;
            }
            if (saveButton) {
                saveButton.disabled = !isValid;
            }

            return isValid;
        };

        gradingForm.addEventListener('input', () => {
            validateGrading();
            updateDirtyState();
        });

        gradingForm.addEventListener('submit', () => {
            submitted = true;
            if (dirtyIndicator) { dirtyIndicator.hidden = true; }
            if (cleanIndicator) { cleanIndicator.hidden = true; }
        });

        validateGrading();
        updateDirtyState();
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
