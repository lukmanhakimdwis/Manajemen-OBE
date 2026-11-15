<?php
namespace local_obe;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/local/obe/classes/manager/capaian_kelas_manager.php');
require_once($CFG->dirroot . '/local/obe/classes/manager/capaian_matakuliah_manager.php');

use advanced_testcase;
use stdClass;
use local_obe\manager\capaian_matakuliah_manager;

class capaian_matakuliah_manager_test extends advanced_testcase {

    /**
     * Helper untuk membuat data dasar program studi, tahun akademik, dan mata kuliah.
     */
    private function buat_data_dasar() {
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

        // 3. Setup Mata Kuliah
        $mk = new stdClass();
        $mk->id_prodi = $idprodi;
        $mk->kode_mk = 'MK003';
        $mk->nama_mk = 'Basis Data';
        $mk->sks_mk = 3;
        $idmk = $DB->insert_record('local_obe_mata_kuliah', $mk);

        // 4. Setup Struktur Hierarki Kompetensi (CPL -> Indikator -> CPMK -> Sub-CPMK)
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
        $this->simpan_bobot($idcpl, $idindikator, 100, $idtahun);
        $this->simpan_bobot($idindikator, $idcpmk, 100, $idtahun);
        $this->simpan_bobot($idcpmk, $idsubcpmk, 100, $idtahun);

        return [
            'prodi'     => $idprodi,
            'tahun'     => $idtahun,
            'matkul'    => $idmk,
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
        $bobot->id_kompetensi_tujuan = $tujuan;
        $bobot->nilai_bobot = $nilai;
        $bobot->id_tahun_akademik = $tahun;
        $DB->insert_record('local_obe_bobot_relasi', $bobot);
    }

    /**
     * TC-MK-01: Menguji Skenario Batas (Edge Case) Jika Mata Kuliah Tidak Memiliki Kelas Paralel
     */
    public function test_ambil_detail_kompetensi_mahasiswa_matakuliah_kosong() {
        $this->resetAfterTest(true);
        $this->setAdminUser();
        $base = $this->buat_data_dasar();

        // Cari detail untuk Mata Kuliah ID yang tidak memiliki data kelas di tabel relasi kelas
        $hasil = capaian_matakuliah_manager::ambil_detail_kompetensi_mahasiswa_matakuliah($base['matkul'], $base['cpl'], 'CPL', $base['tahun']);

        $this->assertNull($hasil->cpl_record);
        $this->assertEmpty($hasil->detail_mahasiswa);
        $this->assertNull($hasil->ringkasan);
    }

    /**
     * TC-MK-02: INTEGRASI GABUNGAN - Menguji Penggabungan Data Kelas Paralel dan Statistik Skala Nilai
     */
    public function test_ambil_statistik_skala_matakuliah_paralel() {
        $this->resetAfterTest(true);
        $this->setAdminUser();
        global $DB;

        // Meredam output deprecated akibat interaksi internal library Moodle (PHP 8.2+)
        $this->setOutputCallback(function() {});

        $base = $this->buat_data_dasar();

        // 1. Setup 2 Kursus Riil (Kelas Paralel: Kelas A dan Kelas B)
        $courseA = $this->getDataGenerator()->create_course(['fullname' => 'Kelas A', 'shortname' => 'KLA']);
        $courseB = $this->getDataGenerator()->create_course(['fullname' => 'Kelas B', 'shortname' => 'KLB']);

        // 2. Hubungkan kedua kelas ke satu Mata Kuliah yang sama
        $relasiA = new stdClass();
        $relasiA->id_mata_kuliah = $base['matkul'];
        $relasiA->id_kursus_moodle = $courseA->id;
        $relasiA->id_angkatan = 2026;
        $relasiA->id_tahun_akademik = $base['tahun'];
        $DB->insert_record('local_obe_relasi_kelas', $relasiA);

        $relasiB = new stdClass();
        $relasiB->id_mata_kuliah = $base['matkul'];
        $relasiB->id_kursus_moodle = $courseB->id;
        $relasiB->id_angkatan = 2026;
        $relasiB->id_tahun_akademik = $base['tahun'];
        $DB->insert_record('local_obe_relasi_kelas', $relasiB);

        // 3. Daftarkan Mahasiswa ke Masing-masing Kelas
        $mhs1 = $this->getDataGenerator()->create_user(['username' => 'mhs01', 'firstname' => 'Adi']);
        $this->getDataGenerator()->enrol_user($mhs1->id, $courseA->id);

        $mhs2 = $this->getDataGenerator()->create_user(['username' => 'mhs02', 'firstname' => 'Budi']);
        $this->getDataGenerator()->enrol_user($mhs2->id, $courseB->id);

        // 4. Setup Pemetaan Aktivitas untuk Sub-CPMK di Kedua Kelas
        $assignA = $this->getDataGenerator()->create_module('assign', ['course' => $courseA->id]);
        $mapA = new stdClass();
        $mapA->sub_cpmk_id = $base['subcpmk'];
        $mapA->cmid = $assignA->cmid;
        $mapA->bobot_aktivitas = 100;
        $DB->insert_record('local_obe_aktivitas_map', $mapA);

        $assignB = $this->getDataGenerator()->create_module('assign', ['course' => $courseB->id]);
        $mapB = new stdClass();
        $mapB->sub_cpmk_id = $base['subcpmk'];
        $mapB->cmid = $assignB->cmid;
        $mapB->bobot_aktivitas = 100;
        $DB->insert_record('local_obe_aktivitas_map', $mapB);

        // 5. Setup Skala Nilai (A = 75-100, Kompeten)
        $skala = new stdClass();
        $skala->id_prodi = $base['prodi'];
        $skala->id_tahun_akademik = $base['tahun'];
        $skala->tipe_kompetensi = 'SUB-CPMK';
        $skala->huruf_nilai = 'A';
        $skala->nilai_min = 75.00;
        $skala->nilai_max = 100.00;
        $skala->status_kompeten = 1;
        $DB->insert_record('local_obe_skala_nilai', $skala);

        // 6. Isi Nilai Berbeda untuk Kedua Mahasiswa di Gradebook
        $gradeA = \grade_item::fetch(['courseid' => $courseA->id, 'itemtype' => 'mod', 'itemmodule' => 'assign', 'iteminstance' => $assignA->id]);
        $gradeA->update_final_grade($mhs1->id, 90.00); // Mhs 1 dapat 90 di Kelas A

        $gradeB = \grade_item::fetch(['courseid' => $courseB->id, 'itemtype' => 'mod', 'itemmodule' => 'assign', 'iteminstance' => $assignB->id]);
        $gradeB->update_final_grade($mhs2->id, 80.00); // Mhs 2 dapat 80 di Kelas B

        // 7. Uji Fungsi Statistik Tingkat Mata Kuliah (Menggabungkan A & B)
        $statistik = capaian_matakuliah_manager::ambil_statistik_skala_matakuliah(
            $base['matkul'],
            $base['subcpmk'],
            'SUB-CPMK',
            $base['tahun'],
            2026
        );

        // 8. Assertions Hasil Gabungan (Rata-rata dari 90 dan 80 adalah 85)
        $this->assertEquals(85, $statistik->rata_rata);
        $this->assertEquals(2, $statistik->total_mahasiswa);
        $this->assertEquals(2, $statistik->jumlah_kompeten);
        $this->assertEquals(100, $statistik->persentase_kompeten);
        
        // Cek struktur array distribusi grade
        $this->assertNotEmpty($statistik->distribusi_grade);
        $this->assertEquals('A', $statistik->distribusi_grade[0]->huruf);
        $this->assertEquals(2, $statistik->distribusi_grade[0]->jumlah);
    }

    /**
     * TC-MK-03: Menguji Penarikan Hierarki dan Struktur Capaian Massal untuk Ekspor Mata Kuliah
     */
    public function test_ambil_data_export_matakuliah() {
        $this->resetAfterTest(true);
        $this->setAdminUser();
        $this->setOutputCallback(function() {});
        
        global $DB;
        $base = $this->buat_data_dasar();
        $course = $this->getDataGenerator()->create_course(['fullname' => 'Kelas DB']);

        $relasi = new stdClass();
        $relasi->id_mata_kuliah = $base['matkul'];
        $relasi->id_kursus_moodle = $course->id;
        $relasi->id_angkatan = 2026;
        $relasi->id_tahun_akademik = $base['tahun'];
        $DB->insert_record('local_obe_relasi_kelas', $relasi);

        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user->id, $course->id);

        // Uji ekspor massal tingkat mata kuliah
        $export_data = capaian_matakuliah_manager::ambil_data_export_matakuliah($base['matkul'], $base['tahun'], 2026);
        
        $this->assertIsArray($export_data);
        if (!empty($export_data)) {
            $this->assertTrue(property_exists($export_data[0], 'indikator_list'), 'Struktur ekspor matakuliah kehilangan list indikator');
            $this->assertTrue(property_exists($export_data[0], 'ringkasan'), 'Struktur ekspor matakuliah tidak memiliki properti ringkasan');
        }
    }
}