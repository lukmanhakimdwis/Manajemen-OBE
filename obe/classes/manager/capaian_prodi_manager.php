<?php

namespace local_obe\manager;

use local_obe\manager\capaian_matakuliah_manager;
defined('MOODLE_INTERNAL') || die();



class capaian_prodi_manager {
    

    /**
     * Mengambil detail capaian kompetensi seluruh mahasiswa
     * pada tingkat Program Studi
     * (gabungan seluruh mata kuliah).
     */
    public static function ambil_detail_kompetensi_mahasiswa_prodi( $prodi_id,  $kompetensi_id,  $tipe,  $id_tahun_akademik = 0,  $angkatan_id = 0) {
    global $DB, $SESSION;

    if (empty($id_tahun_akademik)) {
        $id_tahun_akademik = $SESSION->obe_id_tahun_akademik ?? 0;
    }

    // ======================================================
    // Ambil seluruh mata kuliah pada prodi
    // ======================================================
    $matakuliah = $DB->get_records(
        'local_obe_mata_kuliah',
        ['id_prodi' => $prodi_id],
        'nama_mk ASC'
    );

    if (empty($matakuliah)) {
        return (object)[
            'aktivitas_list'   => [],
            'ringkasan'        => null,
            'detail_mahasiswa' => []
        ];
    }

    $detail_mahasiswa = [];

    $aktivitas_list = [];

    $sub_cpmk = null;
    $cpmk_record = null;
    $indikator_record = null;
    $cpl_record = null;

    $bobot_sub = 0;
    $bobot_cpmk = 0;
    $bobot_ind = 0;

    // ======================================================
    // Loop seluruh mata kuliah
    // ======================================================
    foreach ($matakuliah as $mk) {

        $detail = capaian_matakuliah_manager::ambil_detail_kompetensi_mahasiswa_matakuliah(
            $mk->id,
            $kompetensi_id,
            $tipe,
            $id_tahun_akademik,
            $angkatan_id
        );

        if (
            empty($detail) ||
            empty($detail->detail_mahasiswa)
        ) {
            continue;
        }

        // ==========================================
        // Metadata cukup sekali
        // ==========================================
        if (empty($aktivitas_list)) {

            $aktivitas_list = $detail->aktivitas_list;

            $sub_cpmk         = $detail->sub_cpmk;
            $cpmk_record      = $detail->cpmk_record;
            $indikator_record = $detail->indikator_record;
            $cpl_record       = $detail->cpl_record;

            $bobot_sub  = $detail->bobot_sub_display;
            $bobot_cpmk = $detail->bobot_cpmk_display;
            $bobot_ind  = $detail->bobot_ind_display;
        }

        foreach ($detail->detail_mahasiswa as $mhs) {

            $userid = $mhs->userid;

            if (!isset($detail_mahasiswa[$userid])) {

                $detail_mahasiswa[$userid] = (object)[
                    'userid'      => $mhs->userid,
                    'username'    => $mhs->username,
                    'nama'        => $mhs->nama,
                    'nilai'       => 0,
                    'grade'       => '-',
                    'kompeten'    => false,

                    'matakuliah_pendukung' => [],

                    'total_nilai' => 0,
                    'jumlah_mk'   => 0
                ];
            }

            $detail_mahasiswa[$userid]->matakuliah_pendukung[] = (object)[
                'id'    => $mk->id,
                'nama'  => $mk->nama_mk,
                'nilai' => $mhs->nilai
            ];

            $detail_mahasiswa[$userid]->total_nilai += $mhs->nilai;
            $detail_mahasiswa[$userid]->jumlah_mk++;
        }
    }

    // ======================================================
    // Hitung nilai akhir
    // ======================================================
    foreach ($detail_mahasiswa as &$mhs) {

        $mhs->nilai = round(
            $mhs->total_nilai / max(1, $mhs->jumlah_mk),   2
        );

        $mhs->grade = skala_nilai_manager::cek_skala(
            $mhs->nilai,
            $tipe,
            $prodi_id,
            $id_tahun_akademik
        );

        $mhs->kompeten = skala_nilai_manager::is_kompeten($mhs->nilai, $tipe, $prodi_id, $id_tahun_akademik);

        unset($mhs->total_nilai);
        unset($mhs->jumlah_mk);
    }
    unset($mhs);

    $detail_mahasiswa = array_values($detail_mahasiswa);

    usort($detail_mahasiswa, function($a, $b) {
        return strcasecmp($a->nama, $b->nama);
    });

    // ======================================================
    // Ringkasan
    // ======================================================
    $ringkasan = capaian_kelas_manager::buat_ringkasan_kelas(
        $detail_mahasiswa,
        $tipe,
        $prodi_id,
        $id_tahun_akademik
    );

    // ======================================================
    // Return
    // ======================================================
    return (object)[

        'sub_cpmk'           => $sub_cpmk,
        'cpmk_record'        => $cpmk_record,
        'indikator_record'   => $indikator_record,
        'cpl_record'         => $cpl_record,

        'bobot_sub_display'  => $bobot_sub,
        'bobot_cpmk_display' => $bobot_cpmk,
        'bobot_ind_display'  => $bobot_ind,

        'aktivitas_list'     => $aktivitas_list,

        'ringkasan'          => $ringkasan,

        'detail_mahasiswa'   => $detail_mahasiswa
    ];
    }
    /**
     * Mengambil statistik capaian kompetensi tingkat Program Studi
     * (gabungan seluruh mata kuliah).
     */
    public static function ambil_statistik_skala_prodi( $prodi_id, $kompetensi_id, $tipe, $id_tahun_akademik = 0,$angkatan_id = 0) {
    global $SESSION;

    if (empty($id_tahun_akademik)) {
        $id_tahun_akademik = $SESSION->obe_id_tahun_akademik ?? 0;
    }

    // ======================================================
    // Ambil detail kompetensi
    // ======================================================
    $detail = self::ambil_detail_kompetensi_mahasiswa_prodi(
        $prodi_id,
        $kompetensi_id,
        $tipe,
        $id_tahun_akademik,
        $angkatan_id
    );

    // ======================================================
    // Validasi
    // ======================================================
    if (
        empty($detail) ||
        empty($detail->detail_mahasiswa)
    ) {
        return (object)[
            'rata_rata'                 => 0,
            'grade'                     => '-',
            'total_mahasiswa'           => 0,
            'jumlah_kompeten'           => 0,
            'jumlah_belum_kompeten'     => 0,
            'persentase_kompeten'       => 0,
            'persentase_belum_kompeten' => 0,
            'distribusi_grade'          => []
        ];
    }

    // ======================================================
    // Ambil daftar mahasiswa
    // ======================================================
    $mahasiswa = $detail->detail_mahasiswa;

    $total = count($mahasiswa);

    if ($total == 0) {
        return (object)[
            'rata_rata'                 => 0,
            'grade'                     => '-',
            'total_mahasiswa'           => 0,
            'jumlah_kompeten'           => 0,
            'jumlah_belum_kompeten'     => 0,
            'persentase_kompeten'       => 0,
            'persentase_belum_kompeten' => 0,
            'distribusi_grade'          => []
        ];
    }

    // ======================================================
    // Hitung Statistik
    // ======================================================
    $totalnilai = 0;
    $jumlahkompeten = 0;
    $distribusi = [];

    foreach ($mahasiswa as $mhs) {

        $totalnilai += $mhs->nilai;

        if ($mhs->kompeten) {
            $jumlahkompeten++;
        }

        $grade = strtoupper(trim($mhs->grade));

        if ($grade === '') {
            $grade = '-';
        }

        if (!isset($distribusi[$grade])) {

            $distribusi[$grade] = (object)[
                'huruf'       => $grade,
                'jumlah'      => 0,
                'persentase'  => 0
            ];
        }

        $distribusi[$grade]->jumlah++;
    }

    // ======================================================
    // Hitung rata-rata
    // ======================================================
    $ratarata = round($totalnilai / $total, 2);

    // ======================================================
    // Konversi ke grade
    // ======================================================
    $grade_rata = skala_nilai_manager::cek_skala(
        $ratarata,
        $tipe,
        $prodi_id,
        $id_tahun_akademik
    );

    // ======================================================
    // Hitung persentase distribusi
    // ======================================================
    foreach ($distribusi as $key => $item) {

        $distribusi[$key]->persentase =
            round(($item->jumlah / $total) * 100, 2); }

    $jumlahbelum = $total - $jumlahkompeten;

    return (object)[
        'rata_rata'                 => $ratarata,
        'grade'                     => $grade_rata,

        'total_mahasiswa'           => $total,

        'jumlah_kompeten'           => $jumlahkompeten,
        'jumlah_belum_kompeten'     => $jumlahbelum,

        'persentase_kompeten'       => round(($jumlahkompeten / $total) * 100, 2),
        'persentase_belum_kompeten' => round(($jumlahbelum / $total) * 100, 2),

        'distribusi_grade'          => array_values($distribusi)
    ];
    }
        /**
     * Mengambil capaian Sub CPMK tingkat Program Studi
     * (gabungan seluruh mata kuliah).
     */
   public static function ambil_capaian_subcpmk_prodi( $prodi_id, $cpmk_id, $id_tahun_akademik = 0, $angkatan_id = 0) {
        global $DB, $SESSION;

        if (empty($id_tahun_akademik)) {
            $id_tahun_akademik = $SESSION->obe_id_tahun_akademik ?? 0;
        }

        // =====================================
        // UBAH BAGIAN INI: Ambil struktur Sub CPMK dengan filter id_prodi ketat
        // =====================================
        $subcpmk = $DB->get_records('local_obe_kompetensi', [
            'parent_id'         => $cpmk_id,
            'tipe'              => 'SUB-CPMK',
            'id_prodi'          => $prodi_id,
            'id_tahun_akademik' => $id_tahun_akademik
        ], 'id ASC');
        // =====================================

        if (empty($subcpmk)) {
            return [];
        }

        $hasil = [];

        foreach ($subcpmk as $sub) {

            // Ambil deskripsi Sub CPMK
            $kompetensi = $DB->get_record(
                'local_obe_kompetensi',
                ['id' => $sub->id],
                'deskripsi',
                IGNORE_MISSING
            );

            // Hitung statistik tingkat Prodi
            $statistik = self::ambil_statistik_skala_prodi(
                $prodi_id,
                $sub->id,
                'SUB-CPMK',
                $id_tahun_akademik,
                $angkatan_id
            );

            $hasil[] = (object)[
                'id'            => $sub->id,
                'subcpmk_id'    => $sub->id,
                'nama_subcpmk'  => $sub->nama_kompetensi, // Sesuaikan properti field database asli
                'deskripsi'     => $kompetensi->deskripsi ?? '',
                'bobot'         => capaian_kelas_manager::ambil_bobot_relasi($cpmk_id, $sub->id, $id_tahun_akademik),

                'percentage'    => $statistik->rata_rata,
                'grade'         => $statistik->grade
            ];
        }

        return $hasil;
    }
    /**
     * Mengambil capaian CPMK tingkat Program Studi
     * (gabungan seluruh mata kuliah).
     */
    public static function ambil_capaian_cpmk_prodi( $prodi_id,$indikator_id,$id_tahun_akademik = 0,$angkatan_id = 0) {
        global $DB, $SESSION;

        if (empty($id_tahun_akademik)) {
            $id_tahun_akademik = $SESSION->obe_id_tahun_akademik ?? 0;
        }

        // =====================================
        // UBAH BAGIAN INI: Tarik CPMK dengan filter prodi aman
        // =====================================
        $cpmk_data = $DB->get_records('local_obe_kompetensi', [
            'parent_id'         => $indikator_id,
            'tipe'              => 'CPMK',
            'id_prodi'          => $prodi_id,
            'id_tahun_akademik' => $id_tahun_akademik
        ], 'id ASC');
        // =====================================

        if (empty($cpmk_data)) {
            return [];
        }

        $hasil = [];

        foreach ($cpmk_data as $cpmk) {

            $kompetensi = $DB->get_record(
                'local_obe_kompetensi',
                ['id' => $cpmk->id],
                'deskripsi',
                IGNORE_MISSING
            );

            $statistik = self::ambil_statistik_skala_prodi(
                $prodi_id,
                $cpmk->id,
                'CPMK',
                $id_tahun_akademik,
                $angkatan_id
            );

            $hasil[] = (object)[
                'id'          => $cpmk->id,
                'cpmk_id'     => $cpmk->id,
                'nama_cpmk'   => $cpmk->nama_kompetensi, // Sesuaikan properti field database asli
                'deskripsi'   => $kompetensi->deskripsi ?? '',
                'bobot'       => capaian_kelas_manager::ambil_bobot_relasi($indikator_id, $cpmk->id, $id_tahun_akademik),

                'percentage'  => $statistik->rata_rata ?? 0,
                'grade'       => $statistik->grade ?? '-'
            ];
        }

        return $hasil;
    }
    /**
     * Mengambil capaian Indikator tingkat Program Studi
     * (gabungan seluruh mata kuliah).
     */
    public static function ambil_capaian_indikator_prodi($prodi_id,$cpl_id,$id_tahun_akademik = 0,$angkatan_id = 0) {
        global $DB, $SESSION;

        if (empty($id_tahun_akademik)) {
            $id_tahun_akademik = $SESSION->obe_id_tahun_akademik ?? 0;
        }

        // =====================================
        // UBAH BAGIAN INI: Tarik Indikator dengan filter prodi aman
        // =====================================
        $indikator_data = $DB->get_records('local_obe_kompetensi', [
            'parent_id'         => $cpl_id,
            'tipe'              => 'INDIKATOR',
            'id_prodi'          => $prodi_id,
            'id_tahun_akademik' => $id_tahun_akademik
        ], 'id ASC');
        // =====================================

        if (empty($indikator_data)) {
            return [];
        }

        $hasil = [];

        foreach ($indikator_data as $indikator) {

            $kompetensi = $DB->get_record(
                'local_obe_kompetensi',
                ['id' => $indikator->id],
                'deskripsi',
                IGNORE_MISSING
            );

            $statistik = self::ambil_statistik_skala_prodi(
                $prodi_id,
                $indikator->id,
                'INDIKATOR',
                $id_tahun_akademik,
                $angkatan_id
            );

            $hasil[] = (object)[
                'id'              => $indikator->id,
                'nama'            => $indikator->nama_kompetensi, // Sesuaikan properti field database asli
                'deskripsi'       => $kompetensi->deskripsi ?? '',
                'bobot'           => capaian_kelas_manager::ambil_bobot_relasi($cpl_id, $indikator->id, $id_tahun_akademik),

                'total_indikator' => $statistik->rata_rata ?? 0,
                'grade'           => $statistik->grade ?? '-'
            ];
        }

        return $hasil;
    }
    /**
     * Mengambil capaian CPL tingkat Program Studi.
     */
    public static function ambil_capaian_cpl_prodi(  $prodi_id, $id_tahun_akademik = 0, $angkatan_id = 0) {
        global $DB, $SESSION;

        if (empty($id_tahun_akademik)) {
            $id_tahun_akademik = $SESSION->obe_id_tahun_akademik ?? 0;
        }

        // =====================================
        // UBAH BAGIAN INI: Tarik CPL tingkat root ter-filter prodi_id
        // =====================================
        $cpl_data = $DB->get_records('local_obe_kompetensi', [
            'parent_id'         => 0,
            'tipe'              => 'CPL',
            'id_prodi'          => $prodi_id,
            'id_tahun_akademik' => $id_tahun_akademik
        ], 'id ASC');
        // =====================================

        if (empty($cpl_data)) {
            return [];
        }

        $hasil = [];

        foreach ($cpl_data as $cpl) {

            // Ambil deskripsi CPL
            $kompetensi = $DB->get_record(
                'local_obe_kompetensi',
                ['id' => $cpl->id],
                'deskripsi',
                IGNORE_MISSING
            );

            // Hitung statistik capaian prodi
            $statistik = self::ambil_statistik_skala_prodi(
                $prodi_id,
                $cpl->id,
                'CPL',
                $id_tahun_akademik,
                $angkatan_id
            );

            $hasil[] = (object)[
                'id'         => $cpl->id,
                'cpl_id'     => $cpl->id,
                'nama_cpl'   => $cpl->nama_kompetensi, // Sesuaikan properti field database asli
                'deskripsi'  => $kompetensi->deskripsi ?? '',

                'percentage' => $statistik->rata_rata ?? 0,
                'grade'      => $statistik->grade ?? '-'
            ];
        }

        return $hasil;
    }
    /**
     * Mengambil seluruh data yang dibutuhkan untuk export Prodi.
     */
    public static function ambil_data_export_prodi( $prodi_id, $id_tahun_akademik = 0, $angkatan_id = 0) {
        global $SESSION;

        if (empty($id_tahun_akademik)) {
            $id_tahun_akademik = $SESSION->obe_id_tahun_akademik ?? 0;
        }

        // =====================================================
        // Ambil Hierarki Kompetensi
        // =====================================================
        $hierarki = self::ambil_hierarki_prodi(
            $prodi_id,
            $id_tahun_akademik
        );

        if (empty($hierarki)) {
            return [];
        }

        $hasil = [];

        foreach ($hierarki as $cpl) {

            // =====================================================
            // DETAIL CPL
            // =====================================================
            $detailCpl = self::ambil_detail_kompetensi_mahasiswa_prodi(
                $prodi_id,
                $cpl->id,
                'CPL',
                $id_tahun_akademik,
                $angkatan_id
            );

            $ringkasanCpl = self::ambil_statistik_skala_prodi(
                $prodi_id,
                $cpl->id,
                'CPL',
                $id_tahun_akademik,
                $angkatan_id
            );

            $indikatorList = [];

            foreach ($cpl->indikator_list as $indikator) {

                // =====================================================
                // DETAIL INDIKATOR
                // =====================================================
                $detailIndikator = self::ambil_detail_kompetensi_mahasiswa_prodi(
                    $prodi_id,
                    $indikator->id,
                    'INDIKATOR',
                    $id_tahun_akademik,
                    $angkatan_id
                );

                $ringkasanIndikator = self::ambil_statistik_skala_prodi(
                    $prodi_id,
                    $indikator->id,
                    'INDIKATOR',
                    $id_tahun_akademik,
                    $angkatan_id
                );

                $cpmkList = [];

                foreach ($indikator->cpmk_list as $cpmk) {

                    // =====================================================
                    // DETAIL CPMK
                    // =====================================================
                    $detailCpmk = self::ambil_detail_kompetensi_mahasiswa_prodi(
                        $prodi_id,
                        $cpmk->id,
                        'CPMK',
                        $id_tahun_akademik,
                        $angkatan_id
                    );

                    $ringkasanCpmk = self::ambil_statistik_skala_prodi(
                        $prodi_id,
                        $cpmk->id,
                        'CPMK',
                        $id_tahun_akademik,
                        $angkatan_id
                    );

                    $subcpmkList = [];

                    foreach ($cpmk->subcpmk_list as $sub) {

                        // =====================================================
                        // DETAIL SUB CPMK
                        // =====================================================
                        $detailSub = self::ambil_detail_kompetensi_mahasiswa_prodi(
                            $prodi_id,
                            $sub->id,
                            'SUB-CPMK',
                            $id_tahun_akademik,
                            $angkatan_id
                        );

                        $ringkasanSub = self::ambil_statistik_skala_prodi(
                            $prodi_id,
                            $sub->id,
                            'SUB-CPMK',
                            $id_tahun_akademik,
                            $angkatan_id
                        );

                        $subcpmkList[] = (object)[
                            'kompetensi' => $sub,

                            'ringkasan'  => $ringkasanSub,

                            'mahasiswa'  => $detailSub
                        ];
                    }

                    $cpmkList[] = (object)[
                        'kompetensi'   => $cpmk,

                        'ringkasan'    => $ringkasanCpmk,

                        'mahasiswa'    => $detailCpmk,

                        'subcpmk_list' => $subcpmkList
                    ];
                }

                $indikatorList[] = (object)[
                    'kompetensi' => $indikator,

                    'ringkasan'  => $ringkasanIndikator,

                    'mahasiswa'  => $detailIndikator,

                    'cpmk_list'  => $cpmkList
                ];
            }

            $hasil[] = (object)[
                'kompetensi'     => $cpl,

                'ringkasan'      => $ringkasanCpl,

                'mahasiswa'      => $detailCpl,

                'indikator_list' => $indikatorList
            ];
        }

        return $hasil;
    }
        /**
     * Mengambil struktur hierarki kompetensi Program Studi
     * CPL -> Indikator -> CPMK -> Sub CPMK
     */
 public static function ambil_hierarki_prodi($prodi_id, $id_tahun_akademik = 0) {
        global $DB, $SESSION;

        if (empty($id_tahun_akademik)) {
            $id_tahun_akademik = $SESSION->obe_id_tahun_akademik ?? 0;
        }

        // ======================================================
        // UBAH BAGIAN INI: Ambil seluruh CPL milik prodi ini langsung dari tabel
        // ======================================================
        $cpl_list = $DB->get_records('local_obe_kompetensi', [
            'parent_id'         => 0,
            'tipe'              => 'CPL',
            'id_prodi'          => $prodi_id,
            'id_tahun_akademik' => $id_tahun_akademik
        ], 'id ASC');
        // ======================================================

        if (empty($cpl_list)) {
            return [];
        }

        $hasil = [];

        foreach ($cpl_list as $cpl) {

            // ==========================================
            // Ambil Indikator (Filter prodi ketat)
            // ==========================================
            $indikator_list = $DB->get_records('local_obe_kompetensi', [
                'parent_id'         => $cpl->id,
                'tipe'              => 'INDIKATOR',
                'id_prodi'          => $prodi_id,
                'id_tahun_akademik' => $id_tahun_akademik
            ], 'id ASC');

            $indikator_data = [];

            foreach ($indikator_list as $indikator) {

                // ==========================================
                // Ambil CPMK (Filter prodi ketat)
                // ==========================================
                $cpmk_list = $DB->get_records('local_obe_kompetensi', [
                    'parent_id'         => $indikator->id,
                    'tipe'              => 'CPMK',
                    'id_prodi'          => $prodi_id,
                    'id_tahun_akademik' => $id_tahun_akademik
                ], 'id ASC');

                $cpmk_data = [];

                foreach ($cpmk_list as $cpmk) {

                    // ==========================================
                    // Ambil Sub CPMK (Filter prodi ketat)
                    // ==========================================
                    $sub_list_records = $DB->get_records('local_obe_kompetensi', [
                        'parent_id'         => $cpmk->id,
                        'tipe'              => 'SUB-CPMK',
                        'id_prodi'          => $prodi_id,
                        'id_tahun_akademik' => $id_tahun_akademik
                    ], 'id ASC');

                    $sub_list = [];
                    foreach ($sub_list_records as $sub) {
                        $sub_list[] = (object)[
                            'id'    => $sub->id,
                            'nama'  => $sub->nama_kompetensi,
                            'tipe'  => $sub->tipe,
                            'bobot' => capaian_kelas_manager::ambil_bobot_relasi(
                                $cpmk->id,
                                $sub->id,
                                $id_tahun_akademik
                            )
                        ];
                    }

                    $cpmk_data[] = (object)[
                        'id'           => $cpmk->id,
                        'nama'         => $cpmk->nama_kompetensi,
                        'bobot'        => capaian_kelas_manager::ambil_bobot_relasi(
                            $indikator->id,
                            $cpmk->id,
                            $id_tahun_akademik
                        ),
                        'subcpmk_list' => $sub_list
                    ];
                }

                $indikator_data[] = (object)[
                    'id'        => $indikator->id,
                    'nama'      => $indikator->nama_kompetensi,
                    'bobot'     => capaian_kelas_manager::ambil_bobot_relasi(
                        $cpl->id,
                        $indikator->id,
                        $id_tahun_akademik
                    ),
                    'cpmk_list' => $cpmk_data
                ];
            }

            $hasil[] = (object)[
                'id'             => $cpl->id,
                'nama'           => $cpl->nama_kompetensi,
                'deskripsi'      => $cpl->deskripsi ?? '',
                'indikator_list' => $indikator_data
            ];
        }

        return $hasil;
    }

}
?>