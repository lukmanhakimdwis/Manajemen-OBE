<?php
defined('MOODLE_INTERNAL') || die();

use local_obe\manager\angkatan_manager;
use local_obe\manager\prodi_manager;

global $USER;

// =====================================
// 1. IDENTIFIKASI PERAN & TANGKAP FILTER
// =====================================
$prodi_aktif = prodi_manager::get_prodi_aktif_user();

if ($prodi_aktif) {
    // Jika Kaprodi, kunci variabel id_prodi berdasarkan hak aksesnya
    $id_prodi = (int)$prodi_aktif->id;
} else {
    // Jika Admin/Kajur, ambil prodi dari dropdown parameter GET
    $id_prodi = optional_param('id_prodi', 0, PARAM_INT);
}

// =====================================
// 2. TARIK DATA MASTER
// =====================================
$angkatans = angkatan_manager::ambil_semua($id_prodi);

// Data master dropdown prodi hanya ditarik jika diakses oleh Admin/Kajur
$prodis = !$prodi_aktif ? prodi_manager::ambil_semua_prodi() : [];
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h4 class="mb-0 font-weight-bold text-dark">
        <i class="fa fa-users mr-2 text-primary"></i>Daftar Angkatan
    </h4>
    <a href="?halaman=kelola_angkatan&sub=form<?= $id_prodi > 0 ? '&filter_prodi=' . $id_prodi : '' ?>" class="btn btn-primary btn-sm shadow-sm font-weight-bold">
        <i class="fa fa-plus mr-1"></i> Tambah Angkatan
    </a>
</div>

<?php if (!$prodi_aktif): ?>
<div class="card shadow-sm border-0 mb-4">
    <div class="card-body py-3">
        <form method="get" class="form-inline m-0">
            <input type="hidden" name="halaman" value="kelola_angkatan">

            <label class="mr-2 font-weight-bold">Filter Prodi:</label>
            <select name="id_prodi" class="form-control" onchange="this.form.submit()">
                <option value="0">-- Semua Prodi --</option>
                <?php foreach ($prodis as $p): ?>
                    <option value="<?= $p->id ?>" <?= ($id_prodi == $p->id) ? 'selected' : '' ?>>
                        <?= s($p->nama_prodi) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </form>
    </div>
</div>
<?php endif; ?>

<div class="table-responsive bg-white rounded shadow-sm p-3">
    <table class="table table-hover align-middle mb-0">
        <thead class="thead-light">
            <tr class="text-uppercase small" style="letter-spacing: 0.5px;">
                <th width="12%">Tahun</th>
                <th>Nama Angkatan</th>
                <th width="30%">Program Studi</th>
                <th width="15%" class="text-center">Aksi</th>
            </tr>
        </thead>

        <tbody>
            <?php if (empty($angkatans)): ?>
                <tr>
                    <td colspan="4" class="text-center text-muted py-5">
                        <i class="fa fa-users fa-2x mb-2 d-block text-secondary"></i>
                        Belum ada data angkatan yang terekam untuk program studi ini.
                    </td>
                </tr>
            <?php else: ?>
                <?php foreach ($angkatans as $a): ?>
                    <?php $memilikirelasi = angkatan_manager::memiliki_relasi($a->id); ?>
                    <tr>
                        <td>
                            <span class="badge badge-secondary px-3 py-2" style="font-size: 0.85rem;">
                                <?= s($a->tahun_masuk); ?>
                            </span>
                        </td>
                        <td>
                            <span class="font-weight-bold text-dark">
                                <?= s($a->nama_angkatan); ?>
                            </span>
                        </td>
                        <td>
                            <i class=""></i> <?= s($a->nama_prodi); ?>
                        </td>
                        <td class="text-center">
                            <a href="?halaman=kelola_angkatan&sub=form&id=<?= $a->id; ?>" class="btn btn-warning btn-sm shadow-sm" title="Edit Angkatan">
                                <i class="fa fa-edit"></i>
                            </a>

                            <?php if ($memilikirelasi): ?>
                                <button type="button" class="btn btn-secondary btn-sm shadow-sm" title="Data memiliki relasi aktif dan tidak dapat dihapus" disabled>
                                    <i class="fa fa-lock"></i>
                                </button>
                            <?php else: ?>
                                <a href="?halaman=kelola_angkatan&aksi=hapus&id=<?= $a->id; ?>&sesskey=<?= sesskey(); ?>" class="btn btn-danger btn-sm shadow-sm" title="Hapus Angkatan" onclick="return confirm('Hapus data ini?')">
                                    <i class="fa fa-trash"></i>
                                </a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>