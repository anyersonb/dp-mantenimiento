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
    'hourmeter_status' => 'Hour meter',
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
    'replace_hourmeter' => 'Log hour meter replacement',
    'replace_hourmeter_old_hours' => 'Last reading of the old hour meter',
    'replace_hourmeter_new_hours' => 'Initial reading of the new hour meter',
    'replace_hourmeter_note' => 'Note (optional)',
    'replace_hourmeter_confirm' => 'This marks the hour meter as replaced, re-anchors service tracking to the new scale, and gets logged in the audit trail. Confirm?',
    'replace_hourmeter_success' => 'Hour meter replacement logged.',

    // Parts
    'parts_catalog' => 'Parts catalog',
    'part' => 'Part',
    'parts' => 'Parts',
    'part_category' => 'Type',
    'change_interval' => 'Change interval',
    'detail' => 'Detail',

    // Readings
    'horometer_history' => 'Hour meter history',
    // Etiquetas del modelo del relation manager de lecturas: sin ellas Filament
    // escribe "horometer reading" (derivado de la clase) en los modales.
    'reading_singular' => 'Hour meter reading',
    'reading_plural' => 'Hour meter readings',
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
    'delete_warning' => 'This sends :machine to the Trash: it is NOT a permanent retirement. The machine is hidden and can be restored at any time, and its history is not touched or lost now —it currently has :work_orders work order(s) with their costs, :readings hour meter reading(s), :alerts alert(s), :parts catalogue part(s) and :field_reports field report(s), all untouched—. That history is only lost, in cascade and with no way back, if an administrator later permanently deletes it from the Trash. To retire a machine without going through the trash, use the Inactive status, or the Discard action if it is under review.',
    'delete_confirm_button' => 'Yes, send to trash',

    // Trash — PERMANENT deletion (a real hard delete, no way back). Different
    // from the warning above: that one is the normal soft delete (recoverable
    // from the trash); this one truly cannot be undone.
    'force_delete_warning' => 'This machine (:machine) has :work_orders work order(s), :readings hour meter reading(s), :parts part(s), :alerts alert(s) and :field_reports field report(s). Permanently deleting it will remove them forever and cannot be undone.',
    'force_delete_bulk_warning' => 'These :count machines have a combined :work_orders work order(s), :readings hour meter reading(s), :parts part(s), :alerts alert(s) and :field_reports field report(s). Permanently deleting them will remove them forever and cannot be undone.',

    'imported_reading_note' => 'Reading imported from the PM Service Report',
    'imported_reading_from_file_note' => 'Reading imported from the PM Service Report (:file)',

    // Machine number filter (client request 2026-08-05)
    'machine_number' => 'Machine number',
    'machine_number_placeholder' => 'e.g. EX010, or just EX',

    // Attachments module — see spec-complementos-dp.md. Standalone record,
    // no link to machines. Reuses this same file with an `attachment_*`
    // prefix to keep the ES/EN parity that TranslationParitySentinelTest
    // watches, and shares the generic keys above (model, serial,
    // serial_type, year, location, description, status, needs_review,
    // review_note, notes, spec_sheet, image, gallery, make) because those
    // are the same fields as in Machines.
    'attachments' => 'Attachments',
    'attachment' => 'Attachment',
    'attachment_status_section' => 'Status',
    'attachment_technical' => 'Technical',
    'attachment_documents' => 'Documents',
    // Its own label, distinct from "ID" (fleet.id_code, the Machines one):
    // this is the field the client explicitly asked for by name.
    'attachment_id_code' => 'Attachment ID',
    'attachment_name' => 'Name',
    'attachment_type' => 'Type',
    'attachment_type_bucket' => 'Bucket',
    'attachment_type_hammer' => 'Hydraulic hammer',
    'attachment_type_grapple' => 'Grapple',
    'attachment_type_auger' => 'Auger',
    'attachment_type_broom' => 'Broom',
    'attachment_type_ripper' => 'Ripper',
    'attachment_type_fork' => 'Fork',
    'attachment_type_blade' => 'Blade',
    'attachment_type_compactor' => 'Compactor',
    'attachment_type_other' => 'Other',
    'attachment_weight' => 'Weight',
    'attachment_dimensions' => 'Dimensions',
    'attachment_compatibility' => 'Compatibility',
    'attachment_acquisition_date' => 'Acquisition date',
    'attachment_condition_note' => 'Condition note',
    'attachment_number' => 'Attachment number',
    'attachment_number_placeholder' => 'e.g. BKT-01, or just BKT',
];
