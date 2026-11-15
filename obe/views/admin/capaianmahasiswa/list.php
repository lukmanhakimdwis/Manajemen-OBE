<?php
defined('MOODLE_INTERNAL') || die();

use local_obe\manager\prodi_manager;
use local_obe\manager\capaian_mahasiswa_manager;

global $DB, $SESSION;

$prodiid = optional_param('prodiid', 0, PARAM_INT);
$angkatanid = optional_param('angkatanid', 0, PARAM_INT);
$userid = optional_param('userid', 0, PARAM_INT);

// 1. Dukungan Fitur Kaprodi: Otomatis mengunci prodi jika user adalah Kaprodi
$prodi_aktif = prodi_manager::get_prodi_aktif_user();

if ($prodi_aktif) {
    $prodiid = (int)$prodi_aktif->id;
    $prodis = [$prodi_aktif->id => $prodi_aktif];
} else {
    $prodis = prodi_manager::ambil_semua_prodi();
}

// 2. Filter angkatan berdasarkan id_prodi pilihan
$angkatans = [];
if ($prodiid) {
    $angkatans = $DB->get_records(
        'local_obe_angkatan',
        ['id_prodi' => $prodiid],
        'nama_angkatan DESC', // Diurutkan dari yang terbaru
        'id, nama_angkatan'
    );
}

$mahasiswa = [];
$hasilcpl = [];

// 3. PERBAIKAN QUERY MUTLAK: Mengunci Role standar Mahasiswa & Membuang akun Dosen/Staf internal
if ($prodiid && $angkatanid) {
    $userfieldsapi = \core_user\fields::for_name();
    $namefields = $userfieldsapi->get_sql('u', false, '', '', false)->selects;

    $raw_mahasiswa = $DB->get_records_sql("
        SELECT DISTINCT
            u.id,
            u.username,
            $namefields
        FROM {user} u
        JOIN {user_enrolments} ue ON ue.userid = u.id
        JOIN {enrol} e ON e.id = ue.enrolid
        JOIN {course} c ON c.id = e.courseid
        JOIN {context} ctx ON ctx.instanceid = c.id AND ctx.contextlevel = :contextlevel
        JOIN {role_assignments} ra ON ra.userid = u.id AND ra.contextid = ctx.id
        JOIN {role} r ON r.id = ra.roleid
        JOIN {local_obe_relasi_kelas} rk ON rk.id_kursus_moodle = c.id
        JOIN {local_obe_mata_kuliah} mk ON mk.id = rk.id_mata_kuliah
        WHERE mk.id_prodi = :prodi
        AND rk.id_angkatan = :angkatan
        AND r.shortname = :studentshortname
        AND u.deleted = 0
        AND u.suspended = 0
        ORDER BY u.firstname ASC
    ", [
        'contextlevel'     => CONTEXT_COURSE,
        'studentshortname' => 'student', // Filter akurat berbasis shortname archetype Student Moodle
        'prodi'            => $prodiid,
        'angkatan'         => $angkatanid
    ]);

    if (!empty($raw_mahasiswa)) {
        foreach ($raw_mahasiswa as $key => $mhs) {
            if (is_siteadmin($mhs->id)) {
                continue;
            }
            $mahasiswa[$key] = $mhs;
        }
    }
}

// 4. Eksekusi penarikan pohon nilai OBE Mahasiswa jika form disubmit
if ($userid && $prodiid) {
    $hasilcpl = capaian_mahasiswa_manager::ambil_trace_cpl_mahasiswa(
        $userid,
        $prodiid
    );
}

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

