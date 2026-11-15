<?php
// File: local/obe/views/admin/capaian_kelas/grafik_cpl.php
defined('MOODLE_INTERNAL') || die();

use local_obe\manager\capaian_kelas_manager;
use local_obe\manager\prodi_manager;

global $COURSE, $DB, $OUTPUT, $SESSION;

// =====================================================
// 1. TANGKAP PARAMETER COURSE ID
// =====================================================
$courseid = optional_param('id_course', optional_param('id', 0, PARAM_INT), PARAM_INT);
if ($courseid <= 0 && isset($COURSE)) {
    $courseid = $COURSE->id;
}

// =====================================================
// 2. VALIDASI RELASI KELAS & PROTEKSI AKSES
// =====================================================
$prodi_aktif = prodi_manager::get_prodi_aktif_user();
if ($prodi_aktif) {
    $sql_relasi = "SELECT rk.*
                     FROM {local_obe_relasi_kelas} rk
                     JOIN {local_obe_mata_kuliah} mk ON mk.id = rk.id_mata_kuliah
                    WHERE rk.id_kursus_moodle = :courseid
                      AND mk.id_prodi = :prodiid";
    $relasi_kelas = $DB->get_record_sql($sql_relasi, ['courseid' => $courseid, 'prodiid' => $prodi_aktif->id]);
} else {
    $relasi_kelas = $DB->get_record('local_obe_relasi_kelas', ['id_kursus_moodle' => $courseid], '*', IGNORE_MULTIPLE);
}

if (!$relasi_kelas) {
    echo $OUTPUT->notification('Relasi kurikulum kelas tidak ditemukan.', 'error');
    return;
}

$course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
$tahun_akademik = $relasi_kelas->id_tahun_akademik;

