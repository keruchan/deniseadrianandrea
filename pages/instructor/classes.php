<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/sidebar_config.php';
require_once __DIR__ . '/../../includes/class_management.php';
require_once __DIR__ . '/../../includes/attendance_management.php';
require_once __DIR__ . '/../../includes/participation_management.php';
require_once __DIR__ . '/../../includes/assessment_management.php';
require_once __DIR__ . '/../../includes/grouping_management.php';
require_once __DIR__ . '/../../includes/dashboard_layout.php';

require_role('instructor');

$instructorId = current_instructor_id($pdo);
if ($instructorId === null) {
    redirect_to('pages/auth/logout.php');
}

ensure_attendance_schema($pdo);
ensure_participation_schema($pdo);
ensure_assessment_schema($pdo);
ensure_grouping_schema($pdo);

$errors = [];
$successMessage = '';
$formData = class_form_defaults();
$scheduleData = attendance_schedule_defaults();
$assessmentTotals = ['activity' => null, 'quiz' => null];
$assessmentTotalsInput = ['activity' => '', 'quiz' => ''];
$autoOpenModal = '';
$editTargetClassId = 0;
$postedAction = '';

if (!empty($_SESSION['class_success'])) {
    $successMessage = (string) $_SESSION['class_success'];
    unset($_SESSION['class_success']);
}

$csrfToken = csrf_token('csrf_class_manage_token');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postedAction = (string) ($_POST['class_action'] ?? 'create');
    $submittedToken = (string) ($_POST['csrf_token'] ?? '');
    $classId = (int) ($_POST['class_id'] ?? 0);
    $meetingId = (int) ($_POST['meeting_id'] ?? 0);
    $meetingData = [];

    if (!csrf_is_valid('csrf_class_manage_token', $submittedToken)) {
        $errors[] = 'Security validation failed. Please refresh the page and try again.';
    }

    if ($postedAction === 'create' || $postedAction === 'edit') {
        $formData = class_form_data_from_array($_POST);
        $errors = array_merge($errors, validate_class_form_data($formData));
        $scheduleData = attendance_schedule_data_from_array($_POST);
        $errors = array_merge($errors, validate_attendance_schedule_data($scheduleData));

        foreach (['activity' => 'total_activities', 'quiz' => 'total_quizzes'] as $totalType => $totalField) {
            [$totalValue, $totalProvided, $totalError] = assessment_total_from_input($_POST[$totalField] ?? '');

            if ($totalError !== null) {
                $errors[] = 'Total ' . strtolower(assessment_types()[$totalType]['plural']) . ' ' . $totalError;
            }

            $assessmentTotals[$totalType] = $totalValue;
            $assessmentTotalsInput[$totalType] = trim((string) ($_POST[$totalField] ?? ''));
        }
    }

    if (in_array($postedAction, ['edit', 'archive', 'delete', 'meeting_add', 'meeting_save', 'assessment_configure'], true) && $classId <= 0) {
        $errors[] = 'Class selection is invalid.';
    }

    if ($postedAction === 'assessment_configure') {
        $assessmentType = (string) ($_POST['assessment_type'] ?? '');

        if (!isset(assessment_types()[$assessmentType])) {
            $errors[] = 'Assessment type is invalid.';
        }

        [$configureTotal, , $configureError] = assessment_total_from_input($_POST['assessment_total'] ?? '');

        if ($configureTotal === null) {
            $errors[] = 'Total count ' . ($configureError ?? 'is required.');
        }
    }

    if ($postedAction === 'assessment_item_save') {
        $assessmentItemId = (int) ($_POST['item_id'] ?? 0);
        $assessmentItemTitle = trim((string) ($_POST['item_title'] ?? ''));
        $assessmentItemDescription = trim((string) ($_POST['item_description'] ?? ''));
        $assessmentItemMaxRaw = trim((string) ($_POST['item_max_score'] ?? ''));
        $assessmentItemDate = trim((string) ($_POST['item_date'] ?? ''));
        $assessmentItemMode = (string) ($_POST['item_mode'] ?? 'individual') === 'group' ? 'group' : 'individual';
        $assessmentItemGroupingId = (int) ($_POST['item_grouping_id'] ?? 0);

        if ($assessmentItemId <= 0) {
            $errors[] = 'Item selection is invalid.';
        }

        if ($assessmentItemTitle === '' || text_length($assessmentItemTitle) > 150) {
            $errors[] = 'Item title is required and must not exceed 150 characters.';
        }

        if ($assessmentItemDate !== '' && strtotime($assessmentItemDate) === false) {
            $errors[] = 'Item date is invalid.';
        }

        if ($assessmentItemDescription !== '' && text_length($assessmentItemDescription) > 255) {
            $errors[] = 'Item description must not exceed 255 characters.';
        }

        $assessmentItemMax = is_numeric($assessmentItemMaxRaw) ? (float) $assessmentItemMaxRaw : -1;

        if ($assessmentItemMax < 1 || $assessmentItemMax > 1000) {
            $errors[] = 'Item max score must be between 1 and 1000.';
        }
    }

    if ($postedAction === 'assessment_grade_save') {
        $gradeItemId = (int) ($_POST['item_id'] ?? 0);
        $gradeView = (string) ($_POST['grade_view'] ?? 'activities');

        if ($gradeItemId <= 0) {
            $errors[] = 'Item selection is invalid.';
        }
    }

    if ($postedAction === 'assessment_assign_grouping') {
        $assignItemId = (int) ($_POST['item_id'] ?? 0);
        $assignSource = (string) ($_POST['grouping_source'] ?? 'existing');

        if ($assignItemId <= 0) {
            $errors[] = 'Item selection is invalid.';
        }

        if ($assignSource === 'new') {
            [$assignConfig, $assignConfigErrors] = grouping_config_from_input($_POST);
            $errors = array_merge($errors, $assignConfigErrors);
        } else {
            $assignGroupingId = (int) ($_POST['grouping_id'] ?? 0);
            if ($assignGroupingId <= 0) {
                $errors[] = 'Please choose a grouping to use.';
            }
        }
    }

    if (in_array($postedAction, ['grouping_create', 'grouping_save', 'grouping_delete'], true) && $classId <= 0) {
        $errors[] = 'Class selection is invalid.';
    }

    if ($postedAction === 'grouping_create') {
        [$groupingConfig, $groupingConfigErrors] = grouping_config_from_input($_POST);
        $errors = array_merge($errors, $groupingConfigErrors);
        $groupingCreateContext = (string) ($_POST['grouping_context'] ?? 'class');
        $groupingCreateItemId = (int) ($_POST['grouping_item_id'] ?? 0);
    }

    if (in_array($postedAction, ['grouping_save', 'grouping_delete'], true)) {
        $groupingId = (int) ($_POST['grouping_id'] ?? 0);

        if ($groupingId <= 0) {
            $errors[] = 'Grouping selection is invalid.';
        }
    }

    if (in_array($postedAction, ['meeting_add', 'meeting_save'], true)) {
        $meetingData = [
            'meeting_date' => trim((string) ($_POST['meeting_date'] ?? '')),
            'meeting_type' => trim((string) ($_POST['meeting_type'] ?? '')),
            'status' => (string) ($_POST['meeting_status'] ?? 'regular'),
            'topic' => trim((string) ($_POST['topic'] ?? '')),
        ];

        if ($meetingData['meeting_date'] === '' || strtotime($meetingData['meeting_date']) === false) {
            $errors[] = 'Meeting date is required.';
        }

        if ($meetingData['meeting_type'] === '') {
            $errors[] = 'Meeting type is required.';
        }

        if (!isset(attendance_statuses()[$meetingData['status']])) {
            $errors[] = 'Meeting status is invalid.';
        }
    }

    if ($postedAction === 'meeting_save' && $meetingId <= 0) {
        $errors[] = 'Meeting selection is invalid.';
    }

    if ($postedAction === 'attendance_save' && $meetingId <= 0) {
        $errors[] = 'Meeting selection is invalid.';
    }

    if ($postedAction === 'participation_save' && $meetingId <= 0) {
        $errors[] = 'Meeting selection is invalid.';
    }

    if (empty($errors)) {
        try {
            if ($postedAction === 'create') {
                $classCode = generate_class_code($pdo);
                $insertStmt = $pdo->prepare(
                    'INSERT INTO classes
                        (instructor_id, class_code, class_name, section, subject_code, subject_name, schedule, description, school_year, term, status)
                     VALUES
                        (:instructor_id, :class_code, :class_name, :section, :subject_code, :subject_name, :schedule, :description, :school_year, :term, :status)'
                );
                $insertStmt->execute([
                    ':instructor_id' => $instructorId,
                    ':class_code' => $classCode,
                    ':class_name' => $formData['class_name'],
                    ':section' => $formData['section'] !== '' ? $formData['section'] : null,
                    ':subject_code' => $formData['subject_code'] !== '' ? $formData['subject_code'] : null,
                    ':subject_name' => $formData['subject_name'],
                    ':schedule' => $formData['schedule'] !== '' ? $formData['schedule'] : null,
                    ':description' => $formData['description'] !== '' ? $formData['description'] : null,
                    ':school_year' => $formData['school_year'] !== '' ? $formData['school_year'] : null,
                    ':term' => $formData['term'] !== '' ? $formData['term'] : null,
                    ':status' => 'active',
                ]);
                $newClassId = (int) $pdo->lastInsertId();
                save_class_teaching_schedule($pdo, $newClassId, $scheduleData);
                regenerate_class_meetings($pdo, $newClassId);

                foreach ($assessmentTotals as $assessType => $assessTotal) {
                    if ($assessTotal !== null) {
                        save_assessment_total($pdo, $newClassId, $assessType, $assessTotal);
                        sync_assessment_items($pdo, $newClassId, $assessType, $assessTotal);
                    }
                }

                rotate_csrf_token('csrf_class_manage_token');
                $_SESSION['class_success'] = 'Class created successfully. Students can now join using code ' . $classCode . '.';

                redirect_to('pages/instructor/classes.php');
            } elseif ($postedAction === 'edit') {
                $editTargetClassId = $classId;

                if (!instructor_class_exists($pdo, $instructorId, $classId, 'active')) {
                    $errors[] = 'Class not found or no longer active.';
                } else {
                    $updateStmt = $pdo->prepare(
                        'UPDATE classes
                         SET class_name = :class_name,
                             section = :section,
                             subject_code = :subject_code,
                             subject_name = :subject_name,
                             schedule = :schedule,
                             description = :description,
                             school_year = :school_year,
                             term = :term
                         WHERE id = :class_id AND instructor_id = :instructor_id AND status = "active"'
                    );
                    $updateStmt->execute([
                        ':class_name' => $formData['class_name'],
                        ':section' => $formData['section'] !== '' ? $formData['section'] : null,
                        ':subject_code' => $formData['subject_code'] !== '' ? $formData['subject_code'] : null,
                        ':subject_name' => $formData['subject_name'],
                        ':schedule' => $formData['schedule'] !== '' ? $formData['schedule'] : null,
                        ':description' => $formData['description'] !== '' ? $formData['description'] : null,
                        ':school_year' => $formData['school_year'] !== '' ? $formData['school_year'] : null,
                        ':term' => $formData['term'] !== '' ? $formData['term'] : null,
                        ':class_id' => $classId,
                        ':instructor_id' => $instructorId,
                    ]);
                    save_class_teaching_schedule($pdo, $classId, $scheduleData);
                    regenerate_class_meetings($pdo, $classId);

                    $keptGradedNote = '';
                    foreach ($assessmentTotals as $assessType => $assessTotal) {
                        // Blank totals leave the existing configuration unchanged.
                        if ($assessTotal !== null) {
                            save_assessment_total($pdo, $classId, $assessType, $assessTotal);
                            $syncResult = sync_assessment_items($pdo, $classId, $assessType, $assessTotal);

                            if ($syncResult['kept_graded'] > 0) {
                                $keptGradedNote .= ' ' . $syncResult['kept_graded'] . ' graded '
                                    . strtolower($syncResult['kept_graded'] === 1 ? assessment_types()[$assessType]['label'] : assessment_types()[$assessType]['plural'])
                                    . ' with recorded scores ' . ($syncResult['kept_graded'] === 1 ? 'was' : 'were') . ' kept beyond the new total.';
                            }
                        }
                    }

                    rotate_csrf_token('csrf_class_manage_token');
                    $_SESSION['class_success'] = 'Class updated successfully.' . $keptGradedNote;

                    redirect_to('pages/instructor/classes.php');
                }
            } elseif ($postedAction === 'archive') {
                if (!instructor_class_exists($pdo, $instructorId, $classId, 'active')) {
                    $errors[] = 'Class not found or no longer active.';
                } else {
                    update_instructor_class_status($pdo, $instructorId, $classId, 'archived');
                    rotate_csrf_token('csrf_class_manage_token');
                    $_SESSION['class_success'] = 'Class archived successfully.';

                    redirect_to('pages/instructor/classes.php');
                }
            } elseif ($postedAction === 'delete') {
                if (!instructor_class_exists($pdo, $instructorId, $classId)) {
                    $errors[] = 'Class not found.';
                } else {
                    delete_instructor_class($pdo, $instructorId, $classId);
                    rotate_csrf_token('csrf_class_manage_token');
                    $_SESSION['class_success'] = 'Class deleted successfully.';

                    redirect_to('pages/instructor/classes.php');
                }
            } elseif ($postedAction === 'meeting_add') {
                if (!instructor_class_exists($pdo, $instructorId, $classId, 'active')) {
                    $errors[] = 'Class not found or no longer active.';
                } else {
                    $newMeetingId = save_class_meeting($pdo, $classId, $meetingData);
                    rotate_csrf_token('csrf_class_manage_token');
                    $_SESSION['class_success'] = 'Meeting added successfully.';

                    redirect_to('classes/' . $classId . '/attendance?meeting_id=' . $newMeetingId);
                }
            } elseif ($postedAction === 'meeting_save') {
                $meeting = meeting_belongs_to_instructor($pdo, $meetingId, $instructorId);

                if (!$meeting || (int) $meeting['class_id'] !== $classId) {
                    $errors[] = 'Meeting not found.';
                } else {
                    save_class_meeting($pdo, $classId, $meetingData, $meetingId);
                    rotate_csrf_token('csrf_class_manage_token');
                    $_SESSION['class_success'] = 'Meeting updated successfully.';

                    redirect_to('classes/' . $classId . '/attendance?meeting_id=' . $meetingId);
                }
            } elseif ($postedAction === 'attendance_save') {
                $meeting = meeting_belongs_to_instructor($pdo, $meetingId, $instructorId);

                if (!$meeting) {
                    $errors[] = 'Meeting not found.';
                } else {
                    save_attendance_records($pdo, $meetingId, $_POST['attendance'] ?? [], (int) $meeting['class_id']);
                    rotate_csrf_token('csrf_class_manage_token');
                    $_SESSION['class_success'] = 'Attendance saved successfully.';

                    redirect_to('classes/' . (int) $meeting['class_id'] . '/attendance?meeting_id=' . $meetingId);
                }
            } elseif ($postedAction === 'participation_save') {
                $meeting = meeting_belongs_to_instructor($pdo, $meetingId, $instructorId);

                if (!$meeting) {
                    $errors[] = 'Meeting not found.';
                } else {
                    save_participation_records(
                        $pdo,
                        $meetingId,
                        $_POST['participation'] ?? [],
                        $_POST['participation_remarks'] ?? [],
                        (int) $meeting['class_id']
                    );
                    rotate_csrf_token('csrf_class_manage_token');
                    $_SESSION['class_success'] = 'Participation saved successfully.';

                    redirect_to('classes/' . (int) $meeting['class_id'] . '/participation?meeting_id=' . $meetingId);
                }
            } elseif ($postedAction === 'assessment_configure') {
                if (!instructor_class_exists($pdo, $instructorId, $classId, 'active')) {
                    $errors[] = 'Class not found or no longer active.';
                } else {
                    $typeMeta = assessment_types()[$assessmentType];
                    save_assessment_total($pdo, $classId, $assessmentType, $configureTotal);
                    $syncResult = sync_assessment_items($pdo, $classId, $assessmentType, $configureTotal);

                    rotate_csrf_token('csrf_class_manage_token');
                    $_SESSION['class_success'] = $typeMeta['plural'] . ' configured successfully.'
                        . ($syncResult['added'] > 0 ? ' ' . $syncResult['added'] . ' item' . ($syncResult['added'] === 1 ? '' : 's') . ' generated.' : '');

                    redirect_to('classes/' . $classId . '/' . $typeMeta['view']);
                }
            } elseif ($postedAction === 'assessment_item_save') {
                $assessmentItem = assessment_item_belongs_to_instructor($pdo, $assessmentItemId, $instructorId);

                if (!$assessmentItem) {
                    $errors[] = 'Item not found.';
                } else {
                    $itemClassId = (int) $assessmentItem['class_id'];
                    $itemType = (string) $assessmentItem['type'];
                    $groupingRef = null;

                    // Group activity may reference an existing class grouping or create a new
                    // activity-specific grouping (which never overwrites class groupings).
                    // A grouping is OPTIONAL at setup: title/date/max still save (item becomes
                    // ready), and the grouping can be selected or created later on the grading
                    // screen. Any existing grouping is preserved unless the instructor changes it.
                    if ($itemType === 'activity' && $assessmentItemMode === 'group') {
                        $groupingSource = (string) ($_POST['item_grouping_source'] ?? 'existing');
                        $groupingRef = !empty($assessmentItem['grouping_id']) ? (int) $assessmentItem['grouping_id'] : null;

                        if ($groupingSource === 'new') {
                            [$agConfig, $agErrors] = grouping_config_from_input($_POST);
                            if (!empty($agErrors)) {
                                $errors = array_merge($errors, $agErrors);
                            } else {
                                $students = get_class_enrolled_students($pdo, $itemClassId);
                                $students = array_map(static fn ($s) => ['id' => (int) $s['id'], 'name' => (string) $s['student_name']], $students);
                                $perf = get_student_performance_map($pdo, $itemClassId);
                                $generated = generate_grouping($students, $agConfig['method'], $agConfig['size_by'], $agConfig['size_value'], $agConfig['assign_leaders'], $perf);
                                $groupingRef = create_grouping($pdo, $itemClassId, $agConfig['name'], $agConfig['method'], 'activity', $assessmentItemId);
                                save_grouping_structure($pdo, $groupingRef, $itemClassId, $generated);
                            }
                        } elseif ($assessmentItemGroupingId > 0) {
                            $chosen = grouping_belongs_to_instructor($pdo, $assessmentItemGroupingId, $instructorId);
                            if ($chosen && (int) $chosen['class_id'] === $itemClassId) {
                                $groupingRef = $assessmentItemGroupingId;
                            }
                            // An invalid/blank selection keeps the preserved grouping (may be null)
                            // rather than blocking setup.
                        }
                    }

                    if (empty($errors)) {
                        save_assessment_item($pdo, $assessmentItemId, [
                            'title' => $assessmentItemTitle,
                            'max_score' => $assessmentItemMax,
                            'scheduled_date' => $assessmentItemDate,
                            'description' => $assessmentItemDescription,
                            'activity_mode' => $itemType === 'activity' ? $assessmentItemMode : 'individual',
                            'grouping_id' => $groupingRef,
                        ]);
                        delete_orphan_activity_groupings($pdo, $assessmentItemId, (int) $groupingRef);
                        $typeMeta = assessment_types()[$itemType];

                        rotate_csrf_token('csrf_class_manage_token');
                        $_SESSION['class_success'] = $typeMeta['label'] . ' updated successfully.';

                        redirect_to('classes/' . $itemClassId . '/' . $typeMeta['view']);
                    }
                }
            } elseif ($postedAction === 'assessment_grade_save') {
                $gradeItem = assessment_item_belongs_to_instructor($pdo, $gradeItemId, $instructorId);

                if (!$gradeItem) {
                    $errors[] = 'Item not found.';
                } elseif (!is_assessment_item_gradeable($gradeItem)) {
                    $errors[] = 'Complete the item setup before grading.';
                } else {
                    $itemClassId = (int) $gradeItem['class_id'];
                    $typeMeta = assessment_types()[(string) $gradeItem['type']];
                    $skipped = save_assessment_scores($pdo, $gradeItemId, $itemClassId, (float) $gradeItem['max_score'], $_POST['score'] ?? []);

                    rotate_csrf_token('csrf_class_manage_token');
                    $_SESSION['class_success'] = 'Grades saved successfully.'
                        . ($skipped > 0 ? ' ' . $skipped . ' entr' . ($skipped === 1 ? 'y was' : 'ies were') . ' skipped for being out of range.' : '');

                    redirect_to('classes/' . $itemClassId . '/' . $typeMeta['view'] . '?item=' . $gradeItemId);
                }
            } elseif ($postedAction === 'assessment_assign_grouping') {
                $assignItem = assessment_item_belongs_to_instructor($pdo, $assignItemId, $instructorId);

                if (!$assignItem || (string) $assignItem['type'] !== 'activity' || (string) $assignItem['activity_mode'] !== 'group') {
                    $errors[] = 'Group activity not found.';
                } else {
                    $itemClassId = (int) $assignItem['class_id'];
                    $assignedGroupingId = 0;

                    if ($assignSource === 'new') {
                        $students = get_class_enrolled_students($pdo, $itemClassId);
                        $students = array_map(static fn ($s) => ['id' => (int) $s['id'], 'name' => (string) $s['student_name']], $students);
                        $perf = get_student_performance_map($pdo, $itemClassId);
                        $generated = generate_grouping($students, $assignConfig['method'], $assignConfig['size_by'], $assignConfig['size_value'], $assignConfig['assign_leaders'], $perf);
                        $assignedGroupingId = create_grouping($pdo, $itemClassId, $assignConfig['name'], $assignConfig['method'], 'activity', $assignItemId);
                        save_grouping_structure($pdo, $assignedGroupingId, $itemClassId, $generated);
                    } else {
                        $chosen = grouping_belongs_to_instructor($pdo, $assignGroupingId, $instructorId);
                        if (!$chosen || (int) $chosen['class_id'] !== $itemClassId) {
                            $errors[] = 'The selected grouping is not valid for this class.';
                        } else {
                            $assignedGroupingId = $assignGroupingId;
                        }
                    }

                    if (empty($errors)) {
                        set_assessment_item_grouping($pdo, $assignItemId, $assignedGroupingId);
                        delete_orphan_activity_groupings($pdo, $assignItemId, $assignedGroupingId);
                        rotate_csrf_token('csrf_class_manage_token');
                        $_SESSION['class_success'] = 'Grouping assigned. You can now grade this group activity.';

                        redirect_to('classes/' . $itemClassId . '/activities?item=' . $assignItemId);
                    }
                }
            } elseif ($postedAction === 'grouping_create') {
                if (!instructor_class_exists($pdo, $instructorId, $classId, 'active')) {
                    $errors[] = 'Class not found or no longer active.';
                } else {
                    $students = get_class_enrolled_students($pdo, $classId);
                    $students = array_map(static fn ($s) => ['id' => (int) $s['id'], 'name' => (string) $s['student_name']], $students);
                    $perf = get_student_performance_map($pdo, $classId);
                    $generated = generate_grouping($students, $groupingConfig['method'], $groupingConfig['size_by'], $groupingConfig['size_value'], $groupingConfig['assign_leaders'], $perf);
                    $newGroupingId = create_grouping($pdo, $classId, $groupingConfig['name'], $groupingConfig['method'], 'class');
                    save_grouping_structure($pdo, $newGroupingId, $classId, $generated);

                    rotate_csrf_token('csrf_class_manage_token');
                    $_SESSION['class_success'] = 'Grouping "' . $groupingConfig['name'] . '" created.';

                    redirect_to('classes/' . $classId . '/groupings?grouping=' . $newGroupingId);
                }
            } elseif ($postedAction === 'grouping_save') {
                $grouping = grouping_belongs_to_instructor($pdo, $groupingId, $instructorId);

                if (!$grouping || (int) $grouping['class_id'] !== $classId) {
                    $errors[] = 'Grouping not found.';
                } else {
                    $groupingName = trim((string) ($_POST['grouping_name'] ?? ''));
                    if ($groupingName !== '') {
                        rename_grouping($pdo, $groupingId, $groupingName);
                    }

                    // Rebuild group structure from posted per-student assignments.
                    $groupNames = (array) ($_POST['group_name'] ?? []);
                    $groupLeaders = (array) ($_POST['group_leader'] ?? []);
                    $assignments = (array) ($_POST['student_group'] ?? []);

                    $structure = [];
                    foreach ($groupNames as $slot => $name) {
                        $structure[(int) $slot] = [
                            'name' => (string) $name,
                            'leader' => (int) ($groupLeaders[$slot] ?? 0),
                            'members' => [],
                        ];
                    }
                    foreach ($assignments as $studentId => $slot) {
                        $slot = (int) $slot;
                        if (isset($structure[$slot])) {
                            $structure[$slot]['members'][] = (int) $studentId;
                        }
                    }

                    save_grouping_structure($pdo, $groupingId, $classId, array_values($structure));

                    rotate_csrf_token('csrf_class_manage_token');
                    $_SESSION['class_success'] = 'Grouping updated successfully.';

                    redirect_to('classes/' . $classId . '/groupings?grouping=' . $groupingId);
                }
            } elseif ($postedAction === 'grouping_delete') {
                $grouping = grouping_belongs_to_instructor($pdo, $groupingId, $instructorId);

                if (!$grouping || (int) $grouping['class_id'] !== $classId) {
                    $errors[] = 'Grouping not found.';
                } else {
                    delete_grouping($pdo, $groupingId);
                    rotate_csrf_token('csrf_class_manage_token');
                    $_SESSION['class_success'] = 'Grouping deleted.';

                    redirect_to('classes/' . $classId . '/groupings');
                }
            } else {
                $errors[] = 'Class action is invalid.';
            }
        } catch (PDOException $e) {
            error_log('[EDUPREDICT CLASS MANAGEMENT ERROR] ' . $e->getMessage());
            $errors[] = 'Unable to update the class at this time. Please try again later.';
        }
    }

    if (!empty($errors)) {
        if ($postedAction === 'create') {
            $autoOpenModal = '#createClassModal';
        } elseif ($postedAction === 'edit' && $classId > 0 && instructor_class_exists($pdo, $instructorId, $classId, 'active')) {
            $editTargetClassId = $classId;
            $autoOpenModal = '#editClassModal-' . $classId;
        }
    }

    $csrfToken = csrf_token('csrf_class_manage_token');
}

