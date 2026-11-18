<?php
namespace local_obe;
defined('MOODLE_INTERNAL') || die();
require_once(__DIR__.'/fixtures/ai_fixture.php');

/** @covers \local_obe\ai\scope */
final class ai_mod_form_test extends \advanced_testcase {
    use \local_obe\tests\ai_fixture;

    private function prepared_page($course, $cm=null): \moodle_page {
        $page=new \moodle_page();
        $page->set_url('/course/modedit.php');
        if ($cm) {$page->set_cm($cm,$course);}
        else {$page->set_course($course); $page->set_context(\context_course::instance($course->id));}
        return $page;
    }

    public function test_teacher_add_edit_form_and_save_callbacks_preserve_prepared_page(): void {
        global $CFG,$PAGE,$DB,$OUTPUT,$COURSE;
        $this->resetAfterTest(); $e=$this->environment(); $this->setUser($e['teacher']);
        require_once($CFG->dirroot.'/course/lib.php');
        require_once($CFG->dirroot.'/mod/obeinterview/mod_form.php');
        require_once($CFG->dirroot.'/mod/obeinterview/lib.php');
        $oldpage=$PAGE;$oldoutput=$OUTPUT;$oldcourse=$COURSE;
        try {
            // standard_coursemodule_elements() reads global COURSE, not only PAGE.
            $COURSE=$e['course'];
            course_create_sections_if_missing($COURSE,[1]);
            $PAGE=$this->prepared_page($e['course']);
            $OUTPUT=$PAGE->get_renderer('core');
            $context=$PAGE->context; $theme=$PAGE->theme;
            // Constructor argument 2 is course_sections.section (number), never its id or section_info.
            $section=get_fast_modinfo($COURSE)->get_section_info(1);
            $sectionnumber=(int)$section->section;
            $this->assertSame(1,$sectionnumber);
            $this->assertEquals($section->id,$DB->get_field('course_sections','id',
                ['course'=>$COURSE->id,'section'=>$sectionnumber],MUST_EXIST));
            $moduleid=$DB->get_field('modules','id',['name'=>'obeinterview'],MUST_EXIST);
            $initial=(object)['instance'=>0,'course'=>$e['course']->id,'section'=>$sectionnumber,
                'intro'=>'','introformat'=>FORMAT_HTML,
                'module'=>$moduleid,'modulename'=>'obeinterview','add'=>'obeinterview','coursemodule'=>0,
                'visible'=>1,'visibleoncoursepage'=>1,'groupmode'=>0,'groupingid'=>0];
            $form=new \mod_obeinterview_mod_form($initial,$sectionnumber,null,$e['course']);
            $this->assertSame($sectionnumber,$form->get_section());
            $this->assertSame($COURSE->id,$form->get_course()->id);
            $this->assertNull($form->get_coursemodule());
            $this->assertArrayHasKey($e['ids']['CPL'],\local_obe\ai\scope::options($e['course']->id,$form->get_context()));
            $data=(object)['course'=>$e['course']->id,'name'=>'Uji formulir sintetis','focusid'=>$e['ids']['CPL'],
                'rubricjson'=>json_encode(\local_obe\ai\rubric::defaults()),'corequestion'=>'Kasus batas apa yang Anda uji?',
                'duration'=>600,'maxfollow'=>2,'retentiondays'=>180,'projectcmid'=>0,'intro'=>'','introformat'=>FORMAT_HTML];
            $id=obeinterview_add_instance($data,$form);
            $this->assertGreaterThan(0,$id);
            $this->assertTrue($DB->record_exists('obeinterview',['id'=>$id]));
            $saved=$DB->get_record('obeinterview',['id'=>$id],'*',MUST_EXIST);
            $this->assertSame('Uji formulir sintetis',$saved->name);
            $this->assertEquals($e['teacher']->id,$saved->ownerid);
            $this->assertEquals($COURSE->id,$saved->course);
            $this->assertSame($context,$PAGE->context); $this->assertSame($theme,$PAGE->theme);
            // Edit an activity with a real course module (created by the fixture).
            $cm=get_coursemodule_from_id('obeinterview',$e['activity']->cmid,0,false,MUST_EXIST);
            $PAGE=$this->prepared_page($e['course'],$cm); $OUTPUT=$PAGE->get_renderer('core'); $context=$PAGE->context; $theme=$PAGE->theme;
            $current=$DB->get_record('obeinterview',['id'=>$cm->instance],'*',MUST_EXIST);
            $current->instance=$current->id;
            $editsection=get_fast_modinfo($e['course'])->get_section_info_by_id($cm->section);
            $current->coursemodule=$cm->id;$current->module=$moduleid;$current->modulename='obeinterview';
            $editsectionnumber=(int)$editsection->section;
            $this->assertEquals($cm->section,$editsection->id); // cm.section is the record ID.
            $current->section=$editsectionnumber;
            foreach (['visible','visibleold','visibleoncoursepage','groupmode','groupingid','completion'] as $field) {
                if (isset($cm->$field)) {$current->$field=$cm->$field;}
            }
            $edit=new \mod_obeinterview_mod_form($current,$editsectionnumber,$cm,$e['course']);
            $this->assertSame($editsectionnumber,$edit->get_section());
            $this->assertSame($cm->id,$edit->get_coursemodule()->id);
            $this->assertSame($context,$edit->get_context());
            $current->name='Nama diperbarui';
            $this->assertTrue(obeinterview_update_instance($current,$edit));
            $this->assertSame('Nama diperbarui',$DB->get_field('obeinterview','name',['id'=>$cm->instance]));
            $this->assertSame($context,$PAGE->context); $this->assertSame($theme,$PAGE->theme);
        } finally {$PAGE=$oldpage;$OUTPUT=$oldoutput;$COURSE=$oldcourse;}
    }

    public function test_student_cannot_select_focus_from_form_context(): void {
        $this->resetAfterTest(); $e=$this->environment(); $this->setUser($e['student']);
        $this->expectException(\required_capability_exception::class);
        \local_obe\ai\scope::options($e['course']->id,\context_course::instance($e['course']->id));
    }
    public function test_teacher_cannot_use_another_course_context(): void {
        $this->resetAfterTest(); $e=$this->environment(); $other=$this->getDataGenerator()->create_course();
        $this->setUser($e['teacher']); $this->expectException(\invalid_parameter_exception::class);
        \local_obe\ai\scope::options($e['course']->id,\context_course::instance($other->id));
    }
    public function test_foreign_competency_rejected_even_with_valid_form_context(): void {
        $this->resetAfterTest(); $a=$this->environment(); $b=$this->environment(); $this->setUser($a['teacher']);
        $this->expectException(\dml_missing_record_exception::class);
        \local_obe\ai\scope::focus($a['course']->id,$b['ids']['CPL'],0,\context_course::instance($a['course']->id));
    }
}
