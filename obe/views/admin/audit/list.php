<?php
defined('MOODLE_INTERNAL') || die();

global $DB;

// =====================================================================
// PAGINATION
// =====================================================================
$page = optional_param('auditpage', 0, PARAM_INT);

if ($page < 0) {
    $page = 0;
}

$perpage = 15;
$totalrecords = $DB->count_records('local_obe_audit_log');
$totalpages = max(1, ceil($totalrecords / $perpage));

if ($page >= $totalpages) {
    $page = $totalpages - 1;
}

$offset = $page * $perpage;

// =====================================================================
// QUERY DATA
// =====================================================================
$sql = "
    SELECT
        al.*,
        u.firstname,
        u.lastname
    FROM {local_obe_audit_log} al
    LEFT JOIN {user} u ON u.id = al.userid
    ORDER BY al.timecreated DESC
";

$logs = $DB->get_records_sql($sql, [], $offset, $perpage);

// =====================================================================
// LABEL ENTITY
// =====================================================================
$entitylabels = [
    'prodi'          => 'Program Studi',
    'mata_kuliah'    => 'Mata Kuliah',
    'angkatan'       => 'Angkatan',
    'relasi_kelas'   => 'Relasi Kelas',
    'aktivitas_map'  => 'Pemetaan Aktivitas',
    'bobot_relasi'   => 'Bobot Kompetensi',
    'skala_nilai'    => 'Skala Nilai'
];
?>

