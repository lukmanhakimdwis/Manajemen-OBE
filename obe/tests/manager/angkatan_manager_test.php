<?php
namespace local_obe;

defined('MOODLE_INTERNAL') || die();

global $CFG;

require_once($CFG->dirroot . '/local/obe/classes/manager/angkatan_manager.php');

use advanced_testcase;
use local_obe\manager\angkatan_manager;

class angkatan_manager_test extends advanced_testcase {

    /**
     * TC-ANG-01
     * Menyimpan data angkatan valid.
     */
    public function test_simpan_angkatan_valid() {
        global $DB;

        $this->resetAfterTest(true);
        $this->setAdminUser();

        // Membuat Program Studi
        $prodi = new \stdClass();
        $prodi->kode_prodi = 'TRPL';
        $prodi->nama_prodi = 'Teknologi Rekayasa Perangkat Lunak';
        $idprodi = $DB->insert_record('local_obe_prodi', $prodi);

        // Data Angkatan
        $data = new \stdClass();
        $data->id_prodi = $idprodi;
        $data->tahun_masuk = 2024;
        $data->nama_angkatan = 'Angkatan 2024';

        $id = angkatan_manager::simpan($data);

        $this->assertGreaterThan(0, $id);

        $record = $DB->get_record(
            'local_obe_angkatan',
            ['id' => $id]
        );

        $this->assertNotEmpty($record);
        $this->assertEquals(2024, $record->tahun_masuk);
        $this->assertEquals('Angkatan 2024', $record->nama_angkatan);
    }

    /**
     * TC-ANG-02
     * Validasi data angkatan duplikat.
     */
    public function test_simpan_angkatan_duplikat() {
        global $DB;

        $this->resetAfterTest(true);
        $this->setAdminUser();

        // Membuat Program Studi
        $prodi = new \stdClass();
        $prodi->kode_prodi = 'TRPL';
        $prodi->nama_prodi = 'Teknologi Rekayasa Perangkat Lunak';
        $idprodi = $DB->insert_record('local_obe_prodi', $prodi);

        // Data Angkatan
        $data = new \stdClass();
        $data->id_prodi = $idprodi;
        $data->tahun_masuk = 2024;
        $data->nama_angkatan = 'Angkatan 2024';

        angkatan_manager::simpan($data);

        $this->expectException(\moodle_exception::class);

        angkatan_manager::simpan($data);
    }
}