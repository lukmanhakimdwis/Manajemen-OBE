<?php
namespace local_obe\controller;
defined('MOODLE_INTERNAL') || die();
use local_obe\manager\tahun_akademik_manager;

class tahun_akademik_controller {
    
    public static function handle_process($aksi) {
        require_sesskey();
        
        switch ($aksi) {
            case 'tambah':
                $nama_tahun = required_param('nama_tahun', PARAM_TEXT);
                tahun_akademik_manager::simpan($nama_tahun);
                redirect(new \moodle_url('/local/obe/views/admin/dashboard.php', [
                    'halaman' => 'tahun_akademik'
                ]), 'Tahun akademik berhasil ditambahkan.');
                break;

            case 'aktifkan':
                $id = required_param('id', PARAM_INT);
                tahun_akademik_manager::aktifkan($id);
                redirect(new \moodle_url('/local/obe/views/admin/dashboard.php', [
                    'halaman' => 'tahun_akademik'
                ]), 'Tahun akademik berhasil diaktifkan sebagai sesi global.');
                break;

            case 'hapus':
                $id = required_param('id', PARAM_INT);
                tahun_akademik_manager::hapus($id);
                redirect(new \moodle_url('/local/obe/views/admin/dashboard.php', [
                    'halaman' => 'tahun_akademik'
                ]), 'Tahun akademik berhasil dihapus.');
                break;
        }
    }
}