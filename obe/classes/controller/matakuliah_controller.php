<?php
namespace local_obe\controller;
defined('MOODLE_INTERNAL') || die();
use local_obe\manager\matakuliah_manager;

class matakuliah_controller {
    public static function eksekusi_simpan() {
        $data = new \stdClass();
        $data->id_prodi = required_param('id_prodi', PARAM_INT);
        $data->kode_mk  = required_param('kode_mk', PARAM_TEXT);
        $data->nama_mk  = required_param('nama_mk', PARAM_TEXT);
        $data->sks_mk   = required_param('sks_mk', PARAM_INT);
        \local_obe\access_guard::require_admin_or_same_prodi($data->id_prodi);
        return matakuliah_manager::simpan($data);
    }

    public static function eksekusi_update() {
        $data = new \stdClass();
        $data->id       = required_param('id', PARAM_INT);
        $data->id_prodi = required_param('id_prodi', PARAM_INT);
        $data->kode_mk  = required_param('kode_mk', PARAM_TEXT);
        $data->nama_mk  = required_param('nama_mk', PARAM_TEXT);
        $data->sks_mk   = required_param('sks_mk', PARAM_INT);
        \local_obe\access_guard::require_record_prodi_access('local_obe_mata_kuliah', $data->id);
        \local_obe\access_guard::require_admin_or_same_prodi($data->id_prodi);
        return matakuliah_manager::update($data);
    }

    public static function eksekusi_hapus($id) {
        \local_obe\access_guard::require_record_prodi_access('local_obe_mata_kuliah', $id);
        return matakuliah_manager::hapus($id);
    }
}