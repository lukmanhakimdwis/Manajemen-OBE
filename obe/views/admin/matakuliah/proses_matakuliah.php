<?php
defined('MOODLE_INTERNAL') || die();
use local_obe\controller\matakuliah_controller;

\local_obe\access_guard::require_admin_or_kaprodi_capability('local/obe:manageprogram');

$aksi = optional_param('aksi', '', PARAM_ALPHA);
$id   = optional_param('id', 0, PARAM_INT);

if ($aksi) require_sesskey();

if ($aksi === 'simpan') {
    matakuliah_controller::eksekusi_simpan();
    redirect(new moodle_url('/local/obe/views/admin/dashboard.php', ['halaman' => 'kelola_mk']), 'Data berhasil disimpan', 2);
} else if ($aksi === 'update') {
    matakuliah_controller::eksekusi_update();
    redirect(new moodle_url('/local/obe/views/admin/dashboard.php', ['halaman' => 'kelola_mk']), 'Data berhasil diupdate', 2);
} else if ($aksi === 'hapus') {
    matakuliah_controller::eksekusi_hapus($id);
    redirect(new moodle_url('/local/obe/views/admin/dashboard.php', ['halaman' => 'kelola_mk']), 'Data berhasil dihapus', 2);
}