<?php

namespace local_obe\manager;
use local_obe\manager\capaian_kelas_manager;
use local_obe\manager\skala_nilai_manager;

defined('MOODLE_INTERNAL') || die();



class capaian_matakuliah_manager {
  

    /**
     * Mengambil detail capaian kompetensi seluruh mahasiswa
     * pada semua kelas yang berada dalam satu mata kuliah.
     */
    public static function ambil_detail_kompetensi_mahasiswa_matakuliah($matkul_id, $kompetensi_id, $tipe, $id_tahun_akademik = 0, $angkatan_id = 0) {
    global $DB, $SESSION;

    if (empty($id_tahun_akademik)) {
        $id_tahun_akademik = $SESSION->obe_id_tahun_akademik ?? 0;
    }

    // ======================================================
    // Ambil seluruh kelas pada Mata Kuliah
    // ======================================================
    $params = [
        'id_mata_kuliah'    => $matkul_id,
        'id_tahun_akademik' => $id_tahun_akademik
    ];

    if ($angkatan_id > 0) {
        $params['id_angkatan'] = $angkatan_id;
    }

    $kelas = $DB->get_records(
        'local_obe_relasi_kelas',
        $params,
        'id_kursus_moodle ASC'
    );

    if (empty($kelas)) {
        return (object)[
            'sub_cpmk'           => null,
            'cpmk_record'        => null,
            'indikator_record'   => null,
            'cpl_record'         => null,

            'bobot_sub_display'  => 0,
            'bobot_cpmk_display' => 0,
            'bobot_ind_display'  => 0,

            'aktivitas_list'     => [],
            'ringkasan'          => null,
            'detail_mahasiswa'   => []
        ];
    }

    // ======================================================
    // Variabel Gabungan
    // ======================================================
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
    // Loop seluruh kelas
    // ======================================================
    foreach ($kelas as $k) {

        if (empty($k->id_kursus_moodle)) {
            continue;
        }

        try {
            $course = get_course($k->id_kursus_moodle);
        } catch (\Exception $e) {
            continue;
        }

        if (!capaian_kelas_manager::supports($k->id_kursus_moodle, $kompetensi_id)) { continue; }
        $detail = capaian_kelas_manager::ambil_detail_kompetensi_mahasiswa(
            $k->id_kursus_moodle,
            $kompetensi_id,
            $tipe,
            $id_tahun_akademik
        );

        if (empty($detail)) {
            continue;
        }

        // ==================================================
        // Metadata cukup diambil sekali
        // ==================================================
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

        if (empty($detail->detail_mahasiswa)) {
            continue;
        }

        // ==================================================
        // Gabungkan mahasiswa
        // ==================================================
        foreach ($detail->detail_mahasiswa as $mhs) {

            $mhs->courseid  = $course->id;
            $mhs->shortname = $course->shortname;
            $mhs->namakelas = $course->fullname;

            $detail_mahasiswa[] = $mhs;
        }
    }

    // ======================================================
    // Sorting
    // ======================================================
    $latest = [];
    foreach ($detail_mahasiswa as $row) {
        if (!isset($latest[$row->userid]) || $row->courseid > $latest[$row->userid]->courseid) { $latest[$row->userid] = $row; }
    }
    $detail_mahasiswa = array_values($latest);
    usort($detail_mahasiswa, function($a, $b) {

        $kelas = strcmp($a->namakelas, $b->namakelas);

        if ($kelas !== 0) {
            return $kelas;
        }

        return strcmp($a->nama, $b->nama);
    });

    // ======================================================
    // Ringkasan gabungan
    // ======================================================
    $prodi_id = $DB->get_field(
        'local_obe_mata_kuliah',
        'id_prodi',
        ['id' => $matkul_id]
    );

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
     * Mengambil statistik capaian kompetensi pada level Mata Kuliah
     * (gabungan seluruh kelas paralel).
     */
    public static function ambil_statistik_skala_matakuliah( $matkul_id,$kompetensi_id,$tipe,$id_tahun_akademik = 0,$angkatan_id = 0) {
    global $DB, $SESSION;

    if (empty($id_tahun_akademik)) {
        $id_tahun_akademik = $SESSION->obe_id_tahun_akademik ?? 0;
    }

    // ======================================================
    // Ambil detail mahasiswa
    // ======================================================
    $detail = self::ambil_detail_kompetensi_mahasiswa_matakuliah(
        $matkul_id,
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

    // ======================================================
    // Ambil Prodi
    // ======================================================
    $prodi_id = $DB->get_field(
        'local_obe_mata_kuliah',
        'id_prodi',
        ['id' => $matkul_id]
    );

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
    // Grade rata-rata
    // ======================================================
    $grade_rata = skala_nilai_manager::cek_skala(
        $ratarata,
        $tipe,
        $prodi_id,
        $id_tahun_akademik
    );

    // ======================================================
    // Persentase distribusi grade
    // ======================================================
    foreach ($distribusi as $key => $item) {

        $distribusi[$key]->persentase =     round(($item->jumlah / $total) * 100, 2);
    }

    $jumlahbelum = $total - $jumlahkompeten;

    return (object)[

        'rata_rata' => $ratarata,

        'grade' => $grade_rata,

        'total_mahasiswa' => $total,

        'jumlah_kompeten' => $jumlahkompeten,

        'jumlah_belum_kompeten' => $jumlahbelum,

        'persentase_kompeten' =>
            round(($jumlahkompeten / $total) * 100, 2),

        'persentase_belum_kompeten' =>
            round(($jumlahbelum / $total) * 100, 2),

        'distribusi_grade' => array_values($distribusi)
    ];
}
    public static function ambil_capaian_subcpmk_matakuliah($matkul_id,$cpmk_id, $id_tahun_akademik = 0, $angkatan_id = 0) {
        global $DB, $SESSION;

        if (empty($id_tahun_akademik)) {
            $id_tahun_akademik = $SESSION->obe_id_tahun_akademik ?? 0;
        }

        
        // Ambil prodi dari mata kuliah terlebih dahulu
        $prodi_id = $DB->get_field('local_obe_mata_kuliah', 'id_prodi', ['id' => $matkul_id]);
        if (empty($prodi_id)) {
            return [];
        }

        // Ambil struktur Sub-CPMK yang dikunci ketat menggunakan id_prodi prodi bersangkutan
        $subcpmk = $DB->get_records('local_obe_kompetensi', [
            'parent_id'         => $cpmk_id,
            'tipe'              => 'SUB-CPMK',
            'id_prodi'          => $prodi_id,
            'id_tahun_akademik' => $id_tahun_akademik
        ], 'id ASC');
        // -----------------------

        if (empty($subcpmk)) {
            return [];
        }

        $hasil = [];

        foreach ($subcpmk as $sub) {

            // Ambil deskripsi kompetensi
            $kompetensi = $DB->get_record(
                'local_obe_kompetensi',
                ['id' => $sub->id],
                'deskripsi',
                IGNORE_MISSING
            );

            // Hitung statistik gabungan seluruh kelas
            $statistik = self::ambil_statistik_skala_matakuliah(
                $matkul_id,
                $sub->id,
                'SUB-CPMK',
                $id_tahun_akademik,
                $angkatan_id
            );

            $hasil[] = (object)[
                'id'            => $sub->id,
                'subcpmk_id'    => $sub->id,
                'nama_subcpmk'  => $sub->nama_kompetensi, // Ubah properti agar sesuai field tabel asli
                'deskripsi'     => $kompetensi->deskripsi ?? '',
                'bobot'         => capaian_kelas_manager::ambil_bobot_relasi($cpmk_id, $sub->id, $id_tahun_akademik),

                'percentage'    => $statistik->rata_rata,
                'grade'         => $statistik->grade
            ];
        }

        return $hasil;
    }

    /**
 * Mengambil capaian CPMK tingkat Mata Kuliah
 * (gabungan seluruh kelas paralel).
 */
   public static function ambil_capaian_cpmk_matakuliah( $matkul_id,  $indikator_id,  $id_tahun_akademik = 0,  $angkatan_id = 0) {
        global $DB, $SESSION;

        if (empty($id_tahun_akademik)) {
            $id_tahun_akademik = $SESSION->obe_id_tahun_akademik ?? 0;
        }

        // --- UBAH BAGIAN INI ---
        $prodi_id = $DB->get_field('local_obe_mata_kuliah', 'id_prodi', ['id' => $matkul_id]);
        if (empty($prodi_id)) {
            return [];
        }

        // Mengambil struktur CPMK langsung ter-filter prodi
        $cpmk_data = $DB->get_records('local_obe_kompetensi', [
            'parent_id'         => $indikator_id,
            'tipe'              => 'CPMK',
            'id_prodi'          => $prodi_id,
            'id_tahun_akademik' => $id_tahun_akademik
        ], 'id ASC');
        // -----------------------

        if (empty($cpmk_data)) {
            return [];
        }

        $hasil = [];

        foreach ($cpmk_data as $cpmk) {

            // Ambil deskripsi CPMK
            $kompetensi = $DB->get_record(
                'local_obe_kompetensi',
                ['id' => $cpmk->id],
                'deskripsi',
                IGNORE_MISSING
            );

            // Hitung statistik gabungan seluruh kelas
            $statistik = self::ambil_statistik_skala_matakuliah(
                $matkul_id,
                $cpmk->id,
                'CPMK',
                $id_tahun_akademik,
                $angkatan_id
            );

            $hasil[] = (object)[
                'id'          => $cpmk->id,
                'cpmk_id'     => $cpmk->id,
                'nama_cpmk'   => $cpmk->nama_kompetensi, // Sesuai field tabel
                'deskripsi'   => $kompetensi->deskripsi ?? '',
                'bobot'       => capaian_kelas_manager::ambil_bobot_relasi($indikator_id, $cpmk->id, $id_tahun_akademik),

                'percentage'  => $statistik->rata_rata,
                'grade'       => $statistik->grade
            ];
        }

        return $hasil;
    }
    /**
 * Mengambil capaian Indikator tingkat Mata Kuliah
 * (gabungan seluruh kelas paralel).
 */
    public static function ambil_capaian_indikator_matakuliah($matkul_id,$cpl_id,$id_tahun_akademik = 0,$angkatan_id = 0) {
        global $DB, $SESSION;

        if (empty($id_tahun_akademik)) {
            $id_tahun_akademik = $SESSION->obe_id_tahun_akademik ?? 0;
        }

        // --- UBAH BAGIAN INI ---
        $prodi_id = $DB->get_field('local_obe_mata_kuliah', 'id_prodi', ['id' => $matkul_id]);
        if (empty($prodi_id)) {
            return [];
        }

        // Tarik struktur Indikator dengan menyertakan prodi_id
        $indikator_data = $DB->get_records('local_obe_kompetensi', [
            'parent_id'         => $cpl_id,
            'tipe'              => 'INDIKATOR',
            'id_prodi'          => $prodi_id,
            'id_tahun_akademik' => $id_tahun_akademik
        ], 'id ASC');
        // -----------------------

        if (empty($indikator_data)) {
            return [];
        }

        $hasil = [];

        foreach ($indikator_data as $indikator) {

            // Ambil deskripsi indikator
            $kompetensi = $DB->get_record(
                'local_obe_kompetensi',
                ['id' => $indikator->id],
                'deskripsi',
                IGNORE_MISSING
            );

            // Hitung statistik seluruh kelas
            $statistik = self::ambil_statistik_skala_matakuliah(
                $matkul_id,
                $indikator->id,
                'INDIKATOR',
                $id_tahun_akademik,
                $angkatan_id
            );

            $hasil[] = (object)[
                'id'              => $indikator->id,
                'nama'            => $indikator->nama_kompetensi, // Sesuai field tabel
                'deskripsi'       => $kompetensi->deskripsi ?? '',
                'bobot'           => capaian_kelas_manager::ambil_bobot_relasi($cpl_id, $indikator->id, $id_tahun_akademik),

                'total_indikator' => $statistik->rata_rata,
                'grade'           => $statistik->grade
            ];
        }

        return $hasil;
    }
    /**
     * Mengambil capaian CPL tingkat Mata Kuliah
     * (gabungan seluruh kelas paralel).
     */
    public static function ambil_capaian_cpl_matakuliah( $matkul_id, $id_tahun_akademik = 0, $angkatan_id = 0) {
        global $DB, $SESSION;

        if (empty($id_tahun_akademik)) {
            $id_tahun_akademik = $SESSION->obe_id_tahun_akademik ?? 0;
        }

        // Ambil Prodi dari Mata Kuliah
        $prodi_id = $DB->get_field(
            'local_obe_mata_kuliah',
            'id_prodi',
            ['id' => $matkul_id]
        );

        if (!$prodi_id) {
            return [];
        }

        // --- UBAH BAGIAN INI ---
        // Penarikan CPL root di-filter ketat menggunakan id_prodi
        $cpl_data = $DB->get_records('local_obe_kompetensi', [
            'parent_id'         => 0,
            'tipe'              => 'CPL',
            'id_prodi'          => $prodi_id,
            'id_tahun_akademik' => $id_tahun_akademik
        ], 'id ASC');
        // -----------------------

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

            // Hitung statistik seluruh kelas
            $statistik = self::ambil_statistik_skala_matakuliah(
                $matkul_id,
                $cpl->id,
                'CPL',
                $id_tahun_akademik,
                $angkatan_id
            );

            $hasil[] = (object)[
                'id'         => $cpl->id,
                'cpl_id'     => $cpl->id,
                'nama_cpl'   => $cpl->nama_kompetensi, // Sesuai field tabel

                'percentage' => $statistik->rata_rata,
                'grade'      => $statistik->grade
            ];
        }

        return $hasil;
    }

    /**
     * Mengambil struktur hierarki kompetensi pada suatu Mata Kuliah
     * CPL -> Indikator -> CPMK -> Sub CPMK
     */
    public static function ambil_hierarki_matakuliah( $matkul_id,  $id_tahun_akademik = 0,  $angkatan_id = 0) {
        global $DB, $SESSION;

        if (empty($id_tahun_akademik)) {
            $id_tahun_akademik = $SESSION->obe_id_tahun_akademik ?? 0;
        }

        // ======================================================
        // Ambil Prodi Mata Kuliah
        // ======================================================
        $prodi_id = $DB->get_field(
            'local_obe_mata_kuliah',
            'id_prodi',
            ['id' => $matkul_id]
        );

        if (empty($prodi_id)) {
            return [];
        }

        // ======================================================
        // Ambil seluruh CPL pada Prodi
        // ======================================================
        $cpl_list = capaian_kelas_manager::ambil_struktur_kompetensi_kelas(
            0,
            'CPL',
            $id_tahun_akademik,
            $prodi_id
        );

        if (empty($cpl_list)) {
            return [];
        }

        $hasil = [];

        foreach ($cpl_list as $cpl) {

            // ==========================================
            // Ambil Indikator
            // ==========================================
            $indikator_list = capaian_kelas_manager::ambil_struktur_kompetensi_kelas(
                $cpl->id,
                'INDIKATOR',
                $id_tahun_akademik
            );

            $indikator_data = [];

            foreach ($indikator_list as $indikator) {

                // ==========================================
                // Ambil CPMK
                // ==========================================
                $cpmk_list = capaian_kelas_manager::ambil_struktur_kompetensi_kelas(
                    $indikator->id,
                    'CPMK',
                    $id_tahun_akademik
                );

                $cpmk_data = [];

                foreach ($cpmk_list as $cpmk) {

                    // ==========================================
                    // Ambil Sub CPMK
                    // ==========================================
                    $sub_list = capaian_kelas_manager::ambil_struktur_kompetensi_kelas(
                        $cpmk->id,
                        'SUB-CPMK',
                        $id_tahun_akademik
                    );

                    $cpmk_data[] = (object)[
                        'id'           => $cpmk->id,
                        'nama'         => $cpmk->nama,
                        'bobot'        => $cpmk->bobot,
                        'subcpmk_list' => $sub_list
                    ];
                }

                $indikator_data[] = (object)[
                    'id'        => $indikator->id,
                    'nama'      => $indikator->nama,
                    'bobot'     => $indikator->bobot,
                    'cpmk_list' => $cpmk_data
                ];
            }

            $hasil[] = (object)[
                'id'             => $cpl->id,
                'nama'           => $cpl->nama,
                'deskripsi'      => $cpl->deskripsi ?? '',
                'indikator_list' => $indikator_data
            ];
        }

        return $hasil;
    }
    /**
     * Mengambil seluruh data yang dibutuhkan untuk export Mata Kuliah.
     */
    public static function ambil_data_export_matakuliah(  $matkul_id,  $id_tahun_akademik = 0,  $angkatan_id = 0) {
        global $SESSION;

        if (empty($id_tahun_akademik)) {
            $id_tahun_akademik = $SESSION->obe_id_tahun_akademik ?? 0;
        }

        // =====================================================
        // Ambil Hierarki
        // =====================================================
        $hierarki = self::ambil_hierarki_matakuliah(
            $matkul_id,
            $id_tahun_akademik,
            $angkatan_id
        );

        if (empty($hierarki)) {
            return [];
        }

        $hasil = [];

        foreach ($hierarki as $cpl) {

            // =====================================================
            // DETAIL CPL
            // =====================================================
            $detailCpl = self::ambil_detail_kompetensi_mahasiswa_matakuliah(
                $matkul_id,
                $cpl->id,
                'CPL',
                $id_tahun_akademik,
                $angkatan_id
            );

            $ringkasanCpl = self::ambil_statistik_skala_matakuliah(
                $matkul_id,
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
                $detailIndikator = self::ambil_detail_kompetensi_mahasiswa_matakuliah(
                    $matkul_id,
                    $indikator->id,
                    'INDIKATOR',
                    $id_tahun_akademik,
                    $angkatan_id
                );

                $ringkasanIndikator = self::ambil_statistik_skala_matakuliah(
                    $matkul_id,
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
                    $detailCpmk = self::ambil_detail_kompetensi_mahasiswa_matakuliah(
                        $matkul_id,
                        $cpmk->id,
                        'CPMK',
                        $id_tahun_akademik,
                        $angkatan_id
                    );

                    $ringkasanCpmk = self::ambil_statistik_skala_matakuliah(
                        $matkul_id,
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
                        $detailSub = self::ambil_detail_kompetensi_mahasiswa_matakuliah(
                            $matkul_id,
                            $sub->id,
                            'SUB-CPMK',
                            $id_tahun_akademik,
                            $angkatan_id
                        );

                        $ringkasanSub = self::ambil_statistik_skala_matakuliah(
                            $matkul_id,
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
    
}
?>