<?php
namespace local_obe\manager;
defined('MOODLE_INTERNAL') || die();

class capaian_mahasiswa_manager {
    /**
 * Mengambil seluruh mata kuliah pendukung pada suatu Sub-CPMK
 * beserta nilai mahasiswa.
 */
  public static function ambil_matakuliah_pendukung_subcpmk( $userid, $subcpmk_id, $prodi_id, $id_tahun_akademik = 0) {
    global $DB, $SESSION;

    if (empty($id_tahun_akademik)) {
        $id_tahun_akademik = $SESSION->obe_id_tahun_akademik ?? 0;
    }

    // ============================================
    // Ambil seluruh mata kuliah pada prodi
    // ============================================
    $matakuliah = $DB->get_records(
        'local_obe_mata_kuliah',
        ['id_prodi' => $prodi_id],
        'kode_mk ASC'
    );

    if (empty($matakuliah)) {
        return [];
    }

    $hasil = [];

    foreach ($matakuliah as $mk) {

        // ============================================
        // Ambil detail mahasiswa pada Sub CPMK
        // ============================================
        $detail = capaian_matakuliah_manager::ambil_detail_kompetensi_mahasiswa_matakuliah(
            $mk->id,
            $subcpmk_id,
            'SUB-CPMK',
            $id_tahun_akademik
        );

        if (empty($detail) || empty($detail->detail_mahasiswa)) {
            continue;
        }

        // ============================================
        // Cari mahasiswa yang sesuai
        // ============================================
        foreach ($detail->detail_mahasiswa as $mhs) {

            if (empty($mhs->userid)) {
                continue;
            }

            if ((int)$mhs->userid !== (int)$userid) {
                continue;
            }

            $hasil[] = (object)[
                'matkul_id'  => $mk->id,
                'kode_mk'    => $mk->kode_mk,
                'nama_mk'    => $mk->nama_mk,

                'courseid'   => $mhs->courseid ?? 0,
                'nama_kelas' => $mhs->namakelas ?? '-',

                'nilai'      => $mhs->nilai,
                'grade'      => $mhs->grade,
                'kompeten'   => $mhs->kompeten
            ];

            // Mahasiswa hanya satu kali pada setiap mata kuliah
            break;
        }
    }

    // ============================================
    // Urutkan berdasarkan kode mata kuliah
    // ============================================
    usort($hasil, function($a, $b) {

        $kode = strcasecmp($a->kode_mk, $b->kode_mk);

        if ($kode !== 0) {
            return $kode;
        }

        return strcasecmp($a->nama_mk, $b->nama_mk);
    });

    return $hasil;
}
    /**
 * Mengambil detail capaian satu Sub-CPMK mahasiswa.
 */
    public static function ambil_detail_subcpmk( $userid, $subcpmk_id, $prodi_id, $id_tahun_akademik = 0) {
        global $DB, $SESSION;

        if (empty($id_tahun_akademik)) {
            $id_tahun_akademik = $SESSION->obe_id_tahun_akademik ?? 0;
        }

        // =====================================================
        // Ambil data Sub CPMK
        // =====================================================
        $subcpmk = $DB->get_record(
            'local_obe_kompetensi',
            ['id' => $subcpmk_id],
            '*',
            MUST_EXIST
        );

        // =====================================================
        // Ambil seluruh mata kuliah pendukung
        // =====================================================
        $matakuliah = self::ambil_matakuliah_pendukung_subcpmk(
            $userid,
            $subcpmk_id,
            $prodi_id,
            $id_tahun_akademik
        );

        // =====================================================
        // Hitung rata-rata
        // =====================================================
        $jumlah = count($matakuliah);

        $total = 0;

        foreach ($matakuliah as $mk) {
            $total += $mk->nilai;
        }

        $nilai = ($jumlah > 0)
            ? round($total / $jumlah, 2)
            : 0;

        // =====================================================
        // Hitung grade
        // =====================================================
        $grade = skala_nilai_manager::cek_skala(
            $nilai,
            'SUB-CPMK',
            $prodi_id,
            $id_tahun_akademik
        );

        // =====================================================
        // Hitung status kompeten
        // =====================================================
        $ambang = skala_nilai_manager::ambil_ambang_batas_kompeten(
            $prodi_id,
            $id_tahun_akademik,
            'SUB-CPMK'
        );

        $kompeten = (
            skala_nilai_manager::is_kompeten($nilai, 'SUB-CPMK', $prodi_id, $id_tahun_akademik)
        );

        return (object)[
            'id' => $subcpmk->id,
            'nama' => $subcpmk->nama_kompetensi,
            'deskripsi' => $subcpmk->deskripsi,

            'nilai' => $nilai,
            'grade' => $grade,
            'kompeten' => $kompeten,

            'matakuliah_pendukung' => $matakuliah
        ];
    }
    /**
     * Mengambil detail capaian satu CPMK mahasiswa.
     */
    public static function ambil_detail_cpmk( $userid, $cpmk_id, $prodi_id, $id_tahun_akademik = 0 ) {
        global $DB, $SESSION;

        if (empty($id_tahun_akademik)) {
            $id_tahun_akademik = $SESSION->obe_id_tahun_akademik ?? 0;
        }

        // ============================================
        // Ambil data CPMK
        // ============================================
        $cpmk = $DB->get_record(
            'local_obe_kompetensi',
            ['id' => $cpmk_id],
            '*',
            MUST_EXIST
        );

        // ============================================
        // Ambil seluruh Sub CPMK
        // ============================================
        $subcpmk_list = capaian_kelas_manager::ambil_struktur_kompetensi_kelas(
            $cpmk_id,
            'SUB-CPMK',
            $id_tahun_akademik
        );

        $detail_subcpmk = [];

        $total_bobot = 0;
        $total_nilai = 0;

        foreach ($subcpmk_list as $sub) {

            $detail = self::ambil_detail_subcpmk(
                $userid,
                $sub->id,
                $prodi_id,
                $id_tahun_akademik
            );

            $detail->bobot = $sub->bobot;

            $detail_subcpmk[] = $detail;

            $total_bobot += $sub->bobot;
            $total_nilai += ($detail->nilai * $sub->bobot);
        }

        // ============================================
        // Hitung nilai CPMK
        // ============================================
        $nilai = ($total_bobot > 0)
            ? round($total_nilai / 100, 2)
            : 0;

        // ============================================
        // Hitung grade
        // ============================================
        $grade = skala_nilai_manager::cek_skala(
            $nilai,
            'CPMK',
            $prodi_id,
            $id_tahun_akademik
        );

        // ============================================
        // Hitung status kompeten
        // ============================================
        $ambang = skala_nilai_manager::ambil_ambang_batas_kompeten(
            $prodi_id,
            $id_tahun_akademik,
            'CPMK'
        );

        $kompeten = (
            skala_nilai_manager::is_kompeten($nilai, 'CPMK', $prodi_id, $id_tahun_akademik)
        );

        return (object)[
            'id' => $cpmk->id,
            'nama' => $cpmk->nama_kompetensi,
            'deskripsi' => $cpmk->deskripsi,

            'nilai' => $nilai,
            'grade' => $grade,
            'kompeten' => $kompeten,

            'subcpmk_list' => $detail_subcpmk
        ];
    }
    /**
     * Mengambil detail capaian satu Indikator mahasiswa.
     */
    public static function ambil_detail_indikator(  $userid, $indikator_id, $prodi_id, $id_tahun_akademik = 0) {
        global $DB, $SESSION;

        if (empty($id_tahun_akademik)) {
            $id_tahun_akademik = $SESSION->obe_id_tahun_akademik ?? 0;
        }

        // ======================================================
        // Ambil data indikator
        // ======================================================
        $indikator = $DB->get_record(
            'local_obe_kompetensi',
            ['id' => $indikator_id],
            '*',
            MUST_EXIST
        );

        // ======================================================
        // Ambil seluruh CPMK
        // ======================================================
        $cpmk_list = capaian_kelas_manager::ambil_struktur_kompetensi_kelas(
            $indikator_id,
            'CPMK',
            $id_tahun_akademik
        );

        $detail_cpmk = [];

        $totalbobot = 0;
        $totalnilai = 0;

        foreach ($cpmk_list as $cpmk) {

            $detail = self::ambil_detail_cpmk(
                $userid,
                $cpmk->id,
                $prodi_id,
                $id_tahun_akademik
            );

            $detail->bobot = $cpmk->bobot;

            $detail_cpmk[] = $detail;

            $totalbobot += $cpmk->bobot;
            $totalnilai += ($detail->nilai * $cpmk->bobot);
        }

        // ======================================================
        // Hitung nilai indikator
        // ======================================================
        $nilai = ($totalbobot > 0)
            ? round($totalnilai / 100, 2)
            : 0;

        // ======================================================
        // Grade
        // ======================================================
        $grade = skala_nilai_manager::cek_skala(
            $nilai,
            'INDIKATOR',
            $prodi_id,
            $id_tahun_akademik
        );

        // ======================================================
        // Kompeten
        // ======================================================
        $ambang = skala_nilai_manager::ambil_ambang_batas_kompeten(
            $prodi_id,
            $id_tahun_akademik,
            'INDIKATOR'
        );

        $kompeten = (
            skala_nilai_manager::is_kompeten($nilai, 'INDIKATOR', $prodi_id, $id_tahun_akademik)
        );

        return (object)[
            'id' => $indikator->id,
            'nama' => $indikator->nama_kompetensi,
            'deskripsi' => $indikator->deskripsi,

            'nilai' => $nilai,
            'grade' => $grade,
            'kompeten' => $kompeten,

            'cpmk_list' => $detail_cpmk
        ];
    }
    /**
     * Mengambil detail capaian satu CPL mahasiswa.
     */
   public static function ambil_detail_cpl( $userid, $cpl_id, $prodi_id, $id_tahun_akademik = 0) {
    global $DB, $SESSION;

    if (empty($id_tahun_akademik)) {
        $id_tahun_akademik = $SESSION->obe_id_tahun_akademik ?? 0;
    }

    // ==========================================
    // Data CPL
    // ==========================================
    $cpl = $DB->get_record(
        'local_obe_kompetensi',
        ['id' => $cpl_id],
        '*',
        MUST_EXIST
    );

    // ==========================================
    // Ambil seluruh mata kuliah prodi
    // ==========================================
    $matakuliah = $DB->get_records(
        'local_obe_mata_kuliah',
        ['id_prodi' => $prodi_id],
        'kode_mk ASC'
    );

    $totalnilai = 0;
    $jumlahmk   = 0;

    $detail_matakuliah = [];

    foreach ($matakuliah as $mk) {

        $detail = capaian_matakuliah_manager::ambil_detail_kompetensi_mahasiswa_matakuliah(
            $mk->id,
            $cpl_id,
            'CPL',
            $id_tahun_akademik
        );

        if (empty($detail) || empty($detail->detail_mahasiswa)) {
            continue;
        }

        foreach ($detail->detail_mahasiswa as $mhs) {

            if (empty($mhs->userid)) {
                continue;
            }

            if ((int)$mhs->userid !== (int)$userid) {
                continue;
            }

            $detail_matakuliah[] = (object)[
                'matkul_id' => $mk->id,
                'kode_mk'   => $mk->kode_mk,
                'nama_mk'   => $mk->nama_mk,
                'courseid'  => $mhs->courseid ?? 0,
                'namakelas' => $mhs->namakelas ?? '-',

                'nilai'     => $mhs->nilai,
                'grade'     => $mhs->grade,
                'kompeten'  => $mhs->kompeten
            ];

            $totalnilai += $mhs->nilai;
            $jumlahmk++;

            // Satu mahasiswa hanya satu kali pada satu mata kuliah
            break;
        }
    }

    // ==========================================
    // Nilai rata-rata CPL
    // ==========================================
    $nilai = ($jumlahmk > 0)
        ? round($totalnilai / $jumlahmk, 2)
        : 0;

    $grade = skala_nilai_manager::cek_skala(
        $nilai,
        'CPL',
        $prodi_id,
        $id_tahun_akademik
    );

    $ambang = skala_nilai_manager::ambil_ambang_batas_kompeten(
        $prodi_id,
        $id_tahun_akademik,
        'CPL'
    );

    // ==========================================
    // Detail indikator
    // ==========================================
    $indikator_list = [];

    foreach (
        capaian_kelas_manager::ambil_struktur_kompetensi_kelas(
            $cpl_id,
            'INDIKATOR',
            $id_tahun_akademik
        ) as $indikator
    ) {

        $indikator_list[] = self::ambil_detail_indikator(
            $userid,
            $indikator->id,
            $prodi_id,
            $id_tahun_akademik
        );
    }

    return (object)[
        'id'                    => $cpl->id,
        'nama'                  => $cpl->nama_kompetensi,
        'deskripsi'             => $cpl->deskripsi,

        'nilai'                 => $nilai,
        'grade'                 => $grade,
        'kompeten'              => (skala_nilai_manager::is_kompeten($nilai, 'INDIKATOR', $prodi_id, $id_tahun_akademik)),

        'indikator_list'        => $indikator_list,
        'matakuliah_pendukung'  => $detail_matakuliah
    ];
    }
    /**
     * Mengambil seluruh trace capaian CPL mahasiswa.
     */
    public static function ambil_trace_cpl_mahasiswa($userid, $prodi_id, $id_tahun_akademik = 0) {
        global $DB, $SESSION;

        if (empty($id_tahun_akademik)) {
            $id_tahun_akademik = $SESSION->obe_id_tahun_akademik ?? 0;
        }

        // =====================================================
        // Ambil seluruh CPL pada prodi
        // =====================================================
        $cpl_list = $DB->get_records(
            'local_obe_kompetensi',
            [
                'id_prodi' => $prodi_id,
                'tipe' => 'CPL',
                'id_tahun_akademik' => $id_tahun_akademik
            ],
            'competencyid ASC'
        );

        if (empty($cpl_list)) {
            return [];
        }

        $hasil = [];

        foreach ($cpl_list as $cpl) {

            $hasil[] = self::ambil_detail_cpl(
                $userid,
                $cpl->id,
                $prodi_id,
                $id_tahun_akademik
            );
        }

        return $hasil;
    }
    public static function ambil_data_export_mahasiswa($userid, $prodi_id, $id_tahun_akademik = 0) {
    global $DB, $SESSION;

    if (empty($id_tahun_akademik)) {
        $id_tahun_akademik = $SESSION->obe_id_tahun_akademik ?? 0;
    }

    // Data mahasiswa
    $mahasiswa = $DB->get_record(
        'user',
        ['id' => $userid],
        '*',
        MUST_EXIST
    );

    // Seluruh pohon capaian mahasiswa
    $trace = self::ambil_trace_cpl_mahasiswa(
        $userid,
        $prodi_id,
        $id_tahun_akademik
    );

    return (object)[
        'mahasiswa' => $mahasiswa,
        'trace' => $trace
    ];
}
}