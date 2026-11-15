<?php
namespace local_obe;

defined('MOODLE_INTERNAL') || die();

global $CFG;

require_once($CFG->dirroot . '/local/obe/classes/manager/skala_nilai_manager.php');

use advanced_testcase;
use local_obe\manager\skala_nilai_manager;

class skala_nilai_manager_test extends advanced_testcase {

    /**
     * TC-SN-01
     * Memastikan nilai berhasil dikonversi menjadi huruf nilai.
     */
    public function test_cek_skala_valid() {
        global $DB;

        $this->resetAfterTest(true);
        $this->setAdminUser();

        // Program Studi
        $prodi = new \stdClass();
        $prodi->kode_prodi = 'TRPL';
        $prodi->nama_prodi = 'Teknologi Rekayasa Perangkat Lunak';
        $idprodi = $DB->insert_record('local_obe_prodi', $prodi);

        // Tahun Akademik
        $tahun = new \stdClass();
        $tahun->nama_tahun = '2025/2026';
        $tahun->status_aktif = 1;
        $idtahun = $DB->insert_record('local_obe_tahun_akademik', $tahun);

        // Skala Nilai
        $skala = new \stdClass();
        $skala->id_prodi = $idprodi;
        $skala->id_tahun_akademik = $idtahun;
        $skala->tipe_kompetensi = 'CPL';
        $skala->huruf_nilai = 'A';
        $skala->nilai_min = 80;
        $skala->nilai_max = 100;
        $skala->status_kompeten = 1;
        $skala->timecreated = time();
        $skala->timemodified = time();

        $DB->insert_record('local_obe_skala_nilai', $skala);

        $hasil = skala_nilai_manager::cek_skala(
            90,
            'CPL',
            $idprodi,
            $idtahun
        );

        $this->assertEquals('A', $hasil);
    }

    /**
     * TC-SN-02
     * Memastikan fungsi mengembalikan "-" jika skala tidak ditemukan.
     */
    public function test_cek_skala_tidak_ditemukan() {
        global $DB;

        $this->resetAfterTest(true);
        $this->setAdminUser();

        // Program Studi
        $prodi = new \stdClass();
        $prodi->kode_prodi = 'TRPL';
        $prodi->nama_prodi = 'Teknologi Rekayasa Perangkat Lunak';
        $idprodi = $DB->insert_record('local_obe_prodi', $prodi);

        // Tahun Akademik
        $tahun = new \stdClass();
        $tahun->nama_tahun = '2025/2026';
        $tahun->status_aktif = 1;
        $idtahun = $DB->insert_record('local_obe_tahun_akademik', $tahun);

        $hasil = skala_nilai_manager::cek_skala(
            90,
            'CPL',
            $idprodi,
            $idtahun
        );

        $this->assertEquals('-', $hasil);
    }

}