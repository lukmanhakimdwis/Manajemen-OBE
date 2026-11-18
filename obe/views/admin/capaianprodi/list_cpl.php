<?php
// File: local/obe/views/admin/capaianprodi/list_cpl.php
defined('MOODLE_INTERNAL') || die();

use local_obe\manager\kompetensi_manager;
use local_obe\manager\capaian_prodi_manager;
use local_obe\manager\prodi_manager;

global $SESSION, $OUTPUT, $USER, $DB;

// ========================================
// 1. CEK ROLE & PROTEKSI KAPRODI (ANTI-IDOR)
// ========================================
$is_kaprodi = prodi_manager::is_kaprodi($USER->id);
$tahun_akademik = $SESSION->obe_id_tahun_akademik ?? 0;

$angkatan_id = optional_param('angkatan', 0, PARAM_INT);

// Validasi eksistensi Angkatan terpilih
if ($angkatan_id > 0) {
    if (!$DB->record_exists('local_obe_angkatan', ['id' => $angkatan_id])) {
        throw new moodle_exception(
            'invalidparameter',
            'local_obe',
            '',
            null,
            'Aksi ditolak! Data Angkatan yang Anda masukkan tidak ditemukan di sistem.'
        );
    }
}

// Tentukan prodi aktif
if ($is_kaprodi) {
    $prodiaktif = prodi_manager::ambil_prodi_kaprodi($USER->id);
    if (!$prodiaktif) {
        throw new moodle_exception('invalidrecord', 'local_obe', '', null, 'Akun Kaprodi belum terhubung dengan Program Studi.');
    }
    $prodi_terpilih = $prodiaktif->id;
} else {
    $prodi_terpilih = optional_param('prodi', 0, PARAM_INT);
}

// Validasi Integritas Data Cross-Prodi
if ($prodi_terpilih > 0 && $angkatan_id) {
    $validangkatan = $DB->record_exists('local_obe_angkatan', [
        'id'        => $angkatan_id,
        'id_prodi'  => $prodi_terpilih
    ]);
    if (!$validangkatan) {
        $angkatan_id = 0;
    }
}

// ========================================
// 2. DATA DROPDOWN MASTER FILTER
// ========================================
$daftar_prodi = [];
if (!$is_kaprodi) {
    $daftar_prodi = kompetensi_manager::ambil_daftar_prodi();
}

$kondisi_angkatan = ($prodi_terpilih > 0) ? ['id_prodi' => $prodi_terpilih] : null;
$daftar_angkatan = $DB->get_records('local_obe_angkatan', $kondisi_angkatan, 'nama_angkatan DESC', 'id, nama_angkatan');

// ========================================
// 3. AMBIL DATA CPL
// ========================================
$cpl_data = [];
if ($prodi_terpilih > 0) {
    $cpl_data = capaian_prodi_manager::ambil_capaian_cpl_prodi(
        $prodi_terpilih,
        $tahun_akademik,
        $angkatan_id
    );
}

$nama_prodi_header = '';
if ($prodi_terpilih > 0) {
    $nama_prodi_header = $DB->get_field('local_obe_prodi', 'nama_prodi', ['id' => $prodi_terpilih]);
}

$format_nilai = function($nilai, $desimal = 2) {
    if (!is_numeric($nilai)) {
        return $nilai;
    }
    return (floor($nilai) == $nilai)
        ? (int)$nilai
        : rtrim(rtrim(number_format($nilai, $desimal, '.', ''), '0'), '.');
};
?>

