<?php

namespace local_obe;

defined('MOODLE_INTERNAL') || die();

global $CFG;

require_once(
    $CFG->dirroot . '/local/obe/classes/controller/tahun_akademik_controller.php'
);

use advanced_testcase;
use local_obe\controller\tahun_akademik_controller;

/**
 * Unit Test
 * Tahun Akademik Controller
 */
class tahun_akademik_controller_test
    extends advanced_testcase {

    // =====================================================
    // TEST TAMBAH TAHUN AKADEMIK
    // =====================================================

    public function test_handle_process_tambah() {

        $this->resetAfterTest(true);
        $this->setAdminUser();

        global $DB;

        $this->setAdminUser();

        $_POST['nama_tahun'] =
            '2025/2026';

        $_POST['sesskey'] =
            sesskey();

        $_REQUEST['nama_tahun'] =
            '2025/2026';

        $_REQUEST['sesskey'] =
            sesskey();

        try {

            tahun_akademik_controller
                ::handle_process(
                    'tambah'
                );

        } catch (\moodle_exception $e) {

            // Redirect dianggap sukses
        }

        $record =
            $DB->get_record(
                'local_obe_tahun_akademik',
                [
                    'nama_tahun' => '2025/2026'
                ]
            );

        $this->assertNotEmpty($record);
    }

    // =====================================================
    // TEST AKTIFKAN TAHUN AKADEMIK
    // =====================================================

    public static function aktifkan($id) {

    global $DB;

    // Nonaktifkan semua tahun akademik
    $DB->execute(
        "UPDATE {local_obe_tahun_akademik}
         SET status_aktif = 0"
    );

    // Aktifkan tahun akademik terpilih
    return $DB->set_field(
        'local_obe_tahun_akademik',
        'status_aktif',
        1,
        ['id' => $id]
    );
}

    // =====================================================
    // TEST HAPUS TAHUN AKADEMIK
    // =====================================================

    public function test_handle_process_hapus() {

        $this->resetAfterTest(true);
        $this->setAdminUser();

        global $DB;

        $this->setAdminUser();

        $id =
            $DB->insert_record(
                'local_obe_tahun_akademik',
                (object)[
                    'nama_tahun' => '2025/2026',
                    'status_aktif' => 0
                ]
            );

        $_POST['id'] =
            $id;

        $_POST['sesskey'] =
            sesskey();

        $_REQUEST['id'] =
            $id;

        $_REQUEST['sesskey'] =
            sesskey();

        try {

            tahun_akademik_controller
                ::handle_process(
                    'hapus'
                );

        } catch (\moodle_exception $e) {

            // Redirect dianggap sukses
        }

        $record =
            $DB->get_record(
                'local_obe_tahun_akademik',
                [
                    'id' => $id
                ]
            );

        $this->assertFalse($record);
    }

    // =====================================================
    // TEST AKSI TIDAK VALID
    // =====================================================

    public function test_handle_process_aksi_tidak_valid() {

        $this->resetAfterTest(true);
        $this->setAdminUser();

        $this->setAdminUser();

        $_POST['sesskey'] =
            sesskey();

        $_REQUEST['sesskey'] =
            sesskey();

        $this->assertNull(
            tahun_akademik_controller
                ::handle_process(
                    'aksi_salah'
                )
        );
    }
}