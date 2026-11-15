<?php
// File: local/obe/views/admin/capaianprodi/grafik_indikator.php
defined('MOODLE_INTERNAL') || die();

use local_obe\manager\capaian_prodi_manager;
use local_obe\manager\prodi_manager;

global $DB, $OUTPUT, $SESSION, $USER;

// =====================================================
// 1. CEK ROLE & TANGKAP PARAMETER
// =====================================================
$is_kaprodi = prodi_manager::is_kaprodi($USER->id);
$tahun_akademik = $SESSION->obe_id_tahun_akademik ?? 0;
$angkatan_id = optional_param('angkatan', 0, PARAM_INT);
$cpl_id      = optional_param('cpl_id', 0, PARAM_INT);

if ($is_kaprodi) {
    $prodiaktif = prodi_manager::ambil_prodi_kaprodi($USER->id);
    if (!$prodiaktif) {
        throw new moodle_exception('invalidrecord', 'local_obe', '', null, 'Akun Kaprodi belum terhubung dengan Program Studi.');
    }
    $prodi_terpilih = $prodiaktif->id;
} else {
    $prodi_terpilih = optional_param('prodi', 0, PARAM_INT);
}

if (empty($prodi_terpilih) || empty($cpl_id)) {
    echo $OUTPUT->notification('Parameter Program Studi atau CPL belum lengkap.', 'error');
    return;
}

$prodi = $DB->get_record('local_obe_prodi', ['id' => $prodi_terpilih], '*', MUST_EXIST);
$cpl   = $DB->get_record('local_obe_kompetensi', ['id' => $cpl_id], '*', MUST_EXIST);

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

$palette_cpmk = ['#28a745', '#17a2b8', '#007bff', '#ffc107', '#fd7e14', '#6f42c1'];

// =====================================================
// 3. PENARIKAN STRUKTUR INDIKATOR & CPMK PENDUKUNG
// =====================================================
$indikator_data = capaian_prodi_manager::ambil_capaian_indikator_prodi($prodi_terpilih, $cpl_id, $tahun_akademik, $angkatan_id);

if (empty($indikator_data)) {
    echo $OUTPUT->notification('Belum ada data Indikator Kinerja yang terhitung untuk CPL ini.', 'warning');
    return;
}

$labels = [];
$ind_cpmk_data = [];
$all_cpmk_names = [];
$ind_direct_scores = [];
$ind_direct_colors = [];

foreach ($indikator_data as $ind) {
    $nama_ind = !empty($ind->nama) ? $ind->nama : (!empty($ind->nama_kompetensi) ? $ind->nama_kompetensi : 'Indikator');
    $labels[] = $nama_ind;
    
    $ind_id_master = !empty($ind->id) ? $ind->id : 0;
    
    // Direct Fallback Score
    $score_raw = isset($ind->total_indikator) ? $ind->total_indikator : (isset($ind->percentage) ? $ind->percentage : 0);
    $score_direct = round((float)$score_raw, 2);
    $grade_direct = !empty($ind->grade) ? strtoupper(trim($ind->grade)) : '-';
    
    $ind_direct_scores[] = $score_direct;
    $ind_direct_colors[] = $map_warna_grade[$grade_direct] ?? '#28a745';
    
    // Ambil CPMK pendukung di bawah Indikator ini pada level Prodi
    $list_cpmk = [];
    if ($ind_id_master > 0 && method_exists('local_obe\manager\capaian_prodi_manager', 'ambil_capaian_cpmk_prodi')) {
        $list_cpmk = capaian_prodi_manager::ambil_capaian_cpmk_prodi($prodi_terpilih, $ind_id_master, $tahun_akademik, $angkatan_id);
    }
    
    if (!empty($list_cpmk)) {
        foreach ($list_cpmk as $cpmk) {
            $rata_raw = isset($cpmk->percentage) ? $cpmk->percentage : (isset($cpmk->rata_rata) ? $cpmk->rata_rata : 0);
            $rata_rata_cpmk = (float)$rata_raw;
            $bobot_cpmk     = (float)(!empty($cpmk->bobot) ? $cpmk->bobot : 0);
            
            if ($bobot_cpmk > 0) {
                $kontribusi = round(($rata_rata_cpmk * $bobot_cpmk) / 100, 2);
            } else {
                $kontribusi = round($rata_rata_cpmk, 2);
            }
            
            $grade_cpmk = !empty($cpmk->grade) ? strtoupper(trim($cpmk->grade)) : '-';
            $nama_cpmk  = !empty($cpmk->nama_cpmk) ? $cpmk->nama_cpmk : (!empty($cpmk->nama) ? $cpmk->nama : 'CPMK');
            
            if (!in_array($nama_cpmk, $all_cpmk_names)) {
                $all_cpmk_names[] = $nama_cpmk;
            }
            
            $ind_cpmk_data[$ind_id_master][$nama_cpmk] = (object)[
                'kontribusi' => $kontribusi,
                'grade'      => $grade_cpmk
            ];
        }
    }
}

// =====================================================
// 4. MEMBUAT DIAGRAM MOODLE CHART
// =====================================================
$chart = new \core\chart_bar();
$chart->set_labels($labels);

if (!empty($all_cpmk_names)) {
    // Mode Stacked: Tampilkan tumpukan kontribusi CPMK
    $chart->set_title("Visualisasi Capaian Indikator Kinerja Berdasarkan Kontribusi CPMK");
    $chart->set_stacked(true);
    
    $color_idx = 0;
    foreach ($all_cpmk_names as $cpmk_name) {
        $series_data = [];
        $last_grade = '-';
        
        foreach ($indikator_data as $ind) {
            $ind_id_master = !empty($ind->id) ? $ind->id : 0;
            
            if (isset($ind_cpmk_data[$ind_id_master][$cpmk_name])) {
                $item = $ind_cpmk_data[$ind_id_master][$cpmk_name];
                $val  = (float)$item->kontribusi;
                $series_data[] = $val;
                if ($val > 0) {
                    $last_grade = $item->grade;
                }
            } else {
                $series_data[] = 0;
            }
        }
        
        $series = new \core\chart_series($cpmk_name, $series_data);
        if (isset($map_warna_grade[$last_grade]) && $last_grade !== '-') {
            $series->set_color($map_warna_grade[$last_grade]);
        } else {
            $series->set_color($palette_cpmk[$color_idx % count($palette_cpmk)]);
        }
        $chart->add_series($series);
        $color_idx++;
    }
} else {
    // Mode Fallback: Menampilkan Rata-Rata Capaian Indikator Prodi Secara Langsung
    $chart->set_title("Visualisasi Rata-Rata Capaian Indikator Kinerja (%)");
    
    $series = new \core\chart_series('Capaian Indikator', $ind_direct_scores);
    $series->set_colors($ind_direct_colors);
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
    'halaman'  => 'capaian_pembelajaran_prodi',
    'sub'      => 'indikator',
    'prodi'    => $prodi_terpilih,
    'angkatan' => $angkatan_id,
    'cpl_id'   => $cpl_id
]);
?>

<!-- =====================================================
// 6. LAYOUT TAMPILAN
// ===================================================== -->
<div class="card shadow-sm mb-4 border-0">
    <div class="card-header bg-white py-3">
        <div class="d-flex justify-content-between align-items-center">
            <h5 class="mb-0 font-weight-bold text-dark">
                <i class="fa fa-bar-chart text-primary mr-2"></i>Grafik Capaian Indikator - CPL: <?= s($cpl->nama_kompetensi ?? $cpl->nama ?? '-') ?> (Prodi: <?= s($prodi->nama_prodi ?? '-') ?>)
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