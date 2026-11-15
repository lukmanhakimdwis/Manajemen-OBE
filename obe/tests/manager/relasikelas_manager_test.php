<?php
namespace local_obe;

defined('MOODLE_INTERNAL') || die();

global $CFG;

require_once($CFG->dirroot . '/local/obe/classes/manager/relasikelas_manager.php');

use advanced_testcase;
use local_obe\manager\relasikelas_manager;

class relasikelas_manager_test extends advanced_testcase {

    /**
     * TC-RK-01
     * Menyimpan data relasi kelas yang valid.
     */
    public function test_simpan_relasi_kelas_valid() {
        global $DB;

        $this->resetAfterTest(true);
        $this->setAdminUser();

        // ===========================
        // Program Studi
        // ===========================
        $prodi = new \stdClass();
        $prodi->kode_prodi = 'TRPL';
        $prodi->nama_prodi = 'Teknologi Rekayasa Perangkat Lunak';
        $idprodi = $DB->insert_record('local_obe_prodi', $prodi);

        // ===========================
        // Tahun Akademik
        // ===========================
        $tahun = new \stdClass();
        $tahun->nama_tahun = '2025/2026';
        $tahun->status_aktif = 1;
        $idtahun = $DB->insert_record('local_obe_tahun_akademik', $tahun);

        // ===========================
        // Angkatan
        // ===========================
        $angkatan = new \stdClass();
        $angkatan->id_prodi = $idprodi;
        $angkatan->tahun_masuk = 2024;
        $angkatan->nama_angkatan = 'Angkatan 2024';
        $idangkatan = $DB->insert_record('local_obe_angkatan', $angkatan);

        // ===========================
        // Mata Kuliah
        // ===========================
        $mk = new \stdClass();
        $mk->id_prodi = $idprodi;
        $mk->kode_mk = 'TRPL101';
        $mk->nama_mk = 'Pemrograman Dasar';
        $mk->sks_mk = 3;
        $idmk = $DB->insert_record('local_obe_mata_kuliah', $mk);

        // ===========================
        // Data Relasi
        // ===========================
        $data = new \stdClass();
        $data->id_mata_kuliah = $idmk;
        $data->id_kursus_moodle = 100;
        $data->id_angkatan = $idangkatan;
        $data->id_tahun_akademik = $idtahun;

        $id = relasikelas_manager::simpan($data);

        $this->assertGreaterThan(0, $id);

        $record = $DB->get_record(
            'local_obe_relasi_kelas',
            ['id' => $id]
        );

        $this->assertNotEmpty($record);
        $this->assertEquals($idmk, $record->id_mata_kuliah);
        $this->assertEquals(100, $record->id_kursus_moodle);
        $this->assertEquals($idangkatan, $record->id_angkatan);
        $this->assertEquals($idtahun, $record->id_tahun_akademik);
    }

    /**
     * TC-RK-02
     * Validasi kelas Moodle belum dipilih.
     */
    public function test_simpan_relasi_kelas_course_kosong() {
        global $DB;

        $this->resetAfterTest(true);
        $this->setAdminUser();

        // ===========================
        // Program Studi
        // ===========================
        $prodi = new \stdClass();
        $prodi->kode_prodi = 'TRPL';
        $prodi->nama_prodi = 'Teknologi Rekayasa Perangkat Lunak';
        $idprodi = $DB->insert_record('local_obe_prodi', $prodi);

        // ===========================
        // Tahun Akademik
        // ===========================
        $tahun = new \stdClass();
        $tahun->nama_tahun = '2025/2026';
        $tahun->status_aktif = 1;
        $idtahun = $DB->insert_record('local_obe_tahun_akademik', $tahun);

        // ===========================
        // Angkatan
        // ===========================
        $angkatan = new \stdClass();
        $angkatan->id_prodi = $idprodi;
        $angkatan->tahun_masuk = 2024;
        $angkatan->nama_angkatan = 'Angkatan 2024';
        $idangkatan = $DB->insert_record('local_obe_angkatan', $angkatan);

        // ===========================
        // Mata Kuliah
        // ===========================
        $mk = new \stdClass();
        $mk->id_prodi = $idprodi;
        $mk->kode_mk = 'TRPL101';
        $mk->nama_mk = 'Pemrograman Dasar';
        $mk->sks_mk = 3;
        $idmk = $DB->insert_record('local_obe_mata_kuliah', $mk);

        // ===========================
        // Data Relasi
        // ===========================
        $data = new \stdClass();
        $data->id_mata_kuliah = $idmk;
        $data->id_kursus_moodle = 0;
        $data->id_angkatan = $idangkatan;
        $data->id_tahun_akademik = $idtahun;

        $this->expectException(\moodle_exception::class);

        relasikelas_manager::simpan($data);
    }
}