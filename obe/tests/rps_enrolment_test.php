<?php
namespace local_obe;
defined('MOODLE_INTERNAL') || die();
require_once('/opt/obe-support/rps_enrolment.php');
/** @covers ::rps_manual_enrol_instance */
final class rps_enrolment_test extends \advanced_testcase {
    private function course(): \stdClass {
        $this->resetAfterTest();$this->setAdminUser();set_config('enrol_plugins_enabled','manual');
        return $this->getDataGenerator()->create_course();
    }
    public function test_existing_instance_is_reused_as_object(): void {
        $course=$this->course();[$plugin,$first]=\rps_manual_enrol_instance($course);
        [$plugin,$again]=\rps_manual_enrol_instance($course);
        $this->assertInstanceOf(\stdClass::class,$again);$this->assertEquals($first->id,$again->id);$this->assertEquals($course->id,$again->courseid);
    }
    public function test_disabled_instance_is_reactivated_only_on_target_course(): void {
        $course=$this->course();[$plugin,$first]=\rps_manual_enrol_instance($course);
        $plugin->update_status($first,ENROL_INSTANCE_DISABLED);[$plugin,$active]=\rps_manual_enrol_instance($course);
        $this->assertEquals($first->id,$active->id);$this->assertEquals(ENROL_INSTANCE_ENABLED,$active->status);
    }
    public function test_missing_instance_is_created_then_resolved_before_enrol_user(): void {
        global $DB;$course=$this->course();$plugin=enrol_get_plugin('manual');
        foreach (enrol_get_instances($course->id,false) as $i) {if($i->enrol==='manual') {$plugin->delete_instance($i);}}
        [$plugin,$active]=\rps_manual_enrol_instance($course);$student=$this->getDataGenerator()->create_user();
        $plugin->enrol_user($active,$student->id,$DB->get_field('role','id',['shortname'=>'student'],MUST_EXIST));
        $this->assertTrue($DB->record_exists('user_enrolments',['enrolid'=>$active->id,'userid'=>$student->id]));
    }
    public function test_instance_from_another_course_is_never_selected(): void {
        $first=$this->course();$other=$this->getDataGenerator()->create_course();
        [$plugin,$a]=\rps_manual_enrol_instance($first);[$plugin,$b]=\rps_manual_enrol_instance($other);
        $this->assertNotEquals($a->id,$b->id);$this->assertEquals($other->id,$b->courseid);
    }
    public function test_globally_disabled_manual_plugin_is_not_enabled_by_helper(): void {
        $course=$this->course();set_config('enrol_plugins_enabled','guest');
        $this->expectException(\RuntimeException::class);\rps_manual_enrol_instance($course);
    }
}
