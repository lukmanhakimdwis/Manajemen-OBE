<?php

namespace local_obe;

defined('MOODLE_INTERNAL') || die();

global $CFG;

require_once(
    $CFG->dirroot . '/local/obe/classes/controller/matakuliah_controller.php'
);

use advanced_testcase;
use local_obe\controller\matakuliah_controller;

/**
 * Unit Test
 * Mata Kuliah Controller
 */
class matakuliah_controller_test
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
                (object)[
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

        $_POST['kode_mk'] =
            'IF001';

        $_POST['nama_mk'] =
            'Pemrograman Web';

        $_POST['sks_mk'] =
            3;

        $_REQUEST['id_prodi'] =
            $data['prodiid'];

        $_REQUEST['kode_mk'] =
            'IF001';

        $_REQUEST['nama_mk'] =
            'Pemrograman Web';

        $_REQUEST['sks_mk'] =
            3;

        $hasil =
            matakuliah_controller
                ::eksekusi_simpan();

        $this->assertIsInt($hasil);

        $this->assertGreaterThan(
            0,
            $hasil
        );

        $record =
            $DB->get_record(
                'local_obe_mata_kuliah',
                [
                    'id' => $hasil
                ]
            );

        $this->assertNotEmpty($record);

        $this->assertEquals(
            'IF001',
            $record->kode_mk
        );

        $this->assertEquals(
            'Pemrograman Web',
            $record->nama_mk
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
                'local_obe_mata_kuliah',
                (object)[
                    'id_prodi' => $data['prodiid'],
                    'kode_mk' => 'IF001',
                    'nama_mk' => 'Mata Kuliah Lama',
                    'sks_mk' => 2
                ]
            );

        $_POST['id'] =
            $id;

        $_POST['id_prodi'] =
            $data['prodiid'];

        $_POST['kode_mk'] =
            'IF002';

        $_POST['nama_mk'] =
            'Pemrograman Mobile';

        $_POST['sks_mk'] =
            4;

        $_REQUEST['id'] =
            $id;

        $_REQUEST['id_prodi'] =
            $data['prodiid'];

        $_REQUEST['kode_mk'] =
            'IF002';

        $_REQUEST['nama_mk'] =
            'Pemrograman Mobile';

        $_REQUEST['sks_mk'] =
            4;

        $hasil =
            matakuliah_controller
                ::eksekusi_update();

        $this->assertTrue($hasil);

        $record =
            $DB->get_record(
                'local_obe_mata_kuliah',
                [
                    'id' => $id
                ]
            );

        $this->assertEquals(
            'IF002',
            $record->kode_mk
        );

        $this->assertEquals(
            'Pemrograman Mobile',
            $record->nama_mk
        );

        $this->assertEquals(
            4,
            $record->sks_mk
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
                'local_obe_mata_kuliah',
                (object)[
                    'id_prodi' => $data['prodiid'],
                    'kode_mk' => 'IF003',
                    'nama_mk' => 'Data Mining',
                    'sks_mk' => 3
                ]
            );

        $hasil =
            matakuliah_controller
                ::eksekusi_hapus($id);

        $this->assertTrue($hasil);

        $record =
            $DB->get_record(
                'local_obe_mata_kuliah',
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

        $this->expectException(\moodle_exception::class);
        $hasil =
            matakuliah_controller
                ::eksekusi_hapus(999999);

        $this->assertTrue($hasil);
    }
}