<?php
namespace local_obe;

defined('MOODLE_INTERNAL') || die();

use local_obe\manager\prodi_manager;

/**
 * Centralised access control, ownership validation, and lightweight audit logging
 * for the OBE local plugin.
 */
class access_guard {

    /**
     * Program-level capabilities that allow a Kaprodi to enter the OBE admin area.
     * Page/action specific checks are still enforced after this general gate.
     */
    private const PROGRAM_AREA_CAPABILITIES = [
        'local/obe:manageprogram',
        'local/obe:viewprogramreport',
        'local/obe:configprogramscale',
    ];

   public static function is_system_manager() {
    global $USER;

    // Jika user adalah Kaprodi, jangan dianggap Admin OBE
    if (prodi_manager::is_kaprodi($USER->id)) {
        return false;
    }

    return has_capability(
        'local/obe:manage',
        \context_system::instance()
    );
}

    public static function has_any_system_capability(array $capabilities) {
        $context = \context_system::instance();
        foreach ($capabilities as $capability) {
            if (has_capability($capability, $context)) {
                return true;
            }
        }
        return false;
    }

    public static function get_kaprodi_prodi() {
        global $USER;
        return prodi_manager::ambil_prodi_kaprodi($USER->id);
    }

    public static function require_admin() {
        require_login();
        $context = \context_system::instance();
        require_capability('local/obe:manage', $context);
        \local_obe\local\ux::restore_year();
        return $context;
    }

    /**
     * Require a system OBE admin or a valid Kaprodi with at least one program-level
     * capability. Being listed as userid_kaprodi alone is NOT enough.
     *
     * @return object|null Kaprodi prodi record for Kaprodi users, null for system managers.
     */
    public static function require_admin_or_kaprodi(array $acceptedcapabilities = self::PROGRAM_AREA_CAPABILITIES) {
        require_login();

        if (self::is_system_manager()) {
            \local_obe\local\ux::restore_year();
            return null;
        }

        if (!self::has_any_system_capability($acceptedcapabilities)) {
            throw new \moodle_exception('nopermissions', 'error');
        }

        $prodi = self::get_kaprodi_prodi();
        if ($prodi) {
            \local_obe\local\ux::restore_year();
            return $prodi;
        }

        throw new \moodle_exception(
            'aksesditolak',
            'local_obe',
            '',
            null,
            'Akses Kaprodi membutuhkan role/capability OBE dan relasi Kaprodi pada data program studi.'
        );
    }

    public static function require_admin_or_kaprodi_capability($capability) {
        return self::require_admin_or_kaprodi([$capability]);
    }

    /**
     * Same as require_admin_or_kaprodi_capability(), but Kaprodi is restricted to their own prodi.
     */
    public static function require_admin_or_same_prodi($idprodi, $capability = 'local/obe:manageprogram') {
        $prodi = self::require_admin_or_kaprodi([$capability]);

        if ($prodi && (int)$idprodi > 0 && (int)$idprodi !== (int)$prodi->id) {
            throw new \moodle_exception(
                'aksesditolak',
                'local_obe',
                '',
                null,
                'Akses ditolak. Anda tidak memiliki hak untuk mengakses data program studi lain.'
            );
        }

        return $prodi;
    }

    /**
     * For program-level requests: admin may use requested prodi, Kaprodi is forced to their own prodi.
     */
    public static function resolve_allowed_prodi($requestedprodi = 0, $capability = 'local/obe:manageprogram') {
        $prodi = self::require_admin_or_kaprodi([$capability]);
        if ($prodi) {
            return (int)$prodi->id;
        }
        return (int)$requestedprodi;
    }

    public static function require_course_manage($courseid) {
        $course = get_course($courseid);
        require_login($course);
        $context = \context_course::instance($courseid);
        require_capability('local/obe:managecourse', $context);
        return [$course, $context];
    }

    public static function require_course_report($courseid) {
        $course = get_course($courseid);
        require_login($course);
        $context = \context_course::instance($courseid);

        if (!has_capability('local/obe:viewreport', $context)
                && !has_capability('local/obe:managecourse', $context)
                && !self::is_system_manager()) {
            require_capability('local/obe:viewreport', $context);
        }

        return [$course, $context];
    }

    public static function require_enrolled_course_user($courseid) {
        global $USER;

        $course = get_course($courseid);
        require_login($course);
        $context = \context_course::instance($courseid);

        if (self::is_system_manager()
                || has_capability('local/obe:viewreport', $context)
                || has_capability('local/obe:managecourse', $context)) {
            return [$course, $context];
        }

        require_capability('local/obe:viewownreport', $context);

        if (!is_enrolled($context, $USER, '', true)) {
            throw new \moodle_exception('nopermissions', 'error');
        }

        return [$course, $context];
    }

    public static function require_matakuliah_prodi_access($matakuliahid, $capability = 'local/obe:viewprogramreport') {
        global $DB;

        $idprodi = $DB->get_field('local_obe_mata_kuliah', 'id_prodi', ['id' => $matakuliahid], MUST_EXIST);
        self::require_admin_or_same_prodi($idprodi, $capability);
        return (int)$idprodi;
    }

    public static function require_record_prodi_access($table, $id, $field = 'id_prodi', $capability = 'local/obe:manageprogram') {
        global $DB;

        $record = $DB->get_record($table, ['id' => $id], '*', MUST_EXIST);
        if (!isset($record->{$field})) {
            throw new \coding_exception('Field ' . $field . ' tidak ditemukan pada tabel ' . $table);
        }
        self::require_admin_or_same_prodi($record->{$field}, $capability);
        return $record;
    }

    public static function output_safe_export_error(\Throwable $e) {
        debugging($e->getMessage(), DEBUG_DEVELOPER);
        while (ob_get_level()) {
            ob_end_clean();
        }
        header('Content-Type: text/plain; charset=utf-8');
        echo "Gagal membuat export. Silakan hubungi administrator OBE dan cek log Moodle untuk detail teknis.";
        exit;
    }

    public static function audit($action, $entity, $entityid = 0, array $details = []) {
        global $DB, $USER;

        try {
            $dbman = $DB->get_manager();
            $table = new \xmldb_table('local_obe_audit_log');
            if (!$dbman->table_exists($table)) {
                return;
            }

            $record = new \stdClass();
            $record->userid = isset($USER->id) ? (int)$USER->id : 0;
            $record->action = substr((string)$action, 0, 80);
            $record->entity = substr((string)$entity, 0, 80);
            $record->entityid = (int)$entityid;
            $record->details = json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $record->timecreated = time();

            $DB->insert_record('local_obe_audit_log', $record);
        } catch (\Throwable $e) {
            debugging('OBE audit log gagal: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }
    }
}
