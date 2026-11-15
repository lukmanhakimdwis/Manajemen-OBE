<?php
namespace local_obe;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/local/obe/classes/manager/kompetensi_manager.php');
require_once($CFG->dirroot . '/local/obe/classes/manager/capaian_kelas_manager.php');

use advanced_testcase;
use stdClass;
use local_obe\manager\kompetensi_manager;

class kompetensi_manager_test extends advanced_testcase {

    /**
     * TC-KP-01: Memastikan fungsi mengembalikan null jika Sub-CPMK tidak ditemukan.
     */
    public function test_ambil_hierarki_subcpmk_tidak_ditemukan() {
        $this->resetAfterTest(true);
        $this->setAdminUser();
        $hasil = kompetensi_manager::ambil_hierarki_subcpmk(99999);
        $this->assertNull($hasil);
    }

    /**
     * TC-KP-02: Memastikan fungsi mengembalikan hierarki CPL -> Indikator -> CPMK -> Sub-CPMK.
     */
    public function test_ambil_hierarki_subcpmk() {
        global $DB;
        $this->resetAfterTest(true);
        $this->setAdminUser();

        $prodi = new stdClass();
        $prodi->kode_prodi = 'TRPL';
        $prodi->nama_prodi = 'Teknologi Rekayasa Perangkat Lunak';
        $idprodi = $DB->insert_record('local_obe_prodi', $prodi);

        $tahun = new stdClass();
        $tahun->nama_tahun = '2025/2026';
        $tahun->status_aktif = 1;
        $idtahun = $DB->insert_record('local_obe_tahun_akademik', $tahun);

        $cpl = new stdClass();
        $cpl->id_prodi = $idprodi;
        $cpl->frameworkid = 1;
        $cpl->competencyid = 1;
        $cpl->nama_kompetensi = 'CPL-01';
        $cpl->tipe = 'CPL';
        $cpl->parent_id = 0;
        $cpl->id_tahun_akademik = $idtahun;
        $idcpl = $DB->insert_record('local_obe_kompetensi', $cpl);

        $indikator = new stdClass();
        $indikator->id_prodi = $idprodi;
        $indikator->frameworkid = 1;
        $indikator->competencyid = 2;
        $indikator->nama_kompetensi = 'IK-01';
        $indikator->tipe = 'INDIKATOR';
        $indikator->parent_id = $idcpl;
        $indikator->id_tahun_akademik = $idtahun;
        $idindikator = $DB->insert_record('local_obe_kompetensi', $indikator);

        $cpmk = new stdClass();
        $cpmk->id_prodi = $idprodi;
        $cpmk->frameworkid = 1;
        $cpmk->competencyid = 3;
        $cpmk->nama_kompetensi = 'CPMK-01';
        $cpmk->tipe = 'CPMK';
        $cpmk->parent_id = $idindikator;
        $cpmk->id_tahun_akademik = $idtahun;
        $idcpmk = $DB->insert_record('local_obe_kompetensi', $cpmk);

        $sub = new stdClass();
        $sub->id_prodi = $idprodi;
        $sub->frameworkid = 1;
        $sub->competencyid = 4;
        $sub->nama_kompetensi = 'Sub CPMK-01';
        $sub->tipe = 'SUB-CPMK';
        $sub->parent_id = $idcpmk;
        $sub->id_tahun_akademik = $idtahun;
        $idsub = $DB->insert_record('local_obe_kompetensi', $sub);

        $hasil = kompetensi_manager::ambil_hierarki_subcpmk($idsub);

        $this->assertNotNull($hasil);
        $this->assertEquals('Sub CPMK-01', $hasil->subcpmk->nama_kompetensi);
        $this->assertEquals('CPMK-01', $hasil->cpmk->nama_kompetensi);
        $this->assertEquals('IK-01', $hasil->indikator->nama_kompetensi);
        $this->assertEquals('CPL-01', $hasil->cpl->nama_kompetensi);
    }

