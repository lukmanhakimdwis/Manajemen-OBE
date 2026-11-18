<?php
namespace mod_obeinterview;
defined('MOODLE_INTERNAL') || die();

use mod_obeinterview\local\review;
use local_obe\ai\rubric;

/** @covers \mod_obeinterview\local\review */
final class review_test extends \advanced_testcase {
    private function evidence(int $id,string $state,array $scores): \stdClass {
        $body=['rubric'=>rubric::defaults(),'scores'=>$scores,'missing'=>[],
            'quotes'=>[['criterion'=>'kasusbatas','turnid'=>2,'text'=>'Stok habis'],['criterion'=>'assertion','turnid'=>4,'text'=>'assertion dan exception']]];
        return (object)['id'=>$id,'state'=>$state,'body'=>json_encode($body),'revision'=>1,'rubricversion'=>'stok-v1','snapshot'=>'','score'=>rubric::total(rubric::defaults(),$scores)];
    }
    private function model(array $evidence,array $decisions=[],array $failed=[]): array {
        $student=(object)['id'=>4,'firstname'=>'Mahasiswa','lastname'=>'Demo'];
        foreach (\core_user\fields::get_name_fields() as $field) {
            if (!property_exists($student,$field)) {$student->$field='';}
        }
        return review::session_model((object)['id'=>1,'status'=>'finished','deadline'=>time()+100,'snapshot'=>''],
            $student,$evidence,$decisions,60,0,$failed);
    }
    public function test_proposal_four_does_not_hide_signed_decision_three(): void {
        $old=$this->evidence(3,'approved',['kasusbatas'=>60,'assertion'=>60]);$new=$this->evidence(4,'proposed',['kasusbatas'=>80,'assertion'=>80]);
        $decision=(object)['id'=>9,'evidenceid'=>3,'evidence_state'=>'approved','revision'=>2,'actorid'=>3,'timecreated'=>time(),
            'body'=>json_encode(['total'=>60,'scores'=>['kasusbatas'=>60,'assertion'=>60],'reason'=>'Diperiksa dosen'])];
        $model=$this->model([$old,$new],[$decision]);
        $this->assertEquals(3,$model['current']['evidenceid']);$this->assertSame(review::number(60),$model['current']['score']);
        $this->assertEquals(4,$model['latestid']);$this->assertSame(get_string('review_pending','obeinterview'),$model['lateststatus']);
        $this->assertTrue($model['evidence'][0]['open']);$this->assertFalse($model['evidence'][0]['issigned']);
        $this->assertSame(80,$model['evidence'][0]['criteria'][0]['value']);
        $this->assertTrue($model['evidence'][1]['issigned']);
    }
    public function test_null_and_zero_are_not_confused(): void {
        $model=$this->model([$this->evidence(4,'insufficient',['kasusbatas'=>0,'assertion'=>null])]);
        $criteria=$model['evidence'][0]['criteria'];
        $this->assertSame(review::number(0),$criteria[0]['proposed']);$this->assertSame(0,$criteria[0]['value']);
        $this->assertSame(get_string('review_no_score','obeinterview'),$criteria[1]['proposed']);$this->assertSame('',$criteria[1]['value']);
        $this->assertFalse($model['evidence'][0]['canapprove']);
    }
    public function test_approval_status_requires_a_stored_decision(): void {
        $model=$this->model([$this->evidence(3,'approved',['kasusbatas'=>60,'assertion'=>60])]);
        $this->assertFalse($model['hascurrent']);$this->assertFalse($model['evidence'][0]['issigned']);
        $this->assertSame(get_string('review_pending','obeinterview'),$model['lateststatus']);
    }
    public function test_form_adapter_uses_existing_rubric_ranges_required_flags_and_total(): void {
        $valid=review::validate(rubric::defaults(),['kasusbatas'=>'90','assertion'=>'60'],'Kutipan diperiksa');
        $this->assertSame([],$valid['errors']);$this->assertSame(78.0,$valid['total']);
        $zero=review::validate(rubric::defaults(),['kasusbatas'=>'0','assertion'=>'0'],'Skor nol sah');$this->assertSame(0.0,$zero['total']);
        $invalid=review::validate(rubric::defaults(),['kasusbatas'=>'101','assertion'=>'','foreign'=>1],'');
        $this->assertArrayHasKey('kasusbatas',$invalid['errors']);$this->assertArrayHasKey('assertion',$invalid['errors']);
        $this->assertArrayHasKey('reason',$invalid['errors']);$this->assertArrayHasKey('form',$invalid['errors']);
    }
    public function test_failed_values_and_stale_revision_are_preserved(): void {
        $e=$this->evidence(4,'proposed',['kasusbatas'=>60,'assertion'=>60]);$e->revision=2;
        $model=$this->model([$e],[],['evidenceid'=>4,'revision'=>1,'scores'=>['kasusbatas'=>'101','assertion'=>'0'],'reason'=>'Alasan yang tetap ada','errors'=>['kasusbatas'=>'Periksa rentang']]);
        $form=$model['evidence'][0];$this->assertSame('101',$form['criteria'][0]['value']);$this->assertSame('0',$form['criteria'][1]['value']);
        $this->assertSame('Alasan yang tetap ada',$form['reason']);$this->assertSame(1,$form['revision']);$this->assertTrue($form['stale']);
    }
    public function test_retained_grade_survives_expired_evidence_body_without_a_score_form(): void {
        $e=$this->evidence(3,'approved',['kasusbatas'=>60,'assertion'=>60]);$e->body=json_encode(['expired'=>true,'score_retained'=>true]);
        $decision=(object)['id'=>9,'evidenceid'=>3,'evidence_state'=>'approved','revision'=>2,'actorid'=>3,'timecreated'=>time(),
            'body'=>json_encode(['total'=>60,'scores'=>['kasusbatas'=>60,'assertion'=>60],'reason'=>'Alasan dihapus sesuai masa simpan'])];
        $model=$this->model([$e],[$decision]);$this->assertTrue($model['hascurrent']);
        $this->assertFalse($model['evidence'][0]['canapprove']);$this->assertNotEmpty($model['evidence'][0]['missing']);
    }

