<?php
// File : local/obe/export/export_hierarki_kelas_csv.php

while (ob_get_level()) {
    ob_end_clean();
}

error_reporting(0);
@ini_set('display_errors', 0);

require_once(__DIR__ . '/../../../config.php');

$courseid = required_param('courseid', PARAM_INT);

list($course, $context) = \local_obe\access_guard::require_course_report($courseid);

require_sesskey();

// =======================================================
// Header CSV
// =======================================================
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="capaian_hierarki_kelas_' . $courseid . '.csv"');

$output = fopen('php://output', 'w');

// =======================================================
// Ambil Data Baru via Manager Terpusat Kelas
// =======================================================
$data = \local_obe\manager\capaian_kelas_manager::ambil_data_export_kelas($courseid, \local_obe\manager\aktivitasnilai_manager::ambil_tahun_id_dari_course($courseid));

if (empty($data)) {
    fputcsv($output, ['Tidak ada data']);
    fclose($output);
    exit;
}

// =======================================================
// JUDUL DOKUMEN EXPORT
// =======================================================
fputcsv($output, ['CAPAIAN PEMBELAJARAN KELAS BERBASIS OBE']);
fputcsv($output, ['']);
fputcsv($output, ['Nama Kelas', format_string($course->fullname)]);
fputcsv($output, ['Tanggal Export', userdate(time())]);
fputcsv($output, ['']);

