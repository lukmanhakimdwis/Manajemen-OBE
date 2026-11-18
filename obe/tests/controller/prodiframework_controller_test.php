<?php

namespace local_obe;

defined('MOODLE_INTERNAL') || die();

global $CFG;

require_once(
    $CFG->dirroot . '/local/obe/classes/controller/prodiframework_controller.php'
);

use advanced_testcase;
use local_obe\controller\prodiframework_controller;

/**
 * Unit Test
 * Prodi Framework Controller
 */
class prodiframework_controller_test
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
                    'kode_prodi' => 'TI',
                    'nama_prodi' => 'Teknik Informatika',
                    'keterangan' => 'Testing'
                ]
            );

        // =====================================================
        // FRAMEWORK
        // =====================================================

        $framework=$this->getDataGenerator()->get_plugin_generator('core_competency')->create_framework(['shortname'=>'KKNI','idnumber'=>'FR001','description'=>'Framework Testing']);
        $frameworkid=$framework->get('id');

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

        return [
            'prodiid' => $prodiid,
            'frameworkid' => $frameworkid,
            'tahunid' => $tahunid
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

        $_POST['frameworkid'] =
            $data['frameworkid'];

        $_POST['id_tahun_akademik'] =
            $data['tahunid'];

        $_REQUEST['id_prodi'] =
            $data['prodiid'];

        $_REQUEST['frameworkid'] =
            $data['frameworkid'];

        $_REQUEST['id_tahun_akademik'] =
            $data['tahunid'];

        $hasil =
            prodiframework_controller
                ::eksekusi_simpan();

        $this->assertIsInt($hasil);

        $this->assertGreaterThan(
            0,
            $hasil
        );

        $record =
            $DB->get_record(
                'local_obe_prodi_framework',
                [
                    'id' => $hasil
                ]
            );

        $this->assertNotEmpty($record);

        $this->assertEquals(
            $data['prodiid'],
            $record->id_prodi
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
            'local_obe_prodi_framework',
            (object)[
                'id_prodi' => $data['prodiid'],
                'frameworkid' => $data['frameworkid'],
                'id_tahun_akademik' => $data['tahunid']
            ]
        );

        $_POST['id_prodi'] =
            $data['prodiid'];

        $_POST['frameworkid'] =
            $data['frameworkid'];

        $_POST['id_tahun_akademik'] =
            $data['tahunid'];

        $_REQUEST['id_prodi'] =
            $data['prodiid'];

        $_REQUEST['frameworkid'] =
            $data['frameworkid'];

        $_REQUEST['id_tahun_akademik'] =
            $data['tahunid'];

        $this->expectException(
            \Exception::class
        );

        prodiframework_controller
            ::eksekusi_simpan();
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
                'local_obe_prodi_framework',
                (object)[
                    'id_prodi' => $data['prodiid'],
                    'frameworkid' => $data['frameworkid'],
                    'id_tahun_akademik' => $data['tahunid']
                ]
            );

        $hasil =
            prodiframework_controller
                ::eksekusi_hapus($id);

        $this->assertTrue($hasil);

        $record =
            $DB->get_record(
                'local_obe_prodi_framework',
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
            prodiframework_controller
                ::eksekusi_hapus(999999);

        $this->assertTrue($hasil);
    }
}