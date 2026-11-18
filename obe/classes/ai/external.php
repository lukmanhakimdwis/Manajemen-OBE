<?php
namespace local_obe\ai;
defined('MOODLE_INTERNAL') || die();
global $CFG;
require_once($CFG->libdir.'/externallib.php');
final class external extends \external_api {
    public static function runner_parameters() {
        return new \external_function_parameters([
            'cmid'=>new \external_value(PARAM_INT,'Aktivitas Quiz/Assignment/LTI yang sudah dipetakan'),
            'userid'=>new \external_value(PARAM_INT,'Mahasiswa aktif di kelas'),
            'attemptkey'=>new \external_value(PARAM_ALPHANUMEXT,'ID kiriman idempoten'),
            'snapshot'=>new \external_value(PARAM_ALPHANUM,'Commit SHA atau manifest SHA256'),
            'rubricversion'=>new \external_value(PARAM_ALPHANUMEXT,'Versi rubrik'),
            'body'=>new \external_value(PARAM_RAW,'JSON rubrik, skor, tes, sumber dan metadata runner'),
        ]);
    }
    public static function runner($cmid,$userid,$attemptkey,$snapshot,$rubricversion,$body) {
        $p=self::validate_parameters(self::runner_parameters(),compact('cmid','userid','attemptkey','snapshot','rubricversion','body'));
        $cm=get_coursemodule_from_id('',$p['cmid'],0,false,MUST_EXIST);
        self::validate_context(\context_course::instance($cm->course));
        require_capability('local/obe:ingestai',\context_course::instance($cm->course));
        $data=json_decode($p['body'],true,64,JSON_THROW_ON_ERROR);
        if (empty($data['tests'])||empty($data['runner'])||empty($data['source_ref'])) {throw new \invalid_parameter_exception('Hasil tes, identitas runner dan sumber wajib');}
        return evidence::propose($p['cmid'],$p['userid'],$p['attemptkey'],'runner',$p['rubricversion'],$p['snapshot'],$data);
    }
    public static function runner_returns() {return new \external_value(PARAM_INT,'ID bukti; tidak langsung mengubah Gradebook');}
}
