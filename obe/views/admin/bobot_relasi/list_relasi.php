<?php
// File: local/obe/views/admin/bobot_relasi/view.php
defined('MOODLE_INTERNAL') || die();

use local_obe\manager\kompetensi_manager;
use local_obe\manager\bobot_relasi_manager;
use local_obe\manager\prodi_manager;

global $SESSION, $DB, $OUTPUT;

$courseid       = optional_param('id', 0, PARAM_INT);
$tahun_akademik = $SESSION->obe_id_tahun_akademik ?? 0;

// =========================================================================
// 1. IDENTIFIKASI PERAN & PROTEKSI SERVER (ANTI-IDOR PARAMETER BYPASS)
// =========================================================================
$prodi_aktif = prodi_manager::get_prodi_aktif_user();
if ($prodi_aktif) {
    $prodi_terpilih = (int)$prodi_aktif->id;
} else {
    $prodi_terpilih = optional_param('prodi', 0, PARAM_INT);
}

// =========================================================================
// 2. KONSTRUKSI URL NAVIGASI KEMBALI
// =========================================================================
$url_kembali = new moodle_url('/local/obe/views/admin/dashboard.php', [
    'halaman' => 'manajemen_kompetensi',
    'prodi'   => $prodi_terpilih,
    'id'      => $courseid
]);

$daftar_prodi           = !$prodi_aktif ? kompetensi_manager::ambil_daftar_prodi() : [];
$kompetensi             = ($prodi_terpilih > 0) ? kompetensi_manager::ambil_kompetensi_terurut($prodi_terpilih, $tahun_akademik) : [];
$semua_bobot            = bobot_relasi_manager::get_semua_bobot_by_prodi($prodi_terpilih, $tahun_akademik);
$total_bobot_per_parent = bobot_relasi_manager::get_total_bobot_terisi_by_parent($prodi_terpilih, $tahun_akademik);
$proses_url             = new moodle_url('/local/obe/views/admin/bobot_relasi/proses_relasi.php', ['id' => $courseid]);
?>

<?php if (!$prodi_aktif): ?>
<div class="card mb-4 shadow-sm border-0">
    <div class="card-body py-3">
        <form method="get" class="form-inline m-0">
            <input type="hidden" name="halaman" value="bobot_relasi">
            <label class="mr-2 font-weight-bold text-dark">Pilih Program Studi:</label>
            <select name="prodi" class="form-control" onchange="this.form.submit()">
                <option value="0">-- Pilih Prodi --</option>
                <?php foreach ($daftar_prodi as $p): ?>
                    <option value="<?= $p->id ?>" <?= $prodi_terpilih == $p->id ? 'selected' : '' ?>><?= s($p->nama_prodi) ?></option>
                <?php endforeach; ?>
            </select>
        </form>
    </div>
</div>
<?php endif; ?>

<?php if ($prodi_aktif): ?>
<div class="alert alert-light border shadow-sm mb-4 p-3 d-flex align-items-center" style="border-radius: 8px;">
    <i class="fa fa-university text-primary mr-3 fa-lg"></i>
    <span class="text-dark">Program Studi di bawah naungan Anda: <strong><?= s($prodi_aktif->nama_prodi) ?></strong></span>
</div>
<?php endif; ?>

<?php if ($prodi_terpilih == 0): ?>
    <div class="alert alert-info py-4 text-center border-0 shadow-sm">
        <i class="fa fa-info-circle fa-2x text-info mb-2 d-block"></i>
        <h5 class="font-weight-bold text-dark mb-1">Silakan Pilih Program Studi Terlebih Dahulu</h5>
        <p class="text-muted mb-0 small">Gunakan dropdown filter di atas untuk mengonfigurasi pembobotan relasi kurikulum OBE.</p>
    </div>
