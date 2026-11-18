<?php
namespace local_obe;
defined('MOODLE_INTERNAL') || die();
require_once(__DIR__.'/fixtures/ai_fixture.php');
use local_obe\local\assessment_integrity as integrity;
use local_obe\manager\aktivitasnilai_manager as maps;

/** @covers \local_obe\local\assessment_integrity */
final class assessment_integrity_test extends \advanced_testcase {
    use \local_obe\tests\ai_fixture;
    public function test_delete_empty_40_releases_exactly_40_and_retry_insert_is_safe(): void {
        global $DB;$this->resetAfterTest();$this->preventResetByRollback();$e=$this->environment();$this->setUser($e['teacher']);
        $id=$DB->get_field('local_obe_aktivitas_map','id',['cmid'=>$e['activity']->cmid]);
        maps::update($id,$e['ids']['SUB-CPMK'],60,$e['course']->id);
        $a=$this->getDataGenerator()->create_module('obeinterview',['course'=>$e['course']->id,'focusid'=>$e['ids']['CPL']]);
        $b=$this->getDataGenerator()->create_module('obeinterview',['course'=>$e['course']->id,'focusid'=>$e['ids']['CPL']]);
        $empty=maps::insert($a->cmid,$e['ids']['SUB-CPMK'],40,$e['course']->id);
        $this->assertEquals(100,maps::get_total_bobot_usage($e['course']->id)[$e['ids']['SUB-CPMK']]);
        maps::delete($empty);$this->assertEquals(60,maps::get_total_bobot_usage($e['course']->id)[$e['ids']['SUB-CPMK']]);
        $next=maps::insert($b->cmid,$e['ids']['SUB-CPMK'],40,$e['course']->id);
        $this->assertSame($next,maps::insert($b->cmid,$e['ids']['SUB-CPMK'],40,$e['course']->id));
        $this->assertEquals(100,maps::get_total_bobot_usage($e['course']->id)[$e['ids']['SUB-CPMK']]);
    }
    public function test_zero_grade_prevents_delete_and_weight_change(): void {
        global $DB,$CFG;$this->resetAfterTest();$this->preventResetByRollback();$e=$this->environment();$this->setUser($e['teacher']);require_once($CFG->libdir.'/gradelib.php');
        grade_update('test',$e['course']->id,'mod','obeinterview',$e['activity']->id,0,(object)['userid'=>$e['student']->id,'rawgrade'=>0]);
        $this->assertTrue(integrity::used($e['activity']->cmid));
        $id=$DB->get_field('local_obe_aktivitas_map','id',['cmid'=>$e['activity']->cmid]);
        try {maps::delete($id);$this->fail('Zero grade was deleted');} catch (\moodle_exception $exception) {$this->assertTrue($DB->record_exists('local_obe_aktivitas_map',['id'=>$id]));}
        $this->expectException(\moodle_exception::class);maps::update($id,$e['ids']['SUB-CPMK'],20,$e['course']->id);
    }
    public function test_replacement_archives_grade_and_mapping_without_duplicate_weight(): void {
        global $DB,$CFG;$this->resetAfterTest();$this->preventResetByRollback();$e=$this->environment();$this->setUser($e['teacher']);require_once($CFG->libdir.'/gradelib.php');
        grade_update('test',$e['course']->id,'mod','obeinterview',$e['activity']->id,0,(object)['userid'=>$e['student']->id,'rawgrade'=>60]);
        $old=$DB->get_record('local_obe_aktivitas_map',['cmid'=>$e['activity']->cmid]);$before=$DB->get_records('grade_grades');
        $a=$this->getDataGenerator()->create_module('obeinterview',['course'=>$e['course']->id,'focusid'=>$e['ids']['CPL']]);
        set_config('replacementpolicy','Hanya data sintetis: revisi capaian aktif diizinkan, item Gradebook lama ditinjau pengelola.','local_obe');
        $hash=maps::fingerprint($old);$id=maps::replace($old->id,$a->cmid,$e['course']->id,'Kebijakan kelas uji',$hash,true);
        $this->assertSame($id,maps::replace($old->id,$a->cmid,$e['course']->id,'Ulang',$hash,true));
        $this->assertEquals(100,maps::get_total_bobot_usage($e['course']->id)[$e['ids']['SUB-CPMK']]);
        foreach ($before as $g) {$this->assertEquals($g->finalgrade,$DB->get_field('grade_grades','finalgrade',['id'=>$g->id]));}
        $this->assertTrue($DB->record_exists('local_obe_audit_log',['entity'=>'activity_map_archive','entityid'=>$old->id]));
    }
    public function test_course_delete_callback_protects_interview_history_before_destruction(): void {
        global $DB;$this->resetAfterTest();$this->preventResetByRollback();$e=$this->environment();$this->setUser($e['student']);
        \local_obe\ai\interview::start($e['activity']->cmid,true);$this->setUser($e['teacher']);
        $cm=$DB->get_record('course_modules',['id'=>$e['activity']->cmid]);
        $this->expectException(\moodle_exception::class);integrity::before_module_delete($cm);
    }
    public function test_pending_deletion_still_owns_capacity_and_report_map(): void {
        global $DB;$this->resetAfterTest();$this->preventResetByRollback();$e=$this->environment();
        $DB->set_field('course_modules','deletioninprogress',1,['id'=>$e['activity']->cmid]);
        $this->assertCount(1,integrity::maps($e['course']->id));
        $this->assertEquals(100,maps::get_total_bobot_usage($e['course']->id)[$e['ids']['SUB-CPMK']]);
    }
    public function test_other_course_cannot_borrow_capacity_or_replace_activity(): void {
        global $DB;$this->resetAfterTest();$this->preventResetByRollback();$e=$this->environment();$this->setUser($e['teacher']);
        $other=$this->getDataGenerator()->create_course();$a=$this->getDataGenerator()->create_module('assign',['course'=>$other->id]);
        $old=$DB->get_record('local_obe_aktivitas_map',['cmid'=>$e['activity']->cmid]);
        $this->expectException(\moodle_exception::class);maps::replace($old->id,$a->cmid,$e['course']->id,'Uji',maps::fingerprint($old),true);
    }
    public function test_second_writer_cannot_spend_capacity_used_by_first_writer(): void {
        global $DB;$this->resetAfterTest();$this->preventResetByRollback();$e=$this->environment();$this->setUser($e['teacher']);
        $id=$DB->get_field('local_obe_aktivitas_map','id',['cmid'=>$e['activity']->cmid]);maps::update($id,$e['ids']['SUB-CPMK'],60,$e['course']->id);
        $a=$this->getDataGenerator()->create_module('obeinterview',['course'=>$e['course']->id,'focusid'=>$e['ids']['CPL']]);
        $b=$this->getDataGenerator()->create_module('obeinterview',['course'=>$e['course']->id,'focusid'=>$e['ids']['CPL']]);
        maps::insert($a->cmid,$e['ids']['SUB-CPMK'],40,$e['course']->id);
        $this->expectException(\moodle_exception::class);maps::insert($b->cmid,$e['ids']['SUB-CPMK'],40,$e['course']->id);
    }
    public function test_simulation_selects_per_criterion_and_does_not_quote_unrelated_turn(): void {
        $r=\local_obe\ai\stock_simulation::analyze(['rubric'=>\local_obe\ai\rubric::defaults(),'turns'=>[
          ['id'=>12,'speaker'=>'student','body'=>'Blackbox dan whitebox tanpa kasus stok.'],
          ['id'=>13,'speaker'=>'student','body'=>'Sama dengan stok dibanding pembelian melebihi stok.']]]);
        $this->assertSame(['kasusbatas'=>60,'assertion'=>null],$r['scores']);$this->assertCount(1,$r['quotes']);$this->assertSame(13,$r['quotes'][0]['turnid']);
        $this->assertSame(0.0,\local_obe\ai\rubric::total(\local_obe\ai\rubric::defaults(),['kasusbatas'=>0,'assertion'=>0]));
    }
    public function test_real_core_delete_of_empty_assignment_releases_capacity_only_after_success(): void {
        global $DB,$CFG;$this->resetAfterTest();$this->preventResetByRollback();$e=$this->environment();$this->setUser($e['teacher']);
        require_once($CFG->dirroot.'/course/lib.php');
        $id=$DB->get_field('local_obe_aktivitas_map','id',['cmid'=>$e['activity']->cmid]);maps::update($id,$e['ids']['SUB-CPMK'],60,$e['course']->id);
        $a=$this->getDataGenerator()->create_module('assign',['course'=>$e['course']->id]);
        $mapid=maps::insert($a->cmid,$e['ids']['SUB-CPMK'],40,$e['course']->id);
        course_delete_module($a->cmid,false);
        $this->assertFalse($DB->record_exists('course_modules',['id'=>$a->cmid]));
        $this->assertEquals(60,maps::get_total_bobot_usage($e['course']->id)[$e['ids']['SUB-CPMK']]);
        $this->assertTrue($DB->record_exists('local_obe_audit_log',['entity'=>'core_delete_requested_map','entityid'=>$mapid]));
    }
    public function test_used_relation_cannot_be_deleted_and_student_cannot_replace_mapping(): void {
        global $DB;$this->resetAfterTest();$this->preventResetByRollback();$e=$this->environment();
        global $CFG;require_once($CFG->libdir.'/gradelib.php');
        grade_update('test',$e['course']->id,'mod','obeinterview',$e['activity']->id,0,(object)['userid'=>$e['student']->id,'rawgrade'=>0]);
        $r=$DB->get_record('local_obe_bobot_relasi',['id_kompetensi_asal'=>$e['ids']['SUB-CPMK']]);
        try {\local_obe\manager\bobot_relasi_manager::delete_relasi($r->id);$this->fail('Mapped edge deleted');}
        catch (\moodle_exception $exception) {$this->assertTrue($DB->record_exists('local_obe_bobot_relasi',['id'=>$r->id]));}
        $map=$DB->get_record('local_obe_aktivitas_map',['cmid'=>$e['activity']->cmid]);$this->setUser($e['student']);
        $this->expectException(\required_capability_exception::class);
        maps::replace($map->id,$e['activity']->cmid,$e['course']->id,'Uji',maps::fingerprint($map),true);
    }