    public function test_decision_order_wins_over_evidence_id_order(): void {
        $a=$this->evidence(3,'approved',['kasusbatas'=>60,'assertion'=>60]);$b=$this->evidence(4,'approved',['kasusbatas'=>80,'assertion'=>80]);
        $decision=static function($id,$eid,$total) {return (object)['id'=>$id,'evidenceid'=>$eid,'evidence_state'=>'approved','revision'=>2,'actorid'=>3,'timecreated'=>time(),
            'body'=>json_encode(['total'=>$total,'scores'=>['kasusbatas'=>$total,'assertion'=>$total],'reason'=>'Diperiksa'])];};
        $model=$this->model([$a,$b],[$decision(8,4,80),$decision(10,3,60)]);
        $this->assertEquals(3,$model['current']['evidenceid']);$this->assertEquals(4,$model['latestid']);$this->assertFalse($model['gradewarning']);
    }

    public function test_template_renders_labelled_numeric_inputs_and_escapes_quotes(): void {
        global $OUTPUT;
        $this->resetAfterTest();$this->setAdminUser();
        $e=$this->evidence(4,'proposed',['kasusbatas'=>60,'assertion'=>60]);$body=json_decode($e->body,true);
        $body['quotes'][0]['text']='<script>alert(1)</script>';$e->body=json_encode($body);
        $context=['sessions'=>[$this->model([$e])],'hassessions'=>true,'target'=>'Sub-CPMK stok','weight'=>'40','path'=>[],
            'action'=>'/mod/obeinterview/view.php?id=3','sesskey'=>'test'];
        $html=$OUTPUT->render_from_template('mod_obeinterview/review',$context);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;',$html);
        $this->assertStringNotContainsString('<script>alert(1)</script>',$html);
        $dom=new \DOMDocument();@$dom->loadHTML('<?xml encoding="utf-8" ?>'.$html);$xpath=new \DOMXPath($dom);
        $inputs=$xpath->query('//input[@type="number"]');$this->assertCount(2,$inputs);
        foreach ($inputs as $input) {$this->assertEquals(1,$xpath->query('//label[@for="'.$input->getAttribute('id').'"]')->length);}
        $this->assertEquals(0,$xpath->query('//textarea[@name="scores"]')->length);
    }

}
