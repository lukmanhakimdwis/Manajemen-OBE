<?php
namespace local_obe\manager;
defined('MOODLE_INTERNAL') || die();

class capaian_pembelajaran_manager {

    /**
     * Mengambil mahasiswa aktif menggunakan enrolment API Moodle, lalu mengecualikan user
     * yang memiliki capability pengelolaan course agar dosen/admin tidak ikut dihitung.
     */
    private static function ambil_mahasiswa_terdaftar($courseid) {
        $context = \context_course::instance($courseid);
        $users = get_enrolled_users($context, '', 0, 'u.*', 'u.lastname ASC, u.firstname ASC');
        $students = [];

        foreach ($users as $user) {
            if (!has_capability('moodle/course:update', $context, $user->id)) {
                $students[$user->id] = $user;
            }
        }

        return $students;
    }

    /**
     * Mengambil data course module memakai Course Modinfo API Moodle.
     */
    private static function ambil_course_module($courseid, $cmid) {
        $modinfo = get_fast_modinfo($courseid);
        $cms = $modinfo->get_cms();
        return $cms[$cmid] ?? null;
    }

    /**
     * Mengambil daftar aktivitas yang dipetakan ke Sub-CPMK memakai Course Modinfo API.
     */
    private static function ambil_aktivitas_subcpmk($courseid, $sub_cpmk_id) {
        global $DB;

        $maps = \local_obe\local\assessment_integrity::maps((int)$courseid,(int)$sub_cpmk_id);
        $activities = [];

        foreach ($maps as $map) {
            $cm = self::ambil_course_module($courseid, (int)$map->cmid);
            if (!$cm) {
                continue;
            }
            $activities[$map->id] = (object)[
                'id'              => $map->id,
                'cmid'            => $cm->id,
                'bobot_aktivitas' => $map->bobot_aktivitas,
                'instance'        => $cm->instance,
                'modulename'      => $cm->modname,
                'name'            => $cm->name,
            ];
        }

        return $activities;
    }

    /**
     * Mengambil nilai aktivitas dengan Grade API Moodle, bukan join langsung ke grade_items/grade_grades.
     * Hasil dipetakan sebagai $lookup[userid][cmid].
     */
    private static function ambil_grade_aktivitas($courseid, $activities, $userids = []) {
        global $CFG;
        require_once($CFG->libdir . '/gradelib.php');

        $lookup = [];
        $requestedusers = !empty($userids) ? array_values($userids) : null;

        foreach ($activities as $activity) {
            $grades = grade_get_grades($courseid, 'mod', $activity->modulename, $activity->instance, $requestedusers);
            if (empty($grades->items)) {
                continue;
            }

            $item = reset($grades->items);
            if (empty($item->grades)) {
                continue;
            }

            foreach ($item->grades as $userid => $grade) {
                $lookup[$userid][$activity->cmid] = (object)[
                    'userid'      => $userid,
                    'finalgrade'  => isset($grade->grade) && $grade->grade !== '' ? (float)$grade->grade : null,
                    'rawgrademax' => isset($item->grademax) ? (float)$item->grademax : 100,
                    'itemname'    => $item->name ?? $activity->name,
                ];
            }
        }

        return $lookup;
    }

    /**
     * Mengambil nilai mahasiswa untuk mata kuliah tertentu
     */
    public static function ambil_nilai_mahasiswa_per_mata_kuliah($courseid) {
        global $DB;

        $students = self::ambil_mahasiswa_terdaftar($courseid);
        if (empty($students)) {
            return [];
        }

        $maps = \local_obe\local\assessment_integrity::maps((int)$courseid);
        $activities = [];
        foreach ($maps as $map) {
            $cm = self::ambil_course_module($courseid, (int)$map->cmid);
            if (!$cm) {
                continue;
            }
            $activities[$map->id] = (object)[
                'id'              => $map->id,
                'cmid'            => $cm->id,
                'modulename'      => $cm->modname,
                'instance'        => $cm->instance,
                'name'            => $cm->name,
                'sub_cpmk_id'     => $map->sub_cpmk_id,
                'bobot_aktivitas' => $map->bobot_aktivitas,
            ];
        }

        $grades = self::ambil_grade_aktivitas($courseid, $activities, array_keys($students));
        $records = [];

        foreach ($students as $userid => $user) {
            foreach ($activities as $activity) {
                $grade = $grades[$userid][$activity->cmid] ?? null;
                $records[] = (object)[
                    'userid'          => $userid,
                    'firstname'       => $user->firstname,
                    'lastname'        => $user->lastname,
                    'itemname'        => $activity->name,
                    'finalgrade'      => $grade->finalgrade ?? null,
                    'sub_cpmk_id'     => $activity->sub_cpmk_id,
                    'bobot_aktivitas' => $activity->bobot_aktivitas,
                ];
            }
        }

        return $records;
    }

    /**
     * Mengambil data untuk dashboard capaian pembelajaran
     */
    public static function ambil_data_dasbor($prodi_id, $matkul_id, $angkatan_id, $id_tahun_akademik = 0) {
        global $DB, $SESSION;

        if (empty($id_tahun_akademik)) {
            $id_tahun_akademik = $SESSION->obe_id_tahun_akademik ?? 0;
        }
        
        $sql = "SELECT rk.id_kursus_moodle, mk.nama_mk, rk.id_angkatan
                FROM {local_obe_relasi_kelas} rk
                JOIN {local_obe_mata_kuliah} mk ON rk.id_mata_kuliah = mk.id
                WHERE rk.id_tahun_akademik = :tahun_akademik";
        
        $params = ['tahun_akademik' => $id_tahun_akademik];
        
        if ($prodi_id > 0) { 
            $sql .= " AND mk.id_prodi = :prodi"; 
            $params['prodi'] = $prodi_id; 
        }
        if ($matkul_id > 0) { 
            $sql .= " AND mk.id = :mk"; 
            $params['mk'] = $matkul_id; 
        }
        if ($angkatan_id > 0) { 
            $sql .= " AND rk.id_angkatan = :angkatan"; 
            $params['angkatan'] = $angkatan_id; 
        }

        return $DB->get_records_sql($sql, $params);
    }
    /**
     * Fungsi Terpusat untuk Kalkulasi Nilai OBE
     */
    public static function hitung_nilai_obe_terbobot($nilai_mentah, $grademax, $bobot_aktivitas, $bobot_relasi) {
        $skor_maksimal = ($grademax > 0) ? (float)$grademax : 100;
        $skor_normalisasi = ($nilai_mentah / $skor_maksimal) * 100;

        $b_akt = ($bobot_aktivitas > 1) ? ($bobot_aktivitas / 100) : (float)$bobot_aktivitas;
        $b_rel = ($bobot_relasi > 1) ? ($bobot_relasi / 100) : (float)$bobot_relasi;

        $hasil_hitung = ($skor_normalisasi * $b_akt * $b_rel);
        
        return min(100, $hasil_hitung);
    }

   /**
     * Mengambil bobot relasi secara aman dua arah dengan filter tahun akademik
     */
    private static function ambil_bobot_relasi($asal_id, $tujuan_id, $id_tahun_akademik = 0) {
        global $SESSION;
        return \local_obe\local\assessment_integrity::relation_weight((int)$asal_id,(int)$tujuan_id,(int)($id_tahun_akademik?:($SESSION->obe_id_tahun_akademik??0)));
    }

