<?php
// logout.php
require_once 'includes/config.php';
require_once 'includes/session.php';

// Logout the user
SessionManager::logout();

// Redirect to login page with message
SessionManager::setMessage('You have been successfully logged out.', 'success');
header('Location: login.php');
exit();
?>