<div class="card mb-4 shadow-sm border-0">
    <div class="card-header bg-white py-3 border-bottom">
        <h5 class="mb-0 font-weight-bold text-dark">
            <i class="fa fa-filter text-primary mr-2"></i>Parameter Analisis Capaian
        </h5>
    </div>
    <div class="card-body">
        <form method="get" class="row m-0 align-items-end">
            <input type="hidden" name="halaman" value="capaian_pembelajaran_prodi">
            <input type="hidden" name="sub" value="cpl">
            
            <?php if (!$is_kaprodi): ?>
            <div class="col-md-4 mb-2">
                <div class="form-group mb-0">
                    <label class="form-label font-weight-bold text-dark small d-block mb-1">Program Studi</label>
                    <select name="prodi" id="select-prodi" class="form-control form-control-sm custom-select" onchange="this.form.submit()">
                        <option value="0">-- Semua Prodi --</option>
                        <?php foreach ($daftar_prodi as $prodi): ?>
                            <option value="<?= $prodi->id ?>" <?= ($prodi_terpilih == $prodi->id) ? 'selected' : '' ?>>
                                <?= s($prodi->nama_prodi) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <?php endif; ?>

            <div class="<?= !$is_kaprodi ? 'col-md-5' : 'col-md-8' ?> mb-2">
                <div class="form-group mb-0">
                    <label class="form-label font-weight-bold text-dark small d-block mb-1">Angkatan</label>
                    <select name="angkatan" id="select-angkatan" class="form-control form-control-sm custom-select" onchange="this.form.submit()">
                        <option value="0">-- Semua Angkatan --</option>
                        <?php foreach ($daftar_angkatan as $angkatan): ?>
                            <option value="<?= $angkatan->id ?>" <?= ($angkatan_id == $angkatan->id) ? 'selected' : '' ?>>
                                <?= s($angkatan->nama_angkatan) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="<?= !$is_kaprodi ? 'col-md-3' : 'col-md-4' ?> mb-2 text-right">
                <?php if ($prodi_terpilih > 0): ?>
                    <?php
                    $url_grafik = new moodle_url('/local/obe/views/admin/dashboard.php', [
                        'halaman'  => 'capaian_pembelajaran_prodi',
                        'sub'      => 'grafik_cpl',
                        'prodi'    => $prodi_terpilih,
                        'angkatan' => $angkatan_id,
                        'sesskey'  => sesskey()
                    ]);

                    $url_export = new moodle_url('/local/obe/export/export_capaian_prodi_csv.php', [
                        'prodi'    => $prodi_terpilih,
                        'angkatan' => $angkatan_id,
                        'sesskey'  => sesskey()
                    ]);
                    ?>
                   <div class="d-flex justify-content-end align-items-center" style="gap: 6px;">
                        <a href="<?= $url_grafik->out() ?>" class="btn btn-primary btn-sm font-weight-bold shadow-sm px-3" style="min-height: 32px; height: auto; display: inline-flex; align-items: center; justify-content: center; white-space: nowrap;">
                            <i class="fa fa-bar-chart mr-1.5"></i> Grafik CPL
                        </a>
                        <a href="<?= $url_export->out() ?>" class="btn btn-success btn-sm font-weight-bold shadow-sm px-3" style="min-height: 32px; height: auto; display: inline-flex; align-items: center; justify-content: center; white-space: nowrap;">
                            <i class="fa fa-download mr-1.5"></i> Unduh CSV
                        </a>
                    </div>
                <?php else: ?>
                    <span class="badge badge-secondary font-weight-bold px-2 py-2 d-inline-block text-center" style="font-size: 0.75rem; min-height: 31px; line-height: 15px;">Pilih Prodi Aktif</span>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<?php if ($prodi_terpilih == 0): ?>
    <div class="alert alert-info py-4 text-center border-0 shadow-sm" style="border-radius: 8px;">
        <i class="fa fa-info-circle fa-2x text-info mb-2 d-block"></i>
        <h5 class="font-weight-bold text-dark mb-1">Silakan Pilih Program Studi Terlebih Dahulu</h5>
        <p class="text-muted mb-0 small">Gunakan dropdown filter di atas untuk memuat data laporan ketercapaian CPL Program Studi.</p>
    </div>
