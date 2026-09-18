<?php

return [
    // Login
    'login_title' => 'Sign in',
    'login_email' => 'Username',
    'login_password' => 'Password',
    'login_submit' => 'Sign in',
    'login_error' => 'Incorrect email or password.',
    'login_throttled' => 'Too many attempts. Wait :seconds seconds before trying again.',

    // Nav
    'nav_home' => 'Home',
    'nav_logout' => 'Log out',
    'back' => 'Back',

    // Home
    'home_title' => 'Home',
    'home_greeting' => 'Hi, :name',
    'home_go_fuel' => 'Log fuel',
    'home_go_report' => 'Field report',
    'home_go_foreman' => 'My job site',
    'home_go_admin' => 'Go to admin panel',
    'home_go_notifications' => 'Notifications',
    'home_no_access' => 'You do not have a screen assigned yet. Contact your supervisor.',

    // Notifications (/field inbox; same table as the panel bell)
    'notifications_title' => 'Notifications',
    'notifications_empty' => 'You have no notifications.',
    'notifications_mark_read' => 'Mark as read',
    'notifications_mark_all_read' => 'Mark all as read',

    // Machine picker (shared)
    'machine_search_label' => 'Machine',
    'machine_search_placeholder' => 'Type the machine ID…',
    'machine_change' => 'Change',
    'validation_required_machine' => 'Select a machine.',
    'hours_regressive' => 'The reading (:hours h) is lower than the last one recorded (:current h). Check the value before submitting.',
    'hours_above_next' => 'The reading (:hours h) is higher than a later one already recorded (:next h). An hour meter cannot go down over time: check the date or the value.',

    // Geolocation
    'geolocation_capturing' => 'Getting your location…',
    'geolocation_ok' => 'Location captured',
    'geolocation_error_denied' => 'You didn\'t allow access to your location. Enable it in your browser permissions so it can be captured.',
    'geolocation_error_unavailable' => 'Your location could not be obtained. It might be the signal at this spot — try again.',
    'geolocation_error_unsupported' => 'This device can\'t get your location. You can still send the report, but it will be saved without one.',
    'geolocation_retry' => 'Retry',

    // Fuel
    'fuel_title' => 'Log fuel',
    'fuel_gallons' => 'Gallons',
    'fuel_hours' => 'Hour meter reading',
    'fuel_note' => 'Note (optional)',
    'fuel_note_placeholder' => 'Anything worth mentioning…',
    'fuel_submit' => 'Save',
    'fuel_success' => 'Logged ✓',
    'fuel_success_detail' => 'The fuel record was saved.',
    'fuel_success_no_location' => 'Logged, but without a location',
    'fuel_will_submit_without_location' => 'Your location hasn\'t been captured yet: if you send now, the record will be saved without it.',
    'fuel_new' => 'Log another',

    // Field report
    'report_title' => 'Field report',
    'report_condition' => 'Machine condition',
    'report_condition_ok' => 'OK',
    'report_condition_attention' => 'Needs attention',
    'report_condition_critical' => 'Critical',
    'report_hours' => 'Hour meter reading (optional)',
    'report_notes' => 'Notes',
    'report_submit' => 'Send report',
    'report_success' => 'Report sent ✓',
    'report_success_no_location' => 'Report sent, but without a location',
    'report_will_submit_without_location' => 'Your location hasn\'t been captured yet: if you send now, the report will be saved without it.',
    'report_new' => 'Send another',

    // Foreman
    'foreman_title' => 'My job site',
    'foreman_my_machines' => 'Machines at my site',
    'foreman_no_machines' => 'No machines assigned to this site yet.',
    'foreman_new_location' => 'Location',
    'foreman_hours' => 'Hour meter reading (optional)',
    'foreman_submit' => 'Update',
    'foreman_success' => 'Updated ✓',
];
