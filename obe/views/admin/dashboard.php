<?php
// File: local/obe/views/admin/dashboard.php

require_once(__DIR__ . '/../../../../config.php'); 
require_once(__DIR__ . '/../../lib.php');        

require_login();

global $USER, $SESSION, $CFG, $PAGE, $DB;

$systemcontext = context_system::instance();
$PAGE->set_context($systemcontext);

$prodi_aktif_dashboard = \local_obe\access_guard::require_admin_or_kaprodi();
$is_obe_admin = \local_obe\access_guard::is_system_manager();

// =====================================================
// IDENTIFIKASI DAN CACHING PRODI DOSEN/KAPRODI VIA SESSION
// =====================================================
$prodi_kaprodi = \local_obe\manager\prodi_manager::ambil_prodi_kaprodi($USER->id);

if ($prodi_kaprodi) {
    $SESSION->obe_prodi_kaprodi = $prodi_kaprodi->id;
} else {
    unset($SESSION->obe_prodi_kaprodi);
}

// 1. SETUP PAGE ENVIRONMENT MOODLE
$PAGE->set_url(new moodle_url('/local/obe/views/admin/dashboard.php'));
$PAGE->set_context($systemcontext);
$PAGE->set_title('Dashboard OBE');
$PAGE->set_heading('Sistem Informasi OBE');

// 2. TANGKAP PARAMETER REQUEST
$halaman = optional_param('halaman', 'beranda', PARAM_ALPHAEXT);
$sub     = optional_param('sub', 'list', PARAM_ALPHA);
$aksi    = optional_param('aksi', '', PARAM_ALPHA);

// =====================================================
// 3. SEKURITI GATE: RBAC + PEMBATASAN KAPRODI
// =====================================================
$is_kaprodi = (!$is_obe_admin && !empty($prodi_aktif_dashboard));

// Modul global hanya boleh dikelola oleh role dengan capability local/obe:manage.
$halaman_admin_only = [
    'kelola_prodi',
    'audit',
    'tahun_akademik',
];

if (!$is_obe_admin && in_array($halaman, $halaman_admin_only, true)) {
    throw new moodle_exception(
        'aksesditolak',
        'local_obe',
        '',
        null,
        'Menu ini hanya dapat diakses Administrator OBE.'
    );
}

// Kaprodi wajib punya capability spesifik sesuai menu, bukan hanya tercatat sebagai userid_kaprodi.
$halaman_capability_kaprodi = [
    'kelola_mk' => 'local/obe:manageprogram',
    'kelola_angkatan' => 'local/obe:manageprogram',
    'kelola_relasi' => 'local/obe:manageprogram',
    'kelola_framework'                  => 'local/obe:manageprogram',
    'manajemen_kompetensi'              => 'local/obe:manageprogram',
    'bobot_relasi'                      => 'local/obe:manageprogram',
    'skala_nilai'                       => 'local/obe:configprogramscale',
    'capaian_pembelajaran_kelas'        => 'local/obe:viewprogramreport',
    'capaian_pembelajaran_matakuliah'   => 'local/obe:viewprogramreport',
    'capaian_pembelajaran_prodi'        => 'local/obe:viewprogramreport',
    'capaian_pembelajaran_mahasiswa'     => 'local/obe:viewprogramreport',
    
];

if (!$is_obe_admin && isset($halaman_capability_kaprodi[$halaman])) {
    require_capability($halaman_capability_kaprodi[$halaman], $systemcontext);
}

// Kaprodi hanya boleh melihat/mengelola data prodi sendiri.
if ($is_kaprodi) {
    $_GET['prodi'] = (int)$prodi_aktif_dashboard->id;
    $_POST['prodi'] = (int)$prodi_aktif_dashboard->id;
}

// =====================================================
// 4. PROSES AKSI DATA (DILAKUKAN SEBELUM BUFFERING LAYOUT)
// =====================================================
if (!empty($aksi)) {
    switch ($halaman) {
        case 'tahun_akademik':
            include(__DIR__ . '/tahun_akademik/proses.php');
            break;
        case 'kelola_prodi':
            if (!$is_obe_admin) {
                throw new moodle_exception(
                    'aksesditolak',
                    'local_obe'
                );
            }
            include(__DIR__ . '/prodi/proses_prodi.php');
            break;

        case 'kelola_mk':
            include(__DIR__ . '/matakuliah/proses_matakuliah.php');
            break;
        case 'kelola_angkatan':
            include(__DIR__ . '/angkatan/proses_angkatan.php');
            break;
        case 'kelola_relasi':
            include(__DIR__ . '/relasikelas/proses_relasikelas.php');
            break;
        case 'kelola_framework':
            include(__DIR__ . '/framework/proses_framework.php');
            break;
        case 'manajemen_kompetensi':
            include(__DIR__ . '/kompetensi/proses_kompetensi.php');
            break;
        case 'bobot_relasi':
            include(__DIR__ . '/bobot_relasi/proses_relasi.php');
            break;
        case 'skala_nilai':
            include(__DIR__ . '/skala_nilai/proses_skala.php');
            break;
        default:
            break;
    }
}

