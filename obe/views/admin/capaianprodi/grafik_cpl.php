<?php
// File: local/obe/views/admin/capaianprodi/grafik_cpl.php
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

if ($is_kaprodi) {
    $prodiaktif = prodi_manager::ambil_prodi_kaprodi($USER->id);
    if (!$prodiaktif) {
        throw new moodle_exception('invalidrecord', 'local_obe', '', null, 'Akun Kaprodi belum terhubung dengan Program Studi.');
    }
    $prodi_terpilih = $prodiaktif->id;
} else {
    $prodi_terpilih = optional_param('prodi', 0, PARAM_INT);
}

if (empty($prodi_terpilih)) {
    echo $OUTPUT->notification('Program Studi belum dipilih.', 'error');
    return;
}

$prodi = $DB->get_record('local_obe_prodi', ['id' => $prodi_terpilih], '*', MUST_EXIST);

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

$palette_indikator = ['#28a745', '#17a2b8', '#007bff', '#ffc107', '#fd7e14', '#6f42c1'];

// =====================================================
// 3. PENARIKAN STRUKTUR CPL PRODI & INDIKATOR PENDUKUNG
// =====================================================
$cpl_data = capaian_prodi_manager::ambil_capaian_cpl_prodi($prodi_terpilih, $tahun_akademik, $angkatan_id);

if (empty($cpl_data)) {
    echo $OUTPUT->notification('Belum ada data Capaian CPL yang terhitung untuk Program Studi ini.', 'warning');
    return;
}

$labels = [];
$cpl_ind_data = [];
$all_ind_names = [];
$cpl_direct_scores = [];
$cpl_direct_colors = [];

foreach ($cpl_data as $cpl) {
    $nama_cpl = !empty($cpl->nama_cpl) ? $cpl->nama_cpl : (!empty($cpl->nama) ? $cpl->nama : 'CPL');
    $labels[] = $nama_cpl;
    
    $cpl_id_master = !empty($cpl->cpl_id) ? $cpl->cpl_id : (!empty($cpl->id) ? $cpl->id : 0);
    
    // Direct Fallback Score
    $score_raw = isset($cpl->percentage) ? $cpl->percentage : 0;
    $score_direct = round((float)$score_raw, 2);
    $grade_direct = !empty($cpl->grade) ? strtoupper(trim($cpl->grade)) : '-';
    
    $cpl_direct_scores[] = $score_direct;
    $cpl_direct_colors[] = $map_warna_grade[$grade_direct] ?? '#28a745';
    
    // Ambil Indikator Kinerja pendukung di bawah CPL ini pada level Prodi
    $list_ind = [];
    if ($cpl_id_master > 0 && method_exists('local_obe\manager\capaian_prodi_manager', 'ambil_capaian_indikator_prodi')) {
        $list_ind = capaian_prodi_manager::ambil_capaian_indikator_prodi($prodi_terpilih, $cpl_id_master, $tahun_akademik, $angkatan_id);
    }
    
    if (!empty($list_ind)) {
        foreach ($list_ind as $ind) {
            $rata_raw = isset($ind->total_indikator) ? $ind->total_indikator : (isset($ind->percentage) ? $ind->percentage : 0);
            $rata_rata_ind = (float)$rata_raw;
            $bobot_ind     = (float)(!empty($ind->bobot) ? $ind->bobot : 0);
            
            if ($bobot_ind > 0) {
                $kontribusi = round(($rata_rata_ind * $bobot_ind) / 100, 2);
            } else {
                $kontribusi = round($rata_rata_ind, 2);
            }
            
            $grade_ind = !empty($ind->grade) ? strtoupper(trim($ind->grade)) : '-';
            $nama_ind  = !empty($ind->nama) ? $ind->nama : (!empty($ind->nama_kompetensi) ? $ind->nama_kompetensi : 'Indikator');
            
            if (!in_array($nama_ind, $all_ind_names)) {
                $all_ind_names[] = $nama_ind;
            }
            
            $cpl_ind_data[$cpl_id_master][$nama_ind] = (object)[
                'kontribusi' => $kontribusi,
                'grade'      => $grade_ind
            ];
        }
    }
}

// =====================================================
// 4. MEMBUAT DIAGRAM MOODLE CHART
// =====================================================
$chart = new \core\chart_bar();
$chart->set_labels($labels);

if (!empty($all_ind_names)) {
    // Mode Stacked: Tampilkan tumpukan kontribusi Indikator Kinerja
    $chart->set_title("Visualisasi Capaian CPL Prodi Berdasarkan Kontribusi Indikator Kinerja");
    $chart->set_stacked(true);
    
    $color_idx = 0;
    foreach ($all_ind_names as $ind_name) {
        $series_data = [];
        $last_grade = '-';
        
        foreach ($cpl_data as $cpl) {
            $cpl_id_master = !empty($cpl->cpl_id) ? $cpl->cpl_id : (!empty($cpl->id) ? $cpl->id : 0);
            
            if (isset($cpl_ind_data[$cpl_id_master][$ind_name])) {
                $item = $cpl_ind_data[$cpl_id_master][$ind_name];
                $val  = (float)$item->kontribusi;
                $series_data[] = $val;
                if ($val > 0) {
                    $last_grade = $item->grade;
                }
            } else {
                $series_data[] = 0;
            }
        }
        
        $series = new \core\chart_series($ind_name, $series_data);
        if (isset($map_warna_grade[$last_grade]) && $last_grade !== '-') {
            $series->set_color($map_warna_grade[$last_grade]);
        } else {
            $series->set_color($palette_indikator[$color_idx % count($palette_indikator)]);
        }
        $chart->add_series($series);
        $color_idx++;
    }
} else {
    // Mode Fallback: Menampilkan Rata-Rata Capaian CPL Prodi Secara Langsung
    $chart->set_title("Visualisasi Rata-Rata Capaian CPL Prodi (%)");
    
    $series = new \core\chart_series('Capaian CPL', $cpl_direct_scores);
    $series->set_colors($cpl_direct_colors);
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
    'sub'      => 'cpl',
    'prodi'    => $prodi_terpilih,
    'angkatan' => $angkatan_id
]);
?>

<!-- =====================================================
// 6. LAYOUT TAMPILAN
// ===================================================== -->
<div class="card shadow-sm mb-4 border-0">
    <div class="card-header bg-white py-3">
        <div class="d-flex justify-content-between align-items-center">
            <h5 class="mb-0 font-weight-bold text-dark">
                <i class="fa fa-bar-chart text-primary mr-2"></i>Grafik Capaian CPL - Program Studi: <?= s($prodi->nama_prodi ?? '-') ?>
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