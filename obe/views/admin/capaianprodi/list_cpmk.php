<?php
// File: local/obe/views/admin/capaianprodi/list_cpmk.php
defined('MOODLE_INTERNAL') || die();

use local_obe\manager\capaian_prodi_manager;
use local_obe\manager\prodi_manager;
use local_obe\manager\skala_nilai_manager;

global $SESSION, $DB, $USER;

// =====================================
// 1. TANGKAP PARAMETER & RETENSI STATE
// =====================================
$prodi_id      = optional_param('prodi', 0, PARAM_INT);
$cpl_id        = optional_param('cpl_id', 0, PARAM_INT);
$indikator_id = optional_param('indikator_id', 0, PARAM_INT);
$angkatan_id   = optional_param('angkatan', 0, PARAM_INT); 

$tahun_akademik = $SESSION->obe_id_tahun_akademik ?? 0;

// =========================================================================
// 2. PROTEKSI SEKURITI SERVER (ANTI-IDOR & INTERACTION SHIELD)
// =========================================================================
$is_kaprodi = prodi_manager::is_kaprodi($USER->id);

if ($is_kaprodi) {
    $prodiaktif = prodi_manager::ambil_prodi_kaprodi($USER->id);
    if (!$prodiaktif) {
        throw new moodle_exception('invalidrecord', 'local_obe', '', null, 'Akun Kaprodi belum terhubung dengan Program Studi.');
    }
    $prodi_id = (int)$prodiaktif->id;
}

if ($angkatan_id > 0) {
    if (!$DB->record_exists('local_obe_angkatan', ['id' => $angkatan_id])) {
        throw new moodle_exception('invalidparameter', 'local_obe', '', null, 'Data Angkatan tidak ditemukan.');
    }
}

if ($prodi_id > 0 && !$is_kaprodi) {
    if (!$DB->record_exists('local_obe_prodi', ['id' => $prodi_id])) {
        throw new moodle_exception('invalidparameter', 'local_obe', '', null, 'Data Program Studi tidak valid.');
    }
}

if ($cpl_id > 0) {
    $cpl_sah = $DB->record_exists('local_obe_kompetensi', ['id' => $cpl_id, 'id_prodi' => $prodi_id, 'tipe' => 'CPL']);
    if (!$cpl_sah) {
        throw new moodle_exception('aksesditolak', 'local_obe', '', null, 'Struktur CPL tidak sesuai dengan Program Studi Anda.');
    }
}

if ($indikator_id > 0) {
    $indikator = $DB->get_record('local_obe_kompetensi', ['id' => $indikator_id, 'id_prodi' => $prodi_id, 'tipe' => 'INDIKATOR'], 'id, parent_id');
    if (!$indikator) {
        throw new moodle_exception('aksesditolak', 'local_obe', '', null, 'Struktur Indikator tidak ditemukan.');
    }
    if ($cpl_id > 0 && (int)$indikator->parent_id !== (int)$cpl_id) {
        throw new moodle_exception('aksesditolak', 'local_obe', '', null, 'Pelanggaran struktur: Indikator tidak cocok dengan CPL Induk.');
    }
}

// =========================================================================
// 3. AMBIL DATA SUMMARY INDUK (PRE-RENDER ANCHOR BLOCK)
// =========================================================================
$indikator_info = null;
if ($indikator_id > 0) {
    $indikator_info = $DB->get_record('local_obe_kompetensi', [
        'id' => $indikator_id,
        'tipe' => 'INDIKATOR'
    ], 'id, nama_kompetensi, deskripsi');
}

$nama_prodi_header = $DB->get_field('local_obe_prodi', 'nama_prodi', ['id' => $prodi_id]);

// =====================================
// 4. TARIK DATA UTAMA (TERFILTER ANGKATAN)
// =====================================
$data = [];
if ($prodi_id > 0 && $indikator_id > 0) {
    $data = capaian_prodi_manager::ambil_capaian_cpmk_prodi(
        $prodi_id,
        $indikator_id,
        $tahun_akademik,
        $angkatan_id
    );
}

// =====================================
// 5. KONSTRUKSI CONTEXTUAL BACK & GRAFIK URL
// =====================================
$url_kembali = new moodle_url('/local/obe/views/admin/dashboard.php', [
    'halaman'  => 'capaian_pembelajaran_prodi',
    'sub'      => 'indikator',
    'prodi'    => $prodi_id,
    'angkatan' => $angkatan_id,
    'cpl_id'   => $cpl_id
]);

$url_grafik = new moodle_url('/local/obe/views/admin/dashboard.php', [
    'halaman'      => 'capaian_pembelajaran_prodi',
    'sub'          => 'grafik_cpmk',
    'prodi'        => $prodi_id,
    'angkatan'     => $angkatan_id,
    'cpl_id'       => $cpl_id,
    'indikator_id' => $indikator_id,
    'sesskey'      => sesskey()
]);

