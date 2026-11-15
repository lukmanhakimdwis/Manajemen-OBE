<?php
namespace local_obe\controller;
defined('MOODLE_INTERNAL') || die();
use local_obe\manager\angkatan_manager;

class angkatan_controller {
    public static function eksekusi_simpan() {
        $data = new \stdClass();
        $data->id_prodi     = required_param('id_prodi', PARAM_INT);
        $data->tahun_masuk  = required_param('tahun_masuk', PARAM_INT);
        $data->nama_angkatan = required_param('nama_angkatan', PARAM_TEXT);
        $data->status_aktif  = optional_param('status_aktif', 1, PARAM_INT);
        \local_obe\access_guard::require_admin_or_same_prodi($data->id_prodi);
        return angkatan_manager::simpan($data);
    }

    public static function eksekusi_update() {
        $data = new \stdClass();
        $data->id           = required_param('id', PARAM_INT);
        $data->id_prodi     = required_param('id_prodi', PARAM_INT);
        $data->tahun_masuk  = required_param('tahun_masuk', PARAM_INT);
        $data->nama_angkatan = required_param('nama_angkatan', PARAM_TEXT);
        $data->status_aktif  = optional_param('status_aktif', 1, PARAM_INT);
        \local_obe\access_guard::require_record_prodi_access('local_obe_angkatan', $data->id);
        \local_obe\access_guard::require_admin_or_same_prodi($data->id_prodi);
        return angkatan_manager::update($data);
    }

    public static function eksekusi_hapus($id) {
        \local_obe\access_guard::require_record_prodi_access('local_obe_angkatan', $id);
        return angkatan_manager::hapus($id);
    }
}