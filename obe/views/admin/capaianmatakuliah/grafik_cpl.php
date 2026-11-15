<?php
// File: local/obe/views/admin/capaianmatakuliah/grafik_cpl.php
defined('MOODLE_INTERNAL') || die();

use local_obe\manager\capaian_matakuliah_manager;
use local_obe\manager\prodi_manager;

global $DB, $OUTPUT, $SESSION;

// =====================================================
// 1. TANGKAP PARAMETER FILTER & VALIDASI
// =====================================================
$prodi_id       = optional_param('prodi', 0, PARAM_INT);
$matkul_id      = optional_param('matkul', 0, PARAM_INT);
$angkatan_id    = optional_param('angkatan', 0, PARAM_INT);
$tahun_akademik = $SESSION->obe_id_tahun_akademik ?? 0;

if (empty($matkul_id)) {
    echo $OUTPUT->notification('Mata Kuliah belum dipilih.', 'error');
    return;
}

$matkul = $DB->get_record('local_obe_mata_kuliah', ['id' => $matkul_id], '*', MUST_EXIST);

// =====================================================
// 2. VALIDASI KEAMANAN & HAK AKSES PRODI
// =====================================================
$prodi_aktif = prodi_manager::get_prodi_aktif_user();
if ($prodi_aktif) {
    if ((int)$matkul->id_prodi !== (int)$prodi_aktif->id) {
        echo $OUTPUT->notification('Aksi ditolak! Anda tidak memiliki hak akses terhadap program studi lain.', 'error');
        return;
    }
    $prodi_id = (int)$prodi_aktif->id;
}

// =====================================================
// 3. MAP WARNA GRADE & PALETTE
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
// 4. PENARIKAN STRUKTUR CPL & INDIKATOR PENDUKUNG
// =====================================================
$cpl_data = capaian_matakuliah_manager::ambil_capaian_cpl_matakuliah($matkul_id, $tahun_akademik, $angkatan_id);

if (empty($cpl_data)) {
    echo $OUTPUT->notification('Belum ada data Capaian CPL yang terhubung dengan Mata Kuliah ini.', 'warning');
    return;
}

$labels = [];
$cpl_ind_data = [];
$all_ind_names = [];
$cpl_direct_scores = [];
$cpl_direct_colors = [];

foreach ($cpl_data as $index => $cpl) {
    $nama_cpl = !empty($cpl->nama_cpl) ? $cpl->nama_cpl : (!empty($cpl->nama) ? $cpl->nama : 'CPL');
    $labels[] = $nama_cpl;
    
    $cpl_id_master = !empty($cpl->cpl_id) ? $cpl->cpl_id : (!empty($cpl->id) ? $cpl->id : 0);
    
    // Simpan nilai direct fallback
    $score_raw = isset($cpl->percentage) ? $cpl->percentage : 0;
    $score_direct = round((float)$score_raw, 2);
    $grade_direct = !empty($cpl->grade) ? strtoupper(trim($cpl->grade)) : '-';
    
    $cpl_direct_scores[] = $score_direct;
    $cpl_direct_colors[] = $map_warna_grade[$grade_direct] ?? '#28a745';
    
    // Ambil Indikator Kinerja pendukung
    $list_ind = [];
    if ($cpl_id_master > 0) {
        $list_ind = capaian_matakuliah_manager::ambil_capaian_indikator_matakuliah($matkul_id, $cpl_id_master, $tahun_akademik, $angkatan_id);
    }
    
    if (!empty($list_ind)) {
        foreach ($list_ind as $ind) {
            // MEMBACA DENGAN TEPAT 'total_indikator' DARI MANAGER
            $rata_raw = isset($ind->total_indikator) ? $ind->total_indikator : (isset($ind->percentage) ? $ind->percentage : 0);
            $rata_rata_ind = (float)$rata_raw;
            
            $bobot_ind = (float)(!empty($ind->bobot) ? $ind->bobot : 0);
            
            // Perhitungan kontribusi: jika bobot diset > 0, hitung terbobot. Jika tidak, pakai nilai langsung.
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
// 5. MEMBUAT DIAGRAM MOODLE CHART
// =====================================================
$chart = new \core\chart_bar();
$chart->set_labels($labels);

if (!empty($all_ind_names)) {
    // Mode Stacked: Tampilkan kontribusi Indikator Kinerja
    $chart->set_title("Visualisasi Capaian CPL Mata Kuliah Berdasarkan Kontribusi Indikator Kinerja");
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
    // Mode Direct Bar: Tampilkan Rata-Rata Nilai CPL Mata Kuliah Secara Langsung
    $chart->set_title("Visualisasi Rata-Rata Capaian CPL Mata Kuliah (%)");
    
    $series = new \core\chart_series('Capaian CPL', $cpl_direct_scores);
    $series->set_colors($cpl_direct_colors);
    $chart->add_series($series);
}

// KONFIGURASI SUMBU Y (0 SAMPAI 100%)
$yaxis = $chart->get_yaxis();
$yaxis->set_min(0);
$yaxis->set_max(100);

// =====================================================
// 6. SETUP URL NAVIGASI KEMBALI
// =====================================================
$url_kembali = new moodle_url('/local/obe/views/admin/dashboard.php', [
    'halaman'  => 'capaian_pembelajaran_matakuliah',
    'sub'      => 'cpl',
    'prodi'    => $prodi_id,
    'matkul'   => $matkul_id,
    'angkatan' => $angkatan_id
]);
?>

<!-- =====================================================
// 7. LAYOUT TAMPILAN
// ===================================================== -->
<div class="card shadow-sm mb-4 border-0">
    <div class="card-header bg-white py-3">
        <div class="d-flex justify-content-between align-items-center">
            <h5 class="mb-0 font-weight-bold text-dark">
                <i class="fa fa-bar-chart text-primary mr-2"></i>Grafik Capaian CPL - Mata Kuliah: <?= s($matkul->nama_mk ?? '-') ?>
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