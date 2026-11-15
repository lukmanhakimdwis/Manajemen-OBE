<?php
namespace local_obe;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/local/obe/classes/manager/capaian_kelas_manager.php');

use advanced_testcase;
use stdClass;
use local_obe\manager\capaian_kelas_manager;

class capaian_kelas_manager_test extends advanced_testcase {

    /**
     * Membuat data struktur dasar kurikulum OBE untuk keperluan pengujian.
     */
    private function buat_struktur_dasar() {
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

        // 4. Setup Bobot Relasi Antar Hierarki
        $this->simpan_bobot($idcpl, $idindikator, 100, $idtahun);
        $this->simpan_bobot($idindikator, $idcpmk, 100, $idtahun);
        $this->simpan_bobot($idcpmk, $idsubcpmk, 100, $idtahun);

        return [
            'prodi'     => $idprodi,
            'tahun'     => $idtahun,
            'cpl'       => $idcpl,
            'indikator' => $idindikator,
            'cpmk'      => $idcpmk,
            'subcpmk'   => $idsubcpmk
        ];
    }

    /**
     * Helper untuk menyimpan record bobot relasi.
     */
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
     * TC-CK-01: Menguji Filter Fleksibel pada Pengambilan Daftar Kelas
     */
    public function test_ambil_daftar_kelas_dengan_filter() {
        $this->resetAfterTest(true);
        $this->setAdminUser();
        global $DB;

        $base = $this->buat_struktur_dasar();

        $mk = new stdClass();
        $mk->id_prodi = $base['prodi'];
        $mk->kode_mk = 'MK001';
        $mk->nama_mk = 'Algoritma';
        $mk->sks_mk = 3; 
        $idmk = $DB->insert_record('local_obe_mata_kuliah', $mk);

        $relasi = new stdClass();
        $relasi->id_mata_kuliah = $idmk;
        $relasi->id_kursus_moodle = 101;
        $relasi->id_angkatan = 5;
        $relasi->id_tahun_akademik = $base['tahun'];
        $DB->insert_record('local_obe_relasi_kelas', $relasi);

        $hasil = capaian_kelas_manager::ambil_daftar_kelas($base['prodi'], $idmk, 5, $base['tahun']);
        $this->assertCount(1, $hasil);
        $kelas = reset($hasil);
        $this->assertEquals('Algoritma', $kelas->nama_mk);

        $hasil_kosong = capaian_kelas_manager::ambil_daftar_kelas($base['prodi'], $idmk, 999, $base['tahun']);
        $this->assertEmpty($hasil_kosong);
    }

    /**
     * TC-CK-02: Menguji Kemampuan Pengambilan Hierarki Kelas Terstruktur
     */
    public function test_ambil_hierarki_kelas() {
        $this->resetAfterTest(true);
        $this->setAdminUser();
        $base = $this->buat_struktur_dasar();

        global $DB;
        $mk=$DB->insert_record('local_obe_mata_kuliah',(object)['id_prodi'=>$base['prodi'],'kode_mk'=>'TEST','nama_mk'=>'Test','sks_mk'=>3]);
        $DB->insert_record('local_obe_relasi_kelas',(object)['id_mata_kuliah'=>$mk,'id_kursus_moodle'=>101,'id_angkatan'=>1,'id_tahun_akademik'=>$base['tahun']]);
        $hierarki = capaian_kelas_manager::ambil_hierarki_kelas(101, $base['tahun']);
        
        $this->assertNotEmpty($hierarki);
        $cpl_teruji = $hierarki[0];
        $this->assertEquals('CPL-1', $cpl_teruji->nama);
        $this->assertEquals('IK-1', $cpl_teruji->indikator_list[0]->nama);
        $this->assertEquals('SUB-1', $cpl_teruji->indikator_list[0]->cpmk_list[0]->subcpmk_list[0]->nama);
    }

    /**
     * TC-CK-03: Skenario Batas (Edge Case) Jika Kelas Kosong Tanpa Mahasiswa
     */
    public function test_ambil_detail_kompetensi_mahasiswa_kosong() {
        $this->resetAfterTest(true);
        $this->setAdminUser();
        global $DB;
        $base = $this->buat_struktur_dasar();

        // PERBAIKAN: Gunakan course riil yang valid agar context_course::instance() tidak melempar exception dml_missing
        $course = $this->getDataGenerator()->create_course();

        // Kaitkan relasi kelas prodi ke course kosong ini
        $relasi = new stdClass();
        $relasi->id_mata_kuliah = 1;
        $relasi->id_kursus_moodle = $course->id;
        $relasi->id_angkatan = 2026;
        $relasi->id_tahun_akademik = $base['tahun'];
        $DB->insert_record('local_obe_relasi_kelas', $relasi);

        // Eksekusi pemanggilan manager menggunakan Course ID riil yang kosong dari mahasiswa
        $hasil = capaian_kelas_manager::ambil_detail_kompetensi_mahasiswa($course->id, $base['cpl'], 'CPL', $base['tahun']);

        $this->assertNull($hasil->cpl_record);
        $this->assertEmpty($hasil->detail_mahasiswa);
        $this->assertEquals(0, $hasil->ringkasan->jumlah_mahasiswa);
        $this->assertEquals(0, $hasil->ringkasan->rata_rata);
    }

