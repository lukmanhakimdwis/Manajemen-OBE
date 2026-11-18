<?php

namespace local_obe;

defined('MOODLE_INTERNAL') || die();

global $CFG;

require_once(
    $CFG->dirroot . '/local/obe/classes/controller/capaian_pembelajaran_controller.php'
);

use advanced_testcase;
use local_obe\controller\capaian_pembelajaran_controller;

/**
 * Unit Test
 * Capaian Pembelajaran Controller
 */
class capaian_pembelajaran_controller_test
    extends advanced_testcase {

    /**
     * Setup data dummy
     */
    private function buat_data_dummy() {

        global $DB;

        // =====================================================
        // PRODI
        // =====================================================

        $prodiid =
            $DB->insert_record(
                'local_obe_prodi',
                (object)['kode_prodi'=>'TEST-'.uniqid(), 
                    'nama_prodi' => 'Teknik Informatika'
                ]
            );

        // =====================================================
        // COURSE
        // =====================================================

        $generator =
            $this->getDataGenerator();

        $course =
            $generator->create_course([
                'fullname' => 'Testing Course',
                'shortname' => 'TC001'
            ]);

        // =====================================================
        // ANGKATAN
        // =====================================================

        $angkatanid =
            $DB->insert_record(
                'local_obe_angkatan',
                (object)[
                    'id_prodi' => $prodiid,
                    'tahun_masuk' => 2025,
                    'nama_angkatan' => 'Angkatan 2025',
                    'status_aktif' => 1
                ]
            );

        return [
            'prodiid' => $prodiid,
            'angkatanid' => $angkatanid,
            'courseid' => $course->id
        ];
    }

    // =====================================================
    // TEST GET DATA BERHASIL
    // =====================================================

    public function test_get_data_berhasil() {

        $this->resetAfterTest(true);
        $this->setAdminUser();

        $data =
            $this->buat_data_dummy();

        $hasil =
            capaian_pembelajaran_controller
                ::get_data(
                    $data['prodiid'],
                    $data['angkatanid'],
                    $data['courseid']
                );

        $this->assertIsArray($hasil);
    }

    // =====================================================
    // TEST GET DATA GAGAL PRODI KOSONG
    // =====================================================

    public function test_get_data_gagal_prodi_kosong() {

        $this->resetAfterTest(true);
        $this->setAdminUser();

        $hasil =
            capaian_pembelajaran_controller
                ::get_data(
                    0,
                    1,
                    1
                );

        $this->assertEquals(
            [],
            $hasil
        );
    }

    // =====================================================
    // TEST GET DATA GAGAL ANGKATAN KOSONG
    // =====================================================

    public function test_get_data_gagal_angkatan_kosong() {

        $this->resetAfterTest(true);
        $this->setAdminUser();

        $hasil =
            capaian_pembelajaran_controller
                ::get_data(
                    1,
                    0,
                    1
                );

        $this->assertEquals(
            [],
            $hasil
        );
    }

    // =====================================================
    // TEST GET DATA GAGAL COURSE KOSONG
    // =====================================================

    public function test_get_data_gagal_course_kosong() {

        $this->resetAfterTest(true);
        $this->setAdminUser();

        $hasil =
            capaian_pembelajaran_controller
                ::get_data(
                    1,
                    1,
                    0
                );

        $this->assertEquals(
            [],
            $hasil
        );
    }
}