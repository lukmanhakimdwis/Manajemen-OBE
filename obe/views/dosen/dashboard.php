<?php
// File: /local/obe/views/dosen/dashboard.php
require_once(__DIR__ . '/../../../../config.php');
require_once(__DIR__ . '/../../lib.php');

use local_obe\manager\aktivitasnilai_manager;

// =====================================================
// PARAMETER
// =====================================================
$courseid = optional_param('id', optional_param('id_course', 0, PARAM_INT), PARAM_INT);
$halaman  = optional_param('halaman', 'beranda', PARAM_ALPHAEXT);

if ($courseid <= 0) {
    throw new moodle_exception('Parameter course tidak ditemukan.');
}

// =====================================================
// VALIDASI COURSE
// =====================================================
list($course, $context) = \local_obe\access_guard::require_course_report($courseid);

// =====================================================
// AMBIL RELASI KELAS & SET SESSKEY TAHUN AKADEMIK
// =====================================================
$relasi_kelas = $DB->get_record('local_obe_relasi_kelas', ['id_kursus_moodle' => $courseid]);

if ($relasi_kelas && !empty($relasi_kelas->id_tahun_akademik)) {
    $SESSION->obe_id_tahun_akademik = $relasi_kelas->id_tahun_akademik;
}

// =====================================================
// SETUP PAGE ENVIRONMENT MOODLE
// =====================================================
$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/obe/views/dosen/dashboard.php', ['id' => $courseid, 'halaman' => $halaman]));
$PAGE->set_title('Manajemen OBE - ' . format_string($course->fullname));
$PAGE->set_heading(format_string($course->fullname));

$canmanage=has_capability('local/obe:managecourse',$context);
if ($halaman==='aktivitas_nilai') {require_capability('local/obe:managecourse',$context);}

// =====================================================
// VALIDASI KURSUS MOODLE SUDAH TERPETAKAN OLEH ADMIN
// =====================================================
if ($halaman !== 'beranda' && !aktivitasnilai_manager::is_course_mapped($courseid)) {
    echo $OUTPUT->header();
    echo $OUTPUT->notification('Mata kuliah ini belum dihubungkan dengan data OBE. Silakan hubungi Admin.', 'error');
    echo $OUTPUT->footer();
    return;
}

// =====================================================
// PROSES AKSI BACKEND FORM (SEBELUM OUTPUT BUFFER)
// =====================================================
if ($halaman === 'aktivitas_nilai' && optional_param('aksi', '', PARAM_ALPHA) === 'simpan') {
    include(__DIR__ . '/aktivitas_nilai/proses_aktivitasnilai.php');
}

// =====================================================
// OUTPUT BUFFERING UNTUK RENDERING KONTEN INTERNAL
// =====================================================
ob_start();
echo \local_obe\local\ux::course_links($courseid);

switch ($halaman) {
    // -------------------------------------------------
    // PEMETAAN AKTIVITAS & BOBOT INSTRUMEN PENILAIAN
    // -------------------------------------------------
    case 'aktivitas_nilai':
        $aksi = optional_param('aksi', '', PARAM_ALPHA);
        if ($aksi === 'tambah' || $aksi === 'edit') {
            include(__DIR__ . '/aktivitas_nilai/form_pemetaan.php');
        } else {
            include(__DIR__ . '/aktivitas_nilai/list_aktivitasnilai.php');
        }
        break;

    // -------------------------------------------------
    // MODUL CAPAIAN LAPORAN OBE (SHARE VIEW DENGAN ADMIN)
    // -------------------------------------------------
    case 'laporan':
        $mode_view = 'dosen';
        $sub = optional_param('sub', 'cpl', PARAM_ALPHAEXT);

        switch ($sub) {
            case 'cpl':
                $path_laporan = $CFG->dirroot . '/local/obe/views/admin/capaian_kelas/list_cpl.php';
                break;
            case 'indikator':
                $path_laporan = $CFG->dirroot . '/local/obe/views/admin/capaian_kelas/list_indikator.php';
                break;
            case 'cpmk':
                $path_laporan = $CFG->dirroot . '/local/obe/views/admin/capaian_kelas/list_cpmk.php';
                break;
            case 'subcpmk':
                $path_laporan = $CFG->dirroot . '/local/obe/views/admin/capaian_kelas/list_subcpmk.php';
                break;
            case 'detail_nilai_cpl':
                $path_laporan = $CFG->dirroot . '/local/obe/views/admin/capaian_kelas/detail_nilai_cpl.php';
                break;
            case 'detail_nilai_indikator':
                $path_laporan = $CFG->dirroot . '/local/obe/views/admin/capaian_kelas/detail_nilai_indikator.php';
                break;
            case 'detail_nilai_cpmk':
                $path_laporan = $CFG->dirroot . '/local/obe/views/admin/capaian_kelas/detail_nilai_cpmk.php';
                break;
            case 'detail_mahasiswa':
                $path_laporan = $CFG->dirroot . '/local/obe/views/admin/capaian_kelas/detail_nilai_subcpmk.php';
                break;
            default:
                $path_laporan = $CFG->dirroot . '/local/obe/views/admin/capaian_kelas/list_cpl.php';
                break;
        }

        if (file_exists($path_laporan)) {
            include_once($path_laporan);
        } else {
            echo "<div class='alert alert-danger'>File laporan tidak ditemukan: " . s($path_laporan) . "</div>";
        }
        break;

    // -------------------------------------------------
    // HALAMAN DEFAULT (LANDING BERANDA DOSEN)
    // -------------------------------------------------
    default:
        echo '<h2>OBE Kelas</h2><p>Siapkan aktivitas, periksa penilaian, lalu telusuri capaian pembelajaran.</p>';
        echo \local_obe\local\ux::glossary();
        if (!$relasi_kelas) {echo $OUTPUT->notification('Kelas belum terhubung dengan prodi dan tahun akademik. Minta administrator OBE menyiapkan relasi kelas.','warning');}
        if ($canmanage) {echo html_writer::link(new moodle_url('/local/obe/views/dosen/dashboard.php',['id'=>$courseid,'halaman'=>'aktivitas_nilai']),'1. Pemetaan aktivitas dan bobot',['class'=>'btn btn-primary mr-2 mb-2']);}
        echo html_writer::link(new moodle_url('/local/obe/views/dosen/dashboard.php',['id'=>$courseid,'halaman'=>'laporan']),'Lihat capaian kelas',['class'=>'btn btn-outline-primary mr-2 mb-2']);
        if ($relasi_kelas) {foreach (aktivitasnilai_manager::ambil_semua_pemetaan($courseid) as $entry) {
            if ($entry->modulename==='obeinterview' && has_capability('mod/obeinterview:grade',context_module::instance($entry->cmid)) && has_capability('local/obe:approveai',$context)) {
                echo html_writer::tag('p',html_writer::link(new moodle_url('/mod/obeinterview/view.php',['id'=>$entry->cmid]),'Periksa penilaian: '.s($entry->name),['class'=>'btn btn-outline-primary']));
            }
        }}
        if ($canmanage && has_capability('local/obe:useai',$context)) {
            echo html_writer::link(new moodle_url('/local/obe/ai/dashboard.php',['courseid'=>$courseid]),'Draf soal dan bukti AI (staging)',['class'=>'btn btn-primary']);
        }
        break;
}

$isi_konten = ob_get_clean();

// =====================================================
// ASSEMBLE LAYOUT UTAMA TATA LETAK
// =====================================================
local_obe_render_tata_letak($isi_konten, $halaman);