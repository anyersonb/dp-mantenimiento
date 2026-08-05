<?php

return [
    // Navigation / groups
    'group_fleet' => 'Fleet',
    'group_operations' => 'Operations',
    'group_admin' => 'Administration',
    'group_management' => 'Management',
    'machines' => 'Machines',
    'machine' => 'Machine',

    // Sections
    'identification' => 'Identification',
    'status_service' => 'Status & Service',
    'technical' => 'Technical spec sheet',
    'images' => 'Images',
    'data_control' => 'Data control',

    // Fields
    'id_code' => 'ID',
    'category' => 'Category',
    'make' => 'Make',
    'model' => 'Model',
    'serial' => 'Serial / PIN',
    'serial_type' => 'Serial type',
    'year' => 'Year',
    'location' => 'Location',
    'description' => 'Description',
    'status' => 'Status',
    'hourmeter_status' => 'Hourmeter',
    'hours_adjustment' => 'Hours adjustment',
    'current_hours' => 'Current hours',
    'current_hours_date' => 'Reading date',
    'service_interval' => 'Service interval',
    'last_service_hours' => 'Last service (h)',
    'last_service_date' => 'Last service date',
    'remaining_hours' => 'Remaining',
    'remaining_help' => 'Hours left to the next service (snapshot from PM report).',
    'engine_model' => 'Engine model',
    'engine_serial' => 'Engine serial',
    'electrical' => 'Electrical system',
    'tires' => 'Tires',
    'oil_capacity' => 'Oil capacity',
    'spec_sheet' => 'Spec sheet (Machinery Info Book)',
    'spec_sheet_help' => 'Full verbatim text as it appears in the Info Book; do not edit except to correct data.',
    'image' => 'Main image',
    'gallery' => 'Gallery',
    'needs_review' => 'Needs review',
    'review' => 'Review',
    'review_note' => 'Review note',
    'notes' => 'Notes',

    // Data verification (verify_data permission)
    'approve_data' => 'Approve data',
    'approve_confirm' => 'This machine will be marked as verified and it will be logged in the audit trail. Confirm?',
    'approved_ok' => 'Data verified and approved.',
    'approve_bulk' => 'Approve selected',
    'approved_bulk_ok' => ':count machine(s) approved.',

    // Statuses
    'status_active' => 'Active',
    'status_not_in_service' => 'Not in service',
    'status_down' => 'Down',
    'status_inactive' => 'Inactive',
    'status_unknown' => 'Unknown',
    'hm_broken' => 'Broken',
    'hm_no_info' => 'No info',
    'hm_replaced' => 'Replaced',
    'due_soon' => 'Due soon (<= 100 h)',

    // Hourmeter replacement (its own event, not just another reading)
    'replace_hourmeter' => 'Log hourmeter replacement',
    'replace_hourmeter_old_hours' => 'Last reading of the old hourmeter',
    'replace_hourmeter_new_hours' => 'Initial reading of the new hourmeter',
    'replace_hourmeter_note' => 'Note (optional)',
    'replace_hourmeter_confirm' => 'This marks the hourmeter as replaced, re-anchors service tracking to the new scale, and gets logged in the audit trail. Confirm?',
    'replace_hourmeter_success' => 'Hourmeter replacement logged.',

    // Parts
    'parts_catalog' => 'Parts catalog',
    'part' => 'Part',
    'parts' => 'Parts',
    'part_category' => 'Type',
    'change_interval' => 'Change interval',
    'detail' => 'Detail',

    // Readings
    'horometer_history' => 'Hourmeter history',
    // Etiquetas del modelo del relation manager de lecturas: sin ellas Filament
    // escribe "horometer reading" (derivado de la clase) en los modales.
    'reading_singular' => 'Hourmeter reading',
    'reading_plural' => 'Hourmeter readings',
    'hours' => 'Hours',
    'read_at' => 'Date',
    'source' => 'Source',
    'gallons' => 'Gallons',
    'verified' => 'Verified',
    'note' => 'Note',
    'src_fuel' => 'Fuel truck',
    'src_maintenance' => 'Maintenance',
    'src_workshop' => 'Shop',
    'src_manual' => 'Manual',
    'discard_data' => 'Discard',
    'discard_confirm' => 'The machine is set to inactive and leaves the review list. Its history (work orders, readings and costs) is kept in full.',
    'discard_reason' => 'Reason for discarding',
    'discarded_ok' => 'Machine discarded. Its history was kept.',
    'delete_heading' => 'Delete machine :machine',
    'delete_warning' => 'WARNING: this is NOT a retirement. Deleting :machine also removes, in cascade: :work_orders work order(s) with their costs, :readings hourmeter reading(s), :alerts alert(s) and :parts catalogue part(s). To retire a machine use the Inactive status, or the Discard action if it is under review.',
    'delete_confirm_button' => 'Yes, delete and lose the history',

    'imported_reading_note' => 'Reading imported from the PM Service Report',
    'imported_reading_from_file_note' => 'Reading imported from the PM Service Report (:file)',

    // Machine number filter (client request 2026-08-05)
    'machine_number' => 'Machine number',
    'machine_number_placeholder' => 'e.g. EX010, or just EX',
];
