<?php
// File : local/obe/export/export_capaian_prodi_csv.php

while (ob_get_level()) {
    ob_end_clean();
}

error_reporting(0);
@ini_set('display_errors', 0);

require_once(__DIR__ . '/../../../config.php');

// =======================================================
// Parameter
// =======================================================
$prodi_id          = required_param('prodi', PARAM_INT);
$angkatan_id       = optional_param('angkatan', 0, PARAM_INT);
$id_tahun_akademik = optional_param('tahun', 0, PARAM_INT);

\local_obe\access_guard::require_admin_or_same_prodi($prodi_id, 'local/obe:viewprogramreport');
require_sesskey();
require_login();

// =======================================================
// Ambil Data Prodi
// =======================================================
$prodi = $DB->get_record(
    'local_obe_prodi',
    ['id' => $prodi_id],
    '*',
    MUST_EXIST
);

// =======================================================
// Ambil Angkatan
// =======================================================
$angkatan = null;
if ($angkatan_id > 0) {
    $angkatan = $DB->get_record(
        'local_obe_angkatan',
        ['id' => $angkatan_id]
    );
}

// =======================================================
// Ambil Tahun Akademik State
// =======================================================
if (empty($id_tahun_akademik)) {
    $id_tahun_akademik = $SESSION->obe_id_tahun_akademik ?? 0;
}

$tahun = null;
if ($id_tahun_akademik > 0) {
    $tahun = $DB->get_record(
        'local_obe_tahun_akademik',
        ['id' => $id_tahun_akademik]
    );
}

// =======================================================
// Header CSV
// =======================================================
header('Content-Type: text/csv; charset=utf-8');
header(
    'Content-Disposition: attachment; filename="capaian_prodi_' .
    clean_filename($prodi->nama_prodi) .
    '.csv"'
);

// =======================================================
// Output Stream
// =======================================================
$output = fopen('php://output', 'w');

// =======================================================
// Ambil Data Export
// =======================================================
$data = \local_obe\manager\capaian_prodi_manager::ambil_data_export_prodi(
    $prodi_id,
    $id_tahun_akademik,
    $angkatan_id
);

if (empty($data)) {
    fputcsv($output, ['Tidak ada data']);
    fclose($output);
    exit;
}

// =======================================================
// Header Laporan
// =======================================================
fputcsv($output, ['LAPORAN CAPAIAN PEMBELAJARAN PROGRAM STUDI']);
fputcsv($output, []);
fputcsv($output, ['Program Studi', $prodi->nama_prodi]);
fputcsv($output, ['Angkatan', $angkatan->nama_angkatan ?? '-']);
fputcsv($output, ['Tahun Akademik', $tahun->nama_tahun ?? '-']);
fputcsv($output, ['Tanggal Export', userdate(time())]);
fputcsv($output, []);

