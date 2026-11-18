<?php
namespace local_obe;
defined('MOODLE_INTERNAL') || die();
require_once(__DIR__.'/fixtures/ai_fixture.php');

/** @covers \local_obe\ai\interview
 *  @covers \local_obe\ai\agent
 */
final class ai_chat_test extends \advanced_testcase {
    use \local_obe\tests\ai_fixture;
    public function test_question_identity_deduplicates_retries_but_allows_same_text_for_next_question(): void {
        global $DB;
        $this->resetAfterTest();$e=$this->environment();$this->setUser($e['student']);
        $sid=\local_obe\ai\interview::start($e['activity']->cmid,true);
        $first=\local_obe\ai\interview::answer($sid,'req1','stok habis','core-1');
        \local_obe\ai\interview::answer($sid,'req1','stok habis','core-1');
        \local_obe\ai\interview::answer($sid,'other-tab-request','stok habis','core-1');
        $this->assertEquals(3,$DB->count_records('local_obe_ai_turn',['sessionid'=>$sid]));
        $second=\local_obe\ai\interview::answer($sid,'req2','stok habis',$first['questionkey']);
        $this->assertEquals(2,$DB->count_records('local_obe_ai_turn',['sessionid'=>$sid,'speaker'=>'student']));
        $questions=array_column(array_filter($second['turns'],static function($t) {return $t->speaker==='assistant';}),'body');
        $this->assertCount(count($questions),array_unique($questions));
        $end=\local_obe\ai\interview::answer($sid,'req3','stok habis',$second['questionkey']);
        $this->assertSame('finished',$end['session']->status);$this->assertFalse($end['cananswer']);$this->assertFalse($end['pending']);
        $count=count($end['turns']);
        $retry=\local_obe\ai\interview::answer($sid,'req3','stok habis',$second['questionkey']);
        $this->assertCount($count,$retry['turns']);
    }
    public function test_exhausted_simulation_finishes_even_with_large_followup_budget(): void {
        global $DB;
        $this->resetAfterTest();$e=$this->environment();$DB->set_field('obeinterview','maxfollow',5,['id'=>$e['activity']->id]);$this->setUser($e['student']);
        $sid=\local_obe\ai\interview::start($e['activity']->cmid,true);$state=\local_obe\ai\interview::read($sid);
        for ($i=1;$i<=3;$i++) {$state=\local_obe\ai\interview::answer($sid,'req'.$i,'jawaban sama',$state['questionkey']);}
        $this->assertSame('finished',$state['session']->status);$this->assertFalse($state['cananswer']);
    }
    public function test_pending_response_can_resume_without_a_second_student_turn(): void {
        global $DB;
        $this->resetAfterTest();$e=$this->environment();$this->setUser($e['student']);
        $sid=\local_obe\ai\interview::start($e['activity']->cmid,true);
        set_config('aiworkerroot','/nonexistent-obe-test-root','local_obe');
        $pending=\local_obe\ai\interview::answer($sid,'req1','stok habis','core-1');
        $this->assertTrue($pending['pending']);$this->assertFalse($pending['cananswer']);
        unset_config('aiworkerroot','local_obe');
        $resumed=\local_obe\ai\interview::answer($sid,'new-tab-id','stok habis','core-1');
        $this->assertFalse($resumed['pending']);$this->assertTrue($resumed['cananswer']);
        $this->assertEquals(1,$DB->count_records('local_obe_ai_turn',['sessionid'=>$sid,'speaker'=>'student']));
    }
    public function test_retry_of_retired_question_after_teacher_reopen_does_not_append_turns(): void {
        global $DB;
        $this->resetAfterTest();$e=$this->environment();$this->setUser($e['student']);
        $sid=\local_obe\ai\interview::start($e['activity']->cmid,true);
        set_config('aiworkerroot','/nonexistent-obe-test-root','local_obe');
        \local_obe\ai\interview::answer($sid,'old-request','stok habis','core-1');
        unset_config('aiworkerroot','local_obe');
        \local_obe\ai\interview::close($sid);$this->setUser($e['teacher']);
        \local_obe\ai\interview::reopen($sid,'Bukti tambahan sintetis');
        $before=$DB->count_records('local_obe_ai_turn',['sessionid'=>$sid]);$this->setUser($e['student']);
        $state=\local_obe\ai\interview::answer($sid,'old-request','stok habis','core-1');
        $this->assertEquals($before,$DB->count_records('local_obe_ai_turn',['sessionid'=>$sid]));
        $this->assertTrue($state['cananswer']);$this->assertSame('core-reopen-1',$state['questionkey']);
    }
    public function test_manual_close_persists_is_idempotent_and_teacher_can_review(): void {
        global $DB;
        $this->resetAfterTest();$e=$this->environment();$this->setUser($e['student']);
        $sid=\local_obe\ai\interview::start($e['activity']->cmid,true);
        \local_obe\ai\interview::answer($sid,'one','stok habis','core-1');
        \local_obe\ai\interview::close($sid);\local_obe\ai\interview::close($sid);
        $state=\local_obe\ai\interview::read($sid);$this->assertSame('closed',$state['session']->status);$this->assertFalse($state['cananswer']);
        $this->setUser($e['teacher']);$review=\local_obe\ai\interview::read($sid,true);
        $this->assertCount(3,$review['turns']);$this->assertSame('closed',$review['session']->status);
        $this->assertFalse($DB->record_exists('local_obe_ai_decision',[]));
    }
    public function test_read_and_close_do_not_reset_a_prepared_theme(): void {
        global $PAGE;
        $this->resetAfterTest();$e=$this->environment();$this->setUser($e['student']);
        $sid=\local_obe\ai\interview::start($e['activity']->cmid,true);
        $oldpage=$PAGE;
        try {
            $cm=get_coursemodule_from_id('obeinterview',$e['activity']->cmid,0,false,MUST_EXIST);
            $PAGE=new \moodle_page();$PAGE->set_cm($cm,$e['course']);$PAGE->set_url('/mod/obeinterview/view.php',['id'=>$cm->id]);
            $PAGE->initialise_theme_and_output();$theme=$PAGE->theme;$context=$PAGE->context;
            \local_obe\ai\interview::read($sid);\local_obe\ai\interview::close($sid);
            $closed=\local_obe\ai\interview::read($sid);
            $this->assertSame('closed',$closed['session']->status);
            $this->assertSame($theme,$PAGE->theme);$this->assertSame($context,$PAGE->context);
        } finally {$PAGE=$oldpage;}
    }
    public function test_deadline_is_persisted_as_timedout_not_retention_expired(): void {
        global $DB;
        $this->resetAfterTest();$e=$this->environment();$this->setUser($e['student']);
        $sid=\local_obe\ai\interview::start($e['activity']->cmid,true);
        $DB->set_field('local_obe_ai_session','deadline',time()-1,['id'=>$sid]);
        $state=\local_obe\ai\interview::read($sid);$this->assertSame('timedout',$state['session']->status);
        $this->assertFalse($state['cananswer']);$this->assertFalse($state['pending']);
        \local_obe\ai\interview::close($sid);$this->assertSame('timedout',$DB->get_field('local_obe_ai_session','status',['id'=>$sid]));
    }
}
