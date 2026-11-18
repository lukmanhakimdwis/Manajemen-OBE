<?php
require_once(__DIR__.'/../../config.php');
use local_obe\ai\interview;
$id=required_param('id',PARAM_INT); $cm=get_coursemodule_from_id('obeinterview',$id,0,false,MUST_EXIST);
$ctx=context_module::instance($id); require_login(get_course($cm->course),false,$cm); require_capability('mod/obeinterview:view',$ctx);
$grading=has_capability('mod/obeinterview:grade',$ctx)&&has_capability('local/obe:approveai',context_course::instance($cm->course));
[$cm,$activity]=interview::activity($id,$grading);
$PAGE->set_context($ctx); $PAGE->set_cm($cm); $PAGE->set_url('/mod/obeinterview/view.php',['id'=>$id]);
// Explicit stylesheet before output; cache key changes with this code-only patch.
$PAGE->requires->css(new moodle_url('/mod/obeinterview/chat.css',['v'=>substr(hash_file('sha256',__DIR__.'/chat.css'),0,12)]));
$PAGE->set_title(format_string($activity->name)); $PAGE->set_heading(format_string(get_course($cm->course)->fullname));
$failed=[];$reviewerror='';$selected=optional_param('reviewid',0,PARAM_INT);
if ($_SERVER['REQUEST_METHOD']==='POST') {
    require_sesskey();
    if (!$grading) {throw new required_capability_exception($ctx,'mod/obeinterview:grade','nopermissions','');}
    $action=required_param('action',PARAM_ALPHA);
    try {
        if ($action==='approve') {
            $eid=required_param('evidenceid',PARAM_INT);
            $e=$DB->get_record('local_obe_ai_evidence',['id'=>$eid,'cmid'=>$id,'source'=>'interview'],'*',MUST_EXIST);
            $scores=optional_param_array('scores',[],PARAM_RAW_TRIMMED);$reason=required_param('reason',PARAM_TEXT);
            $revision=required_param('revision',PARAM_INT);$selected=$eid;
            $failed=['evidenceid'=>$eid,'revision'=>$revision,'scores'=>$scores,'reason'=>$reason,'errors'=>[]];
            $body=json_decode($e->body,true,64,JSON_THROW_ON_ERROR);
            $rubric=\local_obe\ai\rubric::parse(json_encode($body['rubric']??[],JSON_THROW_ON_ERROR));
            $validated=\mod_obeinterview\local\review::validate($rubric,$scores,$reason);
            $failed['errors']=$validated['errors'];
            if ($validated['errors']) {$reviewerror=get_string('review_save_failed','obeinterview');}
            else {
                $result=\local_obe\ai\evidence::approve($eid,$revision,$validated['scores'],$reason);
                redirect(new moodle_url('/mod/obeinterview/view.php',['id'=>$id,'reviewid'=>$eid],'obe-evidence-'.$eid),
                    get_string('review_saved','obeinterview',\mod_obeinterview\local\review::number($result['grade_get_grades'])),null,\core\output\notification::NOTIFY_SUCCESS);
            }
        } else {
            $sid=required_param('sid',PARAM_INT);$DB->get_record('local_obe_ai_session',['id'=>$sid,'cmid'=>$id],'*',MUST_EXIST);
            if ($action==='proposal') {
                $eid=interview::proposal($sid);
                redirect(new moodle_url('/mod/obeinterview/view.php',['id'=>$id,'reviewid'=>$eid],'obe-evidence-'.$eid),get_string('review_analysed','obeinterview'),null,\core\output\notification::NOTIFY_SUCCESS);
            } else if ($action==='reopen') {
                $reason=required_param('reason',PARAM_TEXT);$failed=['sid'=>$sid,'action'=>'reopen','reason'=>$reason];
                interview::reopen($sid,$reason);
                redirect($PAGE->url,get_string('review_reopened','obeinterview'),null,\core\output\notification::NOTIFY_SUCCESS);
            } else {throw new invalid_parameter_exception('Aksi tidak dikenal');}
        }
    } catch (\moodle_exception | \JsonException $exception) {
        $reviewerror=get_string('review_save_failed','obeinterview');
        if (isset($failed['evidenceid'])) {$failed['errors']['form']=$reviewerror;}
    }
}
// Validate before rendering; configuration/access failures must not look like a missing mapping.
$map=null;
try {
    $map=\local_obe\ai\scope::mapping($id);
    \local_obe\ai\scope::focus((int)$cm->course,(int)$activity->focusid,(int)$map->sub_cpmk_id,$ctx);
} catch (\local_obe\ai\mapping_missing $e) {
    // A genuinely unmapped activity remains viewable, with no start controls.
} catch (\invalid_parameter_exception | \dml_missing_record_exception $e) {
    $map=null;$reviewerror='Fokus atau struktur kompetensi belum sesuai dengan Sub-CPMK tujuan. Dosen perlu memeriksa fokus wawancara dan pemetaannya.';
}
echo $OUTPUT->header();
echo $OUTPUT->notification(get_string('review_simulation','obeinterview'),'info');
if ($reviewerror!=='') {echo $OUTPUT->notification($reviewerror,'error');}
echo format_module_intro('obeinterview',$activity,$id);
if ($map===null) {
    $archived=false;
    foreach ($DB->get_records('local_obe_audit_log',['entity'=>'activity_map_archive']) as $archive) {
        $details=json_decode($archive->details,true);
        if ((int)($details['courseid']??0)===(int)$cm->course&&(int)($details['oldmap']['cmid']??0)===$id) {$archived=true;break;}
    }
    if ($archived) {
        echo $OUTPUT->notification('Aspek ini telah diganti dan tidak lagi menyumbang pada capaian aktif. Nilai lama tetap disimpan di Gradebook; riwayat dan transkrip arsip dapat diperiksa dosen.','info');
        if ($grading&&has_capability('local/obe:managecourse',context_course::instance($cm->course))) {echo html_writer::link(new moodle_url('/local/obe/views/dosen/aktivitas_nilai/replace.php',['courseid'=>$cm->course]),'Lihat riwayat penggantian');}
        echo $OUTPUT->footer();exit;
    }
    echo $OUTPUT->notification($reviewerror?:get_string('aimappingmissing','local_obe'),'info');
    if ($grading) {echo \local_obe\local\ux::course_links((int)$cm->course);echo html_writer::link(new moodle_url('/course/modedit.php',['update'=>$id]),'Periksa pengaturan wawancara',['class'=>'btn btn-outline-primary']);}
    echo $OUTPUT->footer(); exit;
}
if ($grading) {
    echo \local_obe\local\ux::course_links((int)$cm->course);
    $PAGE->requires->js(new moodle_url('/mod/obeinterview/review.js',['v'=>substr(hash_file('sha256',__DIR__.'/review.js'),0,12)]));
    echo $OUTPUT->render_from_template('mod_obeinterview/review',\mod_obeinterview\local\review::page($cm,$map,$selected,$failed,['page'=>optional_param('page',0,PARAM_INT),'filter'=>optional_param('filter','',PARAM_ALPHA),'q'=>optional_param('q','',PARAM_TEXT),'open_session'=>optional_param('open_session',0,PARAM_INT),'enabled'=>true]));
} else {
    $result=\local_obe\ai\outcome::student($id);
    echo '<section class="card card-body mb-3" aria-labelledby="obe-result-title"><h2 id="obe-result-title" class="h5">Hasil penilaian saya</h2><p id="obe-assessment-status" role="status" aria-live="polite">'.s($result['status']).'</p><p id="obe-assessment-grade">'.s($result['hasgrade']?'Nilai yang berlaku: '.$result['grade'].'/100':$result['message']).'</p><p id="obe-assessment-note">'.s($result['newproposal']).'</p>';
    echo html_writer::link(new moodle_url($result['reporturl']),'Lihat capaian saya',['class'=>'btn btn-outline-primary mr-2']);
    echo html_writer::link(new moodle_url($result['courseurl']),'Kembali ke kursus',['class'=>'btn btn-outline-secondary mr-2']);echo '<button type="button" id="obe-refresh-result" class="btn btn-outline-secondary">Perbarui hasil</button></section>';
    $target=end($map->path);
    echo html_writer::tag('p',s('Target: '.$target->nama_kompetensi.' · Bobot wawancara terhadap Sub-CPMK: '.$map->bobot_aktivitas.'%'));
    echo '<section id="obe-chat" class="card card-body" aria-label="Wawancara OBE AI"><p>Tujuan: jelaskan pengujian dari pekerjaan Anda. Durasi '.format_float($activity->duration/60,2,true,true).' menit, maksimal '.(int)$activity->maxfollow.' pertanyaan lanjutan. Setiap giliran disimpan selama '.(int)$activity->retentiondays.' hari untuk pemeriksaan dosen. Fokus dan rubrik sama untuk seluruh peserta aktivitas ini.</p>';
    echo '<fieldset id="setup" class="obe-setup"><legend class="h5">Mulai wawancara</legend><label for="snapshotid">Kiriman proyek yang dibahas</label><select class="form-control" id="snapshotid"><option value="0">'.($activity->projectcmid?'Pilih kiriman proyek':'Tanpa proyek terkait').'</option>';
    if ($activity->projectcmid) {
        foreach ($DB->get_records('local_obe_ai_evidence',['cmid'=>$activity->projectcmid,'userid'=>$USER->id,'source'=>'runner'],'id DESC') as $e) {
            if ($e->state!=='expired'&&preg_match('/^[a-f0-9]{40,64}$/',$e->snapshot)) {echo '<option value="'.$e->id.'">'.s('Kiriman '.userdate($e->timecreated).' — versi '.substr($e->snapshot,0,8)).'</option>';} 
        }
    }
    echo '</select><p class="small text-muted">Jika proyek diwajibkan tetapi daftar kosong, kumpulkan proyek melalui aktivitas terkait atau hubungi dosen.</p><div class="obe-consent"><input type="checkbox" id="consent"> <label for="consent">Saya memahami tujuan, aturan dan penyimpanan percakapan.</label></div><button type="button" class="btn btn-primary" id="start">Mulai wawancara</button></fieldset>';
    echo '<div class="obe-session-bar"><p id="status" role="status" aria-live="polite"></p><p class="obe-timer">Sisa waktu: <strong id="remaining">—</strong></p></div>';
    echo '<ol id="turns" class="obe-transcript" aria-label="Transkrip wawancara"></ol><p id="stream" class="obe-stream" aria-live="polite" hidden></p>';
    echo '<div id="composer" class="obe-composer" hidden><label for="answer">Jawaban Anda</label><textarea class="form-control" id="answer" rows="4" maxlength="20000" aria-describedby="answer-help"></textarea><p id="answer-help" class="text-muted">Jawab pertanyaan AI terakhir. Setiap jawaban disimpan sebelum respons berikutnya diproses.</p><button type="button" class="btn btn-primary" id="send" disabled>Kirim</button></div>';
    echo '<div class="obe-actions"><button type="button" class="btn btn-outline-secondary" id="resume" hidden>Muat ulang status / transkrip</button><button type="button" class="btn btn-outline-danger" id="end" disabled>Akhiri wawancara</button></div></section>';
    $old=$DB->get_record('local_obe_ai_session',['cmid'=>$id,'userid'=>$USER->id]);
    $cfg=['cmid'=>$id,'userid'=>(int)$USER->id,'projectrequired'=>(bool)$activity->projectcmid,'sid'=>$old?(int)$old->id:0,'sesskey'=>sesskey(),'url'=>(new moodle_url('/mod/obeinterview/rpc.php'))->out(false)];
    echo '<script type="application/json" id="obe-config">'.json_encode($cfg,JSON_HEX_TAG|JSON_HEX_QUOT|JSON_HEX_AMP|JSON_HEX_APOS).'</script>';
    $PAGE->requires->js(new moodle_url('/mod/obeinterview/chat.js',['v'=>substr(hash_file('sha256',__DIR__.'/chat.js'),0,12)]));
}
echo $OUTPUT->footer();
