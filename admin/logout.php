<?php
require_once __DIR__ . '/../includes/auth.php';
adminLogout();
header('Location: login.php');
exit;
