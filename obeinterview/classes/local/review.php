<?php
namespace mod_obeinterview\local;
defined('MOODLE_INTERNAL') || die();

use local_obe\ai\interview;
use local_obe\ai\rubric;

/** Presentation and form adapter only. Decisions/Gradebook remain owned by evidence::approve(). */
final class review {
    public static function number($value): string {
        return $value===null?'—':format_float((float)$value,5,true,true);
    }
    public static function evidence_status(\stdClass $e,array $body,bool $signed): string {
        if ($signed) {return 'review_signed';}
        if ($e->state==='expired') {return 'review_expired';}
        if ($e->state==='insufficient'||!empty($body['missing'])||empty($body['quotes'])) {return 'review_insufficient';}
        return 'review_pending';
    }
    /** Normalise labelled numeric inputs, delegating score rules and rounding to the existing rubric. */
    public static function validate(array $rubric,array $input,string $reason): array {
        $errors=[];$scores=[];$known=array_column($rubric['criteria'],'id');
        if (array_diff(array_keys($input),$known)) {$errors['form']=get_string('review_unknown_score','obeinterview');}
        foreach ($rubric['criteria'] as $criterion) {
            $id=$criterion['id'];$raw=$input[$id]??'';
            if ($raw===''||$raw===null) {
                $scores[$id]=null;
                if ($criterion['required']??true) {$errors[$id]=get_string('review_score_required','obeinterview');}
            } else if (!is_scalar($raw)||!is_numeric($raw)||!is_finite((float)$raw)||(float)$raw<0||(float)$raw>100) {
                $errors[$id]=get_string('review_score_range','obeinterview');
            } else {$scores[$id]=(float)$raw;}
        }
        if (!trim($reason)||strlen($reason)>4000) {$errors['reason']=get_string('review_reason_error','obeinterview');}
        $total=$errors?null:rubric::total($rubric,$scores);
        return ['scores'=>$scores,'errors'=>$errors,'total'=>$total];
    }
    public static function page($cm,$map,int $selected=0,array $failed=[],array $options=[]): array {
        global $DB,$CFG;
        interview::activity((int)$cm->id,true);
        require_once($CFG->libdir.'/gradelib.php');
        $sessions=$DB->get_records('local_obe_ai_session',['cmid'=>$cm->id],'id DESC');$out=[];$queue=[];
        if ($options) {
            $queue=self::queue($cm,$sessions,$options,$selected);
            $sessions=array_filter($sessions,static function($s) use($queue) {return in_array((int)$s->userid,$queue['visibleusers'],true);});
        }
        foreach ($sessions as $session) {
            interview::session((int)$session->id,true); // Keep course/program/year and historical session checks.
            $student=$DB->get_record('user',['id'=>$session->userid],'id,'.\core_user\fields::for_name()->get_sql('',false,'','',false)->selects,MUST_EXIST);
            $evidence=$DB->get_records('local_obe_ai_evidence',['cmid'=>$cm->id,'userid'=>$session->userid,'sessionid'=>$session->id,'source'=>'interview'],'id DESC');
            // Decision ID order matches obeinterview_update_grades(), not evidence ID or timestamps.
            $decisions=$DB->get_records_sql('SELECT d.*, e.state AS evidence_state FROM {local_obe_ai_decision} d
                JOIN {local_obe_ai_evidence} e ON e.id=d.evidenceid
                WHERE e.cmid=:cmid AND e.userid=:userid AND e.sessionid=:sid AND e.source=:source ORDER BY d.id DESC',
                ['cmid'=>$cm->id,'userid'=>$session->userid,'sid'=>$session->id,'source'=>'interview']);
            foreach ($decisions as $decision) {
                $actor=$DB->get_record('user',['id'=>$decision->actorid],'id,'.\core_user\fields::for_name()->get_sql('',false,'','',false)->selects);
                $decision->actorname=$actor?fullname($actor):('ID '.$decision->actorid);
            }
            $grade=grade_get_grades($cm->course,'mod','obeinterview',$cm->instance,[(int)$session->userid]);
            $item=$grade->items?reset($grade->items):null;$actual=$item->grades[$session->userid]->grade??null;
            $model=self::session_model($session,$student,$evidence,$decisions,$actual,$selected,$failed);
            $model['transcript']=[];
            $model['transcripturl']=(new \moodle_url('/mod/obeinterview/view.php',['id'=>$cm->id,'open_session'=>$session->id,'page'=>$queue['page']??0,'filter'=>$options['filter']??'','q'=>$options['q']??''],'obe-transcript-'.$session->id))->out(false);
            $model['transcriptloaded']=!$options || (int)($options['open_session']??0)===(int)$session->id;
            if ($model['transcriptloaded']) {
            foreach ($DB->get_records('local_obe_ai_turn',['sessionid'=>$session->id],'id ASC') as $turn) {
                $model['transcript'][]=['id'=>$turn->id,'speaker'=>get_string($turn->speaker==='student'?'review_student_answer':'review_question','obeinterview'),
                    'body'=>$turn->body,'time'=>userdate($turn->timecreated)];
            }
            if ($session->snapshotid) {
                $project=$DB->get_record('local_obe_ai_evidence',['id'=>$session->snapshotid,'userid'=>$session->userid,'source'=>'runner']);
                if ($project) {$model['projectjson']=$project->body;}
            }
            }
            $out[]=$model;
        }
        $path=array_map(static function($c) {return ['label'=>$c->nama_kompetensi,'type'=>$c->tipe];},$map->path);
        $sub=end($map->path);
        return $queue+['hasqueue'=>(bool)$options,'sessions'=>$out,'hassessions'=>(bool)$out,'path'=>$path,'target'=>$sub->nama_kompetensi,
            'weight'=>self::number($map->bobot_aktivitas),'cmid'=>$cm->id,'sesskey'=>sesskey(),
            'action'=>(new \moodle_url('/mod/obeinterview/view.php',['id'=>$cm->id]))->out(false)];
    }
    /** Paginated active roster plus historical participants; never load hidden transcripts eagerly. */
    private static function queue($cm,array $sessions,array $options,int $selected): array {
        global $DB,$OUTPUT;
        $roster=get_enrolled_users(\context_module::instance($cm->id),'mod/obeinterview:attempt',0,'u.id,u.username,'.\core_user\fields::for_name()->get_sql('u',false,'','',false)->selects,'u.lastname ASC,u.firstname ASC',0,0,true);
        $byuser=[];foreach ($sessions as $s) {
            $byuser[$s->userid]=$s;
            if (!isset($roster[$s->userid])) {$record=$DB->get_record('user',['id'=>$s->userid],'id,username,'.\core_user\fields::for_name()->get_sql('',false,'','',false)->selects);if ($record) {$roster[$record->id]=$record;}}
        }
        $latest=[];
        foreach ($DB->get_records('local_obe_ai_evidence',['cmid'=>$cm->id,'source'=>'interview'],'id DESC','id,userid,state') as $e) {
            if (!isset($latest[$e->userid])) {$latest[$e->userid]=$e;}
        }
        $signed=$DB->get_records_sql('SELECT DISTINCT e.id FROM {local_obe_ai_evidence} e JOIN {local_obe_ai_decision} d ON d.evidenceid=e.id WHERE e.cmid=:cmid AND e.source=:source',['cmid'=>$cm->id,'source'=>'interview']);
        $filter=$options['filter']??'';$q=trim($options['q']??'');$page=max(0,(int)($options['page']??0));$perpage=10;
        $labels=[''=>'Semua peserta','notstarted'=>'Belum mulai','pending'=>'Menunggu pengesahan','insufficient'=>'Bukti belum cukup','signed'=>'Nilai disahkan','active'=>'Sedang berlangsung','unanalysed'=>'Belum dianalisis'];
        if (!array_key_exists($filter,$labels)) {throw new \invalid_parameter_exception('Filter tidak sah');}
        $target=0;
        if ($selected) {$e=$DB->get_record('local_obe_ai_evidence',['id'=>$selected,'cmid'=>$cm->id,'source'=>'interview']);$target=$e?(int)$e->userid:0;}
        foreach ($sessions as $s) {if ((int)$s->id===(int)($options['open_session']??0)) {$target=(int)$s->userid;}}
        if ($selected && $target) {$filter='';$q='';}
        $rows=[];$counts=array_fill_keys(array_keys($labels),0);
        foreach ($roster as $person) {
            $s=$byuser[$person->id]??null;$e=$latest[$person->id]??null;
            $key=!$s?'notstarted':($e?($e->state==='insufficient'?'insufficient':(isset($signed[$e->id])?'signed':($e->state==='expired'?'unanalysed':'pending'))):($s->status==='active'&&$s->deadline>time()?'active':'unanalysed'));
            $counts['']++;$counts[$key]++;
            if ($filter!==''&&$key!==$filter) {continue;}
            if ($q!==''&&\core_text::strpos(\core_text::strtolower(fullname($person).' '.$person->username),\core_text::strtolower($q))===false) {continue;}
            $rows[]=['userid'=>(int)$person->id,'name'=>fullname($person),'username'=>$person->username,'status'=>$labels[$key],'sid'=>$s->id??0,'hassession'=>(bool)$s];
        }
        usort($rows,static function($a,$b) {return strcmp($a['name'],$b['name'])?:($a['userid']<=>$b['userid']);});
        if ($target) {foreach ($rows as $i=>$row) {if ($row['userid']===$target) {$page=(int)floor($i/$perpage);}}}
        $total=count($rows);$page=min($page,max(0,(int)ceil($total/$perpage)-1));$visible=array_slice($rows,$page*$perpage,$perpage);
        $base=['id'=>$cm->id,'filter'=>$filter,'q'=>$q];$url=new \moodle_url('/mod/obeinterview/view.php',$base);
        $visibleusers=[];
        foreach ($visible as &$row) {
            $visibleusers[]=$row['userid'];$args=$base+['page'=>$page,'open_session'=>$row['sid']];
            $row['url']=(new \moodle_url('/mod/obeinterview/view.php',$args,'obe-student-'.$row['sid']))->out(false);
        }unset($row);
        $filters=[];foreach ($labels as $value=>$label) {$filters[]=['value'=>$value,'label'=>$label.' ('.$counts[$value].')','selected'=>$filter===$value];}
        return ['roster'=>$visible,'filters'=>$filters,'q'=>$q,'paging'=>$OUTPUT->paging_bar($total,$page,$perpage,$url),
            'visibleusers'=>$visibleusers,'page'=>$page,'total'=>$total,'filtered'=>$filter!==''||$q!==''];
    }
    /** Testable presentation of persisted evidence: a newer proposal never replaces a signed decision. */
    /** $student must contain all name fields from core_user\fields::for_name(), as supplied by page(). */
    public static function session_model($session,$student,array $evidence,array $decisions,$actual,int $selected=0,array $failed=[]): array {
        usort($evidence,static function($a,$b) {return $b->id<=>$a->id;});
        usort($decisions,static function($a,$b) {return $b->id<=>$a->id;});
        $current=null;$byEvidence=[];$history=[];$labels=[];
        $config=json_decode($session->configjson??'{}',true,64,JSON_THROW_ON_ERROR);
        foreach ($evidence as $item) {
            $raw=json_decode($item->body,true,64,JSON_THROW_ON_ERROR);
            foreach ($raw['rubric']['criteria']??($config['rubric']['criteria']??[]) as $criterion) {$labels[$item->id][$criterion['id']]=$criterion['label'];}
        }
        foreach ($decisions as $decision) {
            $body=json_decode($decision->body,true,64,JSON_THROW_ON_ERROR);
            if (!isset($byEvidence[$decision->evidenceid])) {$byEvidence[$decision->evidenceid]=$body;}
            if ($current===null && $decision->evidence_state==='approved') {$current=['evidenceid'=>$decision->evidenceid,'score'=>self::number($body['total']),'rawscore'=>(float)$body['total']];}
            $criteria=[];
            foreach ($body['scores']??[] as $key=>$score) {$criteria[]=['label'=>$labels[$decision->evidenceid][$key]??$key,'score'=>self::number($score)];}
            $history[]=['id'=>$decision->id,'evidenceid'=>$decision->evidenceid,'revision'=>$decision->revision,'score'=>self::number($body['total']),
                'criteria'=>$criteria,'reason'=>$body['reason'],'actorid'=>$decision->actorid,'actor'=>$decision->actorname??('ID '.$decision->actorid),'time'=>userdate($decision->timecreated)];
        }
        $status=$session->status==='active' && time()>=$session->deadline?'timedout':$session->status;
        $sessionlabels=['active'=>'review_active','closed'=>'review_finished','finished'=>'review_finished','timedout'=>'review_timedout','expired'=>'review_expired'];
        $latest=$evidence?reset($evidence):null;$models=[];
        $selected=(int)($selected?:($latest->id??0));
        foreach ($evidence as $e) {
            $body=json_decode($e->body,true,64,JSON_THROW_ON_ERROR);$validrubric=true;
            try {$r=rubric::parse(json_encode($body['rubric']??[],JSON_THROW_ON_ERROR));}
            catch (\moodle_exception | \JsonException $exception) {$validrubric=false;$r=['criteria'=>[]];}
            $missing=array_values($body['missing']??[]);
            if (!$validrubric) {$missing[]=!empty($body['expired'])?'Isi bukti telah dihapus sesuai masa simpan. Nilai dan riwayat keputusan tetap ditampilkan.':'Rubrik bukti tidak tersedia atau tidak valid. Minta pengelola memeriksa detail teknis.';}
            $signed=isset($byEvidence[$e->id]);$statuskey=self::evidence_status($e,$body,$signed);
            $decision=$byEvidence[$e->id]??null;$initial=$decision['scores']??($body['scores']??[]);
            $failedhere=(int)($failed['evidenceid']??0)===(int)$e->id;
            if ($failedhere) {$initial=$failed['scores'];}
            $errors=$failedhere?($failed['errors']??[]):[];$criteria=[];
            foreach ($r['criteria'] as $criterion) {
                $id=$criterion['id'];$proposed=$body['scores'][$id]??null;$quotes=[];
                foreach ($body['quotes']??[] as $quote) {if (($quote['criterion']??'')===$id) {$quotes[]=['text'=>$quote['text'],'turnid'=>$quote['turnid']];}}
                $criteria[]=['id'=>$id,'inputid'=>'obe-score-'.$e->id.'-'.$id,'label'=>$criterion['label'],
                    'weight'=>self::number($criterion['weight']*100),'rawweight'=>$criterion['weight'],
                    'required'=>(bool)($criterion['required']??true),'proposed'=>$proposed===null?get_string('review_no_score','obeinterview'):self::number($proposed),
                    'criterionmissing'=>array_values(array_filter($body['missing']??[],static function($message) use($criterion) {return strpos($message,$criterion['label'].':')===0;})),
                    'value'=>$initial[$id]??'','quotes'=>$quotes,'hasquotes'=>(bool)$quotes,'error'=>$errors[$id]??''];
            }
            $canapprove=$validrubric&&!in_array($e->state,['insufficient','expired'],true)&&empty($body['missing'])&&!empty($body['quotes']);
            $stale=$failedhere&&(int)$failed['revision']!==(int)$e->revision;
            $preview=null;
            try {if ($validrubric) {$preview=rubric::total($r,$initial);}} catch (\moodle_exception $exception) { /* Preserve invalid inputs for correction. */ }
            $models[]=['id'=>$e->id,'revision'=>$failedhere?$failed['revision']:$e->revision,'currentrevision'=>$e->revision,
                'legacysimulation'=>($body['model']??'')==='fixture-stok-v3','status'=>get_string($statuskey,'obeinterview'),'issigned'=>$signed,'criteria'=>$criteria,'missing'=>$missing,
                'canapprove'=>$canapprove,'stale'=>$stale,'open'=>$selected===(int)$e->id||$failedhere,'preview'=>$preview===null?get_string('review_incomplete_preview','obeinterview'):self::number($preview),
                'reason'=>$failedhere?($failed['reason']??''):'','reasonerror'=>$errors['reason']??'','formerror'=>$errors['form']??'',
                'button'=>get_string($signed?'review_correct':'review_approve','obeinterview'),
                'json'=>json_encode(['evidenceid'=>$e->id,'state'=>$e->state,'rubricversion'=>$e->rubricversion,'snapshot'=>$e->snapshot,'body'=>$body],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)];
        }
        $latestbody=$latest?json_decode($latest->body,true,64,JSON_THROW_ON_ERROR):[];
        return ['id'=>$session->id,'userid'=>$student->id,'name'=>fullname($student),'sessionstatus'=>get_string($sessionlabels[$status]??'review_finished','obeinterview'),
            'lateststatus'=>$latest?get_string(self::evidence_status($latest,$latestbody,isset($byEvidence[$latest->id])),'obeinterview'):get_string('review_unanalysed','obeinterview'),
            'latestscore'=>$latest?self::number($latest->score):'', 'haslatestscore'=>$latest && $latest->score!==null,'latestid'=>$latest->id??0,'haslatest'=>(bool)$latest,'current'=>$current,'hascurrent'=>$current!==null,
            'gradewarning'=>$current!==null&&($actual===null||abs($current['rawscore']-(float)$actual)>0.00001),'gradebook'=>self::number($actual),'hasgradebook'=>$actual!==null,'history'=>array_reverse($history),'hashistory'=>(bool)$history,
            'evidence'=>$models,'snapshot'=>$session->snapshot,'reopenreason'=>(int)($failed['sid']??0)===(int)$session->id?($failed['reason']??''):'','cananalyse'=>$status!=='expired','isactive'=>$status==='active','canreopen'=>in_array($status,['closed','finished','timedout'],true)];
    }
}
