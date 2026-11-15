<?php
// File: local/obe/views/admin/capaian_kelas/grafik_indikator.php
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

$cpl_id         = optional_param('cpl_id', 0, PARAM_INT);
$tahun_akademik = $SESSION->obe_id_tahun_akademik ?? 0;

if (empty($cpl_id)) {
    echo $OUTPUT->notification('ID CPL tidak valid atau kosong.', 'error');
    return;
}

$cpl = $DB->get_record('local_obe_kompetensi', ['id' => $cpl_id], '*', MUST_EXIST);

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

$palette_cpmk = ['#28a745', '#17a2b8', '#007bff', '#ffc107', '#fd7e14', '#6f42c1'];

// =====================================================
// 4. PENARIKAN STRUKTUR INDIKATOR & CPMK PENDUKUNG
// =====================================================
$list_ind = capaian_kelas_manager::ambil_struktur_kompetensi_kelas($cpl_id, 'INDIKATOR', $tahun_akademik);

if (empty($list_ind)) {
    echo $OUTPUT->notification('Belum ada data Indikator Kinerja yang terhubung dengan CPL ini.', 'warning');
    return;
}

$labels = [];
$ind_cpmk_data = [];
$all_cpmk_names = [];

foreach ($list_ind as $ind) {
    $labels[] = $ind->nama;
    
    // Ambil CPMK pendukung di bawah Indikator ini
    $list_cpmk = capaian_kelas_manager::ambil_struktur_kompetensi_kelas($ind->id, 'CPMK', $tahun_akademik);
    
    if (!empty($list_cpmk)) {
        foreach ($list_cpmk as $cpmk) {
            // Hitung detail nilai CPMK
            $detail_cpmk = capaian_kelas_manager::ambil_detail_kompetensi_mahasiswa($courseid, $cpmk->id, 'CPMK', $tahun_akademik);
            $ringkasan_cpmk = $detail_cpmk->ringkasan;
            
            $rata_rata_cpmk = !empty($ringkasan_cpmk->rata_rata) ? $ringkasan_cpmk->rata_rata : 0;
            $bobot_cpmk     = !empty($cpmk->bobot) ? $cpmk->bobot : 0;
            
            // KONTRIBUSI CPMK KE INDIKATOR = (Nilai CPMK * Bobot) / 100
            $kontribusi = round(($rata_rata_cpmk * $bobot_cpmk) / 100, 2);
            $grade_cpmk  = !empty($ringkasan_cpmk->grade) ? strtoupper(trim($ringkasan_cpmk->grade)) : '-';
            
            $nama_cpmk = $cpmk->nama;
            if (!in_array($nama_cpmk, $all_cpmk_names)) {
                $all_cpmk_names[] = $nama_cpmk;
            }
            
            $ind_cpmk_data[$ind->id][$nama_cpmk] = (object)[
                'kontribusi' => $kontribusi,
                'grade'      => $grade_cpmk
            ];
        }
    }
}

// =====================================================
// 5. MEMBUAT DIAGRAM STACKED CHART
// =====================================================
$chart = new \core\chart_bar();
$chart->set_title("Visualisasi Capaian Indikator Kinerja Berdasarkan Kontribusi CPMK");
$chart->set_labels($labels);
$chart->set_stacked(true);

if (!empty($all_cpmk_names)) {
    $color_idx = 0;
    foreach ($all_cpmk_names as $cpmk_name) {
        $series_data = [];
        $last_grade = '-';
        
        foreach ($list_ind as $ind) {
            if (isset($ind_cpmk_data[$ind->id][$cpmk_name])) {
                $item = $ind_cpmk_data[$ind->id][$cpmk_name];
                $series_data[] = $item->kontribusi;
                if ($item->kontribusi > 0) {
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
    $dummy_series = new \core\chart_series('Capaian Indikator', array_fill(0, count($labels), 0));
    $chart->add_series($dummy_series);
}

// KONFIGURASI SUMBU Y (0 - 100)
$yaxis = $chart->get_yaxis();
$yaxis->set_min(0);
$yaxis->set_max(100);

// =====================================================
// 6. SETUP URL NAVIGASI KEMBALI
// =====================================================
if (isset($mode_view) && $mode_view === 'dosen') {
    $url_kembali = new moodle_url('/local/obe/views/dosen/dashboard.php', [
        'id_course' => $courseid,
        'halaman'   => 'laporan',
        'sub'       => 'indikator',
        'cpl_id'    => $cpl_id
    ]);
} else {
    $url_kembali = new moodle_url('/local/obe/views/admin/dashboard.php', [
        'id'        => $courseid,
        'id_course' => $courseid,
        'halaman'   => 'capaian_pembelajaran_kelas',
        'sub'       => 'indikator',
        'cpl_id'    => $cpl_id
    ]);
}
?>

<!-- =====================================================
// 7. LAYOUT TAMPILAN (SAMA PERSIS DENGAN GRAFIK_CPL.PHP)
// ===================================================== -->
<div class="card shadow-sm mb-4 border-0">
    <div class="card-header bg-white py-3">
        <div class="d-flex justify-content-between align-items-center">
            <h5 class="mb-0 font-weight-bold text-dark">
                <i class="fa fa-bar-chart text-primary mr-2"></i>Grafik Capaian Indikator - CPL: <?= s($cpl->nama_kompetensi ?? $cpl->nama ?? '-') ?>
            </h5>
            <a href="<?= $url_kembali->out() ?>" class="btn btn-secondary btn-sm font-weight-bold shadow-sm">
                <i class="fa fa-arrow-left mr-1"></i> Kembali
            </a>
        </div>
    </div>
    <div class="card-body p-4">
        <!-- Style CSS untuk menyembunyikan teks info & tabel bawaan Moodle Chart -->
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