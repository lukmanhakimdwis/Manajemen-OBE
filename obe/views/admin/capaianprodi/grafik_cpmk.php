<?php
// File: local/obe/views/admin/capaianprodi/grafik_cpmk.php
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

if (empty($prodi_terpilih) || empty($indikator_id)) {
    echo $OUTPUT->notification('Parameter Program Studi atau Indikator Kinerja belum lengkap.', 'error');
    return;
}

$prodi     = $DB->get_record('local_obe_prodi', ['id' => $prodi_terpilih], '*', MUST_EXIST);
$indikator = $DB->get_record('local_obe_kompetensi', ['id' => $indikator_id], '*', MUST_EXIST);

// =====================================================
// 2. MAP WARNA GRADE & PALETTE
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

$palette_subcpmk = ['#28a745', '#17a2b8', '#007bff', '#ffc107', '#fd7e14', '#6f42c1'];

// =====================================================
// 3. PENARIKAN STRUKTUR CPMK PRODI & SUB-CPMK PENDUKUNG
// =====================================================
$cpmk_data = capaian_prodi_manager::ambil_capaian_cpmk_prodi($prodi_terpilih, $indikator_id, $tahun_akademik, $angkatan_id);

if (empty($cpmk_data)) {
    echo $OUTPUT->notification('Belum ada data CPMK yang terhitung untuk Indikator Kinerja ini.', 'warning');
    return;
}

$labels = [];
$cpmk_subcpmk_data = [];
$all_subcpmk_names = [];
$cpmk_direct_scores = [];
$cpmk_direct_colors = [];

foreach ($cpmk_data as $cpmk) {
    $nama_cpmk = !empty($cpmk->nama_cpmk) ? $cpmk->nama_cpmk : (!empty($cpmk->nama) ? $cpmk->nama : 'CPMK');
    $labels[]  = $nama_cpmk;
    
    $cpmk_id_master = !empty($cpmk->cpmk_id) ? $cpmk->cpmk_id : (!empty($cpmk->id) ? $cpmk->id : 0);
    
    // Direct Fallback Score
    $score_raw = isset($cpmk->percentage) ? $cpmk->percentage : (isset($cpmk->rata_rata) ? $cpmk->rata_rata : 0);
    $score_direct = round((float)$score_raw, 2);
    $grade_direct = !empty($cpmk->grade) ? strtoupper(trim($cpmk->grade)) : (!empty($cpmk->grade_cpmk) ? strtoupper(trim($cpmk->grade_cpmk)) : '-');
    
    $cpmk_direct_scores[] = $score_direct;
    $cpmk_direct_colors[] = $map_warna_grade[$grade_direct] ?? '#28a745';
    
    // Ambil Sub-CPMK pendukung di bawah CPMK ini pada level Prodi
    $list_subcpmk = [];
    if ($cpmk_id_master > 0 && method_exists('local_obe\manager\capaian_prodi_manager', 'ambil_capaian_subcpmk_prodi')) {
        $list_subcpmk = capaian_prodi_manager::ambil_capaian_subcpmk_prodi($prodi_terpilih, $cpmk_id_master, $tahun_akademik, $angkatan_id);
    }
    
    if (!empty($list_subcpmk)) {
        foreach ($list_subcpmk as $sub) {
            $rata_raw = isset($sub->percentage) ? $sub->percentage : (isset($sub->rata_rata) ? $sub->rata_rata : 0);
            $rata_rata_sub = (float)$rata_raw;
            $bobot_sub     = (float)(!empty($sub->bobot) ? $sub->bobot : 0);
            
            if ($bobot_sub > 0) {
                $kontribusi = round(($rata_rata_sub * $bobot_sub) / 100, 2);
            } else {
                $kontribusi = round($rata_rata_sub, 2);
            }
            
            $grade_sub = !empty($sub->grade) ? strtoupper(trim($sub->grade)) : '-';
            $nama_sub  = !empty($sub->nama_subcpmk) ? $sub->nama_subcpmk : (!empty($sub->nama) ? $sub->nama : 'Sub-CPMK');
            
            if (!in_array($nama_sub, $all_subcpmk_names)) {
                $all_subcpmk_names[] = $nama_sub;
            }
            
            $cpmk_subcpmk_data[$cpmk_id_master][$nama_sub] = (object)[
                'kontribusi' => $kontribusi,
                'grade'      => $grade_sub
            ];
        }
    }
}

// =====================================================
// 4. MEMBUAT DIAGRAM MOODLE CHART
// =====================================================
$chart = new \core\chart_bar();
$chart->set_labels($labels);

if (!empty($all_subcpmk_names)) {
    // Mode Stacked: Tampilkan tumpukan kontribusi Sub-CPMK
    $chart->set_title("Visualisasi Capaian CPMK Berdasarkan Kontribusi Sub-CPMK");
    $chart->set_stacked(true);
    
    $color_idx = 0;
    foreach ($all_subcpmk_names as $sub_name) {
        $series_data = [];
        $last_grade = '-';
        
        foreach ($cpmk_data as $cpmk) {
            $cpmk_id_master = !empty($cpmk->cpmk_id) ? $cpmk->cpmk_id : (!empty($cpmk->id) ? $cpmk->id : 0);
            
            if (isset($cpmk_subcpmk_data[$cpmk_id_master][$sub_name])) {
                $item = $cpmk_subcpmk_data[$cpmk_id_master][$sub_name];
                $val  = (float)$item->kontribusi;
                $series_data[] = $val;
                if ($val > 0) {
                    $last_grade = $item->grade;
                }
            } else {
                $series_data[] = 0;
            }
        }
        
        $series = new \core\chart_series($sub_name, $series_data);
        if (isset($map_warna_grade[$last_grade]) && $last_grade !== '-') {
            $series->set_color($map_warna_grade[$last_grade]);
        } else {
            $series->set_color($palette_subcpmk[$color_idx % count($palette_subcpmk)]);
        }
        $chart->add_series($series);
        $color_idx++;
    }
} else {
    // Mode Fallback: Menampilkan Rata-Rata Capaian CPMK Prodi Secara Langsung
    $chart->set_title("Visualisasi Rata-Rata Capaian CPMK (%)");
    
    $series = new \core\chart_series('Capaian CPMK', $cpmk_direct_scores);
    $series->set_colors($cpmk_direct_colors);
    $chart->add_series($series);
}

// KONFIGURASI SUMBU Y (0 SAMPAI 100%)
$yaxis = $chart->get_yaxis();
$yaxis->set_min(0);
$yaxis->set_max(100);

// =====================================================
// 5. SETUP URL NAVIGASI KEMBALI
// =====================================================
$url_kembali = new moodle_url('/local/obe/views/admin/dashboard.php', [
    'halaman'      => 'capaian_pembelajaran_prodi',
    'sub'          => 'cpmk',
    'prodi'        => $prodi_terpilih,
    'angkatan'     => $angkatan_id,
    'cpl_id'       => $cpl_id,
    'indikator_id' => $indikator_id
]);
?>

<!-- =====================================================
// 6. LAYOUT TAMPILAN
// ===================================================== -->
<div class="card shadow-sm mb-4 border-0">
    <div class="card-header bg-white py-3">
        <div class="d-flex justify-content-between align-items-center">
            <h5 class="mb-0 font-weight-bold text-dark">
                <i class="fa fa-bar-chart text-primary mr-2"></i>Grafik Capaian CPMK - IK: <?= s($indikator->nama_kompetensi ?? $indikator->nama ?? '-') ?> (Prodi: <?= s($prodi->nama_prodi ?? '-') ?>)
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