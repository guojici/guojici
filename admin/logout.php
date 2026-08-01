<?php
require_once __DIR__ . '/../config/auth.php';
admin_logout();
header('Location: /admin/login.php');
exit;