$searchTerm = trim((string) ($_GET['q'] ?? ''));
$baseClassSelect = 'SELECT
        c.id,
        c.class_code,
        c.class_name,
        c.section,
        c.subject_code,
        c.subject_name,
        c.schedule,
        c.school_year,
        c.term,
        c.description,
        c.status,
        c.created_at,
        (
            SELECT COUNT(*)
            FROM class_enrollments ce
            WHERE ce.class_id = c.id AND ce.status = "active"
        ) AS student_count
     FROM classes c
     WHERE c.instructor_id = :instructor_id AND c.status = "active"';

$allClassesStmt = $pdo->prepare($baseClassSelect . ' ORDER BY c.created_at DESC');
$allClassesStmt->execute([':instructor_id' => $instructorId]);
$allClasses = $allClassesStmt->fetchAll();

$classes = $allClasses;

if ($searchTerm !== '') {
    $classesStmt = $pdo->prepare(
        $baseClassSelect . '
         AND (
            c.class_name LIKE :search
            OR c.section LIKE :search
            OR c.subject_name LIKE :search
            OR c.subject_code LIKE :search
            OR c.class_code LIKE :search
         )
         ORDER BY c.created_at DESC'
    );
    $classesStmt->execute([
        ':instructor_id' => $instructorId,
        ':search' => '%' . $searchTerm . '%',
    ]);
    $classes = $classesStmt->fetchAll();
}

$origin = 'http';
if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
    $origin = 'https';
}
$host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
$baseJoinUrl = $origin . '://' . $host . url_for('pages/student/classes.php?code=');

$requestedClassId = isset($_GET['class_id']) ? (int) $_GET['class_id'] : 0;
$classView = instructor_normalize_class_view((string) ($_GET['view'] ?? 'overview'));
$selectedClass = null;

if ($requestedClassId > 0) {
    foreach ($allClasses as $class) {
        if ((int) $class['id'] === $requestedClassId) {
            $selectedClass = $class;
            break;
        }
    }

    if ($selectedClass === null) {
        redirect_to('pages/instructor/classes.php');
    }
}

$selectedClassId = $selectedClass !== null ? (int) $selectedClass['id'] : null;
$classViewMeta = instructor_class_view_meta($classView);
$activeRoute = $selectedClass !== null ? (string) $classViewMeta['route'] : 'classes';
$pageTitle = 'Classes';
$pageEyebrow = 'Classroom setup';
$pageDescription = 'Create classroom spaces and share a join code or invite link with students.';

if ($selectedClass !== null) {
    $classLabel = instructor_class_label($selectedClass);
    $pageTitle = $classView === 'overview' ? $classLabel : (string) $classViewMeta['label'] . ' - ' . $classLabel;
    $pageEyebrow = 'Class workspace';
    $pageDescription = (string) $classViewMeta['description'];
}

$menu = instructor_sidebar_menu($allClasses, $selectedClassId);

function render_teaching_schedule_fields(array $scheduleData, string $idPrefix): void
{
    $weekdays = attendance_weekdays();
    $slots = $scheduleData['slots'];

    if (empty($slots)) {
        $slots = attendance_schedule_defaults()['slots'];
    }
    ?>
    <div class="teaching-schedule-block">
        <div class="section-heading compact">
            <h2>Teaching Schedule</h2>
            <span>Auto-generates meetings</span>
        </div>

        <div class="form-grid">
            <div class="field">
                <label for="<?php echo e($idPrefix); ?>course_start_date" class="form-label">Course start date</label>
                <input type="date" class="form-control" id="<?php echo e($idPrefix); ?>course_start_date" name="course_start_date" value="<?php echo e((string) $scheduleData['course_start_date']); ?>" required>
            </div>
            <div class="field">
                <label for="<?php echo e($idPrefix); ?>semester_weeks" class="form-label">Semester length</label>
                <input type="number" class="form-control" id="<?php echo e($idPrefix); ?>semester_weeks" name="semester_weeks" value="<?php echo e((string) $scheduleData['semester_weeks']); ?>" min="1" max="30" step="1" required>
            </div>
            <div class="field">
                <label for="<?php echo e($idPrefix); ?>meetings_per_week" class="form-label">Meetings per week</label>
                <input type="number" class="form-control" id="<?php echo e($idPrefix); ?>meetings_per_week" name="meetings_per_week" value="<?php echo e((string) $scheduleData['meetings_per_week']); ?>" min="1" max="7" step="1" data-meetings-per-week required>
            </div>
        </div>

        <div class="schedule-slot-list" data-schedule-slot-list>
            <?php foreach ($slots as $slot): ?>
                <div class="schedule-slot-row" data-schedule-slot>
                    <div class="field">
                        <label class="form-label">Day of week</label>
                        <select class="form-control" name="meeting_day[]">
                            <?php foreach ($weekdays as $dayValue => $dayLabel): ?>
                                <option value="<?php echo e((string) $dayValue); ?>" <?php echo (int) $slot['day_of_week'] === $dayValue ? 'selected' : ''; ?>><?php echo e($dayLabel); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="field">
                        <label class="form-label">Meeting type</label>
                        <input type="text" class="form-control" name="meeting_type[]" value="<?php echo e((string) $slot['meeting_type']); ?>" maxlength="80" placeholder="Lecture">
                    </div>
                    <button class="btn btn-copy btn-danger-soft" type="button" data-remove-schedule-slot aria-label="Remove meeting"><i class="bi bi-trash"></i></button>
                </div>
            <?php endforeach; ?>
        </div>

        <button class="btn btn-copy" type="button" data-add-schedule-slot><i class="bi bi-plus-circle"></i> Add weekly meeting</button>
    </div>
    <?php
}

function render_assessment_config_fields(string $activityValue, string $quizValue, string $idPrefix): void
{
    ?>
    <div class="teaching-schedule-block">
        <div class="section-heading compact">
            <h2>Activities &amp; Quizzes</h2>
            <span>Optional - can be set later</span>
        </div>

        <div class="form-grid">
            <div class="field">
                <label for="<?php echo e($idPrefix); ?>total_activities" class="form-label">Total activities</label>
                <input type="number" class="form-control" id="<?php echo e($idPrefix); ?>total_activities" name="total_activities" value="<?php echo e($activityValue); ?>" min="1" max="200" step="1" placeholder="Leave blank to set later">
            </div>
            <div class="field">
                <label for="<?php echo e($idPrefix); ?>total_quizzes" class="form-label">Total quizzes</label>
                <input type="number" class="form-control" id="<?php echo e($idPrefix); ?>total_quizzes" name="total_quizzes" value="<?php echo e($quizValue); ?>" min="1" max="200" step="1" placeholder="Leave blank to set later">
            </div>
        </div>
        <p class="participation-hint"><i class="bi bi-info-circle"></i> Items are generated automatically (Activity 1&hellip;N, Quiz 1&hellip;N) and can be renamed later. Graded items are never removed automatically.</p>
    </div>
    <?php
}

