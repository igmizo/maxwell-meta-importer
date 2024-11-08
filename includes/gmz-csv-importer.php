<?php

use ContentEgg\application\components\ContentManager;
use ContentEgg\application\components\ModuleManager;

class Maxwell_CSV_Importer
{
    private $data;
    private $maps;
    private $processors;

    private $post_type;
    private $taxonomy;
    private $skip;

    private $created_count = 0;
    private $updated_count = 0;
    private $failed_count = 0;
    private $amazon_fetched = 0;
    private $amazon_failed = 0;
    private $error_msg = "";
    private $failed = [];
    private $offers = [];
    private $amazonAsins = [];
    private $amazon_errors = [];

    const MAX_ROWS_PER_BATCH = 5;

    function __construct($file, $post_type, $taxonomy, $skip = 0)
    {
        $this->data = $this->readFile($file);
        $this->post_type = $post_type;
        $this->taxonomy = $taxonomy;
        $this->skip = $skip;
    }

    public function process(): array
    {
        $finished = false;
        $total = count($this->data);
        $current = 0;
        foreach ($this->data as $row_number => $row) {
            $data = [];
            if($row_number < $this->skip) {
                continue;
            }
            if ($row_number > self::MAX_ROWS_PER_BATCH + $this->skip - 1) {
                break;
            }
            $current = $row_number;
            foreach ($row as $column_number => $value) {
                $processor = $this->processors[$column_number];
                $map = $this->maps[$column_number];
                $value = $this->processValue($value, $processor);
                $data[$map] = $value;
            }
            if($row_number == $total-1) {
                $finished = true;
            }
            $this->createPost($data);
        }
        return [
            "failed_count" => $this->failed_count,
            "updated_count" => $this->updated_count,
            "created_count" => $this->created_count,
            "amazon_fetched" => $this->amazon_fetched,
            "amazon_failed" => $this->amazon_failed,
            "error_msg" => $this->error_msg,
            "total" => count($this->data),
            "finished" => $finished,
            "failed" => $this->failed,
            "current" => $current+1,
            "amazon_errors" => $this->amazon_errors
        ];
    }

    public function process_batch($batch_index)
    {
        $total = count($this->data);
        $skip = self::MAX_ROWS_PER_BATCH * $batch_index;
        $results = ["batch_{$batch_index}" => ['successful_rows' => [], 'failed_rows' => []]];

        foreach ($this->data as $row_number => $row) {
            $data = [];

            if ($row_number < $skip) continue;
            if ($row_number > self::MAX_ROWS_PER_BATCH + $skip - 1) break;

            foreach ($row as $column_number => $value) {
                $processor = $this->processors[$column_number];
                $map = $this->maps[$column_number];
                $value = $this->processValue($value, $processor);
                $data[$map] = $value;
            }

            $post_id = $this->createPost($data);
            $data_post_id = $data['id'] ?? '';

            if ($post_id > 0) {
                $results["batch_{$batch_index}"]['successful_rows'][] = [
                    'row_number' => $row_number + 1,
                    'post_id' => $post_id,
                    'data_post_id' => $data_post_id
                ];
            } else {
                $results["batch_{$batch_index}"]['failed_rows'][] = [
                    'row_number' => $row_number + 1,
                    'data_post_id' => $data_post_id
                ];
            }
        }

        return [
            "failed_count" => $this->failed_count,
            "updated_count" => $this->updated_count,
            "created_count" => $this->created_count,
            "amazon_fetched" => $this->amazon_fetched,
            "amazon_failed" => $this->amazon_failed,
            "error_msg" => $this->error_msg,
            "failed" => $this->failed,
            "amazon_errors" => $this->amazon_errors,
            "results" => $results
        ];
    }

    public function get_rows()
    {
        return $this->data;
    }

    public function get_batch_size()
    {
        return self::MAX_ROWS_PER_BATCH;
    }

