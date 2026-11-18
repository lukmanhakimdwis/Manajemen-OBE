<?php
// File: local/obe/settings.php
defined('MOODLE_INTERNAL') || die();

if (has_capability('local/obe:manage', context_system::instance())) {
    $ADMIN->add('localplugins', new admin_externalpage(
        'local_obe_admin_entry',
        'Manajemen OBE',
        new moodle_url('/local/obe/views/admin/dashboard.php'),
        'local/obe:manage'
    ));
}

if ($hassiteconfig) {
    $settings=new admin_settingpage('local_obe_ai','OBE · Pengaturan AI staging');
    $ADMIN->add('localplugins',$settings);
    $settings->add(new admin_setting_configtextarea('local_obe/replacementpolicy','Kebijakan penggantian aspek bernilai','Kosong berarti penggantian aspek bernilai/berriwayat ditolak server. Pengelola harus mencatat dasar persetujuan revisi capaian aktif seluruh mahasiswa, penanganan capaian terbit, dan agregasi item Gradebook lama. Gunakan kelas/versi kurikulum baru jika revisi historis tidak diizinkan.','',PARAM_TEXT));
    $settings->add(new admin_setting_configtext('local_obe/aiworkerroot','Direktori worker Python','Path backend tepercaya; kosong memakai fixture PHP SIMULASI. Bukan path kiriman mahasiswa.','',PARAM_RAW_TRIMMED));
    $settings->add(new admin_setting_configtext('local_obe/aievidencedays','Masa simpan draf/bukti nonwawancara (hari)','Isi sensitif dibersihkan cron; default perlu ditetapkan kampus.',180,PARAM_INT));
    $settings->add(new admin_setting_configselect('local_obe/aiprovider','Penyedia','OAuth dosen/izin plan usage belum tersedia; API berbayar tidak diaktifkan.','simulation',['simulation'=>'SIMULASI — tanpa inferensi AI']));
}
