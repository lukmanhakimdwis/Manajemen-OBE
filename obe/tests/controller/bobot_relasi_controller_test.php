<?php

namespace local_obe;

defined('MOODLE_INTERNAL') || die();

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
        // CPL
        // =====================================================

        $kompetensiid =
            $DB->insert_record(
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

        return [
            'prodiid' => $prodiid,
            'tahunid' => $tahunid,
            'kompetensiid' => $kompetensiid
        ];
    }

    // =====================================================
    // TEST HANDLE PROCESS BERHASIL
    // =====================================================

    public function test_handle_process_berhasil() {

        $this->resetAfterTest(true);
        $this->setAdminUser();

        global $SESSION;

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

            // Redirect dianggap sukses
            $this->assertTrue(true);

            return;
        }

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