// =======================================================
// LOOP LAYER 1: CPL (ROOT LEVEL)
// =======================================================
foreach ($data as $cpl) {

    fputcsv($output, ['===========================================================']);
    fputcsv($output, ['CPL', $cpl->kompetensi->nama]);
    fputcsv($output, ['Deskripsi', strip_tags($cpl->kompetensi->deskripsi)]);
    fputcsv($output, ['Nilai Rata-rata Kelas', round($cpl->detail->ringkasan->rata_rata, 2)]);
    fputcsv($output, ['Grade Kinerja Kelas', $cpl->detail->ringkasan->grade]);
    fputcsv($output, ['']);

    // --- TABEL MAHASISWA LEVEL CPL ---
    fputcsv($output, ['No', 'Nama Mahasiswa', 'Nilai CPL', 'Grade', 'Status Ketuntasan']);
    $no = 1;
    foreach ($cpl->detail->detail_mahasiswa as $mhs) {
        fputcsv($output, [
            $no++,
            $mhs->nama,
            round($mhs->nilai, 2),
            $mhs->grade,
            $mhs->kompeten ? 'Kompeten' : 'Belum Kompeten'
        ]);
    }
    fputcsv($output, ['']);

    // ===================================================
    // LOOP LAYER 2: INDIKATOR KINERJA (CHILD CPL)
    // ===================================================
    foreach ($cpl->indikator_list as $indikator) {

        fputcsv($output, ['----------------------------------------------------------']);
        fputcsv($output, ['INDIKATOR', $indikator->kompetensi->nama]);
        fputcsv($output, ['Nilai Rata-rata', round($indikator->detail->ringkasan->rata_rata, 2)]);
        fputcsv($output, ['Grade', $indikator->detail->ringkasan->grade]);
        fputcsv($output, ['']);

        // --- TABEL MAHASISWA LEVEL INDIKATOR ---
        fputcsv($output, ['No', 'Mahasiswa', 'Nilai IK', 'Grade', 'Status']);
        $noIk = 1;
        foreach ($indikator->detail->detail_mahasiswa as $mhsIk) {
            fputcsv($output, [
                $noIk++,
                $mhsIk->nama,
                round($mhsIk->nilai, 2),
                $mhsIk->grade,
                $mhsIk->kompeten ? 'Kompeten' : 'Belum Kompeten'
            ]);
        }
        fputcsv($output, ['']);

        // ===================================================
        // LOOP LAYER 3: CPMK (CHILD INDIKATOR)
        // ===================================================
        foreach ($indikator->cpmk_list as $cpmk) {

            fputcsv($output, ['.........................................................']);
            fputcsv($output, ['CPMK', $cpmk->kompetensi->nama]);
            fputcsv($output, ['Nilai Rata-rata', round($cpmk->detail->ringkasan->rata_rata, 2)]);
            fputcsv($output, ['Grade', $cpmk->detail->ringkasan->grade]);
            fputcsv($output, ['']);

            // --- TABEL MAHASISWA LEVEL CPMK ---
            fputcsv($output, ['No', 'Mahasiswa', 'Nilai CPMK', 'Grade', 'Status']);
            $noCpmk = 1;
            foreach ($cpmk->detail->detail_mahasiswa as $mhsCpmk) {
                fputcsv($output, [
                    $noCpmk++,
                    $mhsCpmk->nama,
                    round($mhsCpmk->nilai, 2),
                    $mhsCpmk->grade,
                    $mhsCpmk->kompeten ? 'Kompeten' : 'Belum Kompeten'
                ]);
            }
            fputcsv($output, ['']);

            // ===================================================
            // LOOP LAYER 4: SUB CPMK (LEAF LEVEL)
            // ===================================================
            foreach ($cpmk->subcpmk_list as $sub) {

                fputcsv($output, ['********************************************************']);
                fputcsv($output, ['SUB CPMK', $sub->kompetensi->nama]);
                fputcsv($output, ['Nilai Rata-rata', round($sub->detail->ringkasan->rata_rata, 2)]);
                fputcsv($output, ['Grade', $sub->detail->ringkasan->grade]);
                fputcsv($output, ['']);

                // --- DATA AKTIVITAS MOODLE PENDUKUNG ---
                fputcsv($output, ['METADATA COMPONENT', 'Tipe Aktivitas Moodle', 'Bobot Komponen']);
                foreach ($sub->detail->aktivitas_list as $aktivitas) {
                    fputcsv($output, [
                        $aktivitas->nama_aktivitas ?? $aktivitas->name ?? 'Aktivitas',
                        ucfirst($aktivitas->modulename),
                        number_format($aktivitas->bobot_aktivitas, 0) . '%'
                    ]);
                }
                fputcsv($output, ['']);

                // --- HEADER DATA MAHASISWA SUB-CPMK COMBINED ---
                $header = ['No', 'Nama Mahasiswa'];
                foreach ($sub->detail->aktivitas_list as $aktivitas) {
                    $header[] = $aktivitas->nama_aktivitas ?? $aktivitas->name ?? 'Aktivitas';
                }
                $header[] = 'Nilai Sub CPMK';
                $header[] = 'Nilai CPMK';
                $header[] = 'Nilai IK';
                $header[] = 'Nilai CPL';
                $header[] = 'Grade';
                $header[] = 'Status Kelulusan';

                fputcsv($output, $header);

                // --- RUNTIME LOOKUP UNTUK AKUMULASI LINTAS LAYER ---
                $lookupCpmk = [];
                foreach ($cpmk->detail->detail_mahasiswa as $mhs) {
                    $lookupCpmk[$mhs->userid] = $mhs;
                }

                $lookupIk = [];
                foreach ($indikator->detail->detail_mahasiswa as $mhs) {
                    $lookupIk[$mhs->userid] = $mhs;
                }

                $lookupCpl = [];
                foreach ($cpl->detail->detail_mahasiswa as $mhs) {
                    $lookupCpl[$mhs->userid] = $mhs;
                }

                // --- ITERASI DATA BARIS MAHASISWA ---
                $noSub = 1;
                foreach ($sub->detail->detail_mahasiswa as $mhs) {
                    $row = [];
                    $row[] = $noSub++;
                    $row[] = $mhs->nama;

                    // Nilai Aktivitas Terbobot Riil Moodle
                    foreach ($sub->detail->aktivitas_list as $index => $aktivitas) {
                        $nilaiAktivitas = '-';
                        if (isset($mhs->rincian_list[$index])) {
                            $nilaiAktivitas = round($mhs->rincian_list[$index]->nilai_terbobot, 2);
                        }
                        $row[] = $nilaiAktivitas;
                    }

                    // Gabungkan Nilai Capaian Lintas Hierarki Tree
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
// Tutup Aliran File CSV
// ======================================================
fclose($output);
exit;