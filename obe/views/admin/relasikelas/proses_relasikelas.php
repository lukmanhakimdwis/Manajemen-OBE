<?php
require_once(__DIR__ . '/../../../../../config.php');
require_login();
\local_obe\access_guard::require_admin();
require_sesskey();

use local_obe\controller\relasikelas_controller;

$aksi = optional_param('aksi', '', PARAM_ALPHA);
$id   = optional_param('id', 0, PARAM_INT);

if ($aksi === 'simpan') {
    try {
        relasikelas_controller::eksekusi_simpan();
        redirect(new moodle_url('/local/obe/views/admin/dashboard.php', ['halaman' => 'kelola_relasi']), 'Relasi berhasil dibuat', 2);
    } catch (Exception $e) {
        redirect(new moodle_url('/local/obe/views/admin/dashboard.php', ['halaman' => 'kelola_relasi', 'sub' => 'form']), $e->getMessage(), 5);
    }
} else if ($aksi === 'update') {
    try {
        relasikelas_controller::eksekusi_update();
        redirect(new moodle_url('/local/obe/views/admin/dashboard.php', ['halaman' => 'kelola_relasi']), 'Relasi berhasil diperbarui', 2);
    } catch (Exception $e) {
        redirect(new moodle_url('/local/obe/views/admin/dashboard.php', ['halaman' => 'kelola_relasi', 'sub' => 'form', 'id' => $id]), $e->getMessage(), 5);
    }
} else if ($aksi === 'hapus') {
    relasikelas_controller::eksekusi_hapus($id);
    redirect(new moodle_url('/local/obe/views/admin/dashboard.php', ['halaman' => 'kelola_relasi']), 'Relasi berhasil dihapus', 2);
} else {
    redirect(new moodle_url('/local/obe/views/admin/dashboard.php', ['halaman' => 'kelola_relasi']));
}