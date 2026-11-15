<?php
// File : local/obe/export/export_capaian_mahasiswa_csv.php

while (ob_get_level()) {
    ob_end_clean();
}

error_reporting(0);
@ini_set('display_errors', 0);

require_once(__DIR__ . '/../../../config.php');

use local_obe\manager\capaian_mahasiswa_manager;

// =======================================================
// Parameter
// =======================================================
$userid            = required_param('userid', PARAM_INT);
$prodi_id          = required_param('prodi', PARAM_INT);
$id_tahun_akademik = optional_param('tahun', 0, PARAM_INT);

require_login();
\local_obe\access_guard::require_admin_or_same_prodi($prodi_id, 'local/obe:viewprogramreport');
require_sesskey();

if (empty($id_tahun_akademik)) {
    $id_tahun_akademik = $SESSION->obe_id_tahun_akademik ?? 0;
}

// =======================================================
// Ambil Data
// =======================================================
$data = capaian_mahasiswa_manager::ambil_data_export_mahasiswa(
    $userid,
    $prodi_id,
    $id_tahun_akademik
);

if (empty($data)) {
    print_error('Data tidak ditemukan');
}

// =======================================================
// Data Pendukung
// =======================================================
$prodi = $DB->get_record(
    'local_obe_prodi',
    ['id' => $prodi_id],
    '*',
    MUST_EXIST
);

$tahun = $DB->get_record(
    'local_obe_tahun_akademik',
    ['id' => $id_tahun_akademik]
);

// =======================================================
// Header CSV
// =======================================================
header('Content-Type: text/csv; charset=utf-8');
header(
    'Content-Disposition: attachment; filename="capaian_' .
    clean_filename($data->mahasiswa->username) .
    '.csv"'
);

$output = fopen('php://output', 'w');

// =======================================================
// Header Laporan
// =======================================================
fputcsv($output, ['LAPORAN CAPAIAN PEMBELAJARAN MAHASISWA']);
fputcsv($output, []);

fputcsv($output, ['Nama', fullname($data->mahasiswa)]);
fputcsv($output, ['NIM', $data->mahasiswa->username]);
fputcsv($output, ['Program Studi', $prodi->nama_prodi]);
fputcsv($output, ['Tahun Akademik', $tahun->nama_tahun ?? '-']);
fputcsv($output, ['Tanggal Export', userdate(time())]);

fputcsv($output, []);

// =======================================================
// LOOP CPL
// =======================================================
foreach ($data->trace as $cpl) {

    fputcsv($output, ['================================================']);
    fputcsv($output, ['CPL', $cpl->nama]);
    fputcsv($output, ['Deskripsi', strip_tags($cpl->deskripsi)]);
    fputcsv($output, ['Nilai', round($cpl->nilai,2)]);
    fputcsv($output, ['Grade', $cpl->grade]);
    fputcsv($output, []);

    foreach ($cpl->indikator_list as $indikator) {

        fputcsv($output, ['------------------------------------------------']);
        fputcsv($output, ['INDIKATOR', $indikator->nama]);
        fputcsv($output, ['Nilai', round($indikator->nilai,2)]);
        fputcsv($output, ['Grade', $indikator->grade]);
        fputcsv($output, []);

        foreach ($indikator->cpmk_list as $cpmk) {

            fputcsv($output, ['................................................']);
            fputcsv($output, ['CPMK', $cpmk->nama]);
            fputcsv($output, ['Nilai', round($cpmk->nilai,2)]);
            fputcsv($output, ['Grade', $cpmk->grade]);
            fputcsv($output, []);

            foreach ($cpmk->subcpmk_list as $sub) {

                fputcsv($output, ['**********************************************']);
                fputcsv($output, ['SUB CPMK', $sub->nama]);
                fputcsv($output, ['Deskripsi', strip_tags($sub->deskripsi)]);
                fputcsv($output, ['Nilai', round($sub->nilai,2)]);
                fputcsv($output, ['Grade', $sub->grade]);
                fputcsv($output, []);

                // ===================================================
                // Mata Kuliah Pendukung
                // ===================================================
                fputcsv($output, [
                    'Kode MK',
                    'Nama Mata Kuliah',
                    'Kelas',
                    'Nilai',
                    'Grade',
                    'Status'
                ]);

                foreach ($sub->matakuliah_pendukung as $mk) {

                    fputcsv($output, [
                        $mk->kode_mk,
                        $mk->nama_mk,
                        $mk->nama_kelas,
                        round($mk->nilai,2),
                        $mk->grade,
                        $mk->kompeten ? 'Kompeten' : 'Belum Kompeten'
                    ]);
                }

                fputcsv($output, []);
            }
        }
    }
}

fclose($output);
exit;