function render_meeting_form_fields(array $meeting = []): void
{
    $statuses = attendance_statuses();
    $meetingDate = (string) ($meeting['meeting_date'] ?? date('Y-m-d'));
    $meetingType = (string) ($meeting['meeting_type'] ?? 'Lecture');
    $meetingStatus = (string) ($meeting['status'] ?? 'regular');
    $topic = (string) ($meeting['topic'] ?? '');
    ?>
    <div class="form-grid">
        <div class="field">
            <label class="form-label">Date</label>
            <input type="date" class="form-control" name="meeting_date" value="<?php echo e($meetingDate); ?>" required>
        </div>
        <div class="field">
            <label class="form-label">Meeting type</label>
            <input type="text" class="form-control" name="meeting_type" value="<?php echo e($meetingType); ?>" maxlength="80" required>
        </div>
        <div class="field">
            <label class="form-label">Status</label>
            <select class="form-control" name="meeting_status">
                <?php foreach ($statuses as $statusKey => $statusLabel): ?>
                    <option value="<?php echo e($statusKey); ?>" <?php echo $meetingStatus === $statusKey ? 'selected' : ''; ?>><?php echo e($statusLabel); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field field-wide">
            <label class="form-label">Topic</label>
            <input type="text" class="form-control" name="topic" value="<?php echo e($topic); ?>" maxlength="255" placeholder="Optional topic">
        </div>
    </div>
    <?php
}

function student_search_terms(array $student): string
{
    return trim(mb_strtolower((string) ($student['student_name'] ?? '') . ' ' . (string) ($student['student_no'] ?? '')));
}

function render_student_search_bar(string $placeholder = 'Search by name or ID'): void
{
    ?>
    <div class="student-search-bar">
        <div class="search-input-wrap">
            <i class="bi bi-search"></i>
            <input type="text" class="form-control" data-student-search placeholder="<?php echo e($placeholder); ?>" aria-label="Search students by name or ID" autocomplete="off">
        </div>
    </div>
    <?php
}

function render_student_search_empty(): void
{
    ?>
    <div class="student-search-empty" data-student-search-empty hidden>
        <i class="bi bi-search"></i> No students found.
    </div>
    <?php
}

function render_attendance_workspace(PDO $pdo, int $instructorId, array $class, string $csrfToken, array $errors, string $successMessage): void
{
    $classId = (int) $class['id'];
    $schedule = get_class_teaching_schedule($pdo, $classId);
    $meetings = get_class_meetings($pdo, $classId);

    if (empty($meetings)) {
        regenerate_class_meetings($pdo, $classId);
        $meetings = get_class_meetings($pdo, $classId);
    }

    $summary = get_attendance_summary($pdo, $classId);
    $selectedMeetingId = (int) ($_GET['meeting_id'] ?? 0);
    $selectedMeeting = null;
    $meetingSelectionNotice = '';

    foreach ($meetings as $meeting) {
        if ((int) $meeting['id'] === $selectedMeetingId) {
            $selectedMeeting = $meeting;
            break;
        }
    }

    if ($selectedMeeting === null && $selectedMeetingId > 0 && !empty($meetings)) {
        $selectedMeeting = $meetings[0];
        $meetingSelectionNotice = 'The selected meeting was refreshed. Showing the first available meeting instead.';
    }

    $students = $selectedMeeting ? get_enrolled_students_with_attendance($pdo, $classId, (int) $selectedMeeting['id']) : [];
    $recordStatusMeta = attendance_record_status_meta();
    $visualStateMeta = meeting_visual_state_meta();

    $meetingsByWeek = [];
    foreach ($meetings as $meeting) {
        $meetingsByWeek[(int) $meeting['week_number']][] = $meeting;
    }

    $weekKeys = array_keys($meetingsByWeek);
    $currentWeek = attendance_current_week_number($meetings);
    $currentWeekIndex = array_search($currentWeek, $weekKeys, true);
    $currentWeekIndex = $currentWeekIndex === false ? 0 : $currentWeekIndex;
    $lastWeekIndex = max(0, count($weekKeys) - 1);
    $visibleStart = max(0, $currentWeekIndex - 1);
    $visibleEnd = min($lastWeekIndex, $currentWeekIndex + 1);

    if ($selectedMeeting !== null) {
        $selectedWeekIndex = array_search((int) $selectedMeeting['week_number'], $weekKeys, true);

        if ($selectedWeekIndex !== false) {
            $visibleStart = min($visibleStart, $selectedWeekIndex);
            $visibleEnd = max($visibleEnd, $selectedWeekIndex);
        }
    }

    $attentionMeetings = get_meetings_needing_attention($meetings);

    $allEnrolledStudents = get_class_enrolled_students($pdo, $classId);
    $attendanceMatrix = get_class_attendance_matrix($pdo, $classId);
    $studentOverview = build_student_attendance_overview($allEnrolledStudents, $meetings, $attendanceMatrix);
    ?>
    <section class="attendance-layout mt-4">
        <div class="attendance-main">
            <?php if ($successMessage !== ''): ?>
                <div class="alert alert-success" role="alert"><?php echo e($successMessage); ?></div>
            <?php endif; ?>

            <?php if (!empty($errors)): ?>
                <div class="alert alert-danger" role="alert">
                    <ul class="mb-0">
                        <?php foreach ($errors as $error): ?>
                            <li><?php echo e($error); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <?php if ($meetingSelectionNotice !== ''): ?>
                <div class="alert alert-warning" role="alert"><?php echo e($meetingSelectionNotice); ?></div>
            <?php endif; ?>

            <div class="section-heading compact">
                <h2>Attendance Overview</h2>
                <button class="btn btn-copy" type="button" data-bs-toggle="modal" data-bs-target="#studentAttendanceModal" data-reset-student-modal>
                    <i class="bi bi-people"></i> View Student Attendance
                </button>
            </div>

            <?php if (!empty($attentionMeetings)): ?>
                <div class="attention-panel">
                    <div class="attention-panel-header">
                        <i class="bi bi-exclamation-triangle-fill"></i>
                        <span><?php echo count($attentionMeetings); ?> past meeting<?php echo count($attentionMeetings) === 1 ? '' : 's'; ?> still need attendance recorded</span>
                    </div>
                    <div class="attention-list">
                        <?php foreach ($attentionMeetings as $meeting): ?>
                            <a class="attention-item" href="<?php echo e(instructor_class_route($classId, 'attendance') . '?meeting_id=' . (int) $meeting['id']); ?>">
                                <span>
                                    <strong><?php echo e(date('M j, Y', strtotime((string) $meeting['meeting_date']))); ?></strong>
                                    <em>Week <?php echo e((string) $meeting['week_number']); ?> &bull; <?php echo e($meeting['meeting_type']); ?><?php echo !empty($meeting['topic']) ? ' &middot; ' . e($meeting['topic']) : ''; ?></em>
                                </span>
                                <span class="meeting-status-badge is-pending"><i class="bi bi-exclamation-circle-fill"></i> Pending</span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>

            <div class="metric-grid attendance-metrics">
                <article class="metric-card tone-emerald">
                    <div class="metric-icon"><i class="bi bi-percent"></i></div>
                    <div>
                        <div class="metric-label">Attendance rate</div>
                        <div class="metric-value"><?php echo e((string) $summary['attendance_rate']); ?>%</div>
                        <div class="metric-note">Present, late, and excused over saved records.</div>
                    </div>
                </article>
                <article class="metric-card">
                    <div class="metric-icon"><i class="bi bi-calendar2-week"></i></div>
                    <div>
                        <div class="metric-label">Total planned meetings</div>
                        <div class="metric-value"><?php echo e((string) $summary['total_planned']); ?></div>
                        <div class="metric-note"><?php echo e((string) $schedule['semester_weeks']); ?> weeks planned.</div>
                    </div>
                </article>
                <article class="metric-card tone-indigo">
                    <div class="metric-icon"><i class="bi bi-check2-square"></i></div>
                    <div>
                        <div class="metric-label">Counted meetings</div>
                        <div class="metric-value"><?php echo e((string) $summary['counted']); ?></div>
                        <div class="metric-note">Regular meetings only.</div>
                    </div>
                </article>
                <article class="metric-card tone-amber">
                    <div class="metric-icon"><i class="bi bi-check-circle"></i></div>
                    <div>
                        <div class="metric-label">Completed meetings</div>
                        <div class="metric-value"><?php echo e((string) $summary['completed']); ?></div>
                        <div class="metric-note">Regular meetings with saved sheets.</div>
                    </div>
                </article>
                <article class="metric-card">
                    <div class="metric-icon"><i class="bi bi-hourglass-split"></i></div>
                    <div>
                        <div class="metric-label">Remaining meetings</div>
                        <div class="metric-value"><?php echo e((string) $summary['remaining']); ?></div>
                        <div class="metric-note">Counted meetings still unsaved.</div>
                    </div>
                </article>
            </div>

            <section class="class-section mt-4">
                <div class="section-heading">
                    <h2>Generated Meeting Schedule</h2>
                    <button class="btn btn-edupredict" type="button" data-bs-toggle="modal" data-bs-target="#addMeetingModal">
                        <i class="bi bi-plus-circle"></i> Add meeting
                    </button>
                </div>

                <?php if (empty($meetingsByWeek)): ?>
                    <div class="empty-state large">
                        <i class="bi bi-calendar2-week"></i>
                        <span>No meetings generated yet. Update the teaching schedule to generate planned meetings.</span>
                    </div>
                <?php else: ?>
                    <div class="meeting-week-nav">
                        <button class="btn btn-copy" type="button" data-load-previous-weeks><i class="bi bi-chevron-up"></i> Load previous weeks</button>
                        <span class="meeting-week-range-label" data-week-range-label></span>
                        <button class="btn btn-copy" type="button" data-load-more-weeks>Load more weeks <i class="bi bi-chevron-down"></i></button>
                    </div>
                    <div class="meeting-week-list" data-meeting-weeks data-visible-start="<?php echo e((string) $visibleStart); ?>" data-visible-end="<?php echo e((string) $visibleEnd); ?>">
                        <?php foreach (array_values($meetingsByWeek) as $weekIndex => $weekMeetings): ?>
                            <?php $weekNumber = (int) $weekMeetings[0]['week_number']; ?>
                            <div class="meeting-week" data-week-index="<?php echo e((string) $weekIndex); ?>" data-week-number="<?php echo e((string) $weekNumber); ?>">
                                <h3>
                                    Week <?php echo e((string) $weekNumber); ?>
                                    <?php if ($weekNumber === $currentWeek): ?><span class="current-week-flag">Current</span><?php endif; ?>
                                </h3>
                                <div class="meeting-list">
                                    <?php foreach ($weekMeetings as $meeting): ?>
                                        <?php
                                        $isSelected = $selectedMeeting && (int) $selectedMeeting['id'] === (int) $meeting['id'];
                                        $dateLabel = date('M j', strtotime((string) $meeting['meeting_date']));
                                        $statusLabel = attendance_statuses()[$meeting['status']] ?? 'Regular';
                                        $visualState = meeting_visual_state($meeting);
                                        $visualMeta = $visualStateMeta[$visualState];
                                        $isInactiveMeeting = in_array($visualState, ['holiday', 'cancelled'], true);
                                        ?>
                                        <article class="meeting-item <?php echo $isSelected ? 'active' : ''; ?> <?php echo $isInactiveMeeting ? 'is-inactive' : ''; ?>">
                                            <div>
                                                <strong><?php echo e($dateLabel); ?> &bull; <?php echo e($meeting['meeting_type']); ?></strong>
                                                <span>
                                                    <?php echo e($statusLabel); ?><?php echo !empty($meeting['topic']) ? ' &middot; ' . e($meeting['topic']) : ''; ?>
                                                </span>
                                                <span class="meeting-status-badge is-<?php echo e($visualState); ?>">
                                                    <i class="bi <?php echo e($visualMeta['icon']); ?>"></i> <?php echo e($visualMeta['label']); ?>
                                                </span>
                                            </div>
                                            <div class="meeting-actions">
                                                <a class="btn btn-copy" href="<?php echo e(instructor_class_route($classId, 'attendance') . '?meeting_id=' . (int) $meeting['id']); ?>"><i class="bi bi-card-checklist"></i> Sheet</a>
                                                <button class="btn btn-copy" type="button" data-bs-toggle="modal" data-bs-target="#editMeetingModal-<?php echo e((string) $meeting['id']); ?>"><i class="bi bi-pencil-square"></i> Edit</button>
                                            </div>
                                        </article>

                                        <div class="modal fade" id="editMeetingModal-<?php echo e((string) $meeting['id']); ?>" tabindex="-1" aria-labelledby="editMeetingModalLabel-<?php echo e((string) $meeting['id']); ?>" aria-hidden="true">
                                            <div class="modal-dialog modal-lg modal-dialog-scrollable">
                                                <div class="modal-content">
                                                    <div class="modal-header">
                                                        <div>
                                                            <h2 class="modal-title h5" id="editMeetingModalLabel-<?php echo e((string) $meeting['id']); ?>">Edit Meeting</h2>
                                                            <p class="mb-0 text-secondary small">Changes affect this meeting only and keep existing attendance records attached.</p>
                                                        </div>
                                                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                                    </div>
                                                    <form method="post" action="<?php echo e(instructor_class_route($classId, 'attendance') . '?meeting_id=' . (int) $meeting['id']); ?>" novalidate>
                                                        <div class="modal-body">
                                                            <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                                                            <input type="hidden" name="class_action" value="meeting_save">
                                                            <input type="hidden" name="class_id" value="<?php echo e((string) $classId); ?>">
                                                            <input type="hidden" name="meeting_id" value="<?php echo e((string) $meeting['id']); ?>">
                                                            <?php render_meeting_form_fields($meeting); ?>
                                                        </div>
                                                        <div class="modal-footer">
                                                            <button type="button" class="btn btn-copy" data-bs-dismiss="modal">Cancel</button>
                                                            <button type="submit" class="btn btn-edupredict"><i class="bi bi-check2-circle"></i> Save meeting</button>
                                                        </div>
                                                    </form>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>
        </div>

        <aside class="attendance-sheet-panel">
            <?php if (!$selectedMeeting): ?>
                <div class="section-heading">
                    <h2>Attendance Sheet</h2>
                    <span>Select meeting</span>
                </div>
                <div class="empty-state large">
                    <i class="bi bi-card-checklist"></i>
                    <span>Select a meeting to take attendance.</span>
                </div>
            <?php else: ?>
                <div class="section-heading">
                    <h2><?php echo e(date('M j, Y', strtotime((string) $selectedMeeting['meeting_date']))); ?></h2>
                    <span><?php echo e($selectedMeeting['meeting_type']); ?></span>
                </div>
                <div class="attendance-sheet-meta">
                    <span><i class="bi bi-tag"></i><?php echo e(attendance_statuses()[$selectedMeeting['status']] ?? 'Regular'); ?></span>
                    <span><i class="bi bi-journal-text"></i><?php echo e($selectedMeeting['topic'] ?: 'No topic'); ?></span>
                </div>
                <form method="post" action="<?php echo e(instructor_class_route($classId, 'attendance') . '?meeting_id=' . (int) $selectedMeeting['id']); ?>" data-attendance-sheet>
                    <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                    <input type="hidden" name="class_action" value="attendance_save">
                    <input type="hidden" name="class_id" value="<?php echo e((string) $classId); ?>">
                    <input type="hidden" name="meeting_id" value="<?php echo e((string) $selectedMeeting['id']); ?>">

                    <button class="btn btn-copy w-100 mb-3" type="button" data-mark-all-present><i class="bi bi-check2-all"></i> Mark all present</button>

                    <?php if (empty($students)): ?>
                        <div class="empty-state large">
                            <i class="bi bi-people"></i>
                            <span>No enrolled students yet.</span>
                        </div>
                    <?php else: ?>
                        <div data-student-search-scope>
                            <?php render_student_search_bar(); ?>
                            <div class="attendance-student-list" data-student-search-list>
                                <?php foreach ($students as $student): ?>
                                    <?php $currentStatus = (string) $student['attendance_status']; ?>
                                    <div class="attendance-student-row" data-search-terms="<?php echo e(student_search_terms($student)); ?>">
                                        <span class="attendance-student-identity">
                                            <strong><?php echo e($student['student_name']); ?></strong>
                                            <small><?php echo e($student['student_no'] ?: 'No student number'); ?></small>
                                        </span>
                                        <span class="attendance-status-field">
                                            <select class="form-control attendance-status-select status-<?php echo e($currentStatus); ?>" name="attendance[<?php echo e((string) $student['id']); ?>]" data-attendance-status aria-label="Attendance status for <?php echo e($student['student_name']); ?>">
                                                <?php foreach ($recordStatusMeta as $statusKey => $statusMeta): ?>
                                                    <option value="<?php echo e($statusKey); ?>" <?php echo $currentStatus === $statusKey ? 'selected' : ''; ?>><?php echo e($statusMeta['label']); ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </span>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <?php render_student_search_empty(); ?>
                        </div>
                        <button class="btn btn-edupredict w-100 mt-3" type="submit"><i class="bi bi-save"></i> Save attendance</button>
                    <?php endif; ?>
                </form>
            <?php endif; ?>
        </aside>
    </section>

    <div class="modal fade" id="addMeetingModal" tabindex="-1" aria-labelledby="addMeetingModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <div>
                        <h2 class="modal-title h5" id="addMeetingModalLabel">Add Meeting</h2>
                        <p class="mb-0 text-secondary small">Extra meetings are added without changing the generated teaching schedule.</p>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="post" action="<?php echo e(instructor_class_route($classId, 'attendance')); ?>" novalidate>
                    <div class="modal-body">
                        <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                        <input type="hidden" name="class_action" value="meeting_add">
                        <input type="hidden" name="class_id" value="<?php echo e((string) $classId); ?>">
                        <?php render_meeting_form_fields(); ?>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-copy" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-edupredict"><i class="bi bi-plus-circle"></i> Add meeting</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <?php render_student_attendance_modal($classId, $studentOverview, $meetings, $attendanceMatrix, $recordStatusMeta, $visualStateMeta); ?>
    <?php
}

