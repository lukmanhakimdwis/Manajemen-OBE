<?php
namespace local_obe\ai;
defined('MOODLE_INTERNAL') || die();

/** Supporting evidence never bypasses the module/AGS grade APIs or replaces OBE aggregation. */
final class evidence {
    private static function canonical(array $value): array {
        foreach ($value as $key=>$child) {if (is_array($child)) {$value[$key]=self::canonical($child);}}
        if ($value && array_keys($value)!==range(0,count($value)-1)) {ksort($value,SORT_STRING);}
        return $value;
    }
    public static function propose(int $cmid,int $userid,string $attempt,string $source,string $version,string $snapshot,array $body,int $sid=0): int {
        global $DB;
        $cm=get_coursemodule_from_id('',$cmid,0,false,MUST_EXIST);
        if ($source==='interview') {
            $session=interview::session($sid,true);
            if ((int)$session->cmid!==$cmid||(int)$session->userid!==$userid) {throw new \invalid_parameter_exception('Identitas sesi dan bukti tidak cocok');}
        }
        else if ($source==='essay') {scope::course((int)$cm->course,true,\context_module::instance($cmid)); require_capability('local/obe:useai',\context_course::instance($cm->course));}
        else if ($source==='runner') {
            scope::course((int)$cm->course,false,\context_module::instance($cmid)); require_capability('local/obe:ingestai',\context_course::instance($cm->course));
            if (!in_array($cm->modname,['lti','assign','quiz'],true)) {throw new \invalid_parameter_exception('Runner hanya untuk Quiz/Assignment/LTI');}
        }
        else {throw new \invalid_parameter_exception('Jenis sumber tidak tersedia');}
        if (!is_enrolled(\context_course::instance($cm->course),$userid,'',true)) {throw new \invalid_parameter_exception('Mahasiswa tidak aktif di kelas');}
        scope::mapping($cmid);
        if (!preg_match('/^[a-zA-Z0-9_-]{1,80}$/',$attempt) || !preg_match('/^[a-zA-Z0-9_-]{1,64}$/',$version) || ($snapshot!==''&&!preg_match('/^[a-f0-9]{40,64}$/',$snapshot))) {throw new \invalid_parameter_exception('Identitas bukti tidak valid');}
        if ($source==='runner' && !$snapshot) {throw new \invalid_parameter_exception('Snapshot runner wajib');}
        $rubric=rubric::parse(json_encode($body['rubric']??[],JSON_THROW_ON_ERROR));
        if ($rubric['version']!==$version) {throw new \invalid_parameter_exception('Versi rubrik tidak cocok');}
        $score=rubric::total($rubric,$body['scores']??[]);
        $body=self::canonical($body);
        $raw=json_encode($body,JSON_THROW_ON_ERROR); if (strlen($raw)>262144) {throw new \invalid_parameter_exception('Bukti terlalu besar');}
        $digest=hash('sha256',json_encode([$source,$version,$snapshot,$raw],JSON_THROW_ON_ERROR));
        $lock=\core\lock\lock_config::get_lock_factory('local_obe_ai')->get_lock('evidence:'.$cmid.':'.$userid.':'.$attempt,10);
        if (!$lock) {throw new \moodle_exception('locktimeout');}
        try {
            $old=$DB->get_record('local_obe_ai_evidence',['cmid'=>$cmid,'userid'=>$userid,'attemptkey'=>$attempt]);
            if ($old) {
                if (!hash_equals($old->payloadhash,$digest)) {throw new \invalid_parameter_exception('Identitas bukti sama dengan isi berbeda; gunakan review/koreksi');}
                return (int)$old->id;
            }
            return (int)$DB->insert_record('local_obe_ai_evidence',(object)['cmid'=>$cmid,'userid'=>$userid,'attemptkey'=>$attempt,
                'source'=>$source,'sessionid'=>$sid,'snapshot'=>$snapshot,'rubricversion'=>$version,'body'=>$raw,'payloadhash'=>$digest,
                'score'=>$score,'state'=>($score===null||!empty($body['missing']))?'insufficient':'proposed','revision'=>1,
                'approvedby'=>0,'timecreated'=>time(),'timemodified'=>time()]);
        } finally {$lock->release();}
    }

