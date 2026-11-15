<?php
namespace local_obe;

defined('MOODLE_INTERNAL') || die();

global $CFG;

require_once($CFG->dirroot . '/local/obe/classes/manager/tahun_akademik_manager.php');

use advanced_testcase;
use local_obe\manager\tahun_akademik_manager;

class tahun_akademik_manager_test extends advanced_testcase {

    /**
     * TC-TA-01
     * Menyimpan data tahun akademik dengan data valid.
     */
    public function test_simpan_data_valid() {
        global $DB;

        $this->resetAfterTest(true);
        $this->setAdminUser();

        $id = tahun_akademik_manager::simpan('2025/2026');

        $this->assertGreaterThan(0, $id);

        $record = $DB->get_record(
            'local_obe_tahun_akademik',
            ['id' => $id]
        );

        $this->assertNotEmpty($record);
        $this->assertEquals('2025/2026', $record->nama_tahun);
        $this->assertEquals(0, $record->status_aktif);
    }

    /**
     * TC-TA-02
     * Validasi nama tahun akademik kosong.
     */
    public function test_simpan_nama_kosong() {

        $this->resetAfterTest(true);
        $this->setAdminUser();

        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage('Nama tahun akademik wajib diisi.');

        tahun_akademik_manager::simpan('');
    }

    /**
     * TC-TA-03
     * Validasi data duplikat.
     */
    public function test_simpan_data_duplikat() {

        $this->resetAfterTest(true);
        $this->setAdminUser();

        tahun_akademik_manager::simpan('2025/2026');

        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage('Tahun akademik sudah tersedia.');

        tahun_akademik_manager::simpan('2025/2026');
    }
}