<?php
defined('MOODLE_INTERNAL') || die();

/**
 * Plugin upgrade steps.
 */
function xmldb_local_obe_upgrade($oldversion) {
    global $DB;

    $dbman = $DB->get_manager();

    if ($oldversion < 2026061801) {
        $table = new xmldb_table('local_obe_audit_log');

        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('action', XMLDB_TYPE_CHAR, '80', null, XMLDB_NOTNULL, null, null);
            $table->add_field('entity', XMLDB_TYPE_CHAR, '80', null, XMLDB_NOTNULL, null, null);
            $table->add_field('entityid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('details', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');

            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);

            $table->add_index('userid_idx', XMLDB_INDEX_NOTUNIQUE, ['userid']);
            $table->add_index('entity_idx', XMLDB_INDEX_NOTUNIQUE, ['entity', 'entityid']);
            $table->add_index('timecreated_idx', XMLDB_INDEX_NOTUNIQUE, ['timecreated']);

            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(true, 2026061801, 'local', 'obe');
    }

    if ($oldversion < 2026111501) {
        foreach (['local_obe_relasi_kelas' => ['id_kursus_moodle', 'course_unique'], 'local_obe_aktivitas_map' => ['cmid', 'cmid_idx']] as $table => $spec) {
            [$field, $indexname] = $spec;
            if ($DB->record_exists_sql("SELECT $field FROM {{$table}} GROUP BY $field HAVING COUNT(*) > 1")) {
                throw new moodle_exception('Upgrade OBE ditunda: pemetaan ganda pada ' . $table . '. Rekonsiliasi duplikat tanpa menghapus riwayat nilai lalu jalankan upgrade kembali.');
            }
            $xtable = new xmldb_table($table);
            $oldindex = new xmldb_index($indexname, XMLDB_INDEX_NOTUNIQUE, [$field]);
            if ($dbman->index_exists($xtable, $oldindex)) { $dbman->drop_index($xtable, $oldindex); }
            $unique = new xmldb_index($indexname, XMLDB_INDEX_UNIQUE, [$field]);
            if (!$dbman->index_exists($xtable, $unique)) { $dbman->add_index($xtable, $unique); }
        }
        upgrade_plugin_savepoint(true, 2026111501, 'local', 'obe');
    }
    return true;
}
