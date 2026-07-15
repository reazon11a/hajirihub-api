<?php
// routes/leave_requests.php

function handleLeaveRequests(string $method, array $segments): void {
    $payload = requireAuth();
    $userId  = $payload['sub'];
    $role    = $payload['role'] ?? 'student';
    $db      = getDB();

    // GET /leave-requests/mine  (student: list own requests)
    if (count($segments) === 2 && $segments[1] === 'mine' && $method === 'GET') {
        $stmt = $db->prepare("
            SELECT lr.id, lr.student_id, lr.class_id, lr.date, lr.reason,
                   lr.status, lr.created_at, c.name AS class_name
            FROM leave_requests lr
            JOIN classes c ON c.id = lr.class_id
            WHERE lr.student_id = ?
            ORDER BY lr.created_at DESC
        ");
        $stmt->execute([$userId]);
        jsonOk(['requests' => $stmt->fetchAll()]);
    }

    // GET /leave-requests  (teacher: list all requests for their classes)
    if (count($segments) === 1 && $method === 'GET') {
        $stmt = $db->prepare("
            SELECT lr.id, lr.student_id, lr.class_id, lr.date, lr.reason,
                   lr.status, lr.created_at,
                   s.name  AS student_name,
                   c.name  AS class_name
            FROM leave_requests lr
            JOIN students s ON s.id = lr.student_id
            JOIN classes  c ON c.id = lr.class_id
            WHERE c.teacher_id = ?
            ORDER BY lr.created_at DESC
        ");
        $stmt->execute([$userId]);
        jsonOk(['requests' => $stmt->fetchAll()]);
    }

    // POST /leave-requests  (student: submit a new request)
    if (count($segments) === 1 && $method === 'POST') {
        $body    = jsonBody();
        $classId = trim($body['class_id'] ?? '');
        $date    = trim($body['date']     ?? '');
        $reason  = trim($body['reason']   ?? '');

        if (!$classId || !$date || !$reason) jsonError('class_id, date, and reason are required');

        // validate student is enrolled
        $enrolled = $db->prepare("SELECT id FROM class_students WHERE class_id = ? AND student_id = ?");
        $enrolled->execute([$classId, $userId]);
        if (!$enrolled->fetch()) jsonError('You are not enrolled in this class', 403);

        $id = generateUUID();
        $db->prepare("INSERT INTO leave_requests (id, student_id, class_id, date, reason) VALUES (?,?,?,?,?)")
           ->execute([$id, $userId, $classId, $date, $reason]);

        $stmt = $db->prepare("SELECT lr.*, c.name AS class_name FROM leave_requests lr JOIN classes c ON c.id = lr.class_id WHERE lr.id = ?");
        $stmt->execute([$id]);
        jsonOk(['request' => $stmt->fetch()], 201);
    }

    // PUT /leave-requests/{id}  (teacher: approve or reject)
    if (count($segments) === 2 && $method === 'PUT') {
        $requestId = $segments[1];
        $body   = jsonBody();
        $status = $body['status'] ?? '';

        if (!in_array($status, ['approved', 'rejected'], true)) {
            jsonError('status must be approved or rejected');
        }

        // verify teacher owns the class this request belongs to
        $owns = $db->prepare("
            SELECT lr.id FROM leave_requests lr
            JOIN classes c ON c.id = lr.class_id
            WHERE lr.id = ? AND c.teacher_id = ?
        ");
        $owns->execute([$requestId, $userId]);
        if (!$owns->fetch()) jsonError('Request not found or access denied', 404);

        $db->prepare("UPDATE leave_requests SET status = ? WHERE id = ?")
           ->execute([$status, $requestId]);

        jsonOk(['message' => 'Request updated', 'status' => $status]);
    }

    jsonError('Not found', 404);
}
