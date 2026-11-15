<?php
namespace local_obe\controller;
defined('MOODLE_INTERNAL') || die();
use local_obe\manager\prodi_manager;

class prodi_controller {
    
    public static function eksekusi_simpan_prodi() {
        $data = new \stdClass();
        $data->kode_prodi = required_param('kode_prodi', PARAM_TEXT);
        $data->nama_prodi = required_param('nama_prodi', PARAM_TEXT);
        $data->userid_kaprodi = optional_param('userid_kaprodi', 0, PARAM_INT);
        $data->keterangan = optional_param('keterangan', '', PARAM_TEXT);

        return prodi_manager::simpan_prodi($data);
    }

    public static function eksekusi_perbarui_prodi() {
        $data = new \stdClass();
        $data->id         = required_param('id', PARAM_INT);
        $data->kode_prodi = required_param('kode_prodi', PARAM_TEXT);
        $data->nama_prodi = required_param('nama_prodi', PARAM_TEXT);
        $data->userid_kaprodi = optional_param('userid_kaprodi', 0, PARAM_INT);
        $data->keterangan = optional_param('keterangan', '', PARAM_TEXT);

        return prodi_manager::perbarui_prodi($data);
    }

    public static function eksekusi_hapus_prodi($id) {
        return prodi_manager::hapus_prodi($id);
    }
}