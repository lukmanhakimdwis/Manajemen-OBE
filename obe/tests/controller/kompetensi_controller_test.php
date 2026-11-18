<?php

namespace local_obe;

defined('MOODLE_INTERNAL') || die();
require_once(__DIR__.'/../fixtures/ai_fixture.php');

global $CFG;

require_once(
    $CFG->dirroot . '/local/obe/classes/controller/kompetensi_controller.php'
);

use advanced_testcase;
use local_obe\controller\kompetensi_controller;

/**
 * Unit Test
 * Kompetensi Controller
 */
class kompetensi_controller_test
    extends advanced_testcase {
    use \local_obe\tests\ai_fixture;

    /**
     * Setup data dummy
     */
    private function buat_data_dummy() {
        $this->preventResetByRollback();$e=$this->environment();
        return ['prodiid'=>$e['prodi'],'tahunid'=>$e['year'],'kompetensiid'=>$e['ids']['SUB-CPMK']];
    }

   

    // =====================================================
    // TEST HANDLE REQUEST DEFAULT
    // =====================================================

    public function test_handle_request_default() {

        $this->resetAfterTest(true);
        $this->setAdminUser();

        $this->assertNull(
            kompetensi_controller
                ::handle_request(
                    'aksi_salah',
                    1
                )
        );
    }

    // =====================================================
    // TEST HANDLE SYNC
    // =====================================================

    public function test_handle_sync() {

        $this->resetAfterTest(true);
        $this->setAdminUser();

        global $SESSION,$DB;

        $this->setAdminUser();

        $data =
            $this->buat_data_dummy();

        $SESSION->obe_id_tahun_akademik =
            $data['tahunid'];

        $_POST['sesskey'] =
            sesskey();

        $_REQUEST['sesskey'] =
            sesskey();

        try {

            kompetensi_controller
                ::handle_sync(
                    $data['prodiid']
                );

        } catch (\moodle_exception $e) {

            // Validate the real synced fixture even when redirect terminates the request.
            $this->assertEquals(4,$DB->count_records('local_obe_kompetensi',['id_prodi'=>$data['prodiid'],'id_tahun_akademik'=>$data['tahunid']]));
            $this->assertTrue(true);

            return;
        }

        $this->assertTrue(true);
    }

    // =====================================================
    // TEST HANDLE UPDATE AMBANG
    // =====================================================

    public function test_handle_update_ambang() {

        $this->resetAfterTest(true);
        $this->setAdminUser();

        $this->setAdminUser();

        $data =
            $this->buat_data_dummy();

        $_POST['sesskey'] =
            sesskey();

        $_REQUEST['sesskey'] =
            sesskey();

        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage('Ambang kompeten dikelola melalui menu skala nilai, bukan per kompetensi.');
        kompetensi_controller::handle_update_ambang($data['kompetensiid'],80,$data['prodiid']);
    }
}
