<?php
require_once(__DIR__ . '/../../../../../config.php');
require_login();
\local_obe\access_guard::require_admin_or_kaprodi_capability('local/obe:manageprogram');
require_sesskey();

$courseid = optional_param('id', 0, PARAM_INT);
$aksi     = required_param('aksi', PARAM_TEXT);
$prodi    = optional_param('prodi', 0, PARAM_INT);

try {
    \local_obe\controller\bobot_relasi_controller::handle_process($aksi, $courseid, $prodi);
} catch (Exception $e) {
    redirect(new moodle_url('/local/obe/views/admin/dashboard.php', [
        'halaman' => 'bobot_relasi', 
        'prodi' => $prodi
    ]), 'Error: ' . $e->getMessage(), 5);
}