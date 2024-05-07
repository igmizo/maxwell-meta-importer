<?php

class Maxwell_Post_Import_Scheduler extends WP_Background_Process
{
  protected $action = 'maxwell_post_import_scheduler';

  public function schedule_file($file, $post_type, $taxonomy)
  {
    global $wpdb;

    $table_name = $wpdb->prefix . 'gmz_post_import_schedule';
    $importer = new Maxwell_CSV_Importer($file, $post_type, $taxonomy);
    $rows = $importer->get_rows();
    $task_items = array_chunk($rows, $importer->get_batch_size());
    $queue_key = $this->generate_key();

    foreach ($task_items as $index => $item) {
      $this->push_to_queue(['key' => $queue_key, 'batch_index' => $index]);
    }

    $schedule = [
      'file' => $file,
      'post_type' => $post_type,
      'taxonomy' => $taxonomy,
      'total' => count($rows),
      'failed_count' => 0,
      'updated_count' => 0,
      'created_count' => 0,
      'amazon_fetched' => 0,
      'amazon_failed' => 0,
      'error_msg' => '',
      'failed' => [],
      'created_at' => time(),
      'processed_batches' => [],
      'amazon_errors' => [],
      'results' => []
    ];

    $wpdb->insert($table_name, ['name' => $queue_key, 'details' => maybe_serialize($schedule)]);
    $this->save($queue_key)->dispatch();

    return $this->format_response($this->get_schedules());
  }

  public function get_queue_history()
  {
    $schedules = $this->get_schedules();

    wp_send_json($this->format_response($schedules));
    wp_die();
  }

  public function clean_queue_history()
  {
    global $wpdb;

    $table_name = $wpdb->prefix . 'gmz_post_import_schedule';
    $schedules = $this->get_schedules();
    $completed_queues = [];

    foreach ($schedules as $schedule) {
      $processed_count = $schedule->details['created_count'] + $schedule->details['failed_count'] + $schedule->details['updated_count'];

      if ($schedule->details['total'] == $processed_count) {
        $completed_queues[] = $schedule->id;
      }
    }

    foreach ($completed_queues as $id) {
      $wpdb->delete($table_name, ['id' => $id]);
    }

    wp_send_json($this->format_response($this->get_schedules()));
    wp_die();
  }

  public function update_database()
  {
    global $wpdb;

    $current_db_version = get_site_option('gmz_post_import_db_version', 1);

    if ($current_db_version != MAXWELL_POST_IMPORT_DB_VERSION) {
      $table_name = $wpdb->prefix . 'gmz_post_import_schedule';
      $charsetCollate = $wpdb->get_charset_collate();
      $sql = "CREATE TABLE $table_name (
          id mediumint(9) NOT NULL AUTO_INCREMENT,
          name varchar(255) NOT NULL,
          details longtext NOT NULL,
          PRIMARY KEY  (id)
      ) $charsetCollate;";

      require_once ABSPATH . 'wp-admin/includes/upgrade.php';
      dbDelta( $sql );
      update_option('gmz_post_import_db_version', MAXWELL_POST_IMPORT_DB_VERSION);
    }

