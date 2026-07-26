<?php

/*
 * Stage 05, Block 5 (finding M6) — resources/views/errors/ didn't exist and
 * the app fell back to Laravel's default error pages: English only, no
 * branding, no way out. In this system a 403 is a design response (not an
 * edge case) every time a role touches something outside its scope, so it
 * needs clear copy and a working exit link.
 */
return [
    'title' => 'Error :code',

    '403_heading' => 'Access not allowed',
    '403_message' => 'Your account does not have permission to view this page. If you think this is a mistake, contact an administrator.',

    '404_heading' => 'Page not found',
    '404_message' => 'The page you are looking for does not exist or was moved.',

    '419_heading' => 'Page expired',
    '419_message' => 'Your session expired due to inactivity. Go back and try again.',

    '500_heading' => 'Server error',
    '500_message' => 'Something went wrong on our end. It has already been logged; please try again in a few minutes.',

    '503_heading' => 'Scheduled maintenance',
    '503_message' => 'The system is undergoing scheduled maintenance. Please try again shortly.',

    'exit_panel' => 'Back to the panel',
    'exit_field' => 'Back to home',
    'exit_login' => 'Go to login',
];