function render_student_attendance_modal(int $classId, array $studentOverview, array $meetings, array $attendanceMatrix, array $recordStatusMeta, array $visualStateMeta): void
{
    ?>
    <div class="modal fade" id="studentAttendanceModal" tabindex="-1" aria-labelledby="studentAttendanceModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <div>
                        <h2 class="modal-title h5" id="studentAttendanceModalLabel">Student Attendance</h2>
                        <p class="mb-0 text-secondary small" data-student-modal-subtitle>Select a student to view their complete attendance history.</p>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="student-attendance-panel is-active" data-student-panel="list">
                        <?php if (empty($studentOverview)): ?>
                            <div class="empty-state large">
                                <i class="bi bi-people"></i>
                                <span>No enrolled students yet.</span>
                            </div>
                        <?php else: ?>
                            <div data-student-search-scope>
                            <?php render_student_search_bar(); ?>
                            <div class="student-overview-list" data-student-search-list>
                                <?php foreach ($studentOverview as $student): ?>
                                    <button type="button" class="student-overview-row" data-student-select="<?php echo e((string) $student['id']); ?>" data-search-terms="<?php echo e(student_search_terms($student)); ?>">
                                        <span class="student-overview-identity">
                                            <strong><?php echo e($student['student_name']); ?></strong>
                                            <small><?php echo e($student['student_no'] ?: 'No student number'); ?></small>
                                        </span>
                                        <span class="student-overview-rate">
                                            <?php if ($student['rate'] === null): ?>
                                                <span class="status-pill small tone-slate">No records yet</span>
                                            <?php else: ?>
                                                <span class="status-pill small <?php echo $student['rate'] >= 75 ? 'tone-emerald' : ($student['rate'] >= 50 ? 'tone-amber' : 'tone-rose'); ?>"><?php echo e((string) $student['rate']); ?>% attendance</span>
                                                <small><?php echo e((string) $student['attended']); ?> / <?php echo e((string) $student['total']); ?> meetings</small>
                                            <?php endif; ?>
                                        </span>
                                        <i class="bi bi-chevron-right"></i>
                                    </button>
                                <?php endforeach; ?>
                            </div>
                            <?php render_student_search_empty(); ?>
                            </div>
                        <?php endif; ?>
                    </div>

                    <?php foreach ($studentOverview as $student): ?>
                        <?php $history = build_student_meeting_history($meetings, $attendanceMatrix, (int) $student['id']); ?>
                        <div class="student-attendance-panel" data-student-panel="detail" data-student-id="<?php echo e((string) $student['id']); ?>">
                            <button type="button" class="btn btn-copy mb-3" data-student-back><i class="bi bi-arrow-left"></i> All students</button>

                            <div class="student-detail-header">
                                <div>
                                    <strong><?php echo e($student['student_name']); ?></strong>
                                    <small><?php echo e($student['student_no'] ?: 'No student number'); ?></small>
                                </div>
                                <?php if ($student['rate'] === null): ?>
                                    <span class="status-pill tone-slate">No records yet</span>
                                <?php else: ?>
                                    <span class="status-pill <?php echo $student['rate'] >= 75 ? 'tone-emerald' : ($student['rate'] >= 50 ? 'tone-amber' : 'tone-rose'); ?>"><?php echo e((string) $student['rate']); ?>% attendance rate</span>
                                <?php endif; ?>
                            </div>

                            <?php if (empty($history)): ?>
                                <div class="empty-state large">
                                    <i class="bi bi-calendar2-week"></i>
                                    <span>No meetings recorded yet.</span>
                                </div>
                            <?php else: ?>
                                <div class="student-history-list">
                                    <?php foreach ($history as $entry): ?>
                                        <?php
                                        $meeting = $entry['meeting'];
                                        $status = $entry['status'];
                                        $dateLabel = date('M j, Y', strtotime((string) $meeting['meeting_date']));
                                        ?>
                                        <div class="student-history-row">
                                            <span>
                                                <strong><?php echo e($dateLabel); ?></strong>
                                                <small>Week <?php echo e((string) $meeting['week_number']); ?> &bull; <?php echo e($meeting['meeting_type']); ?></small>
                                            </span>
                                            <?php if ($meeting['status'] !== 'regular'): ?>
                                                <span class="meeting-status-badge is-<?php echo e((string) $meeting['status']); ?>">
                                                    <i class="bi <?php echo e($visualStateMeta[$meeting['status']]['icon'] ?? 'bi-info-circle'); ?>"></i> <?php echo e($visualStateMeta[$meeting['status']]['label'] ?? ucfirst((string) $meeting['status'])); ?>
                                                </span>
                                            <?php elseif ($status === null): ?>
                                                <span class="status-pill small tone-slate">Not recorded</span>
                                            <?php else: ?>
                                                <span class="status-pill small status-<?php echo e($status); ?>">
                                                    <i class="bi <?php echo e($recordStatusMeta[$status]['icon'] ?? 'bi-info-circle'); ?>"></i> <?php echo e($recordStatusMeta[$status]['label'] ?? ucfirst($status)); ?>
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>
    <?php
}

function render_participation_workspace(PDO $pdo, int $instructorId, array $class, string $csrfToken, array $errors, string $successMessage): void
{
    $classId = (int) $class['id'];
    $meetings = get_class_meetings($pdo, $classId);

    if (empty($meetings)) {
        regenerate_class_meetings($pdo, $classId);
        $meetings = get_class_meetings($pdo, $classId);
    }

    $participationCounts = get_participation_counts($pdo, $classId);
    foreach ($meetings as $index => $meeting) {
        $meetings[$index]['participation_count'] = $participationCounts[(int) $meeting['id']] ?? 0;
    }

    $summary = get_participation_summary($pdo, $classId);
    $maxScore = participation_max_score();
    $visualStateMeta = meeting_visual_state_meta();

    $selectedMeetingId = (int) ($_GET['meeting_id'] ?? 0);
    $selectedMeeting = null;
    $meetingSelectionNotice = '';

    foreach ($meetings as $meeting) {
        if ((int) $meeting['id'] === $selectedMeetingId) {
            $selectedMeeting = $meeting;
            break;
        }
    }

    if ($selectedMeeting === null && $selectedMeetingId > 0 && !empty($meetings)) {
        $selectedMeeting = $meetings[0];
        $meetingSelectionNotice = 'The selected meeting was refreshed. Showing the first available meeting instead.';
    }

    $students = $selectedMeeting ? get_students_with_participation($pdo, $classId, (int) $selectedMeeting['id']) : [];

    // Build the picker dataset for the selected meeting.
    $pickerStudents = [];
    foreach ($students as $student) {
        $pickerStudents[] = [
            'id' => (int) $student['id'],
            'name' => (string) $student['student_name'],
            'student_no' => (string) ($student['student_no'] ?? ''),
            'absent' => ($student['attendance_status'] ?? null) === 'absent',
            'graded' => $student['participation_score'] !== null,
        ];
    }

    $meetingsByWeek = [];
    foreach ($meetings as $meeting) {
        $meetingsByWeek[(int) $meeting['week_number']][] = $meeting;
    }

    $weekKeys = array_keys($meetingsByWeek);
    $currentWeek = attendance_current_week_number($meetings);
    $currentWeekIndex = array_search($currentWeek, $weekKeys, true);
    $currentWeekIndex = $currentWeekIndex === false ? 0 : $currentWeekIndex;
    $lastWeekIndex = max(0, count($weekKeys) - 1);
    $visibleStart = max(0, $currentWeekIndex - 1);
    $visibleEnd = min($lastWeekIndex, $currentWeekIndex + 1);

    if ($selectedMeeting !== null) {
        $selectedWeekIndex = array_search((int) $selectedMeeting['week_number'], $weekKeys, true);

        if ($selectedWeekIndex !== false) {
            $visibleStart = min($visibleStart, $selectedWeekIndex);
            $visibleEnd = max($visibleEnd, $selectedWeekIndex);
        }
    }

    $allEnrolledStudents = get_class_enrolled_students($pdo, $classId);
    $participationMatrix = get_class_participation_matrix($pdo, $classId);
    $studentOverview = build_student_participation_overview($allEnrolledStudents, $meetings, $participationMatrix);
    ?>
    <section class="attendance-layout mt-4">
        <div class="attendance-main">
            <?php if ($successMessage !== ''): ?>
                <div class="alert alert-success" role="alert"><?php echo e($successMessage); ?></div>
            <?php endif; ?>

            <?php if (!empty($errors)): ?>
                <div class="alert alert-danger" role="alert">
                    <ul class="mb-0">
                        <?php foreach ($errors as $error): ?>
                            <li><?php echo e($error); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <?php if ($meetingSelectionNotice !== ''): ?>
                <div class="alert alert-warning" role="alert"><?php echo e($meetingSelectionNotice); ?></div>
            <?php endif; ?>

            <div class="section-heading compact">
                <h2>Participation Overview</h2>
                <button class="btn btn-copy" type="button" data-bs-toggle="modal" data-bs-target="#studentParticipationModal" data-reset-student-modal>
                    <i class="bi bi-people"></i> View Student Participation
                </button>
            </div>

            <div class="metric-grid attendance-metrics">
                <article class="metric-card tone-emerald">
                    <div class="metric-icon"><i class="bi bi-star-half"></i></div>
                    <div>
                        <div class="metric-label">Average score</div>
                        <div class="metric-value"><?php echo e((string) $summary['avg_score']); ?></div>
                        <div class="metric-note">Out of <?php echo e((string) $maxScore); ?> across all records.</div>
                    </div>
                </article>
                <article class="metric-card tone-indigo">
                    <div class="metric-icon"><i class="bi bi-hand-index-thumb"></i></div>
                    <div>
                        <div class="metric-label">Total participations</div>
                        <div class="metric-value"><?php echo e((string) $summary['total_records']); ?></div>
                        <div class="metric-note">Recorded across all meetings.</div>
                    </div>
                </article>
                <article class="metric-card tone-amber">
                    <div class="metric-icon"><i class="bi bi-people"></i></div>
                    <div>
                        <div class="metric-label">Students participating</div>
                        <div class="metric-value"><?php echo e((string) $summary['coverage']); ?>%</div>
                        <div class="metric-note"><?php echo e((string) $summary['active_participants']); ?> of <?php echo e((string) $summary['enrolled']); ?> students.</div>
                    </div>
                </article>
                <article class="metric-card">
                    <div class="metric-icon"><i class="bi bi-calendar2-check"></i></div>
                    <div>
                        <div class="metric-label">Sessions recorded</div>
                        <div class="metric-value"><?php echo e((string) $summary['sessions_recorded']); ?></div>
                        <div class="metric-note">Of <?php echo e((string) $summary['counted_meetings']); ?> regular meetings.</div>
                    </div>
                </article>
                <article class="metric-card">
                    <div class="metric-icon"><i class="bi bi-hourglass-split"></i></div>
                    <div>
                        <div class="metric-label">Pending sessions</div>
                        <div class="metric-value"><?php echo e((string) max(0, (int) $summary['counted_meetings'] - (int) $summary['sessions_recorded'])); ?></div>
                        <div class="metric-note">Regular meetings without participation.</div>
                    </div>
                </article>
            </div>

            <section class="class-section mt-4">
                <div class="section-heading">
                    <h2>Participation Sessions</h2>
                    <span>Grouped by week</span>
                </div>

                <?php if (empty($meetingsByWeek)): ?>
                    <div class="empty-state large">
                        <i class="bi bi-calendar2-week"></i>
                        <span>No meetings generated yet. Update the teaching schedule to generate meetings.</span>
                    </div>
                <?php else: ?>
                    <div class="meeting-week-nav">
                        <button class="btn btn-copy" type="button" data-load-previous-weeks><i class="bi bi-chevron-up"></i> Load previous weeks</button>
                        <span class="meeting-week-range-label" data-week-range-label></span>
                        <button class="btn btn-copy" type="button" data-load-more-weeks>Load more weeks <i class="bi bi-chevron-down"></i></button>
                    </div>
                    <div class="meeting-week-list" data-meeting-weeks data-visible-start="<?php echo e((string) $visibleStart); ?>" data-visible-end="<?php echo e((string) $visibleEnd); ?>">
                        <?php foreach (array_values($meetingsByWeek) as $weekIndex => $weekMeetings): ?>
                            <?php $weekNumber = (int) $weekMeetings[0]['week_number']; ?>
                            <div class="meeting-week" data-week-index="<?php echo e((string) $weekIndex); ?>" data-week-number="<?php echo e((string) $weekNumber); ?>">
                                <h3>
                                    Week <?php echo e((string) $weekNumber); ?>
                                    <?php if ($weekNumber === $currentWeek): ?><span class="current-week-flag">Current</span><?php endif; ?>
                                </h3>
                                <div class="meeting-list">
                                    <?php foreach ($weekMeetings as $meeting): ?>
                                        <?php
                                        $isSelected = $selectedMeeting && (int) $selectedMeeting['id'] === (int) $meeting['id'];
                                        $dateLabel = date('M j', strtotime((string) $meeting['meeting_date']));
                                        $statusLabel = attendance_statuses()[$meeting['status']] ?? 'Regular';
                                        $participationCount = (int) $meeting['participation_count'];
                                        $isInactiveMeeting = in_array($meeting['status'], ['holiday', 'cancelled'], true);
                                        ?>
                                        <article class="meeting-item <?php echo $isSelected ? 'active' : ''; ?> <?php echo $isInactiveMeeting ? 'is-inactive' : ''; ?>">
                                            <div>
                                                <strong><?php echo e($dateLabel); ?> &bull; <?php echo e($meeting['meeting_type']); ?></strong>
                                                <span>
                                                    <?php echo e($statusLabel); ?><?php echo !empty($meeting['topic']) ? ' &middot; ' . e($meeting['topic']) : ''; ?>
                                                </span>
                                                <?php if ($participationCount > 0): ?>
                                                    <span class="meeting-status-badge is-completed"><i class="bi bi-check-circle-fill"></i> <?php echo e((string) $participationCount); ?> recorded</span>
                                                <?php elseif ($isInactiveMeeting): ?>
                                                    <span class="meeting-status-badge is-<?php echo e((string) $meeting['status']); ?>"><i class="bi <?php echo e($visualStateMeta[$meeting['status']]['icon']); ?>"></i> <?php echo e($visualStateMeta[$meeting['status']]['label']); ?></span>
                                                <?php elseif ((string) $meeting['meeting_date'] > date('Y-m-d')): ?>
                                                    <span class="meeting-status-badge is-upcoming"><i class="bi bi-calendar2-week"></i> Upcoming</span>
                                                <?php else: ?>
                                                    <span class="meeting-status-badge is-pending"><i class="bi bi-dash-circle"></i> None yet</span>
                                                <?php endif; ?>
                                            </div>
                                            <div class="meeting-actions">
                                                <a class="btn btn-copy" href="<?php echo e(instructor_class_route($classId, 'participation') . '?meeting_id=' . (int) $meeting['id']); ?>"><i class="bi bi-chat-square-text"></i> Sheet</a>
                                            </div>
                                        </article>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>
        </div>

        <aside class="attendance-sheet-panel">
            <?php if (!$selectedMeeting): ?>
                <div class="section-heading">
                    <h2>Participation Sheet</h2>
                    <span>Select meeting</span>
                </div>
                <div class="empty-state large">
                    <i class="bi bi-chat-square-text"></i>
                    <span>Select a meeting to record participation.</span>
                </div>
            <?php else: ?>
                <div class="section-heading">
                    <h2><?php echo e(date('M j, Y', strtotime((string) $selectedMeeting['meeting_date']))); ?></h2>
                    <span><?php echo e($selectedMeeting['meeting_type']); ?></span>
                </div>
                <div class="attendance-sheet-meta">
                    <span><i class="bi bi-tag"></i><?php echo e(attendance_statuses()[$selectedMeeting['status']] ?? 'Regular'); ?></span>
                    <span><i class="bi bi-journal-text"></i><?php echo e($selectedMeeting['topic'] ?: 'No topic'); ?></span>
                </div>

                <?php if (empty($students)): ?>
                    <div class="empty-state large">
                        <i class="bi bi-people"></i>
                        <span>No enrolled students yet.</span>
                    </div>
                <?php else: ?>
                    <button class="btn btn-edupredict w-100 mb-3" type="button" data-bs-toggle="modal" data-bs-target="#studentPickerModal">
                        <i class="bi bi-shuffle"></i> Pick Student
                    </button>

                    <form method="post" action="<?php echo e(instructor_class_route($classId, 'participation') . '?meeting_id=' . (int) $selectedMeeting['id']); ?>" data-participation-sheet>
                        <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                        <input type="hidden" name="class_action" value="participation_save">
                        <input type="hidden" name="class_id" value="<?php echo e((string) $classId); ?>">
                        <input type="hidden" name="meeting_id" value="<?php echo e((string) $selectedMeeting['id']); ?>">

                        <div data-student-search-scope>
                            <?php render_student_search_bar(); ?>
                            <div class="participation-student-list" data-student-search-list>
                                <?php foreach ($students as $student): ?>
                                    <?php
                                    $isAbsent = ($student['attendance_status'] ?? null) === 'absent';
                                    $scoreValue = $student['participation_score'] !== null ? format_score($student['participation_score']) : '';
                                    ?>
                                    <div class="participation-student-row <?php echo $isAbsent ? 'is-absent' : ''; ?>" data-participation-student="<?php echo e((string) $student['id']); ?>" data-search-terms="<?php echo e(student_search_terms($student)); ?>">
                                        <span class="participation-student-identity">
                                            <strong><?php echo e($student['student_name']); ?></strong>
                                            <small>
                                                <?php echo e($student['student_no'] ?: 'No student number'); ?>
                                                <?php if ($isAbsent): ?><span class="status-pill small status-absent">Absent</span><?php endif; ?>
                                            </small>
                                        </span>
                                        <span class="participation-score-field">
                                            <input type="number" class="form-control" name="participation[<?php echo e((string) $student['id']); ?>]" value="<?php echo e($scoreValue); ?>" min="0" max="<?php echo e((string) $maxScore); ?>" step="0.5" placeholder="—" data-participation-score aria-label="Participation score for <?php echo e($student['student_name']); ?>">
                                            <input type="hidden" name="participation_remarks[<?php echo e((string) $student['id']); ?>]" value="<?php echo e((string) ($student['participation_remarks'] ?? '')); ?>" data-participation-remark>
                                        </span>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <?php render_student_search_empty(); ?>
                        </div>
                        <p class="participation-hint"><i class="bi bi-info-circle"></i> Leave a score blank to clear that student's record. Max <?php echo e((string) $maxScore); ?>.</p>
                        <button class="btn btn-edupredict w-100 mt-2" type="submit"><i class="bi bi-save"></i> Save participation</button>
                    </form>
                <?php endif; ?>
            <?php endif; ?>
        </aside>
    </section>

    <?php if ($selectedMeeting && !empty($students)): ?>
        <?php render_student_picker_modal($selectedMeeting, $pickerStudents, $maxScore); ?>
    <?php endif; ?>

    <?php render_student_participation_modal($classId, $studentOverview, $meetings, $participationMatrix, $visualStateMeta, $maxScore); ?>
    <?php
}