    public function test_empty_competency_edge_releases_sibling_weight_without_changing_activity_weight(): void {
        global $DB;$this->resetAfterTest();$this->preventResetByRollback();$e=$this->environment();
        $this->setAdminUser();
        \local_obe\manager\bobot_relasi_manager::save_bobot($e['ids']['SUB-CPMK'],60,$e['year']);
        $parent=$DB->get_record('local_obe_kompetensi',['id'=>$e['ids']['CPMK']]);
        $cg=$this->getDataGenerator()->get_plugin_generator('core_competency');
        $cg->create_competency(['competencyframeworkid'=>$parent->frameworkid,'parentid'=>$parent->competencyid,'shortname'=>'Sub kosong','idnumber'=>'EMPTY-TEST']);
        \local_obe\manager\kompetensi_manager::sync_competencies($e['prodi'],$e['year']);
        $nodes=$DB->get_records('local_obe_kompetensi',['parent_id'=>$parent->id]);
        foreach ($nodes as $node) {if ((int)$node->id!==$e['ids']['SUB-CPMK']) {$empty=$node;}}
        \local_obe\manager\bobot_relasi_manager::save_bobot($empty->id,40,$e['year']);
        $totals=\local_obe\manager\bobot_relasi_manager::get_total_bobot_terisi_by_parent($e['prodi'],$e['year']);$this->assertEquals(100,$totals[$parent->id]);
        $edge=$DB->get_record('local_obe_bobot_relasi',['id_kompetensi_asal'=>$empty->id]);
        \local_obe\manager\bobot_relasi_manager::delete_relasi($edge->id);
        $totals=\local_obe\manager\bobot_relasi_manager::get_total_bobot_terisi_by_parent($e['prodi'],$e['year']);$this->assertEquals(60,$totals[$parent->id]);
        $this->assertEquals(100,maps::get_total_bobot_usage($e['course']->id)[$e['ids']['SUB-CPMK']]);
        $this->assertEquals(60,integrity::relation_weight($parent->id,$e['ids']['SUB-CPMK'],$e['year']));
    }

