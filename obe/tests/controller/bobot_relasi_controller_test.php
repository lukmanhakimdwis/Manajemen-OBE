<?php

namespace local_obe;

defined('MOODLE_INTERNAL') || die();
require_once(__DIR__.'/../fixtures/ai_fixture.php');

global $CFG;

require_once(
    $CFG->dirroot . '/local/obe/classes/controller/bobot_relasi_controller.php'
);

use advanced_testcase;
use local_obe\controller\bobot_relasi_controller;

/**
 * Unit Test
 * Bobot Relasi Controller
 */
class bobot_relasi_controller_test
    extends advanced_testcase {
    use \local_obe\tests\ai_fixture;

    /**
     * Setup data dummy
     */
    private function buat_data_dummy() {
        $this->preventResetByRollback();$e=$this->environment();
        return ['prodiid'=>$e['prodi'],'tahunid'=>$e['year'],'kompetensiid'=>$e['ids']['CPL']];
    }

    // =====================================================
    // TEST HANDLE PROCESS BERHASIL
    // =====================================================

    public function test_handle_process_berhasil() {

        $this->resetAfterTest(true);
        $this->setAdminUser();

        global $SESSION,$DB;

        $this->setAdminUser();

        $data =
            $this->buat_data_dummy();

        $SESSION->obe_id_tahun_akademik =
            $data['tahunid'];

        $_POST['id_kompetensi'] =
            $data['kompetensiid'];

        $_POST['bobot'] =
            50;

        $_REQUEST['id_kompetensi'] =
            $data['kompetensiid'];

        $_REQUEST['bobot'] =
            50;

        $_POST['sesskey'] =
            sesskey();

        $_REQUEST['sesskey'] =
            sesskey();

        try {

            bobot_relasi_controller
                ::handle_process(
                    'simpan_bobot',
                    1,
                    $data['prodiid']
                );

        } catch (\moodle_exception $e) {

            // A redirect alone is not proof of a successful save.
            $this->assertEquals(50,$DB->get_field('local_obe_bobot_relasi','nilai_bobot',['id_kompetensi_asal'=>$data['kompetensiid'],'id_tahun_akademik'=>$data['tahunid']]));
            $this->assertTrue(true);

            return;
        }

        $this->assertEquals(50,$DB->get_field('local_obe_bobot_relasi','nilai_bobot',['id_kompetensi_asal'=>$data['kompetensiid'],'id_tahun_akademik'=>$data['tahunid']]));
        $this->assertTrue(true);
    }

    // =====================================================
    // TEST HANDLE PROCESS AKSI TIDAK SESUAI
    // =====================================================

    public function test_handle_process_aksi_tidak_sesuai() {

        $this->resetAfterTest(true);
        $this->setAdminUser();

        $this->setAdminUser();

        bobot_relasi_controller
            ::handle_process(
                'aksi_salah',
                1,
                1
            );

        $this->assertTrue(true);
    }
}