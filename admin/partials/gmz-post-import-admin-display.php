<?php
if (isset($_POST['method']) && $_POST['method'] == 'upload_csv') {
    $this->handle_csv_upload();
}

$all_post_types = get_post_types();
$all_taxonomies = get_taxonomies();
?>

<!-- This file should primarily consist of HTML with a little of PHP. -->
<div class="wrap">
    <h1>Maxwell Meta Importer</h1>
    <form id="csv_upload_form" name="csv_upload_form" action="" method="post" enctype="multipart/form-data" class="step1">
        <input type="hidden" name="method" value="upload_csv">
        <div class="row">
            <label for="post_type">Post Type</label>
            <select name="post_type">
                <?php foreach ($all_post_types as $type): ?>
                    <option value="<?php echo $type ?>"><?php echo $type ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="row">
            <label for="taxonomy">Taxonomy</label>
            <select name="taxonomy">
                <?php foreach ($all_taxonomies as $tax): ?>
                    <option value="<?php echo $tax ?>"><?php echo $tax ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="row">
            <label for="csv">CSV File</label>
            <input name="csv_file" type="file">
        </div>
        <div class="row">
            <label for="csv">Update Amazon</label>
            <input name="update_amazon" type="checkbox" value="yes">
        </div>
        <div class="row">

        </div>
        <button class="button button-primary button-hero" type="submit">Upload</button>

    </form>

    <div class="notice notice-error is-dismissible hidden" id="import_error_message">
        <p id="import_error_message_text"></p>
        <button type="button" class="notice-dismiss">
            <span class="screen-reader-text">Dismiss this notice.</span>
        </button>
    </div>

    <table class="wp-list-table widefat fixed striped table-view-list" id="queue_table">
        <thead>
            <tr>
                <th scope="col" class="manage-column file-name-column column-primary">File</th>
                <th scope="col" class="manage-column">Start time</th>
                <th scope="col" class="manage-column">Created</th>
                <th scope="col" class="manage-column">Updated</th>
                <th scope="col" class="manage-column">Failed</th>
                <th scope="col" class="manage-column">Progress</th>
                <th scope="col" class="manage-column"></th>
            </tr>
        </thead>
        <tbody></tbody>
    </table>

    <div id="error_table_wrapper">
        <h2 class="title">Amazon Errors</h2>
        <table class="wp-list-table widefat fixed striped table-view-list" id="error_table">
            <thead>
                <tr>
                    <th scope="col" class="manage-column file-name-column column-primary">File</th>
                    <th scope="col" class="manage-column">Start Time</th>
                    <th scope="col" class="manage-column">Post ID</th>
                    <th scope="col" class="manage-column">ASIN</th>
                    <th scope="col" class="manage-column">Error Message</th>
                </tr>
            </thead>
            <tbody></tbody>
        </table>
    </div>

    <div class="tablenav bottom">
        <div id="queue_table_loader">
            <span class="loader"></span>
            <span>Loading...</span>
        </div>

        <div id="queue_table_load_error" class="hidden">Coulnd not load the data.</div>

        <div class="alignleft actions bulkactions hidden" id="queue_table_actions">
            <button type="button" class="button action" id="queue_table_cleanup">Clear completed</button>
        </div>
    </div>
</div>
