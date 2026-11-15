<?php
defined('MOODLE_INTERNAL') || die();

use local_obe\controller\skala_nilai_controller;
use local_obe\manager\kompetensi_manager;
use local_obe\manager\prodi_manager;

global $SESSION, $OUTPUT, $USER;

// =========================================================================
// 1. IDENTIFIKASI PERAN & TANGKAP PARAMETER TERISOLASI
// =========================================================================
$prodi_aktif = prodi_manager::get_prodi_aktif_user();

if ($prodi_aktif) {
    // Jika pengguna adalah Kaprodi, paksa kunci ID prodi miliknya sendiri
    $prodi_terpilih = (int)$prodi_aktif->id;
} else {
    // Jika Admin/Kajur, ijinkan menangkap prodi terpilih dari filter dropdown
    $prodi_terpilih = optional_param('prodi', 0, PARAM_INT);
}

// Mengambil tahun akademik dari session dengan fallback 0 jika kosong
$tahun_akademik = $SESSION->obe_id_tahun_akademik ?? 0;

// Mengambil daftar program studi (Hanya jika diakses oleh Admin atau Kajur)
$daftar_prodi = !$prodi_aktif ? kompetensi_manager::ambil_daftar_prodi() : [];

// Kontainer untuk mengelompokkan data berdasarkan tipe kompetensi
$data_terkelompok = [];

if ($prodi_terpilih > 0) {
    $data_mentah = skala_nilai_controller::tampilkan_data(
        $prodi_terpilih,
        $tahun_akademik
    );

    // Mengelompokkan data secara dinamis berdasarkan tipe_kompetensi
    if (!empty($data_mentah)) {
        foreach ($data_mentah as $d) {
            $tipe = strtoupper($d->tipe_kompetensi); 
            $data_terkelompok[$tipe][] = $d;
        }
    }
}

// Daftar kompetensi wajib
$list_tipe_wajib = ['CPL', 'INDIKATOR', 'CPMK', 'SUB-CPMK'];
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h4 class="mb-0 font-weight-bold text-dark">
        <i class="fa fa-graduation-cap mr-2 text-primary"></i>
        Matriks Skala Nilai Kurikulum
    </h4>
</div>

<?php if (!$prodi_aktif): ?>
<div class="card shadow-sm border-0 mb-4">
    <div class="card-body py-3">
        <form method="get" action="" class="form-inline m-0">
            <input type="hidden" name="halaman" value="skala_nilai">
            
            <label class="mr-2 font-weight-bold" for="select_prodi">Filter Prodi:</label>
            <select name="prodi" id="select_prodi" class="form-control" onchange="this.form.submit()">
                <option value="0">-- Pilih Prodi --</option>
                <?php if (!empty($daftar_prodi)): ?>
                    <?php foreach ($daftar_prodi as $p): ?>
                        <option value="<?= $p->id ?>" <?= ($prodi_terpilih == $p->id) ? 'selected' : '' ?>>
                            <?= s($p->nama_prodi) ?>
                        </option>
                    <?php endforeach; ?>
                <?php endif; ?>
            </select>
        </form>
    </div>
</div>
<?php endif; ?>

<?php if ($prodi_terpilih == 0): ?>
    <div class="alert alert-info py-4 text-center">
        <i class="fa fa-info-circle fa-2x text-info mb-2 d-block"></i>
        <h5 class="font-weight-bold text-dark mb-1">Silakan Pilih Program Studi Terlebih Dahulu</h5>
        <p class="text-muted mb-0 small">Gunakan dropdown filter di atas untuk memuat data master matriks skala nilai kurikulum OBE prodi aktif.</p>
    </div>
