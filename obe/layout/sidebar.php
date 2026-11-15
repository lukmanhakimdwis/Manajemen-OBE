<?php
defined('MOODLE_INTERNAL') || die();

use local_obe\manager\prodi_manager;

global $PAGE, $COURSE, $OUTPUT, $DB, $USER;

// =====================================================
// IDENTIFIKASI HALAMAN
// =====================================================
$halaman_sekarang = optional_param('halaman', '', PARAM_ALPHAEXT);

// =====================================================
// COURSE ID KHUSUS
// Jangan gunakan parameter generic "id"
// =====================================================
$course_id = optional_param(
    'id_course',
    optional_param('id', 0, PARAM_INT),
    PARAM_INT
);

// =====================================================
// CONTEXT & ROLE EVALUATION
// =====================================================
$sys_context = context_system::instance();
$course_context = null;

if ($course_id > 0 && $DB->record_exists('course', ['id' => $course_id])) {
    $course_context = context_course::instance($course_id);
}

$currentpath = $PAGE->url->get_path();

$is_admin_path = (strpos($currentpath, '/local/obe/views/admin/') !== false);
$is_dosen_path = (strpos($currentpath, '/local/obe/views/dosen/') !== false);

// Proteksi Tambahan: Ambil status riil peran Kaprodi dari manager
$is_kaprodi = prodi_manager::is_kaprodi($USER->id);
?>

<style>
    .obe-sidebar {
        border-radius: 12px;
        overflow: hidden;
        background: #ffffff;
    }

    .obe-sidebar .menu-header {
        background: #f8f9fa;
        color: #6c757d;
        font-size: 12px;
        font-weight: 700;
        letter-spacing: 0.5px;
        padding: 12px 18px;
        border-bottom: 1px solid #e9ecef;
    }

    .obe-sidebar .list-group-item {
        border: none;
        padding: 14px 18px;
        font-weight: 500;
        color: #2d3436;
        transition: all 0.2s ease;
        background: #ffffff;
    }

    .obe-sidebar .list-group-item:hover {
        background: #f1f5ff;
        color: #0062cc;
        transform: translateX(3px);
    }

    .obe-sidebar .list-group-item i.fa {
        width: 20px;
        color: #6c757d;
        transition: all 0.2s ease;
    }

    .obe-sidebar .list-group-item:hover i.fa {
        color: #0062cc;
    }

    .obe-sidebar .list-group-item.active,
    .obe-sidebar .list-group-item.active:hover,
    .obe-sidebar .list-group-item.active:focus {
        background: #0062cc !important;
        color: #ffffff !important;
        border-left: 4px solid #004a99;
        font-weight: 600;
    }

    .obe-sidebar .list-group-item.active span {
        color: #ffffff !important;
    }

    .obe-sidebar .list-group-item.active i.fa {
        color: #ffffff !important;
    }

    .obe-sidebar .list-group-item.active:active,
    .obe-sidebar .list-group-item.active:visited {
        background: #0062cc !important;
        color: #ffffff !important;
    }

    .obe-sidebar.shadow-sm {
        box-shadow: 0 4px 12px rgba(0,0,0,0.08) !important;
    }
</style>

<div class="list-group list-group-flush shadow-sm border obe-sidebar">

    <?php if ($is_admin_path): ?>

        <div class="menu-header">
            <?= $is_kaprodi ? 'KAPRODI DASHBOARD' : 'ADMINISTRATOR' ?>
        </div>

        <?php
        // Pembentukan struktur menu dinamis berbasis hak eksklusif admin murni
     // TULIS KODE INI SEBAGAI GANTINYA:
$menu_admin = []; // Membuat array kosong terlebih dahulu