    private function getPostIdByTitle($title): int
    {
        global $wpdb;
        $posts = $wpdb->get_results($wpdb->prepare("SELECT * FROM $wpdb->posts WHERE post_title = '%s'", $title));
        if (empty($posts)) {
            return 0;
        }
        return intval($posts[0]->ID);
    }

    private function prepareComparisonMeta($data)
    {
        if (array_key_exists('sections', $data)) {
            $sectionCount = 0;
            $index = 0;

            while (array_key_exists("sections_{$index}_section_name", $data)) {
                $name = trim($data["sections_{$index}_section_name"]);
                $sectionCount++;

                if (strlen($name) == 0) {
                    $sectionCount--;

                    unset($data["sections_{$index}_section_name"]);
                    unset($data["sections_{$index}_title"]);
                    unset($data["sections_{$index}_text"]);
                }

                $index++;
            }

            if ($sectionCount > 0) {
                $data['sections'] = "$sectionCount";
            } else {
                unset($data['sections']);
            }
        }

        return $data;
    }

    private function prepareMeta($data): array
    {
        $cleaned = [];
        $skip = ["", "skip", "post_title", "post_parent", "post_author", "category", "featured_image", "post_excerpt", "post_status", "post_name", "post_content", "id"];
        foreach ($data as $key => $value) {
            if (in_array($key, $skip)) continue;
            // saves array to offer fields
            if(strpos($key, "offer/") === 0) {
                $cleaned_key = str_replace("offer/", "", $key);
                $this->offers[$cleaned_key] = $value;
                continue;
            }
            if(strpos($key, "amazon/") === 0) {
                $cleaned_key = str_replace("amazon/", "", $key);
                $this->amazonAsins[$cleaned_key] = $value;
                continue;
            }
            $key = trim($key);
            $cleaned[$key] = $value;
        }

        if ($this->post_type == 'comparison') {
            $cleaned = $this->prepareComparisonMeta($cleaned);
        }

        return $cleaned;
    }


