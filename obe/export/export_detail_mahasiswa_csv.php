<?php
// File: local/obe/export/export_detail_mahasiswa_csv.php

// 1. CLEAR BUFFER & MATIKAN DETEKSI ERROR DISPLAY AGAR TIDAK MERUSAK BINARY HEADER CSV
while (ob_get_level()) {
    ob_end_clean();
}
error_reporting(0);
@ini_set('display_errors', 0);

require_once(__DIR__ . '/../../../config.php');

// Memastikan user sudah login ke sistem Moodle
require_login();

// Menggunakan nama parameter sesuai dengan link URL Anda (courseid)
$courseid = required_param('courseid', PARAM_INT);

// 2. SET HEADER DENGAN VALIDASI BERSIH
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="detail_mahasiswa_obe_' . $courseid . '.csv"');
header('Cache-Control: max-age=0, no-cache, must-revalidate');
header('Pragma: public');

// Buka stream output langsung ke browser
$output = fopen('php://output', 'w');

// Tulis Baris Judul Kolom (Header) - Total 20 Kolom Struktural
fputcsv($output, [
    'NIM',
    'NAMA MAHASISWA',

    'AKTIVITAS',
    'NILAI AKTIVITAS',

    'SUB CPMK',
    'NILAI SUB CPMK',
    'GRADE SUB CPMK',
    'STATUS SUB CPMK',

    'CPMK',
    'NILAI CPMK',
    'GRADE CPMK',
    'STATUS CPMK',

    'INDIKATOR',
    'NILAI INDIKATOR',
    'GRADE INDIKATOR',
    'STATUS INDIKATOR',

    'CPL',
    'NILAI CPL',
    'GRADE CPL',
    'STATUS CPL'
]);

try {
    $data = \local_obe\manager\capaian_pembelajaran_manager::ambil_data_export_mahasiswa_kelas($courseid);
} catch (\Throwable $e) {
    // Jika gagal dalam stream CSV, kembalikan teks error murni tanpa merusak format pembacaan browser
    header('Content-Type: text/plain; charset=utf-8');
    die(
        "ERROR GENERATE DATA EXPORT:\n" .
        $e->getMessage() . "\n\n" .
        "File: " . $e->getFile() . "\n" .
        "Line: " . $e->getLine()
    );
}

$nim_sebelumnya = null;

if (!empty($data) && is_array($data)) {
    // Iterasi baris data untuk dimasukkan ke dalam baris CSV
    foreach ($data as $row) {

        // Tambahkan pembatas baris kosong jika berganti mahasiswa (NIM Berubah)
        if ($nim_sebelumnya !== null && $nim_sebelumnya != $row['nim']) {
            fputcsv($output, []);
        }

        // Tulis baris data sesuai dengan urutan struktur field baru (Total 20 Kolom)
        fputcsv($output, [
            $row['nim'] ?? '-',
            $row['nama_mahasiswa'] ?? '-',

            $row['aktivitas'] ?? '-',
            $row['nilai_aktivitas'] ?? 0,

            $row['subcpmk'] ?? '-',
            $row['nilai_subcpmk'] ?? 0,
            $row['grade_subcpmk'] ?? '-',
            $row['status_subcpmk'] ?? '-',

            $row['cpmk'] ?? '-',
            $row['nilai_cpmk'] ?? 0,
            $row['grade_cpmk'] ?? '-',
            $row['status_cpmk'] ?? '-',

            $row['indikator'] ?? '-',
            $row['nilai_indikator'] ?? 0,
            $row['grade_indikator'] ?? '-',
            $row['status_indikator'] ?? '-',

            $row['cpl'] ?? '-',
            $row['nilai_cpl'] ?? 0,
            $row['grade_cpl'] ?? '-',
            $row['status_cpl'] ?? '-'
        ]);

        $nim_sebelumnya = $row['nim'];
    }
}

// Tutup stream dan hentikan proses agar core Moodle tidak menyisipkan HTML footer
fclose($output);
exit;