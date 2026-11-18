<?php

defined('MOODLE_INTERNAL') || die();
require_once(__DIR__.'/../fixtures/legacy_framework.php');

require_once(
    __DIR__ . '/../../classes/manager/capaian_pembelajaran_manager.php'
);

use local_obe\manager\capaian_pembelajaran_manager;

/**
 * Unit Test
 * Capaian Pembelajaran Manager
 */
class local_obe_capaian_pembelajaran_manager_test
    extends advanced_testcase {

    /**
     * Setup data lengkap
     */
    private function buat_data_lengkap() {
        $this->setAdminUser();

        global $DB;

        // =====================================================
        // PRODI
        // =====================================================

        $prodi = (object)[
            'kode_prodi'=>'TEST-'.uniqid(),
            'nama_prodi' => 'Teknik Informatika'
        ];

        $idprodi = $DB->insert_record(
            'local_obe_prodi',
            $prodi
        );

        // =====================================================
        // TAHUN AKADEMIK
        // =====================================================

        $tahun = (object)[
            'nama_tahun' => '2025/2026',
            'status_aktif' => 1
        ];

        $idtahun = $DB->insert_record(
            'local_obe_tahun_akademik',
            $tahun
        );

        // =====================================================
        // CPL
        // =====================================================

        $cpl = (object)[
            'id_prodi' => $idprodi,
            'frameworkid' => 1,
            'nama_kompetensi' => 'CPL 1',
            'tipe' => 'CPL',
            'parent_id' => 0,
            'ambang_batas' => 70,
            'id_tahun_akademik' => $idtahun
        ];

        $idcpl = $DB->insert_record(
            'local_obe_kompetensi',
            $cpl
        );

        // =====================================================
        // INDIKATOR
        // =====================================================

        $indikator = (object)[
            'id_prodi' => $idprodi,
            'frameworkid' => 1,
            'nama_kompetensi' => 'Indikator 1',
            'tipe' => 'INDIKATOR',
            'parent_id' => $idcpl,
            'ambang_batas' => 70,
            'id_tahun_akademik' => $idtahun
        ];

        $idindikator = $DB->insert_record(
            'local_obe_kompetensi',
            $indikator
        );

        // =====================================================
        // CPMK
        // =====================================================

        $cpmk = (object)[
            'id_prodi' => $idprodi,
            'frameworkid' => 1,
            'nama_kompetensi' => 'CPMK 1',
            'tipe' => 'CPMK',
            'parent_id' => $idindikator,
            'ambang_batas' => 70,
            'id_tahun_akademik' => $idtahun
        ];

        $idcpmk = $DB->insert_record(
            'local_obe_kompetensi',
            $cpmk
        );

        // =====================================================
        // SUB CPMK
        // =====================================================

        $sub = (object)[
            'id_prodi' => $idprodi,
            'frameworkid' => 1,
            'nama_kompetensi' => 'Sub CPMK 1',
            'tipe' => 'SUB-CPMK',
            'parent_id' => $idcpmk,
            'ambang_batas' => 70,
            'id_tahun_akademik' => $idtahun
        ];

        $idsub = $DB->insert_record(
            'local_obe_kompetensi',
            $sub
        );

        // =====================================================
        // BOBOT RELASI
        // =====================================================

        $DB->insert_record(
            'local_obe_bobot_relasi',
            (object)['tipe_asal'=>$DB->get_field('local_obe_kompetensi','tipe',['id'=>$idsub]),'tipe_tujuan'=>$DB->get_field('local_obe_kompetensi','tipe',['id'=>$idcpmk]),
                'id_kompetensi_asal' => $idsub,
                'id_kompetensi_tujuan' => $idcpmk,
                'nilai_bobot' => 100,
                'id_tahun_akademik' => $idtahun
            ]
        );

        $DB->insert_record(
            'local_obe_bobot_relasi',
            (object)['tipe_asal'=>$DB->get_field('local_obe_kompetensi','tipe',['id'=>$idcpmk]),'tipe_tujuan'=>$DB->get_field('local_obe_kompetensi','tipe',['id'=>$idindikator]),
                'id_kompetensi_asal' => $idcpmk,
                'id_kompetensi_tujuan' => $idindikator,
                'nilai_bobot' => 100,
                'id_tahun_akademik' => $idtahun
            ]
        );

        $DB->insert_record(
            'local_obe_bobot_relasi',
            (object)['tipe_asal'=>$DB->get_field('local_obe_kompetensi','tipe',['id'=>$idindikator]),'tipe_tujuan'=>$DB->get_field('local_obe_kompetensi','tipe',['id'=>$idcpl]),
                'id_kompetensi_asal' => $idindikator,
                'id_kompetensi_tujuan' => $idcpl,
                'nilai_bobot' => 100,
                'id_tahun_akademik' => $idtahun
            ]
        );

        // =====================================================
        // COURSE
        // =====================================================

        $generator = $this->getDataGenerator();

        $course = $generator->create_course([
            'fullname' => 'Course Testing',
            'shortname' => 'COURSE001'
        ]);

        // =====================================================
        // USER
        // =====================================================

        $student = $generator->create_user([
            'firstname' => 'Budi',
            'lastname' => 'Santoso'
        ]);

        $studentrole = $DB->get_record(
            'role',
            ['shortname' => 'student']
        );

        enrol_try_internal_enrol(
            $course->id,
            $student->id,
            $studentrole->id
        );

        // =====================================================
        // MATA KULIAH
        // =====================================================

        $mk = (object)[
            'id_prodi' => $idprodi,
            'kode_mk' => 'IF001',
            'nama_mk' => 'Pemrograman',
            'sks_mk' => 3
        ];

        $idmk = $DB->insert_record(
            'local_obe_mata_kuliah',
            $mk
        );

        // =====================================================
        // ANGKATAN
        // =====================================================

        $angkatan = (object)[
            'id_prodi' => $idprodi,
            'tahun_masuk' => 2025,
            'nama_angkatan' => '2025'
        ];

        $idangkatan = $DB->insert_record(
            'local_obe_angkatan',
            $angkatan
        );

        // =====================================================
        // RELASI KELAS
        // =====================================================

        $DB->insert_record(
            'local_obe_relasi_kelas',
            (object)[
                'id_mata_kuliah' => $idmk,
                'id_angkatan' => $idangkatan,
                'id_tahun_akademik' => $idtahun,
                'id_kursus_moodle' => $course->id,
                'waktu_koneksi' => time()
            ]
        );

        \local_obe_test_bind_framework($this->getDataGenerator(),$idprodi,$idtahun);
        return [
            'courseid' => $course->id,
            'userid' => $student->id,
            'prodiid' => $idprodi,
            'tahunid' => $idtahun,
            'cplid' => $idcpl,
            'indikatorid' => $idindikator,
            'cpmkid' => $idcpmk,
            'subid' => $idsub
        ];
    }

    // =====================================================
    // TEST HITUNG NILAI OBE
    // =====================================================

    public function test_hitung_nilai_obe_terbobot() {

        $this->resetAfterTest(true);

        $hasil =
            capaian_pembelajaran_manager
                ::hitung_nilai_obe_terbobot(
                    80,
                    100,
                    50,
                    50
                );

        $this->assertEquals(20, $hasil);
    }

    public function test_hitung_nilai_maksimal_100() {

        $this->resetAfterTest(true);

        $hasil =
            capaian_pembelajaran_manager
                ::hitung_nilai_obe_terbobot(
                    200,
                    100,
                    100,
                    100
                );

        $this->assertEquals(100, $hasil);
    }

    public function test_hitung_nilai_grademax_0() {

        $this->resetAfterTest(true);

        $hasil =
            capaian_pembelajaran_manager
                ::hitung_nilai_obe_terbobot(
                    50,
                    0,
                    100,
                    100
                );

        $this->assertEquals(50, $hasil);
    }

    // =====================================================
    // TEST DASBOR
    // =====================================================

    public function test_ambil_data_dasbor() {

        $this->resetAfterTest(true);

        $data = $this->buat_data_lengkap();

        $hasil =
            capaian_pembelajaran_manager
                ::ambil_data_dasbor(
                    $data['prodiid'],
                    0,
                    0,
                    $data['tahunid']
                );

        $this->assertNotEmpty($hasil);
    }

    // =====================================================
    // TEST RINCIAN CPMK
    // =====================================================

    public function test_ambil_rincian_cpmk_dan_kontribusi() {

        $this->resetAfterTest(true);

        $data = $this->buat_data_lengkap();

        $hasil =
            capaian_pembelajaran_manager
                ::ambil_rincian_cpmk_dan_kontribusi(
                    $data['courseid'],
                    $data['indikatorid'],
                    $data['tahunid']
                );

        $this->assertIsArray($hasil);

        $this->assertEquals(
            'CPMK 1',
            $hasil[0]->nama
        );
    }

    // =====================================================
    // TEST RINCIAN INDIKATOR
    // =====================================================

    public function test_ambil_rincian_indikator_dan_kontribusi() {

        $this->resetAfterTest(true);

        $data = $this->buat_data_lengkap();

        $hasil =
            capaian_pembelajaran_manager
                ::ambil_rincian_indikator_dan_kontribusi(
                    $data['courseid'],
                    $data['cplid'],
                    $data['tahunid']
                );

        $this->assertIsArray($hasil);

        $this->assertEquals(
            'Indikator 1',
            $hasil[0]->nama
        );
    }

    // =====================================================
    // TEST CPL PER MK
    // =====================================================

    public function test_ambil_capaian_cpl_per_mata_kuliah() {

        $this->resetAfterTest(true);

        $data = $this->buat_data_lengkap();

        $hasil =
            capaian_pembelajaran_manager
                ::ambil_capaian_cpl_per_mata_kuliah(
                    $data['courseid'],
                    $data['tahunid']
                );

        $this->assertIsArray($hasil);
    }

    // =====================================================
    // TEST CPL DENGAN RINCIAN
    // =====================================================

    public function test_ambil_capaian_cpl_dengan_rincian() {

        $this->resetAfterTest(true);

        $data = $this->buat_data_lengkap();

        $hasil =
            capaian_pembelajaran_manager
                ::ambil_capaian_cpl_dengan_rincian(
                    $data['courseid'],
                    $data['tahunid']
                );

        $this->assertIsArray($hasil);
    }

    // =====================================================
    // TEST CPL USER
    // =====================================================

    public function test_ambil_capaian_cpl() {

        $this->resetAfterTest(true);

        $data = $this->buat_data_lengkap();

        $hasil =
            capaian_pembelajaran_manager
                ::ambil_capaian_cpl(
                    $data['courseid'],
                    $data['userid'],
                    $data['tahunid']
                );

        $this->assertIsArray($hasil);
    }

    // =====================================================
    // TEST CPMK BERDASARKAN CPL
    // =====================================================

    public function test_ambil_capaian_cpmk_berdasarkan_cpl() {

        $this->resetAfterTest(true);

        $data = $this->buat_data_lengkap();

        $hasil =
            capaian_pembelajaran_manager
                ::ambil_capaian_cpmk_berdasarkan_cpl(
                    $data['courseid'],
                    $data['indikatorid'],
                    $data['userid'],
                    $data['tahunid']
                );

        $this->assertIsArray($hasil);
    }

    // =====================================================
    // TEST DETAIL MAHASISWA
    // =====================================================

    public function test_ambil_detail_data_mahasiswa_obe() {

        $this->resetAfterTest(true);

        $data = $this->buat_data_lengkap();

        $hasil =
            capaian_pembelajaran_manager
                ::ambil_detail_data_mahasiswa_obe(
                    $data['courseid'],
                    $data['subid'],
                    $data['cpmkid'],
                    $data['cplid'],
                    $data['tahunid']
                );

        $this->assertTrue(
            property_exists($hasil, 'sub_cpmk')
        );

        $this->assertTrue(
            property_exists($hasil, 'users')
        );
    }

    // =====================================================
    // TEST DETAIL NILAI MODUL
    // =====================================================

    public function test_ambil_detail_nilai_mahasiswa_berdasarkan_modul() {

        $this->resetAfterTest(true);

        global $DB, $CFG;

        $data = $this->buat_data_lengkap();

        require_once($CFG->dirroot.'/mod/assign/locallib.php');
        $activity=$this->getDataGenerator()->create_module('assign',['course'=>$data['courseid'],'name'=>'Tugas Testing','grade'=>100,'cmidnumber'=>'LEGACY-REPORT-ASSIGN','attemptreopenmethod'=>'manual','sendstudentnotifications'=>0]);
        $cmid=$activity->cmid;$cm=get_coursemodule_from_id('assign',$cmid,$data['courseid'],false,MUST_EXIST);
        $assignment=new \assign(\context_module::instance($cmid),$cm,get_course($data['courseid']));
        $this->assertTrue($assignment->save_grade($data['userid'],(object)['grade'=>85,'attemptnumber'=>-1,'addattempt'=>0,'applytoall'=>0,'sendstudentnotifications'=>0]));
        $DB->insert_record(
            'local_obe_aktivitas_map',
            (object)[
                'cmid'            => $cmid,
                'sub_cpmk_id'     => $data['subid'],
                'bobot_aktivitas' => 100
            ]
        );

        $hasil =
            capaian_pembelajaran_manager
                ::ambil_detail_nilai_mahasiswa_berdasarkan_modul(
                    $data['courseid'],
                    $cmid,
                    $data['tahunid']
                );

        $this->assertIsObject($hasil);

        $this->assertTrue(
            property_exists($hasil, 'users')
        );

        $this->assertTrue(
            property_exists($hasil, 'sub_cpmk')
        );

        $this->assertEquals(
            'Sub CPMK 1',
            $hasil->sub_cpmk->nama_kompetensi
        );
    }

    // =====================================================
    // TEST RINCIAN SUB CPMK
    // =====================================================

    public function test_ambil_rincian_sub_cpmk_dan_total() {

        $this->resetAfterTest(true);

        $data = $this->buat_data_lengkap();

        $hasil =
            capaian_pembelajaran_manager
                ::ambil_rincian_sub_cpmk_dan_total(
                    $data['courseid'],
                    $data['cpmkid'],
                    $data['tahunid']
                );

        $this->assertTrue(
            property_exists(
                $hasil,
                'total_cpmk_value'
            )
        );
    }

    // =====================================================
    // TEST LAPORAN AKHIR
    // =====================================================

    public function test_ambil_laporan_akhir_obe() {

        $this->resetAfterTest(true);

        $data = $this->buat_data_lengkap();

        $hasil =
            capaian_pembelajaran_manager
                ::ambil_laporan_akhir_obe(
                    $data['courseid'],
                    $data['tahunid']
                );

        $this->assertIsArray($hasil);
    }

    // =====================================================
    // TEST NILAI MAHASISWA MK
    // =====================================================

    public function test_ambil_nilai_mahasiswa_per_mata_kuliah() {

        $this->resetAfterTest(true);

        $data = $this->buat_data_lengkap();

        $hasil =
            capaian_pembelajaran_manager
                ::ambil_nilai_mahasiswa_per_mata_kuliah(
                    $data['courseid']
                );

        $this->assertIsArray($hasil);
    }

    // =====================================================
    // TEST RATA RATA INDIKATOR
    // =====================================================

    public function test_hitung_rata_rata_indikator() {

        $this->resetAfterTest(true);

        $data = $this->buat_data_lengkap();

        $hasil =
            capaian_pembelajaran_manager
                ::hitung_rata_rata_indikator(
                    $data['courseid'],
                    $data['indikatorid'],
                    $data['tahunid']
                );

        $this->assertIsNumeric($hasil);
    }
}