<?php else: ?>
    <div class="card mb-4 shadow-sm border-0">
        <div class="card-header d-flex justify-content-between align-items-center bg-light py-3">
            <h5 class="mb-0 font-weight-bold text-dark" style="color: #000000 !important;">
                <i class="fa fa-link text-primary mr-2"></i>Pengaturan Bobot Hierarki Kompetensi
            </h5>
            <a href="<?= $url_kembali->out() ?>" class="btn btn-sm btn-secondary font-weight-bold shadow-sm px-3">
                <i class="fa fa-arrow-left mr-1"></i> Kembali
            </a>
        </div>

        <div class="table-responsive bg-white rounded">
            <table class="table table-bordered table-striped table-hover align-middle mb-0 border">
                <thead class="thead-light">
                    <tr class="text-uppercase small" style="letter-spacing: 0.5px;">
                        <th class="py-3 pl-4" style="width: 40%;">Struktur Kompetensi</th>
                        <th style="width: 150px;" class="text-center">Tipe</th>
                        <th style="width: 30%;">Status Bobot</th>
                        <th class="text-center" style="width: 160px;">Input Bobot</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($kompetensi)): ?>
                        <tr>
                            <td colspan="4" class="text-center py-5 text-muted">
                                <i class="fa fa-exclamation-triangle fa-2x d-block mb-2 text-warning"></i>Data kompetensi belum disinkronisasi.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($kompetensi as $k): ?>
                            <?php
                            $tipe_up = strtoupper(trim($k->tipe));
                            $depth = 0; $badge_class = 'secondary';
                            if ($tipe_up === 'CPL') { $depth = 0; $badge_class = 'primary'; }
                            elseif ($tipe_up === 'INDIKATOR') { $depth = 1; $badge_class = 'warning text-white'; }
                            elseif ($tipe_up === 'CPMK') { $depth = 2; $badge_class = 'info'; }
                            elseif ($tipe_up === 'SUB-CPMK') { $depth = 3; $badge_class = 'secondary'; }

                            // Dikembalikan ke 30px agar lekukan hierarki terlihat jelas
                            $indent = ($depth * 30) . 'px';
                            $parent_id = $k->parent_id ?? 0;

                            $has_child = false;
                            foreach ($kompetensi as $cek) {
                                if ($cek->parent_id == $k->id) {
                                    $has_child = true;
                                    break;
                                }
                            }

                            // Sisa Bobot Presisi Tanpa Pembulatan Paksa
                            $terpakai = $total_bobot_per_parent[$k->id] ?? 0;
                            $sisa = 100 - $terpakai;
                            $sisa_display = (floor($sisa) == $sisa) ? (int)$sisa : rtrim(rtrim(number_format($sisa, 2, '.', ''), '0'), '.');
                            
                            $badge_status = ($sisa < 0) ? 'badge-danger' : (($sisa == 0) ? 'badge-success' : 'badge-light border text-dark');
                            $icon_status  = ($sisa == 0) ? 'fa-check-circle' : (($sisa < 0) ? 'fa-exclamation-triangle' : 'fa-pie-chart');
                            $label_anak   = ($tipe_up === 'CPL') ? 'INDIKATOR' : (($tipe_up === 'INDIKATOR') ? 'CPMK' : 'SUB-CPMK');

                            // Nilai Input Box Presisi Bersih
                            $bobot = '';
                            if (isset($semua_bobot[$k->id])) {
                                $bobot = (floor($semua_bobot[$k->id]) == $semua_bobot[$k->id]) ? (int)$semua_bobot[$k->id] : rtrim(rtrim(number_format($semua_bobot[$k->id], 2, '.', ''), '0'), '.');
                            }
                            ?>
                            <tr class="kompetensi-row" data-id="<?= $k->id ?>" data-parent="<?= $parent_id ?>" <?= $parent_id > 0 ? 'style="display:none;"' : '' ?>>
                                <td style="padding-left: calc(20px + <?= $indent ?>);" class="py-3 text-dark font-weight-bold" style="color: #212529 !important;">
                                    <?php if ($has_child): ?>
                                        <span class="toggle-container text-primary mr-2" style="cursor:pointer">
                                            <i class="fa fa-chevron-right toggle-icon text-info" data-id="<?= $k->id ?>"></i>
                                        </span>
                                    <?php else: ?>
                                        <i class="fa fa-caret-right text-muted mr-2"></i>
                                    <?php endif; ?>
                                    <?= s($k->nama_kompetensi) ?>
                                </td>
                                <td class="py-3 text-center">
                                    <span class="badge badge-<?= $badge_class ?> px-3 py-1.5 font-weight-bold text-uppercase" style="font-size: 0.8rem; min-width: 100px; letter-spacing: 0.5px;">
                                        <?= $tipe_up ?>
                                    </span>
                                </td>
                                <td class="py-3">
                                    <?php if ($tipe_up !== 'SUB-CPMK'): ?>
                                        <span class="badge <?= $badge_status ?> px-2.5 py-1.5 font-weight-bold" style="font-size: 0.85rem; letter-spacing: 0.5px;">
                                            <i class="fa <?= $icon_status ?> mr-1"></i>Sisa Bobot <?= $label_anak ?>: <?= $sisa_display ?>%
                                        </span>
                                    <?php else: ?>
                                        <span class="text-muted small font-italic"><i class="fa fa-flag mr-1"></i>Level Terakhir</span>
                                    <?php endif; ?>
                                </td>
                                <td class="py-3 text-center">
                                    <?php if ($tipe_up !== 'CPL'): ?>
                                        <form action="<?= $proses_url->out() ?>" method="post" class="d-flex justify-content-center align-items-center mb-0">
                                            <input type="hidden" name="sesskey" value="<?= sesskey() ?>">
                                            <input type="hidden" name="aksi" value="simpan_bobot">
                                            <input type="hidden" name="id_kompetensi" value="<?= $k->id ?>">
                                            <input type="hidden" name="prodi" value="<?= $prodi_terpilih ?>">
                                            <div class="input-group input-group-sm" style="max-width: 120px;">
                                                <input type="number" step="0.1" name="bobot" max="100" min="0" value="<?= $bobot ?>" class="form-control text-center font-weight-bold text-dark" placeholder="0" style="height: 31px; border-radius: 4px 0 0 4px; color: #000000 !important; font-size: 0.9rem;">
                                                <div class="input-group-append">
                                                    <button type="submit" class="btn btn-primary btn-sm d-flex align-items-center justify-content-center" title="Simpan Bobot" style="border-radius: 0 4px 4px 0; height: 31px; width: 34px;"><i class="fa fa-save"></i></button>
                                                </div>
                                            </div>
                                        </form>
                                    <?php else: ?>
                                        <span class="text-secondary font-weight-bold small">Root (100%)</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <script>
    document.addEventListener('DOMContentLoaded', function() {
        document.querySelectorAll('.toggle-icon').forEach(icon => {
            icon.addEventListener('click', function() {
                let id = this.dataset.id;
                let buka = this.classList.contains('fa-chevron-right');
                if (buka) {
                    this.classList.remove('fa-chevron-right');
                    this.classList.add('fa-chevron-down');
                    simpanStatusTerbuka(id, true);
                } else {
                    this.classList.remove('fa-chevron-down');
                    this.classList.add('fa-chevron-right');
                    simpanStatusTerbuka(id, false);
                }
                toggleRows(id, buka);
            });
        });

        function toggleRows(parentId, show) {
            let children = document.querySelectorAll(`tr[data-parent="${parentId}"]`);
            children.forEach(row => {
                row.style.display = show ? '' : 'none';
                if (!show) {
                    let id = row.dataset.id;
                    toggleRows(id, false);
                    simpanStatusTerbuka(id, false);
                    let icon = document.querySelector(`.toggle-icon[data-id="${id}"]`);
                    if (icon) {
                        icon.classList.remove('fa-chevron-down');
                        icon.classList.add('fa-chevron-right');
                    }
                }
            });
        }

        /* Status Session Retention Storage */
        function simpanStatusTerbuka(id, isOpen) {
            let dibukaSebelumnya = JSON.parse(localStorage.getItem('obe_bobot_terbuka')) || [];
            if (isOpen) {
                if (!dibukaSebelumnya.includes(id)) { dibukaSebelumnya.push(id); }
            } else {
                dibukaSebelumnya = dibukaSebelumnya.filter(item => item !== id);
            }
            localStorage.setItem('obe_bobot_terbuka', JSON.stringify(dibukaSebelumnya));
        }

        function kembalikanStatusTerbuka() {
            let dibukaSebelumnya = JSON.parse(localStorage.getItem('obe_bobot_terbuka')) || [];
            dibukaSebelumnya.forEach(id => {
                let icon = document.querySelector(`.toggle-icon[data-id="${id}"]`);
                if (icon) {
                    icon.classList.remove('fa-chevron-right');
                    icon.classList.add('fa-chevron-down');
                    let children = document.querySelectorAll(`tr[data-parent="${id}"]`);
                    children.forEach(row => { row.style.display = ''; });
                }
            });
        }
        kembalikanStatusTerbuka();
    });
    </script>
<?php endif; ?>