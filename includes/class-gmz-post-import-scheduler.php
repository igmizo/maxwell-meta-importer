<?php

use GuzzleHttp\Client;

class Maxwell_Post_Import_Scheduler
{
  protected $prefix = 'ep';
  protected $action = 'maxwell_post_import_scheduler';
  protected $identifier;
  protected $data = [];
  protected $start_time = 0;
  protected $cron_hook_identifier;
  protected $cron_interval_identifier;

  const STATUS_CANCELLED = 1;
  const STATUS_PAUSED = 2;

  public function __construct() {
    $this->identifier = $this->prefix . '_' . $this->action;
    $this->cron_hook_identifier     = $this->identifier . '_cron';
    $this->cron_interval_identifier = $this->identifier . '_cron_interval';

    add_action('wp_ajax_' . $this->identifier, [$this, 'maybe_handle']);
    add_action('wp_ajax_nopriv_' . $this->identifier, [$this, 'maybe_handle']);
    // add_action($this->cron_hook_identifier, [$this, 'handle_cron_healthcheck']);
    // add_filter('cron_schedules', [$this, 'schedule_cron_healthcheck']);
    add_action('maxwell_meta_importer_action', [$this, 'handle']);
  }

  public function data($data)
  {
    $this->data = $data;

    return $this;
  }

  public function schedule_file($file, $post_type, $taxonomy)
  {
    global $wpdb;

    $table_name = $wpdb->prefix . 'maxwell_meta_import_schedule';
    $importer = new Maxwell_CSV_Importer($file, $post_type, $taxonomy);
    $rows = $importer->get_rows();
    $queue_key = $this->generate_key();
    $task_item_count = ceil(count($rows) / $importer->get_batch_size());

    for ($i = 0; $i < $task_item_count; $i++) {
      $this->push_to_queue(['key' => $queue_key, 'batch_index' => $i]);
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

    $table_name = $wpdb->prefix . 'maxwell_meta_import_schedule';
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

    $current_db_version = get_site_option('maxwell_meta_import_db_version', 1);

    if ($current_db_version != MAXWELL_POST_IMPORT_DB_VERSION) {
      $table_name = $wpdb->prefix . 'maxwell_meta_import_schedule';
      $options_table_name = $wpdb->prefix . 'maxwell_meta_import_options';
      $charsetCollate = $wpdb->get_charset_collate();
      $schedule_sql = "CREATE TABLE $table_name (
          id mediumint(9) NOT NULL AUTO_INCREMENT,
          name varchar(255) NOT NULL,
          details longtext NOT NULL,
          PRIMARY KEY (id)
      ) $charsetCollate;";
      $options_sql = "CREATE TABLE $options_table_name (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        name varchar(255) NOT NULL,
        value longtext NOT NULL,
        PRIMARY KEY (id),
        UNIQUE (name)
      ) $charsetCollate;";

      require_once ABSPATH . 'wp-admin/includes/upgrade.php';
      dbDelta([$schedule_sql, $options_sql]);
      update_option('maxwell_meta_import_db_version', MAXWELL_POST_IMPORT_DB_VERSION);
    }

