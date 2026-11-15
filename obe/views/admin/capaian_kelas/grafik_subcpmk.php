<?php
// File: local/obe/views/admin/capaian_kelas/grafik_subcpmk.php
defined('MOODLE_INTERNAL') || die();

use local_obe\manager\capaian_kelas_manager;
use local_obe\manager\prodi_manager;

global $COURSE, $DB, $OUTPUT, $SESSION;

// =====================================================
// 1. TANGKAP PARAMETER ID & VALIDASI
// =====================================================
$courseid = optional_param('id_course', optional_param('id', 0, PARAM_INT), PARAM_INT);
if ($courseid <= 0 && isset($COURSE)) {
    $courseid = $COURSE->id;
}

$cpmk_id        = optional_param('cpmk_id', 0, PARAM_INT);
$indikator_id   = optional_param('indikator_id', 0, PARAM_INT);
$cpl_id         = optional_param('cpl_id', 0, PARAM_INT);
$tahun_akademik = $SESSION->obe_id_tahun_akademik ?? 0;

if (empty($cpmk_id)) {
    echo $OUTPUT->notification('ID CPMK tidak valid atau kosong.', 'error');
    return;
}

$cpmk_record = $DB->get_record('local_obe_kompetensi', ['id' => $cpmk_id], '*', MUST_EXIST);

// =====================================================
// 2. VALIDASI KEAMANAN & HAK AKSES
// =====================================================
$prodi_aktif = prodi_manager::get_prodi_aktif_user();
if ($prodi_aktif) {
    $sql_relasi = "SELECT rk.id FROM {local_obe_relasi_kelas} rk
                   JOIN {local_obe_mata_kuliah} mk ON mk.id = rk.id_mata_kuliah
                  WHERE rk.id_kursus_moodle = :courseid AND mk.id_prodi = :prodiid";
    if (!$DB->record_exists_sql($sql_relasi, ['courseid' => $courseid, 'prodiid' => $prodi_aktif->id])) {
        echo $OUTPUT->notification('Aksi ditolak! Anda tidak memiliki hak akses.', 'error');
        return;
    }
}

// =====================================================
// 3. MAP WARNA GRADE & PALETTE CADANGAN
// =====================================================
$map_warna_grade = [
    'A'  => '#28a745', // Hijau
    'AB' => '#17a2b8', // Teal
    'B'  => '#007bff', // Biru
    'BC' => '#6f42c1', // Ungu
    'C'  => '#ffc107', // Kuning
    'D'  => '#fd7e14', // Jingga
    'E'  => '#dc3545', // Merah
    '-'  => '#e9ecef', // Abu-abu
];

// =====================================================
// 4. PENARIKAN STRUKTUR SUB-CPMK & SKOR LANGSUNG
// =====================================================
$list_subcpmk = capaian_kelas_manager::ambil_struktur_kompetensi_kelas($cpmk_id, 'SUB-CPMK', $tahun_akademik);
if (empty($list_subcpmk)) {
    $list_subcpmk = capaian_kelas_manager::ambil_struktur_kompetensi_kelas($cpmk_id, 'SUB_CPMK', $tahun_akademik);
}

if (empty($list_subcpmk)) {
    echo $OUTPUT->notification('Belum ada data Sub-CPMK yang terhubung dengan CPMK ini.', 'warning');
    return;
}

$labels = [];
$subcpmk_scores = [];
$subcpmk_colors = [];

foreach ($list_subcpmk as $sub) {
    $labels[] = $sub->nama;
    
    // Ambil detail nilai rata-rata Sub-CPMK dari manager
    $detail_sub = capaian_kelas_manager::ambil_detail_kompetensi_mahasiswa($courseid, $sub->id, 'SUB-CPMK', $tahun_akademik);
    $ringkasan  = $detail_sub->ringkasan;
    
    $score = !empty($ringkasan->rata_rata) ? round($ringkasan->rata_rata, 2) : 0;
    $grade = !empty($ringkasan->grade) ? strtoupper(trim($ringkasan->grade)) : '-';
    
    $subcpmk_scores[] = $score;
    $subcpmk_colors[] = $map_warna_grade[$grade] ?? '#28a745';
}

// =====================================================
// 5. MEMBUAT DIAGRAM MOODLE CHART
// =====================================================
$chart = new \core\chart_bar();
$chart->set_title("Visualisasi Rata-Rata Capaian Sub-CPMK Kelas (%)");
$chart->set_labels($labels);

$series = new \core\chart_series('Capaian Sub CPMK', $subcpmk_scores);
$series->set_colors($subcpmk_colors);
$chart->add_series($series);

// KONFIGURASI SUMBU Y (0 - 100)
$yaxis = $chart->get_yaxis();
$yaxis->set_min(0);
$yaxis->set_max(100);

// =====================================================
// 6. SETUP URL NAVIGASI KEMBALI
// =====================================================
if (isset($mode_view) && $mode_view === 'dosen') {
    $url_kembali = new moodle_url('/local/obe/views/dosen/dashboard.php', [
        'id_course'    => $courseid,
        'halaman'      => 'laporan',
        'sub'          => 'subcpmk',
        'cpmk_id'      => $cpmk_id,
        'indikator_id' => $indikator_id,
        'cpl_id'       => $cpl_id
    ]);
} else {
    $url_kembali = new moodle_url('/local/obe/views/admin/dashboard.php', [
        'id'           => $courseid,
        'id_course'    => $courseid,
        'halaman'      => 'capaian_pembelajaran_kelas',
        'sub'          => 'subcpmk',
        'cpmk_id'      => $cpmk_id,
        'indikator_id' => $indikator_id,
        'cpl_id'       => $cpl_id
    ]);
}
?>

<!-- =====================================================
// 7. LAYOUT TAMPILAN
// ===================================================== -->
<div class="card shadow-sm mb-4 border-0">
    <div class="card-header bg-white py-3">
        <div class="d-flex justify-content-between align-items-center">
            <h5 class="mb-0 font-weight-bold text-dark">
                <i class="fa fa-bar-chart text-primary mr-2"></i>Grafik Capaian Sub-CPMK - CPMK: <?= s($cpmk_record->nama_kompetensi ?? $cpmk_record->nama ?? '-') ?>
            </h5>
            <a href="<?= $url_kembali->out() ?>" class="btn btn-secondary btn-sm font-weight-bold shadow-sm">
                <i class="fa fa-arrow-left mr-1"></i> Kembali
            </a>
        </div>
    </div>
    <div class="card-body p-4">
        <!-- Style CSS untuk menyembunyikan tabel bawaan Moodle Chart -->
        <style>
            .chart-table-container table,
            .chart-table-container p.secured,
            .chart-table-container + .sr-only,
            .chart-container + [id^="chart-table-description"],
            .chart-image-description,
            .chart-area + a,
            .chart-area + button,
            .chart-area ~ a[role="button"],
            .chart-area ~ a,
            a[aria-controls^="chart-table-"] {
                display: none !important;
            }
        </style>

        <div class="chart-table-container">
            <div class="chart-container text-center" style="position: relative; width:100%; min-height:450px;">
                <?php
                echo $OUTPUT->render($chart);
                ?>
            </div>
        </div>
    </div>
</div>