// Fungsi Formatter Nilai Desimal Efisien
$format_nilai = function($nilai, $desimal = 2) {
    if (!is_numeric($nilai)) {
        return $nilai;
    }

    return (floor($nilai) == $nilai)
        ? (int)$nilai
        : rtrim(rtrim(number_format($nilai, $desimal, '.', ''), '0'), '.');
};
?>

<div class="card shadow-sm border-0 mb-4 bg-white rounded">
    <div class="card-body p-4">
        <div class="d-flex justify-content-between align-items-start flex-wrap">
            
            <div style="flex: 1; min-width: 250px;">
                
                <div class="d-flex align-items-center flex-wrap mb-3">
                    <h4 class="font-weight-bold mb-0 text-dark" style="font-size: 1.35rem; color: #000000 !important; letter-spacing: -0.3px;">
                        <i class="fa fa-graduation-cap text-primary mr-2"></i>Capaian CPMK Program Studi
                    </h4>
                    
                    <?php if (!empty($nama_prodi_header)): ?>
                        <span class="badge badge-light border text-muted px-2 py-1 small ml-3 font-weight-medium" style="background: #f8f9fa; border-radius: 6px; color: #212529 !important;">
                            <i class="fa fa-university text-muted mr-1"></i><?= s($nama_prodi_header) ?>
                        </span>
                    <?php endif; ?>
                </div>

                <?php if ($indikator_info): ?>
                    <h6 class="mb-1 text-dark" style="color: #000000 !important; font-weight: 700;">
                        <i class="fa fa-sitemap text-primary mr-2"></i>IK: <?= s($indikator_info->nama_kompetensi) ?>
                    </h6>

                    <div class="text-secondary small ml-4 mb-1" style="color: #495057 !important; line-height: 1.5; max-width: 850px; font-style: normal !important;">
                        <?php if (!empty($indikator_info->deskripsi)): ?>
                            <?= s(strip_tags($indikator_info->deskripsi)) ?>
                        <?php else: ?>
                            <?php 
                            $desc_db = $DB->get_field('local_obe_kompetensi', 'deskripsi', ['id' => $indikator_id]); 
                            echo !empty($desc_db) ? s(strip_tags($desc_db)) : '<span class="text-muted font-italic">Tidak ada deskripsi materi untuk Indikator ini.</span>';
                            ?>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

            </div>

            <div class="ml-3 flex-shrink-0 pt-1 d-flex align-items-center" style="gap: 6px;">
                <a href="<?= $url_grafik->out() ?>" class="btn btn-sm btn-primary font-weight-bold shadow-sm px-3 py-2" style="border-radius: 6px; font-size: 0.8rem;">
                    <i class="fa fa-bar-chart mr-1"></i>Grafik CPMK
                </a>
                <a href="<?= $url_kembali->out() ?>" class="btn btn-sm btn-secondary font-weight-bold shadow-sm px-3 py-2" style="border-radius: 6px; font-size: 0.8rem;">
                    <i class="fa fa-arrow-left mr-1"></i>Kembali ke Indikator
                </a>
            </div>

        </div>
    </div>
</div>

<?php if ($prodi_id == 0 || $indikator_id == 0): ?>
    <div class="alert alert-danger text-center py-4 border-0 shadow-sm" style="border-radius: 8px;">
        <i class="fa fa-exclamation-triangle fa-2x mb-2 d-block"></i>
        <h5 class="font-weight-bold">Parameter Tidak Lengkap</h5>
        <p class="mb-0 small">Gunakan menu navigasi utama dashboard untuk melihat rincian capaian CPMK secara terstruktur.</p>
    </div>
