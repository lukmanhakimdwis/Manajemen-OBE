<?php
// File: local/obe/settings.php
defined('MOODLE_INTERNAL') || die();

if (has_capability('local/obe:manage', context_system::instance())) {
    $ADMIN->add('localplugins', new admin_externalpage(
        'local_obe_admin_entry',
        'Manajemen OBE',
        new moodle_url('/local/obe/views/admin/dashboard.php'),
        'local/obe:manage'
    ));
}