    /**
     * TC-CK-04: INTEGRASI PENUH - Menguji Alur Nilai Nyata dari Gradebook Moodle ke SUB-CPMK
     */
    public function test_kalkulasi_nilai_real_subcpmk() {
        $this->resetAfterTest(true);
        $this->setAdminUser();
        global $DB;

        // PERBAIKAN RISKY TEST: Beritahu PHPUnit untuk mengabaikan output Deprecated dari PHP 8.2 bawaan core Moodle
        $this->setOutputCallback(function() {});

        $base = $this->buat_struktur_dasar();
        $course = $this->getDataGenerator()->create_course();
        
        $mk = new stdClass();
        $mk->id_prodi = $base['prodi'];
        $mk->kode_mk = 'MK002';
        $mk->nama_mk = 'Struktur Data';
        $mk->sks_mk = 4; 
        $idmk = $DB->insert_record('local_obe_mata_kuliah', $mk);

        $relasi = new stdClass();
        $relasi->id_mata_kuliah = $idmk;
        $relasi->id_kursus_moodle = $course->id;
        $relasi->id_angkatan = 2026;
        $relasi->id_tahun_akademik = $base['tahun'];
        $DB->insert_record('local_obe_relasi_kelas', $relasi);

        $student = $this->getDataGenerator()->create_user(['username' => 'mhs01', 'firstname' => 'Adi']);
        $this->getDataGenerator()->enrol_user($student->id, $course->id);

        $assign = $this->getDataGenerator()->create_module('assign', ['course' => $course->id, 'name' => 'Tugas 1']);

        $map = new stdClass();
        $map->sub_cpmk_id = $base['subcpmk'];
        $map->cmid = $assign->cmid;
        $map->bobot_aktivitas = 100; 
        $DB->insert_record('local_obe_aktivitas_map', $map);

        $skala = new stdClass();
        $skala->id_prodi = $base['prodi'];
        $skala->id_tahun_akademik = $base['tahun'];
        $skala->tipe_kompetensi = 'SUB-CPMK';
        $skala->huruf_nilai = 'A';
        $skala->nilai_min = 75.00;
        $skala->nilai_max = 100.00;
        $skala->status_kompeten = 1;
        $DB->insert_record('local_obe_skala_nilai', $skala);

        $gradebackend = \grade_item::fetch([
            'courseid' => $course->id, 
            'itemtype' => 'mod', 
            'itemmodule' => 'assign', 
            'iteminstance' => $assign->id
        ]);
        
        if ($gradebackend) {
            $gradebackend->update_final_grade($student->id, 80.00); 
        }

        $hasil = capaian_kelas_manager::ambil_detail_kompetensi_mahasiswa($course->id, $base['subcpmk'], 'SUB-CPMK', $base['tahun']);

        $this->assertNotEmpty($hasil->detail_mahasiswa);
        $mhs_hasil = $hasil->detail_mahasiswa[0];
        
        $this->assertEquals(80, $mhs_hasil->nilai); 
        $this->assertEquals('A', $mhs_hasil->grade); 
        $this->assertTrue($mhs_hasil->kompeten); 
        
        $this->assertEquals(80, $hasil->ringkasan->rata_rata);
        $this->assertEquals(1, $hasil->ringkasan->jumlah_kompeten);
        $this->assertEquals(100, $hasil->ringkasan->persentase_kompeten);
    }

    /**
     * TC-CK-05: Menguji Alur Output Keseluruhan Data untuk Ekspor
     */
    public function test_ambil_data_export_kelas() {
        $this->resetAfterTest(true);
        $this->setAdminUser();
        
        // PERBAIKAN RISKY TEST: Abaikan output Deprecated bertingkat
        $this->setOutputCallback(function() {});

        $base = $this->buat_structure_dan_enrol_tiruan(); 

        $export_data = capaian_kelas_manager::ambil_data_export_kelas($base['courseid'], $base['tahun']);
        
        $this->assertIsArray($export_data);
        if (!empty($export_data)) {
            $this->assertTrue(property_exists($export_data[0], 'indikator_list'), 'Objek tidak memiliki properti indikator_list');
            $this->assertTrue(property_exists($export_data[0], 'detail'), 'Objek tidak memiliki properti detail');
        }
    }

    /**
     * Helper tambahan untuk meniru enrollment cepat tanpa Gradebook penuh
     */
    private function buat_structure_dan_enrol_tiruan() {
        global $DB;
        $base = $this->buat_struktur_dasar();
        $course = $this->getDataGenerator()->create_course();
        
        $relasi = new stdClass();
        $relasi->id_mata_kuliah = 1;
        $relasi->id_kursus_moodle = $course->id;
        $relasi->id_angkatan = 2026;
        $relasi->id_tahun_akademik = $base['tahun'];
        $DB->insert_record('local_obe_relasi_kelas', $relasi);

        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user->id, $course->id);

        return [
            'courseid' => $course->id,
            'tahun'    => $base['tahun']
        ];
    }
}