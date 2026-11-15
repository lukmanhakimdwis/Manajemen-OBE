<?php
// File : local/obe/export/export_capaian_matakuliah_csv.php

// ======================================================
// Bersihkan output buffer
// ======================================================
while (ob_get_level()) {
    ob_end_clean();
}

error_reporting(0);
@ini_set('display_errors', 0);

require_once(__DIR__ . '/../../../config.php');

// ======================================================
// Parameter Masukan Lintas Kelas Paralel Prodi
// ======================================================
$matkul_id   = required_param('matkul_id', PARAM_INT);
$angkatan_id = required_param('angkatan_id', PARAM_INT);

require_login();
\local_obe\access_guard::require_matakuliah_prodi_access($matkul_id);
require_sesskey();

// ======================================================
// Ambil Tahun Akademik State
// ======================================================
$id_tahun_akademik = optional_param(
    'id_tahun_akademik',
    $SESSION->obe_id_tahun_akademik ?? 0,
    PARAM_INT
);

// ======================================================
// Ambil Informasi Mata Kuliah & Definisikan Header Filename
// ======================================================
$matkul = $DB->get_record(
    'local_obe_mata_kuliah',
    [
        'id' => $matkul_id
    ],
    '*',
    MUST_EXIST
);

// ======================================================
// Ambil Informasi Pendukung Metadata Header
// ======================================================
$prodi = $DB->get_record(
    'local_obe_prodi',
    [
        'id' => $matkul->id_prodi
    ]
);

$angkatan = $DB->get_record(
    'local_obe_angkatan',
    [
        'id' => $angkatan_id
    ]
);

$tahun = $DB->get_record(
    'local_obe_tahun_akademik',
    [
        'id' => $id_tahun_akademik
    ]
);

// ======================================================
// Header Download CSV
// ======================================================
header('Content-Type: text/csv; charset=utf-8');
header(
    'Content-Disposition: attachment; filename="capaian_matakuliah_' .
    clean_filename($matkul->kode_mk) .
    '.csv"'
);

$output = fopen('php://output', 'w');

// ======================================================
// Ambil Data Export via Mata Kuliah Manager Baru
// ======================================================
$data = \local_obe\manager\capaian_matakuliah_manager::ambil_data_export_matakuliah(
    $matkul_id,
    $id_tahun_akademik,
    $angkatan_id
);

if (empty($data)) {
    fputcsv($output, ['Tidak ada data.']);
    fclose($output);
    exit;
}

// ======================================================
// HEADER DOKUMEN EXPORT MATA KULIAH
// ======================================================
fputcsv($output, ['LAPORAN CAPAIAN PEMBELAJARAN MATA KULIAH']);
fputcsv($output, []);
fputcsv($output, ['Kode Mata Kuliah', $matkul->kode_mk]);
fputcsv($output, ['Nama Mata Kuliah', $matkul->nama_mk]);
fputcsv($output, ['Program Studi', $prodi->nama_prodi ?? '-']);
fputcsv($output, ['Angkatan', $angkatan->nama_angkatan ?? '-']);
fputcsv($output, ['Tahun Academic', $tahun->nama_tahun ?? '-']);
fputcsv($output, []);
fputcsv($output, ['==============================================================']);

