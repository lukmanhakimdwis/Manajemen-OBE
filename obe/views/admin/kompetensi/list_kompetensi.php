<?php
// File: local/obe/views/admin/kompetensi/list_kompetensi.php
defined('MOODLE_INTERNAL') || die();

use local_obe\manager\kompetensi_manager;
use local_obe\manager\prodi_manager;

global $PAGE, $OUTPUT, $SESSION, $USER, $DB;

// =========================================================================
// 1. IDENTIFIKASI PERAN & TANGKAP PARAMETER TERISOLASI (ANTI-IDOR GUARD)
// =========================================================================
$prodi_aktif = prodi_manager::get_prodi_aktif_user();

if ($prodi_aktif) {
    $prodi_terpilih = (int)$prodi_aktif->id;
} else {
    $prodi_terpilih = optional_param('prodi', 0, PARAM_INT);
}

if ($prodi_terpilih > 0 && !$prodi_aktif) {
    if (!$DB->record_exists('local_obe_prodi', ['id' => $prodi_terpilih])) {
        throw new moodle_exception('invalidparameter', 'local_obe', '', null, 'Data Program Studi tidak ditemukan di sistem.');
    }
}

$tahun_akademik = $SESSION->obe_id_tahun_akademik ?? 0;
$daftar_prodi = !$prodi_aktif ? kompetensi_manager::ambil_daftar_prodi() : [];

$kompetensi = ($prodi_terpilih > 0)
    ? kompetensi_manager::ambil_kompetensi_terurut($prodi_terpilih, $tahun_akademik)
    : [];

// =========================================================================
// 2. KONSTRUKSI URL NAVIGASI AMAN
// =========================================================================
$url_bobot = new moodle_url('/local/obe/views/admin/dashboard.php', [
    'halaman' => 'bobot_relasi',
    'prodi'   => $prodi_terpilih
]);

$proses_url = new moodle_url('/local/obe/views/admin/kompetensi/proses_kompetensi.php');

$sync_url = new moodle_url($proses_url, [
    'aksi'    => 'sync',
    'prodi'   => $prodi_terpilih,
    'sesskey' => sesskey()
]);
?>

<?php if (!$prodi_aktif): ?>
<div class="card shadow-sm border-0 mb-4">
    <div class="card-body py-3">
        <form method="get" class="form-inline m-0">
            <input type="hidden" name="halaman" value="manajemen_kompetensi">
            
            <label class="mr-2 font-weight-bold">Filter Prodi:</label>
            <select name="prodi" class="form-control" onchange="this.form.submit()">
                <option value="0">-- Pilih Prodi --</option>
                <?php foreach ($daftar_prodi as $p): ?>
                    <option value="<?= $p->id ?>" <?= $prodi_terpilih == $p->id ? 'selected' : '' ?>>
                        <?= s($p->nama_prodi) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </form>
    </div>
</div>
<?php endif; ?>

<?php if ($prodi_terpilih == 0): ?>
    <div class="alert alert-info py-4 text-center border-0 shadow-sm" style="border-radius: 8px;">
        <i class="fa fa-info-circle fa-2x text-info mb-2 d-block"></i>
        <h5 class="font-weight-bold text-dark mb-1">Silakan Pilih Program Studi Terlebih Dahulu</h5>
        <p class="text-muted mb-0 small">Gunakan dropdown filter di atas untuk melihat struktur hierarki kurikulum OBE prodi aktif.</p>
    </div>
<?php else: ?>

    <div class="card shadow-sm border-0 mb-4">
        <div class="card-header bg-white d-flex justify-content-between align-items-center py-3 border-bottom">
            <h5 class="mb-0 font-weight-bold text-dark">
                <i class="fa fa-list text-primary mr-2"></i>Daftar Kompetensi Struktur Kurikulum
            </h5>
            <div class="btn-group">
                <a href="<?= $url_bobot->out() ?>" class="btn btn-primary btn-sm shadow-sm font-weight-bold mr-2">
                    <i class="fa fa-link mr-1"></i> Bobot Kompetensi
                </a>
                <a href="<?= $sync_url->out() ?>" class="btn btn-success btn-sm shadow-sm font-weight-bold" onclick="return confirm('Hapus data ini?')">
                    <i class="fa fa-refresh mr-1"></i> Sinkronisasi Data
                </a>
            </div>
        </div>

        <div class="table-responsive bg-white rounded p-3">
            <table class="table table-striped table-hover align-middle mb-0 border">
                <thead class="thead-light">
                    <tr class="text-uppercase small" style="letter-spacing: 0.5px;">
                        <th style="width: 35%;" class="py-3 pl-4">Nama Kompetensi</th>
                        <th style="width: 50%;">Deskripsi Lengkap</th>
                        <th style="width: 15%;" class="text-center">Tipe</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($kompetensi)): ?>
                    <tr>
                        <td colspan="3" class="text-center text-muted py-5">
                            <i class="fa fa-exclamation-triangle fa-2x d-block mb-2 text-warning"></i>
                            Data hierarki belum tersedia. Silakan klik tombol <strong class="text-success">"Sinkronisasi Data"</strong> di atas untuk memetakan data framework prodi aktif.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php
                    foreach ($kompetensi as $k):
                        $tipe_up = strtoupper(trim($k->tipe));
                        $depth = 0;
                        $badge_class = 'secondary';
                        
                        $font_style = 'color: #212529; font-weight: 500;';
                        
                        if ($tipe_up === 'CPL') {
                            $depth = 0;
                            $badge_class = 'primary';
                            $font_style = 'color: #000000; font-weight: 700;';
                        } elseif ($tipe_up === 'INDIKATOR') {
                            $depth = 1;
                            $badge_class = 'warning text-white'; 
                            $font_style = 'color: #111111; font-weight: 600;';
                        } elseif ($tipe_up === 'CPMK') {
                            $depth = 2;
                            $badge_class = 'info';
                            $font_style = 'color: #212529; font-weight: 500;';
                        } elseif ($tipe_up === 'SUB-CPMK') {
                            $depth = 3;
                            $badge_class = 'secondary';
                            $font_style = 'color: #495057; font-weight: 400;';
                        }
                        
                        $indent = ($depth * 25) . 'px';
                    ?>
                    <tr>
                        <td style="padding-left: calc(15px + <?= $indent ?>);" class="py-3">
                            <div style="<?= $font_style ?>" class="text-break">
                                <i class="fa fa-caret-right text-info mr-2"></i><?= s($k->nama_kompetensi) ?>
                            </div>
                        </td>
                        
                        <td class="py-3 text-secondary small" style="line-height: 1.5; color: #495057 !important;">
                            <?php if (!empty($k->deskripsi)): ?>
                                <?= s(strip_tags($k->deskripsi)) ?>
                            <?php else: ?>
                                <span class="text-muted font-italic" style="color: #adb5bd !important;">-</span>
                            <?php endif; ?>
                        </td>
                        
                        <td class="py-3 text-center">
                            <span class="badge badge-<?= $badge_class ?> px-3 py-1.5 font-weight-bold text-uppercase" style="font-size: 0.75rem; min-width: 100px; letter-spacing: 0.5px;">
                                <?= $tipe_up ?>
                            </span>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>