<div class="card mb-4 shadow-sm border-0">
    <div class="card-header bg-white py-3 border-bottom">
        <h5 class="mb-0 font-weight-bold text-dark">
            <i class="fa fa-filter text-primary mr-2"></i>Parameter Analisis Capaian
        </h5>
    </div>
    <div class="card-body">
        <form method="get" class="row m-0 align-items-end">
            <input type="hidden" name="halaman" value="capaian_pembelajaran_mahasiswa">

            <?php if (!$prodi_aktif): ?>
            <div class="col-md-4 mb-3">
                <label class="form-label font-weight-bold text-dark small">Program Studi</label>
                <select name="prodiid" class="form-control form-control-sm custom-select w-100" onchange="this.form.submit()">
                    <option value="0">-- Semua Prodi --</option>
                    <?php foreach ($prodis as $prodi): ?>
                        <option value="<?= $prodi->id ?>" <?= $prodiid == $prodi->id ? 'selected' : '' ?>>
                            <?= format_string($prodi->nama_prodi) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>

            <div class="col-md-4 mb-3">
                <label class="form-label font-weight-bold text-dark small">Angkatan</label>
                <select name="angkatanid" class="form-control form-control-sm custom-select w-100" onchange="this.form.submit()">
                    <option value="0">-- Semua Angkatan --</option>
                    <?php foreach ($angkatans as $angkatan): ?>
                        <option value="<?= $angkatan->id ?>" <?= $angkatanid == $angkatan->id ? 'selected' : '' ?>>
                            <?= format_string($angkatan->nama_angkatan) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-md-4 mb-3">
                <label class="form-label font-weight-bold text-dark small">Mahasiswa</label>
                <select name="userid" class="form-control form-control-sm custom-select w-100" onchange="this.form.submit()">
                    <option value="0">-- Cari nama mahasiswa... --</option>
                    <?php foreach ($mahasiswa as $mhs): ?>
                        <option value="<?= $mhs->id ?>" <?= $userid == $mhs->id ? 'selected' : '' ?>>
                            <?= fullname($mhs) ?> (<?= s($mhs->username) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </form>
    </div>
</div>

<?php if ($userid && !empty($hasilcpl)): ?>
    <div class="card shadow-sm border-0 mt-4">
       <div class="card-header bg-white py-3 border-bottom">
    <div class="d-flex justify-content-between align-items-center">

        <h5 class="mb-0 font-weight-bold text-dark">
            <i class="fa fa-sitemap text-primary mr-2"></i>
            Struktur  Capaian OBE Mahasiswa
        </h5>

        <a href="<?= new moodle_url('/local/obe/export/export_capaian_mahasiswa_csv.php', [
                'userid' => $userid,
                'prodi'  => $prodiid,
                'tahun'  => $SESSION->obe_id_tahun_akademik,
                'sesskey'=> sesskey()
            ]) ?>"
           class="btn btn-success btn-sm">

            <i class="fa fa-download mr-1"></i>
            Export CSV
        </a>

    </div>