// =======================================================
// LOOP LAYER 1: CPL (ROOT LEVEL)
// =======================================================
foreach ($data as $cpl) {

    fputcsv($output, ['==============================================================']);
    fputcsv($output, ['CPL', $cpl->kompetensi->nama]); // Level 1 menggunakan properti 'nama' dari mapping buatan
    fputcsv($output, ['Deskripsi', strip_tags($cpl->kompetensi->deskripsi)]);
    fputcsv($output, ['Nilai Rata-rata Prodi', round($cpl->ringkasan->rata_rata, 2)]);
    fputcsv($output, ['Grade', $cpl->ringkasan->grade]);
    fputcsv($output, [
        'Status', 
        ($cpl->ringkasan->persentase_kompeten >= 100) ? 'Kompeten' : 'Evaluasi'
    ]);
    fputcsv($output, []);

    // --- TABEL MAHASISWA LEVEL CPL ---
    fputcsv($output, ['No', 'NIM', 'Nama Mahasiswa', 'Nilai CPL', 'Grade', 'Status']);
    $no = 1;
    foreach ($cpl->mahasiswa->detail_mahasiswa as $mhs) {
        fputcsv($output, [
            $no++,
            $mhs->username,
            $mhs->nama,
            round($mhs->nilai, 2),
            $mhs->grade,
            $mhs->kompeten ? 'Kompeten' : 'Belum Kompeten'
        ]);
    }
    fputcsv($output, []);

    // ===================================================
    // LOOP LAYER 2: INDIKATOR KINERJA (CHILD CPL)
    // ===================================================
    foreach ($cpl->indikator_list as $indikator) {

        fputcsv($output, ['--------------------------------------------------------------']);
        // Perbaikan Level 2-4: Menggunakan nama field database riil 'nama_kompetensi'
        fputcsv($output, ['INDIKATOR', $indikator->kompetensi->nama_kompetensi ?? $indikator->kompetensi->nama ?? '-']);
        fputcsv($output, ['Nilai Rata-rata', round($indikator->ringkasan->rata_rata, 2)]);
        fputcsv($output, ['Grade', $indikator->ringkasan->grade]);
        fputcsv($output, [
            'Status', 
            ($indikator->ringkasan->persentase_kompeten >= 100) ? 'Kompeten' : 'Evaluasi'
        ]);
        fputcsv($output, []);

        // --- TABEL MAHASISWA LEVEL INDIKATOR ---
        fputcsv($output, ['No', 'NIM', 'Nama Mahasiswa', 'Nilai IK', 'Grade', 'Status']);
        $noIk = 1;
        foreach ($indikator->mahasiswa->detail_mahasiswa as $mhsIk) {
            fputcsv($output, [
                $noIk++,
                $mhsIk->username,
                $mhsIk->nama,
                round($mhsIk->nilai, 2),
                $mhsIk->grade,
                $mhsIk->kompeten ? 'Kompeten' : 'Belum Kompeten'
            ]);
        }
        fputcsv($output, []);

        // ===================================================
        // LOOP LAYER 3: CPMK (CHILD INDIKATOR)
        // ===================================================
        foreach ($indikator->cpmk_list as $cpmk) {

            fputcsv($output, ['..............................................................']);
            fputcsv($output, ['CPMK', $cpmk->kompetensi->nama_kompetensi ?? $cpmk->kompetensi->nama ?? '-']);
            fputcsv($output, ['Nilai Rata-rata', round($cpmk->ringkasan->rata_rata, 2)]);
            fputcsv($output, ['Grade', $cpmk->ringkasan->grade]);
            fputcsv($output, [
                'Status', 
                ($cpmk->ringkasan->persentase_kompeten >= 100) ? 'Kompeten' : 'Evaluasi'
            ]);
            fputcsv($output, []);

            // --- TABEL MAHASISWA LEVEL CPMK ---
            fputcsv($output, ['No', 'NIM', 'Nama Mahasiswa', 'Nilai CPMK', 'Grade', 'Status']);
            $noCpmk = 1;
            foreach ($cpmk->mahasiswa->detail_mahasiswa as $mhsCpmk) {
                fputcsv($output, [
                    $noCpmk++,
                    $mhsCpmk->username,
                    $mhsCpmk->nama,
                    round($mhsCpmk->nilai, 2),
                    $mhsCpmk->grade,
                    $mhsCpmk->kompeten ? 'Kompeten' : 'Belum Kompeten'
                ]);
            }
            fputcsv($output, []);

            // ===================================================
            // LOOP LAYER 4: SUB CPMK (LEAF LEVEL)
            // ===================================================
            foreach ($cpmk->subcpmk_list as $sub) {

                fputcsv($output, ['**************************************************************']);
                fputcsv($output, ['SUB CPMK', $sub->kompetensi->nama_kompetensi ?? $sub->kompetensi->nama ?? '-']);
                fputcsv($output, ['Nilai Rata-rata', round($sub->ringkasan->rata_rata, 2)]);
                fputcsv($output, ['Grade', $sub->ringkasan->grade]);
                fputcsv($output, [
                    'Status', 
                    ($sub->ringkasan->persentase_kompeten >= 100) ? 'Kompeten' : 'Evaluasi'
                ]);
                fputcsv($output, []);

                // --- DATA METADATA AKTIVITAS MOODLE PENDUKUNG ---
                fputcsv($output, ['DAFTAR AKTIVITAS']);
                foreach ($sub->mahasiswa->aktivitas_list as $aktivitas) {
                    fputcsv($output, [
                        $aktivitas->name,
                        $aktivitas->modulename,
                        $aktivitas->bobot_aktivitas . '%'
                    ]);
                }
                fputcsv($output, []);

                // --- HEADER TABEL MAHASISWA COMBINED LINTAS LAYER ---
                $header = ['No', 'NIM', 'Nama Mahasiswa', 'Mata Kuliah Pendukung'];
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

                // --- RUNTIME LOOKUP RAM CACHE MAPPING ---
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

                // --- ITERASI DATA BARIS MAHASISWA ANGKATAN ---
                $noSub = 1;
                foreach ($sub->mahasiswa->detail_mahasiswa as $mhs) {
                    $row = [];
                    $row[] = $noSub++;
                    $row[] = $mhs->username;
                    $row[] = $mhs->nama;

                    // Mata Kuliah Pendukung String Builder
                    if (!empty($mhs->matakuliah_pendukung)) {
                        $mk = [];
                        foreach ($mhs->matakuliah_pendukung as $pendukung) {
                            $mk[] = $pendukung->nama;
                        }
                        $row[] = implode(' | ', $mk);
                    } else {
                        $row[] = '-';
                    }

                    // Nilai Aktivitas Terbobot dari Rincian List
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
            } // END SUB CPMK
        } // END CPMK
    } // END INDIKATOR
} // END CPL

// =======================================================
// TUTUP STREAM CSV FILE
// =======================================================
fclose($output);
exit;