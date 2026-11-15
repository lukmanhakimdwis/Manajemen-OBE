<?php
defined('MOODLE_INTERNAL') || die();

global $PAGE;

use local_obe\manager\matakuliah_manager;
use local_obe\manager\prodi_manager;

$id_edit      = optional_param('id', 0, PARAM_INT);
$filter_prodi = optional_param('filter_prodi', 0, PARAM_INT); 

$data = $id_edit ? matakuliah_manager::ambil_by_id($id_edit) : null;
$aksi = $id_edit ? 'update' : 'simpan';

// Identifikasi peran pengguna aktif
$prodi_aktif = prodi_manager::get_prodi_aktif_user();

// =========================================================================
// VALIDASI KEAMANAN (ANTI-IDOR): Proteksi Akses Lintas Prodi via URL Manual
// =========================================================================
if ($prodi_aktif && $data) {
    if ((int)$data->id_prodi !== (int)$prodi_aktif->id) {
        throw new \moodle_exception(
            'aksesditolak', 
            'local_obe', 
            '', 
            null, 
            'Gagal memuat form! Anda tidak memiliki hak akses untuk mengelola data mata kuliah dari program studi lain.'
        );
    }
}

if ($prodi_aktif) {
    // Jika Kaprodi, kunci pilihan prodi hanya untuk prodinya sendiri
    $prodis = [$prodi_aktif];
    $id_prodi_default = (int)$prodi_aktif->id;
} else {
    // Jika Admin, ambil prodi dari data edit lama, atau filter list, atau set default 0
    $prodis = prodi_manager::ambil_semua_prodi();
    $id_prodi_default = $data ? (int)$data->id_prodi : $filter_prodi;
}
?>

<div class="card border-0 shadow-sm rounded-lg mt-3">
    <div class="card-header bg-white border-0 pt-4 pb-0">
        <h5 class="font-weight-bold text-primary">
            <i class="fa fa-<?= $id_edit ? 'edit' : 'plus-circle'; ?> mr-2"></i>
            <?= $id_edit ? 'Edit Mata Kuliah' : 'Tambah Mata Kuliah Baru'; ?>
        </h5>
    </div>
    <div class="card-body">
        <form action="?halaman=kelola_mk&aksi=<?= $aksi; ?>" method="post">
            <input type="hidden" name="sesskey" value="<?= sesskey(); ?>">
            <input type="hidden" name="id" value="<?= $id_edit; ?>">
            
            <div class="form-group mb-4">
                <label class="font-weight-bold">Program Studi <span class="text-danger">*</span></label>
                
                <?php if ($prodi_aktif): ?>
                    <input type="hidden" name="id_prodi" value="<?= $id_prodi_default ?>">
                    <input type="text" class="form-control bg-light font-weight-bold" value="<?= s($prodi_aktif->nama_prodi) ?>" readonly>
                <?php else: ?>
                    <select name="id_prodi" class="form-control" required <?= $id_edit > 0 ? 'disabled' : '' ?>>
                        <option value="">-- Pilih Program Studi --</option>
                        <?php foreach ($prodis as $p): ?>
                            <option value="<?= $p->id ?>" <?= ($id_prodi_default == $p->id) ? 'selected' : '' ?>>
                                <?= s($p->nama_prodi) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <?php if ($id_edit > 0): ?>
                        <input type="hidden" name="id_prodi" value="<?= $id_prodi_default ?>">
                    <?php endif; ?>
                <?php endif; ?>
            </div>

            <div class="row">
                <div class="col-md-4">
                    <div class="form-group mb-3">
                        <label class="font-weight-bold">Kode Mata Kuliah <span class="text-danger">*</span></label>
                        <input type="text" name="kode_mk" class="form-control" 
                               value="<?= $data ? s($data->kode_mk) : ''; ?>" 
                               placeholder="Contoh: IF201" required>
                    </div>
                </div>

                <div class="col-md-6">
                    <div class="form-group mb-3">
                        <label class="font-weight-bold">Nama Mata Kuliah <span class="text-danger">*</span></label>
                        <input type="text" name="nama_mk" class="form-control" 
                               value="<?= $data ? s($data->nama_mk) : ''; ?>" 
                               placeholder="Contoh: Pemrograman Web" required>
                    </div>
                </div>

                <div class="col-md-2">
                    <div class="form-group mb-3">
                        <label class="font-weight-bold">SKS <span class="text-danger">*</span></label>
                        <input type="number" name="sks_mk" class="form-control" 
                               min="1" max="10" 
                               value="<?= $data ? s($data->sks_mk) : '2'; ?>" required>
                    </div>
                </div>
            </div>

            <div class="d-flex justify-content-end border-top pt-3 mt-2">
                <a href="?halaman=kelola_mk<?= $id_prodi_default > 0 ? '&id_prodi='.$id_prodi_default : '' ?>" class="btn btn-light px-4 mr-2">Batal</a>
                <button type="submit" class="btn btn-primary px-4 shadow-sm">
                    <i class="fa fa-save mr-1"></i> Simpan
                </button>
            </div>
        </form>
    </div>
</div>