<?php
namespace local_obe;
defined('MOODLE_INTERNAL') || die();
require_once(__DIR__.'/fixtures/ai_fixture.php');

/** @covers \local_obe\ai\scope
 *  @covers \local_obe\ai\interview
 */
final class ai_mapping_test extends \advanced_testcase {
    use \local_obe\tests\ai_fixture;

    public function test_student_reads_real_cmid_mapping_after_theme_without_manage_permission(): void {
        global $PAGE;
        $this->resetAfterTest();$this->setAdminUser();
        // Deliberately separate course-module IDs from obeinterview instance IDs.
        $othercourse=$this->getDataGenerator()->create_course();
        $this->getDataGenerator()->create_module('assign',['course'=>$othercourse->id]);
        $e=$this->environment();$this->setUser($e['student']);
        $cm=get_coursemodule_from_id('obeinterview',$e['activity']->cmid,0,false,MUST_EXIST);
        $this->assertNotEquals($cm->id,$cm->instance);
        $this->assertFalse(has_capability('local/obe:managecourse',\context_course::instance($cm->course)));
        $oldpage=$PAGE;
        try {
            $PAGE=new \moodle_page();$PAGE->set_cm($cm,$e['course']);$PAGE->set_url('/mod/obeinterview/view.php',['id'=>$cm->id]);
            $PAGE->initialise_theme_and_output();$theme=$PAGE->theme;$context=$PAGE->context;
            $map=\local_obe\ai\scope::mapping((int)$cm->id);
            $this->assertEquals($cm->id,$map->cmid);$this->assertEquals($e['ids']['SUB-CPMK'],$map->sub_cpmk_id);
            $this->assertCount(4,$map->path);$this->assertSame($theme,$PAGE->theme);$this->assertSame($context,$PAGE->context);
        } finally {$PAGE=$oldpage;}
        $sid=\local_obe\ai\interview::start((int)$cm->id,true);
        $this->assertGreaterThan(0,$sid);$read=\local_obe\ai\interview::read($sid);
        $this->assertEquals($cm->id,$read['session']->cmid);
    }
    public function test_missing_mapping_is_a_specific_failure_and_cannot_start(): void {
        global $DB;
        $this->resetAfterTest();$e=$this->environment();
        $this->setAdminUser();
        $unmapped=$this->getDataGenerator()->create_module('obeinterview',['course'=>$e['course']->id,'focusid'=>$e['ids']['CPL']]);
        $this->setUser($e['student']);
        try {\local_obe\ai\scope::mapping($unmapped->cmid);$this->fail('Unmapped activity accepted');}
        catch (\local_obe\ai\mapping_missing $exception) {$this->assertSame('aimappingmissing',$exception->errorcode);}
        $this->assertFalse($DB->record_exists('local_obe_ai_session',['cmid'=>$unmapped->cmid]));
        $this->expectException(\local_obe\ai\mapping_missing::class);
        \local_obe\ai\interview::start($unmapped->cmid,true);
    }
    public function test_outsider_denied_before_mapping_or_start(): void {
        $this->resetAfterTest();$e=$this->environment();$this->setUser($this->getDataGenerator()->create_user());
        $this->expectException(\moodle_exception::class);
        \local_obe\ai\scope::mapping($e['activity']->cmid);
    }
    public function test_other_students_transcript_remains_private(): void {
        $this->resetAfterTest();$e=$this->environment();$this->setUser($e['student']);
        $sid=\local_obe\ai\interview::start($e['activity']->cmid,true);
        $this->setUser($e['other']);$this->expectException(\moodle_exception::class);
        \local_obe\ai\interview::read($sid);
    }
}
