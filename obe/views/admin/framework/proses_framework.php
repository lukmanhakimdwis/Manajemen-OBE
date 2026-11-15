<?php
require_once(__DIR__ . '/../../../../../config.php');

require_login();
\local_obe\access_guard::require_admin_or_kaprodi_capability('local/obe:manageprogram');
require_sesskey();

use local_obe\controller\prodiframework_controller;

$aksi = optional_param('aksi', '', PARAM_ALPHA);
$id   = optional_param('id', 0, PARAM_INT);

try {

    if ($aksi === 'simpan') {

        prodiframework_controller::eksekusi_simpan();

        redirect(
            new moodle_url(
                '/local/obe/views/admin/dashboard.php',
                ['halaman' => 'kelola_framework']
            ),
            'Mapping framework berhasil disimpan.',
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );

    } else if ($aksi === 'hapus') {

        prodiframework_controller::eksekusi_hapus($id);

        redirect(
            new moodle_url(
                '/local/obe/views/admin/dashboard.php',
                ['halaman' => 'kelola_framework']
            ),
            'Mapping framework berhasil dihapus.',
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );

    } else {

        redirect(
            new moodle_url(
                '/local/obe/views/admin/dashboard.php',
                ['halaman' => 'kelola_framework']
            )
        );

    }

} catch (Exception $e) {

    redirect(
        new moodle_url(
            '/local/obe/views/admin/dashboard.php',
            ['halaman' => 'kelola_framework']
        ),
        $e->getMessage(),
        null,
        \core\output\notification::NOTIFY_ERROR
    );

}