// =====================================================
// 3. AMBIL ID PRODI & MASTER SKALA NILAI
// =====================================================
$prodi_id = $DB->get_field_sql("
    SELECT mk.id_prodi FROM {local_obe_relasi_kelas} rk
    JOIN {local_obe_mata_kuliah} mk ON rk.id_mata_kuliah = mk.id
    WHERE rk.id_kursus_moodle = :courseid
", ['courseid' => $courseid], IGNORE_MULTIPLE);

if (empty($prodi_id)) {
    echo $OUTPUT->notification('Program Studi untuk kelas ini belum dikonfigurasi.', 'warning');
    return;
}

// Map Warna Berdasarkan Grade Status Indikator
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

// Palette Warna Cadangan Jika Indikator Memiliki Nama Tertentu
$palette_indikator = ['#28a745', '#17a2b8', '#007bff', '#ffc107', '#fd7e14', '#6f42c1'];

// =====================================================
// 4. AMBIL SELURUH DAFTAR CPL PADA PRODI INI
// =====================================================
$list_cpl = $DB->get_records('local_obe_kompetensi', [
    'parent_id'         => 0,
    'tipe'              => 'CPL',
    'id_prodi'          => $prodi_id,
    'id_tahun_akademik' => $tahun_akademik
], 'id ASC');

if (empty($list_cpl)) {
    echo $OUTPUT->notification('Belum ada data kompetensi CPL untuk memuat grafik.', 'warning');
    return;
}

// =====================================================
// 5. STRUKTURISASI DATA TUMPUKAN INDIKATOR PER CPL
// =====================================================
$labels = [];
$cpl_indikator_data = [];
$all_indikator_names = [];

foreach ($list_cpl as $cpl) {
    $labels[] = $cpl->nama_kompetensi;
    
    // Ambil Indikator-indikator pendukung di bawah CPL ini
    $list_indikator = capaian_kelas_manager::ambil_struktur_kompetensi_kelas($cpl->id, 'INDIKATOR', $tahun_akademik);
    
    if (!empty($list_indikator)) {
        foreach ($list_indikator as $ind) {
            // Ambil Detail Nilai Rata-Rata Indikator Ini
            $detail_ind = capaian_kelas_manager::ambil_detail_kompetensi_mahasiswa($courseid, $ind->id, 'INDIKATOR', $tahun_akademik);
            $ringkasan_ind = $detail_ind->ringkasan;
            
            $rata_rata_ind = !empty($ringkasan_ind->rata_rata) ? $ringkasan_ind->rata_rata : 0;
            $bobot_ind     = !empty($ind->bobot) ? $ind->bobot : 0;
            
            // KONTRUBUSI INDIKATOR KE CPL = (Nilai Indikator * Bobot) / 100
            $kontribusi = round(($rata_rata_ind * $bobot_ind) / 100, 2);
            $grade_ind  = !empty($ringkasan_ind->grade) ? strtoupper(trim($ringkasan_ind->grade)) : '-';
            
            $nama_ind = $ind->nama;
            if (!in_array($nama_ind, $all_indikator_names)) {
                $all_indikator_names[] = $nama_ind;
            }
            
            $cpl_indikator_data[$cpl->id][$nama_ind] = (object)[
                'kontribusi' => $kontribusi,
                'grade'      => $grade_ind
            ];
        }
    }
}

// =====================================================
// 6. MEMBUAT STACKED CHART BERDASARKAN KONTRIBUSI INDIKATOR
// =====================================================
$chart = new \core\chart_bar();
$chart->set_title("Visualisasi Capaian CPL Berdasarkan Kontribusi Indikator");
$chart->set_labels($labels);
$chart->set_stacked(true); // Stacked Bar Chart

if (!empty($all_indikator_names)) {
    $color_idx = 0;
    foreach ($all_indikator_names as $ind_name) {
        $series_data = [];
        $last_grade = '-';
        
        foreach ($list_cpl as $cpl) {
            if (isset($cpl_indikator_data[$cpl->id][$ind_name])) {
                $item = $cpl_indikator_data[$cpl->id][$ind_name];
                $series_data[] = $item->kontribusi;
                if ($item->kontribusi > 0) {
                    $last_grade = $item->grade;
                }
            } else {
                $series_data[] = 0; // 0 jika CPL ini tidak memiliki indikator tersebut
            }
        }
        
        $series = new \core\chart_series($ind_name, $series_data);
        
        // Tentukan warna batang berdasarkan Grade Indikator (Hijau jika A, Biru jika B, dst)
        if (isset($map_warna_grade[$last_grade]) && $last_grade !== '-') {
            $series->set_color($map_warna_grade[$last_grade]);
        } else {
            $series->set_color($palette_indikator[$color_idx % count($palette_indikator)]);
        }
        
        $chart->add_series($series);
        $color_idx++;
    }
} else {
    // Fallback jika belum ada relasi indikator sama sekali
    $dummy_series = new \core\chart_series('Capaian CPL', array_fill(0, count($labels), 0));
    $chart->add_series($dummy_series);
}

// KONFIGURASI SUMBU Y (0 SAMPAI 100)
$yaxis = $chart->get_yaxis();
$yaxis->set_min(0);
$yaxis->set_max(100);

// =====================================================
// 7. SETUP URL NAVIGASI KEMBALI
// =====================================================
$url_kembali = new moodle_url('/local/obe/views/admin/dashboard.php', [
    'halaman'   => 'capaian_pembelajaran_kelas',
    'sub'       => 'cpl',
    'id_course' => $courseid
]);
?>

<!-- =====================================================
// 8. LAYOUT TAMPILAN
// ===================================================== -->
<div class="card shadow-sm mb-4 border-0">
    <div class="card-header bg-white py-3">
        <div class="d-flex justify-content-between align-items-center">
            <h5 class="mb-0 font-weight-bold text-dark">
                <i class="fa fa-bar-chart text-primary mr-2"></i>Grafik Capaian CPL - <?= s($course->fullname) ?>
            </h5>
            <a href="<?= $url_kembali->out() ?>" class="btn btn-secondary btn-sm font-weight-bold shadow-sm">
                <i class="fa fa-arrow-left mr-1"></i> Kembali
            </a>
        </div>
    </div>
    <div class="card-body p-4">
        <!-- CSS Khusus Menyembunyikan Link & Tabel Bawaan Moodle -->
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