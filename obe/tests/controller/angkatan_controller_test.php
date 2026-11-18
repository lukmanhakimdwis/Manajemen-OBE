<?php

namespace local_obe;

defined('MOODLE_INTERNAL') || die();

global $CFG;

require_once(
    $CFG->dirroot . '/local/obe/classes/controller/angkatan_controller.php'
);

use advanced_testcase;
use local_obe\controller\angkatan_controller;

/**
 * Unit Test
 * Angkatan Controller
 */
class angkatan_controller_test
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

        return [
            'prodiid' => $prodiid
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

        $_POST['id_prodi'] =
            $data['prodiid'];

        $_POST['tahun_masuk'] =
            2025;

        $_POST['nama_angkatan'] =
            'Angkatan 2025';

        $_POST['status_aktif'] =
            1;

        $_REQUEST['id_prodi'] =
            $data['prodiid'];

        $_REQUEST['tahun_masuk'] =
            2025;

        $_REQUEST['nama_angkatan'] =
            'Angkatan 2025';

        $_REQUEST['status_aktif'] =
            1;

        $hasil =
            angkatan_controller
                ::eksekusi_simpan();

        $this->assertIsInt($hasil);

        $this->assertGreaterThan(
            0,
            $hasil
        );

        $record =
            $DB->get_record(
                'local_obe_angkatan',
                [
                    'id' => $hasil
                ]
            );

        $this->assertNotEmpty($record);

        $this->assertEquals(
            'Angkatan 2025',
            $record->nama_angkatan
        );
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
                'local_obe_angkatan',
                (object)[
                    'id_prodi' => $data['prodiid'],
                    'tahun_masuk' => 2024,
                    'nama_angkatan' => 'Angkatan Lama',
                    'status_aktif' => 1
                ]
            );

        $_POST['id'] =
            $id;

        $_POST['id_prodi'] =
            $data['prodiid'];

        $_POST['tahun_masuk'] =
            2025;

        $_POST['nama_angkatan'] =
            'Angkatan Baru';

        $_POST['status_aktif'] =
            1;

        $_REQUEST['id'] =
            $id;

        $_REQUEST['id_prodi'] =
            $data['prodiid'];

        $_REQUEST['tahun_masuk'] =
            2025;

        $_REQUEST['nama_angkatan'] =
            'Angkatan Baru';

        $_REQUEST['status_aktif'] =
            1;

        $hasil =
            angkatan_controller
                ::eksekusi_update();

        $this->assertTrue($hasil);

        $record =
            $DB->get_record(
                'local_obe_angkatan',
                [
                    'id' => $id
                ]
            );

        $this->assertEquals(
            'Angkatan Baru',
            $record->nama_angkatan
        );

        $this->assertEquals(
            2025,
            $record->tahun_masuk
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
                'local_obe_angkatan',
                (object)[
                    'id_prodi' => $data['prodiid'],
                    'tahun_masuk' => 2025,
                    'nama_angkatan' => 'Angkatan Hapus',
                    'status_aktif' => 1
                ]
            );

        $hasil =
            angkatan_controller
                ::eksekusi_hapus($id);

        $this->assertTrue($hasil);

        $record =
            $DB->get_record(
                'local_obe_angkatan',
                [
                    'id' => $id
                ]
            );

        $this->assertFalse($record);
    }

    // =====================================================
    // TEST HAPUS GAGAL
    // =====================================================

   public function test_eksekusi_hapus_gagal() {

    $this->resetAfterTest(true);
        $this->setAdminUser();

    $this->expectException(\moodle_exception::class);
        $hasil =
        angkatan_controller
            ::eksekusi_hapus(999999);

    $this->assertTrue($hasil);
}

}