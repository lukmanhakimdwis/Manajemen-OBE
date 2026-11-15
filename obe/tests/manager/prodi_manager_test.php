<?php
namespace local_obe;

defined('MOODLE_INTERNAL') || die();

global $CFG;

require_once($CFG->dirroot . '/local/obe/classes/manager/prodi_manager.php');

use advanced_testcase;
use local_obe\manager\prodi_manager;

class prodi_manager_test extends advanced_testcase {

    /**
     * TC-PR-01
     * Menyimpan data valid.
     */
    public function test_simpan_prodi_valid() {
        global $DB;

        $this->resetAfterTest(true);
        $this->setAdminUser();

        $data = new \stdClass();
        $data->kode_prodi = 'TRPL';
        $data->nama_prodi = 'Teknologi Rekayasa Perangkat Lunak';

        $id = prodi_manager::simpan_prodi($data);

        $this->assertGreaterThan(0, $id);

        $record = $DB->get_record(
            'local_obe_prodi',
            ['id' => $id]
        );

        $this->assertNotEmpty($record);
        $this->assertEquals('TRPL', $record->kode_prodi);
        $this->assertEquals(
            'Teknologi Rekayasa Perangkat Lunak',
            $record->nama_prodi
        );
    }

    /**
     * TC-PR-02
     * Validasi kode kosong.
     */
    public function test_simpan_prodi_kode_kosong() {

        $this->resetAfterTest(true);
        $this->setAdminUser();

        $data = new \stdClass();
        $data->kode_prodi = '';
        $data->nama_prodi = 'TRPL';

        $this->expectException(\moodle_exception::class);

        prodi_manager::simpan_prodi($data);
    }

    /**
     * TC-PR-03
     * Validasi kode duplikat.
     */
    public function test_simpan_prodi_duplikat() {

        $this->resetAfterTest(true);
        $this->setAdminUser();

        $data = new \stdClass();
        $data->kode_prodi = 'TRPL';
        $data->nama_prodi = 'TRPL';

        prodi_manager::simpan_prodi($data);

        $this->expectException(\moodle_exception::class);

        prodi_manager::simpan_prodi($data);
    }

}