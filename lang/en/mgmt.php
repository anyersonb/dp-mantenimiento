<?php

return [
    // Costs / reports dashboard
    'cost_by_machine' => 'Maintenance cost by machine',
    'total_fleet_cost' => 'Total fleet maintenance cost',
    'service_count' => 'Services',
    'maintenance_cost' => 'Maintenance cost',

    // Fleet movement
    'move_machine' => 'Move',
    'move_machines' => 'Move to jobsite',
    'move_to' => 'Destination',
    'move_confirm' => 'This changes the machine\'s current location. It will be recorded in the audit log.',
    'move_success' => 'Location updated.',
    'move_success_bulk' => 'Locations updated.',

    // Audit log
    'audit_log' => 'Audit log',
    'audit_logs' => 'Audit log',
    'date' => 'Date',
    'date_from' => 'From',
    'date_until' => 'Until',
    'causer' => 'User',
    'event' => 'Event',
    'description' => 'Description',
    'subject_type' => 'Record type',
    // Audit log record types. The key is the model class name in snake_case;
    // a missing key falls back to the class name.
    'subject_machine' => 'Machine',
    'subject_work_order' => 'Work order',
    'subject_horometer_reading' => 'Hour meter reading',
    'subject_id' => 'Record #',
    'changes' => 'Changes',
    'properties' => 'Details',
    'attribute' => 'Field',
    'old_value' => 'Old value',
    'new_value' => 'New value',
    'no_causer' => 'System',
    'event_created' => 'Created',
    'event_updated' => 'Updated',
    'event_deleted' => 'Deleted',
    'event_approved' => 'Approved',
    'event_imported' => 'Imported',
    'event_hourmeter_replaced' => 'Hour meter replacement',
    'event_location_moved' => 'Machine moved',
    'event_location_confirmed' => 'Location confirmed',

    // Quotes
    'quotes' => 'Quotes',
    'quote' => 'Quote',
    'title' => 'Title',
    'vendor' => 'Vendor',
    'amount' => 'Amount',
    'machine' => 'Machine',
    'work_order' => 'Work order',
    'file' => 'File',
    'expires_at' => 'Expires at',
    'share_link' => 'Share link',
    'copy_link' => 'Copy link',
    'link_copied' => 'Link copied to clipboard.',
    'public_expired' => 'This link has expired.',
    'public_view_file' => 'View / download file',
    'public_no_file' => 'No file attached to this quote.',
    'public_amount' => 'Amount',
    'public_vendor' => 'Vendor',
    'public_expires_at' => 'Valid until',

    // Reports / exports
    'export_pdf' => 'Export PDF',
    'export_excel' => 'Export Excel',
    'fleet_report_title' => 'Fleet Status Report',
    'generated_at' => 'Generated at',

    // PM Service Report importer
    'import_pm_report' => 'Import PM Service Report',
    'import_pm_report_modal_heading' => 'Import PM Service Report',
    'import_pm_report_modal_description' => 'Upload the PM Service Report Excel to update hours and readings for machines that already exist in the system. Machines in the report that don\'t match an existing one are NOT created: they are listed as "unmatched" for manual review.',
    'import_pm_report_submit' => 'Import',
    'import_pm_report_file_label' => 'Excel file (.xlsx)',
    'import_pm_report_file_help' => '"PM Service Report" format (Sheet1, 2 rows per machine).',
    'import_pm_report_notif_title' => 'PM Service Report import',
    'import_pm_report_summary' => ':updated updated · :unmatched unmatched · :warnings warnings',
    'import_pm_report_unmatched_list' => 'Unmatched: :ids',
    'import_pm_report_log' => 'PM Service Report import: :updated updated, :unmatched unmatched',

    // Hourmeter replacement
    'hourmeter_replaced_log' => 'Hour meter replaced on :machine: :old h (old) -> :new h (new)',

    // Fleet map
    'fleet_map' => 'Fleet map',
    'coords_approx_notice' => 'Coordinates shown are approximate (South Florida area) and pending client confirmation.',
    'no_coords' => 'No jobsites with coordinates yet.',
    'event_discarded' => 'Discarded',
    'machine_moved_log' => 'Machine moved to another job site',
    'location_confirmed_log' => 'Machine location confirmed with no changes',
    'machine_approved_log' => 'Data verified and approved',
    'machine_discarded_log' => 'Machine :machine discarded: set to inactive and removed from review',

    // An administrator handed someone a new password. The password itself is
    // NOT written to the log: what is kept is the fact and who did it.
    'event_password_generated' => 'Password regenerated',
    'user_password_generated_log' => 'A new password was generated for :user',

    'import_duplicate_row' => ':machine appears more than once in the report: the :kept_hours h reading of :kept_date is used and the :dropped_hours h reading of :dropped_date is dropped. Review the file with the client.',
    'import_incoherent_reading' => ':machine: the :hours h reading of :date contradicts the history (:reason). The reading was NOT loaded; the rest of the row was.',
    'import_stale_reading' => ':machine: the report brings :report_hours h from :report_date, older than the :kept_hours h the machine already had. The :kept_hours h are kept and remaining hours are recomputed from the report anchor.',

    /*
     * Importer warnings. These were hardcoded in Spanish inside
     * PmServiceReportImporter, so an English user got them in Spanish while the
     * rest of the UI was translated. They now render in the language of
     * whoever runs the import.
     */
    'import_warn_no_reading' => ':machine (row :row): matched, but the report has no readable reading for this row; nothing was updated.',
    'import_warn_update_failed' => ':machine (row :row): update failed — :error',
    'import_warn_no_id' => 'Row :row: could not identify a machine ID in ":text"; skipped.',
    'import_warn_unreadable_last_service' => ':machine (row :row): last service unreadable (":raw"); the current value is kept.',
    'import_warn_unreadable_latest_reading' => ':machine (row :row): latest reading unreadable (":raw"); the current value is kept.',
    'import_warn_unreadable_remaining' => ':machine (row :row): remaining hours unreadable (":raw"); the current value is kept.',
    'import_warn_orphan_description' => 'Row :row: machine ":machine" has no data row (end of file); skipped.',
];
