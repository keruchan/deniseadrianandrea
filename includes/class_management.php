<?php
/**
 * Shared class management helpers for instructor pages.
 */

function class_form_defaults(): array
{
    return [
        'class_name' => '',
        'section' => '',
        'subject_name' => '',
        'subject_code' => '',
        'schedule' => '',
        'school_year' => '',
        'term' => '',
        'description' => '',
    ];
}

function class_form_data_from_array(array $source): array
{
    $data = class_form_defaults();

    foreach ($data as $key => $value) {
        $data[$key] = trim((string) ($source[$key] ?? ''));
    }

    return $data;
}

function validate_class_form_data(array $formData): array
{
    $errors = [];

    if ($formData['class_name'] === '') {
        $errors[] = 'Class name is required.';
    } elseif (text_length($formData['class_name']) > 150) {
        $errors[] = 'Class name must not exceed 150 characters.';
    }

    if ($formData['subject_name'] === '') {
        $errors[] = 'Subject name is required.';
    } elseif (text_length($formData['subject_name']) > 150) {
        $errors[] = 'Subject name must not exceed 150 characters.';
    }

    if ($formData['section'] !== '' && text_length($formData['section']) > 100) {
        $errors[] = 'Section must not exceed 100 characters.';
    }

    if ($formData['subject_code'] !== '' && text_length($formData['subject_code']) > 50) {
        $errors[] = 'Subject code must not exceed 50 characters.';
    }

    if ($formData['schedule'] !== '' && text_length($formData['schedule']) > 150) {
        $errors[] = 'Schedule must not exceed 150 characters.';
    }

    if ($formData['school_year'] !== '' && text_length($formData['school_year']) > 20) {
        $errors[] = 'School year must not exceed 20 characters.';
    }

    if ($formData['term'] !== '' && text_length($formData['term']) > 50) {
        $errors[] = 'Term must not exceed 50 characters.';
    }

    if ($formData['description'] !== '' && text_length($formData['description']) > 1000) {
        $errors[] = 'Description must not exceed 1000 characters.';
    }

    return $errors;
}

function instructor_class_exists(PDO $pdo, int $instructorId, int $classId, ?string $status = null): bool
{
    $sql = 'SELECT COUNT(*)
            FROM classes
            WHERE id = :class_id AND instructor_id = :instructor_id';
    $params = [
        ':class_id' => $classId,
        ':instructor_id' => $instructorId,
    ];

    if ($status !== null) {
        $sql .= ' AND status = :status';
        $params[':status'] = $status;
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return (int) $stmt->fetchColumn() > 0;
}

function update_instructor_class_status(PDO $pdo, int $instructorId, int $classId, string $status): bool
{
    $stmt = $pdo->prepare(
        'UPDATE classes
         SET status = :status
         WHERE id = :class_id AND instructor_id = :instructor_id'
    );
    $stmt->execute([
        ':status' => $status,
        ':class_id' => $classId,
        ':instructor_id' => $instructorId,
    ]);

    return $stmt->rowCount() > 0;
}

function delete_instructor_class(PDO $pdo, int $instructorId, int $classId): bool
{
    $stmt = $pdo->prepare(
        'DELETE FROM classes
         WHERE id = :class_id AND instructor_id = :instructor_id'
    );
    $stmt->execute([
        ':class_id' => $classId,
        ':instructor_id' => $instructorId,
    ]);

    return $stmt->rowCount() > 0;
}