    /**
     * Mengambil detail nilai mahasiswa dengan normalisasi dan perhitungan OBE
     */
   public static function ambil_detail_nilai_mahasiswa_berdasarkan_modul($courseid, $cmid, $id_tahun_akademik = 0) {
        global $DB, $SESSION;

        if (empty($id_tahun_akademik)) {
            $id_tahun_akademik = $SESSION->obe_id_tahun_akademik ?? 0;
        }

        $map = $DB->get_record('local_obe_aktivitas_map', ['cmid' => $cmid], '*', IGNORE_MISSING);
        $sub_cpmk_id = $map->sub_cpmk_id ?? 0;
        $cm = self::ambil_course_module($courseid, (int)$cmid);

        $sub_cpmk = $sub_cpmk_id ? $DB->get_record('local_obe_kompetensi', ['id' => $sub_cpmk_id], '*', IGNORE_MISSING) : null;
        $cpmk_record = null;
        $indikator_record = null;
        $cpl_record = null;
        $cpmk_id = 0;
        $indikator_id = 0;
        $cpl_id = 0;

        if ($sub_cpmk) {
            $cpmk_id = $sub_cpmk->parent_id;
            $cpmk_record = $DB->get_record('local_obe_kompetensi', ['id' => $cpmk_id], '*', IGNORE_MISSING);
        }
        if ($cpmk_record) {
            $indikator_id = $cpmk_record->parent_id;
            $indikator_record = $DB->get_record('local_obe_kompetensi', ['id' => $indikator_id], '*', IGNORE_MISSING);
        }
        if ($indikator_record) {
            $cpl_id = $indikator_record->parent_id;
            $cpl_record = $DB->get_record('local_obe_kompetensi', ['id' => $cpl_id], '*', IGNORE_MISSING);
        }

        $prodi_id = $DB->get_field_sql("SELECT mk.id_prodi
            FROM {local_obe_relasi_kelas} rk
            JOIN {local_obe_mata_kuliah} mk ON rk.id_mata_kuliah = mk.id
            WHERE rk.id_kursus_moodle = :courseid", ['courseid' => $courseid], IGNORE_MULTIPLE);

        $b_sub  = self::ambil_bobot_relasi($cpmk_id, $sub_cpmk_id, $id_tahun_akademik);
        $b_cpmk = self::ambil_bobot_relasi($indikator_id, $cpmk_id, $id_tahun_akademik);
        $b_ind  = self::ambil_bobot_relasi($cpl_id, $indikator_id, $id_tahun_akademik);

        $students = self::ambil_mahasiswa_terdaftar($courseid);
        $activities = [];
        if ($map && $cm) {
            $activities[$map->id] = (object)[
                'id'              => $map->id,
                'cmid'            => $cm->id,
                'bobot_aktivitas' => $map->bobot_aktivitas,
                'instance'        => $cm->instance,
                'modulename'      => $cm->modname,
                'name'            => $cm->name,
            ];
        }
        $grades = self::ambil_grade_aktivitas($courseid, $activities, array_keys($students));

        $processed = [];
        foreach ($students as $userid => $user) {
            $gradeobj = $grades[$userid][$cmid] ?? null;
            $nilai_obe = 0;
            if ($gradeobj && $gradeobj->finalgrade !== null && $map) {
                $nilai_obe = self::hitung_nilai_obe_terbobot(
                    $gradeobj->finalgrade,
                    $gradeobj->rawgrademax,
                    $map->bobot_aktivitas,
                    $b_sub
                );
            }

            $grade = \local_obe\manager\skala_nilai_manager::cek_skala($nilai_obe, 'SUB-CPMK', $prodi_id, $id_tahun_akademik);
            $processed[] = (object)[
                'userid'          => $userid,
                'firstname'       => $user->firstname,
                'lastname'        => $user->lastname,
                'itemname'        => $cm->name ?? '',
                'grademax'        => $gradeobj->rawgrademax ?? 100,
                'finalgrade'      => $gradeobj->finalgrade ?? null,
                'sub_cpmk_id'     => $sub_cpmk_id,
                'bobot_aktivitas' => $map->bobot_aktivitas ?? 0,
                'nama_sub_cpmk'   => $sub_cpmk->nama_kompetensi ?? '',
                'nama_cpmk'       => $cpmk_record->nama_kompetensi ?? '',
                'nama_indikator'  => $indikator_record->nama_kompetensi ?? '',
                'nama_cpl'        => $cpl_record->nama_kompetensi ?? '',
                'nilai_obe'       => $nilai_obe,
                'grade'           => $grade,
                'b_akt_display'   => isset($map->bobot_aktivitas) ? (($map->bobot_aktivitas > 1) ? $map->bobot_aktivitas : ($map->bobot_aktivitas * 100)) : 0,
                'b_sub_display'   => $b_sub,
                'b_cpmk_display'  => $b_cpmk,
                'b_ind_display'   => $b_ind,
            ];
        }

        return (object)[
            'courseid'           => $courseid,
            'sub_cpmk'           => $sub_cpmk,
            'cpmk_record'        => $cpmk_record,
            'indikator_record'   => $indikator_record,
            'cpl_record'         => $cpl_record,
            'bobot_sub_display'  => $b_sub,
            'bobot_cpmk_display' => $b_cpmk,
            'bobot_ind_display'  => $b_ind,
            'users'              => $processed
        ];
    }

    /**
     * Laporan Akhir OBE
     */
    public static function ambil_laporan_akhir_obe($courseid, $id_tahun_akademik = 0) {
        global $DB, $SESSION;

        if (empty($id_tahun_akademik)) {
            $id_tahun_akademik = $SESSION->obe_id_tahun_akademik ?? 0;
        }

        $students = self::ambil_mahasiswa_terdaftar($courseid);
        if (empty($students)) {
            return [];
        }

        $maps = \local_obe\local\assessment_integrity::maps((int)$courseid);
        $activities = [];
        foreach ($maps as $map) {
            $cm = self::ambil_course_module($courseid, (int)$map->cmid);
            if (!$cm) {
                continue;
            }
            $sub=$DB->get_record('local_obe_kompetensi',['id'=>$map->sub_cpmk_id],'*',MUST_EXIST);
            $bobotrelasi=self::ambil_bobot_relasi($sub->id,$sub->parent_id,$id_tahun_akademik);
            $activities[$map->id] = (object)[
                'id'              => $map->id,
                'cmid'            => $cm->id,
                'modulename'      => $cm->modname,
                'instance'        => $cm->instance,
                'name'            => $cm->name,
                'bobot_aktivitas' => $map->bobot_aktivitas,
                'bobot_relasi'    => $bobotrelasi ?: 0,
            ];
        }

        $grades = self::ambil_grade_aktivitas($courseid, $activities, array_keys($students));
        $results = [];

        foreach ($students as $userid => $user) {
            foreach ($activities as $activity) {
                $grade = $grades[$userid][$activity->cmid] ?? null;
                $skor_mentah = $grade->finalgrade ?? 0;
                $grademax = $grade->rawgrademax ?? 100;
                $nilai_akhir_obe = self::hitung_nilai_obe_terbobot($skor_mentah, $grademax, $activity->bobot_aktivitas, $activity->bobot_relasi);

                $results[] = (object)[
                    'userid'   => $userid,
                    'fullname' => $user->firstname . ' ' . $user->lastname,
                    'itemname' => $activity->name,
                    'raw'      => $skor_mentah,
                    'obe'      => $nilai_akhir_obe,
                    'b_akt'    => ($activity->bobot_aktivitas > 1 ? $activity->bobot_aktivitas : $activity->bobot_aktivitas * 100),
                    'b_rel'    => ($activity->bobot_relasi > 1 ? $activity->bobot_relasi : $activity->bobot_relasi * 100)
                ];
            }
        }

        return $results;
    }

    /**
     * Mengambil dan menghitung capaian CPL secara keseluruhan untuk satu kelas (mata kuliah)
     */
     public static function ambil_capaian_cpl_per_mata_kuliah( $courseid,$id_tahun_akademik = 0 ) {
        global $DB, $SESSION;

        // =====================================================
        // TAHUN AKADEMIK
        // =====================================================
        if (empty($id_tahun_akademik)) {
            $id_tahun_akademik = $DB->get_field('local_obe_relasi_kelas', 'id_tahun_akademik', [
                'id_kursus_moodle' => $courseid
            ]);
        }

        if (empty($id_tahun_akademik)) {
            return [];
        }

        // =====================================================
        // PRODI
        // =====================================================
        $prodi_id = $DB->get_field_sql("
            SELECT mk.id_prodi
            FROM {local_obe_relasi_kelas} rk
            JOIN {local_obe_mata_kuliah} mk ON rk.id_mata_kuliah = mk.id
            WHERE rk.id_kursus_moodle = :courseid
        ", ['courseid' => $courseid], IGNORE_MULTIPLE);

        // =====================================================
        // AMBIL CPL
        // =====================================================
        $sql_cpl = "
            SELECT DISTINCT cpl.id as cpl_id, cpl.nama_kompetensi as nama_cpl, cpl.deskripsi
            FROM {local_obe_kompetensi} cpl
            INNER JOIN {local_obe_mata_kuliah} mk ON mk.id_prodi = cpl.id_prodi
            INNER JOIN {local_obe_relasi_kelas} rk ON rk.id_mata_kuliah = mk.id
            WHERE cpl.parent_id = 0
                AND cpl.tipe = 'CPL'
                AND rk.id_kursus_moodle = :courseid
                AND cpl.id_tahun_akademik = :tahun
        ";

        $cpls = $DB->get_records_sql($sql_cpl, [
            'courseid' => $courseid,
            'tahun'    => $id_tahun_akademik
        ]);

        // =====================================================
        // MAHASISWA
        // =====================================================
        $context = \context_course::instance($courseid);
        $users = get_enrolled_users($context);
        $results = [];

        // =====================================================
        // LOOP CPL
        // =====================================================
        foreach ($cpls as $cpl) {
            $total_nilai = 0;
            $total_users = 0;

            foreach ($users as $user) {
                // Skip pengguna dengan hak akses Dosen/Pengajar
                if (has_capability('moodle/course:update', $context, $user->id)) {
                    continue;
                }

                $total_users++;
                $user_attainment = self::ambil_capaian_cpl($courseid, $user->id, $id_tahun_akademik);

                foreach ($user_attainment as $ua) {
                    if ($ua->cpl_id == $cpl->cpl_id) {
                        $total_nilai += ($ua->percentage ?? 0);
                    }
                }
            }

            // Hitung rata-rata nilai CPL mahasiswa
            $rata_rata = ($total_users > 0) ? ($total_nilai / $total_users) : 0;
            // =====================================================
            // CEK SKALA NILAI
            // =====================================================
            $grade = \local_obe\manager\skala_nilai_manager::cek_skala(
                $rata_rata,
                'CPL',
                $prodi_id,
                $id_tahun_akademik
            );

            $results[] = (object)[
                'cpl_id'      => $cpl->cpl_id,
                'nama_cpl'    => $cpl->nama_cpl,
                'deskripsi'   => $cpl->deskripsi ?? '',
                'total_users' => $total_users,
                'percentage'  => $rata_rata,
                'grade'       => $grade
            ];
        }

        return $results;
    }

    /**
     * Mengambil dan menghitung capaian CPL dengan Rincian
     */
   public static function ambil_capaian_cpl_dengan_rincian( $courseid,$id_tahun_akademik = 0) {
    global $DB, $SESSION;

    if (empty($id_tahun_akademik)) {
        $id_tahun_akademik = $SESSION->obe_id_tahun_akademik ?? 0;
    }

    // =====================================================
    // AMBIL DATA CPL
    // =====================================================
    $raw_cpl_data = self::ambil_capaian_cpl_per_mata_kuliah(
        $courseid,
        $id_tahun_akademik
    );

    // =====================================================
    // FALLBACK TAHUN TERAKHIR
    // =====================================================
    if (empty($raw_cpl_data)) {
        $tahun_terakhir = $DB->get_field_sql(
            "
            SELECT MAX(id_tahun_akademik)
              FROM {local_obe_relasi_kelas}
             WHERE id_kursus_moodle = :courseid
            ",
            ['courseid' => $courseid]
        );

        if ($tahun_terakhir) {
            $id_tahun_akademik = $tahun_terakhir;
            $raw_cpl_data = self::ambil_capaian_cpl_per_mata_kuliah(
                $courseid,
                $id_tahun_akademik
            );
        }
    }

    // =====================================================
    // DEDUPLIKASI CPL
    // =====================================================
    $cpl_data = [];
    $seen_cpl_ids = [];

    if (!empty($raw_cpl_data)) {
        foreach ($raw_cpl_data as $cpl) {
            if (!in_array($cpl->cpl_id, $seen_cpl_ids)) {
                $seen_cpl_ids[] = $cpl->cpl_id;
                $cpl_data[] = $cpl;
            }
        }
    }

    $results = [];
    if (empty($cpl_data)) {
        return $results;
    }

    // =====================================================
    // AMBIL PRODI (Ditambahkan LIMIT 1 agar query SQL Moodle aman)
    // =====================================================
    $prodi_id = $DB->get_field_sql(
        "
        SELECT mk.id_prodi
          FROM {local_obe_relasi_kelas} rk
          JOIN {local_obe_mata_kuliah} mk ON rk.id_mata_kuliah = mk.id
         WHERE rk.id_kursus_moodle = :courseid
        ",
        ['courseid' => $courseid],
        IGNORE_MULTIPLE
    );

    // =====================================================
    // LOOP CPL
    // =====================================================
    foreach ($cpl_data as $cpl) {
        $indikator_list = self::ambil_rincian_indikator_dan_kontribusi(
            $courseid,
            $cpl->cpl_id,
            $id_tahun_akademik
        );

        $total_cpl_value = 0;

        if (!empty($indikator_list)) {
            foreach ($indikator_list as $ind) {
                if (!empty($ind->has_values)) {
                    $total_cpl_value += $ind->nilai_kontribusi;
                }
            }
        }

        // =====================================================
        // AMBIL GRADE DARI SKALA NILAI (Disinkronkan ke fungsi cek_skala)
        // =====================================================
        $grade = \local_obe\manager\skala_nilai_manager::cek_skala(
            $total_cpl_value,
            'CPL',
            $prodi_id,
            $id_tahun_akademik
        );

        $results[] = (object)[
            'cpl_id'         => $cpl->cpl_id,
            'nama_cpl'       => $cpl->nama_cpl,
            'deskripsi'   => $cpl->deskripsi ?? '',
            'percentage'     => $total_cpl_value,
            'grade'          => $grade,
            'indikator_list' => $indikator_list,
             'total_users'    => $cpl->total_users ?? 0
        ];
    }

    return $results;
    }
/**
     * Mengambil data nilai capaian CPL mahasiswa berdasarkan ruang lingkup Prodi dan Tahun Akademik aktif
     *
     * @param int $courseid
     * @param int $userid
     * @param int $id_tahun_akademik
     * @return array
     */
    public static function ambil_capaian_cpl($courseid, $userid, $id_tahun_akademik = 0) {
        global $DB, $SESSION;

        if (empty($id_tahun_akademik)) {
            $id_tahun_akademik = $SESSION->obe_id_tahun_akademik ?? 0;
        }

        // ======================================
        // Ambil prodi dari kelas aktif
        // ======================================
        $prodi_id = $DB->get_field_sql(
            "SELECT mk.id_prodi
             FROM {local_obe_relasi_kelas} rk
             JOIN {local_obe_mata_kuliah} mk ON mk.id = rk.id_mata_kuliah
             WHERE rk.id_kursus_moodle = :courseid",
            ['courseid' => $courseid],
            IGNORE_MULTIPLE
        );

        if (!$prodi_id) {
            return [];
        }

        // ======================================
        // Ambil CPL berdasarkan PRODI
        // ======================================
        $sql = "SELECT id AS cpl_id, nama_kompetensi AS nama_cpl
                FROM {local_obe_kompetensi}
                WHERE tipe = 'CPL'
                AND parent_id = 0
                AND id_prodi = :prodi
                AND id_tahun_akademik = :tahun
                ORDER BY id";

        $cpls = $DB->get_records_sql($sql, [
            'prodi' => $prodi_id,
            'tahun' => $id_tahun_akademik
        ]);

        $results = [];

        // ======================================
        // Hitung nilai tiap CPL
        // ======================================
        foreach ($cpls as $cpl) {
            $indikators = $DB->get_records_sql(
                "SELECT i.id, br.nilai_bobot
                 FROM {local_obe_kompetensi} i
                 JOIN {local_obe_bobot_relasi} br ON br.id_kompetensi_asal = i.id
                 WHERE i.parent_id = :cplid
                 AND i.tipe = 'INDIKATOR'
                 AND br.id_tahun_akademik = :tahun",
                [
                    'cplid' => $cpl->cpl_id,
                    'tahun' => $id_tahun_akademik
                ]
            );

            $total_nilai = 0;

            foreach ($indikators as $ind) {
                $ind->nilai_bobot=self::ambil_bobot_relasi($ind->id,$cpl->cpl_id,$id_tahun_akademik);
                $nilai_indikator = self::ambil_capaian_indikator_per_user(
                    $courseid,
                    $ind->id,
                    $userid,
                    $id_tahun_akademik
                );

                $total_nilai += $nilai_indikator * ($ind->nilai_bobot / 100);
            }

            $grade = \local_obe\manager\skala_nilai_manager::cek_skala(
                $total_nilai,
                'CPL',
                $prodi_id,
                $id_tahun_akademik
            );

            $results[] = (object) [
                'cpl_id'     => $cpl->cpl_id,
                'nama_cpl'   => $cpl->nama_cpl,
                'percentage' => $total_nilai,
                'grade'      => $grade
            ];
        }

        return $results;
    }
    /**
     * Fungsi pembantu (Helper) untuk menghitung capaian indikator per user
     * Memastikan alur Bottom-Up tetap konsisten
     */
      public static function ambil_capaian_indikator_per_user( $courseid, $ind_id,$userid,$ta) {
    global $DB;

    // =====================================================
    // AMBIL CPMK BERDASARKAN INDIKATOR
    // =====================================================
    $cpmk_sql = "
        SELECT cpmk.id, br.nilai_bobot
        FROM {local_obe_kompetensi} cpmk
        JOIN {local_obe_bobot_relasi} br ON cpmk.id = br.id_kompetensi_asal
        WHERE cpmk.parent_id = :indid AND br.id_tahun_akademik = :ta
    ";

    $cpmks = $DB->get_records_sql($cpmk_sql, [
        'indid' => $ind_id,
        'ta'    => $ta
    ]);

    $total_nilai_ind = 0;

    // =====================================================
    // HITUNG AKUMULASI NILAI INDIKATOR
    // =====================================================
    foreach ($cpmks as $cpmk) {
        $cpmk->nilai_bobot=self::ambil_bobot_relasi($cpmk->id,$ind_id,$ta);
        $sub_data = self::ambil_rincian_sub_cpmk_dan_total(
            $courseid,
            $cpmk->id,
            $ta
        );

        $nilai_cpmk = $sub_data->total_cpmk_value ?? 0;
        
        $total_nilai_ind += $nilai_cpmk * ($cpmk->nilai_bobot / 100);
    }

    return $total_nilai_ind;
    }

    /**
     * Mengambil dan menghitung capaian nilai pada level CPMK
     */
   public static function ambil_capaian_cpmk_berdasarkan_cpl( $courseid, $cpl_id, $userid, $id_tahun_akademik = 0) {
    global $DB, $SESSION;

    if (empty($id_tahun_akademik)) {
        $id_tahun_akademik = $SESSION->obe_id_tahun_akademik ?? 0;
    }

    // =====================================================
    // AMBIL PRODI
    // =====================================================
    $prodi_id = $DB->get_field_sql("
        SELECT mk.id_prodi
        FROM {local_obe_relasi_kelas} rk
        JOIN {local_obe_mata_kuliah} mk ON rk.id_mata_kuliah = mk.id
        WHERE rk.id_kursus_moodle = :courseid
    ", ['courseid' => $courseid], IGNORE_MULTIPLE);

    // =====================================================
    // QUERY CPMK
    // =====================================================
    $sql = "
        SELECT cpmk.*
        FROM {local_obe_kompetensi} cpmk
        JOIN {local_obe_kompetensi} indikator ON cpmk.parent_id = indikator.id
        WHERE cpmk.tipe = 'CPMK'
    ";

    $params = [];

    // Kondisi dinamis jika CPL tertentu dipilih
    if ($cpl_id > 0) {
        $sql .= " AND indikator.parent_id = :cplid";
        $params['cplid'] = $cpl_id;
    }

    $sql .= " ORDER BY cpmk.nama_kompetensi ASC";

    $cpmks = $DB->get_records_sql($sql, $params);
    $results = [];

    // =====================================================
    // LOOP CPMK
    // =====================================================
    foreach ($cpmks as $cpmk) {
        // Ambil bobot CPMK terhadap indikator
        $bobot_relasi = self::ambil_bobot_relasi(
            $cpmk->parent_id,
            $cpmk->id,
            $id_tahun_akademik
        );

        $bobot_relasi = $bobot_relasi ?: 0;

        // =====================================================
        // AMBIL SUB CPMK
        // =====================================================
        $sub_data = self::ambil_rincian_sub_cpmk_dan_total(
            $courseid,
            $cpmk->id,
            $id_tahun_akademik
        );

        $nilai_cpmk = $sub_data->total_cpmk_value ?? 0;

        // =====================================================
        // HITUNG NILAI AKHIR & GRADE CPMK
        // =====================================================
        $nilai_akhir = $nilai_cpmk * ($bobot_relasi / 100);

        $grade = \local_obe\manager\skala_nilai_manager::cek_skala(
            $nilai_akhir,
            'CPMK',
            $prodi_id,
            $id_tahun_akademik
        );

        $results[] = (object)[
            'cpmk_id'    => $cpmk->id,
            'nama_cpmk'  => $cpmk->nama_kompetensi,
            'nilai_cpmk' => $nilai_akhir,
            'grade'      => $grade,
            'bobot'      => $bobot_relasi
        ];
    }

    return $results;
    }

/**
     * Mengambil data rincian indikator kurikulum beserta akumulasi nilai kontribusinya ke level CPL
     *
     * @param int $courseid
     * @param int $cpl_id
     * @param int $id_tahun_akademik
     * @return array
     */
    public static function ambil_rincian_indikator_dan_kontribusi( $courseid,   $cpl_id,   $id_tahun_akademik = 0 ) {
        global $DB, $SESSION;

        if (empty($id_tahun_akademik)) {
            $id_tahun_akademik = $SESSION->obe_id_tahun_akademik ?? 0;
        }

        // =====================================================
        // AMBIL PRODI
        // =====================================================
        $prodi_id = $DB->get_field_sql(
            "SELECT mk.id_prodi
             FROM {local_obe_relasi_kelas} rk
             JOIN {local_obe_mata_kuliah} mk ON rk.id_mata_kuliah = mk.id
             WHERE rk.id_kursus_moodle = :courseid",
            ['courseid' => $courseid],
            IGNORE_MULTIPLE
        );

        // =====================================================
        // AMBIL INDIKATOR BERDASARKAN CPL
        // =====================================================
        $sql = "SELECT id, nama_kompetensi,  deskripsi
                FROM {local_obe_kompetensi}
                WHERE parent_id = :cplid
                AND tipe = 'INDIKATOR'
                ORDER BY nama_kompetensi ASC";

        $indikators = $DB->get_records_sql($sql, ['cplid' => $cpl_id]);
        $results = [];

        if (empty($indikators)) {
            return $results;
        }

        // =====================================================
        // LOOPING PER INDIKATOR
        // =====================================================
        foreach ($indikators as $ind) {
            // Bobot indikator -> CPL
            $bobot_ind_ke_cpl = self::ambil_bobot_relasi($cpl_id, $ind->id, $id_tahun_akademik);

            // Ambil rincian CPMK pendukung di bawah indikator ini
            $cpmk_list = self::ambil_rincian_cpmk_dan_kontribusi($courseid, $ind->id, $id_tahun_akademik);

            $total_indikator_value = 0;
            $has_values = false;

            // Hitung akumulasi nilai total indikator dari kontribusi CPMK
            foreach ($cpmk_list as $cpmk) {
                if (!empty($cpmk->has_values)) {
                    $has_values = true;
                    $total_indikator_value += $cpmk->nilai_kontribusi;
                }
            }

            // Hitung nilai kontribusi riil indikator ini ke target CPL
            $nilai_kontribusi_ke_cpl = $total_indikator_value * ($bobot_ind_ke_cpl / 100);

            // Konversi nilai indikator ke Grade Huruf Mutu
            $grade = \local_obe\manager\skala_nilai_manager::cek_skala(
                $total_indikator_value,
                'INDIKATOR',
                $prodi_id,
                $id_tahun_akademik
            );

            // Ambil statistik ketuntasan kompetensi indikator (IK) kelas
            $statistik = self::ambil_statistik_skala($courseid, $ind->id, 'INDIKATOR', $id_tahun_akademik);

            // Simpan hasil pemetaan ke objek standar Moodle
            $results[] = (object) [
                'id'                  => $ind->id,
                'nama'                => $ind->nama_kompetensi,
                'deskripsi'           => $ind->deskripsi,
                'bobot'               => $bobot_ind_ke_cpl,
                'total_indikator'     => round($total_indikator_value, 2),
                'nilai_kontribusi'    => round($nilai_kontribusi_ke_cpl, 2),
                'grade'               => $grade,
                'has_values'          => $has_values,
                'cpmk_list'           => $cpmk_list,
                'jumlah_kompeten'     => $statistik->jumlah_kompeten,
                'jumlah_mahasiswa'    => $statistik->total_mahasiswa,
                'persentase_kompeten' => $statistik->persentase_kompeten,
                'total_users'         => $statistik->total_mahasiswa ?? 0
            ];
        }

        return $results;
    }
        /**
     * Menghitung rata-rata capaian seluruh mahasiswa pada satu Indikator tertentu.
     * Digunakan untuk Dashboard Dosen/Prodi.
     */
    public static function hitung_rata_rata_indikator($courseid, $indikator_id, $id_tahun_akademik = 0) {
        global $DB, $SESSION;

        if (empty($id_tahun_akademik)) {
            $id_tahun_akademik = $SESSION->obe_id_tahun_akademik ?? 0;
        }

        // 1. Ambil daftar CPMK di bawah Indikator ini beserta bobotnya
        $sql = "SELECT cpmk.id, br.nilai_bobot
                FROM {local_obe_kompetensi} cpmk
                JOIN {local_obe_bobot_relasi} br ON cpmk.id = br.id_kompetensi_asal
                WHERE cpmk.parent_id = :indid 
                AND br.id_tahun_akademik = :ta";

        $cpmks = $DB->get_records_sql($sql, [
            'indid' => $indikator_id, 
            'ta'    => $id_tahun_akademik
        ]);

        if (empty($cpmks)) {
            return 0;
        }

        $context = \context_course::instance($courseid);
        $users = get_enrolled_users($context);
        
        $total_nilai_kelas_untuk_ind = 0;
        $student_count = 0;

        foreach ($users as $user) {
            // Skip non-students (teacher/manager)
            if (has_capability('moodle/course:update', $context, $user->id)) {
                continue;
            }

            $student_count++;
            $nilai_user_ind = 0;

            foreach ($cpmks as $cpmk) {
                // Kita gunakan fungsi "ambil_capaian_cpmk" per user yang sudah ada
                // Anda perlu memastikan fungsi ini return nilai mentah (0-100)
                $nilai_cpmks = self::ambil_capaian_cpmk_berdasarkan_cpl($courseid, 0, $user->id, $id_tahun_akademik);
                
                foreach ($nilai_cpmks as $n) {
                    if ($n->cpmk_id == $cpmk->id) {
                        $nilai_user_ind += $n->nilai_cpmk * ($cpmk->nilai_bobot / 100);
                    }
                }
            }
            $total_nilai_kelas_untuk_ind += $nilai_user_ind;
        }

        return ($student_count > 0) ? ($total_nilai_kelas_untuk_ind / $student_count) : 0;
    }


    /**
         * Mengambil dan menghitung nilai CPMK beserta kontribusinya
         */
    /**
     * Mengambil rincian data CPMK beserta nilai kontribusinya terhadap Indikator.
     *
     * @param int $courseid ID Kursus Moodle.
     * @param int $indikator_id ID Indikator (Parent).
     * @param int $id_tahun_akademik ID Tahun Akademik (Opsional).
     * @return array Daftar objek rincian CPMK.
     */
    /**
     * Mengambil rincian capaian CPMK di bawah Indikator CPL tertentu, 
     * menghitung nilai total, nilai kontribusi, huruf mutu (grade), 
     * serta melacak populasi mahasiswa aktif di dalam kelas.
     *
     * @param int $courseid
     * @param int $indikator_id
     * @param int $id_tahun_akademik
     * @return array An array of stdClass competency objects.
     */
    public static function ambil_rincian_cpmk_dan_kontribusi($courseid, $indikator_id, $id_tahun_akademik = 0) {
        global $DB, $SESSION;

        if (empty($id_tahun_akademik)) {
            $id_tahun_akademik = $SESSION->obe_id_tahun_akademik ?? 0;
        }

        // =====================================================
        // AMBIL DATA PRODI
        // =====================================================
        $prodi_id = $DB->get_field_sql(
            "SELECT mk.id_prodi
             FROM {local_obe_relasi_kelas} rk
             JOIN {local_obe_mata_kuliah} mk ON rk.id_mata_kuliah = mk.id
             WHERE rk.id_kursus_moodle = :courseid",
            ['courseid' => $courseid],
            IGNORE_MULTIPLE
        );

        // =====================================================
        // AMBIL DATA CPMK
        // =====================================================
        $sql_cpmk = "SELECT id, nama_kompetensi
                     FROM {local_obe_kompetensi}
                     WHERE parent_id = :indikator_id
                     AND tipe = 'CPMK'";

        $cpmks = $DB->get_records_sql($sql_cpmk, ['indikator_id' => $indikator_id]);
        $results = [];

        if (empty($cpmks)) {
            return $results;
        }

        // =====================================================
        // PROSES CPMK
        // =====================================================
        foreach ($cpmks as $cpmk) {
            // Ambil bobot relasi Indikator -> CPMK
            $bobot_cpmk = self::ambil_bobot_relasi($indikator_id, $cpmk->id, $id_tahun_akademik);

            // Ambil rincian data pencapaian dari level Sub-CPMK di bawahnya
            $sub_data = self::ambil_rincian_sub_cpmk_dan_total($courseid, $cpmk->id, $id_tahun_akademik);
            $total_cpmk = $sub_data->total_cpmk_value ?? 0;

            // Cari populasi maksimal mahasiswa aktif dari sub-data (mencegah bias sebaran user)
            $total_users = 0;
            if (!empty($sub_data->sub_data)) {
                foreach ($sub_data->sub_data as $sub) {
                    $total_users = max($total_users, $sub->total_users ?? 0);
                }
            }

            // Hitung nilai kontribusi riil riwayat aktivitas terhadap bobot indikator
            $nilai_kontribusi = $total_cpmk * ($bobot_cpmk / 100);

            // Cek konversi skala nilai huruf mutu berdasarkan aturan prodi
            $grade = \local_obe\manager\skala_nilai_manager::cek_skala(
                $total_cpmk,
                'CPMK',
                $prodi_id,
                $id_tahun_akademik
            );

            // Simpan hasil pemetaan ke array objek
            $results[] = (object) [
                'id'               => $cpmk->id,
                'nama'             => $cpmk->nama_kompetensi,
                'bobot'            => $bobot_cpmk,
                'total_cpmk'       => $total_cpmk,
                'nilai_kontribusi' => $nilai_kontribusi,
                'grade'            => $grade,
                'has_values'       => ($total_cpmk > 0),
                'total_users'      => $total_users // Parameter penting untuk perhitungan weighted average prodi
            ];
        }

        return $results;
    }

    /**
     * Mengambil dan menghitung nilai Sub-CPMK beserta nilai total CPMK
     */
    public static function ambil_rincian_sub_cpmk_dan_total( $courseid, $cpmk_id, $id_tahun_akademik = 0) {
        global $DB, $SESSION;

        if (empty($id_tahun_akademik)) {
            $id_tahun_akademik = $SESSION->obe_id_tahun_akademik ?? 0;
        }

        $context = \context_course::instance($courseid);

        // =====================================
        // 1. AMBIL PRODI
        // =====================================
        $prodi_id = $DB->get_field_sql("
            SELECT mk.id_prodi
            FROM {local_obe_relasi_kelas} rk
            JOIN {local_obe_mata_kuliah} mk ON rk.id_mata_kuliah = mk.id
            WHERE rk.id_kursus_moodle = :courseid
        ", ['courseid' => $courseid], IGNORE_MULTIPLE);

        // =====================================
        // 2. AMBIL DAFTAR MAHASISWA (Filter non-editing teacher / admin)
        // =====================================
        $users = get_enrolled_users($context);
        $valid_user_ids = [];
        foreach ($users as $user) {
            if (has_capability('moodle/course:update', $context, $user->id)) {
                continue;
            }
            $valid_user_ids[] = $user->id;
        }

        $jumlah_user = count($valid_user_ids);
        $sub_data = [];
        $total_cpmk_value = 0;

        if ($jumlah_user === 0) {
            return (object)[
                'total_cpmk_value' => 0,
                'sub_data' => []
            ];
        }

        // =====================================
        // 3. AMBIL SEMUA SUB CPMK
        // =====================================
        $subs = $DB->get_records_sql("
            SELECT id, nama_kompetensi
            FROM {local_obe_kompetensi}
            WHERE parent_id = :cpmkid
        ", ['cpmkid' => $cpmk_id]);

        if (empty($subs)) {
            return (object)[
                'total_cpmk_value' => 0,
                'sub_data' => []
            ];
        }

        // =====================================
        // 4. EAGER LOADING: Tarik Semua Nilai Sekaligus via Grade API Moodle
        // =====================================
        $activities_by_sub = [];
        $all_activities = [];
        foreach ($subs as $sub) {
            $activities_by_sub[$sub->id] = self::ambil_aktivitas_subcpmk($courseid, $sub->id);
            foreach ($activities_by_sub[$sub->id] as $activity) {
                $all_activities[$activity->cmid] = $activity;
            }
        }

        $grades_lookup = self::ambil_grade_aktivitas($courseid, $all_activities, $valid_user_ids);

        // =====================================
        // 5. PROSES PERHITUNGAN (Pure PHP, No DB Queries Inside)
        // =====================================
        foreach ($subs as $sub) {
            $bobot_subcpmk = self::ambil_bobot_relasi($cpmk_id, $sub->id, $id_tahun_akademik);
            $total_sub = 0;

            foreach ($valid_user_ids as $userid) {
                $nilai_user = 0;
                
                // Cek nilai aktivitas Sub-CPMK dari Grade API lookup.
                foreach (($activities_by_sub[$sub->id] ?? []) as $akt) {
                    $grade = $grades_lookup[$userid][$akt->cmid] ?? null;
                    $skor = $grade && $grade->finalgrade !== null ? floatval($grade->finalgrade) : 0;
                    $max = $grade && $grade->rawgrademax ? floatval($grade->rawgrademax) : 100;

                    $nilai = ($max > 0) ? ($skor / $max) * 100 : 0;
                    $nilai_user += $nilai * (floatval($akt->bobot_aktivitas) / 100);
                }
                
                $total_sub += $nilai_user;
            }

            $rata_rata = $total_sub / $jumlah_user;

            // Ambil skala grade (Tetap di dalam loop Sub-CPMK karena jumlah Sub-CPMK umumnya sedikit)
            $grade = \local_obe\manager\skala_nilai_manager::cek_skala(
                $rata_rata,
                'SUB-CPMK',
                $prodi_id,
                $id_tahun_akademik
            );

            $sub_data[] = (object)[
                'id' => $sub->id,
                'nama_kompetensi' => $sub->nama_kompetensi,
                'bobot_subcpmk' => $bobot_subcpmk,
                'rata_rata_nilai' => $rata_rata,
                'grade' => $grade,
                'total_users'      => $jumlah_user
            ];

            $total_cpmk_value += $rata_rata * ($bobot_subcpmk / 100);
        }

        return (object)[
            'total_cpmk_value' => $total_cpmk_value,
            'sub_data' => $sub_data
        ];
    }

    /**
     * Mengambil detail data mahasiswa OBE untuk view detail_mahasiswa.
     */
    public static function ambil_detail_data_mahasiswa_obe( $courseid,$sub_cpmk_id,$cpmk_id = 0,$cpl_id = 0,$id_tahun_akademik = 0) {
        global $DB, $SESSION;

        if (empty($id_tahun_akademik)) {
            $id_tahun_akademik = $SESSION->obe_id_tahun_akademik ?? 0;
        }

        // =====================================================
        // AMBIL SUB CPMK & HIERARKI OBE
        // =====================================================
        $sub_cpmk = $DB->get_record('local_obe_kompetensi', ['id' => $sub_cpmk_id], '*', MUST_EXIST);
        
        $cpmk_record = $DB->get_record('local_obe_kompetensi', ['id' => $sub_cpmk->parent_id], '*', IGNORE_MISSING);

        $indikator_record = null;
        if ($cpmk_record && !empty($cpmk_record->parent_id)) {
            $indikator_record = $DB->get_record('local_obe_kompetensi', ['id' => $cpmk_record->parent_id], '*', IGNORE_MISSING);
        }

        $cpl_record = null;
        if ($indikator_record && !empty($indikator_record->parent_id)) {
            $cpl_record = $DB->get_record('local_obe_kompetensi', ['id' => $indikator_record->parent_id], '*', IGNORE_MISSING);
        }

        // =====================================================
        // AMBIL PRODI & BOBOT RELASI
        // =====================================================
        $prodi_sql = "
            SELECT mk.id_prodi
            FROM {local_obe_relasi_kelas} rk
            JOIN {local_obe_mata_kuliah} mk ON rk.id_mata_kuliah = mk.id
            WHERE rk.id_kursus_moodle = :courseid
        ";
        $prodi_id = $DB->get_field_sql($prodi_sql, ['courseid' => $courseid], IGNORE_MULTIPLE);

        $b_sub  = self::ambil_bobot_relasi($cpmk_record->id ?? 0, $sub_cpmk->id, $id_tahun_akademik);
        $b_cpmk = self::ambil_bobot_relasi($indikator_record->id ?? 0, $cpmk_record->id ?? 0, $id_tahun_akademik);
        $b_ind  = self::ambil_bobot_relasi($cpl_record->id ?? 0, $indikator_record->id ?? 0, $id_tahun_akademik);

        // =====================================================
        // AMBIL AKTIVITAS MAP via Course Modinfo API Moodle
        // =====================================================
        $aktivitas_list = self::ambil_aktivitas_subcpmk($courseid, $sub_cpmk_id);

        // =====================================================
        // AMBIL DATA MAHASISWA via enrolment API Moodle
        // =====================================================
        $filtered_students = self::ambil_mahasiswa_terdaftar($courseid);
        $student_ids = array_keys($filtered_students);

        if (empty($filtered_students)) {
            return (object) [
                'sub_cpmk' => $sub_cpmk, 'cpmk_record' => $cpmk_record, 'indikator_record' => $indikator_record, 'cpl_record' => $cpl_record,
                'bobot_sub_display' => $b_sub, 'bobot_cpmk_display' => $b_cpmk, 'bobot_ind_display' => $b_ind,
                'users' => [], 'aktivitas_list' => $aktivitas_list
            ];
        }

        // =====================================================
        // BATCHING GRADE via Grade API Moodle
        // =====================================================
        $grades_lookup = self::ambil_grade_aktivitas($courseid, $aktivitas_list, $student_ids);

        // =====================================================
        // PROSES PERHITUNGAN OBE MAHASISWA
        // =====================================================
        $processed_users = [];

        foreach ($filtered_students as $userid => $user) {
            $total_nilai_obe = 0;
            $rincian_list = [];

            foreach ($aktivitas_list as $akt) {
                // Ambil dari lookup array hasil Grade API Moodle.
                $grade = $grades_lookup[$userid][$akt->cmid] ?? null;

                $skor = ($grade && $grade->finalgrade !== null) ? floatval($grade->finalgrade) : 0;
                $max  = ($grade && $grade->rawgrademax) ? floatval($grade->rawgrademax) : 100;

                $nilai = ($max > 0) ? ($skor / $max) * 100 : 0;
                $nilai_terbobot = $nilai * ($akt->bobot_aktivitas / 100);

                $total_nilai_obe += $nilai_terbobot;

                $rincian_list[] = (object) [
                    'modulename'      => $akt->modulename,
                    'skor'            => $skor,
                    'max'             => $max,
                    'bobot_aktivitas' => $akt->bobot_aktivitas,
                    'nilai_terbobot'  => $nilai_terbobot
                ];
            }

            // Hitung skala grade nilai huruf akhir
            $grade_huruf = \local_obe\manager\skala_nilai_manager::cek_skala(
                $total_nilai_obe,
                'SUB-CPMK',
                $prodi_id,
                $id_tahun_akademik
            );

            $processed_users[] = (object) [
                'id'              => $user->id,
                'firstname'       => $user->firstname,
                'lastname'        => $user->lastname,
                'total_nilai_obe' => $total_nilai_obe,
                'grade'           => $grade_huruf,
                'rincian_list'    => $rincian_list
            ];
        }

        return (object) [
            'sub_cpmk'            => $sub_cpmk,
            'cpmk_record'         => $cpmk_record,
            'indikator_record'    => $indikator_record,
            'cpl_record'          => $cpl_record,
            'bobot_sub_display'   => $b_sub,
            'bobot_cpmk_display'  => $b_cpmk,
            'bobot_ind_display'   => $b_ind,
            'users'               => $processed_users,
            'aktivitas_list'      => $aktivitas_list
        ];
    }

    /// =====================================================
    //skala

    public static function ambil_statistik_skala($courseid, $kompetensi_id, $tipe, $id_tahun_akademik = 0) {
        global $DB, $SESSION;

        if (empty($id_tahun_akademik)) {
            $id_tahun_akademik = $SESSION->obe_id_tahun_akademik ?? 0;
        }

        $tipe_upper = strtoupper(trim($tipe));

        // Sinkronisasi tipe IK menjadi INDIKATOR agar sesuai master tabel skala nilai prodi
        $tipe_db = $tipe_upper;

        // =====================================
        // 1. AMBIL PRODI ID & VALIDASI
        // =====================================
        $sql_prodi = "SELECT mk.id_prodi
                    FROM {local_obe_relasi_kelas} rk
                    JOIN {local_obe_mata_kuliah} mk ON rk.id_mata_kuliah = mk.id
                    WHERE rk.id_kursus_moodle = :courseid";
        
        $prodi_id = $DB->get_field_sql($sql_prodi, ['courseid' => $courseid], IGNORE_MULTIPLE);

        if (!$prodi_id) {
            return (object)[
                'total_mahasiswa' => 0,
                'distribusi_grade' => [],
                'jumlah_kompeten' => 0,
                'persentase_kompeten' => 0
            ];
        }

        // =====================================
        // 2. OPTIMASI DATA MASTER SKALA NILAI (LUAR LOOP)
        // =====================================
        $skala_kompeten = $DB->get_fieldset_select(
            'local_obe_skala_nilai',
            'huruf_nilai',
            'id_prodi = :prodi AND id_tahun_akademik = :tahun AND tipe_kompetensi = :tipe AND status_kompeten = 1',
            ['prodi' => $prodi_id, 'tahun' => $id_tahun_akademik, 'tipe' => $tipe_db]
        );
        $map_kompeten = array_fill_keys($skala_kompeten, true);

        // Optimasi cache sub-data untuk tipe SUB-CPMK agar tidak kena N+1 Query Problem
        $cache_subdata = null;
        if ($tipe_upper === 'SUB-CPMK') {
            $parent_id = $DB->get_field('local_obe_kompetensi', 'parent_id', ['id' => $kompetensi_id]) ?: 0;
            if ($parent_id > 0) {
                $cache_subdata = self::ambil_rincian_sub_cpmk_dan_total($courseid, $parent_id, $id_tahun_akademik);
            }
        }

        // =====================================
        // 3. PROSES DATA MAHASISWA
        // =====================================
        $context = \context_course::instance($courseid);
        $users = get_enrolled_users($context);

        $total_mahasiswa = 0;
        $grade_counter = [];
        $kompeten = 0;

        foreach ($users as $user) {
            // Skip dosen / admin
            if (has_capability('moodle/course:update', $context, $user->id)) {
                continue;
            }

            $total_mahasiswa++;
            $grade = '-';

            switch ($tipe_upper) {
               case 'CPL':
                    $nilai=
                    self::ambil_nilai_cpl_per_user( $courseid,$kompetensi_id, $user->id, $id_tahun_akademik );
                    $grade=\local_obe\manager\skala_nilai_manager::cek_skala($nilai,'CPL',  $prodi_id, $id_tahun_akademik);

                break;

                case 'INDIKATOR':
                    $nilai = self::ambil_nilai_indikator_per_user(
                        $courseid,
                        $kompetensi_id,
                        $user->id,
                        $id_tahun_akademik
                    );

                    $grade = \local_obe\manager\skala_nilai_manager::cek_skala(
                        $nilai,
                        'INDIKATOR',
                        $prodi_id,
                        $id_tahun_akademik
                    );
                break;

                case 'CPMK':
                    $nilai=
                    self::ambil_nilai_cpmk_per_user( $courseid, $kompetensi_id,$user->id,$id_tahun_akademik);
                    $grade=\local_obe\manager\skala_nilai_manager::cek_skala($nilai, 'CPMK',$prodi_id, $id_tahun_akademik);

                    break;
                case 'SUB-CPMK':$detail = self::ambil_detail_data_mahasiswa_obe($courseid, $kompetensi_id, 0, 0, $id_tahun_akademik);
                     $nilai = 0;
                foreach ($detail->users as $mhs) {
                        if ($mhs->id == $user->id) {
                            $nilai = $mhs->total_nilai_obe;
                            break;
                        }
                    }

                    $grade = \local_obe\manager\skala_nilai_manager::cek_skala(
                        $nilai,
                        'SUB-CPMK',
                        $prodi_id,
                        $id_tahun_akademik
                    );
                    break;

           
            }

            // Normalisasi string huruf nilai mutu
            $grade = strtoupper(trim($grade));

            // Akumulasi distribusi grade kelas
            if (!isset($grade_counter[$grade])) {
                $grade_counter[$grade] = 0;
            }
            $grade_counter[$grade]++;

            // Validasi kelulusan kompetensi via array map instan (O(1))
            if (isset($map_kompeten[$grade])) {
                $kompeten++;
            }
        }

        // =====================================
        // 4. HITUNG PERSENTASE DISTRIBUSI
        // =====================================
        $hasil = [];
        foreach ($grade_counter as $huruf => $jumlah) {
            $persen = ($total_mahasiswa > 0) ? ($jumlah / $total_mahasiswa) * 100 : 0;
            
            $hasil[] = (object)[
                'huruf'      => $huruf,
                'jumlah'     => $jumlah,
                'persentase' => round($persen, 2)
            ];
        }

        $persentase_kompeten = ($total_mahasiswa > 0) ? ($kompeten / $total_mahasiswa) * 100 : 0;

        return (object)[
            'total_mahasiswa'     => $total_mahasiswa,
            'distribusi_grade'    => $hasil,
            'jumlah_kompeten'     => $kompeten,
            'persentase_kompeten' => round($persentase_kompeten, 2)
        ];
    }
        private static function ambil_nilai_cpl_per_user( $courseid, $cpl_id,$userid, $id_tahun_akademik = 0){

        global $DB,$SESSION;

        if(empty($id_tahun_akademik)){
            $id_tahun_akademik=
            $SESSION->obe_id_tahun_akademik ?? 0;
        }

        // ===============================
        // Ambil semua indikator
        // ===============================

        $indikators=
        $DB->get_records(

            'local_obe_kompetensi',

            [
                'parent_id'=>$cpl_id,
                'tipe'=>'INDIKATOR'
            ]

        );

        if(empty($indikators)){
            return 0;
        }

        $total=0;

        foreach(
            $indikators as $indikator
        ){

            // bobot indikator terhadap CPL
            $bobot=
            self::ambil_bobot_relasi(

                $cpl_id,

                $indikator->id,

                $id_tahun_akademik

            );

            // nilai indikator mahasiswa
            $nilai=
            self::ambil_nilai_indikator_per_user(

                $courseid,

                $indikator->id,

                $userid,

                $id_tahun_akademik

            );

            $total+=
            $nilai
            *
            (
                $bobot/100
            );

        }

        return round(
            $total,
            2
        );

    }

        private static function ambil_nilai_cpmk_per_user( $courseid,  $cpmk_id, $userid, $id_tahun_akademik = 0){
            global $DB,$SESSION;

            if(empty($id_tahun_akademik)){
                $id_tahun_akademik=
                $SESSION->obe_id_tahun_akademik ?? 0;
            }

            // =====================================
            // AMBIL SEMUA SUB CPMK
            // =====================================

            $subs=
            $DB->get_records(
                'local_obe_kompetensi',
                [
                    'parent_id'=>$cpmk_id
                ],
                '',
                'id,nama_kompetensi'
            );

            if(empty($subs)){
                return 0;
            }

            $total_nilai_cpmk=0;

            // =====================================
            // LOOP SUB CPMK
            // =====================================

            foreach($subs as $sub){

                // Ambil bobot Sub CPMK terhadap CPMK
                $bobot_sub=
                self::ambil_bobot_relasi(

                    $cpmk_id,

                    $sub->id,

                    $id_tahun_akademik

                );

                // Ambil detail nilai mahasiswa
                $detail=
                self::ambil_detail_data_mahasiswa_obe(

                    $courseid,

                    $sub->id,

                    $cpmk_id,

                    0,

                    $id_tahun_akademik

                );

                $nilai_sub=0;

                foreach(
                    $detail->users
                    as $mhs
                ){

                    if(
                    $mhs->id==
                    $userid
                    ){

                        $nilai_sub=
                        $mhs->total_nilai_obe;

                        break;
                    }

                }

                // Akumulasi nilai CPMK
                $total_nilai_cpmk +=
                $nilai_sub
                *
                (
                    $bobot_sub/100
                );

            }

            return round(
                $total_nilai_cpmk,
                2
            );

        }
        private static function ambil_nilai_indikator_per_user($courseid,$indikator_id, $userid,$id_tahun_akademik=0){

        global $DB,$SESSION;

        if(empty($id_tahun_akademik)){
            $id_tahun_akademik=
            $SESSION->obe_id_tahun_akademik ?? 0;
        }

        $cpmks=
        $DB->get_records(
            'local_obe_kompetensi',
            [
                'parent_id'=>$indikator_id
            ]
        );

        if(empty($cpmks)){
            return 0;
        }

        $total=0;

        foreach(
            $cpmks as $cpmk
        ){

            $bobot=
            self::ambil_bobot_relasi(

                $indikator_id,

                $cpmk->id,

                $id_tahun_akademik

            );

            $nilai=
            self::ambil_nilai_cpmk_per_user(

                $courseid,

                $cpmk->id,

                $userid,

                $id_tahun_akademik

            );

            $total +=
            $nilai
            *
            (
                $bobot/100
            );

        }

        return round(
            $total,
            2
        );

        }
     

    // =====================================================
    // DETAIL CAPAIAN KOMPETENSI MAHASISWA
    // =====================================================

    /**
     * Mengambil rincian nilai capaian, grade, dan status kompeten tiap mahasiswa dalam satu kelas
     *
     * @param int $courseid
     * @param int $kompetensi_id
     * @param string $tipe
     * @param int $id_tahun_akademik
     * @return array
     */
    public static function ambil_detail_kompetensi_mahasiswa($courseid,$kompetensi_id, $tipe, $id_tahun_akademik = 0 ) {
        global $DB, $SESSION;

        if (empty($id_tahun_akademik)) {
            $id_tahun_akademik = $SESSION->obe_id_tahun_akademik ?? 0;
        }

        $context = \context_course::instance($courseid);
        $users = get_enrolled_users($context);

        // Ambil ID Program Studi berdasarkan relasi kelas moodle
        $prodi_id = $DB->get_field_sql(
            "SELECT mk.id_prodi
             FROM {local_obe_relasi_kelas} rk
             JOIN {local_obe_mata_kuliah} mk ON rk.id_mata_kuliah = mk.id
             WHERE rk.id_kursus_moodle = :cid",
            ['cid' => $courseid],
            IGNORE_MULTIPLE
        );

        $results = [];

        foreach ($users as $user) {
            // Skip pengguna dengan hak akses pengajar / pengelola kelas
            if (has_capability('moodle/course:update', $context, $user->id)) {
                continue;
            }

            $nilai = 0;

            switch (strtoupper($tipe)) {

                case 'CPL':
                    $nilai = self::ambil_nilai_cpl_per_user(
                        $courseid,
                        $kompetensi_id,
                        $user->id,
                        $id_tahun_akademik
                    );
                    break;

                case 'INDIKATOR':
                    $nilai = self::ambil_nilai_indikator_per_user(
                        $courseid,
                        $kompetensi_id,
                        $user->id,
                        $id_tahun_akademik
                    );
                    break;

                case 'CPMK':
                    $nilai = self::ambil_nilai_cpmk_per_user(
                        $courseid,
                        $kompetensi_id,
                        $user->id,
                        $id_tahun_akademik
                    );
                    break;

                case 'SUB-CPMK':
                    $detail = self::ambil_detail_data_mahasiswa_obe(
                        $courseid,
                        $kompetensi_id,
                        0,
                        0,
                        $id_tahun_akademik
                    );

                    foreach ($detail->users as $mhs) {
                        if ($mhs->id == $user->id) {
                            $nilai = $mhs->total_nilai_obe;
                            break;
                        }
                    }
                    break;
            }

            // Hitung grade huruf mutu berdasarkan akumulasi nilai murni
            $grade = \local_obe\manager\skala_nilai_manager::cek_skala(
                $nilai,
                $tipe,
                $prodi_id,
                $id_tahun_akademik
            );

            // Cek status ketuntasan kompetensi mahasiswa
            $kompeten = self::cek_status_kompeten(
                $grade,
                $tipe,
                $prodi_id,
                $id_tahun_akademik
            );

            $results[] = (object) [
                'userid'   => $user->id,
                'nama'     => $user->firstname . ' ' . $user->lastname,
                'nilai'    => round($nilai, 2),
                'grade'    => $grade,
                'kompeten' => $kompeten
            ];
        }

        return $results;
    }
    private static function cek_status_kompeten($grade, $tipe, $prodi_id, $tahun){
    global $DB;

    return $DB->record_exists(
        'local_obe_skala_nilai',
        [
            'huruf_nilai'=>$grade,
            'tipe_kompetensi'=>$tipe,
            'id_prodi'=>$prodi_id,
            'id_tahun_akademik'=>$tahun,
            'status_kompeten'=>1
        ]
    );
}

    // =============================================================================================================
    // capaian prodi
    // ============================================================================================================

    /**
     * Mengambil daftar kelas berdasarkan program studi dan tahun akademik
     * 
     * @param int $prodi_id
     * @param int $id_tahun_akademik
     * @return array
     */
    public static function ambil_kelas_prodi($prodi_id, $id_tahun_akademik = 0, $angkatan_id = 0) 
    {
       global $DB, $SESSION;

            if (empty($id_tahun_akademik)) {
                $id_tahun_akademik = $SESSION->obe_id_tahun_akademik ?? 0;
            }

            $sql = "SELECT rk.id_kursus_moodle,rk.id_mata_kuliah,rk.id_angkatan,mk.nama_mk
                FROM {local_obe_relasi_kelas} rk
                JOIN {local_obe_mata_kuliah} mk
                    ON rk.id_mata_kuliah = mk.id
                WHERE mk.id_prodi = :prodi
                AND rk.id_tahun_akademik = :tahun";

            $params = ['prodi' => $prodi_id,'tahun' => $id_tahun_akademik];

            if ($angkatan_id > 0) {$sql .= " AND rk.id_angkatan = :angkatan "; 
                    $params['angkatan'] = $angkatan_id;}

            return $DB->get_records_sql($sql, $params);
        }
    

   // =====================================================
    // CPL LEVEL PRODI
    // =====================================================
    /**
     * Mengambil dan menghitung akumulasi rata-rata capaian nilai CPL tingkat Program Studi
     * berdasarkan bobot jumlah mahasiswa pada tiap kelas mata kuliah aktif.
     *
     * @param int $prodi_id
     * @param int $id_tahun_akademik
     * @return array
     */
    public static function ambil_capaian_cpl_prodi($prodi_id, $id_tahun_akademik = 0, $angkatan_id = 0) {
        global $SESSION;

        if (empty($id_tahun_akademik)) {
            $id_tahun_akademik = $SESSION->obe_id_tahun_akademik ?? 0;
        }

        // =====================================
        // AMBIL KELAS PRODI
        // =====================================
        $kelas = self::ambil_kelas_prodi($prodi_id, $id_tahun_akademik, $angkatan_id);

        if (empty($kelas)) {
            return [];
        }

        $hasil = [];

        // =====================================
        // LOOP KELAS MALAM / REGULER
        // =====================================
        foreach ($kelas as $k) {
            $cpl_kelas = self::ambil_capaian_cpl_dengan_rincian($k->id_kursus_moodle, $id_tahun_akademik);

            foreach ($cpl_kelas as $cpl) {
                if (!isset($hasil[$cpl->cpl_id])) {
                    $hasil[$cpl->cpl_id] = (object) [
                        'cpl_id'          => $cpl->cpl_id,
                        'nama_cpl'        => $cpl->nama_cpl,
                        'total_nilai'     => 0,
                        'total_mahasiswa' => 0
                    ];
                }

                // Akumulasi nilai berdasarkan bobot populasi jumlah mahasiswa kelas (weighted average)
                $hasil[$cpl->cpl_id]->total_nilai += ($cpl->percentage * $cpl->total_users);
                $hasil[$cpl->cpl_id]->total_mahasiswa += $cpl->total_users;
            }
        }

        // =====================================
        // HITUNG RATA-RATA FINAL & GRADE
        // =====================================
        foreach ($hasil as &$cpl) {
            $pembagi = max(1, $cpl->total_mahasiswa);
            $cpl->percentage = round($cpl->total_nilai / $pembagi, 2);

            // Konversi nilai akumulasi Prodi ke Grade Huruf Mutu
            $cpl->grade = \local_obe\manager\skala_nilai_manager::cek_skala(
                $cpl->percentage,
                'CPL',
                $prodi_id,
                $id_tahun_akademik
            );
        }
        unset($cpl);

        return array_values($hasil);
    }            
   

    // =====================================================
    // INDIKATOR LEVEL PRODI
    // =====================================================

    /**
     * Mengambil dan menghitung akumulasi rata-rata capaian nilai Indikator CPL tingkat Program Studi
     * berdasarkan pembobotan (weighted average) jumlah mahasiswa aktif pada tiap kelas mata kuliah.
     *
     * @param int $prodi_id
     * @param int $cpl_id
     * @param int $id_tahun_akademik
     * @return array
     */
    public static function ambil_capaian_indikator_prodi($prodi_id, $cpl_id, $id_tahun_akademik = 0, $angkatan_id = 0) {
        global $SESSION;

        if (empty($id_tahun_akademik)) {
            $id_tahun_akademik = $SESSION->obe_id_tahun_akademik ?? 0;
        }

        // ============================
        // AMBIL SELURUH KELAS PRODI
        // ============================
        $kelas = self::ambil_kelas_prodi($prodi_id, $id_tahun_akademik, $angkatan_id);

        if (empty($kelas)) {
            return [];
        }

        $hasil = [];

        // ============================
        // LOOP KELAS
        // ============================
        foreach ($kelas as $k) {
            $indikator_kelas = self::ambil_rincian_indikator_dan_kontribusi(
                $k->id_kursus_moodle,
                $cpl_id,
                $id_tahun_akademik
            );

            foreach ($indikator_kelas as $ind) {
                if (!isset($hasil[$ind->id])) {
                    $hasil[$ind->id] = (object) [
                        'id'              => $ind->id,
                        'nama'            => $ind->nama,
                        'bobot'           => $ind->bobot,
                        'total_nilai'     => 0,
                        'total_mahasiswa' => 0
                    ];
                }

                // Akumulasi nilai berdasarkan bobot populasi jumlah mahasiswa kelas (weighted average)
                $hasil[$ind->id]->total_nilai += ($ind->total_indikator * $ind->total_users);
                $hasil[$ind->id]->total_mahasiswa += $ind->total_users;
            }
        }

        // ============================
        // HITUNG FINAL & GRADE
        // ============================
        foreach ($hasil as &$ind) {
            $pembagi = max(1, $ind->total_mahasiswa);
            $ind->total_indikator = round($ind->total_nilai / $pembagi, 2);

            // Konversi nilai rata-rata tingkat prodi ke Grade Huruf Mutu
            $ind->grade = \local_obe\manager\skala_nilai_manager::cek_skala(
                $ind->total_indikator,
                'INDIKATOR',
                $prodi_id,
                $id_tahun_akademik
            );

            // Bersihkan field temporary penyangga akumulasi data
            unset($ind->total_nilai);
            unset($ind->total_mahasiswa);
        }
        unset($ind);

        return array_values($hasil);
    }
    // =====================================================
    // CPMK LEVEL PRODI
    // =====================================================

     /**
     * Mengambil dan menghitung akumulasi rata-rata capaian nilai CPMK tingkat Program Studi
     * berdasarkan pembobotan (weighted average) jumlah mahasiswa aktif dari seluruh kelas mata kuliah.
     *
     * @param int $prodi_id
     * @param int $indikator_id
     * @param int $id_tahun_akademik
     * @return array
     */
    public static function ambil_capaian_cpmk_prodi($prodi_id, $indikator_id, $id_tahun_akademik = 0,  $angkatan_id = 0) {
        global $SESSION;

        if (empty($id_tahun_akademik)) {
            $id_tahun_akademik = $SESSION->obe_id_tahun_akademik ?? 0;
        }

        // =====================================
        // AMBIL SELURUH KELAS PRODI
        // =====================================
        $kelas = self::ambil_kelas_prodi($prodi_id, $id_tahun_akademik, $angkatan_id);

        if (empty($kelas)) {
            return [];
        }

        $hasil = [];

        // =====================================
        // LOOP KELAS
        // =====================================
        foreach ($kelas as $k) {
            $cpmk_kelas = self::ambil_rincian_cpmk_dan_kontribusi(
                $k->id_kursus_moodle,
                $indikator_id,
                $id_tahun_akademik
            );

            foreach ($cpmk_kelas as $cpmk) {
                if (!isset($hasil[$cpmk->id])) {
                    $hasil[$cpmk->id] = (object) [
                        'cpmk_id'         => $cpmk->id,
                        'nama_cpmk'       => $cpmk->nama,
                        'bobot'           => $cpmk->bobot,
                        'total_nilai'     => 0,
                        'total_mahasiswa' => 0
                    ];
                }

                // Akumulasi nilai berdasarkan bobot populasi jumlah mahasiswa kelas (weighted average)
                $hasil[$cpmk->id]->total_nilai += ($cpmk->total_cpmk * $cpmk->total_users);
                $hasil[$cpmk->id]->total_mahasiswa += $cpmk->total_users;
            }
        }

        // =====================================
        // HITUNG RATA-RATA FINAL
        // =====================================
        foreach ($hasil as &$h) {
            $pembagi = max(1, $h->total_mahasiswa);
            $h->percentage = round($h->total_nilai / $pembagi, 2);

            // Bersihkan field temporary penyangga akumulasi data
            unset($h->total_nilai);
            unset($h->total_mahasiswa);
        }
        unset($h);

        return array_values($hasil);
    }

    /**
     * Mengambil dan menghitung akumulasi rata-rata capaian nilai Sub CPMK tingkat Program Studi
     * berdasarkan pembobotan (weighted average) jumlah mahasiswa aktif dari seluruh kelas mata kuliah.
     *
     * @param int $prodi_id
     * @param int $cpmk_id
     * @param int $id_tahun_akademik
     * @return array
     */
    public static function ambil_capaian_subcpmk_prodi($prodi_id, $cpmk_id, $id_tahun_akademik = 0, $angkatan_id = 0) {
        global $SESSION;

        if (empty($id_tahun_akademik)) {
            $id_tahun_akademik = $SESSION->obe_id_tahun_akademik ?? 0;
        }

        // =====================================
        // AMBIL SEMUA KELAS DALAM PRODI
        // =====================================
        $kelas = self::ambil_kelas_prodi($prodi_id, $id_tahun_akademik, $angkatan_id);

        if (empty($kelas)) {
            return [];
        }

        $hasil = [];

        // =====================================
        // LOOP SEMUA KELAS
        // =====================================
        foreach ($kelas as $k) {
            $sub_data = self::ambil_rincian_sub_cpmk_dan_total(
                $k->id_kursus_moodle,
                $cpmk_id,
                $id_tahun_akademik
            );

            // Validasi struktur data internal
            if (empty($sub_data->sub_data)) {
                continue;
            }

            // =====================================
            // LOOP SUB CPMK
            // =====================================
            foreach ($sub_data->sub_data as $sub) {
                if (!isset($hasil[$sub->id])) {
                    $hasil[$sub->id] = (object) [
                        'subcpmk_id'      => $sub->id,
                        'nama_subcpmk'    => $sub->nama_kompetensi,
                        'bobot'           => $sub->bobot_subcpmk,
                        'total_nilai'     => 0,
                        'total_mahasiswa' => 0
                    ];
                }

                // Akumulasi nilai berdasarkan bobot populasi jumlah mahasiswa kelas (weighted average)
                $hasil[$sub->id]->total_nilai += ($sub->rata_rata_nilai * ($sub->total_users ?? 0));
                $hasil[$sub->id]->total_mahasiswa += ($sub->total_users ?? 0);
            }
        }

        // =====================================
        // HITUNG RATA-RATA FINAL
        // =====================================
        foreach ($hasil as &$sub) {
            $pembagi = max(1, $sub->total_mahasiswa);
            $sub->percentage = round($sub->total_nilai / $pembagi, 2);

            // Bersihkan field temporary penyangga akumulasi data
            unset($sub->total_nilai);
            unset($sub->total_mahasiswa);
        }
        unset($sub);

        return array_values($hasil);
    }

    // =====================================================
    // level mata kuliah
    // =====================================================
/**
     * Mengambil capaian CPL tingkat Mata Kuliah
     * (Gabungan / akumulasi seluruh kelas paralel mata kuliah).
     *
     * @param int $matkul_id
     * @param int $id_tahun_akademik
     * @return array
     */
    public static function ambil_capaian_cpl_matakuliah($matkul_id, $id_tahun_akademik = 0, $angkatan_id = 0) {
        global $DB, $SESSION;

        if (empty($id_tahun_akademik)) {
            $id_tahun_akademik = $SESSION->obe_id_tahun_akademik ?? 0;
        }

        // =====================================
        // AMBIL DATA PRODI DARI MATA KULIAH
        // =====================================
        $prodi_id = $DB->get_field('local_obe_mata_kuliah', 'id_prodi', ['id' => $matkul_id]);

        // =====================================
        // AMBIL SELURUH KELAS DARI MATA KULIAH
        // =====================================
        $params = [
                'id_mata_kuliah'    => $matkul_id,
                'id_tahun_akademik' => $id_tahun_akademik
            ];

            if ($angkatan_id > 0) {
                $params['id_angkatan'] = $angkatan_id;
            }

            $kelas = $DB->get_records(
                'local_obe_relasi_kelas',
                $params
            );
        if (empty($kelas)) {
            return [];
        }

        $hasil = [];

        // =====================================
        // LOOP SELURUH KELAS / PARALEL
        // =====================================
        foreach ($kelas as $k) {
            $cpl_kelas = self::ambil_capaian_cpl_dengan_rincian($k->id_kursus_moodle, $id_tahun_akademik);

            foreach ($cpl_kelas as $cpl) {
                if (!isset($hasil[$cpl->cpl_id])) {
                    $hasil[$cpl->cpl_id] = (object) [
                        'cpl_id'          => $cpl->cpl_id,
                        'nama_cpl'        => $cpl->nama_cpl,
                        'total_nilai'     => 0,
                        'total_mahasiswa' => 0
                    ];
                }

                $total_users = $cpl->total_users ?? 0;

                // Akumulasi rata-rata tertimbang (weighted average) berdasarkan jumlah mahasiswa kelas
                $hasil[$cpl->cpl_id]->total_nilai += ($cpl->percentage * $total_users);
                $hasil[$cpl->cpl_id]->total_mahasiswa += $total_users;
            }
        }

        // =====================================
        // HITUNG CAPAIAN FINAL + GRADE
        // =====================================
        foreach ($hasil as &$h) {
            $pembagi = max(1, $h->total_mahasiswa);
            $h->percentage = round($h->total_nilai / $pembagi, 2);

            // Konversi nilai rata-rata gabungan kelas ke Grade Huruf Mutu Mata Kuliah
            $h->grade = \local_obe\manager\skala_nilai_manager::cek_skala(
                $h->percentage,
                'CPL',
                $prodi_id,
                $id_tahun_akademik
            );

            // Bersihkan field temporary penyangga akumulasi data
            unset($h->total_nilai);
            unset($h->total_mahasiswa);
        }
        unset($h);

        return array_values($hasil);
    }
    
    // =====================================================
    //indikator matakuliah
   /**
     * Mengambil capaian Indikator CPL tingkat Mata Kuliah
     * (Gabungan / akumulasi seluruh kelas paralel mata kuliah).
     *
     * @param int $matkul_id
     * @param int $cpl_id
     * @param int $id_tahun_akademik
     * @return array
     */
    public static function ambil_capaian_indikator_matakuliah($matkul_id, $cpl_id, $id_tahun_akademik = 0, $angkatan_id = 0) {
        global $DB, $SESSION;

        if (empty($id_tahun_akademik)) {
            $id_tahun_akademik = $SESSION->obe_id_tahun_akademik ?? 0;
        }

        // =====================================
        // AMBIL PRODI DARI MATA KULIAH
        // =====================================
        $prodi_id = $DB->get_field('local_obe_mata_kuliah', 'id_prodi', ['id' => $matkul_id]);

        // =====================================
        // AMBIL SELURUH KELAS MATA KULIAH
        // =====================================
        $params = [
            'id_mata_kuliah'    => $matkul_id,
            'id_tahun_akademik' => $id_tahun_akademik
        ];

        if ($angkatan_id > 0) {
            $params['id_angkatan'] = $angkatan_id;
        }

        $kelas = $DB->get_records(
            'local_obe_relasi_kelas',
            $params
        );

        if (empty($kelas)) {
            return [];
        }

        $hasil = [];

        // =====================================
        // LOOP KELAS / PARALEL
        // =====================================
        foreach ($kelas as $k) {
            $indikator_kelas = self::ambil_rincian_indikator_dan_kontribusi(
                $k->id_kursus_moodle,
                $cpl_id,
                $id_tahun_akademik
            );

            foreach ($indikator_kelas as $ind) {
                if (!isset($hasil[$ind->id])) {
                    $hasil[$ind->id] = (object) [
                        'id'              => $ind->id,
                        'nama'            => $ind->nama,
                        'bobot'           => $ind->bobot,
                        'total_nilai'     => 0,
                        'total_mahasiswa' => 0
                    ];
                }

                $total_users = $ind->total_users ?? 0;

                // Akumulasi rata-rata tertimbang (weighted average) berdasarkan jumlah mahasiswa kelas
                $hasil[$ind->id]->total_nilai += ($ind->total_indikator * $total_users);
                $hasil[$ind->id]->total_mahasiswa += $total_users;
            }
        }

        // =====================================
        // HITUNG FINAL + GRADE
        // =====================================
        foreach ($hasil as &$h) {
            $pembagi = max(1, $h->total_mahasiswa);
            $h->total_indikator = round($h->total_nilai / $pembagi, 2);

            // Konversi nilai rata-rata gabungan kelas ke Grade Huruf Mutu Tingkat Komponen Indikator
            $h->grade = \local_obe\manager\skala_nilai_manager::cek_skala(
                $h->total_indikator,
                'INDIKATOR',
                $prodi_id,
                $id_tahun_akademik
            );

            // Bersihkan field temporary penyangga akumulasi data
            unset($h->total_nilai);
            unset($h->total_mahasiswa);
        }
        unset($h);

        return array_values($hasil);
    }

    // =====================================================
    //cpmk matakuliah
  /**
     * Mengambil capaian CPMK tingkat Mata Kuliah
     * (Gabungan / akumulasi seluruh kelas paralel mata kuliah).
     *
     * @param int $matkul_id
     * @param int $indikator_id
     * @param int $id_tahun_akademik
     * @return array
     */
    public static function ambil_capaian_cpmk_matakuliah($matkul_id, $indikator_id, $id_tahun_akademik = 0, $angkatan_id = 0) {
        global $DB, $SESSION;

        if (empty($id_tahun_akademik)) {
            $id_tahun_akademik = $SESSION->obe_id_tahun_akademik ?? 0;
        }

        // =====================================
        // AMBIL PRODI DARI MATA KULIAH
        // =====================================
        $prodi_id = $DB->get_field('local_obe_mata_kuliah', 'id_prodi', ['id' => $matkul_id]);

        // =====================================
        // AMBIL SELURUH KELAS MATA KULIAH
        // =====================================
       $params = [
            'id_mata_kuliah'    => $matkul_id,
            'id_tahun_akademik' => $id_tahun_akademik
        ];

        if ($angkatan_id > 0) {
            $params['id_angkatan'] = $angkatan_id;
        }

        $kelas = $DB->get_records(
            'local_obe_relasi_kelas',
            $params
        );

        if (empty($kelas)) {
            return [];
        }

        $hasil = [];

        // =====================================
        // LOOP KELAS / PARALEL
        // =====================================
        foreach ($kelas as $k) {
            $cpmk_kelas = self::ambil_rincian_cpmk_dan_kontribusi(
                $k->id_kursus_moodle,
                $indikator_id,
                $id_tahun_akademik
            );

            foreach ($cpmk_kelas as $cpmk) {
                if (!isset($hasil[$cpmk->id])) {
                    $hasil[$cpmk->id] = (object) [
                        'cpmk_id'         => $cpmk->id,
                        'nama_cpmk'       => $cpmk->nama,
                        'bobot'           => $cpmk->bobot,
                        'total_nilai'     => 0,
                        'total_mahasiswa' => 0
                    ];
                }

                $total_users = $cpmk->total_users ?? 0;

                // Akumulasi rata-rata tertimbang (weighted average) berdasarkan jumlah mahasiswa
                $hasil[$cpmk->id]->total_nilai += ($cpmk->total_cpmk * $total_users);
                $hasil[$cpmk->id]->total_mahasiswa += $total_users;
            }
        }

        // =====================================
        // HITUNG FINAL + GRADE
        // =====================================
        foreach ($hasil as &$h) {
            $pembagi = max(1, $h->total_mahasiswa);
            $h->percentage = round($h->total_nilai / $pembagi, 2);

            // Konversi nilai rata-rata gabungan kelas ke Grade Huruf Mutu Tingkat CPMK Mata Kuliah
            $h->grade = \local_obe\manager\skala_nilai_manager::cek_skala(
                $h->percentage,
                'CPMK',
                $prodi_id,
                $id_tahun_akademik
            );

            // Bersihkan field temporary penyangga akumulasi data
            unset($h->total_nilai);
            unset($h->total_mahasiswa);
        }
        unset($h);

        return array_values($hasil);
    }

    // =====================================================
    // SUB CPMK MATKUL
   /**
     * Mengambil capaian Sub CPMK tingkat Mata Kuliah
     * (Gabungan / akumulasi seluruh kelas paralel mata kuliah).
     *
     * @param int $matkul_id
     * @param int $cpmk_id
     * @param int $id_tahun_akademik
     * @return array
     */
    public static function ambil_capaian_subcpmk_matakuliah($matkul_id, $cpmk_id, $id_tahun_akademik = 0 , $angkatan_id = 0) {
        global $DB, $SESSION;

        if (empty($id_tahun_akademik)) {
            $id_tahun_akademik = $SESSION->obe_id_tahun_akademik ?? 0;
        }

        // =====================================
        // AMBIL PRODI DARI MATA KULIAH
        // =====================================
        $prodi_id = $DB->get_field('local_obe_mata_kuliah', 'id_prodi', ['id' => $matkul_id]);

        // =====================================
        // AMBIL SELURUH KELAS MATA KULIAH
        // =====================================
        $params = [
                'id_mata_kuliah'    => $matkul_id,
                'id_tahun_akademik' => $id_tahun_akademik
            ];

            if ($angkatan_id > 0) {
                $params['id_angkatan'] = $angkatan_id;
            }

            $kelas = $DB->get_records(
                'local_obe_relasi_kelas',
                $params
            );

        if (empty($kelas)) {
            return [];
        }

        $hasil = [];

        // =====================================
        // LOOP KELAS / PARALEL
        // =====================================
        foreach ($kelas as $k) {
            $sub_data = self::ambil_rincian_sub_cpmk_dan_total(
                $k->id_kursus_moodle,
                $cpmk_id,
                $id_tahun_akademik
            );

            if (empty($sub_data->sub_data)) {
                continue;
            }

            foreach ($sub_data->sub_data as $sub) {
                if (!isset($hasil[$sub->id])) {
                    $hasil[$sub->id] = (object) [
                        'subcpmk_id'      => $sub->id,
                        'nama_subcpmk'    => $sub->nama_kompetensi,
                        'bobot'           => $sub->bobot_subcpmk,
                        'total_nilai'     => 0,
                        'total_mahasiswa' => 0
                    ];
                }

                $total_users = $sub->total_users ?? 0;

                // Akumulasi rata-rata tertimbang (weighted average) berdasarkan jumlah mahasiswa
                $hasil[$sub->id]->total_nilai += ($sub->rata_rata_nilai * $total_users);
                $hasil[$sub->id]->total_mahasiswa += $total_users;
            }
        }

        // =====================================
        // HITUNG FINAL + GRADE
        // =====================================
        foreach ($hasil as &$h) {
            $pembagi = max(1, $h->total_mahasiswa);
            $h->percentage = round($h->total_nilai / $pembagi, 2);

            // Konversi nilai rata-rata gabungan kelas ke Grade Huruf Mutu Tingkat Sub-CPMK Mata Kuliah
            $h->grade = \local_obe\manager\skala_nilai_manager::cek_skala(
                $h->percentage,
                'SUB-CPMK',
                $prodi_id,
                $id_tahun_akademik
            );

            // Bersihkan field temporary penyangga akumulasi data
            unset($h->total_nilai);
            unset($h->total_mahasiswa);
        }
        unset($h);

        return array_values($hasil);
    }

    // =====================================================
    // level kelas
   public static function ambil_data_export_hierarki_kelas($courseid, $id_tahun_akademik = 0) {
    global $SESSION, $DB;

    if (empty($id_tahun_akademik)) {
        $id_tahun_akademik = $SESSION->obe_id_tahun_akademik ?? 0;
    }

    $rows = [];

    $cpls = self::ambil_capaian_cpl_dengan_rincian(
        $courseid,
        $id_tahun_akademik
    );

    if (empty($cpls)) {
        return $rows;
    }

    // Ambil parameter id_prodi menggunakan query JOIN yang presisi
    $prodi_id = $DB->get_field_sql(
        "SELECT mk.id_prodi
           FROM {local_obe_relasi_kelas} rk
           JOIN {local_obe_mata_kuliah} mk
             ON rk.id_mata_kuliah = mk.id
          WHERE rk.id_kursus_moodle = :courseid",
        ['courseid' => $courseid],
        IGNORE_MULTIPLE
    );
    
    $prodi_id = (int)$prodi_id;

    foreach ($cpls as $cpl) {

        // =====================================================
        // LEVEL: CPL
        // =====================================================
        $rows[] = [
            'level'  => 'CPL',
            'nama'   => $cpl->nama_cpl,
            'nilai'  => round($cpl->percentage, 2),
            'grade'  => $cpl->grade,
            'status' => self::cek_status_kompeten(
                $cpl->grade,
                'CPL',
                $prodi_id,
                $id_tahun_akademik
            ) ? 'Kompeten' : 'Belum Kompeten'
        ];

        foreach ($cpl->indikator_list as $indikator) {

            // =====================================================
            // LEVEL: INDIKATOR
            // =====================================================
            $rows[] = [
                'level'  => 'INDIKATOR',
                'nama'   => $indikator->nama,
                'nilai'  => round($indikator->total_indikator, 2),
                'grade'  => $indikator->grade,
                'status' => self::cek_status_kompeten(
                    $indikator->grade,
                    'INDIKATOR',
                    $prodi_id,
                    $id_tahun_akademik
                ) ? 'Kompeten' : 'Belum Kompeten'
            ];

            $cpmks = self::ambil_rincian_cpmk_dan_kontribusi(
                $courseid,
                $indikator->id,
                $id_tahun_akademik
            );

            if (empty($cpmks)) {
                continue;
            }

            foreach ($cpmks as $cpmk) {

                // =====================================================
                // LEVEL: CPMK
                // =====================================================
                $rows[] = [
                    'level'  => 'CPMK',
                    'nama'   => $cpmk->nama,
                    'nilai'  => round($cpmk->total_cpmk, 2),
                    'grade'  => $cpmk->grade,
                    'status' => self::cek_status_kompeten(
                        $cpmk->grade,
                        'CPMK',
                        $prodi_id,
                        $id_tahun_akademik
                    ) ? 'Kompeten' : 'Belum Kompeten'
                ];

                $subs = self::ambil_rincian_sub_cpmk_dan_total(
                    $courseid,
                    $cpmk->id,
                    $id_tahun_akademik
                );

                if (empty($subs) || empty($subs->sub_data)) {
                    continue;
                }

                foreach ($subs->sub_data as $sub) {

                    // =====================================================
                    // LEVEL: SUB-CPMK
                    // =====================================================
                    $rows[] = [
                        'level'  => 'SUB-CPMK',
                        'nama'   => $sub->nama_kompetensi,
                        'nilai'  => round($sub->rata_rata_nilai, 2),
                        'grade'  => $sub->grade,
                        'status' => self::cek_status_kompeten(
                            $sub->grade,
                            'SUB-CPMK',
                            $prodi_id,
                            $id_tahun_akademik
                        ) ? 'Kompeten' : 'Belum Kompeten'
                    ];
                }
            }
        }
    }

    return $rows;
    }
    //level mahasiswa
    public static function ambil_data_export_mahasiswa_kelas($courseid, $id_tahun_akademik = 0) {
        global $SESSION, $DB;

        if (empty($id_tahun_akademik)) {
            $id_tahun_akademik = $SESSION->obe_id_tahun_akademik ?? 0;
        }

        $rows = [];

        // 1. Ambil pohon silsilah CPL sampai sub-data kompetensi di hulu
        $cpls = self::ambil_capaian_cpl_dengan_rincian($courseid, $id_tahun_akademik);
        
        if (empty($cpls)) {
            return $rows;
        }

        // 2. Ambil parameter id_prodi menggunakan query JOIN yang presisi
        $prodi_id = $DB->get_field_sql(
            "SELECT mk.id_prodi
            FROM {local_obe_relasi_kelas} rk
            JOIN {local_obe_mata_kuliah} mk ON rk.id_mata_kuliah = mk.id
            WHERE rk.id_kursus_moodle = :courseid",
            ['courseid' => $courseid],
            IGNORE_MULTIPLE
        );
        
        $prodi_id = (int)$prodi_id;

        foreach ($cpls as $cpl) {
            foreach ($cpl->indikator_list as $indikator) {
                $cpmks = self::ambil_rincian_cpmk_dan_kontribusi($courseid, $indikator->id, $id_tahun_akademik);
                if (empty($cpmks)) {
                    continue;
                }

                foreach ($cpmks as $cpmk) {
                    $subs = self::ambil_rincian_sub_cpmk_dan_total($courseid, $cpmk->id, $id_tahun_akademik);
                    if (empty($subs) || empty($subs->sub_data)) {
                        continue;
                    }

                    foreach ($subs->sub_data as $sub) {
                        // Ambil detail nilai kompetensi Sub-CPMK mahasiswa
                        $mahasiswa = self::ambil_detail_kompetensi_mahasiswa($courseid, $sub->id, 'SUB-CPMK', $id_tahun_akademik);
                        
                        if (empty($mahasiswa)) {
                            continue;
                        }

                        // [TAMBAHAN BARU 1]: Bulk retrieval untuk relasi hirarki OBE di atasnya
                        $detail_cpmk = self::ambil_detail_kompetensi_mahasiswa($courseid, $cpmk->id, 'CPMK', $id_tahun_akademik);
                        $detail_indikator = self::ambil_detail_kompetensi_mahasiswa($courseid, $indikator->id, 'INDIKATOR', $id_tahun_akademik);
                        $detail_cpl = self::ambil_detail_kompetensi_mahasiswa($courseid, $cpl->cpl_id, 'CPL', $id_tahun_akademik);

                        // [TAMBAHAN BARU 2]: Pembuatan Lookup Array berbasis User ID
                        $cpmk_lookup = [];
                        if (!empty($detail_cpmk)) {
                            foreach ($detail_cpmk as $d) {
                                $cpmk_lookup[$d->userid] = $d;
                            }
                        }

                        $indikator_lookup = [];
                        if (!empty($detail_indikator)) {
                            foreach ($detail_indikator as $d) {
                                $indikator_lookup[$d->userid] = $d;
                            }
                        }

                        $cpl_lookup = [];
                        if (!empty($detail_cpl)) {
                            foreach ($detail_cpl as $d) {
                                $cpl_lookup[$d->userid] = $d;
                            }
                        }

                        // Kumpulkan ID unik mahasiswa
                        $userids = [];
                        foreach ($mahasiswa as $mhs) {
                            if (!empty($mhs->userid)) {
                                $userids[] = (int)$mhs->userid;
                            }
                        }
                        $userids = array_unique($userids);

                        // BYPASS SAFEMODE: Ambil profile user Moodle via Loop Manual untuk kestabilan driver DB
                        $users_lookup = [];
                        foreach ($userids as $userid) {
                            $userrecord = $DB->get_record('user', ['id' => $userid], 'id,username');
                            if ($userrecord) {
                                $users_lookup[$userid] = $userrecord;
                            }
                        }

                        // AMBIL AKTIVITAS MOODLE PENDUKUNG SUB-CPMK
                        $aktivitas = self::ambil_aktivitas_subcpmk($courseid, $sub->id);
                        $allactivities = [];
                        if (!empty($aktivitas)) {
                            foreach ($aktivitas as $akt) {
                                $allactivities[$akt->cmid] = $akt;
                            }
                        }

                        // BULK LOOKUP GRADE AKTIVITAS MOODLE CORE
                        $gradeslookup = !empty($allactivities) ? self::ambil_grade_aktivitas($courseid, $allactivities, $userids) : [];

                        // ITERASI EVALUASI MAHASISWA
                        foreach ($mahasiswa as $mhs) {
                            $username_nim = isset($users_lookup[$mhs->userid]) ? $users_lookup[$mhs->userid]->username : '-';

                            // [TAMBAHAN BARU 3]: Mapping object OBE per user berdasarkan key lookup array
                            $cpmk_user      = $cpmk_lookup[$mhs->userid] ?? null;
                            $indikator_user = $indikator_lookup[$mhs->userid] ?? null;
                            $cpl_user       = $cpl_lookup[$mhs->userid] ?? null;

                            // Perhitungan nilai mentah untuk kebutuhan presisi data report
                            $nilai_cpmk      = !empty($cpmk_user) ? $cpmk_user->nilai : 0;
                            $nilai_indikator = !empty($indikator_user) ? $indikator_user->nilai : 0;
                            $nilai_cpl       = !empty($cpl_user) ? $cpl_user->nilai : 0;

                            if (!empty($aktivitas)) {
                                foreach ($aktivitas as $akt) {
                                    $gradeinfo = $gradeslookup[$mhs->userid][$akt->cmid] ?? null;
                                    $nilai_aktivitas = 0;

                                    if ($gradeinfo && $gradeinfo->finalgrade !== null) {
                                        $max = $gradeinfo->rawgrademax ?: 100;
                                        $nilai_aktivitas = ($max > 0) ? ($gradeinfo->finalgrade / $max) * 100 : 0;
                                    }

                                    // [TAMBAHAN BARU 4]: Implementasi kolom baru sesuai spesifikasi di blok "IF"
                                    $rows[] = [
                                        'nim'               => $username_nim,
                                        'nama_mahasiswa'    => $mhs->nama,
                                        'aktivitas'         => $akt->name,
                                        'nilai_aktivitas'   => round($nilai_aktivitas, 2),
                                        'subcpmk'           => $sub->nama_kompetensi,
                                        'nilai_subcpmk'     => round($mhs->nilai, 2),
                                        'cpmk'              => $cpmk->nama,
                                        'nilai_cpmk'        => round($nilai_cpmk, 2),
                                        'indikator'         => $indikator->nama,
                                        'nilai_indikator'   => round($nilai_indikator, 2),
                                        'cpl'               => $cpl->nama_cpl,
                                        'nilai_cpl'         => round($nilai_cpl, 2),
                                        'grade_subcpmk'     => strtoupper($mhs->grade ?? '-'),
                                        'status_subcpmk'    => $mhs->kompeten ? 'Kompeten' : 'Belum Kompeten',
                                        'grade_cpmk'        => strtoupper($cpmk_user->grade ?? '-'),
                                        'status_cpmk'       => isset($cpmk_user->kompeten) ? ($cpmk_user->kompeten ? 'Kompeten' : 'Belum Kompeten') : '-',
                                        'grade_indikator'   => strtoupper($indikator_user->grade ?? '-'),
                                        'status_indikator'  => isset($indikator_user->kompeten) ? ($indikator_user->kompeten ? 'Kompeten' : 'Belum Kompeten') : '-',
                                        'grade_cpl'         => strtoupper($cpl_user->grade ?? '-'),
                                        'status_cpl'        => isset($cpl_user->kompeten) ? ($cpl_user->kompeten ? 'Kompeten' : 'Belum Kompeten') : '-'
                                    ];
                                }
                            } else {
                                // [TAMBAHAN BARU 4]: Implementasi kolom baru sesuai spesifikasi di blok "ELSE"
                                $rows[] = [
                                    'nim'               => $username_nim,
                                    'nama_mahasiswa'    => $mhs->nama,
                                    'aktivitas'         => '<Tidak Ada Aktivitas>',
                                    'nilai_aktivitas'   => 0.00,
                                    'subcpmk'           => $sub->nama_kompetensi,
                                    'nilai_subcpmk'     => round($mhs->nilai, 2),
                                    'cpmk'              => $cpmk->nama,
                                    'nilai_cpmk'        => round($nilai_cpmk, 2),
                                    'indikator'         => $indikator->nama,
                                    'nilai_indikator'   => round($nilai_indikator, 2),
                                    'cpl'               => $cpl->nama_cpl,
                                    'nilai_cpl'         => round($nilai_cpl, 2),
                                    'grade_subcpmk'     => strtoupper($mhs->grade ?? '-'),
                                    'status_subcpmk'    => $mhs->kompeten ? 'Kompeten' : 'Belum Kompeten',
                                    'grade_cpmk'        => strtoupper($cpmk_user->grade ?? '-'),
                                    'status_cpmk'       => isset($cpmk_user->kompeten) ? ($cpmk_user->kompeten ? 'Kompeten' : 'Belum Kompeten') : '-',
                                    'grade_indikator'   => strtoupper($indikator_user->grade ?? '-'),
                                    'status_indikator'  => isset($indikator_user->kompeten) ? ($indikator_user->kompeten ? 'Kompeten' : 'Belum Kompeten') : '-',
                                    'grade_cpl'         => strtoupper($cpl_user->grade ?? '-'),
                                    'status_cpl'        => isset($cpl_user->kompeten) ? ($cpl_user->kompeten ? 'Kompeten' : 'Belum Kompeten') : '-'
                                ];
                            }
                        }
                    }
                }
            }
        }

        // PENGURUTAN MULTI-LEVEL SORTING (NIM -> Sub-CPMK)
        usort($rows, function($a, $b) {
            $nim_sort = strcmp($a['nim'], $b['nim']);
            if ($nim_sort !== 0) {
                return $nim_sort;
            }
            return strcmp($a['subcpmk'], $b['subcpmk']);
        });

        return $rows;
    }
    //level matakuliah
    public static function ambil_data_export_hierarki_matakuliah( $matkul_id, $id_tahun_akademik = 0, $angkatan_id = 0) {
    global $DB, $SESSION;

    if (empty($id_tahun_akademik)) {
        $id_tahun_akademik = $SESSION->obe_id_tahun_akademik ?? 0;
    }

    $rows = [];

    // =====================================
    // AMBIL PRODI DARI MATA KULIAH
    // =====================================
    $prodi_id = $DB->get_field(
        'local_obe_mata_kuliah',
        'id_prodi',
        ['id' => $matkul_id]
    );

    // =====================================
    // AMBIL CPL MATA KULIAH
    // =====================================
    $cpls = self::ambil_capaian_cpl_matakuliah(
        $matkul_id,
        $id_tahun_akademik,
        $angkatan_id
    );

    if (empty($cpls)) {
        return $rows;
    }

    foreach ($cpls as $cpl) {

        // =====================================
        // LEVEL CPL
        // =====================================
        $rows[] = [
            'level' => 'CPL',
            'nama'  => $cpl->nama_cpl,
            'nilai' => round($cpl->percentage, 2),
            'grade' => $cpl->grade,
            'status' => self::cek_status_kompeten(
                $cpl->grade,
                'CPL',
                $prodi_id,
                $id_tahun_akademik
            ) ? 'Kompeten' : 'Belum Kompeten'
        ];

        // =====================================
        // AMBIL INDIKATOR
        // =====================================
        $indikators = self::ambil_capaian_indikator_matakuliah(
            $matkul_id,
            $cpl->cpl_id,
            $id_tahun_akademik,
            $angkatan_id
        );

        foreach ($indikators as $indikator) {

            $rows[] = [
                'level' => 'INDIKATOR',
                'nama'  => $indikator->nama,
                'nilai' => round($indikator->total_indikator, 2),
                'grade' => $indikator->grade,
                'status' => self::cek_status_kompeten(
                    $indikator->grade,
                    'INDIKATOR',
                    $prodi_id,
                    $id_tahun_akademik
                ) ? 'Kompeten' : 'Belum Kompeten'
            ];

            // =====================================
            // AMBIL CPMK
            // =====================================
            $cpmks = self::ambil_capaian_cpmk_matakuliah(
                $matkul_id,
                $indikator->id,
                $id_tahun_akademik,
                $angkatan_id
            );

            foreach ($cpmks as $cpmk) {

                $rows[] = [
                    'level' => 'CPMK',
                    'nama'  => $cpmk->nama_cpmk,
                    'nilai' => round($cpmk->percentage, 2),
                    'grade' => $cpmk->grade,
                    'status' => self::cek_status_kompeten(
                        $cpmk->grade,
                        'CPMK',
                        $prodi_id,
                        $id_tahun_akademik
                    ) ? 'Kompeten' : 'Belum Kompeten'
                ];

                // =====================================
                // AMBIL SUB CPMK
                // =====================================
                $subs = self::ambil_capaian_subcpmk_matakuliah(
                    $matkul_id,
                    $cpmk->cpmk_id,
                    $id_tahun_akademik,
                    $angkatan_id
                );

                foreach ($subs as $sub) {

                    $rows[] = [
                        'level' => 'SUB-CPMK',
                        'nama'  => $sub->nama_subcpmk,
                        'nilai' => round($sub->percentage, 2),
                        'grade' => $sub->grade,
                        'status' => self::cek_status_kompeten(
                            $sub->grade,
                            'SUB-CPMK',
                            $prodi_id,
                            $id_tahun_akademik
                        ) ? 'Kompeten' : 'Belum Kompeten'
                        ];
                }
            }
        }
    }

    return $rows;
    }
    //level prodi
    public static function ambil_data_export_hierarki_prodi( $prodi_id, $id_tahun_akademik = 0, $angkatan_id = 0) {
        global $SESSION;

        if (empty($id_tahun_akademik)) {
            $id_tahun_akademik = $SESSION->obe_id_tahun_akademik ?? 0;
        }

        $rows = [];

        // =====================================
        // CPL PRODI
        // =====================================
        $cpls = self::ambil_capaian_cpl_prodi(
            $prodi_id,
            $id_tahun_akademik,
            $angkatan_id
        );

        if (empty($cpls)) {
            return $rows;
        }

        foreach ($cpls as $cpl) {

            $grade_cpl = !empty($cpl->grade)
                ? $cpl->grade
                : '-';

            $status_cpl = self::cek_status_kompeten(
                $grade_cpl,
                'CPL',
                $prodi_id,
                $id_tahun_akademik
            );

            $rows[] = [
                'level'  => 'CPL',
                'nama'   => $cpl->nama_cpl,
                'nilai'  => round($cpl->percentage, 2),
                'grade'  => $grade_cpl,
                'status' => $status_cpl ? 'Kompeten' : 'Belum Kompeten'
            ];

            // =====================================
            // INDIKATOR
            // =====================================
            $indikators = self::ambil_capaian_indikator_prodi(
                $prodi_id,
                $cpl->cpl_id,
                $id_tahun_akademik,
                $angkatan_id
            );

            foreach ($indikators as $indikator) {

                $grade_indikator = !empty($indikator->grade)
                    ? $indikator->grade
                    : '-';

                $status_indikator = self::cek_status_kompeten(
                    $grade_indikator,
                    'INDIKATOR',
                    $prodi_id,
                    $id_tahun_akademik
                );

                $rows[] = [
                    'level'  => 'INDIKATOR',
                    'nama'   => $indikator->nama,
                    'nilai'  => round($indikator->total_indikator, 2),
                    'grade'  => $grade_indikator,
                    'status' => $status_indikator ? 'Kompeten' : 'Belum Kompeten'
                ];

                // =====================================
                // CPMK
                // =====================================
                $cpmks = self::ambil_capaian_cpmk_prodi(
                    $prodi_id,
                    $indikator->id,
                    $id_tahun_akademik,
                    $angkatan_id
                );

                foreach ($cpmks as $cpmk) {

                    $grade_cpmk = !empty($cpmk->grade)
                        ? $cpmk->grade
                        : \local_obe\manager\skala_nilai_manager::cek_skala(
                            $cpmk->percentage,
                            'CPMK',
                            $prodi_id,
                            $id_tahun_akademik
                        );

                    $status_cpmk = self::cek_status_kompeten(
                        $grade_cpmk,
                        'CPMK',
                        $prodi_id,
                        $id_tahun_akademik
                    );

                    $rows[] = [
                        'level'  => 'CPMK',
                        'nama'   => $cpmk->nama_cpmk,
                        'nilai'  => round($cpmk->percentage, 2),
                        'grade'  => $grade_cpmk,
                        'status' => $status_cpmk ? 'Kompeten' : 'Belum Kompeten'
                    ];

                    // =====================================
                    // SUB CPMK
                    // =====================================
                    $subs = self::ambil_capaian_subcpmk_prodi(
                        $prodi_id,
                        $cpmk->cpmk_id,
                        $id_tahun_akademik,
                        $angkatan_id
                    );

                    foreach ($subs as $sub) {

                        $grade_sub = !empty($sub->grade)
                            ? $sub->grade
                            : \local_obe\manager\skala_nilai_manager::cek_skala(
                                $sub->percentage,
                                'SUB-CPMK',
                                $prodi_id,
                                $id_tahun_akademik
                            );

                        $status_sub = self::cek_status_kompeten(
                            $grade_sub,
                            'SUB-CPMK',
                            $prodi_id,
                            $id_tahun_akademik
                        );

                        $rows[] = [
                            'level'  => 'SUB-CPMK',
                            'nama'   => $sub->nama_subcpmk,
                            'nilai'  => round($sub->percentage, 2),
                            'grade'  => $grade_sub,
                            'status' => $status_sub ? 'Kompeten' : 'Belum Kompeten'
                        ];
                    }
                }
            }
        }

        return $rows;
    }
}