</div>
        <div class="card-body bg-white px-4">
            <div class="accordion" id="accordionCPL">
                
                <?php foreach ($hasilcpl as $index_cpl => $cpl): ?>
                    <?php $cpl_dom_id = "cpl_" . ($cpl->id ?? 0) . "_" . $index_cpl; ?>
                    <div class="card border-light mb-4 shadow-sm">
                        
                        <div class="card-header bg-light py-3" id="heading<?= $cpl_dom_id ?>">
                            <div class="d-flex justify-content-between align-items-center">
                                <div class="text-dark p-0" data-toggle="collapse" data-target="#collapse<?= $cpl_dom_id ?>" style="cursor: pointer;">
                                    <div class="font-weight-bold mb-1" style="font-size: 1.05rem; color: #212529;">
                                        <i class="fa fa-chevron-down text-muted mr-2" style="font-size: 0.85rem;"></i>
                                        <?= s($cpl->nama ?? $cpl->kode_cpl ?? $cpl->nama_cpl ?? 'CPL') ?>
                                    </div>
                                    <div class="ml-4" style="font-size: 0.9rem; font-weight: normal !important; color: #6c757d; line-height: 1.5;">
                                        <?= format_string($cpl->deskripsi ?? '-') ?>
                                    </div>
                                </div>
                                <span class="badge badge-primary font-weight-bold px-3 py-2 ml-3 shadow-sm flex-shrink-0" style="font-size: 0.85rem;">
                                    CPL: <?= isset($cpl->nilai) ? $format_nilai($cpl->nilai) : '0' ?> (<?= s($cpl->grade ?? '-') ?>)
                                </span>
                            </div>
                        </div>

                        <div id="collapse<?= $cpl_dom_id ?>" class="collapse show" aria-labelledby="heading<?= $cpl_dom_id ?>">
                            <div class="card-body pl-4 border-left ml-4 py-3" style="border-left: 2px solid #e9ecef !important;">
                                
                                <?php if (!empty($cpl->indikator_list)): ?>
                                    <?php foreach ($cpl->indikator_list as $index_ik => $ik): ?>
                                        <?php $ik_dom_id = "ik_" . ($ik->id ?? 0) . "_" . $index_ik; ?>
                                        <div class="card border-0 mb-4 bg-transparent">
                                            
                                            <div class="card-header bg-white pl-0 border-bottom-0 py-2">
                                                <div class="d-flex justify-content-between align-items-start">
                                                    <div class="p-0" data-toggle="collapse" data-target="#collapse<?= $ik_dom_id ?>" style="cursor: pointer;">
                                                        <div class="font-weight-bold mb-1" style="font-size: 0.95rem; color: #212529;">
                                                            <i class="fa fa-chevron-down text-muted mr-2" style="font-size: 0.75rem;"></i>
                                                            <span class="text-info">Indikator:</span> <?= s($ik->nama ?? $ik->kode_indikator ?? '-') ?>
                                                        </div>
                                                        <div class="ml-4" style="font-size: 0.9rem; font-weight: normal !important; color: #6c757d; line-height: 1.5;">
                                                            <?= format_string($ik->deskripsi ?? '-') ?>
                                                        </div>
                                                    </div>
                                                    <span class="badge badge-info text-white font-weight-bold px-2 py-1 ml-3 shadow-sm flex-shrink-0" style="font-size: 0.8rem;">
                                                        IK: <?= isset($ik->nilai) ? $format_nilai($ik->nilai) : '0' ?> (<?= s($ik->grade ?? '-') ?>)
                                                    </span>
                                                </div>
                                            </div>

                                            <div id="collapse<?= $ik_dom_id ?>" class="collapse show pl-4 border-left ml-2" style="border-left: 2px dashed #dee2e6 !important;">
                                                
                                                <?php if (!empty($ik->cpmk_list)): ?>
                                                    <?php foreach ($ik->cpmk_list as $index_cpmk => $cpmk): ?>
                                                        <?php $cpmk_dom_id = "cpmk_" . ($cpmk->id ?? 0) . "_" . $index_cpmk; ?>
                                                        <div class="mb-4 py-1">
                                                            
                                                            <div class="d-flex justify-content-between align-items-center mb-3 p-2 rounded shadow-sm" style="background-color: #f1f3f5; border: 1px solid #dee2e6;">
                                                                <span class="font-weight-bold pl-2" style="font-size: 0.9rem; color: #212529 !important;">
                                                                    <i class="fa fa-tags text-warning mr-2"></i> 
                                                                    <span>CPMK: <?= s($cpmk->nama ?? $cpmk->kode_cpmk ?? '-') ?></span>
                                                                    <?php if(!empty($cpmk->deskripsi) && $cpmk->deskripsi !== '-'): ?>
                                                                        <span class="small" style="font-weight: normal; color: #6c757d;"> - <?= format_string($cpmk->deskripsi) ?></span>
                                                                    <?php endif; ?>
                                                                </span>
                                                                <span class="badge badge-warning text-dark font-weight-bold px-2 py-1 shadow-sm" style="font-size: 0.8rem;">
                                                                    CPMK: <?= isset($cpmk->nilai) ? $format_nilai($cpmk->nilai) : '0' ?> (<?= s($cpmk->grade ?? '-') ?>)
                                                                </span>
                                                            </div>

                                                            <?php if (!empty($cpmk->subcpmk_list)): ?>
                                                                <div class="pl-3">
                                                                    <?php foreach ($cpmk->subcpmk_list as $sub): ?>
                                                                        <div class="card mb-3 shadow-sm border-0" style="border-left: 4px solid #007bff !important; background-color: #fafafa;">
                                                                            <div class="card-body py-3">
                                                                                
                                                                                <div class="d-flex justify-content-between align-items-center">
                                                                                    <div>
                                                                                        <i class="fa fa-code-fork text-primary mr-2"></i>
                                                                                        <strong class="text-dark" style="font-size: 0.9rem;"><?= s($sub->nama ?? $sub->kode_subcpmk ?? '-') ?></strong>
                                                                                        <?php if (!empty($sub->deskripsi)): ?>
                                                                                            <br><small class="ml-4 d-block mt-1" style="font-size: 0.85rem; font-weight: normal !important; color: #6c757d; line-height: 1.4;"><?= format_string($sub->deskripsi) ?></small>
                                                                                        <?php endif; ?>
                                                                                    </div>
                                                                                    <div class="text-right flex-shrink-0 ml-3">
                                                                                        <span class="badge badge-dark font-weight-bold px-2 py-1" style="font-size: 0.75rem; background-color: #495057;">Nilai: <?= $format_nilai($sub->nilai ?? 0) ?></span>
                                                                                        <span class="badge badge-secondary font-weight-bold px-2 py-1" style="font-size: 0.75rem;"><?= s($sub->grade ?? '-') ?></span>
                                                                                    </div>
                                                                                </div>

                                                                                <?php if (!empty($sub->matakuliah_pendukung)): ?>
                                                                                    <div class="mt-3 ml-4">
                                                                                        <div class="text-muted font-weight-bold mb-2 small text-uppercase" style="letter-spacing: 0.5px; font-size: 0.7rem;">Mata Kuliah Pendukung</div>
                                                                                        <?php foreach ($sub->matakuliah_pendukung as $mk): ?>
                                                                                            <div class="card mb-2 border-0 bg-white shadow-none" style="border: 1px solid #e9ecef !important;">
                                                                                                <div class="card-body py-2 px-3">
                                                                                                    <div class="row align-items-center m-0">
                                                                                                        <div class="col-md-6 p-0 small text-dark font-weight-bold">
                                                                                                            <i class="fa fa-book text-muted mr-2"></i><?= s($mk->nama_mk ?? '-') ?>
                                                                                                        </div>
                                                                                                        <div class="col-md-3 p-0 text-center small text-muted">
                                                                                                            Nilai Kelas: <strong class="text-dark"><?= isset($mk->nilai) ? $format_nilai($mk->nilai) : '0' ?></strong>
                                                                                                        </div>
                                                                                                        <div class="col-md-3 p-0 text-right">
                                                                                                            <span class="badge badge-success px-2 py-1 font-weight-bold" style="font-size: 0.7rem; min-width: 30px; text-align: center;"><?= s($mk->grade ?? '-') ?></span>
                                                                                                        </div>
                                                                                                    </div>
                                                                                                </div>
                                                                                            </div>
                                                                                        <?php endforeach; ?>
                                                                                    </div>
                                                                                <?php else: ?>
                                                                                    <div class="alert alert-light mt-3 mb-0 py-2 px-3 small text-muted italic"><i class="fa fa-info-circle mr-1"></i>Belum ada mata kuliah pendukung yang terpetakan.</div>
                                                                                <?php endif; ?>

                                                                            </div>
                                                                        </div>
                                                                    <?php endforeach; ?>
                                                                </div>
                                                            <?php endif; ?>

                                                        </div>
                                                    <?php endforeach; ?>
                                                <?php endif; ?>
                                                
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                                
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>

            </div>
        </div>
    </div>
<?php elseif ($userid && empty($hasilcpl)): ?>
    <div class="card shadow-sm border-0 mt-4">
        <div class="card-body text-center text-muted py-5">
            <i class="fa fa-inbox fa-2x d-block mb-2 text-secondary"></i>
            Data capaian OBE untuk mahasiswa ini belum tersedia atau mata kuliah pendukung belum dikonfigurasi.
        </div>
    </div>
<?php else: ?>
    <div class="card shadow-sm border-0 mt-4">
        <div class="card-body text-center text-muted py-5">
            <i class="fa fa-user fa-2x d-block mb-2 text-primary"></i>
            <h5 class="font-weight-bold text-dark mb-1">Silakan Pilih Nama Mahasiswa Terlebih Dahulu</h5>
            <p class="text-muted mb-0 small">Gunakan dropdown filter di atas untuk menganalisis dan meninjau bagan pohon capaian OBE individu.</p>
        </div>
    </div>
<?php endif; ?>