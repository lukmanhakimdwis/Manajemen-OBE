<?php
namespace local_obe;

defined('MOODLE_INTERNAL') || die();

global $CFG;

require_once($CFG->dirroot . '/local/obe/classes/manager/matakuliah_manager.php');

use advanced_testcase;
use local_obe\manager\matakuliah_manager;

class matakuliah_manager_test extends advanced_testcase {

    /**
     * TC-MK-01
     * Menyimpan data mata kuliah dengan data valid.
     */
    public function test_simpan_matakuliah_valid() {
        global $DB;

        $this->resetAfterTest(true);
        $this->setAdminUser();

        // Membuat Program Studi
        $prodi = new \stdClass();
        $prodi->kode_prodi = 'TRPL';
        $prodi->nama_prodi = 'Teknologi Rekayasa Perangkat Lunak';
        $idprodi = $DB->insert_record('local_obe_prodi', $prodi);

        // Data Mata Kuliah
        $data = new \stdClass();
        $data->kode_mk = 'TRPL101';
        $data->nama_mk = 'Pemrograman Dasar';
        $data->sks_mk = 3;
        $data->id_prodi = $idprodi;

        $id = matakuliah_manager::simpan($data);

        $this->assertGreaterThan(0, $id);

        $record = $DB->get_record(
            'local_obe_mata_kuliah',
            ['id' => $id]
        );

        $this->assertNotEmpty($record);
        $this->assertEquals('TRPL101', $record->kode_mk);
        $this->assertEquals('Pemrograman Dasar', $record->nama_mk);
        $this->assertEquals(3, $record->sks_mk);
    }

    /**
     * TC-MK-02
     * Validasi kode mata kuliah kosong.
     */
    public function test_simpan_matakuliah_kode_kosong() {
        global $DB;

        $this->resetAfterTest(true);
        $this->setAdminUser();

        // Membuat Program Studi
        $prodi = new \stdClass();
        $prodi->kode_prodi = 'TRPL';
        $prodi->nama_prodi = 'Teknologi Rekayasa Perangkat Lunak';
        $idprodi = $DB->insert_record('local_obe_prodi', $prodi);

        // Data Mata Kuliah
        $data = new \stdClass();
        $data->kode_mk = '';
        $data->nama_mk = 'Pemrograman Dasar';
        $data->sks_mk = 3;
        $data->id_prodi = $idprodi;

        $this->expectException(\moodle_exception::class);

        matakuliah_manager::simpan($data);
    }

    /**
     * TC-MK-03
     * Validasi kode mata kuliah duplikat.
     */
    public function test_simpan_matakuliah_duplikat() {
        global $DB;

        $this->resetAfterTest(true);
        $this->setAdminUser();

        // Membuat Program Studi
        $prodi = new \stdClass();
        $prodi->kode_prodi = 'TRPL';
        $prodi->nama_prodi = 'Teknologi Rekayasa Perangkat Lunak';
        $idprodi = $DB->insert_record('local_obe_prodi', $prodi);

        // Data Mata Kuliah
        $data = new \stdClass();
        $data->kode_mk = 'TRPL101';
        $data->nama_mk = 'Pemrograman Dasar';
        $data->sks_mk = 3;
        $data->id_prodi = $idprodi;

        // Simpan pertama
        matakuliah_manager::simpan($data);

        // Simpan kedua harus gagal
        $this->expectException(\moodle_exception::class);

        matakuliah_manager::simpan($data);
    }
}