// =====================================================
// 5. BUFFERING RENDER VIEW LAYOUT
// =====================================================
ob_start(); 

// Panggil komponen pemilih Tahun Akademik aktif global
require_once($CFG->dirroot . '/local/obe/views/admin/tahun_akademik/switcher.php');

switch ($halaman) {
    case 'beranda':
    case 'dashboard':
        include(__DIR__.'/beranda.php');
        break;
    case 'tahun_akademik':
        $file_target = ($sub === 'form') ? 'form_tahun_akademik.php' : 'list_tahun_akademik.php';
        $path = __DIR__ . '/tahun_akademik/' . $file_target;
        if (file_exists($path)) { include($path); } else { echo "<div class='alert alert-danger'>File tidak ditemukan.</div>"; }
        break;

   case 'kelola_prodi':

    if (!$is_obe_admin) {
        throw new moodle_exception(
            'aksesditolak',
            'local_obe'
        );
    }

    $file_target = ($sub === 'form')
        ? 'form_prodi.php'
        : 'list_prodi.php';

    $path = __DIR__ . '/prodi/' . $file_target;

    if (file_exists($path)) {
        include($path);
    } else {
        echo "<div class='alert alert-danger'>File tidak ditemukan.</div>";
    }

    break;

    case 'kelola_mk':
        $file_target = ($sub === 'form') ? 'form_matakuliah.php' : 'list_matakuliah.php';
        $path = __DIR__ . '/matakuliah/' . $file_target;
        if (file_exists($path)) { include($path); } else { echo "<div class='alert alert-danger'>File tidak ditemukan.</div>"; }
        break;
        
    case 'kelola_angkatan':
        $file_target = ($sub === 'form') ? 'form_angkatan.php' : 'list_angkatan.php';
        $path = __DIR__ . '/angkatan/' . $file_target;
        if (file_exists($path)) { include($path); } else { echo "<div class='alert alert-danger'>File tidak ditemukan.</div>"; }
        break;

    case 'kelola_relasi':
        $file_target = ($sub === 'form') ? 'form_relasikelas.php' : 'list_relasikelas.php';
        $path = __DIR__ . '/relasikelas/' . $file_target;
        if (file_exists($path)) { include($path); } else { echo "<div class='alert alert-danger'>File tidak ditemukan.</div>"; }
        break;
        
    case 'kelola_framework':
        $file_target = ($sub === 'form') ? 'form_framework.php' : 'list_framework.php';
        $path = __DIR__ . '/framework/' . $file_target;
        if (file_exists($path)) { include($path); } else { echo "<div class='alert alert-danger'>File tidak ditemukan.</div>"; }
        break;

    case 'manajemen_kompetensi':
        $file_target = ($sub === 'form') ? 'form_kompetensi.php' : 'list_kompetensi.php';
        $path = __DIR__ . '/kompetensi/' . $file_target;
        if (file_exists($path)) { include($path); } else { echo "<div class='alert alert-danger'>File tidak ditemukan.</div>"; }
        break;
    
    case 'skala_nilai':
        $file_target = ($sub === 'form') ? 'form_skala.php' : 'list_skala_nilai.php';
        $path = __DIR__ . '/skala_nilai/' . $file_target;
        if (file_exists($path)) { include($path); } else { echo "<div class='alert alert-danger'>File tidak ditemukan.</div>"; }
        break;
        
    case 'bobot_relasi':
        $file_target = ($sub === 'detail') ? 'detail.php' : 'list_relasi.php';
        $path = __DIR__ . '/bobot_relasi/' . $file_target;
        if (file_exists($path)) { include($path); } else { echo "<div class='alert alert-danger'>File tidak ditemukan.</div>"; }
        break;

    case 'capaian_pembelajaran_kelas': 
        $sub_kelas = optional_param('sub', 'default', PARAM_ALPHAEXT);
        switch ($sub_kelas) {
            case 'detail_mahasiswa':       $file_target = 'detail_nilai_subcpmk.php'; break;
            case 'cpl':                    $file_target = 'list_cpl.php'; break;
            case 'indikator':              $file_target = 'list_indikator.php'; break;
            case 'cpmk':                   $file_target = 'list_cpmk.php'; break;
            case 'subcpmk':                $file_target = 'list_subcpmk.php'; break;
            case 'detail_nilai_cpl':       $file_target = 'detail_nilai_cpl.php'; break;
            case 'detail_nilai_indikator': $file_target = 'detail_nilai_indikator.php'; break;
            case 'detail_nilai_cpmk':      $file_target = 'detail_nilai_cpmk.php'; break;
            case 'grafik_cpl':                  $file_target = 'grafik_cpl.php'; break;
            case 'grafik_indikator':              $file_target = 'grafik_indikator.php'; break;
            case 'grafik_cpmk':                   $file_target = 'grafik_cpmk.php'; break;
            case 'grafik_subcpmk':                $file_target = 'grafik_subcpmk.php'; break;
            default:                       $file_target = 'capaian_pembelajaran.php'; break;
        }
        $path = __DIR__ . '/capaian_kelas/' . $file_target;
        if (file_exists($path)) { include($path); } else { echo "<div class='alert alert-danger'>File tidak ditemukan.</div>"; }
        break;

    case 'capaian_pembelajaran_matakuliah':
        $sub_mk = optional_param('sub', 'cpl', PARAM_TEXT);
        switch ($sub_mk) {
            case 'detail_nilai_cpl':require_once(__DIR__ . '/capaianmatakuliah/detail_nilai_cpl.php'); break;
            case 'detail_nilai_indikator':require_once(__DIR__ . '/capaianmatakuliah/detail_nilai_indikator.php'); break;
            case 'detail_nilai_cpmk':      require_once(__DIR__ . '/capaianmatakuliah/detail_nilai_cpmk.php'); break;
            case 'detail_nilai_subcpmk':   require_once(__DIR__ . '/capaianmatakuliah/detail_nilai_subcpmk.php'); break;
            case 'grafik_cpl':            require_once(__DIR__ . '/capaianmatakuliah/grafik_cpl.php'); break;
            case 'grafik_indikator':      require_once(__DIR__ . '/capaianmatakuliah/grafik_indikator.php'); break;
            case 'grafik_cpmk':           require_once(__DIR__ . '/capaianmatakuliah/grafik_cpmk.php'); break;
            case 'grafik_subcpmk':        require_once(__DIR__ . '/capaianmatakuliah/grafik_subcpmk.php'); break;
            case 'subcpmk':   require_once(__DIR__ . '/capaianmatakuliah/list_subcpmk.php'); break;
            case 'cpmk':      require_once(__DIR__ . '/capaianmatakuliah/list_cpmk.php'); break;
            case 'indikator': require_once(__DIR__ . '/capaianmatakuliah/list_indikator.php'); break;
            case 'cpl':
            default:          require_once(__DIR__ . '/capaianmatakuliah/list_cpl.php'); break;
        }
        break;

    case 'capaian_pembelajaran_prodi':
        $sub_pr = optional_param('sub', 'cpl', PARAM_TEXT);
        switch ($sub_pr) {
            case 'detail_nilai_cpl':require_once(__DIR__ . '/capaianprodi/detail_nilai_cpl.php'); break;
            case 'detail_nilai_indikator':require_once(__DIR__ . '/capaianprodi/detail_nilai_indikator.php'); break;
            case 'detail_nilai_cpmk':      require_once(__DIR__ . '/capaianprodi/detail_nilai_cpmk.php'); break;
            case 'detail_nilai_subcpmk':   require_once(__DIR__ . '/capaianprodi/detail_nilai_subcpmk.php'); break;
            case 'grafik_cpl':            require_once(__DIR__ . '/capaianprodi/grafik_cpl.php'); break;
            case 'grafik_indikator':      require_once(__DIR__ . '/capaianprodi/grafik_indikator.php'); break;
            case 'grafik_cpmk':           require_once(__DIR__ . '/capaianprodi/grafik_cpmk.php'); break;
            case 'grafik_subcpmk':        require_once(__DIR__ . '/capaianprodi/grafik_subcpmk.php'); break;
            case 'subcpmk':   require_once(__DIR__ . '/capaianprodi/list_subcpmk.php'); break;
            case 'cpmk':      require_once(__DIR__ . '/capaianprodi/list_cpmk.php'); break;
            case 'indikator': require_once(__DIR__ . '/capaianprodi/list_indikator.php'); break;
            case 'cpl':
            default:          require_once(__DIR__ . '/capaianprodi/list_cpl.php'); break;
        }
        break;
        
    case 'capaian_pembelajaran_mahasiswa':
        $sub_mhs = optional_param('sub', 'list', PARAM_TEXT);
        switch ($sub_mhs) {
            case 'detail': require_once( __DIR__ . '/capaianmahasiswa/detail.php'); break;
            case 'list':
                 default: require_once( __DIR__ .'/capaianmahasiswa/list.php');break;
        }
        break;
    
    case 'audit':
        if (!$is_obe_admin) {
            throw new moodle_exception(
                'aksesditolak',
                'local_obe'
            );
        }
        $path = __DIR__ . '/audit/list.php';
        if (file_exists($path)) {
            include($path);
        } else {
            echo "<div class='alert alert-danger'>File audit/list.php tidak ditemukan.</div>";
        }

        break;
}

$isi_konten = ob_get_clean(); 

// 6. FINAL OUTPUT RENDERING
local_obe_render_tata_letak($isi_konten, $halaman);