    private function createPost($data)
    {
        $maybe_existing_post_id = 0;
        $updated = false;
        if(isset($data['id']) && !empty($data['id'])) {
            $maybe_existing_post_id = intval($data['id']);
        }
        $category = isset($data['category']) ? intval($data['category']) : 0;
        $metaFields = $this->prepareMeta($data);
        $post = [
            "ID" => $maybe_existing_post_id,
            "post_type" => $this->post_type,
            "meta_input" => $metaFields,
        ];
        if(isset($data['post_title'])) {
            $post['post_title'] = $data['post_title'];
        }
        if(isset($data['post_content'])) {
            $post['post_content'] = $data['post_content'];
        }
        if(isset($data['post_status'])) {
            $post['post_status'] = $data['post_status'];
        }
        if(isset($data['post_excerpt'])) {
            $post["post_excerpt"] = $data['post_excerpt'];
        }
        if(isset($data['post_name'])) {
            $post['post_name'] = $data['post_name'];
        }
        if(isset($data['post_date'])) {
            $post['post_date'] = date("Y-m-d H:i:s", strtotime($data['post_date']));
            $post['post_date_gmt'] = date("Y-m-d H:i:s", strtotime($data['post_date']));
            $post['edit_date'] = true;
        }
        if(isset($data['post_modified'])) {
            $post['post_modified'] = date("Y-m-d H:i:s", strtotime($data['post_modified']));
            $post['post_modified_gmt'] = date("Y-m-d H:i:s", strtotime($data['post_modified']));
            $post['edit_date'] = true;
        }
        if(isset($data['post_parent']) && !empty($data['post_parent'])) {
            $post['post_parent'] = intval($data['post_parent']);
        }
        if(isset($data['post_author']) && !empty($data['post_author'])) {
            $post['post_author'] = intval($data['post_author']);
        }
        if(isset($data['category'])) {
            $post["tax_input"] = [$this->taxonomy => $category];
        }
        if($maybe_existing_post_id > 0) {
            $post_id = wp_update_post($post, true);
            $updated = true;
        } else {
            $post_id = wp_insert_post($post, true);
        }

        $mappings = [];
        if(isset($data['category']) && get_term($category)) {
            $slug = get_term($category)->slug;
            $field_objects = acf_get_field($slug);
            $this->flattenArray($field_objects, $mappings);
            foreach($mappings as $key => $value) {
                update_post_meta($post_id, "_${key}", $value);
            }
        }

        // finds or downloads image from URL and sets it as featured image
        if(isset($data['featured_image'])) {
            $attachment_id = attachment_url_to_postid($data['featured_image']);
            if($attachment_id == 0) {
                $url = $data['featured_image'];
                $attachment_id = media_sideload_image( $url, $post_id, "", "id" );
            }
            set_post_thumbnail($post_id, $attachment_id);
        }

        if(is_wp_error($post_id)) {
            $this->failed_count++;
            $this->failed[] = $data['post_title'] ?? $data['id'];;
            $this->error_msg = $post_id->get_error_message();

            $post_id = 0;
        } else {
            if($updated) {
                $this->updated_count++;
            } else {
                $this->created_count++;
            }

            if($this->post_type == "comparison") {
                update_post_meta($post_id, "_sections", "field_6256a4d02907e");
                foreach(range(0, $data['sections']) as $i) {
                    update_post_meta($post_id, "_sections_${i}_section_name", "field_6256a5aff928f");
                    update_post_meta($post_id, "_sections_${i}_title", "field_6256a5cef9290");
                    update_post_meta($post_id, "_sections_${i}_text", "field_6256a5e5f9291");
                }
            }

            if(isset($data['featured_image'])) {
                $attachment_id = attachment_url_to_postid($data['featured_image']);
                if($attachment_id) {
                    set_post_thumbnail($post_id, $attachment_id);
                }
            }

            if(!empty($this->offers)) {
                $uniq_id = uniqid();
                $this->offers["uniq_id"] = $uniq_id;
                $this->offers["unique_id"] = $uniq_id; // Required by Content Egg plugin
                if(!array_key_exists("merchant", $this->offers)) {
                    $this->offers["merchant"] = '';
                }
                if(!array_key_exists("percentageSaved", $this->offers)) {
                    $this->offers["percentageSaved"] = 0;
                }
                $this->offers['last_updated'] = time();
                $this->offers['availability'] = '';
                $this->offers['stock_status'] = '';
                $offer_data = [
                    $uniq_id => $this->offers
                ];
                update_post_meta($post_id, "_cegg_data_Offer", $offer_data);
            }

            try {
                if(!empty($this->amazonAsins)) {
                    $module = ContentEgg\application\components\ModuleManager::getInstance()->factory("Amazon");
                    $dataFromAmazon = [];
                    foreach($this->amazonAsins as $locale => $keyword) {
                        if(empty($keyword)) continue;
                        $updateParams = array();
                        $updateParams["locale"] = $locale;
                        $dataFromAmazon = array_merge($dataFromAmazon, $module->doRequest($keyword, $updateParams));
                    }
                    $data = array_map(array('self', 'object2Array'), $dataFromAmazon);
                    if($data) {
                        ContentManager::saveData($data, "Amazon", $post_id);
                        $this->amazon_fetched++;
                    } else {
                        $this->amazon_failed++;
                    }
                }
            }
            catch(\Exception $e) {
                $this->amazon_failed++;
                $this->error_msg = $e->getMessage();
                $this->amazon_errors[] = [
                    'post_id' => $post_id,
                    'asins' => $this->amazonAsins,
                    'error' => $this->errorCodeToMessage($this->error_msg)
                ];
            }
        }

        return $post_id;
    }

    public static function object2Array($object)
    {
        return json_decode(json_encode($object), true);
    }

