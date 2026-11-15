<?php
defined('MOODLE_INTERNAL') || die();

use local_obe\manager\prodiframework_manager;
use local_obe\manager\prodi_manager;

global $SESSION;

// =========================================================================
// 1. IDENTIFIKASI PERAN & TANGKAP PARAMETER TERKONSISTENSI
// =========================================================================
$prodi_aktif = prodi_manager::get_prodi_aktif_user();
$filter_prodi = optional_param('filter_prodi', 0, PARAM_INT); // Konsisten menggunakan filter_prodi

if ($prodi_aktif) {
    // Jika Kaprodi, paksa kunci variabel id_prodi berdasarkan hak jabatannya
    $id_prodi = (int)$prodi_aktif->id;
} else {
    // Jika Admin/Kajur, gunakan parameter filter prodi yang dipilih
    $id_prodi = $filter_prodi;
}

// Tarik data dengan melempar parameter ID Prodi ke fungsi manager
$list_data = prodiframework_manager::ambil_semua($id_prodi);
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h4 class="mb-0 font-weight-bold text-dark">
        <i class="fa fa-sitemap mr-2 text-primary"></i>Pemetaan Framework ke Prodi 
        <?= !empty($SESSION->obe_tahun_akademik) ? '(' . s($SESSION->obe_tahun_akademik) . ')' : '' ?>
    </h4>
    <a href="?halaman=kelola_framework&sub=form<?= $id_prodi > 0 ? '&filter_prodi=' . $id_prodi : '' ?>" class="btn btn-primary btn-sm shadow-sm font-weight-bold">
        <i class="fa fa-plus mr-1"></i> Tambah Mapping
    </a>
</div>

<?php if (!$prodi_aktif): ?>
    <?php $prodis = prodi_manager::ambil_semua_prodi(); ?>
    <div class="card shadow-sm border-0 mb-4">
        <div class="card-body py-3">
            <form method="get" class="form-inline m-0">
                <input type="hidden" name="halaman" value="kelola_framework">

                <label class="mr-2 font-weight-bold">Filter Prodi:</label>
                <select name="filter_prodi" class="form-control" onchange="this.form.submit()">
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

<div class="table-responsive bg-white shadow-sm rounded p-3">
    <table class="table table-hover align-middle mb-0">
        <thead class="thead-light">
            <tr class="text-uppercase small" style="letter-spacing: 0.5px;">
                <th width="20%">Tahun Akademik</th>
                <th width="30%">Program Studi</th>
                <th>Competency Framework Moodle</th>
                <th width="20%" class="text-center">Aksi</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($list_data)): ?>
                <tr>
                    <td colspan="4" class="text-center text-muted py-5">
                        <i class="fa fa-sitemap fa-2x mb-2 d-block text-secondary"></i>
                        Belum ada data mapping framework kurikulum yang terekam untuk kriteria filter ini.
                    </td>
                </tr>
            <?php else: ?>
                <?php foreach ($list_data as $row): ?>
                    <?php $memilikirelasi = prodiframework_manager::memiliki_relasi($row->id); ?>
                    <tr>
                        <td>
                            <span class="font-weight-bold text-primary">
                                <?= s($row->nama_tahun ?? '-') ?>
                            </span>
                        </td>
                        <td>
                            <span class="font-weight-bold text-dark">
                                <?= s($row->nama_prodi) ?>
                            </span>
                        </td>
                        <td>
                            <span class="badge badge-info px-3 py-2" style="font-size: 0.85rem;">
                                <i class="fa fa-cube mr-1"></i><?= s($row->framework_name) ?>
                            </span>
                        </td>
                        <td class="text-center">
                            <?php if ($memilikirelasi): ?>
                                
                                <button type="button" class="btn btn-secondary btn-sm shadow-sm" title="Data memiliki relasi aktif di tingkat kurikulum dan tidak dapat dihapus" disabled>
                                    <i class="fa fa-lock"></i>
                                </button>
                            <?php else: ?>
                                <a href="<?= new moodle_url('/local/obe/views/admin/framework/proses_framework.php', [
                                            'aksi'    => 'hapus',
                                            'id'      => $row->id,
                                            'sesskey' => sesskey()
                                        ]) ?>" 
                                   class="btn btn-danger btn-sm shadow-sm" 
                                   title="Hapus Mapping" 
                                   onclick="return confirm('Hapus data ini?')">
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