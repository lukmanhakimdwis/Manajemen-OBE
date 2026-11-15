<?php

namespace local_obe;

defined('MOODLE_INTERNAL') || die();

global $CFG;

require_once(
    $CFG->dirroot . '/local/obe/classes/controller/relasikelas_controller.php'
);

use advanced_testcase;
use local_obe\controller\relasikelas_controller;

/**
 * Unit Test
 * Relasi Kelas Controller
 */
class relasikelas_controller_test
    extends advanced_testcase {

    /**
     * Setup data dummy
     */
    private function buat_data_dummy() {

        global $DB, $SESSION;

        // =====================================================
        // PRODI
        // =====================================================

        $prodiid =
            $DB->insert_record(
                'local_obe_prodi',
                (object)[
                    'kode_prodi' => 'TI',
                    'nama_prodi' => 'Teknik Informatika',
                    'keterangan' => 'Testing'
                ]
            );

        // =====================================================
        // MATA KULIAH
        // =====================================================

        $mkid =
            $DB->insert_record(
                'local_obe_mata_kuliah',
                (object)[
                    'id_prodi' => $prodiid,
                    'kode_mk' => 'IF001',
                    'nama_mk' => 'Pemrograman Web',
                    'sks_mk' => 3
                ]
            );

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

        // =====================================================
        // TAHUN AKADEMIK
        // =====================================================

        $tahunid =
            $DB->insert_record(
                'local_obe_tahun_akademik',
                (object)[
                    'nama_tahun' => '2025/2026',
                    'status_aktif' => 1
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

        $SESSION->obe_id_tahun_akademik =
            $tahunid;

        return [
            'mkid' => $mkid,
            'angkatanid' => $angkatanid,
            'tahunid' => $tahunid,
            'courseid' => $course->id
        ];
    }

    // =====================================================
    // TEST SIMPAN BERHASIL
    // =====================================================

    public function test_eksekusi_simpan_berhasil() {

        $this->resetAfterTest(true);
        $this->setAdminUser();

        global $DB;

        $data =
            $this->buat_data_dummy();

        $_POST['id_mata_kuliah'] =
            $data['mkid'];

        $_POST['id_kursus_moodle'] =
            [$data['courseid']];

        $_POST['id_angkatan'] =
            $data['angkatanid'];

        $_POST['id_tahun_akademik'] =
            $data['tahunid'];

        $_REQUEST['id_mata_kuliah'] =
            $data['mkid'];

        $_REQUEST['id_kursus_moodle'] =
            [$data['courseid']];

        $_REQUEST['id_angkatan'] =
            $data['angkatanid'];

        $_REQUEST['id_tahun_akademik'] =
            $data['tahunid'];

        $hasil =
            relasikelas_controller
                ::eksekusi_simpan();

        $this->assertTrue($hasil);

        $this->assertGreaterThan(
            0,
            $hasil
        );

        $record =
            $DB->get_record(
                'local_obe_relasi_kelas',
                [
                    'id_kursus_moodle' => $data['courseid']
                ]
            );

        $this->assertNotEmpty($record);

        $this->assertEquals(
            $data['courseid'],
            $record->id_kursus_moodle
        );
    }

    // =====================================================
    // TEST SIMPAN DUPLIKAT
    // =====================================================

    public function test_eksekusi_simpan_duplikat() {

        $this->resetAfterTest(true);
        $this->setAdminUser();

        global $DB;

        $data =
            $this->buat_data_dummy();

        // =====================================================
        // INSERT DATA AWAL
        // =====================================================

        $DB->insert_record(
            'local_obe_relasi_kelas',
            (object)[
                'id_mata_kuliah' => $data['mkid'],
                'id_kursus_moodle' => $data['courseid'],
                'id_angkatan' => $data['angkatanid'],
                'id_tahun_akademik' => $data['tahunid'],
                'waktu_koneksi' => time()
            ]
        );

        $_POST['id_mata_kuliah'] =
            $data['mkid'];

        $_POST['id_kursus_moodle'] =
            $data['courseid'];

        $_POST['id_angkatan'] =
            $data['angkatanid'];

        $_POST['id_tahun_akademik'] =
            $data['tahunid'];

        $_REQUEST['id_mata_kuliah'] =
            $data['mkid'];

        $_REQUEST['id_kursus_moodle'] =
            $data['courseid'];

        $_REQUEST['id_angkatan'] =
            $data['angkatanid'];

        $_REQUEST['id_tahun_akademik'] =
            $data['tahunid'];

        $this->expectException(
            \moodle_exception::class
        );

        relasikelas_controller
            ::eksekusi_simpan();
    }

    // =====================================================
    // TEST UPDATE BERHASIL
    // =====================================================

    public function test_eksekusi_update_berhasil() {

        $this->resetAfterTest(true);
        $this->setAdminUser();

        global $DB;

        $data =
            $this->buat_data_dummy();

        $id =
            $DB->insert_record(
                'local_obe_relasi_kelas',
                (object)[
                    'id_mata_kuliah' => $data['mkid'],
                    'id_kursus_moodle' => $data['courseid'],
                    'id_angkatan' => $data['angkatanid'],
                    'id_tahun_akademik' => $data['tahunid'],
                    'waktu_koneksi' => time()
                ]
            );

        $_POST['id'] =
            $id;

        $_POST['id_mata_kuliah'] =
            $data['mkid'];

        $_POST['id_kursus_moodle'] =
            $data['courseid'];

        $_POST['id_angkatan'] =
            $data['angkatanid'];

        $_POST['id_tahun_akademik'] =
            $data['tahunid'];

        $_REQUEST['id'] =
            $id;

        $_REQUEST['id_mata_kuliah'] =
            $data['mkid'];

        $_REQUEST['id_kursus_moodle'] =
            $data['courseid'];

        $_REQUEST['id_angkatan'] =
            $data['angkatanid'];

        $_REQUEST['id_tahun_akademik'] =
            $data['tahunid'];

        $hasil =
            relasikelas_controller
                ::eksekusi_update();

        $this->assertTrue($hasil);

        $record =
            $DB->get_record(
                'local_obe_relasi_kelas',
                [
                    'id' => $id
                ]
            );

        $this->assertEquals(
            $data['mkid'],
            $record->id_mata_kuliah
        );
    }

    // =====================================================
    // TEST HAPUS BERHASIL
    // =====================================================

    public function test_eksekusi_hapus_berhasil() {

        $this->resetAfterTest(true);
        $this->setAdminUser();

        global $DB;

        $data =
            $this->buat_data_dummy();

        $id =
            $DB->insert_record(
                'local_obe_relasi_kelas',
                (object)[
                    'id_mata_kuliah' => $data['mkid'],
                    'id_kursus_moodle' => $data['courseid'],
                    'id_angkatan' => $data['angkatanid'],
                    'id_tahun_akademik' => $data['tahunid'],
                    'waktu_koneksi' => time()
                ]
            );

        $hasil =
            relasikelas_controller
                ::eksekusi_hapus($id);

        $this->assertTrue($hasil);

        $record =
            $DB->get_record(
                'local_obe_relasi_kelas',
                [
                    'id' => $id
                ]
            );

        $this->assertFalse($record);
    }

    // =====================================================
    // TEST HAPUS ID TIDAK ADA
    // =====================================================

    public function test_eksekusi_hapus_id_tidak_ada() {

        $this->resetAfterTest(true);
        $this->setAdminUser();

        $hasil =
            relasikelas_controller
                ::eksekusi_hapus(999999);

        $this->assertTrue($hasil);
    }
}