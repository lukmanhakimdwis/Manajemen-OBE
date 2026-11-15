<?php
defined('MOODLE_INTERNAL') || die();

use local_obe\manager\aktivitasnilai_manager;

global $SESSION, $DB;

// Mengambil seluruh data pemetaan aktivitas nilai untuk course ini
$daftar_pemetaan = aktivitasnilai_manager::ambil_semua_pemetaan($courseid);
$usage_data = aktivitasnilai_manager::get_total_bobot_usage($courseid);
?>

<div class="card shadow-sm border-0 mt-3">
    <div class="card-header bg-primary text-white py-3 shadow-sm">
        <h5 class="mb-0 text-white font-weight-bold">
            <i class="fa fa-list-alt mr-2"></i>Daftar Pemetaan Aktivitas Penilaian
        </h5>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive bg-white rounded p-3">
            <table class="table table-hover align-middle mb-0">
                <thead class="thead-light">
                    <tr class="text-uppercase small" style="letter-spacing: 0.5px;">
                        <th style="width: 40%;" class="py-3 pl-4">Aktivitas Moodle</th>
                        <th style="width: 30%;">Target Sub-CPMK</th>
                        <th style="width: 15%;" class="text-center">Bobot</th>
                        <th style="width: 15%;" class="text-center">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($daftar_pemetaan as $act): ?>
                    <?php 
                        $sub_id = $act->sub_cpmk_id;
                        $terpakai = isset($usage_data[$sub_id]) ? (float)$usage_data[$sub_id] : 0;
                        $terpakai_percent = ($terpakai <= 1 && $terpakai > 0) ? $terpakai * 100 : $terpakai;
                        $sisa = 100.00 - $terpakai_percent;
                        // Format tampilan persen
                        $sisa_display = (floor($sisa) == $sisa)
                            ? (int)$sisa
                            : rtrim(rtrim(number_format($sisa, 2, '.', ''), '0'), '.');

                        // Skema warna dinamis 3 level untuk sisa kapasitas bobot (Hijau / Kuning / Merah)
                        if ($sisa >= 50.00) {
                            $text_color_class = 'text-success';
                            $icon_class = 'fa-check-circle';
                        } elseif ($sisa >= 25.00 && $sisa < 50.00) {
                            $text_color_class = 'text-warning';
                            $icon_class = 'fa-pie-chart';
                        } else {
                            $text_color_class = 'text-danger font-weight-bold';
                            $icon_class = 'fa-exclamation-triangle';
                        }
                    ?>
                    <tr>
                        <td class="pl-4 py-3">
                            <div class="font-weight-bold text-dark mb-1" style="font-size: 0.95rem;">
                                <i class="fa fa-file-text-o text-muted mr-2"></i><?= s($act->name) ?>
                            </div>
                            <span class="badge badge-light text-muted border px-2 py-1" style="font-size: 0.7rem; font-weight: 600;">
                                <i class="fa fa-cube mr-1"></i><?= strtoupper(s($act->modulename)) ?>
                            </span>
                        </td>
                        
                        <td>
                            <?php if ($sub_id): ?>
                                <div class="font-weight-bold text-dark mb-1" style="font-size: 0.95rem;">
                                    <?= isset($act->nama_sub_cpmk) ? s($act->nama_sub_cpmk) : 'ID: ' . $sub_id ?>
                                </div>
                                <small class="<?= $text_color_class ?> d-block mt-1">
                                    <i class=""></i>Sisa bobot: <?= $sisa_display ?>%
                                </small>
                            <?php else: ?>
                                <span class="text-muted small font-italic">
                                    <i class="fa fa-chain-broken mr-1 text-secondary"></i>Belum memiliki target Sub-CPMK
                                </span>
                            <?php endif; ?>
                        </td>
                        
                        <td class="text-center py-3">
                            <?php 
                            if ($sub_id) {
                                $bobot_display = $act->bobot_aktivitas;

                            $bobot_text = (floor($bobot_display) == $bobot_display)
                                ? (int)$bobot_display
                                : rtrim(rtrim(number_format($bobot_display, 2, '.', ''), '0'), '.');

                            echo '<span class="badge badge-secondary px-3 py-1.5 font-weight-bold text-dark border bg-light" style="font-size: 0.85rem;">'
                                . $bobot_text
                                . '%</span>';
                            } else {
                                echo '<span class="text-muted font-italic small">-</span>';
                            }
                            ?>
                        </td>
                        
                        <td class="text-center py-3">
                            <?php if (!empty($act->map_id)): ?>
                                <div class="btn-group shadow-sm" role="group">
                                    <a href="?id=<?= $courseid ?>&halaman=aktivitas_nilai&aksi=edit&map_id=<?= $act->map_id ?>" 
                                       class="btn btn-sm btn-light text-primary border" title="Edit Pemetaan"><i class="fa fa-edit"></i></a>
                                    <a href="aktivitas_nilai/proses_aktivitasnilai.php?courseid=<?= $courseid ?>&aksi=hapus&id=<?= $act->map_id ?>&sesskey=<?= sesskey() ?>" 
                                       class="btn btn-sm btn-light text-danger border" title="Hapus Pemetaan" onclick="return confirm('Apakah Anda yakin ingin menghapus pemetaan aktivitas kurikulum ini?')"><i class="fa fa-trash"></i></a>
                                </div>
                            <?php else: ?>
                                <a href="?id=<?= $courseid ?>&halaman=aktivitas_nilai&aksi=tambah&cmid=<?= $act->cmid ?>" 
                                   class="btn btn-sm btn-primary font-weight-bold shadow-sm px-3">
                                    <i class="fa fa-chain mr-1"></i>Petakan
                                </a>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>