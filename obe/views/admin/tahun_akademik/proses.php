<?php
require_once(__DIR__ . '/../../../../../config.php');
require_login();
\local_obe\access_guard::require_admin();
require_sesskey();

use local_obe\controller\tahun_akademik_controller;

$aksi = optional_param('aksi', '', PARAM_ALPHA);

if (!empty($aksi)) {
    tahun_akademik_controller::handle_process($aksi);
} else {
    redirect(new moodle_url('/local/obe/views/admin/dashboard.php', ['halaman' => 'tahun_akademik']));
}   