<?php

define('JWT_SECRET', getenv('JWT_SECRET') ?: 'hajiri-hub-secret-key-change-in-production-2024');
define('JWT_EXPIRY', 60 * 60 * 24 * 7); // 7 days
