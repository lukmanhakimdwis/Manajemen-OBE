<?php
namespace local_obe;
defined('MOODLE_INTERNAL') || die();
require_once(__DIR__.'/fixtures/ai_fixture.php');

/** @covers \local_obe\ai\form_adapter
 *  @covers \local_obe\ai\outcome
 *  @covers \local_obe\local\ux
 */
final class ai_ux_test extends \advanced_testcase {
    use \local_obe\tests\ai_fixture;
    private function fields(array $r): array {
        $data=[];foreach (\local_obe\ai\form_adapter::rows($r) as $i=>$row) {
            foreach (['criterionlabel'=>'label','criterionweight'=>'weight','criterionrequired'=>'required','criterionid'=>'id'] as $key=>$field) {$data[$key][$i]=$row[$field];}
        }
        return $data;
    }
    public function test_rubric_roundtrip_preserves_exact_existing_json_and_identifiers(): void {
        $r=\local_obe\ai\rubric::defaults();$original=json_encode($r,JSON_PRETTY_PRINT);
        $this->assertSame($original,\local_obe\ai\form_adapter::rubric_json($this->fields($r),$original));
        $r['criteria'][0]['weight']=0;$r['criteria'][1]['weight']=1;unset($r['criteria'][0]['required']);
        $original=json_encode($r);$this->assertSame($original,\local_obe\ai\form_adapter::rubric_json($this->fields($r),$original));
    }
    public function test_rubric_percent_editor_ignores_blank_rows_and_versions_changed_content(): void {
        $r=\local_obe\ai\rubric::defaults();$data=$this->fields($r);$data['criterionweight']=[90,10,''];$data['criterionlabel'][]='';
        $saved=\local_obe\ai\rubric::parse(\local_obe\ai\form_adapter::rubric_json($data,json_encode($r)));
        $this->assertStringStartsWith('stok-v1-',$saved['version']);$this->assertSame('kasusbatas',$saved['criteria'][0]['id']);$this->assertEquals(.9,$saved['criteria'][0]['weight']);
    }
    public function test_rubric_rejects_incomplete_weight_sum(): void {
        $data=$this->fields(\local_obe\ai\rubric::defaults());$data['criterionweight']=[60,30];
        $this->expectException(\invalid_parameter_exception::class);\local_obe\ai\form_adapter::rubric_json($data);
    }
    public function test_own_result_keeps_current_grade_when_new_proposal_is_pending(): void {
        $result=\local_obe\ai\outcome::summary((object)['id'=>4,'state'=>'proposed','signed'=>false],(object)['evidenceid'=>3],60,false);
        $this->assertTrue($result['hasgrade']);$this->assertSame('60',$result['grade']);$this->assertNotEmpty($result['newproposal']);
    }
    public function test_own_hidden_grade_never_returns_a_value_even_if_signed(): void {
        $result=\local_obe\ai\outcome::summary((object)['id'=>3,'state'=>'approved','signed'=>true],(object)['evidenceid'=>3],60,true);
        $this->assertFalse($result['hasgrade']);$this->assertNull($result['grade']);
    }
    public function test_signed_zero_and_null_are_distinct(): void {
        $signed=(object)['evidenceid'=>3];$e=(object)['id'=>3,'state'=>'approved','signed'=>true];
        $this->assertTrue(\local_obe\ai\outcome::summary($e,$signed,0,false)['hasgrade']);
        $this->assertFalse(\local_obe\ai\outcome::summary($e,$signed,null,false)['hasgrade']);
        $this->assertSame(99.0,\local_obe\local\ux::weight(1)['remaining']);$this->assertTrue(\local_obe\local\ux::weight(100)['complete']);
    }
    public function test_score_adapter_uses_existing_required_and_range_rules(): void {
        $r=\local_obe\ai\rubric::defaults();$scores=\local_obe\ai\form_adapter::scores($r,['kasusbatas'=>'0','assertion'=>'0']);
        $this->assertSame(0.0,\local_obe\ai\rubric::total($r,$scores));
        $this->expectException(\invalid_parameter_exception::class);\local_obe\ai\form_adapter::scores($r,['kasusbatas'=>100,'assertion'=>101]);
    }
    public function test_queue_includes_unstarted_student_and_defers_transcript(): void {
        $this->resetAfterTest();$e=$this->environment();$this->setUser($e['student']);
        \local_obe\ai\interview::start($e['activity']->cmid,true);$this->setUser($e['teacher']);
        $cm=get_coursemodule_from_id('obeinterview',$e['activity']->cmid,0,false,MUST_EXIST);
        $page=\mod_obeinterview\local\review::page($cm,\local_obe\ai\scope::mapping($cm->id),0,[],['enabled'=>true]);
        $this->assertCount(2,$page['roster']);$this->assertFalse($page['sessions'][0]['transcriptloaded']);$this->assertEmpty($page['sessions'][0]['transcript']);
        $missing=array_filter($page['roster'],static function($r) use($e) {return (int)$r['userid']===(int)$e['other']->id;});
        $this->assertFalse(reset($missing)['hassession']);
    }
    public function test_student_result_is_owned_and_cannot_read_other_students_grade(): void {
        $this->resetAfterTest();$e=$this->environment();$this->setUser($e['student']);
        $sid=\local_obe\ai\interview::start($e['activity']->cmid,true);
        \local_obe\ai\interview::answer($sid,'one','stok habis','core-1');
        \local_obe\ai\interview::answer($sid,'two','sama dengan stok nol; melebihi stok exception dan assertion','followup-1');
        $this->setUser($e['teacher']);$eid=\local_obe\ai\interview::proposal($sid);\local_obe\ai\evidence::approve($eid,1,['kasusbatas'=>60,'assertion'=>60],'Uji hasil sendiri');
        $this->setUser($e['other']);$other=\local_obe\ai\outcome::student($e['activity']->cmid);$this->assertFalse($other['hasgrade']);$this->assertNull($other['grade']);
        $this->setUser($e['student']);$own=\local_obe\ai\outcome::student($e['activity']->cmid);$this->assertSame('60',$own['grade']);
    }
    public function test_null_activity_grade_is_provisional_while_real_zero_is_a_grade(): void {
        global $CFG;
        $this->resetAfterTest();$e=$this->environment();
        $before=\local_obe\local\ux::grade_readiness($e['course']->id,$e['ids']['SUB-CPMK'],$e['student']->id);$this->assertFalse($before['complete']);
        require_once($CFG->dirroot.'/mod/obeinterview/lib.php');obeinterview_grade_item_update($e['activity'],[$e['student']->id=>(object)['userid'=>$e['student']->id,'rawgrade'=>0]]);
        $after=\local_obe\local\ux::grade_readiness($e['course']->id,$e['ids']['SUB-CPMK'],$e['student']->id);$this->assertTrue($after['complete']);
    }
    public function test_unauthorised_program_user_cannot_change_year_preference_via_guard(): void {
        global $SESSION;
        $this->resetAfterTest();$e=$this->environment();$this->setUser($e['student']);$oldget=$_GET;
        $SESSION->obe_id_tahun_akademik=$e['year'];$_GET['obe_year']=999999;
        try {\local_obe\access_guard::require_admin_or_kaprodi();$this->fail('Unauthorised program access accepted');}
        catch (\moodle_exception $exception) {$this->assertEquals($e['year'],$SESSION->obe_id_tahun_akademik);}
        finally {$_GET=$oldget;}
    }

    public function test_correcting_older_evidence_does_not_mark_an_already_signed_newer_evidence_pending(): void {
        $result=\local_obe\ai\outcome::summary((object)['id'=>4,'state'=>'approved','signed'=>true],(object)['evidenceid'=>3],60,false);
        $this->assertSame('Nilai disahkan',$result['status']);$this->assertSame('',$result['newproposal']);
    }
    public function test_readonly_teacher_can_read_reports_but_cannot_manage_mapping(): void {
        $this->resetAfterTest();$e=$this->environment();$reader=$this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($reader->id,$e['course']->id,'teacher');$this->setUser($reader);
        [$course]=\local_obe\access_guard::require_course_report($e['course']->id);$this->assertEquals($e['course']->id,$course->id);
        $this->expectException(\required_capability_exception::class);\local_obe\access_guard::require_course_manage($e['course']->id);
    }

}