    /**
     * TC-KP-03: Menguji Penanganan Batas sinkronisasi jika data framework tidak valid (Edge Case)
     */
    public function test_sync_competencies_tanpa_framework() {
        $this->resetAfterTest(true);
        $this->setAdminUser();
        $hasil = kompetensi_manager::sync_competencies(999, 1);
        $this->assertFalse($hasil);
    }

    /**
     * TC-KP-04: Menguji Logika Pemetaan Otomatis 4 Level Kurikulum OBE via Framework Moodle
     */
    public function test_sync_dan_pengurutan_hierarki_kompetensi() {
        $this->resetAfterTest(true);
        $this->setAdminUser();
        global $DB;

        // Meredam warning deprecated dynamic property bawaan core Moodle di PHP 8.2+
        $this->setOutputCallback(function() {});

        // 1. Setup Data Dasar Program Studi & Tahun Akademik
        $prodi = new stdClass();
        $prodi->kode_prodi = 'TRPL';
        $prodi->nama_prodi = 'Teknologi Rekayasa Perangkat Lunak';
        $idprodi = $DB->insert_record('local_obe_prodi', $prodi);

        $tahun = new stdClass();
        $tahun->nama_tahun = '2025/2026';
        $tahun->status_aktif = 1;
        $idtahun = $DB->insert_record('local_obe_tahun_akademik', $tahun);

        // 2. Gunakan Core Moodle Generator untuk membuat framework kompetensi yang valid dan lengkap
        $dg = $this->getDataGenerator()->get_plugin_generator('core_competency');
        $framework = $dg->create_framework([
            'shortname' => 'Framework OBE TRPL',
            'idnumber'  => 'FW-TRPL-2026'
        ]);

        // Catat relasi framework kompetensi OBE lokal
        $pf = new stdClass();
        $pf->id_prodi = $idprodi;
        $pf->id_tahun_akademik = $idtahun;
        $pf->frameworkid = $framework->get('id');
        $DB->insert_record('local_obe_prodi_framework', $pf);

        // 3. Gunakan Generator untuk menyisipkan Hierarki Kompetensi Core Moodle secara aman (Bebas constraint DML)
        // Level 1: CPL (parentid = 0)
        $cpl_moodle = $dg->create_competency([
            'competencyframeworkid' => $framework->get('id'),
            'shortname' => 'CPL-Lokal-01',
            'idnumber'  => 'CPL01',
            'parentid'  => 0
        ]);

        // Level 2: INDIKATOR (parentid mengarah ke ID CPL Moodle)
        $ind_moodle = $dg->create_competency([
            'competencyframeworkid' => $framework->get('id'),
            'shortname' => 'IND-Lokal-01',
            'idnumber'  => 'IND01',
            'parentid'  => $cpl_moodle->get('id')
        ]);

        // 4. Jalankan alur fungsi sinkronisasi kompetensi lokal
        $sync_status = kompetensi_manager::sync_competencies($idprodi, $idtahun);
        $this->assertTrue($sync_status);

        // 5. Pastikan tipe level kurikulum terdeteksi otomatis dengan benar di database lokal
        $cpl_check = $DB->get_record('local_obe_kompetensi', ['competencyid' => $cpl_moodle->get('id')]);
        $this->assertEquals('CPL', $cpl_check->tipe);

        $ind_check = $DB->get_record('local_obe_kompetensi', ['competencyid' => $ind_moodle->get('id')]);
        $this->assertEquals('INDIKATOR', $ind_check->tipe);
        $this->assertEquals($cpl_check->id, $ind_check->parent_id); // Relasi parent ID lokal terikat akurat

        // 6. Uji fungsi pengurutan pohon hierarki rekursif lokal
        $terurut = kompetensi_manager::ambil_kompetensi_terurut($idprodi, $idtahun);
        $this->assertNotEmpty($terurut);
        $this->assertEquals('CPL-Lokal-01', $terurut[0]->nama_kompetensi);
        $this->assertEquals('IND-Lokal-01', $terurut[1]->nama_kompetensi);
    }
}