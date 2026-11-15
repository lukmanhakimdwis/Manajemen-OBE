<?php
// File: local/obe/views/admin/capaianmatakuliah/list_subcpmk.php
defined('MOODLE_INTERNAL') || die();

use local_obe\manager\capaian_matakuliah_manager;
use local_obe\manager\prodi_manager;

global $SESSION, $DB;

// ========================================
// 1. PARAMETER FILTER & STATE RETENTION
// ========================================
$prodi_terpilih  = optional_param('prodi', 0, PARAM_INT);
$matkul_terpilih = optional_param('matkul', 0, PARAM_INT);
$angkatan_id     = optional_param('angkatan', 0, PARAM_INT); 
$cpl_id          = optional_param('cpl_id', 0, PARAM_INT);
$indikator_id    = optional_param('indikator_id', 0, PARAM_INT);
$cpmk_id         = optional_param('cpmk_id', 0, PARAM_INT);
$tahun_akademik  = $SESSION->obe_id_tahun_akademik ?? 0;

// =========================================================================
// 2. IDENTIFIKASI PERAN & PROTEKSI SERVER (ANTI-IDOR HIERARCHY SHIELD)
// =========================================================================
$prodi_aktif = prodi_manager::get_prodi_aktif_user();

if ($prodi_aktif) {
    $prodi_terpilih = (int)$prodi_aktif->id;
}

// Lapis Validasi Otoritas Data & Integritas Pohon Kurikulum
if ($prodi_terpilih > 0) {
    if ($matkul_terpilih > 0) {
        $matkul_sah = $DB->record_exists('local_obe_mata_kuliah', [
            'id' => $matkul_terpilih,
            'id_prodi' => $prodi_terpilih
        ]);
        if (!$matkul_sah) {
            throw new \moodle_exception('aksesditolak', 'local_obe', '', null, 'Mata kuliah tidak sah.');
        }
    }

    if ($cpl_id > 0) {
        $cpl_sah = $DB->record_exists('local_obe_kompetensi', [
            'id' => $cpl_id,
            'id_prodi' => $prodi_terpilih,
            'tipe' => 'CPL'
        ]);
        if (!$cpl_sah) {
            throw new \moodle_exception('aksesditolak', 'local_obe', '', null, 'Struktur CPL tidak sah.');
        }
    }

    if ($indikator_id > 0) {
        $indikator = $DB->get_record('local_obe_kompetensi', [
            'id' => $indikator_id,
            'id_prodi' => $prodi_terpilih,
            'tipe' => 'INDIKATOR'
        ], 'id, parent_id');

        if (!$indikator) {
            throw new \moodle_exception('aksesditolak', 'local_obe');
        }

        if ((int)$indikator->parent_id !== (int)$cpl_id) {
            throw new \moodle_exception('aksesditolak', 'local_obe', '', null, 'Indikator tidak cocok dengan CPL.');
        }
    }

    if ($cpmk_id > 0) {
        $cpmk = $DB->get_record('local_obe_kompetensi', [
            'id' => $cpmk_id,
            'id_prodi' => $prodi_terpilih,
            'tipe' => 'CPMK'
        ], 'id, parent_id');

        if (!$cpmk) {
            throw new \moodle_exception('aksesditolak', 'local_obe');
        }

        if ((int)$cpmk->parent_id !== (int)$indikator_id) {
            throw new \moodle_exception('aksesditolak', 'local_obe', '', null, 'CPMK tidak cocok dengan Indikator.');
        }
    }
}

// Ambil data detail CPMK aktif
$cpmk_record = $DB->get_record('local_obe_kompetensi', ['id' => $cpmk_id, 'tipe' => 'CPMK'], 'id, nama_kompetensi, deskripsi');

// ========================================
// 3. DATA SUB CPMK GABUNGAN
// ========================================
$subcpmk_data = [];
if ($matkul_terpilih > 0 && $cpmk_id > 0) {
    $subcpmk_data = capaian_matakuliah_manager::ambil_capaian_subcpmk_matakuliah(
        $matkul_terpilih,
        $cpmk_id,
        $tahun_akademik,
        $angkatan_id
    );
}

$matkul_info = null;
if ($matkul_terpilih > 0) {
    $matkul_info = $DB->get_record(
        'local_obe_mata_kuliah',
        ['id' => $matkul_terpilih],
        'id, kode_mk, nama_mk'
    );
}

// Formatter dinamis
$format_nilai = function($nilai, $desimal = 2) {
    if (!is_numeric($nilai)) {
        return $nilai;
    }
    return (floor($nilai) == $nilai)
        ? (int)$nilai
        : rtrim(rtrim(number_format($nilai, $desimal, '.', ''), '0'), '.');
};

