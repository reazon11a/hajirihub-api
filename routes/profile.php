<?php
// routes/profile.php – get/update teacher or student profile

function handleProfile(string $method, array $segments): void {
    $payload = requireAuth();
    $role    = $payload['role'];
    $userId  = $payload['sub'];
    $db      = getDB();
    $table   = $role === 'teacher' ? 'teachers' : 'students';

    if ($method === 'GET') {
        $stmt = $db->prepare("SELECT * FROM {$table} WHERE id = ? LIMIT 1");
        $stmt->execute([$userId]);
        $user = $stmt->fetch();
        if (!$user) jsonError('Not found', 404);
        unset($user['password']);
        jsonOk(['user' => $user]);
    }

    if ($method === 'PUT') {
        $body = jsonBody();
        $name = trim($body['name'] ?? '');
        if (!$name) jsonError('Name is required');

        if ($role === 'teacher') {
            $dept = trim($body['department'] ?? '');
            $db->prepare("UPDATE teachers SET name = ?, department = ? WHERE id = ?")
               ->execute([$name, $dept, $userId]);
        } else {
            $rollNo   = trim($body['roll_no']  ?? '');
            $semester = (int)($body['semester'] ?? 0);
            if ($semester < 1 || $semester > 8) jsonError('Semester must be between 1 and 8');
            $db->prepare("UPDATE students SET name = ?, roll_no = ?, semester = ? WHERE id = ?")
               ->execute([$name, $rollNo, $semester, $userId]);
        }

        $stmt = $db->prepare("SELECT * FROM {$table} WHERE id = ?");
        $stmt->execute([$userId]);
        $user = $stmt->fetch();
        unset($user['password']);
        jsonOk(['user' => $user]);
    }

    jsonError('Method not allowed', 405);
}
