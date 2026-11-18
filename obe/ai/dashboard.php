<?php
require_once(__DIR__.'/../../../config.php');
use local_obe\ai\scope;
use local_obe\ai\drafts;
use local_obe\ai\rubric;
use local_obe\ai\form_adapter;
$courseid=required_param('courseid',PARAM_INT);scope::course($courseid,true);
$context=context_course::instance($courseid);require_capability('local/obe:useai',$context);
$PAGE->set_context($context);$PAGE->set_url('/local/obe/ai/dashboard.php',['courseid'=>$courseid]);
$PAGE->set_title('Draf soal dan penilaian AI');$PAGE->set_heading(get_course($courseid)->fullname);
$PAGE->requires->js(new moodle_url('/local/obe/accessibility.js',['v'=>substr(hash_file('sha256',__DIR__.'/../accessibility.js'),0,12)]));
$PAGE->requires->js(new moodle_url('/local/obe/score-preview.js',['v'=>substr(hash_file('sha256',__DIR__.'/../score-preview.js'),0,12)]));
$failed=[];$error='';
if ($_SERVER['REQUEST_METHOD']==='POST') {
    require_sesskey();$action=required_param('action',PARAM_ALPHA);
    $failed=['action'=>$action,'cmid'=>optional_param('cmid',0,PARAM_INT),'evidenceid'=>optional_param('evidenceid',0,PARAM_INT),
        'revision'=>optional_param('revision',0,PARAM_INT),'userid'=>optional_param('userid',0,PARAM_INT),'scores'=>optional_param_array('scores',[],PARAM_RAW_TRIMMED),
        'reason'=>optional_param('reason','',PARAM_TEXT),'rows'=>form_adapter::request_rows(),
        'contexttext'=>optional_param('contexttext','',PARAM_TEXT),'competencyid'=>optional_param('competencyid',0,PARAM_INT),'kind'=>optional_param('kind','multichoice',PARAM_ALPHA)];
    try {
        if ($action==='draft') {
            drafts::create($courseid,$failed['competencyid'],$failed['contexttext'],required_param('approvedcontext',PARAM_BOOL),$failed['kind']);
            $message='Draf tersimpan. Periksa pertanyaan, solusi dan rubrik sebelum menerbitkannya.';
        } else if ($action==='publish') {
            $id=required_param('draftid',PARAM_INT);$DB->get_record('local_obe_ai_draft',['id'=>$id,'courseid'=>$courseid],'*',MUST_EXIST);drafts::publish($id);
            $message='Soal diterbitkan ke Bank Soal kelas ini. Gunakan tautan Buka Bank Soal untuk memeriksanya.';
        } else if ($action==='analyze') {
            get_coursemodule_from_id('assign',$failed['cmid'],$courseid,false,MUST_EXIST);
            $json=form_adapter::rubric_json($failed['rows'],json_encode(rubric::defaults()));
            \local_obe\ai\assignment::analyze($failed['cmid'],$failed['userid'],$json);$message='Analisis tersimpan. Periksa kutipan dan skor sebelum mengesahkan.';
        } else if ($action==='approve') {
            $e=$DB->get_record('local_obe_ai_evidence',['id'=>$failed['evidenceid'],'source'=>'essay'],'*',MUST_EXIST);
            get_coursemodule_from_id('assign',$e->cmid,$courseid,false,MUST_EXIST);
            $body=json_decode($e->body,true,64,JSON_THROW_ON_ERROR);$r=rubric::parse(json_encode($body['rubric']??[],JSON_THROW_ON_ERROR));
            $scores=form_adapter::scores($r,$failed['scores']);
            if (!trim($failed['reason'])||strlen($failed['reason'])>4000) {throw new invalid_parameter_exception('Tuliskan alasan singkat sebelum menyimpan.');}
            $result=\local_obe\ai\evidence::approve($e->id,$failed['revision'],$scores,$failed['reason']);
            $message='Server mengonfirmasi nilai '.format_float($result['grade_get_grades'],5,true,true).' tersimpan di Gradebook tugas.';
        } else {throw new invalid_parameter_exception('Aksi tidak dikenal');}
        redirect($PAGE->url,$message,null,\core\output\notification::NOTIFY_SUCCESS);
    } catch (\moodle_exception | \JsonException $exception) {
        $error='Tindakan belum berhasil dikonfirmasi. Periksa mahasiswa dan kirimannya, kelengkapan rubrik/bobot 100%, skor 0–100, alasan serta revisi terbaru. Jika nilai terkunci, minta pengelola memeriksanya. Isian Anda dipertahankan.';
    }
}
function local_obe_ai_hidden(array $values): string {
    $out='';foreach ($values as $key=>$value) {$out.='<input type="hidden" name="'.s($key).'" value="'.s($value).'">';}return $out;
}
echo $OUTPUT->header();echo '<div class="obe-workspace">';
echo \local_obe\local\ux::course_links($courseid);
echo $OUTPUT->notification('Mode simulasi untuk uji coba. Nilai masuk ke Gradebook setelah dosen mengesahkannya. Koneksi akun AI belum tersedia.','info');
if ($error) {echo $OUTPUT->notification($error,'error');}
echo '<h2>Draf soal dari materi atau RPS</h2><form method="post" class="card card-body mb-3">';
echo local_obe_ai_hidden(['sesskey'=>sesskey(),'action'=>'draft']);
echo html_writer::label('Fokus kompetensi','competencyid');echo html_writer::select(scope::options($courseid,$context),'competencyid',$failed['competencyid']??0,'Pilih kompetensi',['id'=>'competencyid','required'=>true]);
echo '<label for="obe-kind">Jenis soal</label>'.html_writer::select(['multichoice'=>'Pilihan ganda','essay'=>'Esai','coding'=>'Coding — draf, publikasi menunggu lingkungan tes'],'kind',$failed['kind']??'multichoice',false,['id'=>'obe-kind']);
echo '<label for="obe-rps">Materi atau RPS yang disahkan</label><textarea class="form-control mb-2" id="obe-rps" name="contexttext" rows="6" maxlength="60000" required>'.s($failed['contexttext']??'').'</textarea><label><input type="checkbox" name="approvedcontext" value="1" required> Saya mengesahkan materi/RPS ini sebagai konteks draf.</label><button class="btn btn-primary">Buat draf simulasi</button></form>';
foreach ($DB->get_records('local_obe_ai_draft',['courseid'=>$courseid],'id DESC','*',0,50) as $row) {
    $body=json_decode($row->body,true);$published=$row->status==='published';
    echo '<details class="card card-body mb-3"><summary>'.s($body['title']??'Draf soal').' — '.($published?'Sudah diterbitkan':($row->status==='expired'?'Masa simpan berakhir':'Draf menunggu persetujuan')).'</summary>';
    echo '<h3 class="h5 mt-3">Pertanyaan</h3><p>'.nl2br(s($body['question']??'Isi draf tidak tersedia')).'</p><h4 class="h6">Kunci atau solusi referensi</h4><p>'.nl2br(s($body['solution']??'Belum tersedia')).'</p>';
    if (!empty($body['answers'])) {echo '<ul>';foreach ($body['answers'] as $answer) {echo '<li>'.s($answer['text']).' — '.s($answer['fraction']).'%</li>';}echo '</ul>';}
    echo '<h4 class="h6">Rubrik</h4><ul>';foreach ($body['rubric']['criteria']??[] as $criterion) {echo '<li>'.s($criterion['label']).' — '.format_float($criterion['weight']*100,2,true,true).'%</li>';}echo '</ul>';
    if (!empty($body['tests'])) {echo '<h4 class="h6">Kasus uji yang diusulkan</h4><ul>';foreach ($body['tests'] as $test) {echo '<li>Stok '.s($test['stock']??'').' · Jumlah '.s($test['quantity']??'').' · Hasil '.s($test['expected']??'').'</li>';}echo '</ul><p>Kasus uji ini masih usulan; belum membuktikan eksekusi kode.</p>';}
    if ($row->status==='draft' && ($body['kind']??'')!=='coding') {echo '<form method="post">'.local_obe_ai_hidden(['sesskey'=>sesskey(),'action'=>'publish','draftid'=>$row->id]).'<button class="btn btn-primary">Setujui dan terbitkan ke Bank Soal</button></form>';}
    if ($row->status==='draft' && ($body['kind']??'')==='coding') {echo '<p class="alert alert-info">Draf coding tersimpan. Publikasi menunggu lingkungan tes dan pengujian solusi referensi tersedia.</p>';}
    if ($published) {echo html_writer::link(new moodle_url('/question/edit.php',['courseid'=>$courseid]),'Buka Bank Soal kelas ini',['class'=>'btn btn-outline-primary']);}
    echo '<details><summary>Detail teknis</summary><pre tabindex="0">'.s(json_encode($body,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)).'</pre></details></details>';
}
echo '<h2>Analisis jawaban tugas</h2><p>Pilot mendukung kiriman teks individual dengan nilai angka. Pengaturan tugas lainnya tetap diperiksa melalui penilaian Moodle.</p>';
foreach (\local_obe\manager\aktivitasnilai_manager::ambil_semua_pemetaan($courseid) as $cm) {
    if ($cm->modulename==='obeinterview') {echo '<p>'.html_writer::link(new moodle_url('/mod/obeinterview/view.php',['id'=>$cm->cmid]),'Periksa wawancara: '.s($cm->name)).'</p>';continue;}
    if ($cm->modulename!=='assign'||!$cm->sub_cpmk_id||!has_capability('mod/assign:grade',context_module::instance($cm->cmid))) {continue;}
    echo '<section class="card card-body mb-4"><h3 class="h5">'.s($cm->name).'</h3>';
    echo html_writer::link(new moodle_url('/mod/assign/view.php',['id'=>$cm->cmid,'action'=>'grading']),'Buka penilaian tugas di Moodle');
    $people=[];foreach (get_enrolled_users(context_module::instance($cm->cmid),'mod/assign:submit',0,'u.id,u.username,'.\core_user\fields::for_name()->get_sql('u',false,'','',false)->selects,'u.lastname ASC',0,0,true) as $person) {$people[$person->id]=fullname($person).' ('.$person->username.')';}
    echo '<details><summary>Analisis kiriman mahasiswa</summary><form method="post">'.local_obe_ai_hidden(['sesskey'=>sesskey(),'action'=>'analyze','cmid'=>$cm->cmid]);
    echo html_writer::label('Mahasiswa','obe-student-'.$cm->cmid);echo html_writer::select($people,'userid',($failed['cmid']??0)==$cm->cmid?($failed['userid']??0):0,'Pilih mahasiswa',['id'=>'obe-student-'.$cm->cmid,'required'=>true]);
    echo form_adapter::editor('obe-rubric-'.$cm->cmid,json_encode(rubric::defaults()),($failed['action']??'')==='analyze'&&($failed['cmid']??0)==$cm->cmid?$failed['rows']:[]);
    echo '<button class="btn btn-primary">Analisis jawaban</button></form></details>';
    foreach ($DB->get_records('local_obe_ai_evidence',['cmid'=>$cm->cmid,'source'=>'essay'],'id DESC') as $e) {
        $body=json_decode($e->body,true);$student=$DB->get_record('user',['id'=>$e->userid],'id,'.\core_user\fields::for_name()->get_sql('',false,'','',false)->selects,MUST_EXIST);
        $decisions=$DB->get_records('local_obe_ai_decision',['evidenceid'=>$e->id],'id DESC','*',0,1);$decision=$decisions?json_decode(reset($decisions)->body,true):null;
        $status=$decision?'Nilai disahkan':($e->state==='insufficient'?'Bukti belum cukup':($e->state==='expired'?'Masa simpan berakhir':'Menunggu pengesahan'));
        $isfailed=($failed['evidenceid']??0)==$e->id;$scores=$isfailed?$failed['scores']:($decision['scores']??($body['scores']??[]));
        echo '<details class="mt-3" '.($isfailed?'open':'').'><summary>'.s(fullname($student)).' — '.$status.'</summary>';
        if ($decision) {echo '<p>Nilai rubrik yang disahkan: '.format_float($decision['percent']??0,5,true,true).'/100. Nilai tugas mengikuti skala Gradebook aktivitas.</p>';}
        foreach ($body['missing']??[] as $missing) {echo '<p class="alert alert-warning">'.s($missing).'</p>';}
        $canapprove=!in_array($e->state,['insufficient','expired'],true)&&empty($body['missing'])&&!empty($body['quotes'])&&!empty($body['rubric']['criteria']);
        if ($canapprove) {echo '<form method="post" data-obe-score-form>'.local_obe_ai_hidden(['sesskey'=>sesskey(),'action'=>'approve','evidenceid'=>$e->id,'revision'=>$isfailed?$failed['revision']:$e->revision]);}
        foreach ($body['rubric']['criteria']??[] as $criterion) {
            $key=$criterion['id'];$id='obe-essay-'.$e->id.'-'.$key;$proposed=$body['scores'][$key]??null;
            echo '<h4 class="h6 mt-3">'.s($criterion['label']).'</h4><p>Bobot kriteria '.format_float($criterion['weight']*100,2,true,true).'% · Usulan: '.($proposed===null?'Belum ada usulan skor':format_float($proposed,5,true,true)).'</p>';
            foreach ($body['quotes']??[] as $quote) {if (($quote['criterion']??'')===$key) {echo '<blockquote>'.nl2br(s($quote['text'])).'</blockquote>';}}
            if ($canapprove) {echo '<label for="'.$id.'">Skor '.s($criterion['label']).' (0–100)</label><input class="form-control obe-score-input" type="number" min="0" max="100" step="any" id="'.$id.'" name="scores['.s($key).']" value="'.s($scores[$key]??'').'" data-weight="'.s($criterion['weight']).'" data-required="'.(($criterion['required']??true)?1:0).'" '.(($criterion['required']??true)?'required':'').'>';}
        }
        if ($canapprove) {
            $stale=$isfailed&&(int)$failed['revision']!==(int)$e->revision;
            if ($stale) {echo '<p class="alert alert-warning">Usulan sudah berubah. Salin isian bila perlu, lalu muat ulang sebelum menyimpan.</p>';}
            echo '<p role="status" aria-live="polite">Pratinjau nilai rubrik: <output data-obe-preview>Lengkapi skor</output>/100</p><label for="obe-essay-reason-'.$e->id.'">Alasan pengesahan atau koreksi</label><textarea class="form-control mb-2" id="obe-essay-reason-'.$e->id.'" name="reason" maxlength="4000" required>'.s($isfailed?$failed['reason']:'').'</textarea><button class="btn btn-primary" '.($stale?'disabled':'').'>'.($decision?'Simpan koreksi nilai':'Sahkan nilai ke Gradebook tugas').'</button></form>';
        }
        echo '<details><summary>Riwayat penilaian</summary>';foreach ($DB->get_records('local_obe_ai_decision',['evidenceid'=>$e->id],'id ASC') as $history) {$record=json_decode($history->body,true);echo '<p>Keputusan #'.$history->id.' — Nilai rubrik '.s($record['percent']??'').' — '.s($record['reason']??'').'</p>';}echo '</details>';
        echo '<details><summary>Detail teknis</summary><pre tabindex="0">'.s(json_encode($body,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)).'</pre></details></details>';
    }
    echo '</section>';
}
echo '</div>'.$OUTPUT->footer();
