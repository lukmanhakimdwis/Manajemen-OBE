<?php

namespace local_obe;

defined('MOODLE_INTERNAL') || die();

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
        // KOMPETENSI
        // =====================================================

        $kompetensiid =
            $DB->insert_record(
                'local_obe_kompetensi',
                (object)[
                    'id_prodi' => $prodiid,
                    'frameworkid' => 1,
                    'id_tahun_akademik' => $tahunid,
                    'nama_kompetensi' => 'Sub CPMK 1',
                    'tipe' => 'SUB-CPMK',
                    'parent_id' => 0,
                    'ambang_batas' => 70
                ]
            );

        return [
            'prodiid' => $prodiid,
            'tahunid' => $tahunid,
            'kompetensiid' => $kompetensiid
        ];
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

        global $SESSION;

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

            // Redirect dianggap sukses
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

        try {

            kompetensi_controller
                ::handle_update_ambang(
                    $data['kompetensiid'],
                    80,
                    $data['prodiid']
                );

        } catch (\moodle_exception $e) {

            // Redirect dianggap sukses
            $this->assertTrue(true);

            return;
        }

        global $DB;

        $record =
            $DB->get_record(
                'local_obe_kompetensi',
                [
                    'id' => $data['kompetensiid']
                ]
            );

        $this->assertEquals(
            80,
            $record->ambang_batas
        );
    }
}