<?php else: ?>
    <div class="card shadow-sm mb-4 border-0">
        <div class="card-header bg-white py-3 border-bottom d-flex justify-content-between align-items-center">
            <h5 class="mb-0 font-weight-bold text-dark">
                <i class="fa fa-university text-primary mr-2"></i>Capaian Pembelajaran Lulusan (CPL) Prodi
            </h5>
            <?php if (!empty($nama_prodi_header)): ?>
                <span class="badge badge-light text-dark border px-2 py-1 font-weight-bold small">
                    <i class="fa fa-graduation-cap text-muted mr-1"></i>PRODI: <?= s($nama_prodi_header) ?>
                </span>
            <?php endif; ?>
        </div>

        <div class="table-responsive bg-white rounded">
            <table class="table table-hover align-middle mb-0">
                <thead class="thead-light">
                    <tr class="text-uppercase small" style="letter-spacing: 0.5px;">
                        <th class="py-3 pl-4" style="width: 200px;">Nama CPL</th>
                        <th class="text-left py-3">Deskripsi CPL</th>
                        <th class="text-center py-3" style="width: 200px;">Rata-rata Capaian</th>
                        <th class="text-center py-3" style="width: 100px;">Grade</th>
                        <th class="text-center py-3" style="width: 180px;">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($cpl_data)): ?>
                        <tr>
                            <td colspan="5" class="text-center text-muted py-5 align-middle">
                                <i class="fa fa-ban fa-2x d-block mb-2 text-secondary"></i>
                                Belum ada data pencapaian nilai CPL yang terekam untuk kriteria angkatan ini.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($cpl_data as $cpl): ?>
                            <?php
                            $grade_tampil = !empty($cpl->grade) ? $cpl->grade : '-';
                            $badge_class = 'secondary';

                            switch (strtoupper(trim($grade_tampil))) {
                                case 'A': case 'AB': case 'SB': $badge_class = 'success'; break;
                                case 'B': case 'BC':             $badge_class = 'primary'; break;
                                case 'C':                         $badge_class = 'warning'; break;
                                case 'D': case 'E': case 'K':    $badge_class = 'danger'; break;
                            }

                            $deskripsi_cpl = '-';
                            if (!empty($cpl->deskripsi)) {
                                $deskripsi_cpl = $cpl->deskripsi;
                            } else if (!empty($cpl->cpl_id)) {
                                $deskripsi_cpl = $DB->get_field('local_obe_kompetensi', 'deskripsi', [
                                    'id'   => $cpl->cpl_id, 
                                    'tipe' => 'CPL'
                                ]) ?: '-';
                            }
                            ?>
                            <tr>
                                <td class="pl-4 py-3 align-middle font-weight-bold text-dark" style="font-size: 0.95rem; white-space: nowrap;">
                                    <i class="fa fa-caret-right text-info mr-2"></i><?= s($cpl->nama_cpl) ?>
                                </td>
                                <td class="text-left py-3 align-middle text-dark" style="font-style: normal !important; line-height: 1.5; min-width: 300px; font-size: 0.9rem;">
                                    <?= s(strip_tags($deskripsi_cpl)) ?>
                                </td>
                                <td class="text-center py-3 align-middle font-weight-bold text-dark h5 mb-0">
                                    <?= $format_nilai($cpl->percentage) ?>
                                </td>
                                <td class="text-center py-3 align-middle">
                                    <span class="badge badge-<?= $badge_class ?> px-3 py-2" style="font-size: 0.85rem; font-weight: 600; min-width: 45px;">
                                        <?= s($grade_tampil) ?>
                                    </span>
                                </td>
                                <td class="text-center py-3 align-middle">
                                    <div class="d-flex justify-content-center align-items-center" style="gap: 6px; min-height: 38px;">
                                        <?php
                                        $url_lihat = new moodle_url('/local/obe/views/admin/dashboard.php', [
                                            'halaman'  => 'capaian_pembelajaran_prodi',
                                            'sub'      => 'indikator',
                                            'prodi'    => $prodi_terpilih,
                                            'angkatan' => $angkatan_id,
                                            'cpl_id'   => $cpl->cpl_id,
                                            'sesskey'  => sesskey()
                                        ]);

                                        $url_detail = new moodle_url('/local/obe/views/admin/dashboard.php', [
                                            'halaman'       => 'capaian_pembelajaran_prodi',
                                            'sub'           => 'detail_nilai_cpl',
                                            'prodi'         => $prodi_terpilih,
                                            'angkatan'      => $angkatan_id,
                                            'kompetensi_id' => $cpl->cpl_id,
                                            'cpl_id'        => $cpl->cpl_id,
                                            'tipe'          => 'CPL',
                                            'sesskey'       => sesskey()
                                        ]);
                                        ?>

                                        <a href="<?= $url_detail->out() ?>"
                                           class="btn btn-sm btn-info d-inline-flex align-items-center justify-content-center p-0 rounded-circle shadow-sm"
                                           style="width: 32px; height: 32px; flex-shrink: 0;"
                                           title="Detail Nilai Mahasiswa">
                                            <i class="fa fa-users" style="font-size: 11px;"></i>
                                        </a>

                                        <a href="<?= $url_lihat->out() ?>"
                                           class="btn btn-sm btn-primary d-inline-flex align-items-center justify-content-center p-0 rounded-circle shadow-sm"
                                           style="width: 32px; height: 32px; flex-shrink: 0;"
                                           title="Lihat Rincian Indikator">
                                            <i class="fa fa-search" style="font-size: 11px;"></i>
                                        </a>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>