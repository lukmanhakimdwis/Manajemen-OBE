<?php
namespace local_obe;

defined('MOODLE_INTERNAL') || die();

global $CFG;

require_once($CFG->dirroot . '/local/obe/classes/manager/bobot_relasi_manager.php');

use advanced_testcase;
use local_obe\manager\bobot_relasi_manager;

class bobot_relasi_manager_test extends advanced_testcase {

    /**
     * Membuat data pendukung
     */
    private function buat_data() {

        global $DB;

        // =========================
        // PRODI
        // =========================
        $prodi = new \stdClass();
        $prodi->kode_prodi = 'TRPL';
        $prodi->nama_prodi = 'Teknologi Rekayasa Perangkat Lunak';

        $idprodi = $DB->insert_record(
            'local_obe_prodi',
            $prodi
        );

        // =========================
        // TAHUN AKADEMIK
        // =========================
        $tahun = new \stdClass();
        $tahun->nama_tahun = '2025/2026';
        $tahun->status_aktif = 1;

        $idtahun = $DB->insert_record(
            'local_obe_tahun_akademik',
            $tahun
        );

        // =========================
        // CPL
        // =========================
        $cpl = new \stdClass();
        $cpl->id_prodi = $idprodi;
        $cpl->frameworkid = 1;
        $cpl->competencyid = 1;
        $cpl->nama_kompetensi = 'CPL';
        $cpl->deskripsi = '';
        $cpl->tipe = 'CPL';
        $cpl->parent_id = 0;
        $cpl->id_tahun_akademik = $idtahun;

        $idcpl = $DB->insert_record(
            'local_obe_kompetensi',
            $cpl
        );

        // =========================
        // CPMK
        // =========================
        $cpmk = new \stdClass();
        $cpmk->id_prodi = $idprodi;
        $cpmk->frameworkid = 1;
        $cpmk->competencyid = 2;
        $cpmk->nama_kompetensi = 'CPMK';
        $cpmk->deskripsi = '';
        $cpmk->tipe = 'CPMK';
        $cpmk->parent_id = $idcpl;
        $cpmk->id_tahun_akademik = $idtahun;

        $idcpmk = $DB->insert_record(
            'local_obe_kompetensi',
            $cpmk
        );

        return [
            'tahun' => $idtahun,
            'cpl'   => $idcpl,
            'cpmk'  => $idcpmk
        ];
    }

    /**
     * TC-BR-01
     * Validasi bobot di luar rentang.
     */
    public function test_save_bobot_invalid() {

        $this->resetAfterTest(true);
        $this->setAdminUser();

        $data = $this->buat_data();

        $this->expectException(\moodle_exception::class);

        bobot_relasi_manager::save_bobot(
            $data['cpmk'],
            120,
            $data['tahun']
        );
    }

    /**
     * TC-BR-02
     * Kompetensi tidak ditemukan.
     */
    public function test_save_bobot_kompetensi_tidak_ada() {

        $this->resetAfterTest(true);
        $this->setAdminUser();

        $hasil = bobot_relasi_manager::save_bobot(
            99999,
            50,
            1
        );

        $this->assertFalse($hasil);
    }

    /**
     * TC-BR-03
     * Simpan bobot berhasil.
     */
    public function test_save_bobot_valid() {

        global $DB;

        $this->resetAfterTest(true);
        $this->setAdminUser();

        $data = $this->buat_data();

        $id = bobot_relasi_manager::save_bobot(
            $data['cpmk'],
            40,
            $data['tahun']
        );

        $this->assertGreaterThan(0, $id);

        $record = $DB->get_record(
            'local_obe_bobot_relasi',
            [
                'id' => $id
            ]
        );

        $this->assertNotEmpty($record);

        $this->assertEquals(
            40,
            $record->nilai_bobot
        );

        $this->assertEquals(
            'CPMK',
            $record->tipe_asal
        );
    }

}