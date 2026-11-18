<?php
defined('MOODLE_INTERNAL') || die();
global $DB,$SESSION;
$program=\local_obe\access_guard::get_kaprodi_prodi();
if (!$program && optional_param('prodi',0,PARAM_INT)>0) {$program=$DB->get_record('local_obe_prodi',['id'=>optional_param('prodi',0,PARAM_INT)],'*',MUST_EXIST);}
if (!$program) {
    $choices=[];foreach ($DB->get_records('local_obe_prodi',[],'nama_prodi ASC') as $p) {$choices[$p->id]=$p->nama_prodi;}
    echo '<form method="get" class="mb-3"><input type="hidden" name="halaman" value="beranda">'.html_writer::label('Program studi','obe-home-prodi').html_writer::select($choices,'prodi',0,'Pilih program studi',['id'=>'obe-home-prodi']).'<button class="btn btn-primary ml-2">Tampilkan kesiapan</button></form>';
}
$state=[];$year=(int)($SESSION->obe_id_tahun_akademik??0);
if ($program && $year) {
    $pid=(int)$program->id;
    $framework=$DB->get_record('local_obe_prodi_framework',['id_prodi'=>$pid,'id_tahun_akademik'=>$year]);
    $state=['kelola_mk'=>$DB->record_exists('local_obe_mata_kuliah',['id_prodi'=>$pid])&&$DB->record_exists('local_obe_angkatan',['id_prodi'=>$pid]),
        'kelola_relasi'=>$DB->record_exists_sql('SELECT rk.id FROM {local_obe_relasi_kelas} rk JOIN {local_obe_mata_kuliah} mk ON mk.id=rk.id_mata_kuliah WHERE mk.id_prodi=:prodi AND rk.id_tahun_akademik=:tahun',['prodi'=>$pid,'tahun'=>$year]),
        'kelola_framework'=>(bool)$framework,
        'manajemen_kompetensi'=>$framework&&$DB->record_exists('local_obe_kompetensi',['id_prodi'=>$pid,'id_tahun_akademik'=>$year,'frameworkid'=>$framework->frameworkid]),
        'bobot_relasi'=>$DB->record_exists_sql('SELECT b.id FROM {local_obe_bobot_relasi} b JOIN {local_obe_kompetensi} k ON k.id=b.id_kompetensi_asal WHERE k.id_prodi=:prodi AND k.id_tahun_akademik=:tahun',['prodi'=>$pid,'tahun'=>$year]),
        'skala_nilai'=>$DB->record_exists('local_obe_skala_nilai',['id_prodi'=>$pid,'id_tahun_akademik'=>$year])];
}
echo '<h2>OBE Program Studi</h2><p>Pilih prodi dan tahun, siapkan struktur kompetensi, lalu periksa capaian.</p>';
if ($program) {echo html_writer::tag('p','Program studi: '.s($program->nama_prodi));}
echo \local_obe\local\ux::glossary();
$steps=[['kelola_mk','1. Mata kuliah dan angkatan','local/obe:manageprogram'],['kelola_relasi','2. Hubungkan kelas Moodle','local/obe:manageprogram'],
    ['kelola_framework','3. Hubungkan kerangka kompetensi','local/obe:manageprogram'],['manajemen_kompetensi','4. Sinkronkan CPL hingga Sub-CPMK','local/obe:manageprogram'],
    ['bobot_relasi','5. Periksa bobot kompetensi','local/obe:manageprogram'],['skala_nilai','6. Periksa skala capaian','local/obe:configprogramscale'],
    ['capaian_pembelajaran_prodi','Lihat capaian prodi','local/obe:viewprogramreport']];
echo '<ol class="obe-setup-steps">';
foreach ($steps as [$route,$label,$cap]) {
    if (!\local_obe\access_guard::is_system_manager()&&!has_capability($cap,context_system::instance())) {continue;}
    $args=['halaman'=>$route];if ($program) {$args['prodi']=$program->id;$args['filter_prodi']=$program->id;}
    echo '<li><p>'.s(!$program?'Pilih program studi':(!$year?'Pilih tahun akademik':(!isset($state[$route])?'Laporan':($state[$route]?'Data tersedia — periksa kelengkapan':'Perlu disiapkan')))).'</p>';
    echo html_writer::link(new moodle_url('/local/obe/views/admin/dashboard.php',$args),$label,['class'=>'btn btn-outline-primary mb-2']).'</li>';
}
echo '</ol><p class="text-muted">Kerangka kompetensi disusun melalui pengelolaan kompetensi Moodle oleh pengguna yang berwenang. Jika akses belum tersedia, minta administrator menyiapkannya; hak staf prodi mengikuti penugasan yang sudah berlaku.</p>';
