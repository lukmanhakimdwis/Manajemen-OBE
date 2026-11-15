<?php
/**
 * proses_prodi.php
 */
require_once(__DIR__ . '/../../../../../config.php');

require_login();
\local_obe\access_guard::require_admin();
require_sesskey();

use local_obe\controller\prodi_controller;

$aksi  = optional_param('aksi', '', PARAM_ALPHA);
$id    = optional_param('id', 0, PARAM_INT);

// Keamanan: Cek token transaksi
if ($aksi) require_sesskey();

if ($aksi === 'simpan') {
    prodi_controller::eksekusi_simpan_prodi();
    redirect(new moodle_url('/local/obe/views/admin/dashboard.php', ['halaman' => 'kelola_prodi']), 'Data berhasil disimpan', 2);
} else if ($aksi === 'update') {
    prodi_controller::eksekusi_perbarui_prodi();
    redirect(new moodle_url('/local/obe/views/admin/dashboard.php', ['halaman' => 'kelola_prodi']), 'Data berhasil diperbarui', 2);
} else if ($aksi === 'hapus') {
    prodi_controller::eksekusi_hapus_prodi($id);
    redirect(new moodle_url('/local/obe/views/admin/dashboard.php', ['halaman' => 'kelola_prodi']), 'Data berhasil dihapus', 2);
} else {
    redirect(new moodle_url('/local/obe/views/admin/dashboard.php', ['halaman' => 'kelola_prodi']));
}