    private function processValue($value, $processor): string
    {
        switch ($processor) {
            case "select":
                $processedValue = $this->processSelect($value);
                break;
            case "number":
                $processedValue = $this->processNumber($value);
                break;
            case "float":
                $processedValue = $this->processFloat($value);
                break;
            case "date":
                $processedValue = $this->processDate($value);
                break;
            case "boolean":
                $processedValue = $this->processBoolean($value);
                break;
            case "shutter_speed":
                $processedValue = $this->processShutterSpeed($value);
                break;
            case "dual_memory_card":
                $processedValue = $this->processDualMemoryCard($value);
                break;
            case "asin":
                $processedValue = $this->processAsin($value);
                break;
            case "serialize":
                $processedValue = $this->processSerialize($value);
                break;
            case "offer":
                $processedValue = $this->processOffer($value);
                break;
            case "add_to_cart_product_image":
                $processedValue = $this->processMedia($value);
                break;
            default:
                $processedValue = $value;
        }
        return $processedValue;
    }


    private function readFile($fileloc): array
    {
        $row = 1;
        $dataRows = [];
        if (($handle = fopen($fileloc, "r")) !== FALSE) {
            while (($data = fgetcsv($handle, 8000, ",")) !== FALSE) {
                if ($row == 1) {
                    $this->maps = $data;
                } else if ($row == 2) {
                    $this->processors = $data;
                } else if ($row == 3 || $row == 4) {
                    // just skip this row
                } else {
                    $dataRows[] = $data;
                }
                $row++;
            }
            fclose($handle);
        }
        return $dataRows;
    }

    private function processSelect($value): string
    {
        return $value . "";
    }

    private function processNumber($value): string
    {
        return floatval($value);
    }

    private function processFloat($value): string
    {
        return floatval($value);
    }

    private function processDate($value): string
    {
        return $value . "";
    }

    private function processBoolean($value): string
    {
        return $value == "Yes";
    }

    private function processShutterSpeed($value): string
    {
        return str_replace("1/", "", $value);
    }

    private function processDualMemoryCard($value): string
    {
        return $value == "2";
    }
    private function processAsin($value): string
    {
        return $value;
    }
    private function processSerialize($value): string
    {
        $value = json_decode($value);
        return serialize($value);
    }
    private function processOffer($value)
    {
        return $value;
    }

    private function flattenArray($array, &$result, $parentName = '', $subFieldArray = [])
    {
        $name = $array['name'] ?? NULL;
        $key = $array['key'] ?? NULL;
        $subFields = $array['sub_fields'] ?? NULL;
        $flattenedName = trim("{$parentName}_$name", '_');
        if ($name) {
            $result[$flattenedName] = $key;
        }
        $subField = array_shift($subFieldArray);
        if ($subField) {
            $this->flattenArray($subField, $result, $parentName, $subFieldArray);
        }
        if ($subFields) {
            $this->flattenArray([], $result, $flattenedName, $subFields);
        }
        return true;
    }

    private function errorCodeToMessage($code)
    {
        $message = $code;

        if (strpos($code, ':') !== FALSE) {
            [$errorCode, $errorDetails] = explode(':', $code);
            $errorCode = trim($errorCode);
            $message = trim($errorDetails);
        }

        return $message;
    }

    private function processMedia($url)
    {
        $attachmentId = $this->getWPAttachmentID($url);

        if ($attachmentId) {
            return $attachmentId;
        }

        return $this->addMediaFromURL($url);
    }

    private function getWPAttachmentID($url)
    {
        $id = null;
        $fileName = basename($url);

        $queryArgs = [
            'post_type' => 'attachment',
            'post_status' => 'inherit',
            'fields' => 'ids',
            'meta_query' => [[
                'value'   => $fileName,
                'compare' => 'LIKE',
                'key'     => '_wp_attachment_metadata'
            ]]
        ];

        $query = new WP_Query($queryArgs);

        if ($query->have_posts()) {
            foreach ($query->posts as $postId) {
                $meta = wp_get_attachment_metadata($postId);
                $originalFile = basename($meta['file']);
                $croppedImageFiles = wp_list_pluck($meta['sizes'], 'file');

                if ($originalFile == $fileName || in_array($fileName, $croppedImageFiles)) {
                    $id = $postId;
                    break;
                }
            }
        }

        return $id;
    }

