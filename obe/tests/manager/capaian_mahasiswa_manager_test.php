<?php
namespace local_obe;

defined('MOODLE_INTERNAL') || die();
require_once(__DIR__.'/../fixtures/legacy_framework.php');

global $CFG;
require_once($CFG->dirroot . '/local/obe/classes/manager/capaian_kelas_manager.php');
require_once($CFG->dirroot . '/local/obe/classes/manager/capaian_matakuliah_manager.php');
require_once($CFG->dirroot . '/local/obe/classes/manager/capaian_mahasiswa_manager.php');

use advanced_testcase;
use stdClass;
use local_obe\manager\capaian_mahasiswa_manager;

class capaian_mahasiswa_manager_test extends advanced_testcase {

    /**
     * Helper untuk mempersiapkan data kurikulum dasar.
     */
    private function buat_data_kurikulum() {
        global $DB;

        // 1. Prodi & Tahun Akademik
        $prodi = new stdClass();
        $prodi->kode_prodi = 'TRPL';
        $prodi->nama_prodi = 'Teknologi Rekayasa Perangkat Lunak';
        $idprodi = $DB->insert_record('local_obe_prodi', $prodi);

        $tahun = new stdClass();
        $tahun->nama_tahun = '2025/2026';
        $tahun->status_aktif = 1;
        $idtahun = $DB->insert_record('local_obe_tahun_akademik', $tahun);

        // 2. Hierarki Kompetensi (CPL -> Indikator -> CPMK -> Sub-CPMK)
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

        // Sambungkan Relasi Bobot 100%
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
     * Helper untuk memasukkan data ambang batas kompeten agar tidak memicu peringatan debugging()
     */
    private function buat_ambang_batas($prodi, $tahun, $tipe) {
        global $DB;
        $skala = new stdClass();
        $skala->id_prodi = $prodi;
        $skala->id_tahun_akademik = $tahun;
        $skala->tipe_kompetensi = $tipe;
        $skala->huruf_nilai = 'B';
        $skala->nilai_min = 70.00;
        $skala->nilai_max = 85.00;
        $skala->status_kompeten = 1;
        $DB->insert_record('local_obe_skala_nilai', $skala);
    }

    /**
     * TC-MS-01: Menguji Kasus Batas (Edge Case) Mahasiswa yang Belum Memiliki Riwayat Nilai
     */
    public function test_ambil_trace_mahasiswa_kosong() {
        $this->resetAfterTest(true);
        $this->setAdminUser();
        $base = $this->buat_data_kurikulum();

        // Masukkan ambang batas kompeten untuk semua tipe agar fungsi internal tidak memicu debugging()
        $this->buat_ambang_batas($base['prodi'], $base['tahun'], 'SUB-CPMK');
        $this->buat_ambang_batas($base['prodi'], $base['tahun'], 'CPMK');
        $this->buat_ambang_batas($base['prodi'], $base['tahun'], 'INDIKATOR');
        $this->buat_ambang_batas($base['prodi'], $base['tahun'], 'CPL');

        // Panggil fungsi pencarian jejak (trace) untuk User ID fiktif
        $hasil = capaian_mahasiswa_manager::ambil_trace_cpl_mahasiswa(999, $base['prodi'], $base['tahun']);

        // PERBAIKAN WHITE-BOX: Karena data CPL ada di database prodi, fungsi Anda mengembalikan struktur hierarki objek dengan nilai 0.
        // Kita periksa bahwa isi penelusurannya menghasilkan nilai rata-rata 0 (aman dari pembagian nol).
        $this->assertNotEmpty($hasil);
        $this->assertEquals(0, $hasil[0]->nilai);
        $this->assertEmpty($hasil[0]->matakuliah_pendukung);
    }

    /**
     * TC-MS-02: INTEGRASI INDIVIDUAL - Menguji Pelacakan Trace CPL Mahasiswa Berdasarkan Nilai Riil
     */
    public function test_ambil_detail_dan_trace_cpl_mahasiswa() {
        $this->resetAfterTest(true);
        $this->setAdminUser();
        global $DB;

        // Meredam output deprecated warning internal Moodle pada PHP 8.2+
        $this->setOutputCallback(function() {});

        $base = $this->buat_data_kurikulum();

        // Setup semua kriteria ambang batas kelulusan kurikulum
        $this->buat_ambang_batas($base['prodi'], $base['tahun'], 'SUB-CPMK');
        $this->buat_ambang_batas($base['prodi'], $base['tahun'], 'CPMK');
        $this->buat_ambang_batas($base['prodi'], $base['tahun'], 'INDIKATOR');
        $this->buat_ambang_batas($base['prodi'], $base['tahun'], 'CPL');

        // 1. Buat Mata Kuliah Pendukung
        $mk = new stdClass();
        $mk->id_prodi = $base['prodi'];
        $mk->kode_mk = 'TRPL01';
        $mk->nama_mk = 'Pemrograman Web';
        $mk->sks_mk = 3;
        $idmk = $DB->insert_record('local_obe_mata_kuliah', $mk);

        // 2. Buat Kursus Riil & Daftarkan Mahasiswa
        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_user(['username' => 'mhs_single', 'firstname' => 'Rian']);
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');

        // 3. Hubungkan ke Relasi Kelas
        $relasi = new stdClass();
        $relasi->id_mata_kuliah = $idmk;
        $relasi->id_kursus_moodle = $course->id;
        $relasi->id_angkatan = 2026;
        $relasi->id_tahun_akademik = $base['tahun'];
        $DB->insert_record('local_obe_relasi_kelas', $relasi);

        // 4. Map Aktivitas ke Sub-CPMK
        $assign = $this->getDataGenerator()->create_module('assign', ['course' => $course->id, 'grade' => 100]);
        $map = new stdClass();
        $map->sub_cpmk_id = $base['subcpmk'];
        $map->cmid = $assign->cmid;
        $map->bobot_aktivitas = 100;
        $DB->insert_record('local_obe_aktivitas_map', $map);

        // 5. Masukkan Nilai Riil (80.00) ke Gradebook
        $grade = \grade_item::fetch(['courseid' => $course->id, 'itemtype' => 'mod', 'itemmodule' => 'assign', 'iteminstance' => $assign->id]);
        $this->assertNotFalse($grade);
        $this->assertNotFalse($grade->update_final_grade($student->id, 80.00));
        $this->assertEquals(80, \grade_grade::fetch(['itemid'=>$grade->id,'userid'=>$student->id])->finalgrade);

        // 6. Eksekusi Pencarian Trace Profil Kompetensi Mahasiswa Terkait
        $trace = capaian_mahasiswa_manager::ambil_trace_cpl_mahasiswa($student->id, $base['prodi'], $base['tahun']);

        // 7. Assertions Skenario Jalur Uji Individual
        $this->assertNotEmpty($trace);
        $cpl_trace = $trace[0];

        $this->assertEquals($base['cpl'], $cpl_trace->id);
        $this->assertEquals(80, $cpl_trace->nilai); // Nilai rata-rata CPL harus terhitung 80
        $this->assertEquals('B', $cpl_trace->grade); // Sesuai acuan skala, angka 80 terkonversi menjadi predikat 'B'
        $this->assertNotEmpty($cpl_trace->matakuliah_pendukung);
        $this->assertEquals('TRPL01', $cpl_trace->matakuliah_pendukung[0]->kode_mk);
    }
}
