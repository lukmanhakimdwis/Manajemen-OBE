<?php
// Synthetic staging only. Do not run this against production.
define('CLI_SCRIPT',true);
require(__DIR__.'/../../../config.php');
require_once($CFG->libdir.'/clilib.php');require_once($CFG->dirroot.'/course/lib.php');require_once($CFG->dirroot.'/course/modlib.php');require_once($CFG->dirroot.'/user/lib.php');require_once($CFG->libdir.'/gradelib.php');
if (getenv('OBE_SYNTHETIC_STAGING')!=='YES') {cli_error('Tetapkan OBE_SYNTHETIC_STAGING=YES hanya pada staging khusus.');}
\core\session\manager::set_user(get_admin());
if (!get_config('core_competency','enabled')) {cli_error('Aktifkan Competencies dari administrasi Moodle terlebih dahulu.');}
if ($DB->record_exists('local_obe_prodi',['kode_prodi'=>'AI-PILOT-TRPL'])) {cli_error('Data demo sudah ada. Seed tidak menimpa data; gunakan kembali kursus demo.');}
$tx=$DB->start_delegated_transaction();
$prodi=\local_obe\manager\prodi_manager::simpan_prodi((object)['kode_prodi'=>'AI-PILOT-TRPL','nama_prodi'=>'TRPL — Pilot sintetis AI','keterangan'=>'DATA SINTETIS STAGING','userid_kaprodi'=>0]);
$year=$DB->insert_record('local_obe_tahun_akademik',(object)['nama_tahun'=>'2026/2027-Pilot','status_aktif'=>0]);
$angkatan=\local_obe\manager\angkatan_manager::simpan((object)['id_prodi'=>$prodi,'tahun_masuk'=>2026,'nama_angkatan'=>'Pilot sintetis','status_aktif'=>1]);
$mk=\local_obe\manager\matakuliah_manager::simpan((object)['id_prodi'=>$prodi,'kode_mk'=>'AI-PILOT-TEST','nama_mk'=>'Pengujian Perangkat Lunak TRPL','sks_mk'=>3]);
$category=$DB->get_record('course_categories',null,'*',IGNORE_MULTIPLE);if (!$category) {cli_error('Sediakan satu kategori kursus staging.');}
$course=create_course((object)['fullname'=>'Pengujian Perangkat Lunak TRPL — AI pilot','shortname'=>'AI-PILOT-TRPL','category'=>$category->id,'visible'=>1]);
\local_obe\manager\relasikelas_manager::simpan((object)['id_mata_kuliah'=>$mk,'id_kursus_moodle'=>$course->id,'id_angkatan'=>$angkatan,'id_tahun_akademik'=>$year,'waktu_koneksi'=>time()]);
$scale=new grade_scale();$scale->name='AI pilot kompetensi';$scale->courseid=0;$scale->userid=get_admin()->id;$scale->scale='Belum,Tercapai';$scale->description='Skala framework sintetis';$scaleid=$scale->insert();
$framework=\core_competency\api::create_framework((object)['shortname'=>'AI-PILOT-TRPL','idnumber'=>'AI-PILOT-TRPL','description'=>'Framework sintetis untuk staging','descriptionformat'=>FORMAT_PLAIN,'contextid'=>context_system::instance()->id,'visible'=>1,'scaleid'=>$scaleid,
    'scaleconfiguration'=>json_encode([['scaleid'=>(string)$scaleid],['id'=>1,'scaledefault'=>1,'proficient'=>0],['id'=>2,'scaledefault'=>0,'proficient'=>1]])]);
