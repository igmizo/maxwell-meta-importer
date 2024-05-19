<?php

class Maxwell_Post_Import_Activator
{
    public static function activate()
    {
        self::createBackgroundTaskTable();
    }

    private static function createBackgroundTaskTable()
    {
        global $wpdb;

        $tableName = $wpdb->prefix . 'maxwell_meta_import_schedule';
        $charsetCollate = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE $tableName (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            name varchar(255) NOT NULL,
            details longtext NOT NULL,
            PRIMARY KEY  (id)
        ) $charsetCollate;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
        add_option('maxwell_meta_import_db_version', MAXWELL_POST_IMPORT_DB_VERSION);
    }
}
