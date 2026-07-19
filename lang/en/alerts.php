<?php

return [
    'alerts' => 'Alerts',
    'alert' => 'Alert',
    'type' => 'Type',
    'title' => 'Title',
    'message' => 'Message',
    'status' => 'Status',
    'notified_at' => 'Notified at',
    'created_at' => 'Created',
    'acknowledge' => 'Acknowledge',
    'resolve' => 'Resolve',
    'empty_heading' => 'No alerts',
    'empty_desc' => 'Service, checklist and hour-meter alerts will show up here.',

    'status_open' => 'Open',
    'status_acknowledged' => 'Acknowledged',
    'status_resolved' => 'Resolved',

    'type_service' => 'Service',
    'type_checklist' => 'Checklist',
    'type_hourmeter' => 'Hour-meter',
    'type_other' => 'Other',

    'auto_title' => 'Service due soon: :machine',
    'auto_message' => 'Machine :machine has :hours h left to its next scheduled service.',

    'checklist_title' => 'Checklist alert: :machine (:code)',

    'create_work_order' => 'Create work order',
    'create_work_order_confirm' => 'This will open a new preventive work order for this machine and mark the alert as acknowledged.',

    'mail_subject' => ':count machine(s) due for service',
    'mail_heading' => 'Machines due for service',
    'mail_intro' => ':count machine(s) are within 100 hours of their next scheduled service.',
    'mail_cta' => 'Open the fleet dashboard',
];
