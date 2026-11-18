<?php
namespace local_obe;
defined('MOODLE_INTERNAL') || die();
require_once(__DIR__.'/fixtures/ai_fixture.php');

/** @covers \local_obe\ai\interview
 *  @covers \local_obe\ai\evidence
 */
final class ai_integration_test extends \advanced_testcase {
    use \local_obe\tests\ai_fixture;
    public function test_fresh_schema_and_upgrade_from_2026111501_preserve_academic_data(): void {
        global $DB,$CFG;
        $this->resetAfterTest();$this->preventResetByRollback(); $e=$this->environment();
        $legacy=$this->getDataGenerator()->create_module('assign',['course'=>$e['course']->id,'grade'=>100,'cmidnumber'=>'OBE-UPGRADE-ASSIGN','attemptreopenmethod'=>'manual','sendstudentnotifications'=>0]);
        $map=$DB->get_record('local_obe_aktivitas_map',['cmid'=>$e['activity']->cmid],'*',MUST_EXIST);
        \local_obe\manager\aktivitasnilai_manager::update($map->id,$e['ids']['SUB-CPMK'],60,$e['course']->id);
        \local_obe\manager\aktivitasnilai_manager::insert($legacy->cmid,$e['ids']['SUB-CPMK'],40,$e['course']->id);
        require_once($CFG->dirroot.'/mod/assign/locallib.php');
        require_once($CFG->libdir.'/upgradelib.php');
        $legacycm=get_coursemodule_from_id('assign',$legacy->cmid,$e['course']->id,false,MUST_EXIST);
        $assignment=new \assign(\context_module::instance($legacycm->id),$legacycm,$e['course']);
        $this->assertTrue($assignment->save_grade($e['student']->id,(object)['grade'=>51,'attemptnumber'=>-1,'addattempt'=>0,'applytoall'=>0,'sendstudentnotifications'=>0]));
        $reportbefore=\local_obe\manager\capaian_kelas_manager::ambil_detail_kompetensi_mahasiswa($e['course']->id,$e['ids']['SUB-CPMK'],'SUB-CPMK',$e['year']);
        $before=$this->academic_snapshot();
        // Remove only empty NEW feature tables to emulate the uploaded schema at 2026111501.
        $dbman=$DB->get_manager();
        foreach (array_keys(\local_obe\ai\schema::definitions()) as $name) {$this->assertSame(0,$DB->count_records($name),'Upgrade fixture feature tables must be empty');}
        $originalupgraderunning=$CFG->upgraderunning??null;
        $originalupgradeconfig=get_config(null,'upgraderunning');
        $originalversion=get_config('local_obe','version');
        try {
        foreach (array_reverse(array_keys(\local_obe\ai\schema::definitions())) as $name) {$dbman->drop_table(new \xmldb_table($name));}
        set_config('version',2026111501,'local_obe');
        require_once($CFG->dirroot.'/local/obe/db/upgrade.php');
        $this->assertTrue(xmldb_local_obe_upgrade(2026111501));
        // Savepoints enter upgrade mode; restore normal runtime before invoking reports.
        if ($originalupgraderunning===null) {unset($CFG->upgraderunning);} else {$CFG->upgraderunning=$originalupgraderunning;}
        $this->assertSame($before,$this->academic_snapshot());
        $this->assertEquals($reportbefore,\local_obe\manager\capaian_kelas_manager::ambil_detail_kompetensi_mahasiswa($e['course']->id,$e['ids']['SUB-CPMK'],'SUB-CPMK',$e['year']));
        foreach (array_keys(\local_obe\ai\schema::definitions()) as $name) {$this->assertTrue($dbman->table_exists(new \xmldb_table($name)));}
        $this->assertTrue(xmldb_local_obe_upgrade(2026111502));
        } finally {
            if ($originalupgradeconfig===false) {unset_config('upgraderunning');} else {set_config('upgraderunning',$originalupgradeconfig);}
            if ($originalupgraderunning===null) {unset($CFG->upgraderunning);} else {$CFG->upgraderunning=$originalupgraderunning;}
            // Restore feature schema even if a migration assertion fails, for later test groups.
            \local_obe\ai\schema::install();
            if ($originalversion===false) {unset_config('version','local_obe');} else {set_config('version',$originalversion,'local_obe');}
        }
    }
    public function test_incomplete_interview_never_writes_a_grade(): void {
        global $CFG;
        $this->resetAfterTest();$e=$this->environment();$this->setUser($e['student']);
        $sid=\local_obe\ai\interview::start($e['activity']->cmid,true);
        \local_obe\ai\interview::answer($sid,'one','stok habis');
        $this->setUser($e['teacher']);$id=\local_obe\ai\interview::proposal($sid);
        global $DB;$this->assertSame('insufficient',$DB->get_field('local_obe_ai_evidence','state',['id'=>$id]));
        require_once($CFG->libdir.'/gradelib.php');
        $r=grade_get_grades($e['course']->id,'mod','obeinterview',$e['activity']->id,[$e['student']->id]); $item=reset($r->items);
        $this->assertNull($item->grades[$e['student']->id]->grade);
        $this->expectException(\invalid_parameter_exception::class);
        \local_obe\ai\evidence::approve($id,1,['kasusbatas'=>90,'assertion'=>90],'Belum cukup');
    }
    public function test_approval_correction_and_legacy_grade_get_grades_path(): void {
        global $DB,$CFG;
        $this->resetAfterTest();$e=$this->environment();$this->setUser($e['student']);
        $sid=\local_obe\ai\interview::start($e['activity']->cmid,true);
        \local_obe\ai\interview::answer($sid,'one','stok habis');
        \local_obe\ai\interview::answer($sid,'two','Jumlah sama dengan stok menghasilkan nol; melebihi stok exception. Assertion memeriksa keduanya.');
        \local_obe\ai\interview::close($sid);$this->setUser($e['teacher']);
        $id=\local_obe\ai\interview::proposal($sid);
        $result=\local_obe\ai\evidence::approve($id,1,['kasusbatas'=>90,'assertion'=>60],'Kutipan dan rubrik diperiksa');
        $this->assertEquals(78,$result['grade_get_grades']);
        // Existing report manager reads exactly this grade path. No alternate OBE engine is used.
        $report=\local_obe\manager\capaian_kelas_manager::ambil_detail_kompetensi_mahasiswa($e['course']->id,$e['ids']['SUB-CPMK'],'SUB-CPMK',$e['year']);
        $own=array_values(array_filter($report->detail_mahasiswa,static function($row) use($e) {return (int)$row->userid===(int)$e['student']->id;}));
        $this->assertCount(1,$own); $this->assertEquals(78,$own[0]->nilai); $this->assertEquals(78,$own[0]->rincian_list[0]->skor);
        $this->assertTrue(\local_obe\manager\skala_nilai_manager::is_kompeten(78,'SUB-CPMK',$e['prodi'],$e['year']));
        $corrected=\local_obe\ai\evidence::approve($id,2,['kasusbatas'=>90,'assertion'=>80],'Koreksi setelah pemeriksaan kutipan');
        $this->assertEquals(86,$corrected['grade_get_grades']);
        $this->assertEquals(2,$DB->count_records('local_obe_ai_decision',['evidenceid'=>$id]));
        $this->expectException(\invalid_parameter_exception::class);
        \local_obe\ai\evidence::approve($id,2,['kasusbatas'=>100,'assertion'=>100],'Revisi kedaluwarsa');
    }
    public function test_resume_duplicates_and_cross_student_denial(): void {
        global $DB;
        $this->resetAfterTest();$e=$this->environment();$this->setUser($e['student']);
        $sid=\local_obe\ai\interview::start($e['activity']->cmid,true);
        \local_obe\ai\interview::answer($sid,'same','stok habis'); \local_obe\ai\interview::answer($sid,'same','stok habis');
        $this->assertEquals(3,$DB->count_records('local_obe_ai_turn',['sessionid'=>$sid]));
        $this->assertSame($sid,\local_obe\ai\interview::start($e['activity']->cmid,true));
        $this->setUser($e['other']);$this->expectException(\moodle_exception::class);\local_obe\ai\interview::read($sid);
    }
    public function test_cross_class_and_framework_selection_are_rejected(): void {
        $this->resetAfterTest();$a=$this->environment();$b=$this->environment();$this->setUser($a['teacher']);
        $this->expectException(\moodle_exception::class);
        \local_obe\ai\scope::competency($a['course']->id,$b['ids']['CPL'],true);
    }
    public function test_question_bank_publication_is_idempotent(): void {
        global $DB;
        $this->resetAfterTest();$e=$this->environment();$this->setUser($e['teacher']);
        $id=\local_obe\ai\drafts::create($e['course']->id,$e['ids']['SUB-CPMK'],'RPS sintetis yang disahkan',true);
        $qid=\local_obe\ai\drafts::publish($id);$this->assertGreaterThan(0,$qid);
        $this->assertSame($qid,\local_obe\ai\drafts::publish($id));$this->assertTrue($DB->record_exists('question',['id'=>$qid]));
    }
    public function test_mapping_validation_and_grade_semantics_are_unchanged(): void {
        $this->resetAfterTest();$e=$this->environment();
        $this->expectException(\moodle_exception::class);
        \local_obe\manager\aktivitasnilai_manager::insert($e['activity']->cmid,$e['ids']['SUB-CPMK'],10,$e['course']->id);
    }
}
