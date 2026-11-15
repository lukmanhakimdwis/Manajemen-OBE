<?php

namespace local_obe;

defined('MOODLE_INTERNAL') || die();

global $CFG;

require_once(
    $CFG->dirroot . '/local/obe/classes/controller/aktivitasnilai_controller.php'
);

use advanced_testcase;
use local_obe\controller\aktivitasnilai_controller;

/**
 * Unit Test
 * Aktivitasnilai Controller
 */
class aktivitasnilai_controller_test
    extends advanced_testcase {

    /**
     * Setup data dummy
     */
    private function buat_data_dummy() {

        global $DB;

        // =====================================================
        // PRODI
        // =====================================================

        $prodiid = $DB->insert_record(
            'local_obe_prodi',
            (object)[
                'nama_prodi' => 'Teknik Informatika'
            ]
        );

        // =====================================================
        // TAHUN AKADEMIK
        // =====================================================

        $tahunid = $DB->insert_record(
            'local_obe_tahun_akademik',
            (object)[
                'nama_tahun' => '2025/2026',
                'status_aktif' => 1
            ]
        );

        // =====================================================
        // CPL
        // =====================================================

        $cplid = $DB->insert_record(
            'local_obe_kompetensi',
            (object)[
                'id_prodi' => $prodiid,
                'frameworkid' => 1,
                'id_tahun_akademik' => $tahunid,
                'nama_kompetensi' => 'CPL 1',
                'tipe' => 'CPL',
                'parent_id' => 0,
                'ambang_batas' => 70
            ]
        );

        // =====================================================
        // INDIKATOR
        // =====================================================

        $indikatorid = $DB->insert_record(
            'local_obe_kompetensi',
            (object)[
                'id_prodi' => $prodiid,
                'frameworkid' => 1,
                'id_tahun_akademik' => $tahunid,
                'nama_kompetensi' => 'Indikator 1',
                'tipe' => 'INDIKATOR',
                'parent_id' => $cplid,
                'ambang_batas' => 70
            ]
        );

        // =====================================================
        // CPMK
        // =====================================================

        $cpmkid = $DB->insert_record(
            'local_obe_kompetensi',
            (object)[
                'id_prodi' => $prodiid,
                'frameworkid' => 1,
                'id_tahun_akademik' => $tahunid,
                'nama_kompetensi' => 'CPMK 1',
                'tipe' => 'CPMK',
                'parent_id' => $indikatorid,
                'ambang_batas' => 70
            ]
        );

        // =====================================================
        // SUB CPMK
        // =====================================================

        $subid = $DB->insert_record(
            'local_obe_kompetensi',
            (object)[
                'id_prodi' => $prodiid,
                'frameworkid' => 1,
                'id_tahun_akademik' => $tahunid,
                'nama_kompetensi' => 'Sub CPMK 1',
                'tipe' => 'SUB-CPMK',
                'parent_id' => $cpmkid,
                'ambang_batas' => 70
            ]
        );

        return [
            'subid' => $subid
        ];
    }

    // =====================================================
    // TEST SIMPAN
    // =====================================================

    public function test_eksekusi_simpan_berhasil() {

        $this->resetAfterTest(true);
        $this->setAdminUser();

        global $DB;

        $data =
            $this->buat_data_dummy();

        $_POST['cmid'] = 10;
        $_POST['sub_cpmk_id'] = $data['subid'];
        $_POST['bobot'] = 40;

        $_REQUEST['cmid'] = 10;
        $_REQUEST['sub_cpmk_id'] = $data['subid'];
        $_REQUEST['bobot'] = 40;

        $hasil =
            aktivitasnilai_controller
                ::eksekusi_simpan();

        $this->assertIsInt($hasil);

            $this->assertGreaterThan(
                0,
                $hasil
            );

        $record =
            $DB->get_record(
                'local_obe_aktivitas_map',
                [
                    'cmid' => 10,
                    'sub_cpmk_id' => $data['subid']
                ]
            );

        $this->assertNotEmpty($record);
    }

    // =====================================================
    // TEST SIMPAN GAGAL
    // =====================================================

    public function test_eksekusi_simpan_gagal_subcpmk_kosong() {

        $this->resetAfterTest(true);
        $this->setAdminUser();

        $_POST['cmid'] = 10;
        $_POST['sub_cpmk_id'] = 0;
        $_POST['bobot'] = 40;

        $_REQUEST['cmid'] = 10;
        $_REQUEST['sub_cpmk_id'] = 0;
        $_REQUEST['bobot'] = 40;

        $this->expectException(\moodle_exception::class);
        $hasil =
            aktivitasnilai_controller
                ::eksekusi_simpan();

        $this->assertFalse($hasil);
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
                'local_obe_aktivitas_map',
                (object)[
                    'cmid' => 10,
                    'sub_cpmk_id' => $data['subid'],
                    'bobot_aktivitas' => 20
                ]
            );

        $_POST['id'] = $id;
        $_POST['sub_cpmk_id'] = $data['subid'];
        $_POST['bobot'] = 80;

        $_REQUEST['id'] = $id;
        $_REQUEST['sub_cpmk_id'] = $data['subid'];
        $_REQUEST['bobot'] = 80;

        $hasil =
            aktivitasnilai_controller
                ::eksekusi_update();

        $this->assertTrue($hasil);

        $record =
            $DB->get_record(
                'local_obe_aktivitas_map',
                [
                    'id' => $id
                ]
            );

        $this->assertEquals(
            80,
            $record->bobot_aktivitas
        );
    }

    // =====================================================
    // TEST UPDATE GAGAL
    // =====================================================

    public function test_eksekusi_update_gagal() {

        $this->resetAfterTest(true);
        $this->setAdminUser();

        $_POST['id'] = 0;
        $_POST['sub_cpmk_id'] = 0;
        $_POST['bobot'] = 50;

        $_REQUEST['id'] = 0;
        $_REQUEST['sub_cpmk_id'] = 0;
        $_REQUEST['bobot'] = 50;

        $this->expectException(\moodle_exception::class);
        $hasil =
            aktivitasnilai_controller
                ::eksekusi_update();

        $this->assertFalse($hasil);
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
                'local_obe_aktivitas_map',
                (object)[
                    'cmid' => $this->getDataGenerator()->create_module('assign', ['course' => $this->getDataGenerator()->create_course()->id])->cmid,
                    'sub_cpmk_id' => $data['subid'],
                    'bobot_aktivitas' => 30
                ]
            );

        $hasil =
            aktivitasnilai_controller
                ::eksekusi_hapus($id);

        $this->assertTrue($hasil);

        $record =
            $DB->get_record(
                'local_obe_aktivitas_map',
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
            aktivitasnilai_controller
                ::eksekusi_hapus(0);

        $this->assertFalse($hasil);
    }
}