    public static function approve(int $eid,int $revision,array $scores,string $reason): array {
        return \local_obe\local\assessment_integrity::atomic(static function() use($eid,$revision,$scores,$reason) {return self::approve_locked($eid,$revision,$scores,$reason);});
    }
    private static function approve_locked(int $eid,int $revision,array $scores,string $reason): array {
        global $DB,$USER,$CFG;
        $e=$DB->get_record('local_obe_ai_evidence',['id'=>$eid],'*',MUST_EXIST);
        $cm=get_coursemodule_from_id('',$e->cmid,0,false,MUST_EXIST);
        scope::course((int)$cm->course,true,\context_module::instance((int)$cm->id)); require_capability('local/obe:approveai',\context_course::instance($cm->course));
        if ($e->source==='essay' && $cm->modname==='assign') {return assignment::approve($e,$revision,$scores,$reason);}
        if ($cm->modname!=='obeinterview' || $e->source!=='interview') {
            throw new \invalid_parameter_exception('Nilai proyek harus disahkan melalui tool LTI AGS/API modul terkait; endpoint ini khusus wawancara');
        }
        interview::session((int)$e->sessionid,true); scope::mapping((int)$e->cmid);
        if (!trim($reason)||strlen($reason)>4000) {throw new \invalid_parameter_exception('Alasan keputusan wajib (maksimum 4000 byte)');}
        require_once($CFG->dirroot.'/mod/obeinterview/lib.php'); require_once($CFG->libdir.'/gradelib.php');
        $factory=\core\lock\lock_config::get_lock_factory('local_obe_ai');
        $sessionlock=$factory->get_lock('session:'.$e->sessionid,10);
        if (!$sessionlock) {throw new \moodle_exception('locktimeout');}
        $lock=$factory->get_lock('approve:'.$e->cmid.':'.$e->userid,10);
        if (!$lock) {$sessionlock->release(); throw new \moodle_exception('locktimeout');}
        try {
            $e=$DB->get_record('local_obe_ai_evidence',['id'=>$eid],'*',MUST_EXIST);
            if ((int)$e->revision!==$revision) {throw new \invalid_parameter_exception('Revisi telah berubah; muat ulang');}
            if ($e->state==='expired') {throw new \invalid_parameter_exception('Isi bukti sudah melewati masa simpan');}
            $body=json_decode($e->body,true,64,JSON_THROW_ON_ERROR);
            if ($e->state==='insufficient'||!empty($body['missing'])||empty($body['quotes'])) {throw new \invalid_parameter_exception('Bukti belum cukup; buka kembali sesi sebelum memberi nilai');}
            $rubric=rubric::parse(json_encode($body['rubric'],JSON_THROW_ON_ERROR)); $total=rubric::total($rubric,$scores);
            if ($total===null) {throw new \invalid_parameter_exception('Skor kriteria wajib belum lengkap');}
            $activity=$DB->get_record('obeinterview',['id'=>$cm->instance],'*',MUST_EXIST);
            $item=\grade_item::fetch(['courseid'=>$cm->course,'itemtype'=>'mod','itemmodule'=>'obeinterview','iteminstance'=>$cm->instance,'itemnumber'=>0]);
            $grade=$item?\grade_grade::fetch(['itemid'=>$item->id,'userid'=>$e->userid]):false;
            if (($item&&$item->is_locked())||($grade&&($grade->is_locked()||$grade->is_overridden()))) {throw new \invalid_parameter_exception('Gradebook terkunci/di-override. Rekonsiliasi melalui pengelola nilai dahulu.');}
            $tx=$DB->start_delegated_transaction(); $before=['score'=>$e->score,'state'=>$e->state,'revision'=>$e->revision];
            $e->score=$total; $e->state='approved'; $e->approvedby=$USER->id; $e->revision++; $e->timemodified=time();
            $DB->update_record('local_obe_ai_evidence',$e);
            $DB->insert_record('local_obe_ai_decision',(object)['evidenceid'=>$eid,'actorid'=>$USER->id,'revision'=>$e->revision,
                'body'=>json_encode(['reason'=>$reason,'before'=>$before,'scores'=>$scores,'total'=>$total,'rubricversion'=>$e->rubricversion,
                    'snapshot'=>$e->snapshot,'provider'=>$body['provider']??'unknown','model'=>$body['model']??'unknown'],JSON_THROW_ON_ERROR),'timecreated'=>time()]);
            $status=\obeinterview_update_grades($activity,(int)$e->userid,false);
            if ($status!==GRADE_UPDATE_OK) {throw new \moodle_exception('Pembaruan Gradebook gagal');}
            $read=grade_get_grades($cm->course,'mod','obeinterview',$cm->instance,[$e->userid]);
            $itemread=empty($read->items)?null:reset($read->items);
            $seen=$itemread->grades[$e->userid]->grade??null;
            if ($seen===null||abs((float)$seen-$total)>0.00001) {throw new \moodle_exception('Verifikasi grade_get_grades gagal; keputusan dibatalkan');}
            $tx->allow_commit();
            \local_obe\access_guard::audit('ai_approve','ai_evidence',$eid,['revision'=>$e->revision,'grade'=>$total,'cmid'=>$cm->id]);
            return ['grade'=>$total,'grade_get_grades'=>(float)$seen,'revision'=>(int)$e->revision];
        } finally {$lock->release(); $sessionlock->release();}
    }
}