<?php else: ?>
    
    
    
    <?php foreach ($list_tipe_wajib as $tipe): 
        $data_tipe = $data_terkelompok[$tipe] ?? [];
        
        $badge_class = 'badge-primary';
        if ($tipe === 'CPMK') $badge_class = 'badge-info';
        if ($tipe === 'SUB-CPMK') $badge_class = 'badge-secondary';
        if ($tipe === 'INDIKATOR') $badge_class = 'badge-warning text-white';

        $collapse_id = 'collapse_' . strtolower(str_replace('-', '_', $tipe));
    ?>
        
        <div class="card mb-3 shadow-sm border-0">
            <div class="card-header bg-light d-flex justify-content-between align-items-center py-3" 
                 data-toggle="collapse" 
                 data-target="#<?= $collapse_id ?>" 
                 aria-expanded="true" 
                 aria-controls="<?= $collapse_id ?>" 
                 style="cursor: pointer; user-select: none;">
                
                <h5 class="mb-0 font-weight-bold text-dark d-flex align-items-center">
                    <span class="badge <?= $badge_class ?> mr-2 px-2 py-1.5"><?= $tipe ?></span> 
                    Skala Nilai Pemetaan <?= $tipe ?>
                    <small class="text-muted font-weight-normal ml-3" style="font-size: 0.8rem;">
                        (<?= count($data_tipe) ?> Data)
                    </small>
                </h5>

                <div class="d-flex align-items-center">
                    <?php
                        $url_tambah = new moodle_url('/local/obe/views/admin/dashboard.php', [
                            'halaman' => 'skala_nilai',
                            'sub'     => 'form',
                            'prodi'   => $prodi_terpilih,
                            'tipe'    => $tipe
                        ]);
                    ?>
                    <a href="<?= $url_tambah->out() ?>" 
                       class="btn btn-outline-primary btn-sm font-weight-bold mr-3" 
                       onclick="event.stopPropagation();">
                        <i class="fa fa-plus mr-1"></i> Tambah Data <?= $tipe ?>
                    </a>
                    <i class="fa fa-chevron-down text-muted transition-icon"></i>
                </div>
            </div>

            <div id="<?= $collapse_id ?>" class="collapse show">
                <div class="table-responsive p-3 bg-white rounded">
                    <table class="table table-bordered table-striped table-hover align-middle mb-0 text-center">
                        <thead class="thead-light">
                            <tr class="text-uppercase small" style="letter-spacing: 0.5px;">
                                <th class="text-left pl-4" style="width: 30%;">Huruf Nilai</th>
                                <th style="width: 20%;">Nilai Minimal</th>
                                <th style="width: 20%;">Nilai Maksimal</th>
                                <th style="width: 15%;">Status OBE</th>
                                <th style="width: 15%;">Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($data_tipe)): ?>
                                <tr>
                                    <td colspan="5" class="text-center text-muted py-4">
                                        <i class="fa fa-ban mr-1"></i> Belum ada data master skala nilai untuk tipe <strong><?= $tipe ?></strong>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($data_tipe as $d): ?>
                                    <tr>
                                        <td class="text-left pl-4 font-weight-bold text-dark" style="font-size: 1.1rem;">
                                            <?= s($d->huruf_nilai) ?>
                                        </td>
                                        <td class="font-weight-bold text-dark"> <?= rtrim(rtrim(number_format($d->nilai_min, 2), '0'), '.') ?></td>
                                            <td class="font-weight-bold text-dark"> <?= rtrim(rtrim(number_format($d->nilai_max, 2), '0'), '.') ?></td>
                                        <td>
                                            <?php if (isset($d->status_kompeten) && $d->status_kompeten == 1): ?>
                                                <span class="badge badge-success px-2.5 py-1 font-weight-bold" style="font-size: 0.75rem; letter-spacing: 0.5px;">
                                                    <i class="fa fa-check mr-1"></i>KOMPETEN
                                                </span>
                                            <?php else: ?>
                                                <span class="badge badge-light border text-muted px-2.5 py-1 font-weight-bold" style="font-size: 0.75rem; letter-spacing: 0.5px;">
                                                    <i class="fa fa-times text-danger mr-1"></i>BELUM
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php
                                            // Parameter URL Edit
                                            $url_edit = new moodle_url('/local/obe/views/admin/dashboard.php', [
                                                'halaman' => 'skala_nilai',
                                                'sub'     => 'form',
                                                'id'      => (int)$d->id,
                                                'prodi'   => $prodi_terpilih,
                                                'tipe'    => $tipe
                                            ]);

                                            // Parameter URL Hapus
                                            $url_hapus = new moodle_url('/local/obe/views/admin/skala_nilai/proses_skala.php', [
                                                'aksi'    => 'hapus',
                                                'id'      => (int)$d->id,
                                                'prodi'   => $prodi_terpilih,
                                                'tipe'    => $tipe,
                                                'sesskey' => sesskey()
                                            ]);
                                            ?>
                                            <a href="<?= $url_edit->out() ?>" class="btn btn-warning btn-sm mr-1 shadow-sm" title="Edit">
                                                <i class="fa fa-pencil text-dark"></i>
                                            </a>
                                            <a href="<?= $url_hapus->out() ?>" class="btn btn-danger btn-sm shadow-sm" title="Hapus" onclick="return confirm('Hapus data ini?')">
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
        </div>
    <?php endforeach; ?>

    <style>
        .card-header[aria-expanded="false"] .transition-icon {
            transform: rotate(-90deg);
        }
        .transition-icon {
            transition: transform 0.25s ease-in-out;
        }
    </style>
<?php endif; ?>