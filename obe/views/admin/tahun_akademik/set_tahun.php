<?php
// File: local/obe/views/admin/tahun_akademik/set_tahun.php

require_once(__DIR__ . '/../../../../../config.php');
require_login();
require_sesskey();

use local_obe\manager\tahun_akademik_manager;

$id_tahun = optional_param('id_tahun', 0, PARAM_INT);
// DINAMIS: Tangkap halaman operasional asal saat dropdown diubah
$halaman_asal = optional_param('halaman_asal', 'dashboard', PARAM_ALPHAEXT);

global $SESSION, $DB;

if ($id_tahun == 0) {
    // Menampilkan semua atau mode global (default)
    unset($SESSION->obe_id_tahun_akademik);
    unset($SESSION->obe_tahun_akademik);
} else {
    // Simpan ke session
    $tahun = $DB->get_record('local_obe_tahun_akademik', ['id' => $id_tahun]);
    if ($tahun) {
        $SESSION->obe_id_tahun_akademik = $tahun->id;
        $SESSION->obe_tahun_akademik = $tahun->nama_tahun;
    }
}

// REDIRECT DINAMIS: Mengembalikan user ke halaman semula mereka berada, bukan dipaksa ke tahun_akademik
redirect(new moodle_url('/local/obe/views/admin/dashboard.php', ['halaman' => $halaman_asal]));