function render_student_picker_modal(array $meeting, array $pickerStudents, int $maxScore): void
{
    ?>
    <div class="modal fade" id="studentPickerModal" tabindex="-1" aria-labelledby="studentPickerModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-scrollable">
            <div class="modal-content" data-student-picker data-picker-students='<?php echo e(json_encode($pickerStudents, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP)); ?>' data-picker-max="<?php echo e((string) $maxScore); ?>">
                <div class="modal-header">
                    <div>
                        <h2 class="modal-title h5" id="studentPickerModalLabel">Pick a Student</h2>
                        <p class="mb-0 text-secondary small">Random recitation picker for <?php echo e(date('M j, Y', strtotime((string) $meeting['meeting_date']))); ?>.</p>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="picker-settings">
                        <div class="picker-mode" role="radiogroup" aria-label="Picker mode">
                            <label class="picker-mode-option">
                                <input type="radio" name="picker_mode" value="random" checked data-picker-mode>
                                <span><i class="bi bi-shuffle"></i> Completely Random</span>
                            </label>
                            <label class="picker-mode-option">
                                <input type="radio" name="picker_mode" value="criteria" data-picker-mode>
                                <span><i class="bi bi-funnel"></i> Criteria-based</span>
                            </label>
                        </div>
                        <div class="picker-criteria" data-picker-criteria>
                            <label class="picker-check">
                                <input type="checkbox" data-picker-option="preventDuplicates" checked>
                                <span>Prevent duplicate picks this meeting</span>
                            </label>
                            <label class="picker-check" data-picker-criteria-only>
                                <input type="checkbox" data-picker-option="excludeAbsent">
                                <span>Exclude absent students</span>
                            </label>
                            <label class="picker-check" data-picker-criteria-only>
                                <input type="checkbox" data-picker-option="excludeGraded">
                                <span>Exclude students already graded</span>
                            </label>
                        </div>
                    </div>

                    <div class="picker-spotlight" data-picker-spotlight>
                        <div class="picker-spotlight-empty" data-picker-empty>
                            <i class="bi bi-dice-5"></i>
                            <span>Press <strong>Pick Student</strong> to choose someone.</span>
                        </div>
                        <div class="picker-spotlight-result" data-picker-result hidden>
                            <div class="picker-avatar" data-picker-avatar></div>
                            <div class="picker-selected-name" data-picker-name></div>
                            <div class="picker-selected-no" data-picker-no></div>
                            <div class="picker-mark-row">
                                <input type="number" class="form-control" min="0" max="<?php echo e((string) $maxScore); ?>" step="0.5" placeholder="Score" data-picker-score>
                                <button type="button" class="btn btn-edupredict" data-picker-mark><i class="bi bi-check2-circle"></i> Mark participation</button>
                            </div>
                            <div class="picker-mark-note" data-picker-marknote hidden></div>
                        </div>
                    </div>

                    <div class="picker-pool-note" data-picker-poolnote></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-copy" data-bs-dismiss="modal">Done</button>
                    <button type="button" class="btn btn-copy" data-picker-refresh><i class="bi bi-arrow-clockwise"></i> Refresh Picks</button>
                    <button type="button" class="btn btn-edupredict" data-picker-pick><i class="bi bi-shuffle"></i> Pick Student</button>
                </div>
            </div>
        </div>
    </div>
    <?php
}

function render_student_participation_modal(int $classId, array $studentOverview, array $meetings, array $participationMatrix, array $visualStateMeta, int $maxScore): void
{
    ?>
    <div class="modal fade" id="studentParticipationModal" tabindex="-1" aria-labelledby="studentParticipationModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <div>
                        <h2 class="modal-title h5" id="studentParticipationModalLabel">Student Participation</h2>
                        <p class="mb-0 text-secondary small">Select a student to view their full participation history.</p>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="student-attendance-panel is-active" data-student-panel="list">
                        <?php if (empty($studentOverview)): ?>
                            <div class="empty-state large">
                                <i class="bi bi-people"></i>
                                <span>No enrolled students yet.</span>
                            </div>
                        <?php else: ?>
                            <div data-student-search-scope>
                            <?php render_student_search_bar(); ?>
                            <div class="student-overview-list" data-student-search-list>
                                <?php foreach ($studentOverview as $student): ?>
                                    <button type="button" class="student-overview-row" data-student-select="<?php echo e((string) $student['id']); ?>" data-search-terms="<?php echo e(student_search_terms($student)); ?>">
                                        <span class="student-overview-identity">
                                            <strong><?php echo e($student['student_name']); ?></strong>
                                            <small><?php echo e($student['student_no'] ?: 'No student number'); ?></small>
                                        </span>
                                        <span class="student-overview-rate">
                                            <?php if ($student['average'] === null): ?>
                                                <span class="status-pill small tone-slate">No records yet</span>
                                            <?php else: ?>
                                                <span class="status-pill small tone-indigo">Avg <?php echo e((string) $student['average']); ?></span>
                                                <small><?php echo e((string) $student['times']); ?> time<?php echo $student['times'] === 1 ? '' : 's'; ?> &middot; total <?php echo e((string) (float) $student['total']); ?></small>
                                            <?php endif; ?>
                                        </span>
                                        <i class="bi bi-chevron-right"></i>
                                    </button>
                                <?php endforeach; ?>
                            </div>
                            <?php render_student_search_empty(); ?>
                            </div>
                        <?php endif; ?>
                    </div>

                    <?php foreach ($studentOverview as $student): ?>
                        <?php $history = build_student_participation_history($meetings, $participationMatrix, (int) $student['id']); ?>
                        <div class="student-attendance-panel" data-student-panel="detail" data-student-id="<?php echo e((string) $student['id']); ?>">
                            <button type="button" class="btn btn-copy mb-3" data-student-back><i class="bi bi-arrow-left"></i> All students</button>

                            <div class="student-detail-header">
                                <div>
                                    <strong><?php echo e($student['student_name']); ?></strong>
                                    <small><?php echo e($student['student_no'] ?: 'No student number'); ?></small>
                                </div>
                                <div class="student-detail-stats">
                                    <?php if ($student['average'] === null): ?>
                                        <span class="status-pill tone-slate">No records yet</span>
                                    <?php else: ?>
                                        <span class="status-pill tone-indigo">Avg <?php echo e((string) $student['average']); ?> / <?php echo e((string) $maxScore); ?></span>
                                        <span class="status-pill tone-emerald"><?php echo e((string) $student['times']); ?> participation<?php echo $student['times'] === 1 ? '' : 's'; ?></span>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <?php
                            $recorded = array_values(array_filter($history, static fn ($entry) => $entry['entry'] !== null));
                            ?>
                            <?php if (empty($recorded)): ?>
                                <div class="empty-state large">
                                    <i class="bi bi-chat-square-text"></i>
                                    <span>No participation recorded yet.</span>
                                </div>
                            <?php else: ?>
                                <div class="student-history-list">
                                    <?php foreach ($recorded as $entry): ?>
                                        <?php
                                        $meeting = $entry['meeting'];
                                        $dateLabel = date('M j, Y', strtotime((string) $meeting['meeting_date']));
                                        $scoreText = format_score($entry['entry']['score']);
                                        $scoreText = $scoreText === '' ? '0' : $scoreText;
                                        ?>
                                        <div class="student-history-row">
                                            <span>
                                                <strong><?php echo e($dateLabel); ?></strong>
                                                <small>
                                                    Week <?php echo e((string) $meeting['week_number']); ?> &bull; <?php echo e($meeting['meeting_type']); ?>
                                                    <?php echo !empty($entry['entry']['remarks']) ? ' &middot; ' . e((string) $entry['entry']['remarks']) : ''; ?>
                                                </small>
                                            </span>
                                            <span class="status-pill small tone-indigo"><?php echo e($scoreText); ?> / <?php echo e((string) $maxScore); ?></span>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>
    <?php
}

function render_edit_class_modal(PDO $pdo, array $class, string $csrfToken, array $errors = [], string $postedAction = '', int $editTargetClassId = 0, array $postedFormData = [], array $postedScheduleData = [], array $postedTotalsInput = []): void
{
    $classId = (int) $class['id'];
    $editFormData = [
        'class_name' => (string) $class['class_name'],
        'section' => (string) ($class['section'] ?? ''),
        'subject_name' => (string) $class['subject_name'],
        'subject_code' => (string) ($class['subject_code'] ?? ''),
        'schedule' => (string) ($class['schedule'] ?? ''),
        'school_year' => (string) ($class['school_year'] ?? ''),
        'term' => (string) ($class['term'] ?? ''),
        'description' => (string) ($class['description'] ?? ''),
    ];
    $editScheduleData = get_class_teaching_schedule($pdo, $classId);
    $assessmentSettings = get_assessment_settings($pdo, $classId);
    $activityValue = $assessmentSettings['activity'] !== null ? (string) $assessmentSettings['activity'] : '';
    $quizValue = $assessmentSettings['quiz'] !== null ? (string) $assessmentSettings['quiz'] : '';

    $repopulate = $postedAction === 'edit' && $editTargetClassId === $classId && !empty($errors);

    if ($repopulate) {
        $editFormData = !empty($postedFormData) ? $postedFormData : $editFormData;
        $editScheduleData = !empty($postedScheduleData) ? $postedScheduleData : $editScheduleData;
        $activityValue = (string) ($postedTotalsInput['activity'] ?? $activityValue);
        $quizValue = (string) ($postedTotalsInput['quiz'] ?? $quizValue);
    }
    ?>
    <div class="modal fade" id="editClassModal-<?php echo e((string) $classId); ?>" tabindex="-1" aria-labelledby="editClassModalLabel-<?php echo e((string) $classId); ?>" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-scrollable class-management-modal">
            <div class="modal-content">
                <div class="modal-header">
                    <div>
                        <h2 class="modal-title h5" id="editClassModalLabel-<?php echo e((string) $classId); ?>">Edit Class</h2>
                        <p class="mb-0 text-secondary small">Update class details without changing the join code. Existing records are kept unless you confirm changes that remove them.</p>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>

                <form method="post" action="<?php echo e(url_for('pages/instructor/classes.php')); ?>" novalidate>
                    <div class="modal-body">
                        <?php if ($repopulate): ?>
                            <div class="alert alert-danger" role="alert">
                                <p class="fw-semibold mb-2">Please fix the following:</p>
                                <ul class="mb-0">
                                    <?php foreach ($errors as $error): ?>
                                        <li><?php echo e($error); ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        <?php endif; ?>

                        <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                        <input type="hidden" name="class_action" value="edit">
                        <input type="hidden" name="class_id" value="<?php echo e((string) $classId); ?>">

                        <div class="form-grid">
                            <div class="field field-wide">
                                <label for="edit_class_name_<?php echo e((string) $classId); ?>" class="form-label">Class name</label>
                                <input type="text" class="form-control" id="edit_class_name_<?php echo e((string) $classId); ?>" name="class_name" value="<?php echo e($editFormData['class_name']); ?>" maxlength="150" required>
                            </div>
                            <div class="field">
                                <label for="edit_subject_name_<?php echo e((string) $classId); ?>" class="form-label">Subject name</label>
                                <input type="text" class="form-control" id="edit_subject_name_<?php echo e((string) $classId); ?>" name="subject_name" value="<?php echo e($editFormData['subject_name']); ?>" maxlength="150" required>
                            </div>
                            <div class="field">
                                <label for="edit_subject_code_<?php echo e((string) $classId); ?>" class="form-label">Subject code</label>
                                <input type="text" class="form-control" id="edit_subject_code_<?php echo e((string) $classId); ?>" name="subject_code" value="<?php echo e($editFormData['subject_code']); ?>" maxlength="50">
                            </div>
                            <div class="field">
                                <label for="edit_section_<?php echo e((string) $classId); ?>" class="form-label">Section</label>
                                <input type="text" class="form-control" id="edit_section_<?php echo e((string) $classId); ?>" name="section" value="<?php echo e($editFormData['section']); ?>" maxlength="100">
                            </div>
                            <div class="field">
                                <label for="edit_schedule_<?php echo e((string) $classId); ?>" class="form-label">Schedule</label>
                                <input type="text" class="form-control" id="edit_schedule_<?php echo e((string) $classId); ?>" name="schedule" value="<?php echo e($editFormData['schedule']); ?>" maxlength="150">
                            </div>
                            <div class="field">
                                <label for="edit_school_year_<?php echo e((string) $classId); ?>" class="form-label">School year</label>
                                <input type="text" class="form-control" id="edit_school_year_<?php echo e((string) $classId); ?>" name="school_year" value="<?php echo e($editFormData['school_year']); ?>" maxlength="20">
                            </div>
                            <div class="field">
                                <label for="edit_term_<?php echo e((string) $classId); ?>" class="form-label">Term</label>
                                <input type="text" class="form-control" id="edit_term_<?php echo e((string) $classId); ?>" name="term" value="<?php echo e($editFormData['term']); ?>" maxlength="50">
                            </div>
                            <div class="field field-wide">
                                <label for="edit_description_<?php echo e((string) $classId); ?>" class="form-label">Description</label>
                                <textarea class="form-control" id="edit_description_<?php echo e((string) $classId); ?>" name="description" rows="4" maxlength="1000"><?php echo e($editFormData['description']); ?></textarea>
                            </div>
                        </div>

                        <?php render_teaching_schedule_fields($editScheduleData, 'edit_' . $classId . '_'); ?>
                        <?php render_assessment_config_fields($activityValue, $quizValue, 'edit_' . $classId . '_'); ?>
                    </div>

                    <div class="modal-footer">
                        <button type="button" class="btn btn-copy" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-edupredict"><i class="bi bi-check2-circle"></i> Save changes</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <?php
}

