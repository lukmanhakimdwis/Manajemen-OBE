<?php
namespace local_obe;

defined('MOODLE_INTERNAL') || die();
require_once(__DIR__.'/../fixtures/legacy_framework.php');

global $CFG;
require_once($CFG->dirroot . '/local/obe/classes/manager/capaian_kelas_manager.php');
require_once($CFG->dirroot . '/local/obe/classes/manager/capaian_matakuliah_manager.php');
require_once($CFG->dirroot . '/local/obe/classes/manager/capaian_prodi_manager.php');

use advanced_testcase;
use stdClass;
use local_obe\manager\capaian_prodi_manager;

class capaian_prodi_manager_test extends advanced_testcase {

    /**
     * Helper untuk membuat struktur kurikulum OBE dan data dasar prodi.
     */
    private function buat_data_dasar_prodi() {
        global $DB;

        // 1. Setup Program Studi
        $prodi = new stdClass();
        $prodi->kode_prodi = 'TRPL';
        $prodi->nama_prodi = 'Teknologi Rekayasa Perangkat Lunak';
        $idprodi = $DB->insert_record('local_obe_prodi', $prodi);

        // 2. Setup Tahun Akademik
        $tahun = new stdClass();
        $tahun->nama_tahun = '2025/2026';
        $tahun->status_aktif = 1;
        $idtahun = $DB->insert_record('local_obe_tahun_akademik', $tahun);

        // 3. Setup Struktur Hierarki Kompetensi (CPL -> Indikator -> CPMK -> Sub-CPMK)
        $cpl = new stdClass();
        $cpl->id_prodi = $idprodi;
        $cpl->id_tahun_akademik = $idtahun;
        $cpl->nama_kompetensi = 'CPL-1';
        $cpl->tipe = 'CPL';
        $cpl->parent_id = 0;
        $idcpl = $DB->insert_record('local_obe_kompetensi', $cpl);

        $indikator = new stdClass();
        $indikator->id_prodi = $idprodi;
        $indikator->id_tahun_akademik = $idtahun;
        $indikator->nama_kompetensi = 'IK-1';
        $indikator->tipe = 'INDIKATOR';
        $indikator->parent_id = $idcpl;
        $idindikator = $DB->insert_record('local_obe_kompetensi', $indikator);

        $cpmk = new stdClass();
        $cpmk->id_prodi = $idprodi;
        $cpmk->id_tahun_akademik = $idtahun;
        $cpmk->nama_kompetensi = 'CPMK-1';
        $cpmk->tipe = 'CPMK';
        $cpmk->parent_id = $idindikator;
        $idcpmk = $DB->insert_record('local_obe_kompetensi', $cpmk);

        $subcpmk = new stdClass();
        $subcpmk->id_prodi = $idprodi;
        $subcpmk->id_tahun_akademik = $idtahun;
        $subcpmk->nama_kompetensi = 'SUB-1';
        $subcpmk->tipe = 'SUB-CPMK';
        $subcpmk->parent_id = $idcpmk;
        $idsubcpmk = $DB->insert_record('local_obe_kompetensi', $subcpmk);

        // Simpan bobot relasi default (100%)
        $this->simpan_bobot($idindikator, $idcpl, 100, $idtahun);
        $this->simpan_bobot($idcpmk, $idindikator, 100, $idtahun);
        $this->simpan_bobot($idsubcpmk, $idcpmk, 100, $idtahun);

        \local_obe_test_bind_framework($this->getDataGenerator(),$idprodi,$idtahun);
        return [
            'prodi'     => $idprodi,
            'tahun'     => $idtahun,
            'cpl'       => $idcpl,
            'indikator' => $idindikator,
            'cpmk'      => $idcpmk,
            'subcpmk'   => $idsubcpmk
        ];
    }

    private function simpan_bobot($asal, $tujuan, $nilai, $tahun) {
        global $DB;
        $bobot = new stdClass();
        $bobot->id_kompetensi_asal = $asal;
        $bobot->tipe_asal=$DB->get_field('local_obe_kompetensi','tipe',['id'=>$asal]);
        $bobot->tipe_tujuan=$DB->get_field('local_obe_kompetensi','tipe',['id'=>$tujuan]);
        $bobot->id_kompetensi_tujuan = $tujuan;
        $bobot->nilai_bobot = $nilai;
        $bobot->id_tahun_akademik = $tahun;
        $DB->insert_record('local_obe_bobot_relasi', $bobot);
    }

