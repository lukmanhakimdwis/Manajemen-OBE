<?php
defined('MOODLE_INTERNAL') || die();

function obeinterview_supports($feature) {
    switch ($feature) {
        case FEATURE_MOD_INTRO: return true;
        case FEATURE_GRADE_HAS_GRADE: return true;
        case FEATURE_BACKUP_MOODLE2: return false; // Explicit: no claim of portable transcript restore.
        case FEATURE_GROUPS: return false;
        default: return null;
    }
}
function obeinterview_validate_config($data) {
    // Core modedit owns PAGE setup; callbacks validate permissions without require_login/set_course.
    $context=\context_course::instance((int)$data->course);
    \local_obe\ai\scope::course((int)$data->course,true,$context);
    \local_obe\ai\scope::focus((int)$data->course,(int)$data->focusid,0,$context);
    if (!empty($data->instance)) {
        global $DB;
        $currentcm=get_coursemodule_from_instance('obeinterview',(int)$data->instance,(int)$data->course,false,MUST_EXIST);
        $currentmap=$DB->get_record('local_obe_aktivitas_map',['cmid'=>$currentcm->id]);
        if ($currentmap) {\local_obe\ai\scope::focus((int)$data->course,(int)$data->focusid,(int)$currentmap->sub_cpmk_id,$context);}
    }
    \local_obe\ai\rubric::parse($data->rubricjson);
    if ($data->duration<60||$data->duration>3600||$data->maxfollow<0||$data->maxfollow>5||$data->retentiondays<1||$data->retentiondays>3650||trim($data->corequestion)==='') {
        throw new invalid_parameter_exception('Batas atau pertanyaan wawancara tidak valid');
    }
    if ($data->projectcmid) {
        $cm=get_coursemodule_from_id('',(int)$data->projectcmid,(int)$data->course,false,MUST_EXIST);
        if (!in_array($cm->modname,['lti','assign'],true)) {throw new invalid_parameter_exception('Proyek harus aktivitas LTI atau Assignment di kelas yang sama');}
    }
}
function obeinterview_add_instance($data,$mform=null) {
    global $DB,$USER;
    obeinterview_validate_config($data);
    $data->timecreated=time(); $data->timemodified=time(); $data->ownerid=$USER->id;
    $data->id=$DB->insert_record('obeinterview',$data);
    obeinterview_grade_item_update($data);
    return $data->id;
}
function obeinterview_update_instance($data,$mform=null) {
    global $DB;
    obeinterview_validate_config($data); $data->id=$data->instance;
    $cm=get_coursemodule_from_instance('obeinterview',$data->id,$data->course,false,MUST_EXIST);
    $old=$DB->get_record('obeinterview',['id'=>$data->id],'*',MUST_EXIST);
    if ($DB->record_exists('local_obe_ai_session',['cmid'=>$cm->id])) {
        foreach (['focusid','rubricjson','corequestion','duration','maxfollow','projectcmid','retentiondays'] as $field) {
            if ((string)$old->$field!==(string)$data->$field) {throw new invalid_parameter_exception('Sesi sudah ada. Buat aktivitas baru untuk mengubah rubrik, fokus atau aturan.');}
        }
    }
    $data->ownerid=$old->ownerid; $data->timemodified=time();
    $DB->update_record('obeinterview',$data); obeinterview_grade_item_update($data); return true;
}
function obeinterview_delete_instance($id) {
    global $DB;
    $activity=$DB->get_record('obeinterview',['id'=>$id]); if (!$activity) {return false;}
    $cm=get_coursemodule_from_instance('obeinterview',$id,$activity->course,false,IGNORE_MISSING);
    if ($cm) {\local_obe\local\assessment_integrity::protect((int)$cm->id);}
    $tx=$DB->start_delegated_transaction();
    if ($cm) {
        foreach ($DB->get_records('local_obe_ai_evidence',['cmid'=>$cm->id]) as $e) {
            $DB->delete_records('local_obe_ai_decision',['evidenceid'=>$e->id]);
        }
        $DB->delete_records('local_obe_ai_evidence',['cmid'=>$cm->id]);
        foreach ($DB->get_records('local_obe_ai_session',['cmid'=>$cm->id]) as $s) {$DB->delete_records('local_obe_ai_turn',['sessionid'=>$s->id]);}
        $DB->delete_records('local_obe_ai_session',['cmid'=>$cm->id]);
        $map=$DB->get_record('local_obe_aktivitas_map',['cmid'=>$cm->id]);
        if ($map) {\local_obe\manager\aktivitasnilai_manager::delete($map->id);}
    }
    $result=obeinterview_grade_item_update($activity,null,true);
    if ($result!==GRADE_UPDATE_OK) {throw new moodle_exception('Penghapusan item nilai gagal');}
    $DB->delete_records('obeinterview',['id'=>$id]); $tx->allow_commit(); return true;
}
function obeinterview_grade_item_update($activity,$grades=null,$deleted=false) {
    global $CFG; require_once($CFG->libdir.'/gradelib.php');
    $params=['itemname'=>$activity->name,'gradetype'=>GRADE_TYPE_VALUE,'grademin'=>0,'grademax'=>100];
    if ($deleted) {$params['deleted']=1;}
    return grade_update('mod/obeinterview',$activity->course,'mod','obeinterview',$activity->id,0,$grades,$params);
}
function obeinterview_update_grades($activity,$userid=0,$nullifnone=true) {
    global $DB;
    $cm=get_coursemodule_from_instance('obeinterview',$activity->id,$activity->course,false,MUST_EXIST);
    $params=['cmid'=>$cm->id]; $userwhere='';
    if ($userid) {$params['userid']=$userid; $userwhere=' AND e.userid=:userid';}
    // Decision order, not second-resolution timestamps, determines the latest signed-off grade.
    $rows=$DB->get_records_sql("SELECT d.id, e.userid, e.score, e.approvedby, e.timemodified
        FROM {local_obe_ai_decision} d JOIN {local_obe_ai_evidence} e ON e.id=d.evidenceid
        WHERE e.cmid=:cmid AND e.state='approved' $userwhere ORDER BY d.id DESC",$params);
    $grades=[];
    foreach ($rows as $row) {
        if (!isset($grades[$row->userid])) {$grades[$row->userid]=(object)['userid'=>$row->userid,'rawgrade'=>(float)$row->score,'usermodified'=>$row->approvedby,'dategraded'=>$row->timemodified];}
    }
    if (!$grades && $userid && $nullifnone) {$grades[$userid]=(object)['userid'=>$userid,'rawgrade'=>null];}
    return obeinterview_grade_item_update($activity,$grades?:null);
}
function obeinterview_get_coursemodule_info($cm) {
    global $DB; $a=$DB->get_record('obeinterview',['id'=>$cm->instance],'id,name',IGNORE_MISSING);
    if (!$a) {return null;} $info=new cached_cm_info(); $info->name=$a->name; return $info;
}
