<?php
// routes/qr.php – create QR sessions, get session info for scanning

function handleQr(string $method, array $segments): void {
    $payload = requireAuth();
    $db      = getDB();

    // POST /qr-sessions  – teacher creates a session
    if ($method === 'POST' && count($segments) === 1) {
        if ($payload['role'] !== 'teacher') jsonError('Only teachers can create QR sessions', 403);

        $body      = jsonBody();
        $classId   = $body['class_id']    ?? '';
        $expiresAt = $body['expires_at']  ?? '';
        $sessionData = $body['session_data'] ?? new stdClass();

        if (!$classId || !$expiresAt) jsonError('class_id and expires_at are required');

        // verify teacher owns the class
        $owns = $db->prepare("SELECT id FROM classes WHERE id = ? AND teacher_id = ?");
        $owns->execute([$classId, $payload['sub']]);
        if (!$owns->fetch()) jsonError('Class not found or access denied', 404);

        $id = generateUUID();
        $db->prepare("INSERT INTO qr_sessions (id, class_id, expires_at, session_data) VALUES (?,?,?,?)")
           ->execute([$id, $classId, $expiresAt, json_encode($sessionData)]);

        $stmt = $db->prepare("SELECT * FROM qr_sessions WHERE id = ?");
        $stmt->execute([$id]);
        $session = $stmt->fetch();
        $session['session_data'] = json_decode($session['session_data'], true);

        jsonOk(['session' => $session], 201);
    }

    // GET /qr-sessions/{id}  – anyone authenticated can read (for scanner)
    if ($method === 'GET' && isset($segments[1])) {
        $sessionId = $segments[1];
        $stmt = $db->prepare("SELECT * FROM qr_sessions WHERE id = ?");
        $stmt->execute([$sessionId]);
        $session = $stmt->fetch();
        if (!$session) jsonError('Session not found', 404);

        $session['session_data'] = json_decode($session['session_data'], true);
        jsonOk(['session' => $session]);
    }

    jsonError('Not found', 404);
}
