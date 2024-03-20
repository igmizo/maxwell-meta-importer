<?php

class Maxwell_Post_Import_Uninstaller
{
    public static function uninstall()
    {
        global $wpdb;

        $tableName = $wpdb->prefix . 'gmz_post_import_schedule';
        $sql = "DROP TABLE IF EXISTS $tableName";

        $wpdb->query($sql);
    }
}
