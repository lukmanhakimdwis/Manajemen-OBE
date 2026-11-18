<?php
namespace local_obe\ai;
defined('MOODLE_INTERNAL') || die();

/** Narrow adapter for individual online-text assignments with simple numeric grading. */
final class assignment {
    public static function source(int $cmid,int $userid): array {
        global $DB,$CFG;
        $cm=get_coursemodule_from_id('assign',$cmid,0,false,MUST_EXIST); scope::course((int)$cm->course,true,\context_module::instance($cmid));
        $ctx=\context_module::instance($cmid); require_capability('mod/assign:grade',$ctx);
        require_capability('local/obe:useai',\context_course::instance($cm->course)); scope::mapping($cmid);
        if (!is_enrolled(\context_course::instance($cm->course),$userid,'',true)) {throw new \moodle_exception('nopermissions','error');}
        require_once($CFG->dirroot.'/mod/assign/locallib.php');
        $assign=new \assign($ctx,$cm,get_course($cm->course)); $instance=$assign->get_instance();
        $area=$DB->get_record('grading_areas',['contextid'=>$ctx->id,'component'=>'mod_assign','areaname'=>'submissions']);
        if ($instance->teamsubmission||$instance->blindmarking||$instance->markingworkflow||$instance->grade<=0||($area&&!empty($area->activemethod))) {
            throw new \invalid_parameter_exception('Pilot esai hanya mendukung individual online text, nilai angka, tanpa blind marking, workflow atau advanced grading');
        }
        $s=$assign->get_user_submission($userid,false);
        if (!$s||$s->status!=='submitted') {throw new \invalid_parameter_exception('Esai belum dikumpulkan');}
        $text=$DB->get_record('assignsubmission_onlinetext',['submission'=>$s->id,'assignment'=>$instance->id],'*',MUST_EXIST);
        $plain=html_to_text($text->onlinetext,0,false);
        return [$assign,$instance,$s,$plain,hash('sha256',$text->onlinetext),$cm];
    }
    public static function analyze(int $cmid,int $userid,string $rubricjson): int {
        [$assign,$instance,$s,$plain,$hash]=self::source($cmid,$userid); $rubric=rubric::parse($rubricjson);
        $out=agent::run('analyze',['turns'=>[['id'=>(int)$s->id,'speaker'=>'student','body'=>$plain]],'rubric'=>$rubric,'snapshot'=>$hash]);
        foreach ($out['quotes']??[] as $q) {
            if ((int)$q['turnid']!==(int)$s->id||$q['text']===''||strpos($plain,$q['text'])===false) {throw new \invalid_parameter_exception('Kutipan esai tidak valid');}
        }
        $out['rubric']=$rubric; $out['source_kind']='assignsubmission_onlinetext'; $out['submissionid']=(int)$s->id;
        $out['attemptnumber']=(int)$s->attemptnumber; $out['source_hash']=$hash;
        if (empty($out['quotes'])) {$out['missing'][]='Kutipan belum tersedia';}
        return evidence::propose($cmid,$userid,'a-'.hash('sha256',json_encode([$s->id,$hash,$rubric['version'],$out['model']])),'essay',$rubric['version'],$hash,$out);
    }
    public static function approve(\stdClass $e,int $revision,array $scores,string $reason): array {
        global $DB,$USER,$CFG;
        [$assign,$instance,$s,$plain,$hash,$cm]=self::source((int)$e->cmid,(int)$e->userid);
        require_once($CFG->libdir.'/gradelib.php'); require_capability('local/obe:approveai',\context_course::instance($cm->course));
        $lock=\core\lock\lock_config::get_lock_factory('local_obe_ai')->get_lock('approve:'.$cm->id.':'.$e->userid,10);
        if (!$lock) {throw new \moodle_exception('locktimeout');}
        try {
            $e=$DB->get_record('local_obe_ai_evidence',['id'=>$e->id],'*',MUST_EXIST); $body=json_decode($e->body,true,64,JSON_THROW_ON_ERROR);
            if ((int)$e->revision!==$revision||$e->state==='expired'||!empty($body['missing'])||empty($body['quotes'])||!trim($reason)) {throw new \invalid_parameter_exception('Revisi, kecukupan bukti atau alasan tidak valid');}
            if ($body['submissionid']!=(int)$s->id||!hash_equals($body['source_hash'],$hash)) {throw new \invalid_parameter_exception('Esai telah berubah; analisis ulang kiriman terkini');}
            $total=rubric::total($body['rubric'],$scores); if ($total===null) {throw new \invalid_parameter_exception('Skor belum lengkap');}
            $item=\grade_item::fetch(['courseid'=>$cm->course,'itemtype'=>'mod','itemmodule'=>'assign','iteminstance'=>$instance->id,'itemnumber'=>0]);
            $grade=$item?\grade_grade::fetch(['itemid'=>$item->id,'userid'=>$e->userid]):false;
            if (($item&&$item->is_locked())||($grade&&($grade->is_locked()||$grade->is_overridden()))) {throw new \invalid_parameter_exception('Gradebook terkunci/di-override');}
            $tx=$DB->start_delegated_transaction(); $before=['score'=>$e->score,'state'=>$e->state,'revision'=>$e->revision];
            $e->score=$total; $e->state='approved'; $e->revision++; $e->approvedby=$USER->id; $e->timemodified=time(); $DB->update_record('local_obe_ai_evidence',$e);
            $DB->insert_record('local_obe_ai_decision',(object)['evidenceid'=>$e->id,'actorid'=>$USER->id,'revision'=>$e->revision,
                'body'=>json_encode(['reason'=>$reason,'before'=>$before,'scores'=>$scores,'percent'=>$total,'source_hash'=>$hash,'model'=>$body['model']??'unknown']), 'timecreated'=>time()]);
            $rawgrade=$total*$instance->grade/100;
            $data=(object)['grade'=>$rawgrade,'attemptnumber'=>$s->attemptnumber,'addattempt'=>0,'applytoall'=>0,'sendstudentnotifications'=>0];
            if (!$assign->save_grade($e->userid,$data)) {throw new \moodle_exception('API Assignment menolak nilai');}
            $read=grade_get_grades($cm->course,'mod','assign',$instance->id,[$e->userid]); $first=$read->items?reset($read->items):null;
            $seen=$first->grades[$e->userid]->grade??null;
            if ($seen===null||abs((float)$seen-$rawgrade)>0.00001) {throw new \moodle_exception('Verifikasi nilai Assignment gagal');}
            $tx->allow_commit(); return ['grade'=>$rawgrade,'grade_get_grades'=>(float)$seen,'revision'=>$e->revision];
        } finally {$lock->release();}
    }
}