<div class="card shadow-sm border-0">

    <div class="card-header bg-white py-3 border-bottom">
        <h5 class="mb-0 font-weight-bold text-dark">
            <i class="fa fa-history text-primary mr-2"></i>
            Audit Log Aktivitas OBE
        </h5>
    </div>

    <div class="card-body border-bottom bg-light py-2">
        <div class="row align-items-center">
            <div class="col-md-6">
                <small class="text-muted font-weight-bold">
                    Total Aktivitas: <strong><?= $totalrecords ?></strong>
                </small>
            </div>
            <div class="col-md-6 text-right">
                <small class="text-muted font-weight-bold">
                    Halaman <strong><?= $page + 1 ?></strong> dari <strong><?= $totalpages ?></strong>
                </small>
            </div>
        </div>
    </div>

    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover table-striped align-middle mb-0">
                <thead class="thead-light">
                    <tr class="text-uppercase small" style="letter-spacing: 0.5px;">
                        <th style="width:180px;">Waktu</th>
                        <th style="width:220px;">Pengguna</th>
                        <th style="width:100px;" class="text-center">Aksi</th>
                        <th style="width:180px;">Entitas</th>
                        <th>Informasi Identifikasi Objek</th>
                    </tr>
                </thead>
                <tbody>

                <?php if (empty($logs)): ?>
                    <tr>
                        <td colspan="5" class="text-center py-5">
                            <i class="fa fa-folder-open fa-3x text-secondary mb-3"></i>
                            <h5 class="font-weight-bold text-dark">
                                Tidak ada data pada halaman ini
                            </h5>
                            <p class="text-muted small mb-4">
                                Silakan kembali ke halaman pertama untuk melihat log aktivitas terbaru.
                            </p>
                            <a href="?halaman=audit&auditpage=0" class="btn btn-primary btn-sm font-weight-bold shadow-sm px-3">
                                Kembali ke Halaman Pertama
                            </a>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($logs as $log): ?>
                        <?php
                        $badgeclass = 'secondary';
                        $prefix_info = '';

                        // Menentukan badge sekaligus keterangan penambahan/perubahan/penghapusan
                        switch (strtolower(trim($log->action))) {
                            case 'create': 
                                $badgeclass = 'success'; 
                                $prefix_info = 'Penambahan: ';
                                break; 
                            case 'update': 
                                $badgeclass = 'warning'; 
                                $prefix_info = 'Perubahan: ';
                                break; 
                            case 'delete': 
                                $badgeclass = 'danger'; 
                                $prefix_info = 'Penghapusan: ';
                                break; 
                        }

                        $namauser = trim(($log->firstname ?? '') . ' ' . ($log->lastname ?? ''));

                        if (empty($namauser)) {
                            $namauser = 'User ID: ' . $log->userid;
                        }

                        $entityname = $entitylabels[$log->entity] ?? $log->entity;
                        $objek_detail = 'ID Data ' . $log->entityid;

                        if (!empty($log->details)) {
                            $detail = json_decode($log->details);

                            if ($detail) {
                                switch ($log->entity) {
                                    case 'prodi':
                                        $objek_detail = ($detail->kode_prodi ?? '-') . ' - ' . ($detail->nama_prodi ?? '-');
                                        break;

                                    case 'mata_kuliah':
                                        $objek_detail = $detail->nama_mk ?? ($detail->kode_mk ?? '-');
                                        break;

                                    case 'angkatan':
                                        $objek_detail = $detail->nama_angkatan ?? '-';
                                        break;

                                    case 'relasi_kelas':
                                        $objek_detail = 'Kelas Kursus Terhubung (ID: ' . $log->entityid . ')';
                                        break;

                                    case 'aktivitas_map':
                                        $objek_detail = 'Modul Moodle Mapped (ID: ' . $log->entityid . ')';
                                        break;

                                    case 'bobot_relasi':
                                        $objek_detail = 'Komponen Bobot Kompetensi (ID: ' . $log->entityid . ')';
                                        break;

                                        case 'skala_nilai':
                                        $objek_detail = 'Kriteria Nilai (' . ($detail->tipe_kompetensi ?? '-') . ')';
                                        break;
                                }
                            }
                        }

                        // Menggabungkan keterangan aksi (Penambahan/Perubahan/Penghapusan) dengan objek detail
                        $informasi = $prefix_info . $objek_detail;
                        ?>
                        <tr>
                            <td class="pl-4 py-3 text-dark font-weight-500">
                                <?= userdate($log->timecreated, '%d/%m/%Y %H:%M:%S') ?>
                            </td>
                            <td class="font-weight-bold text-dark">
                                <i class="fa fa-user-circle text-muted mr-1"></i>
                                <?= s($namauser) ?>
                            </td>
                            <td class="text-center">
                                <span class="badge badge-<?= $badgeclass ?> font-weight-bold px-2 py-1" style="font-size: 0.75rem;">
                                    <?= strtoupper(s($log->action)) ?>
                                </span>
                            </td>
                            <td class="text-dark font-weight-bold small">
                                <?= s($entityname) ?>
                            </td>
                            <td class="text-dark">
                                <?= s($informasi) ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>

                </tbody>
            </table>
        </div>
    </div>
</div>

<?php if ($totalpages > 1): ?>
<div class="mt-3">
    <div class="text-center text-muted small mb-2">
        Menampilkan <strong><?= count($logs) ?></strong> data dari <strong><?= $totalrecords ?></strong> data
    </div>
    <div class="text-center">

        <?php if ($page > 0): ?>
            <a class="btn btn-sm btn-outline-primary font-weight-bold" href="?halaman=audit&auditpage=<?= ($page - 1) ?>">
                « Sebelumnya
            </a>
        <?php endif; ?>

        <?php for ($i = 0; $i < $totalpages; $i++): ?>
            <a class="btn btn-sm font-weight-bold <?= ($i == $page) ? 'btn-primary shadow-sm' : 'btn-outline-primary' ?>" href="?halaman=audit&auditpage=<?= $i ?>">
                <?= $i + 1 ?>
            </a>
        <?php endfor; ?>

        <?php if ($page < ($totalpages - 1)): ?>
            <a class="btn btn-sm btn-outline-primary font-weight-bold" href="?halaman=audit&auditpage=<?= ($page + 1) ?>">
                Berikutnya »
            </a>
        <?php endif; ?>

    </div>
</div>
<?php endif; ?>