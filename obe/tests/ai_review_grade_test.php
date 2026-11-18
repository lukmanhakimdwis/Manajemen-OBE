<?php
namespace local_obe;
defined('MOODLE_INTERNAL') || die();
require_once(__DIR__.'/fixtures/ai_fixture.php');

/** @covers \mod_obeinterview\local\review */
final class ai_review_grade_test extends \advanced_testcase {
    use \local_obe\tests\ai_fixture;
    public function test_review_does_not_change_90_and_60_weighted_report_78_with_a_newer_proposal(): void {
        global $DB,$CFG;
        $this->resetAfterTest();$e=$this->environment();
        $assign=$this->getDataGenerator()->create_module('assign',[
            'course'=>$e['course']->id,'grade'=>100,'cmidnumber'=>'OBE-REVIEW-ASSIGN',
            'attemptreopenmethod'=>'manual','sendstudentnotifications'=>0,
        ]);
        $map=$DB->get_record('local_obe_aktivitas_map',['cmid'=>$e['activity']->cmid],'*',MUST_EXIST);
        \local_obe\manager\aktivitasnilai_manager::update($map->id,$e['ids']['SUB-CPMK'],40,$e['course']->id);
        \local_obe\manager\aktivitasnilai_manager::insert($assign->cmid,$e['ids']['SUB-CPMK'],60,$e['course']->id);
        require_once($CFG->dirroot.'/mod/assign/locallib.php');
        require_once($CFG->libdir.'/gradelib.php');
        $assigncm=get_coursemodule_from_id('assign',$assign->cmid,$e['course']->id,false,MUST_EXIST);
        $this->assertSame('OBE-REVIEW-ASSIGN',$assigncm->idnumber);
        // The native grading API supplies cmidnumber from the real course module.
        // A generator's returned assign-table record is not the grade-item callback payload.
        $assignment=new \assign(\context_module::instance($assigncm->id),$assigncm,$e['course']);
        $this->setUser($e['teacher']);
        $this->assertTrue($assignment->save_grade($e['student']->id,(object)[
            'grade'=>90,'attemptnumber'=>-1,'addattempt'=>0,'applytoall'=>0,'sendstudentnotifications'=>0,
        ]));
        $this->setUser($e['student']);$sid=\local_obe\ai\interview::start($e['activity']->cmid,true);
        \local_obe\ai\interview::answer($sid,'one','stok habis','core-1');
        \local_obe\ai\interview::answer($sid,'two','sama dengan stok nol, melebihi stok exception, assertion keduanya','followup-1');
        \local_obe\ai\interview::close($sid);$this->setUser($e['teacher']);
        $eid=\local_obe\ai\interview::proposal($sid);
        \local_obe\ai\evidence::approve($eid,1,['kasusbatas'=>60,'assertion'=>60],'Nilai fixture untuk uji tampilan');
        $old=$DB->get_record('local_obe_ai_evidence',['id'=>$eid],'*',MUST_EXIST);$body=json_decode($old->body,true);
        $body['scores']=['kasusbatas'=>80,'assertion'=>80];
        $newid=\local_obe\ai\evidence::propose($old->cmid,$old->userid,'new-review-test','interview',$old->rubricversion,$old->snapshot,$body,$sid);
        $readgrade=static function(string $modname,int $instance) use($e): float {
            $grades=grade_get_grades($e['course']->id,'mod',$modname,$instance,[$e['student']->id]);
            $item=reset($grades->items);
            return (float)$item->grades[$e['student']->id]->grade;
        };
        $this->assertEquals(90,$readgrade('assign',(int)$assign->id));
        $this->assertEquals(60,$readgrade('obeinterview',(int)$e['activity']->id));
        $before=$this->academic_snapshot();$decisions=$DB->count_records('local_obe_ai_decision');
        $cm=get_coursemodule_from_id('obeinterview',$e['activity']->cmid,0,false,MUST_EXIST);
        $view=\mod_obeinterview\local\review::page($cm,\local_obe\ai\scope::mapping($cm->id));
        $this->assertSame($before,$this->academic_snapshot());$this->assertEquals($decisions,$DB->count_records('local_obe_ai_decision'));
        $this->assertEquals($eid,$view['sessions'][0]['current']['evidenceid']);$this->assertEquals($newid,$view['sessions'][0]['latestid']);
        $report=\local_obe\manager\capaian_kelas_manager::ambil_detail_kompetensi_mahasiswa($cm->course,$e['ids']['SUB-CPMK'],'SUB-CPMK',$e['year']);
        $student=array_values(array_filter($report->detail_mahasiswa,static function($row) use($e) {return $row->userid==$e['student']->id;}))[0];
        $this->assertEquals(78,$student->total_nilai_obe);
        $details=[];foreach ($student->rincian_list as $row) {$details[$row->modulename]=$row;}
        $this->assertEquals(90,$details['assign']->skor);$this->assertEquals(100,$details['assign']->max);$this->assertEquals(54,$details['assign']->nilai_terbobot);
        $this->assertEquals(60,$details['obeinterview']->skor);$this->assertEquals(24,$details['obeinterview']->nilai_terbobot);
        $this->assertEquals(90,$readgrade('assign',(int)$assign->id));
        $this->assertEquals(60,$readgrade('obeinterview',(int)$e['activity']->id));
    }
    public function test_student_cannot_open_teacher_review(): void {
        $this->resetAfterTest();$e=$this->environment();$this->setUser($e['student']);
        $cm=get_coursemodule_from_id('obeinterview',$e['activity']->cmid,0,false,MUST_EXIST);
        $map=\local_obe\ai\scope::mapping($cm->id);
        $this->expectException(\required_capability_exception::class);\mod_obeinterview\local\review::page($cm,$map);
    }
    public function test_student_cannot_submit_approval_even_without_the_teacher_form(): void {
        global $DB;
        $this->resetAfterTest();$e=$this->environment();$this->setUser($e['student']);
        $sid=\local_obe\ai\interview::start($e['activity']->cmid,true);
        \local_obe\ai\interview::answer($sid,'one','stok habis','core-1');$this->setUser($e['teacher']);
        $eid=\local_obe\ai\interview::proposal($sid);$this->setUser($e['student']);
        try {\local_obe\ai\evidence::approve($eid,1,['kasusbatas'=>100,'assertion'=>100],'Bukan dosen');$this->fail('Student approval accepted');}
        catch (\required_capability_exception $exception) {$this->assertEquals(0,$DB->count_records('local_obe_ai_decision'));}
    }

}
