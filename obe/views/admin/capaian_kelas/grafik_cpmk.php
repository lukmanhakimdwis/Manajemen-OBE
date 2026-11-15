<?php
// File: local/obe/views/admin/capaian_kelas/grafik_cpmk.php
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

$indikator_id   = optional_param('indikator_id', 0, PARAM_INT);
$cpl_id         = optional_param('cpl_id', 0, PARAM_INT);
$tahun_akademik = $SESSION->obe_id_tahun_akademik ?? 0;

if (empty($indikator_id)) {
    echo $OUTPUT->notification('ID Indikator Kinerja tidak valid atau kosong.', 'error');
    return;
}

$indikator = $DB->get_record('local_obe_kompetensi', ['id' => $indikator_id], '*', MUST_EXIST);

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
// 3. MAP WARNA GRADE
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
// 4. PENARIKAN STRUKTUR CPMK & SUB-CPMK PENDUKUNG
// =====================================================
$list_cpmk = capaian_kelas_manager::ambil_struktur_kompetensi_kelas($indikator_id, 'CPMK', $tahun_akademik);

if (empty($list_cpmk)) {
    echo $OUTPUT->notification('Belum ada data CPMK yang terhubung dengan Indikator ini.', 'warning');
    return;
}

$labels = [];
$cpmk_subcpmk_data = [];
$all_subcpmk_names = [];
$cpmk_direct_scores = []; // Untuk fallback jika tidak ada Sub-CPMK
$cpmk_direct_colors = [];

foreach ($list_cpmk as $cpmk) {
    $labels[] = $cpmk->nama;
    
    // Tarik nilai langsung CPMK ini (untuk fallback)
    $detail_cpmk_direct = capaian_kelas_manager::ambil_detail_kompetensi_mahasiswa($courseid, $cpmk->id, 'CPMK', $tahun_akademik);
    $ringkasan_direct   = $detail_cpmk_direct->ringkasan;
    $score_direct       = !empty($ringkasan_direct->rata_rata) ? round($ringkasan_direct->rata_rata, 2) : 0;
    $grade_direct       = !empty($ringkasan_direct->grade) ? strtoupper(trim($ringkasan_direct->grade)) : '-';
    
    $cpmk_direct_scores[] = $score_direct;
    $cpmk_direct_colors[] = $map_warna_grade[$grade_direct] ?? '#28a745';
    
    // Coba ambil Sub-CPMK pendukung di bawah CPMK ini
    $list_subcpmk = capaian_kelas_manager::ambil_struktur_kompetensi_kelas($cpmk->id, 'SUB_CPMK', $tahun_akademik);
    
    if (!empty($list_subcpmk)) {
        foreach ($list_subcpmk as $sub) {
            $detail_sub = capaian_kelas_manager::ambil_detail_kompetensi_mahasiswa($courseid, $sub->id, 'SUB_CPMK', $tahun_akademik);
            $ringkasan_sub = $detail_sub->ringkasan;
            
            $rata_rata_sub = !empty($ringkasan_sub->rata_rata) ? $ringkasan_sub->rata_rata : 0;
            $bobot_sub     = !empty($sub->bobot) ? $sub->bobot : 0;
            
            $kontribusi = round(($rata_rata_sub * $bobot_sub) / 100, 2);
            $grade_sub  = !empty($ringkasan_sub->grade) ? strtoupper(trim($ringkasan_sub->grade)) : '-';
            
            $nama_sub = $sub->nama;
            if (!in_array($nama_sub, $all_subcpmk_names)) {
                $all_subcpmk_names[] = $nama_sub;
            }
            
            $cpmk_subcpmk_data[$cpmk->id][$nama_sub] = (object)[
                'kontribusi' => $kontribusi,
                'grade'      => $grade_sub
            ];
        }
    }
}

// =====================================================
// 5. MEMBUAT DIAGRAM MOODLE CHART
// =====================================================
$chart = new \core\chart_bar();
$chart->set_labels($labels);

if (!empty($all_subcpmk_names)) {
    // Mode Stacked: Jika ada struktur Sub-CPMK
    $chart->set_title("Visualisasi Capaian CPMK Berdasarkan Kontribusi Sub-CPMK");
    $chart->set_stacked(true);
    
    $color_idx = 0;
    foreach ($all_subcpmk_names as $sub_name) {
        $series_data = [];
        $last_grade = '-';
        
        foreach ($list_cpmk as $cpmk) {
            if (isset($cpmk_subcpmk_data[$cpmk->id][$sub_name])) {
                $item = $cpmk_subcpmk_data[$cpmk->id][$sub_name];
                $series_data[] = $item->kontribusi;
                if ($item->kontribusi > 0) {
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
    // Mode Fallback: Tampilkan Rata-Rata Capaian CPMK Secara Langsung
    $chart->set_title("Visualisasi Rata-Rata Capaian CPMK Kelas (%)");
    
    $series = new \core\chart_series('Capaian CPMK', $cpmk_direct_scores);
    $series->set_colors($cpmk_direct_colors);
    $chart->add_series($series);
}

// KONFIGURASI SUMBU Y (MENGUNCI SAKRAL 0 SAMPAI 100%)
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
        'sub'          => 'cpmk',
        'indikator_id' => $indikator_id,
        'cpl_id'       => $cpl_id
    ]);
} else {
    $url_kembali = new moodle_url('/local/obe/views/admin/dashboard.php', [
        'id'           => $courseid,
        'id_course'    => $courseid,
        'halaman'      => 'capaian_pembelajaran_kelas',
        'sub'          => 'cpmk',
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
                <i class="fa fa-bar-chart text-primary mr-2"></i>Grafik Capaian CPMK - Indikator: <?= s($indikator->nama_kompetensi ?? $indikator->nama ?? '-') ?>
            </h5>
            <a href="<?= $url_kembali->out() ?>" class="btn btn-secondary btn-sm font-weight-bold shadow-sm">
                <i class="fa fa-arrow-left mr-1"></i> Kembali
            </a>
        </div>
    </div>
    <div class="card-body p-4">
        <!-- CSS Khusus Menyembunyikan Tabel Bawaan & Menjaga Kerapihan Canvas -->
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