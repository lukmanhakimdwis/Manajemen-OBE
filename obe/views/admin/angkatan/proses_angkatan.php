<?php
defined('MOODLE_INTERNAL') || die();
use local_obe\controller\angkatan_controller;

\local_obe\access_guard::require_admin_or_kaprodi_capability('local/obe:manageprogram');

$aksi = optional_param('aksi', '', PARAM_ALPHA);
$id   = optional_param('id', 0, PARAM_INT);

if ($aksi) require_sesskey(); // Keamanan CSRF

if ($aksi === 'simpan') {
    angkatan_controller::eksekusi_simpan();
    redirect(new moodle_url('/local/obe/views/admin/dashboard.php', ['halaman' => 'kelola_angkatan']), 'Data berhasil disimpan', 2);
} else if ($aksi === 'update') {
    angkatan_controller::eksekusi_update();
    redirect(new moodle_url('/local/obe/views/admin/dashboard.php', ['halaman' => 'kelola_angkatan']), 'Data berhasil diperbarui', 2);
} else if ($aksi === 'hapus') {
    angkatan_controller::eksekusi_hapus($id);
    redirect(new moodle_url('/local/obe/views/admin/dashboard.php', ['halaman' => 'kelola_angkatan']), 'Data berhasil dihapus', 2);
}