<?php
namespace local_obe\ai;
defined('MOODLE_INTERNAL') || die();

/** Own final grade and operational status only; never release draft scores or private review notes. */
final class outcome {
    public static function student(int $cmid): array {
        global $DB,$USER,$CFG;
        [$cm]=interview::activity($cmid);$uid=(int)$USER->id;
        require_once($CFG->libdir.'/gradelib.php');
        $latest=$DB->get_records('local_obe_ai_evidence',['cmid'=>$cmid,'userid'=>$uid,'source'=>'interview'],'id DESC','id,state',0,1);
        $latest=$latest?reset($latest):null;
        if ($latest) {$latest->signed=$DB->record_exists('local_obe_ai_decision',['evidenceid'=>$latest->id]);}
        $signed=$DB->get_records_sql('SELECT d.id,e.id AS evidenceid FROM {local_obe_ai_decision} d JOIN {local_obe_ai_evidence} e ON e.id=d.evidenceid
            WHERE e.cmid=:cmid AND e.userid=:uid AND e.source=:source AND e.state=:approved ORDER BY d.id DESC',
            ['cmid'=>$cmid,'uid'=>$uid,'source'=>'interview','approved'=>'approved'],0,1);
        $signed=$signed?reset($signed):null;
        $item=\grade_item::fetch(['courseid'=>$cm->course,'itemtype'=>'mod','itemmodule'=>'obeinterview','iteminstance'=>$cm->instance,'itemnumber'=>0]);
        $grade=$item?\grade_grade::fetch(['itemid'=>$item->id,'userid'=>$uid]):false;
        $hidden=!has_capability('moodle/grade:view',\context_course::instance($cm->course))||($item&&$item->is_hidden())||($grade&&$grade->is_hidden());
        $value=null;
        if ($signed&&!$hidden) {
            $native=grade_get_grades($cm->course,'mod','obeinterview',$cm->instance,[$uid]);$nativeitem=$native->items?reset($native->items):null;
            $value=$nativeitem->grades[$uid]->grade??null;
        }
        return self::summary($latest,$signed,$value,$hidden)+[
            'reporturl'=>(new \moodle_url('/local/obe/views/mahasiswa/dashboard.php',['id_course'=>$cm->course,'halaman'=>'beranda']))->out(false),
            'courseurl'=>(new \moodle_url('/course/view.php',['id'=>$cm->course]))->out(false)];
    }
    public static function summary($latest,$signed,$value,bool $hidden): array {
        $new=$latest&&$signed&&(int)$latest->id!==(int)$signed->evidenceid&&empty($latest->signed);
        $status=$latest&&$latest->state==='expired'?'Masa simpan berakhir':($latest&&$latest->state==='insufficient'?'Bukti belum cukup':($latest?'Menunggu pengesahan dosen':'Belum dianalisis'));
        if ($signed&&!$new) {$status='Nilai disahkan';}
        else if ($new) {$status=$latest->state==='insufficient'?'Bukti tambahan belum cukup':'Usulan penilaian lanjutan menunggu pengesahan';}
        return ['status'=>$status,'hasgrade'=>$signed&&!$hidden&&$value!==null,
            'grade'=>$signed&&!$hidden&&$value!==null?format_float((float)$value,5,true,true):null,
            'message'=>$signed?($hidden?'Penilaian telah disahkan. Nilai belum ditampilkan oleh dosen.':($value!==null?'Nilai yang berlaku telah disahkan dosen.':'Keputusan telah disimpan; nilai belum terbaca di Gradebook. Hubungi dosen.')):'Nilai belum disahkan dosen.',
            'newproposal'=>$new?($hidden?'Penilaian lanjutan menunggu dosen.':'Ada penilaian lanjutan yang belum disahkan. Nilai yang berlaku tetap ditampilkan.'):''];
    }
}
