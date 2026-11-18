<?php
require_once(dirname(__DIR__,5).'/config.php');
use local_obe\manager\aktivitasnilai_manager as maps;
$courseid=required_param('courseid',PARAM_INT);
[$course,$context]=\local_obe\access_guard::require_course_manage($courseid);
$id=optional_param('id',0,PARAM_INT);$newcmid=optional_param('newcmid',0,PARAM_INT);
$PAGE->set_url(new moodle_url('/local/obe/views/dosen/aktivitas_nilai/replace.php',['courseid'=>$courseid,'id'=>$id]));
$PAGE->set_context($context);$PAGE->set_title('Penggantian aspek penilaian');$PAGE->set_heading($course->fullname);
$back=new moodle_url('/local/obe/views/dosen/dashboard.php',['id'=>$courseid,'halaman'=>'aktivitas_nilai']);
$preview=optional_param('preview',0,PARAM_BOOL);$previewed=optional_param('previewedcmid',0,PARAM_INT);
$error='';$reason=optional_param('reason','',PARAM_TEXT);
if (data_submitted()) {require_sesskey();}
if (data_submitted()&&!$preview) {
    require_sesskey();
    try {
        if ($previewed!==$newcmid) {throw new moodle_exception('assessmenterror','local_obe','', 'Tinjau dampak aktivitas yang dipilih sebelum menyimpan.');}
        maps::replace($id,$newcmid,$courseid,$reason,required_param('expected',PARAM_ALPHANUM),optional_param('ack',0,PARAM_BOOL));
        redirect($back,'Penggantian tersimpan. Bobot lama telah dipindahkan; nilai Gradebook dan keputusan lama tetap disimpan. Capaian aktif dihitung dari pemetaan baru.',null,\core\output\notification::NOTIFY_SUCCESS);
    } catch (\moodle_exception $e) {$error=$e->getMessage();}
}
echo $OUTPUT->header();echo html_writer::link($back,'Kembali ke pemetaan');
echo '<h2>Penggantian aspek penilaian</h2>';
if ($error) {echo $OUTPUT->notification(s($error),'error');}
if ($id) {
    $old=$DB->get_record('local_obe_aktivitas_map',['id'=>$id],'*',MUST_EXIST);
    $cm=get_coursemodule_from_id('',(int)$old->cmid,$courseid,false,MUST_EXIST);
    $choices=[];foreach (get_fast_modinfo($courseid)->get_cms() as $candidate) {
        if (empty($candidate->deletioninprogress)&&$candidate->uservisible&&!$DB->record_exists('local_obe_aktivitas_map',['cmid'=>$candidate->id])) {$choices[$candidate->id]=$candidate->name;}
    }
    echo '<p>Aspek lama: <strong>'.s($cm->name).'</strong>. Bobot <strong>'.s($old->bobot_aktivitas).'%</strong> akan dipindahkan ke aktivitas baru pada Sub-CPMK yang sama. Total bobot tetap; aspek lama tidak lagi menyumbang pada capaian aktif.</p>';
    echo $OUTPUT->notification('Nilai Gradebook, keputusan dan transkrip lama tidak dihapus. Total kursus Moodle tetap mengikuti agregasi Gradebook dan dapat tetap memuat item lama; penggantian ini memindahkan bobot OBE. Capaian aktif seluruh mahasiswa dapat berubah: jika aktivitas baru belum dinilai, kontribusinya sementara 0. Jangan gunakan untuk capaian yang sudah diterbitkan sebelum kebijakan pengelola ditetapkan. Untuk kurikulum berikutnya, gunakan kelas/versi kurikulum baru.','warning');
    $policy=trim((string)get_config('local_obe','replacementpolicy'));
    $policyblocked=\local_obe\local\assessment_integrity::used((int)$old->cmid)&&$policy==='';
    if ($policyblocked) {echo $OUTPUT->notification('Penggantian belum dapat disimpan: pengelola belum menetapkan kebijakan penggantian aspek bernilai/berriwayat di pengaturan OBE. Pratinjau dampak tetap tersedia.','warning');}
    if ($policy!=='') {echo '<p><strong>Kebijakan pengelola:</strong> '.s($policy).'</p>';}
    echo '<form method="post"><input type="hidden" name="courseid" value="'.$courseid.'"><input type="hidden" name="id" value="'.$id.'"><input type="hidden" name="sesskey" value="'.sesskey().'"><input type="hidden" name="expected" value="'.maps::fingerprint($old).'">';
    echo html_writer::label('Aktivitas pengganti','newcmid').html_writer::select($choices,'newcmid',$newcmid,[''=>'Pilih aktivitas'],['id'=>'newcmid','required'=>true]);
    echo '<p><button class="btn btn-outline-primary" name="preview" value="1" formnovalidate>Tinjau dampak pilihan</button></p>';
    $canconfirm=$newcmid&&isset($choices[$newcmid]);
    if ($canconfirm) {
        $snapshot=\local_obe\local\assessment_integrity::snapshot($courseid);
        echo '<p><strong>Dampak: bobot aktif aspek lama '.$old->bobot_aktivitas.'% → 0%; aspek baru 0% → '.$old->bobot_aktivitas.'%. Total bobot tidak berubah.</strong></p>';
        if (has_capability('moodle/grade:viewall',$context)) {
            $newmodule=get_coursemodule_from_id('',$newcmid,$courseid,false,MUST_EXIST);
            $newitem=$DB->get_record('grade_items',['courseid'=>$courseid,'itemtype'=>'mod','itemmodule'=>$newmodule->modname,'iteminstance'=>$newmodule->instance,'itemnumber'=>0]);
            $oldmodule=get_coursemodule_from_id('',(int)$old->cmid,$courseid,false,MUST_EXIST);
            $olditem=$DB->get_record('grade_items',['courseid'=>$courseid,'itemtype'=>'mod','itemmodule'=>$oldmodule->modname,'iteminstance'=>$oldmodule->instance,'itemnumber'=>0]);
            $uids=[];foreach ($snapshot['grades'] as $g) {$uids[$g->userid]=true;}
            echo '<table class="table"><thead><tr><th>Mahasiswa</th><th>Kontribusi aspek lama (poin)</th><th>Kontribusi pengganti (poin)</th><th>Perubahan capaian Sub-CPMK (poin)</th></tr></thead><tbody>';
            foreach ($uids as $uid=>$unused) {
                $og=$olditem?$DB->get_field('grade_grades','finalgrade',['itemid'=>$olditem->id,'userid'=>$uid]):null;
                $ng=$newitem?$DB->get_field('grade_grades','finalgrade',['itemid'=>$newitem->id,'userid'=>$uid]):null;
                $before=(float)$og/(float)(($olditem->grademax??0)?:100)*(float)$old->bobot_aktivitas;
                $after=(float)$ng/(float)(($newitem->grademax??0)?:100)*(float)$old->bobot_aktivitas;
                $student=$DB->get_record('user',['id'=>$uid],'id,firstname,lastname',MUST_EXIST);
                echo '<tr><td>'.s(fullname($student)).'</td><td>'.format_float($before,2).'</td><td>'.format_float($after,2).($ng===false||$ng===null?' (belum dinilai)':'').'</td><td>'.format_float($after-$before,2).'</td></tr>';
            }
            echo '</tbody></table>';
        }
        echo '<input type="hidden" name="previewedcmid" value="'.$newcmid.'"><p><label for="reason">Alasan penggantian</label><textarea id="reason" name="reason" required maxlength="4000" class="form-control">'.s($reason).'</textarea></p><p><label><input type="checkbox" name="ack" value="1" required> Saya memahami bobot lama dipindahkan dan capaian aktif seluruh mahasiswa dihitung ulang; perubahan ini sesuai kebijakan pengelola.</label></p><button class="btn btn-primary"'.($policyblocked?' disabled':'').'>Arsipkan pemetaan lama dan pindahkan bobot</button>';
    }
    echo '</form>';
}
if (has_capability('moodle/grade:viewall',$context)) {
    echo '<h3>Riwayat penggantian</h3>';
    foreach ($DB->get_records('local_obe_audit_log',['entity'=>'activity_map_archive'],'id DESC') as $archive) {
        $d=json_decode($archive->details,true);if (($d['courseid']??0)!==$courseid) {continue;}
        echo '<p>Kebijakan saat penggantian: '.s($d['policy']??'Belum dicatat pada arsip lama').'</p>';
        echo '<details><summary>Pemetaan #'.(int)$archive->entityid.' → #'.(int)$d['newmapid'].' — '.s(userdate($archive->timecreated)).'</summary><p>Alasan: '.s($d['reason']).'</p><p>Bobot lama: '.s($d['oldmap']['bobot_aktivitas']).'%. Rekaman kondisi sebelum penggantian:</p>';
        echo '<table class="table"><thead><tr><th>ID mahasiswa</th><th>Item</th><th>Nilai lama</th><th>Maksimum</th></tr></thead><tbody>';
        foreach ($d['before']['grades'] as $g) {echo '<tr><td>'.(int)$g['userid'].'</td><td>'.s($g['itemname']??'Total kursus').'</td><td>'.s($g['finalgrade']??'Belum dinilai').'</td><td>'.s($g['grademax']).'</td></tr>';}
        echo '</tbody></table><p>Keputusan yang dipertahankan: ';foreach ($d['before']['decisions'] as $decision) {echo '#'.(int)$decision['id'].' (Bukti #'.(int)$decision['evidenceid'].') ';}echo '</p>';
        if (has_capability('local/obe:approveai',$context)) {foreach ($DB->get_records('local_obe_ai_session',['cmid'=>$d['oldmap']['cmid']]) as $session) {if (has_capability('mod/obeinterview:grade',context_module::instance($session->cmid))) {echo html_writer::link(new moodle_url('/local/obe/views/dosen/aktivitas_nilai/replace.php',['courseid'=>$courseid,'archivedsession'=>$session->id]),'Transkrip arsip sesi #'.$session->id,['class'=>'btn btn-outline-secondary mr-2']);}}}
        echo '</details>';
    }
}
$sid=optional_param('archivedsession',0,PARAM_INT);
if ($sid) {
    require_capability('local/obe:approveai',$context);
    $session=$DB->get_record('local_obe_ai_session',['id'=>$sid],'*',MUST_EXIST);
    $sourcecm=get_coursemodule_from_id('',(int)$session->cmid,$courseid,false,MUST_EXIST);
    require_capability('mod/obeinterview:grade',context_module::instance($sourcecm->id));
    $archived=false;
    foreach ($DB->get_records('local_obe_audit_log',['entity'=>'activity_map_archive']) as $a) {$d=json_decode($a->details,true);if (($d['courseid']??0)===$courseid&&(int)($d['oldmap']['cmid']??0)===(int)$session->cmid) {$archived=true;break;}}
    if (!$archived) {throw new moodle_exception('assessmenterror','local_obe','', 'Sesi bukan bagian pemetaan yang diarsipkan.');}
    echo '<h3>Transkrip arsip sesi #'.$sid.'</h3>';
    foreach ($DB->get_records('local_obe_ai_turn',['sessionid'=>$sid],'id ASC') as $turn) {echo '<p><strong>'.($turn->speaker==='student'?'Jawaban mahasiswa':'Pertanyaan wawancara').'</strong> — '.s(userdate($turn->timecreated)).'</p><div class="mb-3" style="white-space:pre-wrap;overflow-wrap:anywhere">'.s($turn->body).'</div>';}
}
echo $OUTPUT->footer();