    /**
     * TC-PR-01: Menguji Skenario Batas (Edge Case) Jika Prodi Belum Memiliki Mata Kuliah
     */
    public function test_ambil_detail_kompetensi_mahasiswa_prodi_kosong() {
        $this->resetAfterTest(true);
        $this->setAdminUser();
        $base = $this->buat_data_dasar_prodi();

        // Panggil fungsi dengan ID Prodi yang belum memiliki mata kuliah pendukung
        $hasil = capaian_prodi_manager::ambil_detail_kompetensi_mahasiswa_prodi($base['prodi'], $base['cpl'], 'CPL', $base['tahun']);

        $this->assertNull($hasil->ringkasan);
        $this->assertEmpty($hasil->detail_mahasiswa);
        $this->assertEmpty($hasil->aktivitas_list);
    }

    /**
     * TC-PR-02: INTEGRASI MAKRO - Menguji Agregasi Nilai Lintas Mata Kuliah Tingkat Program Studi
     */
    public function test_ambil_statistik_skala_prodi_gabungan() {
        $this->resetAfterTest(true);
        $this->setAdminUser();
        global $DB;

        // Meredam peringatan deprecated dynamic properties core Moodle di PHP 8.2+
        $this->setOutputCallback(function() {});

        $base = $this->buat_data_dasar_prodi();

        // 1. Setup 2 Mata Kuliah Berbeda pada Prodi ini
        $mk1 = new stdClass();
        $mk1->id_prodi = $base['prodi'];
        $mk1->kode_mk = 'MK01';
        $mk1->nama_mk = 'Pemrograman';
        $mk1->sks_mk = 3;
        $idmk1 = $DB->insert_record('local_obe_mata_kuliah', $mk1);

        $mk2 = new stdClass();
        $mk2->id_prodi = $base['prodi'];
        $mk2->kode_mk = 'MK02';
        $mk2->nama_mk = 'Basis Data';
        $mk2->sks_mk = 3;
        $idmk2 = $DB->insert_record('local_obe_mata_kuliah', $mk2);

        // 2. Setup Kursus Moodle untuk masing-masing Mata Kuliah
        $course1 = $this->getDataGenerator()->create_course();
        $course2 = $this->getDataGenerator()->create_course();

        $relasi1 = new stdClass();
        $relasi1->id_mata_kuliah = $idmk1;
        $relasi1->id_kursus_moodle = $course1->id;
        $relasi1->id_angkatan = 2026;
        $relasi1->id_tahun_akademik = $base['tahun'];
        $DB->insert_record('local_obe_relasi_kelas', $relasi1);

        $relasi2 = new stdClass();
        $relasi2->id_mata_kuliah = $idmk2;
        $relasi2->id_kursus_moodle = $course2->id;
        $relasi2->id_angkatan = 2026;
        $relasi2->id_tahun_akademik = $base['tahun'];
        $DB->insert_record('local_obe_relasi_kelas', $relasi2);

        // 3. Buat 1 Mahasiswa yang mengambil KEDUA Mata Kuliah tersebut
        $student = $this->getDataGenerator()->create_user(['username' => 'mhs_prodi', 'firstname' => 'Eka']);
        $this->getDataGenerator()->enrol_user($student->id, $course1->id, 'student');
        $this->getDataGenerator()->enrol_user($student->id, $course2->id, 'student');

        // 4. Setup Pemetaan Aktivitas dan Skala Nilai (A = 75-100)
        $assign1 = $this->getDataGenerator()->create_module('assign', ['course' => $course1->id, 'grade' => 100]);
        $assign2 = $this->getDataGenerator()->create_module('assign', ['course' => $course2->id, 'grade' => 100]);

        $map1 = new stdClass();
        $map1->sub_cpmk_id = $base['subcpmk'];
        $map1->cmid = $assign1->cmid;
        $map1->bobot_aktivitas = 100;
        $DB->insert_record('local_obe_aktivitas_map', $map1);

        $map2 = new stdClass();
        $map2->sub_cpmk_id = $base['subcpmk'];
        $map2->cmid = $assign2->cmid;
        $map2->bobot_aktivitas = 100;
        $DB->insert_record('local_obe_aktivitas_map', $map2);

        $skala = new stdClass();
        $skala->id_prodi = $base['prodi'];
        $skala->id_tahun_akademik = $base['tahun'];
        $skala->tipe_kompetensi = 'CPL';
        $skala->huruf_nilai = 'A';
        $skala->nilai_min = 75.00;
        $skala->nilai_max = 100.00;
        $skala->status_kompeten = 1;
        $DB->insert_record('local_obe_skala_nilai', $skala);

        // 5. Berikan Nilai Berbeda di Masing-masing Mata Kuliah untuk Mahasiswa Terkait
        // Pada MK1 mahasiswa mendapat nilai 90
        $grade1 = \grade_item::fetch(['courseid' => $course1->id, 'itemtype' => 'mod', 'itemmodule' => 'assign', 'iteminstance' => $assign1->id]);
        $this->assertNotFalse($grade1);
        $this->assertNotFalse($grade1->update_final_grade($student->id, 90.00));
        $this->assertEquals(90, \grade_grade::fetch(['itemid'=>$grade1->id,'userid'=>$student->id])->finalgrade);

        // Pada MK2 mahasiswa mendapat nilai 70
        $grade2 = \grade_item::fetch(['courseid' => $course2->id, 'itemtype' => 'mod', 'itemmodule' => 'assign', 'iteminstance' => $assign2->id]);
        $this->assertNotFalse($grade2);
        $this->assertNotFalse($grade2->update_final_grade($student->id, 70.00));
        $this->assertEquals(70, \grade_grade::fetch(['itemid'=>$grade2->id,'userid'=>$student->id])->finalgrade);

        // 6. Jalankan Pengujian Ambil Statistik Skala Tingkat Program Studi
        // Logika internal: Rata-rata dari (90 + 70) / 2 = 80
        $statistik = capaian_prodi_manager::ambil_statistik_skala_prodi(
            $base['prodi'],
            $base['cpl'],
            'CPL',
            $base['tahun'],
            2026
        );

        // 7. Assertions Hasil Perhitungan Lintas Mata Kuliah
        $this->assertEquals(80, $statistik->rata_rata); // Nilai rata-rata Prodi harus 80
        $this->assertEquals(1, $statistik->total_mahasiswa);
        $this->assertEquals('A', $statistik->grade); // Nilai 80 terkonversi menjadi predikat 'A'
        $this->assertEquals(1, $statistik->jumlah_kompeten);
    }