// ======================================================
// LOOP LAYER 1: CPL (ROOT LEVEL)
// ======================================================
foreach ($data as $cpl) {

    fputcsv($output, []);
    fputcsv($output, ['CPL', $cpl->kompetensi->nama]);
    fputcsv($output, ['Deskripsi', strip_tags($cpl->kompetensi->deskripsi)]);
    fputcsv($output, ['Nilai Rata-rata Mata Kuliah', round($cpl->ringkasan->rata_rata, 2)]);
    fputcsv($output, ['Grade', $cpl->ringkasan->grade]);
    fputcsv($output, ['Status', ($cpl->ringkasan->persentase_kompeten >= 100) ? 'Tuntas' : 'Evaluasi']);
    fputcsv($output, []);

    // --- TABEL MAHASISWA LEVEL CPL (Sesuai Koreksi 1) ---
    fputcsv($output, ['No', 'Kelas', 'NIM', 'Nama Mahasiswa', 'Nilai CPL', 'Grade', 'Status']);
    $no = 1;
    foreach ($cpl->mahasiswa->detail_mahasiswa as $mhs) {
        fputcsv($output, [
            $no++,
            $mhs->namakelas ?? '-',
            $mhs->username,
            $mhs->nama,
            round($mhs->nilai, 2),
            $mhs->grade,
            $mhs->kompeten ? 'Kompeten' : 'Belum Kompeten'
        ]);
    }
    fputcsv($output, []);

    // =====================================================
    // LOOP LAYER 2: INDIKATOR KINERJA (CHILD CPL)
    // =====================================================
    foreach ($cpl->indikator_list as $indikator) {

        fputcsv($output, ['------------------------------------------------------------']);
        fputcsv($output, ['INDIKATOR', $indikator->kompetensi->nama]);
        fputcsv($output, ['Nilai Rata-rata', round($indikator->ringkasan->rata_rata, 2)]);
        fputcsv($output, ['Grade', $indikator->ringkasan->grade]);
        fputcsv($output, ['Status', ($indikator->ringkasan->persentase_kompeten >= 100) ? 'Tuntas' : 'Evaluasi']);
        fputcsv($output, []);

        // --- TABEL MAHASISWA LEVEL INDIKATOR (Sesuai Koreksi 2) ---
        fputcsv($output, ['No', 'Kelas', 'NIM', 'Nama Mahasiswa', 'Nilai IK', 'Grade', 'Status']);
        $noIk = 1;
        foreach ($indikator->mahasiswa->detail_mahasiswa as $mhsIk) {
            fputcsv($output, [
                $noIk++,
                $mhsIk->namakelas ?? '-',
                $mhsIk->username,
                $mhsIk->nama,
                round($mhsIk->nilai, 2),
                $mhsIk->grade,
                $mhsIk->kompeten ? 'Kompeten' : 'Belum Kompeten'
            ]);
        }
        fputcsv($output, []);

        // =====================================================
        // LOOP LAYER 3: CPMK (CHILD INDIKATOR)
        // =====================================================
        foreach ($indikator->cpmk_list as $cpmk) {

            fputcsv($output, ['........................................................']);
            fputcsv($output, ['CPMK', $cpmk->kompetensi->nama]);
            fputcsv($output, ['Nilai Rata-rata', round($cpmk->ringkasan->rata_rata, 2)]);
            fputcsv($output, ['Grade', $cpmk->ringkasan->grade]);
            fputcsv($output, ['Status', ($cpmk->ringkasan->persentase_kompeten >= 100) ? 'Tuntas' : 'Evaluasi']);
            fputcsv($output, []);

            // --- TABEL MAHASISWA LEVEL CPMK (Sesuai Koreksi 3) ---
            fputcsv($output, ['No', 'Kelas', 'NIM', 'Nama Mahasiswa', 'Nilai CPMK', 'Grade', 'Status']);
            $noCpmk = 1;
            foreach ($cpmk->mahasiswa->detail_mahasiswa as $mhsCpmk) {
                fputcsv($output, [
                    $noCpmk++,
                    $mhsCpmk->namakelas ?? '-',
                    $mhsCpmk->username,
                    $mhsCpmk->nama,
                    round($mhsCpmk->nilai, 2),
                    $mhsCpmk->grade,
                    $mhsCpmk->kompeten ? 'Kompeten' : 'Belum Kompeten'
                ]);
            }
            fputcsv($output, []);

            // =====================================================
            // LOOP LAYER 4: SUB CPMK (LEAF LEVEL)
            // =====================================================
            foreach ($cpmk->subcpmk_list as $sub) {

                fputcsv($output, ['************************************************************']);
                fputcsv($output, ['SUB CPMK', $sub->kompetensi->nama]);
                fputcsv($output, ['Nilai Rata-rata', round($sub->ringkasan->rata_rata, 2)]);
                fputcsv($output, ['Grade', $sub->ringkasan->grade]);
                fputcsv($output, ['Status', ($sub->ringkasan->persentase_kompeten >= 100) ? 'Tuntas' : 'Evaluasi']);
                fputcsv($output, []);

                // --- DATA METADATA AKTIVITAS MOODLE PENDUKUNG (Sudah Benar) ---
                fputcsv($output, ['DAFTAR AKTIVITAS']);
                foreach ($sub->mahasiswa->aktivitas_list as $aktivitas) {
                    fputcsv($output, [
                        $aktivitas->name,
                        $aktivitas->modulename,
                        $aktivitas->bobot_aktivitas . '%'
                    ]);
                }
                fputcsv($output, []);

                // --- HEADER TABEL MAHASISWA SUB-CPMK ---
                $header = ['No', 'Kelas', 'NIM', 'Nama Mahasiswa'];
                foreach ($sub->mahasiswa->aktivitas_list as $aktivitas) {
                    $header[] = $aktivitas->name;
                }
                $header[] = 'Nilai Sub CPMK';
                $header[] = 'Nilai CPMK';
                $header[] = 'Nilai IK';
                $header[] = 'Nilai CPL';
                $header[] = 'Grade';
                $header[] = 'Status';

                fputcsv($output, $header);

                // --- RAM LOOKUP MAPPING AGREGASI (Sesuai Koreksi 4, 5, & 6) ---
                $lookupCpmk = [];
                foreach ($cpmk->mahasiswa->detail_mahasiswa as $mhs) {
                    $lookupCpmk[$mhs->userid] = $mhs;
                }

                $lookupIk = [];
                foreach ($indikator->mahasiswa->detail_mahasiswa as $mhs) {
                    $lookupIk[$mhs->userid] = $mhs;
                }

                $lookupCpl = [];
                foreach ($cpl->mahasiswa->detail_mahasiswa as $mhs) {
                    $lookupCpl[$mhs->userid] = $mhs;
                }

                // --- ITERASI DATA BARIS MAHASISWA ANGKATAN (Sudah Benar) ---
                $noSub = 1;
                foreach ($sub->mahasiswa->detail_mahasiswa as $mhs) {
                    $row = [];
                    $row[] = $noSub++;
                    $row[] = $mhs->namakelas ?? '-';
                    $row[] = $mhs->username;
                    $row[] = $mhs->nama;

                    // Nilai Komponen Aktivitas Terbobot
                    foreach ($sub->mahasiswa->aktivitas_list as $index => $aktivitas) {
                        $nilaiAktivitas = '-';
                        if (isset($mhs->rincian_list[$index])) {
                            $nilaiAktivitas = round($mhs->rincian_list[$index]->nilai_terbobot, 2);
                        }
                        $row[] = $nilaiAktivitas;
                    }

                    // Gabungkan Nilai Capaian Lintas Hierarki Tree Angkatan
                    $row[] = round($mhs->nilai, 2);
                    $row[] = isset($lookupCpmk[$mhs->userid]) ? round($lookupCpmk[$mhs->userid]->nilai, 2) : '-';
                    $row[] = isset($lookupIk[$mhs->userid]) ? round($lookupIk[$mhs->userid]->nilai, 2) : '-';
                    $row[] = isset($lookupCpl[$mhs->userid]) ? round($lookupCpl[$mhs->userid]->nilai, 2) : '-';
                    $row[] = $mhs->grade;
                    $row[] = $mhs->kompeten ? 'Kompeten' : 'Belum Kompeten';

                    fputcsv($output, $row);
                }
                fputcsv($output, ['']);

            } // End foreach subcpmk
        } // End foreach cpmk
    } // End foreach indikator
} // End foreach cpl

// ======================================================
// Tutup Stream CSV
// ======================================================
fclose($output);
exit;