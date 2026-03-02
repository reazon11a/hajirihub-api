<?php
// routes/attendance.php – mark attendance (QR + manual), report, trends, student view

function handleAttendance(string $method, array $segments): void {
    $payload = requireAuth();
    $db      = getDB();
    $action  = $segments[1] ?? '';

    // POST /attendance/mark – student scans QR
    if ($method === 'POST' && $action === 'mark') {
        if ($payload['role'] !== 'student') jsonError('Only students can mark attendance via QR', 403);
        $body      = jsonBody();
        $sessionId = $body['session_id'] ?? '';
        $classId   = $body['class_id']   ?? '';
        $studentId = $payload['sub'];

        if (!$sessionId) jsonError('session_id is required');

        // fetch session
        $ss = $db->prepare("SELECT * FROM qr_sessions WHERE id = ?");
        $ss->execute([$sessionId]);
        $session = $ss->fetch();
        if (!$session) jsonError('QR session not found or expired', 404);

        // check expiry
        if (new DateTime() > new DateTime($session['expires_at'])) {
            jsonError('QR code has expired', 410);
        }

        $classId = $classId ?: $session['class_id'];

        // check enrollment
        $en = $db->prepare("SELECT id FROM class_students WHERE student_id = ? AND class_id = ?");
        $en->execute([$studentId, $classId]);
        if (!$en->fetch()) jsonError('You are not enrolled in this class', 403);

        // check duplicate
        $dup = $db->prepare("SELECT id FROM attendance_records WHERE student_id = ? AND session_id = ?");
        $dup->execute([$studentId, $sessionId]);
        if ($dup->fetch()) {
            jsonOk(['message' => 'Attendance already recorded', 'already_marked' => true]);
        }

        $id = generateUUID();
        $db->prepare("INSERT INTO attendance_records (id, class_id, student_id, session_id, status) VALUES (?,?,?,?,?)")
           ->execute([$id, $classId, $studentId, $sessionId, 'present']);

        jsonOk(['message' => 'Attendance marked successfully']);
    }

    // POST /attendance/manual – teacher marks multiple students present
    if ($method === 'POST' && $action === 'manual') {
        if ($payload['role'] !== 'teacher') jsonError('Only teachers can mark manual attendance', 403);
        $body       = jsonBody();
        $classId    = $body['class_id']    ?? '';
        $studentIds = $body['student_ids'] ?? [];

        if (!$classId || empty($studentIds)) jsonError('class_id and student_ids are required');

        // verify teacher owns class
        $owns = $db->prepare("SELECT id FROM classes WHERE id = ? AND teacher_id = ?");
        $owns->execute([$classId, $payload['sub']]);
        if (!$owns->fetch()) jsonError('Class not found or access denied', 404);

        // create a manual qr_session
        $sessionId = generateUUID();
        $expires   = (new DateTime())->modify('+1 minute')->format('Y-m-d H:i:s');
        $db->prepare("INSERT INTO qr_sessions (id, class_id, expires_at, session_data) VALUES (?,?,?,?)")
           ->execute([$sessionId, $classId, $expires, json_encode(['type' => 'manual'])]);

        $inserted = 0;
        foreach ($studentIds as $sid) {
            try {
                $id = generateUUID();
                $db->prepare("INSERT INTO attendance_records (id, class_id, student_id, session_id, status) VALUES (?,?,?,?,?)")
                   ->execute([$id, $classId, $sid, $sessionId, 'present']);
                $inserted++;
            } catch (Exception) {}
        }

        jsonOk(['message' => "Marked {$inserted} students present"]);
    }

    // GET /attendance/report?class_id=&start=&end=
    if ($method === 'GET' && $action === 'report') {
        if ($payload['role'] !== 'teacher') jsonError('Forbidden', 403);
        $classId = $_GET['class_id'] ?? '';
        $start   = $_GET['start']    ?? '';
        $end     = $_GET['end']      ?? '';

        if (!$classId) jsonError('class_id is required');

        $sql    = "SELECT * FROM attendance_records WHERE class_id = ?";
        $params = [$classId];

        if ($start) { $sql .= " AND marked_at >= ?"; $params[] = $start . ' 00:00:00'; }
        if ($end)   { $sql .= " AND marked_at <= ?"; $params[] = $end   . ' 23:59:59'; }

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        jsonOk(['records' => $stmt->fetchAll()]);
    }

    // GET /attendance/trends?class_id=&class_ids=
    if ($method === 'GET' && $action === 'trends') {
        if ($payload['role'] !== 'teacher') jsonError('Forbidden', 403);
        $classId  = $_GET['class_id']  ?? '';
        $classIds = $_GET['class_ids'] ?? '';  // comma-separated

        $params = [];
        if ($classId) {
            $sql    = "SELECT status, marked_at, session_id, class_id FROM attendance_records WHERE class_id = ?";
            $params = [$classId];
        } elseif ($classIds) {
            $ids      = array_filter(explode(',', $classIds));
            $holders  = implode(',', array_fill(0, count($ids), '?'));
            $sql      = "SELECT status, marked_at, session_id, class_id FROM attendance_records WHERE class_id IN ({$holders})";
            $params   = $ids;
        } else {
            jsonError('class_id or class_ids is required');
        }

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        jsonOk(['records' => $stmt->fetchAll()]);
    }

    // GET /attendance/student – enrolled classes + attendance stats for logged-in student
    if ($method === 'GET' && $action === 'student') {
        if ($payload['role'] !== 'student') jsonError('Forbidden', 403);
        $studentId = $payload['sub'];

        // enrolled classes with teacher name
        $stmt = $db->prepare("
            SELECT cs.class_id, c.name AS class_name, t.name AS teacher_name
            FROM class_students cs
            JOIN classes  c ON c.id = cs.class_id
            JOIN teachers t ON t.id = c.teacher_id
            WHERE cs.student_id = ?
        ");
        $stmt->execute([$studentId]);
        $enrollments = $stmt->fetchAll();

        $classes = [];
        foreach ($enrollments as $e) {
            $cid = $e['class_id'];

            // total sessions
            $s = $db->prepare("SELECT COUNT(*) AS cnt FROM qr_sessions WHERE class_id = ?");
            $s->execute([$cid]);
            $totalSessions = (int)$s->fetch()['cnt'];

            // present records
            $p = $db->prepare("
                SELECT session_id FROM attendance_records
                WHERE class_id = ? AND student_id = ? AND status = 'present'
            ");
            $p->execute([$cid, $studentId]);
            $presentRows = $p->fetchAll();

            $uniqueSessions = count(array_unique(array_filter(array_column($presentRows, 'session_id'))));
            $manualPresent  = count(array_filter($presentRows, fn($r) => $r['session_id'] === null));
            $totalAttended  = $uniqueSessions + $manualPresent;
            $totalClasses   = max($totalSessions, $totalAttended);
            $pct = $totalClasses > 0 ? min(100, round($totalAttended / $totalClasses * 100)) : 0;

            $classes[] = [
                'id'         => $cid,
                'name'       => $e['class_name'],
                'teacher'    => $e['teacher_name'],
                'attendance' => $pct,
                'attended'   => $totalAttended,
                'total'      => $totalClasses,
            ];
        }

        // recent activity
        $recent = $db->prepare("
            SELECT ar.status, ar.marked_at, c.name AS class_name
            FROM attendance_records ar
            JOIN classes c ON c.id = ar.class_id
            WHERE ar.student_id = ?
            ORDER BY ar.marked_at DESC
            LIMIT 10
        ");
        $recent->execute([$studentId]);

        jsonOk(['classes' => $classes, 'recent' => $recent->fetchAll()]);
    }

    jsonError('Not found', 404);
}
