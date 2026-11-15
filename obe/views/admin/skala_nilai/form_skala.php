<?php
// File: local/obe/views/admin/skala_nilai/form_skala.php
defined('MOODLE_INTERNAL') || die();

use local_obe\manager\skala_nilai_manager;
use local_obe\manager\prodi_manager;

global $SESSION;

// 1. TANGKAP PARAMETER DENGAN TAMBAHAN KONTEKS TIPE
$id    = optional_param('id', 0, PARAM_INT);
$prodi = optional_param('prodi', 0, PARAM_INT);
$tipe  = optional_param('tipe', '', PARAM_TEXT); // Menangkap tipe kompetensi dari tombol list
$tahun = $SESSION->obe_id_tahun_akademik ?? 0;

// Identifikasi peran pengguna aktif
$prodi_aktif = prodi_manager::get_prodi_aktif_user();

$data = new stdClass();
$data->id = 0;
$data->id_prodi = $prodi;
$data->tipe_kompetensi = '';
$data->huruf_nilai = '';
$data->nilai_min = null;
$data->nilai_max = null;
$data->status_kompeten = 0;

// 2. MODE EDIT: AMBIL DATA & VALIDASI BACK-END (ANTI-IDOR)
if ($id > 0) {
    $ambil_data = skala_nilai_manager::ambil($id);
    if ($ambil_data) {
        $data = $ambil_data;
        
        // Proteksi IDOR: Pastikan Kaprodi tidak bisa mengintip/mengubah skala nilai milik prodi lain
        if ($prodi_aktif && (int)$data->id_prodi !== (int)$prodi_aktif->id) {
            throw new \moodle_exception(
                'aksesditolak',
                'local_obe',
                '',
                null,
                'Gagal memuat form! Anda tidak memiliki hak akses untuk mengubah skala nilai program studi lain.'
            );
        }
    }
}

// 3. MODE TAMBAH BARU: AUTO-SET CONTEXT TIPE KOMPETENSI
if (!$id && !empty($tipe)) {
    $data->tipe_kompetensi = strtoupper($tipe);
}

// Paksa isolasi parameter prodi jika yang login adalah Kaprodi
if ($prodi_aktif) {
    $prodi = (int)$prodi_aktif->id;
}

$action_url = new moodle_url('/local/obe/views/admin/skala_nilai/proses_skala.php');
$kembali_url = new moodle_url('/local/obe/views/admin/dashboard.php', [
    'halaman' => 'skala_nilai',
    'prodi'   => $prodi
]);
?>

<div class="card border-0 shadow-sm rounded-lg mt-3">
    <div class="card-header bg-white border-0 pt-4 pb-0">
        <h5 class="mb-0 font-weight-bold text-primary">
            <i class="fa fa-pencil-square-o mr-2"></i><?= $id ? 'Ubah' : 'Tambah' ?> Form Skala Nilai
        </h5>
        <?php if ($prodi_aktif): ?>
            <p class="text-muted small mt-1 mb-0">Mengonfigurasi matriks penilaian kurikulum prodi: <strong><?= s($prodi_aktif->nama_prodi) ?></strong></p>
        <?php endif; ?>
    </div>

    <div class="card-body p-4">
        <form method="post" action="<?= $action_url->out() ?>">
            <input type="hidden" name="sesskey" value="<?= sesskey() ?>">
            <input type="hidden" name="id" value="<?= $data->id ?>">
            <input type="hidden" name="id_prodi" value="<?= $prodi ?>">
            <input type="hidden" name="id_tahun_akademik" value="<?= $tahun ?>">

            <div class="form-group mb-3">
                <label class="font-weight-bold text-dark">Tipe Kompetensi <span class="text-danger">*</span></label>
                
                <?php if (!$id && !empty($tipe)): ?>
                    <input type="hidden" name="tipe_kompetensi" value="<?= s($data->tipe_kompetensi) ?>">
                    <input type="text" class="form-control bg-light font-weight-bold text-primary" value="<?= s($data->tipe_kompetensi) ?>" readonly>
                <?php else: ?>
                    <select name="tipe_kompetensi" id="tipe_kompetensi" class="form-control" required <?= $id > 0 ? 'disabled' : '' ?>>
                        <option value="" disabled>-- Pilih Tipe --</option>
                        <?php
                        $list_tipe = ['CPL', 'INDIKATOR', 'CPMK', 'SUB-CPMK'];
                        foreach ($list_tipe as $item):
                            $selected = (strtoupper($data->tipe_kompetensi) === $item) ? 'selected' : '';
                        ?>
                            <option value="<?= $item ?>" <?= $selected ?>><?= $item ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php if ($id > 0): ?>
                        <input type="hidden" name="tipe_kompetensi" value="<?= s($data->tipe_kompetensi) ?>">
                    <?php endif; ?>
                <?php endif; ?>
            </div>

            <div class="form-group mb-3">
                <label class="font-weight-bold text-dark">Huruf Nilai <span class="text-danger">*</span></label>
                <input type="text" name="huruf_nilai" class="form-control text-dark font-weight-bold" required value="<?= s($data->huruf_nilai) ?>" placeholder="Contoh: A, AB, B, BC, C">
            </div>

            <div class="row">
                <div class="col-md-6">
                    <div class="form-group mb-3">
                        <label class="font-weight-bold text-dark">Nilai Minimum <span class="text-danger">*</span></label>
                        <input type="number" step="0.01" min="0" max="100" name="nilai_min" class="form-control font-weight-bold" required value="<?= $data->nilai_min !== null ? rtrim(rtrim(number_format((float)$data->nilai_min, 2), '0'), '.') : '' ?>" placeholder="0">
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="form-group mb-3">
                        <label class="font-weight-bold text-dark">Nilai Maximum <span class="text-danger">*</span></label>
                        <input type="number" step="0.01" min="0" max="100" name="nilai_max" class="form-control font-weight-bold" required value="<?= $data->nilai_max !== null ? rtrim(rtrim(number_format((float)$data->nilai_max, 2), '0'), '.') : '' ?>" placeholder="100">
                    </div>
                </div>
            </div>

            <div class="form-group mb-4">
                <label class="for="status_kompeten" class="font-weight-bold text-dark">Status Capaian OBE <span class="text-danger">*</span></label>
                <select name="status_kompeten" id="status_kompeten" class="form-control" required>
                    <option value="1" <?= (isset($data->status_kompeten) && $data->status_kompeten == 1) ? 'selected' : '' ?>>KOMPETEN (Lulus Kompetensi)</option>
                    <option value="0" <?= (isset($data->status_kompeten) && $data->status_kompeten == 0) ? 'selected' : '' ?>>BELUM KOMPETEN (Tidak Lulus)</option>
                </select>
                <small class="form-text text-muted">Grade dengan status 'KOMPETEN' akan digunakan sebagai jangkar/ambang batas bawah dalam penghitungan persentase ketuntasan kompetensi kelas.</small>
            </div>

            <div class="d-flex justify-content-end border-top pt-3">
                <a href="<?= $kembali_url->out() ?>" class="btn btn-light px-4 mr-2 font-weight-bold">Batal</a>
                <button type="submit" class="btn btn-primary px-4 shadow-sm font-weight-bold">
                    <i class="fa fa-save mr-1"></i> Simpan Data Skala
                </button>
            </div>
        </form>
    </div>
</div>