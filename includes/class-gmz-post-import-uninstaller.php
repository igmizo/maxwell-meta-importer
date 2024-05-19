<?php

class Maxwell_Post_Import_Uninstaller
{
    public static function uninstall()
    {
        global $wpdb;

        $tableName = $wpdb->prefix . 'maxwell_meta_import_schedule';
        $sql = "DROP TABLE IF EXISTS $tableName";

        $wpdb->query($sql);
    }
}