    private function addMediaFromURL($url)
    {
        $uploadsDir = wp_upload_dir();
        $image = file_get_contents($url);
        $fileName = basename($url);
        $filePath = "{$uploadsDir['path']}/$fileName";
        $file = fopen($filePath, 'w');

        fwrite($file, $image);
        fclose($file);

        $fileType = wp_check_filetype($fileName, null);
        $attachment = [
            'guid' => $filePath,
            'post_mime_type' => $fileType['type'],
            'post_title' => preg_replace('/\.[^.]+$/', '', $fileName),
            'post_content' => '',
            'post_status' => 'inherit'
        ];
        $attachmentId = wp_insert_attachment($attachment, $filePath);

        require_once(ABSPATH . 'wp-admin/includes/image.php');

        $attachmentData = wp_generate_attachment_metadata($attachmentId, $filePath);

        wp_update_attachment_metadata($attachmentId, $attachmentData);

        return $attachmentId;
    }
}

function maxwell_remove_background_color_from_amazon_image($post_id) {
    $amazon_data = current(get_post_meta($post_id, '_cegg_data_Amazon', true));
    if(array_key_exists('img', $amazon_data)){
        $amazon_image = $amazon_data['img'];

        $client = new GuzzleHttp\Client();
        $res = $client->post('https://api.remove.bg/v1.0/removebg', [
            'multipart' => [
                [
                    'name'     => 'image_url',
                    'contents' => $amazon_image
                ],
                [
                    'name'     => 'size',
                    'contents' => 'auto'
                ]
            ],
            'headers' => [
                'X-Api-Key' => 'GZwAfJHk5bC6HchWp7dnZzV7'
            ]
        ]);

        $filename = md5($amazon_image) . '.png';

        // Upload the image and get its attachment ID
        $uploaded_file = wp_upload_bits( $filename, null, $res->getBody() );
        $attachment_id = wp_insert_attachment( array(
            'guid'           => $uploaded_file['url'],
            'post_mime_type' => $uploaded_file['type'],
            'post_title'     => $filename,
            'post_content'   => '',
            'post_status'    => 'inherit'
        ), $uploaded_file['file'], $post_id );

        // Set the attachment as the featured image for the post
        set_post_thumbnail( $post_id, $attachment_id );
    }
}

function maxwell_checkbox_meta_box_callback( $post ) {
    wp_nonce_field( 'maxwell_remove_background_nonce', 'maxwell_remove_background_nonce' );

    $value = false;
    ?>
    <label for="remove-background">
        <input type="checkbox" name="remove_background" id="remove-background" value="1" <?php checked( $value, 1 ); ?>>
        <?php _e( 'Remove background', 'my-text-domain' ); ?>
    </label>
    <?php
}


function maxwell_remove_background_button() {
    global $post_id;
    if(has_post_thumbnail($post_id)) return;

    $amazon_field_value = get_post_meta($post_id, '_cegg_data_Amazon', true);
    $amazon_data = [];

    if ($amazon_field_value) {
      $amazon_data = current($amazon_field_value);
    }

    if(array_key_exists('img', $amazon_data)){
        add_meta_box(
            'gmz-checkbox-meta-box',
            __( 'Remove Background', 'my-text-domain' ),
            'maxwell_checkbox_meta_box_callback',
            'product',
            'side'
        );
    }
}
add_action('edit_form_after_editor', 'maxwell_remove_background_button');

function maxwell_checkbox_save_postdata( $post_id )
{
    if (!isset($_POST['maxwell_remove_background_nonce'])) {
        return;
    }

    if (!wp_verify_nonce($_POST['maxwell_remove_background_nonce'], 'maxwell_remove_background_nonce')) {
        return;
    }

    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
        return;
    }

    if (!current_user_can('edit_post', $post_id)) {
        return;
    }
    try {
        if (isset($_POST['remove_background'])) {
            maxwell_remove_background_color_from_amazon_image($post_id);
        }
    } catch (Exception $e) {
        error_log($e->getMessage());
    }
}

add_action( 'save_post', 'maxwell_checkbox_save_postdata' );
