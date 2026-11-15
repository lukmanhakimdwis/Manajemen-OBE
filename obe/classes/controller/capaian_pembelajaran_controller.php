<?php
namespace local_obe\controller;
defined('MOODLE_INTERNAL') || die();
use local_obe\manager\capaian_pembelajaran_manager;

class capaian_pembelajaran_controller {
    public static function get_data($prodi, $angkatan, $courseid) {
        if (empty($prodi) || empty($angkatan) || empty($courseid)) {
            return [];
        }
        return \local_obe\manager\capaian_kelas_manager::ambil_data_export_kelas($courseid, \local_obe\manager\aktivitasnilai_manager::ambil_tahun_id_dari_course($courseid));
    }
}