    if ($current_db_version < 3) {
      $this->migrate_schedule_data();
    }
  }

  public function push_to_queue($data)
  {
    $this->data[] = $data;

    return $this;
  }

  public function save($key = null)
  {
    if (!empty($this->data)) {
      $this->update_option($key, $this->data);
    }

    return $this;
  }

  public function update($name, $data)
  {
    if (!empty($data)) {
      $this->update_option($name, $data);
    }

    return $this;
  }

  public function delete($name)
  {
    $this->delete_option($name);

    return $this;
  }

  public function delete_all()
  {
    $batches = $this->get_batches();

    foreach ($batches as $batch) {
      $this->delete($batch->key);
    }

    $this->delete_option($this->get_status_key());
    $this->cancelled();
  }

  public function cancel()
  {
    $this->update_option($this->get_status_key(), self::STATUS_CANCELLED);
    $this->dispatch();
  }

  public function is_cancelled()
  {
    $status = $this->get_option($this->get_status_key());

    if ($status && absint($status->value) === self::STATUS_CANCELLED) {
      return true;
    }

    return false;
  }

  public function pause()
  {
    $this->update_option($this->get_status_key(), self::STATUS_PAUSED);
  }

  public function is_paused()
  {
    $status = $this->get_option($this->get_status_key());

    if ($status && absint($status->value) === self::STATUS_PAUSED) {
      return true;
    }

    return false;
  }

  public function is_queued()
  {
    return !$this->is_queue_empty();
  }

  public function is_active()
  {
    return $this->is_queued() || $this->is_processing() || $this->is_paused() || $this->is_cancelled();
  }

  protected function paused()
  {
    do_action($this->identifier . '_paused');
  }

  public function resume()
  {
    $this->delete_option($this->get_status_key());

    $this->schedule_event();
    $this->dispatch();
    $this->resumed();
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

    $table_name = $wpdb->prefix . 'maxwell_meta_import_schedule';
    $schedules = $wpdb->get_results("SELECT * FROM $table_name");

    foreach ($schedules as $schedule) {
      $schedule->details = maybe_unserialize($schedule->details);
    }

    return $schedules;
  }

  public function is_processing()
  {
    $option = $this->get_option($this->identifier . '_process_lock');

    return !is_null($option);
  }

  public function get_batches($limit = 0)
  {
    global $wpdb;

    $table_name = $wpdb->prefix . 'maxwell_meta_import_options';
    $name = $wpdb->esc_like( $this->identifier . '_batch_' ) . '%';
    $sql = "SELECT * FROM $table_name WHERE name LIKE %s ORDER BY id ASC";
    $arguments = [$name];

    if (empty($limit) || !is_int($limit)) {
      $limit = 0;
    }

    if ($limit > 0) {
      $sql .= ' LIMIT %d';
      $arguments[] = $limit;
    }

    $items = $wpdb->get_results($wpdb->prepare($sql, $arguments));
    $batches = [];

    if (!empty($items)) {
      $batches = array_map(
        function ($item) {
          $batch = new stdClass();
          $batch->key  = $item->name;
          $batch->data = maybe_unserialize($item->value);

          return $batch;
        },
        $items
      );
    }

    return $batches;
  }

  public function maybe_handle()
  {
    session_write_close();

    if ($this->is_cancelled()) {
      $this->clear_scheduled_event();
      $this->delete_all();

      return $this->maybe_wp_die();
    }

    if ($this->is_paused()) {
      $this->clear_scheduled_event();
      $this->paused();

      return $this->maybe_wp_die();
    }

    if ($this->is_queue_empty()) {
      return $this->maybe_wp_die();
    }

    $this->handle();

    return $this->maybe_wp_die();;
  }

  public function make_request()
  {
    $url = add_query_arg( $this->get_query_args(), $this->get_query_url() );
    $client = new Client();

    try {
      $client->request('POST', $url);
    } catch (\Exception $e) {
      error_log($e->getMessage());
    }
  }

  public function dispatch()
  {
    $this->schedule_event();
  }

  public function schedule_cron_healthcheck($schedules)
  {
    $interval = apply_filters($this->cron_interval_identifier, 5);

    if (property_exists($this, 'cron_interval')) {
      $interval = apply_filters($this->cron_interval_identifier, $this->cron_interval);
    }

    if (1 === $interval) {
      $display = __('Every Minute');
    } else {
      $display = sprintf(__('Every %d Minutes'), $interval);
    }

    $schedules[$this->cron_interval_identifier] = [
      'interval' => MINUTE_IN_SECONDS * $interval,
      'display' => $display,
    ];

    return $schedules;
  }

  public function handle_cron_healthcheck()
  {
    if ($this->is_queue_empty()) {
      return;
    }

    $this->make_request();
    $this->remove_scheduled_event('maxwell_meta_importer_dispatch');
  }

  public function cancel_process()
  {
    $this->cancel();
  }

  protected function resumed()
  {
    do_action($this->identifier . '_resumed');
  }

  protected function get_query_args()
  {
    if (property_exists($this, 'query_args')) {
      return $this->query_args;
    }

    $args = [
      'action' => $this->identifier,
      'nonce' => wp_create_nonce($this->identifier)
    ];

    return apply_filters($this->identifier . '_query_args', $args);
  }

  protected function get_query_url()
  {
    if (property_exists($this, 'query_url')) {
      return $this->query_url;
    }

    $url = admin_url('admin-ajax.php');

    return apply_filters($this->identifier . '_query_url', $url);
  }

  protected function maybe_wp_die($return = null)
  {
    if (apply_filters($this->identifier . '_wp_die', true)) {
      wp_die();
    }

    return $return;
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

      $this->update_schedule($schedule);
    }

    return false;
  }

  protected function complete()
  {
    $this->delete_option($this->get_status_key());
    $this->remove_scheduled_event();
    $this->completed();
  }

  protected function lock_process()
  {
    $this->update_option($this->identifier . '_process_lock', 'on');
  }

  protected function unlock_process()
  {
    $this->delete_option( $this->identifier . '_process_lock' );

    return $this;
  }

  protected function generate_key($length = 64, $key = 'batch')
  {
    $unique  = md5(microtime() . wp_rand());
    $prepend = $this->identifier . '_' . $key . '_';

    return substr($prepend . $unique, 0, $length);
  }

  protected function get_status_key()
  {
    return $this->identifier . '_status';
  }

  protected function is_queue_empty()
  {
    return empty($this->get_batch());
  }

  protected function is_process_running()
  {
    return $this->is_processing();
  }

  protected function get_batch()
  {
    return array_reduce(
      $this->get_batches(1),
      function ($carry, $batch) {
        return $batch;
      },
      []
    );
  }

  public function handle() {
    if ($this->is_processing()) {
      error_log('Already processing');
      return;
    }
    error_log('Handling');
    $this->lock_process();

    $batch = $this->get_batch();

    foreach ($batch->data as $key => $value) {
      error_log('Calling task with '.print_r($value, true));
      $task = $this->task($value);

      if (false !== $task) {
        $batch->data[$key] = $task;
      } else {
        unset($batch->data[$key]);
      }

      if (!empty($batch->data)) {
        $this->update($batch->key, $batch->data);
      }
    }
    error_log('Finished a batch');
    if (empty($batch->data)) {
      $this->delete($batch->key);
    }

    $this->unlock_process();

    if ($this->is_queue_empty()) {
      $this->complete();
    }
  }

  protected function memory_exceeded()
  {
    $memory_limit = $this->get_memory_limit() * 0.9;
    $current_memory = memory_get_usage(true);
    $return = false;

    if ($current_memory >= $memory_limit) {
      $return = true;
    }

    return apply_filters($this->identifier . '_memory_exceeded', $return);
  }

  protected function get_memory_limit()
  {
    if (function_exists('ini_get')) {
      $memory_limit = ini_get('memory_limit');
    } else {
      $memory_limit = '128M';
    }

    if (!$memory_limit || -1 === intval($memory_limit)) {
      $memory_limit = '32000M';
    }

    return wp_convert_hr_to_bytes($memory_limit);
  }

  protected function time_exceeded()
  {
    $finish = $this->start_time + apply_filters($this->identifier . '_default_time_limit', 20);
    $return = false;

    if (time() >= $finish) {
      $return = true;
    }

    return apply_filters($this->identifier . '_time_exceeded', $return);
  }

  protected function completed()
  {
    do_action($this->identifier . '_completed');
  }

  protected function schedule_event()
  {
    if (as_next_scheduled_action('maxwell_meta_importer_action') === false) {
      error_log('Event does not exist');
      as_schedule_cron_action(time(), "* * * * ", 'maxwell_meta_importer_action');
    }
  }

  protected function remove_scheduled_event()
  {
    $active_batch = $this->get_batch();

    if (!empty($active_batch)) {
      $this->delete_option($active_batch->key);
    }

    as_unschedule_all_actions('maxwell_meta_importer_action');
    $this->clear_completed_actions();
  }

  protected function clear_scheduled_event()
  {
    $timestamp = wp_next_scheduled($this->cron_hook_identifier);

    if ($timestamp) {
      wp_unschedule_event($timestamp, $this->cron_hook_identifier);
    }
  }

  private function migrate_schedule_data()
  {
    global $wpdb;

    $schedule = get_option($this->action, []);
    $table_name = $wpdb->prefix . 'maxwell_meta_import_schedule';
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

    $table_name = $wpdb->prefix . 'maxwell_meta_import_schedule';
    $schedule = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_name WHERE name = %s", $name));

    if ($schedule) {
      $schedule->details = maybe_unserialize($schedule->details);
    }

    return $schedule;
  }

  private function update_schedule($schedule)
  {
    global $wpdb;

    $table_name = $wpdb->prefix . 'maxwell_meta_import_schedule';

    $wpdb->update($table_name, ['details' => maybe_serialize($schedule->details)], ['id' => $schedule->id]);
  }

  private function cancel_schedule($key)
  {
    global $wpdb;

    $table_name = $wpdb->prefix . 'maxwell_meta_import_schedule';
    $batches = $this->get_batches();

    foreach ($batches as $batch) {
      if ($batch->key == $key) {
        $this->complete();
        $this->delete($key);

        break;
      }
    }

    $wpdb->delete($table_name, ['name' => $key]);
    $this->unlock_process();
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

    $table_name = $wpdb->prefix . 'maxwell_meta_import_schedule';
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

    if ($schedule->details['total'] == 0) {
      return 0;
    }

    return $total_progress / $schedule->details['total'] * 100;
  }

  private function get_option($name)
  {
    global $wpdb;

    $table_name = $wpdb->prefix . 'maxwell_meta_import_options';
    $option = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_name WHERE name = %s", $name));

    if ($option) {
      $option->value = maybe_unserialize($option->value);
    }

    return $option;
  }

  private function update_option($key, $value)
  {
    global $wpdb;

    $table_name = $wpdb->prefix . 'maxwell_meta_import_options';
    $serialized_value = maybe_serialize($value);

    $wpdb->replace($table_name, ['name' => $key, 'value' => $serialized_value]);
  }

  private function delete_option($key)
  {
    global $wpdb;

    $table_name = $wpdb->prefix . 'maxwell_meta_import_options';

    $wpdb->delete($table_name, ['name' => $key], ['name' => '%s']);
  }

  private function has_scheduled_event()
  {
    $option = $this->get_option($this->identifier . '_scheduled_event');

    return !is_null($option);
  }

  private function clear_completed_actions()
  {
    global $wpdb;

    $table_name = $wpdb->prefix . 'actionscheduler_actions';

    $wpdb->query("DELETE FROM $table_name WHERE hook = 'maxwell_meta_importer_action' AND (status = 'complete' OR status = 'failed')");
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
