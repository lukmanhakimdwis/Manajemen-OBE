<?php
namespace local_obe\ai;
defined('MOODLE_INTERNAL') || die();

/** Durable, idempotent sessions in Moodle DB, reusing prototype protocol without a second academic registry. */
final class interview {
    public static function activity(int $cmid,bool $grading=false): array {
        global $DB;
        $cm=get_coursemodule_from_id('obeinterview',$cmid,0,false,MUST_EXIST);
        $course=get_course($cm->course); $ctx=\context_module::instance($cmid);
        // HTTP entrypoints authenticate once. Repeated read/close/stream checks must not reset PAGE.
        scope::course((int)$course->id,$grading,$ctx);
        $visible=get_fast_modinfo($course)->get_cm($cmid);
        if (!$visible->uservisible || !empty($visible->deletioninprogress)) {throw new \moodle_exception('nopermissions','error');}
        require_capability('mod/obeinterview:view',$ctx);
        if ($grading) {require_capability('mod/obeinterview:grade',$ctx); require_capability('local/obe:approveai',\context_course::instance($cm->course));}
        else {require_capability('mod/obeinterview:attempt',$ctx);}
        $a=$DB->get_record('obeinterview',['id'=>$cm->instance],'*',MUST_EXIST);
        return [$cm,$a,$ctx];
    }
    public static function session(int $sid,bool $grading=false): \stdClass {
        global $DB,$USER;
        $s=$DB->get_record('local_obe_ai_session',['id'=>$sid],'*',MUST_EXIST);
        [$cm,$a]=self::activity((int)$s->cmid,$grading);
        if (!$grading && (int)$s->userid!==(int)$USER->id) {throw new \moodle_exception('nopermissions','error');}
        $config=json_decode($s->configjson,true,64,JSON_THROW_ON_ERROR); $map=scope::mapping((int)$s->cmid);
        $context=\context_module::instance((int)$cm->id);
        $r=scope::course((int)$cm->course,$grading,$context);
        if ((int)$map->sub_cpmk_id!==(int)$config['subid'] || (int)$r->id_tahun_akademik!==(int)$config['yearid'] || (int)$r->id_prodi!==(int)$config['prodiid'] || (int)$r->frameworkid!==(int)$config['frameworkid']) {
            throw new \invalid_parameter_exception('Relasi akademik berubah; tinjau sesi historis sebelum melanjutkan');
        }
        scope::focus((int)$cm->course,(int)$config['focusid'],(int)$map->sub_cpmk_id,$context);
        return $s;
    }
    private static function lock(string $key) {
        $l=\core\lock\lock_config::get_lock_factory('local_obe_ai')->get_lock($key,10);
        if (!$l) {throw new \moodle_exception('locktimeout');} return $l;
    }
    private static function turn($sid,string $request,string $speaker,string $body,string $question,int $competency,string $provider,string $model): int {
        global $DB;
        return (int)$DB->insert_record('local_obe_ai_turn',(object)['sessionid'=>$sid,'requestid'=>$request,'speaker'=>$speaker,
            'body'=>$body,'questionkey'=>$question,'competencyid'=>$competency,'provider'=>$provider,'model'=>$model,'timecreated'=>time()]);
    }
    public static function start(int $cmid,bool $consent,int $snapshotid=0): int {
        global $DB,$USER;
        [$cm,$a]=self::activity($cmid); if (!$consent) {throw new \invalid_parameter_exception('Persetujuan penyimpanan percakapan wajib');}
        $context=\context_module::instance($cmid);
        $map=scope::mapping($cmid); scope::focus((int)$cm->course,(int)$a->focusid,(int)$map->sub_cpmk_id,$context);
        $r=scope::course((int)$cm->course,false,$context); $snapshot='';
        if ($a->projectcmid) {
            $source=$DB->get_record('local_obe_ai_evidence',['id'=>$snapshotid,'cmid'=>$a->projectcmid,'userid'=>$USER->id,'source'=>'runner'],'*',MUST_EXIST);
            if (!preg_match('/^[a-f0-9]{40,64}$/',$source->snapshot) || $source->state==='expired') {throw new \invalid_parameter_exception('Snapshot proyek tidak tersedia');}
            $snapshot=$source->snapshot;
        } else {$snapshotid=0;}
        $lock=self::lock('start:'.$cmid.':'.$USER->id);
        try {
            $old=$DB->get_record('local_obe_ai_session',['cmid'=>$cmid,'userid'=>$USER->id]);
            if ($old) {return (int)$old->id;}
            $config=['focusid'=>(int)$a->focusid,'rubric'=>rubric::parse($a->rubricjson),'corequestion'=>$a->corequestion,
                'duration'=>(int)$a->duration,'maxfollow'=>(int)$a->maxfollow,'retentiondays'=>(int)$a->retentiondays,
                'ownerid'=>(int)$a->ownerid,'mappingid'=>(int)$map->id,'subid'=>(int)$map->sub_cpmk_id,
                'prodiid'=>(int)$r->id_prodi,'yearid'=>(int)$r->id_tahun_akademik,'frameworkid'=>(int)$r->frameworkid];
            $tx=$DB->start_delegated_transaction();
            $sid=$DB->insert_record('local_obe_ai_session',(object)['cmid'=>$cmid,'userid'=>$USER->id,
                'configjson'=>json_encode($config,JSON_THROW_ON_ERROR),'rubricversion'=>$config['rubric']['version'],
                'snapshot'=>$snapshot,'snapshotid'=>$snapshotid,'deadline'=>time()+$a->duration,'status'=>'active','timecreated'=>time(),'timemodified'=>time()]);
            self::turn($sid,'start','assistant',$a->corequestion,'core-1',(int)$a->focusid,'core','teacher-core-v1');
            $tx->allow_commit(); return (int)$sid;
        } finally {$lock->release();}
    }
    public static function read(int $sid,bool $grading=false): array {
        global $DB;
        $s=self::session($sid,$grading);
        // Conditional update also works while answer() holds the session lock. Never overwrite a close/reopen.
        $DB->execute("UPDATE {local_obe_ai_session} SET status=:ended, timemodified=:modified
            WHERE id=:id AND status=:active AND deadline<=:deadline",
            ['ended'=>'timedout','modified'=>time(),'id'=>$sid,'active'=>'active','deadline'=>time()]);
        $s=self::session($sid,$grading);
        $turns=array_values($DB->get_records('local_obe_ai_turn',['sessionid'=>$sid],'id ASC'));
        $last=$turns?end($turns):null; $remaining=max(0,$s->deadline-time());
        $pending=$s->status==='active' && $last && $last->speaker==='student';
        $cananswer=$s->status==='active' && $remaining>0 && $last && $last->speaker==='assistant';
        return ['session'=>$s,'turns'=>$turns,'remaining'=>$remaining,
            'label'=>'SIMULASI — belum ada inferensi resmi','pending'=>(bool)$pending,
            'cananswer'=>(bool)$cananswer,'questionkey'=>$cananswer?$last->questionkey:null]+($grading?[]:['assessment'=>outcome::student((int)$s->cmid)]);
    }
    private static function pending(int $sid): bool {
        global $DB;
        $last=$DB->get_records('local_obe_ai_turn',['sessionid'=>$sid],'id DESC','*',0,1);
        $row=$last?reset($last):null;
        $s=$DB->get_record('local_obe_ai_session',['id'=>$sid],'status',MUST_EXIST);
        return $row && $row->speaker==='student' && $s->status==='active';
    }
    public static function answer(int $sid,string $request,string $body,string $questionkey=''): array {
        global $DB;
        $s=self::session($sid); $config=json_decode($s->configjson,true,64,JSON_THROW_ON_ERROR);
        if (!preg_match('/^[a-zA-Z0-9_-]{1,80}$/',$request) || !trim($body) || strlen($body)>20000) {throw new \invalid_parameter_exception('ID atau isi jawaban tidak valid');}
        $lock=self::lock('session:'.$sid);
        try {
            $s=self::session($sid);
            $old=$DB->get_record('local_obe_ai_turn',['sessionid'=>$sid,'requestid'=>$request,'speaker'=>'student']);
            if (!$old && $questionkey!=='') {
                // One answer per question even across tabs/new request IDs; NOT deduplication by text.
                $old=$DB->get_record('local_obe_ai_turn',['sessionid'=>$sid,'questionkey'=>$questionkey,'speaker'=>'student']);
            }
            if ($old) {
                if ($old->body!==$body || ($questionkey!=='' && $old->questionkey!==$questionkey)) {
                    throw new \invalid_parameter_exception('Pertanyaan ini sudah memiliki jawaban tersimpan');
                }
                $request=$old->requestid;
                // A later explicit question (including teacher reopen) retires this answered question.
                if ($DB->record_exists_select('local_obe_ai_turn','sessionid=:sid AND speaker=:speaker AND id>:afterid',
                        ['sid'=>$sid,'speaker'=>'assistant','afterid'=>$old->id])) {return self::read($sid);}
            } else {
                if ($s->status!=='active'||time()>=$s->deadline) {return self::read($sid);}
                if (self::pending($sid)) {throw new \invalid_parameter_exception('Lanjutkan pengiriman jawaban sebelumnya dengan ID sama');}
                $count=$DB->count_records('local_obe_ai_turn',['sessionid'=>$sid,'speaker'=>'student']);
                if ($count>=$config['maxfollow']+1) {
                    self::finish($sid,'finished');return self::read($sid);
                }
                $last=$DB->get_records('local_obe_ai_turn',['sessionid'=>$sid,'speaker'=>'assistant'],'id DESC','*',0,1); $question=reset($last);
                if (!$question || ($questionkey!=='' && $questionkey!==$question->questionkey)) {
                    throw new \invalid_parameter_exception('Pertanyaan bukan giliran aktif; lanjutkan sesi dari server');
                }
                // Persist before generation; failed responses can be retried with the SAME question/request.
                self::turn($sid,$request,'student',$body,$question->questionkey,(int)$config['focusid'],'student','none');
            }
            if ($DB->record_exists('local_obe_ai_turn',['sessionid'=>$sid,'requestid'=>$request,'speaker'=>'assistant']) || $s->status!=='active') {return self::read($sid);}
            $count=$DB->count_records('local_obe_ai_turn',['sessionid'=>$sid,'speaker'=>'student']);
            if ($count>$config['maxfollow']) {self::finish($sid,'finished');return self::read($sid);}
            if (time()>=$s->deadline) {return self::read($sid);}
            try {
                $questions=$DB->get_records('local_obe_ai_turn',['sessionid'=>$sid,'speaker'=>'assistant'],'id ASC');
                $asked=array_values(array_map(static function($q) {return trim($q->body);},$questions));
                $reply=agent::run('followup',['answer'=>$body,'asked_questions'=>$asked,'focusid'=>$config['focusid'],'rubric'=>$config['rubric'],
                    'snapshot'=>$s->snapshot,'ownerid'=>$config['ownerid']]);
                if (!empty($reply['complete'])) {self::finish($sid,'finished');return self::read($sid);}
                if (empty($reply['text']) || strlen($reply['text'])>10000) {throw new \invalid_parameter_exception('Respons agen tidak valid');}
                // Defensive gate for providers unaware of history: never append a repeated question.
                if (in_array(trim($reply['text']),$asked,true)) {self::finish($sid,'finished');return self::read($sid);}
                if (time()>=$s->deadline) {return self::read($sid);}
                self::turn($sid,$request,'assistant',$reply['text'],'followup-'.$count,(int)$config['focusid'],$reply['provider'],$reply['model']);
            } catch (\Throwable $e) {
                return self::read($sid); // Durable student turn remains pending, not a second question.
            }
            $DB->set_field('local_obe_ai_session','timemodified',time(),['id'=>$sid]); return self::read($sid);
        } finally {$lock->release();}
    }
    private static function finish(int $sid,string $status): void {
        global $DB;
        $DB->execute("UPDATE {local_obe_ai_session} SET status=:ended, timemodified=:modified WHERE id=:id AND status=:active",
            ['ended'=>$status,'modified'=>time(),'id'=>$sid,'active'=>'active']);
    }
    public static function close(int $sid): void {
        self::session($sid); $lock=self::lock('session:'.$sid);
        try {
            $fresh=self::session($sid);
            // Idempotent: do not turn a finished/timedout/retention-expired session back into 'closed'.
            if ($fresh->status==='active') {self::finish($sid,time()>=$fresh->deadline?'timedout':'closed');}
        } finally {$lock->release();}
    }
    public static function reopen(int $sid,string $reason): void {
        global $DB,$USER; $s=self::session($sid,true);
        if ($s->status==='expired') {throw new \invalid_parameter_exception('Isi sesi sudah melewati masa simpan');}
        if (!trim($reason)) {throw new \invalid_parameter_exception('Alasan wajib');}
        $lock=self::lock('session:'.$sid);
        try {
            $s=self::session($sid,true);
            if ($s->status==='expired') {throw new \invalid_parameter_exception('Isi sesi sudah melewati masa simpan');}
            $config=json_decode($s->configjson,true); $tx=$DB->start_delegated_transaction();
            $count=$DB->count_records('local_obe_ai_turn',['sessionid'=>$sid,'speaker'=>'student']);
            $last=$DB->get_records('local_obe_ai_turn',['sessionid'=>$sid],'id DESC','*',0,1); $last=$last?reset($last):null;
            $config['maxfollow']=max($config['maxfollow'],$count);
            if ($last && $last->speaker==='student') {
                self::turn($sid,'reopen-'.bin2hex(random_bytes(8)),'assistant',$config['corequestion'],'core-reopen-'.$count,(int)$config['focusid'],'core','teacher-core-v1');
            }
            $config['reopenhistory'][]=['actor'=>(int)$USER->id,'time'=>time(),'reason'=>$reason];
            $s->configjson=json_encode($config,JSON_THROW_ON_ERROR);
            $s->status='active'; $s->deadline=time()+$config['duration']; $s->timemodified=time(); $DB->update_record('local_obe_ai_session',$s);
            // Preserve turns and rubric. Only a new explicit question extends an exhausted follow-up budget.
            \local_obe\access_guard::audit('ai_reopen','ai_session',$sid,['actor'=>$USER->id,'reasonsha256'=>hash('sha256',$reason)]);
            $tx->allow_commit();
        } finally {$lock->release();}
    }
    public static function proposal(int $sid): int {
        global $DB;
        self::session($sid,true); $lock=self::lock('session:'.$sid);
        try {
        $s=self::session($sid,true);
        if ($s->status==='expired') {throw new \invalid_parameter_exception('Isi sesi sudah melewati masa simpan');}
        $config=json_decode($s->configjson,true,64,JSON_THROW_ON_ERROR);
        $turns=array_map(static function($t) {return (array)$t;},array_values($DB->get_records('local_obe_ai_turn',['sessionid'=>$sid],'id ASC')));
        $out=agent::run('analyze',['turns'=>$turns,'rubric'=>$config['rubric'],'snapshot'=>$s->snapshot,'ownerid'=>$config['ownerid']]);
        $out['rubric']=$config['rubric']; $out['sessionid']=$sid; $out['snapshot']=$s->snapshot;
        $out['turnids']=array_column($turns,'id'); $out['focusid']=$config['focusid'];
        $byid=[]; foreach ($turns as $turn) {$byid[$turn['id']]=$turn;}
        $criteriaids=array_column($config['rubric']['criteria'],'id');$covered=[];
        foreach ($out['quotes']??[] as $q) {
            if (!in_array($q['criterion']??'',$criteriaids,true)) {throw new \invalid_parameter_exception('Kutipan memakai kriteria yang tidak dikenal');}
            $covered[$q['criterion']]=true;
            if (!isset($byid[$q['turnid']]) || $byid[$q['turnid']]['speaker']!=='student' || $q['text']==='' || strpos($byid[$q['turnid']]['body'],$q['text'])===false) {throw new \invalid_parameter_exception('Kutipan tidak cocok dengan sumber');}
        }
        foreach ($config['rubric']['criteria'] as $criterion) {if (empty($covered[$criterion['id']])) {$out['scores'][$criterion['id']]=null;
            $message=$criterion['label'].': Belum ada kutipan relevan yang dapat diperiksa.';
            $out['missing'][]=$message;}}
        $score=rubric::total($config['rubric'],$out['scores']??[]);
        if (empty($out['quotes'])) {$out['missing'][]='Kutipan jawaban belum tersedia';}
        if ($score===null || !empty($out['missing'])) {$out['completeness']='Bukti belum cukup';}
        else {$out['completeness']='Menunggu pengesahan';}
        return evidence::propose((int)$s->cmid,(int)$s->userid,'i-'.hash('sha256',json_encode([$sid,$out['turnids'],$out['model']])),
            'interview',$s->rubricversion,$s->snapshot,$out,$sid);
        } finally {$lock->release();}
    }
}
