<?php

class Maxwell_Post_Import_Admin
{
    private $plugin_name;
    private $version;

    public function __construct($plugin_name, $version)
    {
        $this->plugin_name = $plugin_name;
        $this->version = $version;

        add_action('wp_ajax_maxwell_handle_csv_upload', [$this, 'handle_csv_upload']);
        add_action('wp_ajax_maxwell_import', [$this, 'import']);
    }

    public function enqueue_styles()
    {
        wp_enqueue_style($this->plugin_name, plugin_dir_url(__FILE__) . 'css/gmz-post-import-admin.css', array(), $this->version, 'all');
    }

    public function enqueue_scripts($hook)
    {
        if ($hook == 'toplevel_page_maxwell_test') {
            wp_enqueue_script($this->plugin_name, plugin_dir_url(__FILE__) . 'js/gmz-post-import-admin.js', array('jquery'), $this->version, false);
        }
    }

    public function add_page()
    {
        add_menu_page("Maxwell Meta Importer", "Maxwell Meta Importer", "edit_posts", "maxwell_test", array(&$this, 'display_admin_page'), 'dashicons-database-import', 90);
        add_submenu_page("maxwell_test", "Maxwell Meta Importer", "Logs", "edit_posts", "maxwell_post_import_logs", array(&$this, 'display_logs_page'));
    }

    public function display_admin_page()
    {
        include("partials/gmz-post-import-admin-display.php");
    }

    public function display_logs_page()
    {
        include("partials/gmz-post-import-admin-logs-display.php");
    }

    public function handle_csv_upload()
    {
        $raw_csv = $_FILES['csv_file'];
        $post_type = $_POST['post_type'];
        $taxonomy = $_POST['taxonomy'];
        $update_amazon = isset($_POST['update_amazon']) ? $_POST['update_amazon'] : false;
        $uploaded = wp_handle_upload($raw_csv, ['test_form' => false]);
        $scheduler = new Maxwell_Post_Import_Scheduler();
        $schedule = $scheduler->schedule_file($uploaded['file'], $post_type, $taxonomy, $update_amazon);

        wp_send_json($schedule);
        wp_die();
    }

    public function import()
    {
        $file = $_POST['file'];
        $post_type = $_POST['post_type'];
        $taxonomy = $_POST['taxonomy'];
        $skip = $_POST['skip'];
        $update_amazon = $_POST['update_amazon'];

        $importer = new Maxwell_CSV_Importer($file, $post_type, $taxonomy, $update_amazon, $skip);
        $results = $importer->process();

        echo wp_json_encode($results);
        wp_die();
    }
}
