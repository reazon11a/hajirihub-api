<?php
// routes/classes.php – CRUD for classes + student enrollment + join codes

function handleClasses(string $method, array $segments): void {
    $payload = requireAuth();
    $userId  = $payload['sub'];
    $db      = getDB();

    // ── POST /classes/join  (student joins by join code) ─────────────────────
    if (count($segments) === 2 && $segments[1] === 'join' && $method === 'POST') {
        $body = jsonBody();
        $code = strtoupper(trim($body['code'] ?? ''));
        if (!$code) jsonError('Join code is required');

        $stmt = $db->prepare("SELECT id, name, teacher_id, join_code FROM classes WHERE join_code = ?");
        $stmt->execute([$code]);
        $class = $stmt->fetch();
        if (!$class) jsonError('Invalid join code', 404);

        // check already enrolled
        $already = $db->prepare("SELECT id FROM class_students WHERE class_id = ? AND student_id = ?");
        $already->execute([$class['id'], $userId]);
        if ($already->fetch()) jsonOk(['class' => $class, 'already_enrolled' => true]);

        $enrollId = generateUUID();
        $db->prepare("INSERT INTO class_students (id, class_id, student_id, roll_no) VALUES (?,?,?,'')")
           ->execute([$enrollId, $class['id'], $userId]);

        jsonOk(['class' => $class], 201);
    }

    // All other routes require teacher ownership
    $teacherId = $userId;

    // /classes
    if (count($segments) === 1) {
        if ($method === 'GET') {
            $stmt = $db->prepare("
                SELECT c.id, c.name, c.created_at,
                       COUNT(DISTINCT cs.id) AS students,
                       COUNT(DISTINCT qs.id) AS sessions
                FROM classes c
                LEFT JOIN class_students cs ON cs.class_id = c.id
                LEFT JOIN qr_sessions     qs ON qs.class_id = c.id
                WHERE c.teacher_id = ?
                GROUP BY c.id, c.name, c.created_at
                ORDER BY c.created_at DESC
            ");
            $stmt->execute([$teacherId]);
            jsonOk(['classes' => $stmt->fetchAll()]);
        }

        if ($method === 'POST') {
            $body = jsonBody();
            $name = trim($body['name'] ?? '');
            if (!$name) jsonError('Class name is required');

            $code = strtoupper(substr(str_shuffle('ABCDEFGHJKLMNPQRSTUVWXYZ23456789'), 0, 6));
            $id   = generateUUID();
            $db->prepare("INSERT INTO classes (id, name, teacher_id, join_code) VALUES (?,?,?,?)")
               ->execute([$id, $name, $teacherId, $code]);

            $stmt = $db->prepare("SELECT id, name, join_code, created_at FROM classes WHERE id = ?");
            $stmt->execute([$id]);
            jsonOk(['class' => $stmt->fetch()], 201);
        }
        jsonError('Method not allowed', 405);
    }

    // /classes/{id}
    $classId = $segments[1];

    // verify ownership
    $owns = $db->prepare("SELECT id FROM classes WHERE id = ? AND teacher_id = ?");
    $owns->execute([$classId, $teacherId]);
    if (!$owns->fetch()) jsonError('Class not found or access denied', 404);

    if ($method === 'DELETE') {
        $db->prepare("DELETE FROM classes WHERE id = ?")->execute([$classId]);
        jsonOk(['message' => 'Class deleted']);
    }

    // ── /classes/{id}/join-code ─────────────────────────────────────────────
    if (($segments[2] ?? '') === 'join-code') {
        if ($method === 'GET') {
            $stmt = $db->prepare("SELECT join_code FROM classes WHERE id = ?");
            $stmt->execute([$classId]);
            $row = $stmt->fetch();
            jsonOk(['code' => $row['join_code'] ?? null]);
        }
        if ($method === 'POST') {
            // generate (or regenerate) a unique 6-char code
            do {
                $newCode = strtoupper(substr(str_shuffle('ABCDEFGHJKLMNPQRSTUVWXYZ23456789'), 0, 6));
                $check = $db->prepare("SELECT id FROM classes WHERE join_code = ? AND id != ?");
                $check->execute([$newCode, $classId]);
            } while ($check->fetch());

            $db->prepare("UPDATE classes SET join_code = ? WHERE id = ?")
               ->execute([$newCode, $classId]);
            jsonOk(['code' => $newCode]);
        }
        jsonError('Method not allowed', 405);
    }

    // /classes/{id}/students
    if (($segments[2] ?? '') === 'students') {
        if ($method === 'GET') {
            $stmt = $db->prepare("
                SELECT cs.id, cs.roll_no, cs.enrolled_at,
                       s.id AS student_id, s.name, s.email, s.semester
                FROM class_students cs
                JOIN students s ON s.id = cs.student_id
                WHERE cs.class_id = ?
                ORDER BY cs.roll_no
            ");
            $stmt->execute([$classId]);
            jsonOk(['students' => $stmt->fetchAll()]);
        }

        if ($method === 'POST') {
            $body     = jsonBody();
            $rollNo   = trim($body['roll_no']   ?? '');
            $name     = trim($body['name']      ?? '');
            $email    = trim(strtolower($body['email'] ?? ''));
            $semester = (int)($body['semester'] ?? 0);

            if (!$rollNo || !$name || !$email) jsonError('roll_no, name and email are required');
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) jsonError('Invalid email');

            // find or create student (by roll_no + semester)
            $find = $db->prepare("SELECT id FROM students WHERE roll_no = ? AND semester = ? LIMIT 1");
            $find->execute([$rollNo, $semester]);
            $existing = $find->fetch();
            $studentId = $existing['id'] ?? null;

            if (!$studentId) {
                $studentId = generateUUID();
                // temporary placeholder password – student sets real one on signup
                $hash = password_hash(bin2hex(random_bytes(16)), PASSWORD_BCRYPT);
                $db->prepare("INSERT INTO students (id, name, email, password, roll_no, semester) VALUES (?,?,?,?,?,?)")
                   ->execute([$studentId, $name, $email, $hash, $rollNo, $semester]);
            }

            // check already enrolled
            $enrolled = $db->prepare("SELECT id FROM class_students WHERE class_id = ? AND student_id = ?");
            $enrolled->execute([$classId, $studentId]);
            if ($enrolled->fetch()) {
                jsonOk(['message' => 'Student already enrolled', 'already_enrolled' => true]);
            }

            $enrollId = generateUUID();
            $db->prepare("INSERT INTO class_students (id, class_id, student_id, roll_no) VALUES (?,?,?,?)")
               ->execute([$enrollId, $classId, $studentId, $rollNo]);

            jsonOk(['message' => 'Student enrolled', 'student_id' => $studentId], 201);
        }

        // DELETE /classes/{id}/students/{enrollId}  – remove one student
        if ($method === 'DELETE' && isset($segments[3])) {
            $enrollId = $segments[3];
            $stmt = $db->prepare("DELETE FROM class_students WHERE id = ? AND class_id = ?");
            $stmt->execute([$enrollId, $classId]);
            if ($stmt->rowCount() === 0) jsonError('Enrollment not found', 404);
            jsonOk(['message' => 'Student removed']);
        }

        // POST /classes/{id}/students/bulk-remove
        if ($method === 'POST' && ($segments[3] ?? '') === 'bulk-remove') {
            $body = jsonBody();
            $ids  = array_filter(array_map('strval', (array)($body['enrollment_ids'] ?? [])));
            if (empty($ids)) jsonError('enrollment_ids required');

            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $params = array_merge([$classId], array_values($ids));
            $db->prepare("DELETE FROM class_students WHERE class_id = ? AND id IN ({$placeholders})")
               ->execute($params);
            jsonOk(['message' => 'Students removed', 'count' => count($ids)]);
        }

        jsonError('Method not allowed', 405);
    }

    jsonError('Not found', 404);
}