// ========================================
// 4. KONSTRUKSI NAVIGATION URL BACK & GRAFIK
// ========================================
$url_kembali = new moodle_url('/local/obe/views/admin/dashboard.php', [
    'halaman'      => 'capaian_pembelajaran_matakuliah',
    'sub'          => 'cpmk',
    'prodi'        => $prodi_terpilih,
    'matkul'       => $matkul_terpilih,
    'angkatan'     => $angkatan_id, 
    'cpl_id'       => $cpl_id,
    'indikator_id' => $indikator_id
]);

$url_grafik = new moodle_url('/local/obe/views/admin/dashboard.php', [
    'halaman'      => 'capaian_pembelajaran_matakuliah',
    'sub'          => 'grafik_subcpmk',
    'prodi'        => $prodi_terpilih,
    'matkul'       => $matkul_terpilih,
    'angkatan'     => $angkatan_id,
    'cpl_id'       => $cpl_id,
    'indikator_id' => $indikator_id,
    'cpmk_id'      => $cpmk_id,
    'sesskey'      => sesskey()
]);
?>

<div class="card shadow-sm border-0 mb-4">
    <div class="card-body p-0">
        <div class="p-3 d-flex justify-content-between align-items-center bg-white rounded border-bottom">
            <div>
                <div class="d-flex align-items-center flex-wrap mb-2">
                    <h4 class="font-weight-bold mb-0 text-dark" style="font-size: 1.25rem; color: #000000 !important;">
                        <i class="fa fa-project-diagram text-primary mr-2"></i>Capaian Sub-CPMK Mata Kuliah
                    </h4>

                    <?php if ($prodi_aktif): ?>
                        <span class="badge badge-light text-dark border px-2 py-1 small ml-3" style="color: #212529 !important; font-weight: 500;">
                            <i class="fa fa-university text-muted mr-1"></i><?= s($prodi_aktif->nama_prodi) ?>
                        </span>
                    <?php endif; ?>
                </div>
                 <?php if ($matkul_info): ?>
                    <h6 class="text-dark mb-1.5" style="color: #111111 !important; font-weight: 700;">
                        <i class="fa fa-book text-primary mr-2"></i>Mata Kuliah: <span class="text-secondary" style="color: #495057 !important; font-weight: 600;"><?= s($matkul_info->kode_mk) ?> - <?= s($matkul_info->nama_mk) ?></span>
                    </h6>
                <?php endif; ?>

                <?php if ($cpmk_record): ?>
                    <h6 class="mb-1 text-dark" style="color: #111111 !important; font-weight: 700;">
                        <i class="fa fa-cube text-primary mr-2"></i>CPMK: <?= s($cpmk_record->nama_kompetensi) ?>
                    </h6>

                    <?php if (!empty($cpmk_record->deskripsi)): ?>
                        <div class="text-secondary ml-4 mb-1" style="color: #495057 !important; font-style: normal !important; line-height: 1.45; max-width: 800px;">
                            <?= s(strip_tags($cpmk_record->deskripsi)) ?>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>

            <div class="ml-3 flex-shrink-0 d-flex align-items-center" style="gap: 6px;">
                <a href="<?= $url_grafik->out() ?>" class="btn btn-primary btn-sm font-weight-bold shadow-sm px-3">
                    <i class="fa fa-bar-chart mr-1"></i> Grafik Sub-CPMK
                </a>
                <a href="<?= $url_kembali->out() ?>" class="btn btn-secondary btn-sm font-weight-bold shadow-sm px-3">
                    <i class="fa fa-arrow-left mr-1"></i> Kembali ke CPMK
                </a>
            </div>
        </div>
    </div>
</div>

<?php if ($matkul_terpilih == 0 || $cpmk_id == 0): ?>
    <div class="alert alert-danger text-center py-4 border-0 shadow-sm" style="border-radius: 8px;">
        <i class="fa fa-exclamation-triangle fa-2x mb-2 d-block"></i>
        <h5 class="font-weight-bold">Parameter Tidak Lengkap</h5>
        <p class="mb-0 small">Gunakan menu navigasi utama dashboard untuk melihat rincian capaian Sub CPMK secara terstruktur.</p>
    </div>