function render_assessment_item_modal(PDO $pdo, int $classId, array $item, array $typeMeta, string $type, string $csrfToken, array $classGroupings): void
{
    $itemId = (int) $item['id'];
    $mode = (string) ($item['activity_mode'] ?? 'individual');
    $groupingId = (int) ($item['grouping_id'] ?? 0);
    $maxValue = format_score($item['max_score']);
    ?>
    <div class="modal fade" id="editAssessmentItemModal-<?php echo e((string) $itemId); ?>" tabindex="-1" aria-labelledby="editAssessmentItemModalLabel-<?php echo e((string) $itemId); ?>" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-scrollable assessment-item-modal">
            <div class="modal-content">
                <div class="modal-header">
                    <div>
                        <h2 class="modal-title h5" id="editAssessmentItemModalLabel-<?php echo e((string) $itemId); ?>">Set up <?php echo e($typeMeta['label']); ?></h2>
                        <p class="mb-0 text-secondary small">Title, date, and maximum score are required before grading. Recorded scores stay attached.</p>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="post" action="<?php echo e(instructor_class_route($classId, $typeMeta['view'])); ?>" novalidate<?php echo $type === 'activity' ? ' data-activity-item-form' : ''; ?>>
                    <div class="modal-body">
                        <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                        <input type="hidden" name="class_action" value="assessment_item_save">
                        <input type="hidden" name="item_id" value="<?php echo e((string) $itemId); ?>">
                        <div class="form-grid">
                            <div class="field field-wide">
                                <label class="form-label">Title</label>
                                <input type="text" class="form-control" name="item_title" value="<?php echo e($item['title']); ?>" maxlength="150" required>
                            </div>
                            <div class="field">
                                <label class="form-label">Date</label>
                                <input type="date" class="form-control" name="item_date" value="<?php echo e((string) ($item['scheduled_date'] ?? '')); ?>">
                            </div>
                            <div class="field">
                                <label class="form-label">Max score</label>
                                <input type="number" class="form-control" name="item_max_score" value="<?php echo e($maxValue); ?>" min="1" max="1000" step="0.5" required>
                            </div>
                            <div class="field field-wide">
                                <label class="form-label">Description</label>
                                <input type="text" class="form-control" name="item_description" value="<?php echo e((string) ($item['description'] ?? '')); ?>" maxlength="255" placeholder="Optional notes">
                            </div>
                        </div>

                        <?php if ($type === 'activity'): ?>
                            <div class="assessment-mode-block">
                                <div class="section-heading compact">
                                    <h2>Activity type</h2>
                                    <span>Individual or group</span>
                                </div>
                                <div class="picker-mode" role="radiogroup" aria-label="Activity type">
                                    <label class="picker-mode-option">
                                        <input type="radio" name="item_mode" value="individual" <?php echo $mode !== 'group' ? 'checked' : ''; ?> data-activity-mode>
                                        <span><i class="bi bi-person"></i> Individual Activity</span>
                                    </label>
                                    <label class="picker-mode-option">
                                        <input type="radio" name="item_mode" value="group" <?php echo $mode === 'group' ? 'checked' : ''; ?> data-activity-mode>
                                        <span><i class="bi bi-people"></i> Group Activity</span>
                                    </label>
                                </div>

                                <div class="activity-grouping-block" data-activity-grouping <?php echo $mode === 'group' ? '' : 'hidden'; ?>>
                                    <div class="picker-mode" role="radiogroup" aria-label="Grouping source">
                                        <label class="picker-mode-option">
                                            <input type="radio" name="item_grouping_source" value="existing" checked data-grouping-source>
                                            <span><i class="bi bi-diagram-3"></i> Use existing class grouping</span>
                                        </label>
                                        <label class="picker-mode-option">
                                            <input type="radio" name="item_grouping_source" value="new" data-grouping-source>
                                            <span><i class="bi bi-plus-circle"></i> Create activity-specific grouping</span>
                                        </label>
                                    </div>

                                    <div class="field" data-grouping-existing>
                                        <label class="form-label">Class grouping</label>
                                        <?php if (empty($classGroupings)): ?>
                                            <p class="participation-hint"><i class="bi bi-info-circle"></i> No class groupings yet. Create one in the <a href="<?php echo e(instructor_class_route($classId, 'groupings')); ?>">Groupings</a> module, or create an activity-specific grouping below.</p>
                                        <?php else: ?>
                                            <select class="form-control" name="item_grouping_id">
                                                <?php foreach ($classGroupings as $grouping): ?>
                                                    <option value="<?php echo e((string) $grouping['id']); ?>" <?php echo $groupingId === (int) $grouping['id'] ? 'selected' : ''; ?>><?php echo e($grouping['name']); ?> (<?php echo e((string) $grouping['group_count']); ?> groups)</option>
                                                <?php endforeach; ?>
                                            </select>
                                        <?php endif; ?>
                                    </div>

                                    <div data-grouping-new hidden>
                                        <?php render_grouping_config_fields('act_' . $itemId . '_'); ?>
                                        <p class="participation-hint"><i class="bi bi-info-circle"></i> This grouping is saved only for this activity and never overwrites class groupings.</p>
                                    </div>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-copy" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-edupredict"><i class="bi bi-check2-circle"></i> Save item</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <?php
}

function render_assessment_grading(PDO $pdo, array $class, array $item, array $typeMeta, string $csrfToken, array $errors, string $successMessage): void
{
    $classId = (int) $class['id'];
    $itemId = (int) $item['id'];
    $maxScore = (float) $item['max_score'];
    $maxValue = format_score($item['max_score']);
    $isGroup = (string) $item['type'] === 'activity' && (string) ($item['activity_mode'] ?? 'individual') === 'group';
    $backUrl = instructor_class_route($classId, $typeMeta['view']);
    ?>
    <section class="class-section mt-4">
        <?php if ($successMessage !== ''): ?>
            <div class="alert alert-success" role="alert"><?php echo e($successMessage); ?></div>
        <?php endif; ?>
        <?php if (!empty($errors)): ?>
            <div class="alert alert-danger" role="alert">
                <ul class="mb-0"><?php foreach ($errors as $error): ?><li><?php echo e($error); ?></li><?php endforeach; ?></ul>
            </div>
        <?php endif; ?>

        <div class="class-context-header">
            <div>
                <div class="eyebrow"><?php echo e($typeMeta['label']); ?> <?php echo e((string) $item['position']); ?> &bull; Grading</div>
                <h2><?php echo e($item['title']); ?></h2>
                <p><?php echo !empty($item['description']) ? e($item['description']) : 'No description'; ?></p>
            </div>
            <a class="btn btn-copy" href="<?php echo e($backUrl); ?>"><i class="bi bi-arrow-left"></i> All <?php echo e(strtolower($typeMeta['plural'])); ?></a>
        </div>

        <div class="class-context-meta">
            <span><i class="bi bi-calendar-event"></i><?php echo e(date('M j, Y', strtotime((string) $item['scheduled_date']))); ?></span>
            <span><i class="bi bi-bullseye"></i>Max score <?php echo e($maxValue); ?></span>
            <span><i class="bi <?php echo $isGroup ? 'bi-people' : 'bi-person'; ?>"></i><?php echo $isGroup ? 'Group activity' : 'Individual'; ?></span>
        </div>

        <?php if ($isGroup): ?>
            <?php render_group_grading_form($pdo, $classId, $item, $maxScore, $maxValue, $csrfToken, $typeMeta); ?>
        <?php else: ?>
            <?php $students = get_students_with_scores($pdo, $classId, $itemId); ?>
            <?php if (empty($students)): ?>
                <div class="empty-state large mt-4"><i class="bi bi-people"></i><span>No enrolled students yet.</span></div>
            <?php else: ?>
                <form method="post" action="<?php echo e($backUrl . '?item=' . $itemId); ?>" class="mt-4" data-grading-sheet>
                    <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                    <input type="hidden" name="class_action" value="assessment_grade_save">
                    <input type="hidden" name="item_id" value="<?php echo e((string) $itemId); ?>">
                    <input type="hidden" name="grade_view" value="<?php echo e($typeMeta['view']); ?>">
                    <div data-student-search-scope>
                        <?php render_student_search_bar(); ?>
                        <div class="grading-student-list" data-student-search-list>
                            <?php foreach ($students as $student): ?>
                                <div class="grading-student-row" data-search-terms="<?php echo e(student_search_terms($student)); ?>">
                                    <span class="grading-student-identity">
                                        <strong><?php echo e($student['student_name']); ?></strong>
                                        <small><?php echo e($student['student_no'] ?: 'No student number'); ?></small>
                                    </span>
                                    <span class="grading-score-field">
                                        <input type="number" class="form-control" name="score[<?php echo e((string) $student['id']); ?>]" value="<?php echo e($student['item_score'] !== null ? format_score($student['item_score']) : ''); ?>" min="0" max="<?php echo e($maxValue); ?>" step="0.25" placeholder="&mdash;" data-grade-input aria-label="Score for <?php echo e($student['student_name']); ?>">
                                        <span class="grading-score-max">/ <?php echo e($maxValue); ?></span>
                                    </span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <?php render_student_search_empty(); ?>
                    </div>
                    <p class="participation-hint mt-2"><i class="bi bi-info-circle"></i> Scores must be between 0 and <?php echo e($maxValue); ?>. Leave blank to clear a score.</p>
                    <button class="btn btn-edupredict mt-2" type="submit"><i class="bi bi-save"></i> Save grades</button>
                </form>
            <?php endif; ?>
        <?php endif; ?>
    </section>
    <?php
}

function render_group_grading_form(PDO $pdo, int $classId, array $item, float $maxScore, string $maxValue, string $csrfToken, array $typeMeta): void
{
    $itemId = (int) $item['id'];
    $groupingId = (int) ($item['grouping_id'] ?? 0);
    $grouping = $groupingId > 0 ? get_grouping_with_groups($pdo, $groupingId) : [];

    // A group activity cannot be graded until it has a grouping. Require the
    // instructor to select an existing class grouping or create an activity-specific
    // one right here before the group grading sheet appears.
    if ($groupingId <= 0 || empty($grouping)) {
        $classGroupings = get_class_groupings($pdo, $classId, 'class');
        ?>
        <div class="assessment-config-state mt-4">
            <i class="bi bi-diagram-3"></i>
            <h3>This group activity needs a grouping before grading.</h3>
            <p>Select an existing class grouping or create an activity-specific grouping (saved only for this activity).</p>
        </div>

        <form method="post" action="<?php echo e(instructor_class_route($classId, $typeMeta['view']) . '?item=' . $itemId); ?>" class="grouping-assign-form mt-3" data-grouping-assign novalidate>
            <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
            <input type="hidden" name="class_action" value="assessment_assign_grouping">
            <input type="hidden" name="item_id" value="<?php echo e((string) $itemId); ?>">

            <div class="picker-mode" role="radiogroup" aria-label="Grouping source">
                <label class="picker-mode-option">
                    <input type="radio" name="grouping_source" value="existing" checked data-grouping-source>
                    <span><i class="bi bi-diagram-3"></i> Use existing class grouping</span>
                </label>
                <label class="picker-mode-option">
                    <input type="radio" name="grouping_source" value="new" data-grouping-source>
                    <span><i class="bi bi-plus-circle"></i> Create activity-specific grouping</span>
                </label>
            </div>

            <div class="field mt-2" data-grouping-existing>
                <label class="form-label">Class grouping</label>
                <?php if (empty($classGroupings)): ?>
                    <p class="participation-hint"><i class="bi bi-info-circle"></i> No class groupings yet. Create one in the <a href="<?php echo e(instructor_class_route($classId, 'groupings')); ?>">Groupings</a> module, or create an activity-specific grouping instead.</p>
                <?php else: ?>
                    <select class="form-control" name="grouping_id">
                        <?php foreach ($classGroupings as $g): ?>
                            <option value="<?php echo e((string) $g['id']); ?>"><?php echo e($g['name']); ?> (<?php echo e((string) $g['group_count']); ?> groups)</option>
                        <?php endforeach; ?>
                    </select>
                <?php endif; ?>
            </div>

            <div data-grouping-new hidden>
                <?php render_grouping_config_fields('assign_' . $itemId . '_'); ?>
            </div>

            <button class="btn btn-edupredict mt-3" type="submit"><i class="bi bi-check2-circle"></i> Assign grouping &amp; continue</button>
        </form>
        <?php
        return;
    }

    $scores = get_item_scores_map($pdo, $itemId);
    ?>
    <form method="post" action="<?php echo e(instructor_class_route($classId, $typeMeta['view']) . '?item=' . $itemId); ?>" class="mt-4" data-grading-sheet>
        <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
        <input type="hidden" name="class_action" value="assessment_grade_save">
        <input type="hidden" name="item_id" value="<?php echo e((string) $itemId); ?>">
        <input type="hidden" name="grade_view" value="<?php echo e($typeMeta['view']); ?>">

        <div class="group-grade-list">
            <?php foreach ($grouping as $group): ?>
                <?php
                $members = $group['members'];
                $memberScores = [];
                foreach ($members as $member) {
                    $memberScores[] = isset($scores[(int) $member['student_id']]) ? $scores[(int) $member['student_id']] : null;
                }
                $uniqueScores = array_unique(array_filter($memberScores, static fn ($s) => $s !== null));
                $sharedScore = (count($uniqueScores) === 1 && count(array_filter($memberScores, static fn ($s) => $s === null)) === 0) ? reset($uniqueScores) : null;
                ?>
                <article class="group-grade-card" data-group-grade>
                    <div class="group-grade-head">
                        <div>
                            <strong><?php echo e($group['name']); ?></strong>
                            <small><?php echo count($members); ?> member<?php echo count($members) === 1 ? '' : 's'; ?></small>
                        </div>
                        <span class="group-grade-score">
                            <input type="number" class="form-control" min="0" max="<?php echo e($maxValue); ?>" step="0.25" value="<?php echo e($sharedScore !== null ? format_score($sharedScore) : ''); ?>" placeholder="Group score" data-group-score aria-label="Group score for <?php echo e($group['name']); ?>">
                            <span class="grading-score-max">/ <?php echo e($maxValue); ?></span>
                        </span>
                    </div>

                    <?php if (empty($members)): ?>
                        <p class="participation-hint"><i class="bi bi-info-circle"></i> No members assigned.</p>
                    <?php else: ?>
                        <div class="group-grade-members">
                            <?php foreach ($members as $member): ?>
                                <?php $existing = $scores[(int) $member['student_id']] ?? null; ?>
                                <div class="group-grade-member">
                                    <span>
                                        <strong><?php echo e($member['student_name']); ?></strong>
                                        <?php if ((int) $group['leader_student_id'] === (int) $member['student_id']): ?><span class="status-pill small tone-indigo">Leader</span><?php endif; ?>
                                    </span>
                                    <input type="number" class="form-control" name="score[<?php echo e((string) $member['student_id']); ?>]" value="<?php echo e($existing !== null ? format_score($existing) : ''); ?>" min="0" max="<?php echo e($maxValue); ?>" step="0.25" placeholder="&mdash;" data-group-member-score aria-label="Score for <?php echo e($member['student_name']); ?>">
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </article>
            <?php endforeach; ?>
        </div>

        <p class="participation-hint mt-2"><i class="bi bi-info-circle"></i> The group score applies to every member; edit any member below to override. Scores must be between 0 and <?php echo e($maxValue); ?>.</p>
        <button class="btn btn-edupredict mt-2" type="submit"><i class="bi bi-save"></i> Save group grades</button>
    </form>
    <?php
}

