<?php

// Read-only "Field reports" screen (/admin/field-reports).
// Reports are created by the field worker from /field/report; see
// lang/en/field.php for that mobile app's copy.
return [
    'nav' => 'Field reports',
    'model_singular' => 'Field report',
    'model_plural' => 'Field reports',

    // Columns / filters
    'column_condition' => 'Condition',
    'column_reporter' => 'Reported by',
    'column_hours' => 'Hour meter',
    'column_date' => 'Date',
    'column_location_status' => 'Location',
    'filter_condition' => 'Condition',

    'condition_ok' => 'OK',
    'condition_attention' => 'Needs attention',
    'condition_critical' => 'Critical',

    'location_yes' => 'Has location',
    'location_no' => 'No location',

    // Detail (ViewAction / infolist)
    'detail_notes' => 'Notes',
    'detail_no_notes' => 'No notes',
    'detail_location' => 'Location',
    'detail_map_link' => 'View on map',
    // Read-only section (2026-09-22): work orders opened from this report.
    'detail_work_orders' => 'Associated work orders',

    'empty_heading' => 'No field reports',
    'empty_desc' => 'Reports sent by field staff from the field app will show up here.',

    // Notifications (database channel — see App\Support\Notifications\NotificationRegistry)
    'notification_title_critical' => '🔴 Critical report — :machine',
    'notification_title_attention' => '🟡 Report needs attention — :machine',
    'notification_body' => 'Sent by :reporter',
    'notification_action' => 'View field reports',
    'unknown_reporter' => 'Unknown',
];
