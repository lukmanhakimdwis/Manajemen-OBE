<?php

namespace local_obe;

defined('MOODLE_INTERNAL') || die();

global $CFG;

require_once(
    $CFG->dirroot . '/local/obe/classes/controller/prodi_controller.php'
);

use advanced_testcase;
use local_obe\controller\prodi_controller;

/**
 * Unit Test
 * Prodi Controller
 */
class prodi_controller_test
    extends advanced_testcase {

    // =====================================================
    // TEST SIMPAN PRODI BERHASIL
    // =====================================================

    public function test_eksekusi_simpan_prodi_berhasil() {

        $this->resetAfterTest(true);
        $this->setAdminUser();

        global $DB;

        $_POST['kode_prodi'] =
            'TI';

        $_POST['nama_prodi'] =
            'Teknik Informatika';

        $_POST['keterangan'] =
            'Program Studi TI';

        $_REQUEST['kode_prodi'] =
            'TI';

        $_REQUEST['nama_prodi'] =
            'Teknik Informatika';

        $_REQUEST['keterangan'] =
            'Program Studi TI';

        $hasil =
            prodi_controller
                ::eksekusi_simpan_prodi();

        $this->assertIsInt($hasil);

        $this->assertGreaterThan(
            0,
            $hasil
        );

        $record =
            $DB->get_record(
                'local_obe_prodi',
                [
                    'id' => $hasil
                ]
            );

        $this->assertNotEmpty($record);

        $this->assertEquals(
            'TI',
            $record->kode_prodi
        );

        $this->assertEquals(
            'Teknik Informatika',
            $record->nama_prodi
        );
    }

    // =====================================================
    // TEST UPDATE PRODI BERHASIL
    // =====================================================

    public function test_eksekusi_perbarui_prodi_berhasil() {

        $this->resetAfterTest(true);
        $this->setAdminUser();

        global $DB;

        $id =
            $DB->insert_record(
                'local_obe_prodi',
                (object)[
                    'kode_prodi' => 'SI',
                    'nama_prodi' => 'Sistem Informasi',
                    'keterangan' => 'Keterangan Lama'
                ]
            );

        $_POST['id'] =
            $id;

        $_POST['kode_prodi'] =
            'TI';

        $_POST['nama_prodi'] =
            'Teknik Informatika';

        $_POST['keterangan'] =
            'Keterangan Baru';

        $_REQUEST['id'] =
            $id;

        $_REQUEST['kode_prodi'] =
            'TI';

        $_REQUEST['nama_prodi'] =
            'Teknik Informatika';

        $_REQUEST['keterangan'] =
            'Keterangan Baru';

        $hasil =
            prodi_controller
                ::eksekusi_perbarui_prodi();

        $this->assertTrue($hasil);

        $record =
            $DB->get_record(
                'local_obe_prodi',
                [
                    'id' => $id
                ]
            );

        $this->assertEquals(
            'TI',
            $record->kode_prodi
        );

        $this->assertEquals(
            'Teknik Informatika',
            $record->nama_prodi
        );

        $this->assertEquals(
            'Keterangan Baru',
            $record->keterangan
        );
    }

    // =====================================================
    // TEST HAPUS PRODI BERHASIL
    // =====================================================

    public function test_eksekusi_hapus_prodi_berhasil() {

        $this->resetAfterTest(true);
        $this->setAdminUser();

        global $DB;

        $id =
            $DB->insert_record(
                'local_obe_prodi',
                (object)[
                    'kode_prodi' => 'MI',
                    'nama_prodi' => 'Manajemen Informatika',
                    'keterangan' => 'Testing Hapus'
                ]
            );

        $hasil =
            prodi_controller
                ::eksekusi_hapus_prodi($id);

        $this->assertTrue($hasil);

        $record =
            $DB->get_record(
                'local_obe_prodi',
                [
                    'id' => $id
                ]
            );

        $this->assertFalse($record);
    }

    // =====================================================
    // TEST HAPUS PRODI ID TIDAK ADA
    // =====================================================

    public function test_eksekusi_hapus_prodi_id_tidak_ada() {

        $this->resetAfterTest(true);
        $this->setAdminUser();

        $hasil =
            prodi_controller
                ::eksekusi_hapus_prodi(999999);

        $this->assertTrue($hasil);
    }
}