    /**
     * TC-PR-03: Menguji Penarikan Data Penelusuran Massal untuk Ekspor Level Program Studi
     */
    public function test_ambil_data_export_prodi() {
        $this->resetAfterTest(true);
        $this->setAdminUser();
        $this->setOutputCallback(function() {});
        
        global $DB;
        $base = $this->buat_data_dasar_prodi();
        
        $mk = new stdClass();
        $mk->id_prodi = $base['prodi'];
        $mk->kode_mk = 'MK03';
        $mk->nama_mk = 'Jaringan';
        $mk->sks_mk = 2;
        $idmk = $DB->insert_record('local_obe_mata_kuliah', $mk);

        $course = $this->getDataGenerator()->create_course();
        $relasi = new stdClass();
        $relasi->id_mata_kuliah = $idmk;
        $relasi->id_kursus_moodle = $course->id;
        $relasi->id_angkatan = 2026;
        $relasi->id_tahun_akademik = $base['tahun'];
        $DB->insert_record('local_obe_relasi_kelas', $relasi);

        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user->id, $course->id);

        // Uji ekspor data tingkat prodi
        $export_data = capaian_prodi_manager::ambil_data_export_prodi($base['prodi'], $base['tahun'], 2026);
        
        $this->assertIsArray($export_data);
        if (!empty($export_data)) {
            $this->assertTrue(property_exists($export_data[0], 'kompetensi'), 'Struktur ekspor prodi kehilangan objek kompetensi');
            $this->assertTrue(property_exists($export_data[0], 'ringkasan'), 'Struktur ekspor prodi kehilangan objek ringkasan');
        }
    }
}