<?php else: ?>
    <div class="card shadow-sm border-0">
        <div class="table-responsive bg-white rounded">
            <table class="table table-hover align-middle mb-0">
                <thead class="thead-light">
                    <tr class="text-uppercase small" style="letter-spacing: 0.5px;">
                        <th class="py-3 pl-4" style="width: 200px;">Nama CPMK</th>
                        <th class="text-left py-3">Deskripsi CPMK</th>
                        <th class="text-center py-3" style="width: 100px;">Bobot</th>
                        <th class="text-center py-3" style="width: 200px;">Rata-Rata Capaian</th>
                        <th class="text-center py-3" style="width: 100px;">Grade</th>
                        <th class="text-center py-3" style="width: 160px;">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($data)): ?>
                        <tr>
                            <td colspan="6" class="text-center py-5 text-muted">
                                <i class="fa fa-ban fa-2x d-block mb-2 text-secondary"></i>
                                Belum ada data pencapaian CPMK prodi yang terekam untuk kriteria angkatan ini.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($data as $cpmk): ?>
                            <?php
                            if (isset($cpmk->grade)) {
                                $grade = $cpmk->grade;
                            } elseif (isset($cpmk->grade_cpmk)) {
                                $grade = $cpmk->grade_cpmk;
                            } else {
                                $grade = skala_nilai_manager::cek_skala($cpmk->percentage, 'CPMK', $prodi_id, $tahun_akademik);
                            }
                            
                            $grade_tampil = !empty($grade) ? strtoupper(trim($grade)) : '-';
                            $badge = 'secondary';
                            
                            switch ($grade_tampil) {
                                case 'A': case 'AB': case 'SB': $badge = 'success'; break;
                                case 'B': case 'BC':             $badge = 'primary'; break;
                                case 'C':                         $badge = 'warning'; break;
                                case 'D': case 'E': case 'K':    $badge = 'danger'; break;
                            }

                            // Logika Fallback mengambil deskripsi CPMK langsung dari database
                            $deskripsi_cpmk = '-';
                            if (!empty($cpmk->deskripsi)) {
                                $deskripsi_cpmk = $cpmk->deskripsi;
                            } else if (!empty($cpmk->cpmk_id)) {
                                $deskripsi_cpmk = $DB->get_field('local_obe_kompetensi', 'deskripsi', [
                                    'id'   => $cpmk->cpmk_id,
                                    'tipe' => 'CPMK'
                                ]) ?: '-';
                            }

                            // URL Detail Nilai Mahasiswa CPMK
                            $url_detail = new moodle_url('/local/obe/views/admin/dashboard.php', [
                                'halaman'       => 'capaian_pembelajaran_prodi',
                                'sub'           => 'detail_nilai_cpmk',
                                'prodi'         => $prodi_id,
                                'angkatan'      => $angkatan_id,
                                'cpl_id'        => $cpl_id,
                                'indikator_id'  => $indikator_id,
                                'kompetensi_id' => $cpmk->cpmk_id ?? $cpmk->id,
                                'tipe'          => 'CPMK',
                                'sesskey'       => sesskey()
                            ]);

                            // URL Rincian Sub CPMK
                            $url_rincian = new moodle_url('/local/obe/views/admin/dashboard.php', [
                                'halaman'      => 'capaian_pembelajaran_prodi',
                                'sub'          => 'subcpmk',
                                'prodi'        => $prodi_id,
                                'angkatan'     => $angkatan_id,
                                'cpl_id'       => $cpl_id,
                                'indikator_id' => $indikator_id,
                                'cpmk_id'      => $cpmk->cpmk_id ?? $cpmk->id
                            ]);
                            ?>
                            <tr>
                                <td class="pl-4 py-3 align-middle font-weight-bold text-dark" style="font-size: 0.95rem; white-space: nowrap;">
                                    <i class="fa fa-caret-right text-info mr-2"></i><?= s($cpmk->nama_cpmk ?? $cpmk->nama) ?>
                                </td>
                                
                                <td class="text-left py-3 align-middle text-dark font-weight-normal" style="line-height: 1.5; min-width: 300px; font-size: 0.9rem;">
                                    <?= s(strip_tags($deskripsi_cpmk)) ?>
                                </td>
                                
                                <td class="text-center py-3 align-middle">
                                    <span class="badge badge-secondary font-weight-bold px-2 py-1" style="font-size: 0.8rem; background-color: #e9ecef; color: #495057; border: 1px solid #ced4da;">
                                        <?= $format_nilai($cpmk->bobot, 0) ?>%
                                    </span>
                                </td>
                                
                                <td class="text-center py-3 align-middle font-weight-bold text-dark h5 mb-0" style="font-size: 1.05rem;">
                                    <?= $format_nilai($cpmk->percentage) ?>
                                </td>
                                
                                <td class="text-center py-3 align-middle">
                                    <span class="badge badge-<?= $badge ?> px-3 py-2" style="font-size: 0.85rem; font-weight: 600; min-width: 45px;">
                                        <?= s($grade_tampil) ?>
                                    </span>
                                </td>
                                
                                <td class="text-center py-3 align-middle">
                                    <div class="d-flex justify-content-center align-items-center" style="gap: 6px;">
                                        
                                        <a href="<?= $url_detail->out() ?>"
                                           class="btn btn-sm btn-info d-inline-flex align-items-center justify-content-center p-0 rounded-circle shadow-sm"
                                           style="width: 32px; height: 32px;"
                                           title="Detail Nilai Mahasiswa">
                                            <i class="fa fa-users" style="font-size: 11px;"></i>
                                        </a>

                                        <a href="<?= $url_rincian->out() ?>"
                                           class="btn btn-sm btn-primary d-inline-flex align-items-center justify-content-center p-0 rounded-circle shadow-sm"
                                           style="width: 32px; height: 32px;"
                                           title="Lihat Rincian Sub-CPMK">
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