function render_assessment_workspace(PDO $pdo, int $instructorId, array $class, string $csrfToken, array $errors, string $successMessage, string $type): void
{
    $classId = (int) $class['id'];
    $typeMeta = assessment_types()[$type];
    $settings = get_assessment_settings($pdo, $classId);
    $total = $settings[$type];
    $items = get_assessment_items($pdo, $classId, $type);
    $isConfigured = $total !== null || !empty($items);
    $classGroupings = get_class_groupings($pdo, $classId, 'class');

    // Grading a specific item.
    $requestedItemId = (int) ($_GET['item'] ?? 0);
    if ($requestedItemId > 0) {
        $gradeItem = get_assessment_item($pdo, $classId, $requestedItemId, $type);
        if ($gradeItem && is_assessment_item_gradeable($gradeItem)) {
            render_assessment_grading($pdo, $class, $gradeItem, $typeMeta, $csrfToken, $errors, $successMessage);
            return;
        }
    }

    $summary = get_assessment_summary($pdo, $classId, $type);
    ?>
    <section class="class-section mt-4">
        <?php if ($successMessage !== ''): ?>
            <div class="alert alert-success" role="alert"><?php echo e($successMessage); ?></div>
        <?php endif; ?>

        <?php if (!empty($errors)): ?>
            <div class="alert alert-danger" role="alert">
                <ul class="mb-0">
                    <?php foreach ($errors as $error): ?>
                        <li><?php echo e($error); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <?php if (!$isConfigured): ?>
            <div class="assessment-config-state">
                <i class="bi <?php echo e($typeMeta['icon']); ?>"></i>
                <h3><?php echo e($typeMeta['plural']); ?> have not been configured for this class yet.</h3>
                <p>Set the total number of <?php echo e(strtolower($typeMeta['plural'])); ?> to generate <?php echo e($typeMeta['label']); ?> 1&hellip;N automatically. Each item can be renamed, edited, and graded later.</p>

                <form method="post" action="<?php echo e(instructor_class_route($classId, $typeMeta['view'])); ?>" class="assessment-config-form" novalidate>
                    <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                    <input type="hidden" name="class_action" value="assessment_configure">
                    <input type="hidden" name="class_id" value="<?php echo e((string) $classId); ?>">
                    <input type="hidden" name="assessment_type" value="<?php echo e($type); ?>">
                    <input type="number" class="form-control" name="assessment_total" min="1" max="200" step="1" placeholder="Total <?php echo e(strtolower($typeMeta['plural'])); ?>" required aria-label="Total number of <?php echo e(strtolower($typeMeta['plural'])); ?>">
                    <button class="btn btn-edupredict" type="submit"><i class="bi bi-magic"></i> Save &amp; generate</button>
                </form>

                <button class="btn btn-copy" type="button" data-bs-toggle="modal" data-bs-target="#editClassModal-<?php echo e((string) $classId); ?>">
                    <i class="bi bi-pencil-square"></i> Open Edit Class for more settings
                </button>
            </div>
        <?php else: ?>
            <div class="section-heading">
                <h2><?php echo e($typeMeta['plural']); ?></h2>
                <span><?php echo (int) $summary['configured']; ?> ready &bull; <?php echo (int) $summary['needs_setup']; ?> need setup &bull; <?php echo (int) $summary['graded']; ?> graded</span>
            </div>

            <p class="participation-hint mb-3"><i class="bi bi-info-circle"></i> Complete title, date, and max score to unlock grading. Change the total via <button class="btn-link-inline" type="button" data-bs-toggle="modal" data-bs-target="#editClassModal-<?php echo e((string) $classId); ?>">Edit Class</button>; graded items are never removed automatically.</p>

            <?php if (empty($items)): ?>
                <div class="empty-state large">
                    <i class="bi <?php echo e($typeMeta['icon']); ?>"></i>
                    <span>No <?php echo e(strtolower($typeMeta['plural'])); ?> generated yet.</span>
                </div>
            <?php else: ?>
                <div class="meeting-list">
                    <?php foreach ($items as $item): ?>
                        <?php
                        $graded = (int) $item['score_count'] > 0;
                        $gradeable = is_assessment_item_gradeable($item);
                        $isGroup = $type === 'activity' && (string) ($item['activity_mode'] ?? 'individual') === 'group';
                        ?>
                        <article class="meeting-item <?php echo $gradeable ? '' : 'is-inactive'; ?>">
                            <div>
                                <strong><?php echo e($item['title']); ?></strong>
                                <span>
                                    <?php echo e($typeMeta['label']); ?> <?php echo e((string) $item['position']); ?> &bull; Max <?php echo e(format_score($item['max_score'])); ?>
                                    <?php echo !empty($item['scheduled_date']) ? ' &bull; ' . e(date('M j, Y', strtotime((string) $item['scheduled_date']))) : ''; ?>
                                    <?php echo $isGroup ? ' &bull; Group' : ''; ?>
                                </span>
                                <?php if (!$gradeable): ?>
                                    <span class="meeting-status-badge is-pending"><i class="bi bi-exclamation-circle-fill"></i> Needs setup</span>
                                <?php elseif ($graded): ?>
                                    <span class="meeting-status-badge is-completed"><i class="bi bi-check-circle-fill"></i> <?php echo e((string) $item['score_count']); ?> graded</span>
                                <?php else: ?>
                                    <span class="meeting-status-badge is-upcoming"><i class="bi bi-pencil"></i> Ready to grade</span>
                                <?php endif; ?>
                            </div>
                            <div class="meeting-actions">
                                <?php if ($gradeable): ?>
                                    <a class="btn btn-edupredict" href="<?php echo e(instructor_class_route($classId, $typeMeta['view']) . '?item=' . (int) $item['id']); ?>"><i class="bi bi-card-checklist"></i> Grade</a>
                                <?php endif; ?>
                                <button class="btn btn-copy" type="button" data-bs-toggle="modal" data-bs-target="#editAssessmentItemModal-<?php echo e((string) $item['id']); ?>"><i class="bi bi-pencil-square"></i> Set up</button>
                            </div>
                        </article>

                        <?php render_assessment_item_modal($pdo, $classId, $item, $typeMeta, $type, $csrfToken, $classGroupings); ?>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </section>
    <?php
}

function render_grouping_config_fields(string $idPrefix, bool $includeName = true): void
{
    $methods = grouping_methods();
    ?>
    <div class="grouping-config">
        <?php if ($includeName): ?>
            <div class="field field-wide">
                <label for="<?php echo e($idPrefix); ?>grouping_name" class="form-label">Grouping name</label>
                <input type="text" class="form-control" id="<?php echo e($idPrefix); ?>grouping_name" name="grouping_name" maxlength="150" placeholder="e.g. Lab Groups">
            </div>
        <?php endif; ?>

        <div class="section-heading compact">
            <h2>Method</h2>
            <span>How students are assigned</span>
        </div>
        <div class="picker-mode" role="radiogroup" aria-label="Grouping method">
            <?php foreach ($methods as $key => $meta): ?>
                <label class="picker-mode-option">
                    <input type="radio" name="grouping_method" value="<?php echo e($key); ?>" <?php echo $key === 'random' ? 'checked' : ''; ?>>
                    <span><i class="bi <?php echo e($meta['icon']); ?>"></i> <?php echo e($meta['label']); ?></span>
                </label>
            <?php endforeach; ?>
        </div>

        <div class="form-grid">
            <div class="field">
                <label for="<?php echo e($idPrefix); ?>grouping_size_by" class="form-label">Divide by</label>
                <select class="form-control" id="<?php echo e($idPrefix); ?>grouping_size_by" name="grouping_size_by">
                    <option value="groups">Number of groups</option>
                    <option value="per_group">Students per group</option>
                </select>
            </div>
            <div class="field">
                <label for="<?php echo e($idPrefix); ?>grouping_size_value" class="form-label">Value</label>
                <input type="number" class="form-control" id="<?php echo e($idPrefix); ?>grouping_size_value" name="grouping_size_value" value="4" min="1" max="100" step="1">
            </div>
        </div>

        <label class="picker-check">
            <input type="checkbox" name="grouping_assign_leaders" value="1">
            <span>Assign group leaders (Suggested/Random pick the strongest member)</span>
        </label>
    </div>
    <?php
}

function render_groupings_workspace(PDO $pdo, int $instructorId, array $class, string $csrfToken, array $errors, string $successMessage): void
{
    $classId = (int) $class['id'];
    $requestedGroupingId = (int) ($_GET['grouping'] ?? 0);

    if ($requestedGroupingId > 0) {
        $grouping = grouping_belongs_to_instructor($pdo, $requestedGroupingId, $instructorId);
        if ($grouping && (int) $grouping['class_id'] === $classId) {
            render_grouping_edit($pdo, $classId, $grouping, $csrfToken, $errors, $successMessage);
            return;
        }
    }

    $groupings = get_class_groupings($pdo, $classId, 'class');
    ?>
    <section class="class-section mt-4">
        <?php if ($successMessage !== ''): ?>
            <div class="alert alert-success" role="alert"><?php echo e($successMessage); ?></div>
        <?php endif; ?>
        <?php if (!empty($errors)): ?>
            <div class="alert alert-danger" role="alert">
                <ul class="mb-0"><?php foreach ($errors as $error): ?><li><?php echo e($error); ?></li><?php endforeach; ?></ul>
            </div>
        <?php endif; ?>

        <div class="section-heading">
            <h2>Class Groupings</h2>
            <button class="btn btn-edupredict" type="button" data-bs-toggle="modal" data-bs-target="#createGroupingModal"><i class="bi bi-plus-circle"></i> New grouping</button>
        </div>
        <p class="participation-hint mb-3"><i class="bi bi-info-circle"></i> Reusable groupings for group activities, projects, lab work, and presentations. Create by Random, Suggested (balanced), or Manual assignment.</p>

        <?php if (empty($groupings)): ?>
            <div class="empty-state large">
                <i class="bi bi-diagram-3"></i>
                <span>No groupings yet. Create your first reusable class grouping.</span>
            </div>
        <?php else: ?>
            <div class="meeting-list">
                <?php foreach ($groupings as $grouping): ?>
                    <article class="meeting-item">
                        <div>
                            <strong><?php echo e($grouping['name']); ?></strong>
                            <span>
                                <?php echo e(grouping_methods()[$grouping['method']]['label'] ?? 'Manual'); ?>
                                &bull; <?php echo e((string) $grouping['group_count']); ?> group<?php echo (int) $grouping['group_count'] === 1 ? '' : 's'; ?>
                                &bull; <?php echo e((string) $grouping['member_count']); ?> assigned
                            </span>
                        </div>
                        <div class="meeting-actions">
                            <a class="btn btn-copy" href="<?php echo e(instructor_class_route($classId, 'groupings') . '?grouping=' . (int) $grouping['id']); ?>"><i class="bi bi-pencil-square"></i> Edit</a>
                            <form method="post" action="<?php echo e(instructor_class_route($classId, 'groupings')); ?>" data-confirm-action="Delete this grouping? Activities using it will lose their grouping reference.">
                                <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                                <input type="hidden" name="class_action" value="grouping_delete">
                                <input type="hidden" name="class_id" value="<?php echo e((string) $classId); ?>">
                                <input type="hidden" name="grouping_id" value="<?php echo e((string) $grouping['id']); ?>">
                                <button class="btn btn-copy btn-danger-soft" type="submit"><i class="bi bi-trash"></i> Delete</button>
                            </form>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>

    <div class="modal fade" id="createGroupingModal" tabindex="-1" aria-labelledby="createGroupingModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <div>
                        <h2 class="modal-title h5" id="createGroupingModalLabel">New Grouping</h2>
                        <p class="mb-0 text-secondary small">Generate groups automatically, then fine-tune names, leaders, and membership.</p>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="post" action="<?php echo e(instructor_class_route($classId, 'groupings')); ?>" novalidate>
                    <div class="modal-body">
                        <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                        <input type="hidden" name="class_action" value="grouping_create">
                        <input type="hidden" name="class_id" value="<?php echo e((string) $classId); ?>">
                        <input type="hidden" name="grouping_context" value="class">
                        <?php render_grouping_config_fields('create_grouping_'); ?>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-copy" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-edupredict"><i class="bi bi-magic"></i> Generate grouping</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <?php
}

