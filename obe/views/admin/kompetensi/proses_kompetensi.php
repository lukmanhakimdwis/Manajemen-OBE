<?php
/**
 * proses_kompetensi.php
 */
require_once(__DIR__ . '/../../../../../config.php');

require_login();
\local_obe\access_guard::require_admin_or_kaprodi_capability('local/obe:manageprogram');
require_sesskey();

use local_obe\controller\kompetensi_controller;

$aksi  = required_param('aksi', PARAM_ALPHAEXT);
$prodi = optional_param('prodi', 0, PARAM_INT);

switch ($aksi) {
    case 'sync':
        kompetensi_controller::handle_sync($prodi);
        break;

    

    default:
        redirect(new moodle_url('/local/obe/views/admin/dashboard.php', [
            'halaman' => 'manajemen_kompetensi',
            'prodi'   => $prodi
        ]));
        break;
}