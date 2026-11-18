<?php
namespace local_obe;

defined('MOODLE_INTERNAL') || die();
require_once(__DIR__.'/../fixtures/ai_fixture.php');

global $CFG;

require_once($CFG->dirroot . '/local/obe/classes/manager/bobot_relasi_manager.php');

use advanced_testcase;
use local_obe\manager\bobot_relasi_manager;

class bobot_relasi_manager_test extends advanced_testcase {
    use \local_obe\tests\ai_fixture;

    /**
     * Membuat data pendukung
     */
    private function buat_data() {
        global $DB;$this->preventResetByRollback();$e=$this->environment();
        $edge=$DB->get_record('local_obe_bobot_relasi',['id_kompetensi_asal'=>$e['ids']['CPMK']],'*',MUST_EXIST);
        bobot_relasi_manager::delete_relasi($edge->id);
        return ['tahun'=>$e['year'],'cpl'=>$e['ids']['CPL'],'cpmk'=>$e['ids']['CPMK']];
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