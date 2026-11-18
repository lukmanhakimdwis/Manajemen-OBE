<?php

namespace local_obe;

defined('MOODLE_INTERNAL') || die();
require_once(__DIR__.'/../fixtures/ai_fixture.php');

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
    use \local_obe\tests\ai_fixture;

    /**
     * Setup data dummy
     */
    private function buat_data_dummy() {
        global $DB;$this->preventResetByRollback();$e=$this->environment();
        $map=$DB->get_field('local_obe_aktivitas_map','id',['cmid'=>$e['activity']->cmid],MUST_EXIST);
        \local_obe\manager\aktivitasnilai_manager::delete($map);
        $activity=$this->getDataGenerator()->create_module('assign',['course'=>$e['course']->id]);
        return ['subid'=>$e['ids']['SUB-CPMK'],'courseid'=>$e['course']->id,'cmid'=>$activity->cmid];
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

        $_POST['cmid'] = $data['cmid'];
        $_POST['sub_cpmk_id'] = $data['subid'];
        $_POST['bobot'] = 40;

        $_REQUEST['cmid'] = $data['cmid'];
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
                    'cmid' => $data['cmid'],
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

        $data=$this->buat_data_dummy();
        $_POST['cmid'] = $data['cmid'];
        $_POST['sub_cpmk_id'] = 0;
        $_POST['bobot'] = 40;

        $_REQUEST['cmid'] = $data['cmid'];
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
                    'cmid' => $data['cmid'],
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
                    'cmid' => $data['cmid'],
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