    if ($current_db_version < 3) {
      $this->migrate_schedule_data();
    }
  }

  public function save($key = null)
  {
    if (is_null($key)) return parent::save();

    if (!empty($this->data)) {
      update_site_option($key, $this->data);
    }

    return $this;
  }

  public function cancel_file()
  {
    $key = $_POST['schedule_key'];

    $this->cancel_schedule($key);
    wp_send_json($this->format_response($this->get_schedules()));
    wp_die();
  }

  public function restart_file()
  {
    $key = $_POST['schedule_key'];
    $schedule = $this->get_schedule_by_name($key);

    if ($schedule) {
      $this->restart_schedule($schedule);
    }

    wp_send_json($this->format_response($this->get_schedules()));
    wp_die();
  }

  public function format_response($schedules)
  {
    $response = [];

    foreach ($schedules as $schedule) {
      $schedule->details['key'] = $schedule->name;
      $schedule->details['progress'] = $this->calculate_progress_percentage($schedule);
      $response[] = $schedule->details;
    }

    usort($response, function ($a, $b) {
      if ($a['created_at'] == $b['created_at']) return 0;

      return $a['created_at'] > $b['created_at'] ? -1 : 1;
    });

    return $response;
  }

  public function get_schedules()
  {
    global $wpdb;

    $table_name = $wpdb->prefix . 'gmz_post_import_schedule';
    $schedules = $wpdb->get_results("SELECT * FROM $table_name");

    foreach ($schedules as $schedule) {
      $schedule->details = maybe_unserialize($schedule->details);
    }

    return $schedules;
  }

  protected function task($item)
  {
    $schedule = $this->get_schedule_by_name($item['key']);

    if ($schedule) {
      $importer = new Maxwell_CSV_Importer($schedule->details['file'], $schedule->details['post_type'], $schedule->details['taxonomy']);
      $import_result = $importer->process_batch($item['batch_index']);

      if (!in_array($item['batch_index'], $schedule->details['processed_batches'])) {
        foreach ($import_result as $key => $value) {
          if (is_array($schedule->details[$key])) {
            $schedule->details[$key] = array_merge($schedule->details[$key], $value);
          } elseif (is_int($schedule->details[$key])) {
            $schedule->details[$key] += $value;
          } else {
            $schedule->details[$key] = $value;
          }
        }

        $schedule->details['processed_batches'][] = $item['batch_index'];
      }
    }

    $this->update_schedule($schedule);

    return false;
  }

  protected function complete()
  {
    parent::complete();

    $schedules = $this->get_schedules();
    $active_batch = $this->get_batch();

    if (empty($active_batch)) {
      $active_batch = new \stdClass();

      $active_batch->key = null;
    }

    foreach ($schedules as $schedule) {
      $starting_point = 1698665742;

      if ($schedule->name != $active_batch->key && $schedule->details['created_at'] > $starting_point) {
        $progress = $this->calculate_progress_percentage($schedule);

        if ($progress < 100 && array_key_exists('results', $schedule->details)) {
          $this->restart_schedule($schedule);

          error_log("MXPI: {$schedule->name} has been restarted.");
        }
      }
    }
  }

  private function migrate_schedule_data()
  {
    global $wpdb;

    $schedule = get_option($this->action, []);
    $table_name = $wpdb->prefix . 'gmz_post_import_schedule';
    $schedule_count = $wpdb->get_var("SELECT COUNT(*) FROM $table_name");

    if ($schedule_count > 0) {
      delete_option($this->action);

      return;
    }

    foreach ($schedule as $key => $queue) {
      $wpdb->insert(
        $table_name,
        array(
          'name' => $key,
          'details' => maybe_serialize($queue)
        )
      );
    }
  }

  private function get_schedule_by_name($name)
  {
    global $wpdb;

    $table_name = $wpdb->prefix . 'gmz_post_import_schedule';
    $schedule = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_name WHERE name = %s", $name));

    if ($schedule) {
      $schedule->details = maybe_unserialize($schedule->details);
    }

    return $schedule;
  }

  private function update_schedule($schedule)
  {
    global $wpdb;

    $table_name = $wpdb->prefix . 'gmz_post_import_schedule';

    $wpdb->update($table_name, ['details' => maybe_serialize($schedule->details)], ['id' => $schedule->id]);
  }

  private function cancel_schedule($key)
  {
    global $wpdb;

    $table_name = $wpdb->prefix . 'gmz_post_import_schedule';
    $batch = $this->get_batch();

    if (!empty($batch) && $batch->key == $key) {
      $this->complete();
    } else {
      $this->delete($key);
    }

    $wpdb->delete($table_name, ['name' => $key]);
  }

  private function refresh_processed_batches($schedule)
  {
    $processed_batches = $schedule->details['processed_batches'];

    if (array_key_exists('results', $schedule->details)) {
      $processed_batches = [];

      foreach ($schedule->details['results'] as $batchKey => $results) {
        [$_, $batcnNumber] = explode('_', $batchKey);

        if (!in_array($batcnNumber, $processed_batches)) {
          $processed_batches[] = intval($batcnNumber);
        }
      }
    }

    return $processed_batches;
  }

  private function restart_schedule($schedule)
  {
    global $wpdb;

    $table_name = $wpdb->prefix . 'gmz_post_import_schedule';
    $key = $schedule->name;

    $this->cancel_schedule($key);
    $processed_batches = $this->refresh_processed_batches($schedule);

    $importer = new Maxwell_CSV_Importer($schedule->details['file'], $schedule->details['post_type'], $schedule->details['taxonomy']);
    $rows = $importer->get_rows();
    $task_items = array_chunk($rows, $importer->get_batch_size());

    foreach ($task_items as $index => $item) {
      if (in_array($index, $processed_batches)) continue;

      $this->push_to_queue(['key' => $key, 'batch_index' => $index]);
    }

    $schedule->details['total'] = count($rows);
    $schedule->details['processed_batches'] = $processed_batches;

    $wpdb->insert($table_name, ['name' => $key, 'details' => maybe_serialize($schedule->details)]);
    $this->save($key)->dispatch();
  }

  private function calculate_progress_percentage($schedule)
  {
    $total_progress = $schedule->details['created_count'] + $schedule->details['failed_count'] + $schedule->details['updated_count'];

    return $total_progress / $schedule->details['total'] * 100;
  }
}

add_action('plugins_loaded', function () {
  $scheduler = new Maxwell_Post_Import_Scheduler();

  $scheduler->update_database();

  add_action('wp_ajax_get_queue_history', [$scheduler, 'get_queue_history']);
  add_action('wp_ajax_clean_queue_history', [$scheduler, 'clean_queue_history']);
  add_action('wp_ajax_cancel_file', [$scheduler, 'cancel_file']);
  add_action('wp_ajax_restart_file', [$scheduler, 'restart_file']);
});