if (!$is_kaprodi) {
    // Menu Tahun Akademik dimasukkan ke sini agar HANYA Admin Murni yang bisa lihat
    $menu_admin[] = [
        'halaman' => 'tahun_akademik', 
        'icon'    => 'fa-calendar', 
        'label'   => 'Tahun Akademik'
    ];
    // Menu Kelola Prodi juga hanya untuk Admin Murni
    $menu_admin[] = [
        'halaman' => 'kelola_prodi',
        'icon'    => 'fa-university', 
        'label'   => 'Kelola Prodi'
    ];
}

        // Satukan sisa menu kurikulum OBE yang boleh diakses bersama oleh Admin maupun Kaprodi
        $menu_admin = array_merge($menu_admin, [
            ['halaman' => 'kelola_mk', 'icon' => 'fa-book', 'label' => 'Kelola Mata Kuliah'],
            ['halaman' => 'kelola_angkatan', 'icon' => 'fa-users', 'label' => 'Kelola Angkatan'],
            ['halaman' => 'kelola_relasi', 'icon' => 'fa-network-wired', 'label' => 'Kelola Relasi Kelas'],
            ['halaman' => 'skala_nilai', 'icon' => 'fa-graduation-cap', 'label' => 'Skala Nilai'],
            ['halaman' => 'kelola_framework', 'icon' => 'fa-link', 'label' => 'Hubungkan Kompetensi'],
            ['halaman' => 'manajemen_kompetensi', 'icon' => 'fa-cogs', 'label' => 'Manajemen Kompetensi'],
            ['halaman' => 'capaian_pembelajaran_kelas', 'icon' => 'fa-chart-line', 'label' => 'Capaian Pembelajaran Kelas'],
            ['halaman' => 'capaian_pembelajaran_matakuliah', 'icon' => 'fa-chart-line', 'label' => 'Capaian Pembelajaran Mata Kuliah'],
            ['halaman' => 'capaian_pembelajaran_prodi', 'icon' => 'fa-graduation-cap', 'label' => 'Capaian Pembelajaran Prodi'],
            ['halaman' => 'capaian_pembelajaran_mahasiswa','icon'    => 'fa-user-graduate','label'   => 'Capaian Pembelajaran Mahasiswa'],
            
        ]);
        // Menu khusus Administrator OBE
        if (!$is_kaprodi) {
            $menu_admin[] = [ 'halaman' => 'audit','icon'    => 'fa-history','label'   => 'Audit'
            ];
        }

        foreach ($menu_admin as $item):
            $url_item = new moodle_url('/local/obe/views/admin/dashboard.php', [
                'halaman' => $item['halaman']
            ]);

            $aktif = ($halaman_sekarang === $item['halaman']) ? 'active' : '';
        ?>
            <a href="<?= $url_item->out(); ?>" class="list-group-item list-group-item-action <?= $aktif; ?>">
                <i class="fa <?= $item['icon']; ?> fa-fw mr-2"></i>
                <span><?= $item['label']; ?></span>
            </a>
        <?php endforeach; ?>

    <?php endif; ?>

    <?php if ($is_dosen_path): ?>

        <div class="menu-header">
            DOSEN
        </div>

        <?php
        $menu_dosen = [
            ['halaman' => 'aktivitas_nilai', 'icon' => 'fa-tasks',     'label' => 'Pemetaan Aktivitas'],
            ['halaman' => 'laporan',         'icon' => 'fa-chart-bar', 'label' => 'Capaian Kelas'],
        ];

        foreach ($menu_dosen as $item):
            $url_item = new moodle_url('/local/obe/views/dosen/dashboard.php', [
                'id_course' => $course_id,
                'halaman'   => $item['halaman'],
            ]);

            $aktif = ($halaman_sekarang === $item['halaman']) ? 'active' : '';
        ?>
            <a href="<?= $url_item->out(); ?>" class="list-group-item list-group-item-action <?= $aktif; ?>">
                <i class="fa <?= $item['icon']; ?> fa-fw mr-2"></i>
                <span><?= $item['label']; ?></span>
            </a>
        <?php endforeach; ?>

    <?php endif; ?>

    <?php if (!$is_admin_path && !$is_dosen_path): ?>
        <div class="list-group-item text-muted small py-3 text-center">
            <i class="fa fa-lock mr-2 text-danger"></i>Tidak ada akses menu.
        </div>
    <?php endif; ?>

</div>