function render_grouping_edit(PDO $pdo, int $classId, array $grouping, string $csrfToken, array $errors, string $successMessage): void
{
    $groupingId = (int) $grouping['id'];
    $groups = get_grouping_with_groups($pdo, $groupingId);
    $roster = get_class_enrolled_students($pdo, $classId);
    $memberMap = get_grouping_member_map($pdo, $groupingId);

    // group_id => slot index (matches the order rendered below).
    $slotByGroupId = [];
    foreach ($groups as $slot => $group) {
        $slotByGroupId[(int) $group['id']] = $slot;
    }
    ?>
    <section class="class-section mt-4">
        <?php if ($successMessage !== ''): ?>
            <div class="alert alert-success" role="alert"><?php echo e($successMessage); ?></div>
        <?php endif; ?>
        <?php if (!empty($errors)): ?>
            <div class="alert alert-danger" role="alert">
                <ul class="mb-0"><?php foreach ($errors as $error): ?><li><?php echo e($error); ?></li><?php endforeach; ?></ul>
            </div>
        <?php endif; ?>

        <div class="class-context-header">
            <div>
                <div class="eyebrow">Grouping &bull; <?php echo e(grouping_methods()[$grouping['method']]['label'] ?? 'Manual'); ?></div>
                <h2><?php echo e($grouping['name']); ?></h2>
                <p><?php echo count($groups); ?> group<?php echo count($groups) === 1 ? '' : 's'; ?> &bull; <?php echo count($roster); ?> students</p>
            </div>
            <a class="btn btn-copy" href="<?php echo e(instructor_class_route($classId, 'groupings')); ?>"><i class="bi bi-arrow-left"></i> All groupings</a>
        </div>

        <form method="post" action="<?php echo e(instructor_class_route($classId, 'groupings') . '?grouping=' . $groupingId); ?>" class="mt-3">
            <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
            <input type="hidden" name="class_action" value="grouping_save">
            <input type="hidden" name="class_id" value="<?php echo e((string) $classId); ?>">
            <input type="hidden" name="grouping_id" value="<?php echo e((string) $groupingId); ?>">

            <div class="field field-wide">
                <label class="form-label">Grouping name</label>
                <input type="text" class="form-control" name="grouping_name" value="<?php echo e($grouping['name']); ?>" maxlength="150" required>
            </div>

            <div class="grouping-group-grid mt-3">
                <?php foreach ($groups as $slot => $group): ?>
                    <article class="grouping-group-card">
                        <div class="form-grid">
                            <div class="field field-wide">
                                <label class="form-label">Group <?php echo e((string) ($slot + 1)); ?> name</label>
                                <input type="text" class="form-control" name="group_name[<?php echo e((string) $slot); ?>]" value="<?php echo e($group['name']); ?>" maxlength="150">
                            </div>
                            <div class="field field-wide">
                                <label class="form-label">Leader</label>
                                <select class="form-control" name="group_leader[<?php echo e((string) $slot); ?>]">
                                    <option value="0">No leader</option>
                                    <?php foreach ($group['members'] as $member): ?>
                                        <option value="<?php echo e((string) $member['student_id']); ?>" <?php echo (int) $group['leader_student_id'] === (int) $member['student_id'] ? 'selected' : ''; ?>><?php echo e($member['student_name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="grouping-group-members">
                            <?php if (empty($group['members'])): ?>
                                <span class="text-secondary small">No members yet &mdash; assign below.</span>
                            <?php else: ?>
                                <?php foreach ($group['members'] as $member): ?>
                                    <span class="status-pill small tone-slate"><?php echo e($member['student_name']); ?></span>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>

            <div class="section-heading mt-4">
                <h2>Assign students</h2>
                <span>Move students between groups</span>
            </div>
            <div data-student-search-scope>
                <?php render_student_search_bar(); ?>
                <div class="grouping-assign-list" data-student-search-list>
                    <?php foreach ($roster as $student): ?>
                        <?php
                        $studentId = (int) $student['id'];
                        $currentGroupId = $memberMap[$studentId] ?? 0;
                        $currentSlot = $slotByGroupId[$currentGroupId] ?? -1;
                        ?>
                        <div class="grouping-assign-row" data-search-terms="<?php echo e(student_search_terms($student)); ?>">
                            <span class="grouping-assign-identity">
                                <strong><?php echo e($student['student_name']); ?></strong>
                                <small><?php echo e($student['student_no'] ?: 'No student number'); ?></small>
                            </span>
                            <select class="form-control" name="student_group[<?php echo e((string) $studentId); ?>]" aria-label="Group for <?php echo e($student['student_name']); ?>">
                                <option value="-1" <?php echo $currentSlot === -1 ? 'selected' : ''; ?>>Unassigned</option>
                                <?php foreach ($groups as $slot => $group): ?>
                                    <option value="<?php echo e((string) $slot); ?>" <?php echo $currentSlot === $slot ? 'selected' : ''; ?>><?php echo e($group['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    <?php endforeach; ?>
                </div>
                <?php render_student_search_empty(); ?>
            </div>

            <p class="participation-hint mt-2"><i class="bi bi-info-circle"></i> Leaders and group names update on save. A student assigned to a group they don't belong to becomes a member automatically.</p>
            <button class="btn btn-edupredict mt-2" type="submit"><i class="bi bi-save"></i> Save grouping</button>
        </form>
    </section>
    <?php
}

function render_instructor_class_workspace(array $class, string $classView, array $viewMeta, string $baseJoinUrl, PDO $pdo, int $instructorId, string $csrfToken, array $errors, string $successMessage): void
{
    $classId = (int) $class['id'];
    $joinLink = $baseJoinUrl . urlencode((string) $class['class_code']);
    $classLabel = instructor_class_label($class);
    $createdAt = !empty($class['created_at']) ? date('M j, Y', strtotime((string) $class['created_at'])) : 'Not available';
    ?>
    <section class="class-section">
        <div class="class-context-header">
            <div>
                <div class="eyebrow">Selected class</div>
                <h2><?php echo e($classLabel); ?></h2>
                <p><?php echo e($class['subject_name']); ?><?php echo !empty($class['subject_code']) ? ' (' . e($class['subject_code']) . ')' : ''; ?></p>
            </div>
            <div class="class-context-actions">
                <button class="btn btn-copy" type="button" data-bs-toggle="modal" data-bs-target="#editClassModal-<?php echo e((string) $classId); ?>">
                    <i class="bi bi-pencil-square"></i> Edit class
                </button>
                <a class="btn btn-copy" href="<?php echo e(url_for('classes')); ?>">
                    <i class="bi bi-arrow-left"></i> All classes
                </a>
            </div>
        </div>

        <div class="class-context-meta">
            <span><i class="bi bi-calendar2-week"></i><?php echo e($class['schedule'] ?: 'No schedule set'); ?></span>
            <span><i class="bi bi-people"></i><?php echo e((string) $class['student_count']); ?> students</span>
            <span><i class="bi bi-clock-history"></i>Created <?php echo e($createdAt); ?></span>
            <span><i class="bi bi-circle-fill"></i><?php echo e($class['status']); ?></span>
        </div>

        <?php if ($classView === 'attendance'): ?>
            <?php render_attendance_workspace($pdo, $instructorId, $class, $csrfToken, $errors, $successMessage); ?>
        <?php elseif ($classView === 'participation'): ?>
            <?php render_participation_workspace($pdo, $instructorId, $class, $csrfToken, $errors, $successMessage); ?>
        <?php elseif ($classView === 'activities'): ?>
            <?php render_assessment_workspace($pdo, $instructorId, $class, $csrfToken, $errors, $successMessage, 'activity'); ?>
        <?php elseif ($classView === 'quizzes'): ?>
            <?php render_assessment_workspace($pdo, $instructorId, $class, $csrfToken, $errors, $successMessage, 'quiz'); ?>
        <?php elseif ($classView === 'groupings'): ?>
            <?php render_groupings_workspace($pdo, $instructorId, $class, $csrfToken, $errors, $successMessage); ?>
        <?php elseif ($classView === 'overview'): ?>
            <div class="metric-grid class-overview-grid mt-4">
                <article class="metric-card tone-indigo">
                    <div class="metric-icon"><i class="bi bi-door-open"></i></div>
                    <div>
                        <div class="metric-label">Class code</div>
                        <div class="metric-value compact"><?php echo e($class['class_code']); ?></div>
                        <div class="metric-note">Students use this to join.</div>
                    </div>
                </article>
                <article class="metric-card tone-emerald">
                    <div class="metric-icon"><i class="bi bi-people"></i></div>
                    <div>
                        <div class="metric-label">Students</div>
                        <div class="metric-value"><?php echo e((string) $class['student_count']); ?></div>
                        <div class="metric-note">Active enrollments.</div>
                    </div>
                </article>
                <article class="metric-card tone-amber">
                    <div class="metric-icon"><i class="bi bi-calendar-event"></i></div>
                    <div>
                        <div class="metric-label">Term</div>
                        <div class="metric-value compact"><?php echo e($class['term'] ?: 'Not set'); ?></div>
                        <div class="metric-note"><?php echo e($class['school_year'] ?: 'No school year set'); ?></div>
                    </div>
                </article>
                <article class="metric-card">
                    <div class="metric-icon"><i class="bi bi-easel2"></i></div>
                    <div>
                        <div class="metric-label">Section</div>
                        <div class="metric-value compact"><?php echo e($class['section'] ?: 'Not set'); ?></div>
                        <div class="metric-note"><?php echo e($class['subject_name']); ?></div>
                    </div>
                </article>
            </div>

            <section class="content-grid two-columns mt-4">
                <article class="widget-panel">
                    <div class="section-heading">
                        <h2>Quick Actions</h2>
                        <span>Class</span>
                    </div>
                    <div class="class-action-grid">
                        <a class="btn btn-copy" href="<?php echo e(instructor_class_route($classId, 'attendance')); ?>"><i class="bi bi-calendar-check"></i> Attendance</a>
                        <a class="btn btn-copy" href="<?php echo e(instructor_class_route($classId, 'participation')); ?>"><i class="bi bi-chat-square-text"></i> Participation</a>
                        <a class="btn btn-copy" href="<?php echo e(instructor_class_route($classId, 'activities')); ?>"><i class="bi bi-journal-check"></i> Activities</a>
                        <a class="btn btn-copy" href="<?php echo e(instructor_class_route($classId, 'quizzes')); ?>"><i class="bi bi-patch-question"></i> Quizzes</a>
                        <a class="btn btn-copy" href="<?php echo e(instructor_class_route($classId, 'midterm')); ?>"><i class="bi bi-clipboard-data"></i> Midterm</a>
                        <a class="btn btn-copy" href="<?php echo e(instructor_class_route($classId, 'finals')); ?>"><i class="bi bi-clipboard2-check"></i> Finals</a>
                        <a class="btn btn-copy" href="<?php echo e(instructor_class_route($classId, 'analytics')); ?>"><i class="bi bi-graph-up"></i> Analytics</a>
                        <a class="btn btn-copy" href="<?php echo e(instructor_class_route($classId, 'predictions')); ?>"><i class="bi bi-stars"></i> Predictions</a>
                    </div>
                </article>

                <article class="widget-panel">
                    <div class="section-heading">
                        <h2>Invite Link</h2>
                        <span>Share</span>
                    </div>
                    <p>Share this link with students who need to join the class.</p>
                    <div class="invite-row mt-auto">
                        <input type="text" class="form-control" value="<?php echo e($joinLink); ?>" readonly>
                        <button class="btn btn-copy" type="button" data-copy="<?php echo e($joinLink); ?>">
                            <i class="bi bi-link-45deg"></i> Copy link
                        </button>
                    </div>
                </article>
            </section>
        <?php else: ?>
            <article class="widget-panel class-workspace-panel mt-4">
                <div class="section-heading">
                    <h2><?php echo e((string) $viewMeta['label']); ?></h2>
                    <span>Class module</span>
                </div>
                <p><?php echo e((string) $viewMeta['description']); ?></p>
                <div class="empty-state large">
                    <i class="bi <?php echo e((string) $viewMeta['icon']); ?>"></i>
                    <span><?php echo e((string) $viewMeta['label']); ?> workspace for <?php echo e($classLabel); ?> is ready for its module content.</span>
                </div>
            </article>
        <?php endif; ?>
    </section>

    <?php render_edit_class_modal($pdo, $class, $csrfToken); ?>
    <?php
}

render_dashboard_page([
    'role_label' => 'Instructor',
    'fallback_name' => 'Instructor',
    'title' => $pageTitle,
    'eyebrow' => $pageEyebrow,
    'description' => $pageDescription,
    'active_route' => $activeRoute,
    'menu' => $menu,
    'content' => function () use ($pdo, $instructorId, $errors, $successMessage, $formData, $scheduleData, $assessmentTotalsInput, $csrfToken, $classes, $allClasses, $baseJoinUrl, $selectedClass, $classView, $classViewMeta, $searchTerm, $autoOpenModal, $postedAction, $editTargetClassId) {
        ?>
        <?php if ($selectedClass !== null): ?>
            <?php render_instructor_class_workspace($selectedClass, $classView, $classViewMeta, $baseJoinUrl, $pdo, $instructorId, $csrfToken, $errors, $successMessage); ?>
            <?php return; ?>
        <?php endif; ?>

        <?php if ($autoOpenModal !== ''): ?>
            <span data-auto-open-modal="<?php echo e($autoOpenModal); ?>" hidden></span>
        <?php endif; ?>

        <section class="class-section">
            <div class="section-heading">
                <h2>My Classes</h2>
                <div class="d-flex flex-wrap align-items-center gap-2">
                    <span><?php echo count($classes); ?> shown</span>
                    <a class="btn btn-copy" href="<?php echo e(url_for('classes/archived')); ?>">
                        <i class="bi bi-archive"></i> Archived
                    </a>
                    <button class="btn btn-edupredict" type="button" data-bs-toggle="modal" data-bs-target="#createClassModal">
                        <i class="bi bi-plus-circle"></i> Create class
                    </button>
                </div>
            </div>

            <div class="class-toolbar">
                <form class="class-search-form" method="get" action="<?php echo e(url_for('classes')); ?>">
                    <label class="visually-hidden" for="class_search">Search classes</label>
                    <div class="search-input-wrap">
                        <i class="bi bi-search" aria-hidden="true"></i>
                        <input type="search" class="form-control" id="class_search" name="q" value="<?php echo e($searchTerm); ?>" placeholder="Search by class, section, subject, or code">
                    </div>
                    <button class="btn btn-copy" type="submit"><i class="bi bi-search"></i> Search</button>
                    <?php if ($searchTerm !== ''): ?>
                        <a class="btn btn-copy" href="<?php echo e(url_for('classes')); ?>"><i class="bi bi-x-circle"></i> Clear</a>
                    <?php endif; ?>
                </form>
                <span><?php echo count($allClasses); ?> active total</span>
            </div>

            <?php if ($successMessage !== ''): ?>
                <div class="alert alert-success" role="alert"><?php echo e($successMessage); ?></div>
            <?php endif; ?>

            <?php if (!empty($errors) && $autoOpenModal === ''): ?>
                <div class="alert alert-danger" role="alert">
                    <ul class="mb-0">
                        <?php foreach ($errors as $error): ?>
                            <li><?php echo e($error); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <?php if (empty($classes)): ?>
                <div class="empty-state large">
                    <i class="bi bi-easel2"></i>
                    <span><?php echo $searchTerm !== '' ? 'No active classes match your search.' : 'No classes yet. Use Create class to generate your first join code.'; ?></span>
                </div>
            <?php else: ?>
                <div class="class-grid">
                    <?php foreach ($classes as $class): ?>
                        <?php $joinLink = $baseJoinUrl . urlencode((string) $class['class_code']); ?>
                        <article class="class-card">
                            <div class="class-card-top">
                                <div>
                                    <h3><?php echo e($class['class_name']); ?></h3>
                                    <p><?php echo e($class['subject_name']); ?><?php echo !empty($class['section']) ? ' &middot; ' . e($class['section']) : ''; ?></p>
                                </div>
                                <span class="status-pill small"><?php echo e($class['status']); ?></span>
                            </div>
                            <div class="class-meta">
                                <span><i class="bi bi-calendar2-week"></i><?php echo e($class['schedule'] ?: 'No schedule set'); ?></span>
                                <span><i class="bi bi-people"></i><?php echo e((string) $class['student_count']); ?> students</span>
                            </div>
                            <div class="join-box">
                                <div>
                                    <span class="join-label">Class code</span>
                                    <strong><?php echo e($class['class_code']); ?></strong>
                                </div>
                                <button class="btn btn-copy" type="button" data-copy="<?php echo e($class['class_code']); ?>">
                                    <i class="bi bi-copy"></i> Copy code
                                </button>
                            </div>
                            <div class="invite-row">
                                <input type="text" class="form-control" value="<?php echo e($joinLink); ?>" readonly>
                                <button class="btn btn-copy" type="button" data-copy="<?php echo e($joinLink); ?>">
                                    <i class="bi bi-link-45deg"></i> Copy link
                                </button>
                            </div>
                            <div class="class-card-actions">
                                <a class="btn btn-copy" href="<?php echo e(instructor_class_route((int) $class['id'])); ?>">
                                    <i class="bi bi-box-arrow-up-right"></i> Open class
                                </a>
                                <button class="btn btn-copy" type="button" data-bs-toggle="modal" data-bs-target="#editClassModal-<?php echo e((string) $class['id']); ?>">
                                    <i class="bi bi-pencil-square"></i> Edit
                                </button>
                                <form method="post" action="<?php echo e(url_for('pages/instructor/classes.php')); ?>" data-confirm-action="Archive this class? You can restore it from Archived Classes.">
                                    <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                                    <input type="hidden" name="class_action" value="archive">
                                    <input type="hidden" name="class_id" value="<?php echo e((string) $class['id']); ?>">
                                    <button class="btn btn-copy" type="submit"><i class="bi bi-archive"></i> Archive</button>
                                </form>
                                <form method="post" action="<?php echo e(url_for('pages/instructor/classes.php')); ?>" data-confirm-action="Delete this class permanently? This cannot be undone.">
                                    <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                                    <input type="hidden" name="class_action" value="delete">
                                    <input type="hidden" name="class_id" value="<?php echo e((string) $class['id']); ?>">
                                    <button class="btn btn-copy btn-danger-soft" type="submit"><i class="bi bi-trash"></i> Delete</button>
                                </form>
                            </div>
                        </article>

                        <?php render_edit_class_modal($pdo, $class, $csrfToken, $errors, $postedAction, $editTargetClassId, $formData, $scheduleData, $assessmentTotalsInput); ?>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>

        <div class="modal fade" id="createClassModal" tabindex="-1" aria-labelledby="createClassModalLabel" aria-hidden="true">
            <div class="modal-dialog modal-xl modal-dialog-scrollable class-management-modal">
                <div class="modal-content">
                    <div class="modal-header">
                        <div>
                            <h2 class="modal-title h5" id="createClassModalLabel">Create Class</h2>
                            <p class="mb-0 text-secondary small">Set up the class details students will use when joining.</p>
                        </div>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>

                    <form method="post" action="<?php echo e(url_for('pages/instructor/classes.php')); ?>" novalidate>
                        <div class="modal-body">
                            <?php if ($postedAction === 'create' && !empty($errors)): ?>
                                <div class="alert alert-danger" role="alert">
                                    <p class="fw-semibold mb-2">Please fix the following:</p>
                                    <ul class="mb-0">
                                        <?php foreach ($errors as $error): ?>
                                            <li><?php echo e($error); ?></li>
                                        <?php endforeach; ?>
                                    </ul>
                                </div>
                            <?php endif; ?>

                            <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                            <input type="hidden" name="class_action" value="create">

                            <div class="form-grid">
                                <div class="field field-wide">
                                    <label for="class_name" class="form-label">Class name</label>
                                    <input type="text" class="form-control" id="class_name" name="class_name" value="<?php echo e($formData['class_name']); ?>" maxlength="150" placeholder="Example: Grade 11 STEM - Mathematics" required>
                                </div>
                                <div class="field">
                                    <label for="subject_name" class="form-label">Subject name</label>
                                    <input type="text" class="form-control" id="subject_name" name="subject_name" value="<?php echo e($formData['subject_name']); ?>" maxlength="150" placeholder="General Mathematics" required>
                                </div>
                                <div class="field">
                                    <label for="subject_code" class="form-label">Subject code</label>
                                    <input type="text" class="form-control" id="subject_code" name="subject_code" value="<?php echo e($formData['subject_code']); ?>" maxlength="50" placeholder="MATH-101">
                                </div>
                                <div class="field">
                                    <label for="section" class="form-label">Section</label>
                                    <input type="text" class="form-control" id="section" name="section" value="<?php echo e($formData['section']); ?>" maxlength="100" placeholder="STEM 11-A">
                                </div>
                                <div class="field">
                                    <label for="schedule" class="form-label">Schedule</label>
                                    <input type="text" class="form-control" id="schedule" name="schedule" value="<?php echo e($formData['schedule']); ?>" maxlength="150" placeholder="MWF 9:00 AM - 10:00 AM">
                                </div>
                                <div class="field">
                                    <label for="school_year" class="form-label">School year</label>
                                    <input type="text" class="form-control" id="school_year" name="school_year" value="<?php echo e($formData['school_year']); ?>" maxlength="20" placeholder="2026-2027">
                                </div>
                                <div class="field">
                                    <label for="term" class="form-label">Term</label>
                                    <input type="text" class="form-control" id="term" name="term" value="<?php echo e($formData['term']); ?>" maxlength="50" placeholder="First Semester">
                                </div>
                                <div class="field field-wide">
                                    <label for="description" class="form-label">Description</label>
                                    <textarea class="form-control" id="description" name="description" rows="4" maxlength="1000" placeholder="Optional class notes for students"><?php echo e($formData['description']); ?></textarea>
                                </div>
                            </div>

                            <?php render_teaching_schedule_fields($scheduleData, 'create_'); ?>
                            <?php render_assessment_config_fields($assessmentTotalsInput['activity'], $assessmentTotalsInput['quiz'], 'create_'); ?>
                        </div>

                        <div class="modal-footer">
                            <button type="button" class="btn btn-copy" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" class="btn btn-edupredict"><i class="bi bi-plus-circle"></i> Create class</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        <?php
    },
]);
