<?php
namespace local_obe;

defined('MOODLE_INTERNAL') || die();
require_once(__DIR__.'/../fixtures/ai_fixture.php');

global $CFG;
require_once($CFG->dirroot . '/local/obe/classes/manager/aktivitasnilai_manager.php');

use advanced_testcase;
use stdClass;
use local_obe\manager\aktivitasnilai_manager;

class aktivitasnilai_manager_test extends advanced_testcase {
    use \local_obe\tests\ai_fixture;

    /**
     * Helper untuk mempersiapkan lingkungan data dasar kurikulum OBE.
     */
    private function buat_lingkungan_kurikulum() {
        global $DB;$this->preventResetByRollback();$e=$this->environment('SUB-1');
        $id=$DB->get_field('local_obe_aktivitas_map','id',['cmid'=>$e['activity']->cmid],MUST_EXIST);
        aktivitasnilai_manager::delete($id);
        $assign=$this->getDataGenerator()->create_module('assign',['course'=>$e['course']->id]);
        return ['prodi'=>$e['prodi'],'tahun'=>$e['year'],'subcpmk'=>$e['ids']['SUB-CPMK'],'courseid'=>$e['course']->id,'cmid'=>$assign->cmid];
    }

    /**
     * TC-AN-01: Menguji Alur Operasi CRUD Pemetaan Aktivitas secara Sukses
     */
    public function test_operasional_crud_pemetaan() {
        $this->resetAfterTest(true);
        $this->setAdminUser();
        global $DB;

        // Meredam output deprecated dynamic properties bawaan core Moodle



        $env = $this->buat_lingkungan_kurikulum();

        // 1. Uji INSERT (Create)
        $mapid = aktivitasnilai_manager::insert($env['cmid'], $env['subcpmk'], 40.00, $env['courseid']);
        $this->assertGreaterThan(0, $mapid);

        // Validasi database
        $record = $DB->get_record('local_obe_aktivitas_map', ['id' => $mapid]);
        $this->assertEquals(40.00, $record->bobot_aktivitas);

        // 2. Uji READ (Ambil Semua & Ambil Satu)
        $semua = aktivitasnilai_manager::ambil_semua_pemetaan($env['courseid']);
        $this->assertNotEmpty($semua);
        $matched=array_values(array_filter($semua,static fn($row)=>(int)$row->cmid===(int)$env['cmid']));
        $this->assertCount(1,$matched);
        $this->assertEquals('SUB-1', $matched[0]->nama_sub_cpmk);

        $satu = aktivitasnilai_manager::ambil_satu_pemetaan($mapid);
        $this->assertEquals($env['cmid'], $satu->cmid);

        // 3. Uji UPDATE
        $update_status = aktivitasnilai_manager::update($mapid, $env['subcpmk'], 60.00, $env['courseid']);
        $this->assertTrue($update_status);
        $this->assertEquals(60.00, $DB->get_field('local_obe_aktivitas_map', 'bobot_aktivitas', ['id' => $mapid]));

        // 4. Uji DELETE
        $delete_status = aktivitasnilai_manager::delete($mapid);
        $this->assertTrue($delete_status);
        $this->assertFalse($DB->record_exists('local_obe_aktivitas_map', ['id' => $mapid]));
    }

    /**
     * TC-AN-02: Menguji Jalur Validasi Batas Nilai Bobot (Negatif & Melebihi 100)
     */
    public function test_validasi_rentang_bobot() {
        $this->resetAfterTest(true);
        $this->setAdminUser();
        
        // Meredam output deprecated dynamic properties bawaan core Moodle

        
        $env = $this->buat_lingkungan_kurikulum();

        // Skenario A: Menguji bobot di atas 100% (Harus melempar exception)
        try {
            aktivitasnilai_manager::insert($env['cmid'], $env['subcpmk'], 150.00, $env['courseid']);
            $this->fail('Moodle exception diharapkan karena bobot di atas 100%');
        } catch (\moodle_exception $e) {
            $this->assertStringContainsString('Bobot aktivitas harus berada pada rentang 0 sampai 100.', $e->getMessage());
        }

        // Skenario B: Menguji bobot bernilai negatif (Harus melempar exception)
        try {
            aktivitasnilai_manager::insert($env['cmid'], $env['subcpmk'], -10.00, $env['courseid']);
            $this->fail('Moodle exception diharapkan karena bobot bernilai negatif');
        } catch (\moodle_exception $e) {
            $this->assertTrue(true);
        }
    }

    /**
     * TC-AN-03: Menguji Validasi Server-Side Aturan Akumulasi Total Bobot Maksimal 100%
     */
    public function test_validasi_akumulasi_total_bobot_maksimal() {
        $this->resetAfterTest(true);
        $this->setAdminUser();
        global $DB;
        
        // Meredam output deprecated dynamic properties bawaan core Moodle

        
        $env = $this->buat_lingkungan_kurikulum();

        // Buat modul tambahan di course yang sama untuk membagi komponen bobot
        $assign2 = $this->getDataGenerator()->create_module('assign', ['course' => $env['courseid']]);

        // Simpan komponen pemetaan pertama sebesar 60%
        aktivitasnilai_manager::insert($env['cmid'], $env['subcpmk'], 60.00, $env['courseid']);

        // Coba masukkan komponen pemetaan kedua sebesar 50% (Total menjadi 110%, harus ditolak)
        try {
            aktivitasnilai_manager::insert($assign2->cmid, $env['subcpmk'], 50.00, $env['courseid']);
            $this->fail('Moodle exception diharapkan karena akumulasi total bobot melebihi 100%');
        } catch (\moodle_exception $e) {
            $this->assertStringContainsString('Total bobot aktivitas untuk Sub-CPMK ini melebihi 100%.', $e->getMessage());
        }
    }

    /**
     * TC-AN-04: Menguji Proteksi Validasi Duplikasi Aktivitas Moodle
     */
    public function test_validasi_duplikasi_aktivitas() {
        $this->resetAfterTest(true);
        $this->setAdminUser();
        global $DB;
        
        // Meredam output deprecated dynamic properties bawaan core Moodle

        
        $env = $this->buat_lingkungan_kurikulum();

        // Masukkan pemetaan pertama secara valid
        aktivitasnilai_manager::insert($env['cmid'], $env['subcpmk'], 30.00, $env['courseid']);

        // Coba petakan ulang cmid yang SAMA ke kompetensi lain (Harus ditolak karena 1 cmid hanya boleh punya 1 map)
        try {
            aktivitasnilai_manager::insert($env['cmid'], $env['subcpmk'], 20.00, $env['courseid']);
            $this->fail('Moodle exception diharapkan karena aktivitas sudah terpetakan');
        } catch (\moodle_exception $e) {
            $this->assertStringContainsString('Aktivitas Moodle ini sudah memiliki pemetaan.', $e->getMessage());
        }
    }
}