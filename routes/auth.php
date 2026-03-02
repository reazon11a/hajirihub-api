<?php
// routes/auth.php – login, signup, me, update password

use Firebase\JWT\JWT;

function handleAuth(string $method, array $segments): void {
    $action = $segments[1] ?? '';

    if ($method === 'POST' && $action === 'login')    { authLogin();   return; }
    if ($method === 'POST' && $action === 'signup')   { authSignup();  return; }
    if ($method === 'GET'  && $action === 'me')       { authMe();      return; }
    if ($method === 'POST' && $action === 'logout')   { jsonOk(['message' => 'Logged out']); return; }
    if ($method === 'PUT'  && $action === 'password') { authPassword(); return; }

    jsonError('Not found', 404);
}

function authLogin(): void {
    $body = jsonBody();
    $email    = trim($body['email']    ?? '');
    $password = $body['password'] ?? '';
    $role     = $body['role']     ?? ''; // 'teacher' | 'student'

    if (!$email || !$password || !in_array($role, ['teacher', 'student'])) {
        jsonError('Email, password and role are required');
    }

    $db    = getDB();
    $table = $role === 'teacher' ? 'teachers' : 'students';
    $stmt  = $db->prepare("SELECT * FROM {$table} WHERE email = ? LIMIT 1");
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password'])) {
        jsonError('Invalid email or password', 401);
    }

    unset($user['password']);
    $token = makeToken($user['id'], $role);
    jsonOk(['token' => $token, 'user' => $user, 'role' => $role]);
}

function authSignup(): void {
    $body       = jsonBody();
    $role       = $body['role']       ?? '';
    $name       = trim($body['name']  ?? '');
    $email      = trim(strtolower($body['email'] ?? ''));
    $password   = $body['password']   ?? '';
    $department = trim($body['department'] ?? '');
    $rollNo     = trim($body['roll_no']    ?? '');
    $semester   = (int)($body['semester']  ?? 0);

    if (!$name || !$email || !$password || !in_array($role, ['teacher', 'student'])) {
        jsonError('name, email, password and role are required');
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        jsonError('Invalid email address');
    }
    if (strlen($password) < 8) {
        jsonError('Password must be at least 8 characters');
    }

    $db    = getDB();
    $table = $role === 'teacher' ? 'teachers' : 'students';

    $check = $db->prepare("SELECT id FROM {$table} WHERE email = ? LIMIT 1");
    $check->execute([$email]);
    if ($check->fetch()) jsonError('Email already registered', 409);

    $id   = generateUUID();
    $hash = password_hash($password, PASSWORD_BCRYPT);

    if ($role === 'teacher') {
        $db->prepare("INSERT INTO teachers (id, name, email, password, department) VALUES (?,?,?,?,?)")
           ->execute([$id, $name, $email, $hash, $department]);
    } else {
        if ($semester < 1 || $semester > 8) jsonError('Semester must be between 1 and 8');
        $db->prepare("INSERT INTO students (id, name, email, password, roll_no, semester) VALUES (?,?,?,?,?,?)")
           ->execute([$id, $name, $email, $hash, $rollNo, $semester]);
    }

    $stmt = $db->prepare("SELECT * FROM {$table} WHERE id = ?");
    $stmt->execute([$id]);
    $user = $stmt->fetch();
    unset($user['password']);

    $token = makeToken($id, $role);
    jsonOk(['token' => $token, 'user' => $user, 'role' => $role], 201);
}

function authMe(): void {
    $payload = requireAuth();
    $db      = getDB();
    $table   = $payload['role'] === 'teacher' ? 'teachers' : 'students';

    $stmt = $db->prepare("SELECT * FROM {$table} WHERE id = ? LIMIT 1");
    $stmt->execute([$payload['sub']]);
    $user = $stmt->fetch();

    if (!$user) jsonError('User not found', 404);
    unset($user['password']);
    jsonOk(['user' => $user, 'role' => $payload['role']]);
}

function authPassword(): void {
    $payload  = requireAuth();
    $body     = jsonBody();
    $password = $body['password'] ?? '';

    if (strlen($password) < 8) jsonError('Password must be at least 8 characters');

    $table = $payload['role'] === 'teacher' ? 'teachers' : 'students';
    $hash  = password_hash($password, PASSWORD_BCRYPT);
    $db    = getDB();
    $db->prepare("UPDATE {$table} SET password = ? WHERE id = ?")->execute([$hash, $payload['sub']]);
    jsonOk(['message' => 'Password updated']);
}

function makeToken(string $userId, string $role): string {
    $now = time();
    return JWT::encode([
        'sub'  => $userId,
        'role' => $role,
        'iat'  => $now,
        'exp'  => $now + JWT_EXPIRY,
    ], JWT_SECRET, 'HS256');
}
