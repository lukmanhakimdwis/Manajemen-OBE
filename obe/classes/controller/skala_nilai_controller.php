<?php

namespace local_obe\controller;

defined('MOODLE_INTERNAL') || die();

use local_obe\manager\skala_nilai_manager;

class skala_nilai_controller {

    public static function tampilkan_data(
        $prodi,
        $tahun
    ){

        return
        skala_nilai_manager
        ::ambil_semua(
            $prodi,
            $tahun
        );
    }

    public static function ambil_data(
        $id
    ){

        return
        skala_nilai_manager
        ::ambil(
            $id
        );
    }

}