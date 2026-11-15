<?php
// File: local/obe/views/admin/capaianprodi/grafik_subcpmk.php
defined('MOODLE_INTERNAL') || die();

use local_obe\manager\capaian_prodi_manager;
use local_obe\manager\prodi_manager;

global $DB, $OUTPUT, $SESSION, $USER;

// =====================================================
// 1. CEK ROLE & TANGKAP PARAMETER
// =====================================================
$is_kaprodi = prodi_manager::is_kaprodi($USER->id);
$tahun_akademik = $SESSION->obe_id_tahun_akademik ?? 0;
$angkatan_id    = optional_param('angkatan', 0, PARAM_INT);
$cpmk_id        = optional_param('cpmk_id', 0, PARAM_INT);
$indikator_id   = optional_param('indikator_id', 0, PARAM_INT);
$cpl_id         = optional_param('cpl_id', 0, PARAM_INT);

if ($is_kaprodi) {
    $prodiaktif = prodi_manager::ambil_prodi_kaprodi($USER->id);
    if (!$prodiaktif) {
        throw new moodle_exception('invalidrecord', 'local_obe', '', null, 'Akun Kaprodi belum terhubung dengan Program Studi.');
    }
    $prodi_terpilih = $prodiaktif->id;
} else {
    $prodi_terpilih = optional_param('prodi', 0, PARAM_INT);
}

if (empty($prodi_terpilih) || empty($cpmk_id)) {
    echo $OUTPUT->notification('Parameter Program Studi atau CPMK belum lengkap.', 'error');
    return;
}

$prodi = $DB->get_record('local_obe_prodi', ['id' => $prodi_terpilih], '*', MUST_EXIST);
$cpmk  = $DB->get_record('local_obe_kompetensi', ['id' => $cpmk_id], '*', MUST_EXIST);

// =====================================================
// 2. MAP WARNA GRADE
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
// 3. PENARIKAN STRUKTUR SUB-CPMK PRODI
// =====================================================
$subcpmk_data = capaian_prodi_manager::ambil_capaian_subcpmk_prodi($prodi_terpilih, $cpmk_id, $tahun_akademik, $angkatan_id);

if (empty($subcpmk_data)) {
    echo $OUTPUT->notification('Belum ada data Sub-CPMK yang terhitung untuk CPMK ini.', 'warning');
    return;
}

$labels = [];
$subcpmk_scores = [];
$subcpmk_colors = [];

foreach ($subcpmk_data as $sub) {
    $nama_sub = !empty($sub->nama_subcpmk) ? $sub->nama_subcpmk : (!empty($sub->nama) ? $sub->nama : 'Sub-CPMK');
    $labels[] = $nama_sub;
    
    $score_raw = isset($sub->percentage) ? $sub->percentage : (isset($sub->rata_rata) ? $sub->rata_rata : 0);
    $score = round((float)$score_raw, 2);
    $grade = !empty($sub->grade) ? strtoupper(trim($sub->grade)) : (!empty($sub->grade_subcpmk) ? strtoupper(trim($sub->grade_subcpmk)) : '-');
    
    $subcpmk_scores[] = $score;
    $subcpmk_colors[] = $map_warna_grade[$grade] ?? '#28a745';
}

// =====================================================
// 4. MEMBUAT DIAGRAM MOODLE CHART
// =====================================================
$chart = new \core\chart_bar();
$chart->set_title("Visualisasi Rata-Rata Capaian Sub-CPMK Prodi (%)");
$chart->set_labels($labels);

$series = new \core\chart_series('Capaian Sub-CPMK', $subcpmk_scores);
$series->set_colors($subcpmk_colors);
$chart->add_series($series);

// KONFIGURASI SUMBU Y (0 SAMPAI 100%)
$yaxis = $chart->get_yaxis();
$yaxis->set_min(0);
$yaxis->set_max(100);

// =====================================================
// 5. SETUP URL NAVIGASI KEMBALI
// =====================================================
$url_kembali = new moodle_url('/local/obe/views/admin/dashboard.php', [
    'halaman'      => 'capaian_pembelajaran_prodi',
    'sub'          => 'subcpmk',
    'prodi'        => $prodi_terpilih,
    'angkatan'     => $angkatan_id,
    'cpl_id'       => $cpl_id,
    'indikator_id' => $indikator_id,
    'cpmk_id'      => $cpmk_id
]);
?>

<!-- =====================================================
// 6. LAYOUT TAMPILAN
// ===================================================== -->
<div class="card shadow-sm mb-4 border-0">
    <div class="card-header bg-white py-3">
        <div class="d-flex justify-content-between align-items-center">
            <h5 class="mb-0 font-weight-bold text-dark">
                <i class="fa fa-bar-chart text-primary mr-2"></i>Grafik Capaian Sub-CPMK - CPMK: <?= s($cpmk->nama_kompetensi ?? $cpmk->nama ?? '-') ?> (Prodi: <?= s($prodi->nama_prodi ?? '-') ?>)
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