    public function test_used_replacement_requires_policy_even_when_teacher_acknowledges(): void {
        global $DB,$CFG;$this->resetAfterTest();$this->preventResetByRollback();
        $e=$this->environment();$this->setUser($e['teacher']);require_once($CFG->libdir.'/gradelib.php');
        grade_update('test',$e['course']->id,'mod','obeinterview',$e['activity']->id,0,(object)['userid'=>$e['student']->id,'rawgrade'=>0]);
        unset_config('replacementpolicy','local_obe');
        $old=$DB->get_record('local_obe_aktivitas_map',['cmid'=>$e['activity']->cmid]);
        $a=$this->getDataGenerator()->create_module('obeinterview',['course'=>$e['course']->id,'focusid'=>$e['ids']['CPL']]);
        try {maps::replace($old->id,$a->cmid,$e['course']->id,'Dosen sudah mencentang',maps::fingerprint($old),true);$this->fail('Missing policy accepted');}
        catch (\moodle_exception $exception) {
            $this->assertTrue($DB->record_exists('local_obe_aktivitas_map',['id'=>$old->id]));
            $this->assertFalse($DB->record_exists('local_obe_aktivitas_map',['cmid'=>$a->cmid]));
            $this->assertFalse($DB->record_exists('local_obe_audit_log',['entity'=>'activity_map_archive','entityid'=>$old->id]));
        }
        $hash=maps::fingerprint($old);
        set_config('replacementpolicy','Kebijakan uji yang baru disetujui pengelola.','local_obe');
        $this->assertNotSame($hash,maps::fingerprint($old));
        $this->expectException(\moodle_exception::class);
        maps::replace($old->id,$a->cmid,$e['course']->id,'Pratinjau basi',$hash,true);
    }