$fid=$framework->get('id');\local_obe\manager\prodiframework_manager::simpan((object)['id_prodi'=>$prodi,'frameworkid'=>$fid,'id_tahun_akademik'=>$year]);
$parent=0;
foreach (['CPL','INDIKATOR','CPMK','SUB-CPMK'] as $type) {
    $c=\core_competency\api::create_competency((object)['competencyframeworkid'=>$fid,'parentid'=>$parent,'shortname'=>$type.' pengujian stok','idnumber'=>'AI-PILOT-'.$type,'description'=>'Kasus batas pengurangan stok','descriptionformat'=>FORMAT_PLAIN]);
    $parent=$c->get('id');
}
\local_obe\manager\kompetensi_manager::sync_competencies($prodi,$year);
$ids=[];foreach ($DB->get_records('local_obe_kompetensi',['id_prodi'=>$prodi,'id_tahun_akademik'=>$year]) as $c) {
    $ids[$c->tipe]=$c->id;if ($c->parent_id) {\local_obe\manager\bobot_relasi_manager::save_bobot($c->id,100,$year);}
}
foreach (['CPL','INDIKATOR','CPMK','SUB-CPMK'] as $type) {
    foreach ([[0,74.99,0,'B'],[75,100,1,'A']] as $v) {
        \local_obe\manager\skala_nilai_manager::tambah((object)['id_prodi'=>$prodi,'id_tahun_akademik'=>$year,'tipe_kompetensi'=>$type,'huruf_nilai'=>$v[3],'nilai_min'=>$v[0],'nilai_max'=>$v[1],'status_kompeten'=>$v[2]]);
    }
}
$passwords=[];$users=[];
foreach (['obe_pilot_dosen'=>'editingteacher','obe_pilot_mahasiswa'=>'student','obe_pilot_mahasiswa2'=>'student'] as $name=>$role) {
    if ($DB->record_exists('user',['username'=>$name,'mnethostid'=>$CFG->mnet_localhost_id])) {cli_error('Username demo sudah dipakai: '.$name);}
    $pw=bin2hex(random_bytes(16)).'Aa!';$uid=user_create_user((object)['username'=>$name,'password'=>$pw,'auth'=>'manual','confirmed'=>1,'firstname'=>$role==='student'?'Mahasiswa':'Dosen','lastname'=>'Pilot sintetis','email'=>$name.'@example.invalid','mnethostid'=>$CFG->mnet_localhost_id,'lang'=>'id']);
    $manual=enrol_get_plugin('manual');$instances=enrol_get_instances($course->id,false);$instance=null;
    foreach ($instances as $i) {if ($i->enrol==='manual') {$instance=$i;break;}}
    if (!$instance) {$iid=$manual->add_instance($course);$instance=$DB->get_record('enrol',['id'=>$iid],'*',MUST_EXIST);}
    $roleid=$DB->get_field('role','id',['shortname'=>$role],MUST_EXIST);$manual->enrol_user($instance,$uid,$roleid);$passwords[$name]=$pw;$users[$name]=$uid;
}
$moduleid=$DB->get_field('modules','id',['name'=>'obeinterview'],MUST_EXIST);
$module=(object)['modulename'=>'obeinterview','module'=>$moduleid,'course'=>$course->id,'section'=>0,'visible'=>1,'groupmode'=>0,'groupingid'=>0,'completion'=>0,'showdescription'=>1,'name'=>'Wawancara pengujian stok — simulasi',
    'introeditor'=>['text'=>'Jelaskan kasus batas dan assertion pengurangan stok.','format'=>FORMAT_PLAIN,'itemid'=>0],
    'focusid'=>$ids['INDIKATOR'],'rubricjson'=>json_encode(\local_obe\ai\rubric::defaults()),'corequestion'=>'Kasus batas apa yang Anda uji?','duration'=>600,'maxfollow'=>2,'retentiondays'=>180,'projectcmid'=>0];
$created=add_moduleinfo($module,$course);
\local_obe\manager\aktivitasnilai_manager::insert($created->coursemodule,$ids['SUB-CPMK'],100,$course->id);
$DB->set_field('obeinterview','ownerid',$users['obe_pilot_dosen'],['id'=>$created->instance]);
$tx->allow_commit();
cli_writeln('Course ID '.$course->id.'; cmid '.$created->coursemodule.'; prodi '.$prodi.'; tahun '.$year);
cli_writeln('Simpan password berikut secara privat. Jangan masukkan ke log publik.');
foreach ($passwords as $name=>$password) {cli_writeln($name.' '.$password);}
cli_writeln('Seed belum membuat lab, hasil PHPUnit, nilai final atau panggilan Codex resmi.');
