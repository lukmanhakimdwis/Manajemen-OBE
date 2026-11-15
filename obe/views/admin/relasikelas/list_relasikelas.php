<?php
defined('MOODLE_INTERNAL') || die();

global $SESSION, $USER;

// =====================================
// 1. IDENTIFIKASI PERAN & TANGKAP FILTER
// =====================================
$prodi_aktif = \local_obe\manager\prodi_manager::get_prodi_aktif_user();
$filter_tahun_akademik = $SESSION->obe_id_tahun_akademik ?? 0;

if ($prodi_aktif) {
    // Jika Kaprodi, otomatis kunci berdasarkan ID prodi miliknya
    $filter_prodi = (int)$prodi_aktif->id;
} else {
    // Jika Admin/Kajur, ambil dari opsi dropdown request
    $filter_prodi = optional_param('id_prodi', 0, PARAM_INT);
}

$filter_mk = optional_param('id_mk', 0, PARAM_INT);

// =====================================
// 2. AMBIL DATA MASTER BERDASARKAN FILTER
// =====================================
// Ambil semua daftar prodi untuk opsi dropdown (Hanya jika diakses oleh Admin/Kajur)
$list_prodi = !$prodi_aktif ? \local_obe\manager\prodi_manager::ambil_semua_prodi() : [];

// Filter pilihan Mata Kuliah secara dinamis berdasarkan prodi yang aktif/dipilih
$list_mk = ($filter_prodi > 0)
    ? \local_obe\manager\matakuliah_manager::ambil_semua($filter_prodi)
    : \local_obe\manager\matakuliah_manager::ambil_semua();

// Tarik data relasi kelas dengan menyertakan filter MK dan Prodi
$list_relasi = \local_obe\manager\relasikelas_manager::ambil_semua(
    $filter_mk,
    0,
    $filter_prodi, 
    $filter_tahun_akademik
);
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h4 class="mb-0 font-weight-bold text-dark">
        <i class="fa fa-tasks mr-2 text-primary"></i>
        Daftar Relasi Kelas <?= !empty($SESSION->obe_tahun_akademik) ? '(' . s($SESSION->obe_tahun_akademik) . ')' : '(Mode Global)' ?>
    </h4>
    <a href="?halaman=kelola_relasi&sub=form<?= $filter_prodi > 0 ? '&filter_prodi='.$filter_prodi : '' ?>" class="btn btn-primary btn-sm shadow-sm font-weight-bold">
        <i class="fa fa-plus mr-1"></i> Tambah Relasi
    </a>
</div>

<div class="card shadow-sm border-0 mb-4">
    <div class="card-body py-3">
        <form method="get" class="form-inline m-0">
            <input type="hidden" name="halaman" value="kelola_relasi">
            
            <?php if (!$prodi_aktif): ?>
                <label class="mr-2 font-weight-bold">Filter Prodi:</label>
                <select name="id_prodi" class="form-control mr-3" onchange="document.getElementById('filter_mk_select').value=0; this.form.submit()">
                    <option value="0">-- Semua Prodi --</option>
                    <?php foreach ($list_prodi as $prodi): ?>
                        <option value="<?= $prodi->id ?>" <?= ($filter_prodi == $prodi->id) ? 'selected' : '' ?>>
                            <?= s($prodi->nama_prodi) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            <?php endif; ?>

            <label class="mr-2 font-weight-bold">Filter Mata Kuliah:</label>
            <select name="id_mk" id="filter_mk_select" class="form-control" onchange="this.form.submit()">
                <option value="0">-- Semua Mata Kuliah --</option>
                <?php foreach ($list_mk as $mk): ?>
                    <option value="<?= $mk->id ?>" <?= ($filter_mk == $mk->id) ? 'selected' : '' ?>>
                        <?= s($mk->nama_mk) ?> <?= !empty($mk->kode_mk) ? '('.s($mk->kode_mk).')' : '' ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </form>
    </div>
</div>

<div class="card shadow-sm border-0 mb-4">
    <div class="table-responsive p-3 bg-white rounded">
        <table class="table table-hover align-middle mb-0">
            <thead class="thead-light">
                <tr class="text-uppercase small" style="letter-spacing: 0.5px;">
                    <th>Mata Kuliah</th>
                    <th width="15%" class="text-center">Angkatan</th>
                    <th width="15%" class="text-center">Tahun Akademik</th>
                    <th>Kelas Moodle</th>
                    <th width="15%" class="text-center">Aksi</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($list_relasi)): ?>
                    <tr>
                        <td colspan="5" class="text-center text-muted py-5">
                            <i class="fa fa-book fa-2x mb-2 d-block text-secondary"></i>
                            Belum ada data relasi untuk parameter filter ini.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($list_relasi as $r): ?>
                        <tr>
                            <td>
                                <span class="font-weight-bold text-dark">
                                    <?= s($r->nama_mk) ?>
                                </span>
                            </td>
                            <td class="text-center">
                                <span class="badge badge-info px-3 py-2">
                                    <?= s($r->nama_angkatan) ?>
                                </span>
                            </td>
                            <td class="text-center">
                                <span class="badge badge-secondary px-3 py-2">
                                    <?= s($r->nama_tahun ?? 'Global') ?>
                                </span>
                            </td>
                            <td>
                                <div class="font-weight-bold text-dark mb-1">
                                    <?= s($r->moodle_course_name) ?>
                                </div>
                                <small class="text-muted">
                                    <i class="fa fa-tag mr-1"></i> <code><?= s($r->moodle_shortname) ?></code>
                                </small>
                            </td>
                            <td class="text-center">
                                <a href="?halaman=kelola_relasi&sub=form&id=<?= $r->id ?>" 
                                   class="btn btn-warning btn-sm shadow-sm" 
                                   title="Ubah Relasi">
                                    <i class="fa fa-edit"></i>
                                </a>

                                <?php
                                $url_hapus = new moodle_url('/local/obe/views/admin/relasikelas/proses_relasikelas.php', [
                                    'aksi'    => 'hapus',
                                    'id'      => $r->id,
                                    'sesskey' => sesskey()
                                ]);
                                ?>
                                <a href="<?= $url_hapus->out() ?>" 
                                   class="btn btn-danger btn-sm shadow-sm" 
                                   title="Hapus Relasi" 
                                   onclick="return confirm('Apakah Anda yakin ingin menghapus relasi ini?')">
                                    <i class="fa fa-trash"></i>
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>