    public function test_plugin_and_core_deletion_preserve_approved_decision(): void {
        global $DB,$CFG;$this->resetAfterTest();$this->preventResetByRollback();$e=$this->environment();
        $this->setUser($e['student']);$sid=\local_obe\ai\interview::start($e['activity']->cmid,true);
        \local_obe\ai\interview::answer($sid,'integrity-approved','Pembelian sama dengan stok menghasilkan nol; melebihi stok exception. Assertion stok tersisa 0.');
        \local_obe\ai\interview::close($sid);$this->setUser($e['teacher']);
        $eid=\local_obe\ai\interview::proposal($sid);
        \local_obe\ai\evidence::approve($eid,1,['kasusbatas'=>60,'assertion'=>60],'Pengesahan sintetis khusus tes perlindungan.');
        $before=$this->academic_snapshot();$decision=$DB->get_records('local_obe_ai_decision',['evidenceid'=>$eid]);
        $map=$DB->get_record('local_obe_aktivitas_map',['cmid'=>$e['activity']->cmid]);
        try {maps::delete($map->id);$this->fail('Approved map deleted');}catch(\moodle_exception $exception) {}
        require_once($CFG->dirroot.'/course/lib.php');
        try {course_delete_module($e['activity']->cmid,false);$this->fail('Approved activity deleted');}catch(\moodle_exception $exception) {}
        $this->assertSame($before,$this->academic_snapshot());
        $this->assertEquals($decision,$DB->get_records('local_obe_ai_decision',['evidenceid'=>$eid]));
        $this->assertTrue($DB->record_exists('local_obe_ai_evidence',['id'=>$eid,'state'=>'approved']));
    }

}
