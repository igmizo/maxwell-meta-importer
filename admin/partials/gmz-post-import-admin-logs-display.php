<?php

$scheduler = new Maxwell_Post_Import_Scheduler();
$schedules = $scheduler->format_response($scheduler->get_schedules());

?>

<div class="wrap">
  <h1>GMZ Post Import Logs</h1>

  <div>

    <?php

      foreach ($schedules as $entry) {
        if (array_key_exists('results', $entry)) {

    ?>

    <details>
      <summary>
        <strong><?php echo date('F j, Y, g:i a', $entry['created_at']); ?></strong>,

        <?php echo $entry['file']; ?>

      </summary>
      <div class="gmz-pi-log-output-wrapper">
        <table class="wp-list-table widefat fixed striped table-view-list">
          <thead>
            <tr>
              <th>Batch number</th>
              <th>Row number</th>
              <th>Status</th>
              <th>Post ID</th>
            </tr>
          </thead>
          <tbody>

            <?php

            foreach ($entry['results'] as $batchKey => $batch) {
              [$_, $batcnNumber] = explode('_', $batchKey);

              if (array_key_exists('successful_rows', $batch)) {
                foreach ($batch['successful_rows'] as $row) {

            ?>

            <tr>
              <td><?php echo $batcnNumber; ?></td>
              <td><?php echo $row['row_number']; ?></td>
              <td>Success</td>
              <td><?php echo $row['post_id']; ?></td>
            </tr>

            <?php

                }
              }

              if (array_key_exists('successful_rows', $batch)) {
                foreach ($batch['failed_rows'] as $row) {

            ?>

            <tr>
              <td><?php echo $batcnNumber; ?></td>
              <td><?php echo $row['row_number']; ?></td>
              <td>Failure</td>
              <td></td>
            </tr>

            <?php }}} ?>

          </tbody>
        </table>
      </div>
    </details>

    <?php }} ?>

  </div>
</div>
