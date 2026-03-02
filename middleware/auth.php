<?php

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

function requireAuth(): array {
    $headers = getallheaders();
    $auth    = $headers['Authorization'] ?? $headers['authorization'] ?? '';

    if (!str_starts_with($auth, 'Bearer ')) {
        jsonError('Unauthorized', 401);
    }

    $token = substr($auth, 7);
    try {
        $decoded = JWT::decode($token, new Key(JWT_SECRET, 'HS256'));
        return (array) $decoded;
    } catch (Exception $e) {
        jsonError('Invalid or expired token', 401);
    }
}
