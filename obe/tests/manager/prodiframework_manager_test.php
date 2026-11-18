<?php
namespace local_obe;

defined('MOODLE_INTERNAL') || die();

global $CFG;

require_once($CFG->dirroot . '/local/obe/classes/manager/prodiframework_manager.php');

use advanced_testcase;
use local_obe\manager\prodiframework_manager;

class prodiframework_manager_test extends advanced_testcase {

    /**
     * Membuat data pendukung
     */
    private function buat_data() {
        global $DB;

        // Program Studi
        $prodi = new \stdClass();
        $prodi->kode_prodi = 'TRPL';
        $prodi->nama_prodi = 'Teknologi Rekayasa Perangkat Lunak';
        $idprodi = $DB->insert_record('local_obe_prodi', $prodi);

        // Framework Moodle
        $framework=$this->getDataGenerator()->get_plugin_generator('core_competency')->create_framework(['shortname'=>'Framework OBE','idnumber'=>'FW001']);
        $idframework=$framework->get('id');

        // Tahun Akademik
        $tahun = new \stdClass();
        $tahun->nama_tahun = '2025/2026';
        $tahun->status_aktif = 1;

        $idtahun = $DB->insert_record(
            'local_obe_tahun_akademik',
            $tahun
        );

        return [
            'id_prodi' => $idprodi,
            'id_framework' => $idframework,
            'id_tahun' => $idtahun
        ];
    }

    /**
     * TC-PF-01
     * Data Prodi Framework tidak ditemukan.
     */
    public function test_memiliki_relasi_data_tidak_ditemukan() {

        $this->resetAfterTest(true);
        $this->setAdminUser();

        $hasil = prodiframework_manager::memiliki_relasi(99999);

        $this->assertFalse($hasil);
    }

    /**
     * TC-PF-02
     * Data Prodi Framework ada tetapi belum memiliki relasi kompetensi.
     */
    public function test_memiliki_relasi_false() {

        global $DB;

        $this->resetAfterTest(true);
        $this->setAdminUser();

        $data = $this->buat_data();

        $pf = new \stdClass();
        $pf->id_prodi = $data['id_prodi'];
        $pf->frameworkid = $data['id_framework'];
        $pf->id_tahun_akademik = $data['id_tahun'];

        $idpf = $DB->insert_record(
            'local_obe_prodi_framework',
            $pf
        );

        $hasil = prodiframework_manager::memiliki_relasi($idpf);

        $this->assertFalse($hasil);
    }

    /**
     * TC-PF-03
     * Data Prodi Framework telah memiliki relasi kompetensi.
     */
    public function test_memiliki_relasi_true() {

        global $DB;

        $this->resetAfterTest(true);
        $this->setAdminUser();

        $data = $this->buat_data();

        $pf = new \stdClass();
        $pf->id_prodi = $data['id_prodi'];
        $pf->frameworkid = $data['id_framework'];
        $pf->id_tahun_akademik = $data['id_tahun'];

        $idpf = $DB->insert_record(
            'local_obe_prodi_framework',
            $pf
        );

        // Tambahkan kompetensi agar relasi bernilai TRUE
        $kompetensi = new \stdClass();
        $kompetensi->id_prodi = $data['id_prodi'];
        $kompetensi->frameworkid = $data['id_framework'];
        $kompetensi->id_tahun_akademik = $data['id_tahun'];
        $kompetensi->competencyid = 1;
        $kompetensi->nama_kompetensi = 'CPL-01';
        $kompetensi->tipe = 'CPL';
        $kompetensi->parent_id = 0;

        $DB->insert_record(
            'local_obe_kompetensi',
            $kompetensi
        );

        $hasil = prodiframework_manager::memiliki_relasi($idpf);

        $this->assertTrue($hasil);
    }
}