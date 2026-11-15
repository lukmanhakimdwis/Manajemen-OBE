<?php
defined('MOODLE_INTERNAL') || die();
?>

<div class="card border-0 shadow-sm rounded-lg mt-3">
    <div class="card-header bg-white p-4 border-bottom-0">
        <h5 class="mb-0 font-weight-bold text-primary">
            <i class="fa fa-plus-circle mr-2"></i> Tambah Tahun Akademik
        </h5>
        <p class="text-muted small mt-1 mb-0">Tambahkan data periode tahun akademik baru untuk digunakan di seluruh modul OBE</p>
    </div>

    <div class="card-body p-4 border-top">
        <form action="<?= new moodle_url('/local/obe/views/admin/tahun_akademik/proses.php', ['aksi' => 'tambah']) ?>" method="post">
            <input type="hidden" name="sesskey" value="<?= sesskey(); ?>">

            <div class="form-group mb-4">
                <label class="font-weight-bold">Nama Tahun Akademik <span class="text-danger">*</span></label>
                <input type="text" name="nama_tahun" class="form-control" placeholder="Contoh: 2025/2026" required />
                <small class="form-text text-muted">Format disarankan memakai tanda pemisah tahun seperti garis miring (/).</small>
            </div>

            <div class="d-flex justify-content-end pt-3 border-top">
                <a href="?halaman=tahun_akademik" class="btn btn-light mr-2 px-4">Batal</a>
                <button type="submit" class="btn btn-primary px-4 shadow-sm">
                    <i class="fa fa-save mr-1"></i> Simpan
                </button>
            </div>
        </form>
    </div>
</div>