<?php else: ?>
    <div class="card shadow-sm border-0">
        <div class="table-responsive bg-white rounded p-3">
            <table class="table table-striped table-hover align-middle mb-0 border">
                <thead class="thead-light">
                    <tr class="text-uppercase small" style="letter-spacing: 0.5px;">
                        <th class="py-3 pl-4" style="width: 25%;">Nama Sub-CPMK</th>
                        <th style="width: 34%;">Deskripsi Materi</th>
                        <th class="text-center" style="width: 11%;">Bobot</th>
                        <th class="text-center" style="width: 12%;">Rata-Rata Capaian</th>
                        <th class="text-center" style="width: 10%;">Grade</th>
                        <th class="text-center" style="width: 10%;">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($subcpmk_data)): ?>
                        <tr>
                            <td colspan="6" class="text-center py-5 text-muted align-middle">
                                <i class="fa fa-ban fa-2x d-block mb-2 text-secondary"></i>
                                Belum ada data capaian Sub-CPMK kelas yang terekam untuk kriteria ini.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($subcpmk_data as $sub): ?>
                            <?php
                            $grade_tampil = !empty($sub->grade) ? $sub->grade : '-';
                            $badge_class = 'secondary';

                            switch (strtoupper(trim($grade_tampil))) {
                                case 'A': case 'AB': case 'SB': $badge_class = 'success'; break;
                                case 'B': case 'BC':             $badge_class = 'primary'; break;
                                case 'C':                         $badge_class = 'warning'; break;
                                case 'D': case 'E': case 'K':    $badge_class = 'danger'; break;
                            }
                            ?>
                            <tr>
                                <td class="pl-4 py-3 align-middle text-break" style="font-size: 0.95rem; color: #212529 !important; font-weight: 600;">
                                    <i class="fa fa-caret-right text-info mr-2"></i><?= s($sub->nama_subcpmk) ?>
                                </td>
                                
                                <td class="py-3 align-middle text-secondary" style="font-style: normal !important; line-height: 1.5; color: #495057 !important; font-size: 0.9rem;">
                                    <?php if (!empty($sub->deskripsi)): ?>
                                        <?= s(strip_tags($sub->deskripsi)) ?>
                                    <?php else: ?>
                                        <?php 
                                        $desc_db = $DB->get_field('local_obe_kompetensi', 'deskripsi', ['id' => $sub->id ?? $sub->subcpmk_id]); 
                                        echo !empty($desc_db) ? s(strip_tags($desc_db)) : '<span class="text-muted font-italic">-</span>';
                                        ?>
                                    <?php endif; ?>
                                </td>
                                
                                <td class="text-center py-3 align-middle">
                                    <span class="badge badge-secondary font-weight-bold px-2 py-1" style="font-size: 0.8rem; background-color: #e9ecef; color: #495057; border: 1px solid #ced4da;">
                                        <?= $format_nilai($sub->bobot) ?>%
                                    </span>
                                </td>
                                
                                <td class="text-center py-3 align-middle">
                                    <span class="h5 font-weight-bold mb-0" style="color: #000000 !important; font-size: 1.05rem;">
                                        <?= $format_nilai($sub->percentage) ?>
                                    </span>
                                </td>
                                
                                <td class="text-center py-3 align-middle">
                                    <span class="badge badge-<?= $badge_class ?> px-3 py-1.5" style="font-size: 0.85rem; font-weight: 600; min-width: 50px; letter-spacing: 0.5px; border-radius: 4px;">
                                        <?= s($grade_tampil) ?>
                                    </span>
                                </td>

                                <td class="text-center py-3 align-middle">
                                    <div class="d-flex justify-content-center align-items-center" style="gap:4px; min-height: 38px;">
                                        <?php
                                        $url_detail = new moodle_url('/local/obe/views/admin/dashboard.php', [
                                            'halaman'       => 'capaian_pembelajaran_matakuliah',
                                            'sub'           => 'detail_nilai_subcpmk',
                                            'prodi'         => $prodi_terpilih,
                                            'matkul'        => $matkul_terpilih,
                                            'angkatan'      => $angkatan_id,
                                            'cpl_id'        => $cpl_id,
                                            'indikator_id'  => $indikator_id,
                                            'cpmk_id'       => $cpmk_id,
                                            'kompetensi_id' => $sub->subcpmk_id ?? $sub->id,
                                            'tipe'          => 'SUB-CPMK',
                                            'sesskey'       => sesskey()
                                        ]);
                                        ?>
                                        <a href="<?= $url_detail->out() ?>"
                                           class="btn btn-sm btn-info d-inline-flex align-items-center justify-content-center p-0 rounded-circle"
                                           style="width:32px; height:32px; flex-shrink: 0;"
                                           title="Detail Nilai Mahasiswa">
                                            <i class="fa fa-users" style="font-size:11px;"></i>
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