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
            // $post_id = wp_update_post($post, true);
            $post_id = $this->updatePost($post, true);
            $updated = true;
        } else {
            // $post_id = wp_insert_post($post, true);
            $post_id = $this->insertPost($post, true);
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

    private function insertPost($postarr, $wp_error = false, $fire_after_hooks = true)
    {
      global $wpdb;

      // Capture original pre-sanitized array for passing into filters.
      $unsanitized_postarr = $postarr;

      $user_id = get_current_user_id();

      $defaults = array(
        'post_author'           => $user_id,
        'post_content'          => '',
        'post_content_filtered' => '',
        'post_title'            => '',
        'post_excerpt'          => '',
        'post_status'           => 'draft',
        'post_type'             => 'post',
        'comment_status'        => '',
        'ping_status'           => '',
        'post_password'         => '',
        'to_ping'               => '',
        'pinged'                => '',
        'post_parent'           => 0,
        'menu_order'            => 0,
        'guid'                  => '',
        'import_id'             => 0,
        'context'               => '',
        'post_date'             => '',
        'post_date_gmt'         => '',
      );

      $postarr = wp_parse_args( $postarr, $defaults );

      unset( $postarr['filter'] );

      $postarr = sanitize_post( $postarr, 'db' );

      // Are we updating or creating?
      $post_id = 0;
      $update  = false;
      $guid    = $postarr['guid'];

      if ( ! empty( $postarr['ID'] ) ) {
        $update = true;

        // Get the post ID and GUID.
        $post_id     = $postarr['ID'];
        $post_before = get_post( $post_id );

        if ( is_null( $post_before ) ) {
          if ( $wp_error ) {
            return new WP_Error( 'invalid_post', __( 'Invalid post ID.' ) );
          }
          return 0;
        }

        $guid            = get_post_field( 'guid', $post_id );
        $previous_status = get_post_field( 'post_status', $post_id );
      } else {
        $previous_status = 'new';
        $post_before     = null;
      }

      $post_type = empty( $postarr['post_type'] ) ? 'post' : $postarr['post_type'];

      $post_title   = $postarr['post_title'];
      $post_content = $postarr['post_content'];
      $post_excerpt = $postarr['post_excerpt'];

      if ( isset( $postarr['post_name'] ) ) {
        $post_name = $postarr['post_name'];
      } elseif ( $update ) {
        // For an update, don't modify the post_name if it wasn't supplied as an argument.
        $post_name = $post_before->post_name;
      }

      $maybe_empty = 'attachment' !== $post_type
        && ! $post_content && ! $post_title && ! $post_excerpt
        && post_type_supports( $post_type, 'editor' )
        && post_type_supports( $post_type, 'title' )
        && post_type_supports( $post_type, 'excerpt' );

      /**
       * Filters whether the post should be considered "empty".
       *
       * The post is considered "empty" if both:
       * 1. The post type supports the title, editor, and excerpt fields
       * 2. The title, editor, and excerpt fields are all empty
       *
       * Returning a truthy value from the filter will effectively short-circuit
       * the new post being inserted and return 0. If $wp_error is true, a WP_Error
       * will be returned instead.
       *
       * @since 3.3.0
       *
       * @param bool  $maybe_empty Whether the post should be considered "empty".
       * @param array $postarr     Array of post data.
       */
      if ( apply_filters( 'wp_insert_post_empty_content', $maybe_empty, $postarr ) ) {
        if ( $wp_error ) {
          return new WP_Error( 'empty_content', __( 'Content, title, and excerpt are empty.' ) );
        } else {
          return 0;
        }
      }

      $post_status = empty( $postarr['post_status'] ) ? 'draft' : $postarr['post_status'];

      if ( 'attachment' === $post_type && ! in_array( $post_status, array( 'inherit', 'private', 'trash', 'auto-draft' ), true ) ) {
        $post_status = 'inherit';
      }

      if ( ! empty( $postarr['post_category'] ) ) {
        // Filter out empty terms.
        $post_category = array_filter( $postarr['post_category'] );
      } elseif ( $update && ! isset( $postarr['post_category'] ) ) {
        $post_category = $post_before->post_category;
      }

      // Make sure we set a valid category.
      if ( empty( $post_category ) || 0 === count( $post_category ) || ! is_array( $post_category ) ) {
        // 'post' requires at least one category.
        if ( 'post' === $post_type && 'auto-draft' !== $post_status ) {
          $post_category = array( get_option( 'default_category' ) );
        } else {
          $post_category = array();
        }
      }

      /*
       * Don't allow contributors to set the post slug for pending review posts.
       *
       * For new posts check the primitive capability, for updates check the meta capability.
       */
      if ( 'pending' === $post_status ) {
        $post_type_object = get_post_type_object( $post_type );

        if ( ! $update && $post_type_object && ! current_user_can( $post_type_object->cap->publish_posts ) ) {
          $post_name = '';
        } elseif ( $update && ! current_user_can( 'publish_post', $post_id ) ) {
          $post_name = '';
        }
      }

      /*
       * Create a valid post name. Drafts and pending posts are allowed to have
       * an empty post name.
       */
      if ( empty( $post_name ) ) {
        if ( ! in_array( $post_status, array( 'draft', 'pending', 'auto-draft' ), true ) ) {
          $post_name = sanitize_title( $post_title );
        } else {
          $post_name = '';
        }
      } else {
        // On updates, we need to check to see if it's using the old, fixed sanitization context.
        $check_name = sanitize_title( $post_name, '', 'old-save' );

        if ( $update
          && strtolower( urlencode( $post_name ) ) === $check_name
          && get_post_field( 'post_name', $post_id ) === $check_name
        ) {
          $post_name = $check_name;
        } else { // New post, or slug has changed.
          $post_name = sanitize_title( $post_name );
        }
      }

      /*
       * Resolve the post date from any provided post date or post date GMT strings;
       * if none are provided, the date will be set to now.
       */
      $post_date = wp_resolve_post_date( $postarr['post_date'], $postarr['post_date_gmt'] );

      if ( ! $post_date ) {
        if ( $wp_error ) {
          return new WP_Error( 'invalid_date', __( 'Invalid date.' ) );
        } else {
          return 0;
        }
      }

      if ( empty( $postarr['post_date_gmt'] ) || '0000-00-00 00:00:00' === $postarr['post_date_gmt'] ) {
        if ( ! in_array( $post_status, get_post_stati( array( 'date_floating' => true ) ), true ) ) {
          $post_date_gmt = get_gmt_from_date( $post_date );
        } else {
          $post_date_gmt = '0000-00-00 00:00:00';
        }
      } else {
        $post_date_gmt = $postarr['post_date_gmt'];
      }

      if ( $update || '0000-00-00 00:00:00' === $post_date ) {
        $post_modified     = current_time( 'mysql' );
        $post_modified_gmt = current_time( 'mysql', 1 );
      } else {
        $post_modified     = $post_date;
        $post_modified_gmt = $post_date_gmt;
      }

      if ( 'attachment' !== $post_type ) {
        $now = gmdate( 'Y-m-d H:i:s' );

        if ( 'publish' === $post_status ) {
          if ( strtotime( $post_date_gmt ) - strtotime( $now ) >= MINUTE_IN_SECONDS ) {
            $post_status = 'future';
          }
        } elseif ( 'future' === $post_status ) {
          if ( strtotime( $post_date_gmt ) - strtotime( $now ) < MINUTE_IN_SECONDS ) {
            $post_status = 'publish';
          }
        }
      }

      // Comment status.
      if ( empty( $postarr['comment_status'] ) ) {
        if ( $update ) {
          $comment_status = 'closed';
        } else {
          $comment_status = get_default_comment_status( $post_type );
        }
      } else {
        $comment_status = $postarr['comment_status'];
      }

      // These variables are needed by compact() later.
      $post_content_filtered = $postarr['post_content_filtered'];
      $post_author           = isset( $postarr['post_author'] ) ? $postarr['post_author'] : $user_id;
      $ping_status           = empty( $postarr['ping_status'] ) ? get_default_comment_status( $post_type, 'pingback' ) : $postarr['ping_status'];
      $to_ping               = isset( $postarr['to_ping'] ) ? sanitize_trackback_urls( $postarr['to_ping'] ) : '';
      $pinged                = isset( $postarr['pinged'] ) ? $postarr['pinged'] : '';
      $import_id             = isset( $postarr['import_id'] ) ? $postarr['import_id'] : 0;

      /*
       * The 'wp_insert_post_parent' filter expects all variables to be present.
       * Previously, these variables would have already been extracted
       */
      if ( isset( $postarr['menu_order'] ) ) {
        $menu_order = (int) $postarr['menu_order'];
      } else {
        $menu_order = 0;
      }

      $post_password = isset( $postarr['post_password'] ) ? $postarr['post_password'] : '';
      if ( 'private' === $post_status ) {
        $post_password = '';
      }

      if ( isset( $postarr['post_parent'] ) ) {
        $post_parent = (int) $postarr['post_parent'];
      } else {
        $post_parent = 0;
      }

      $new_postarr = array_merge(
        array(
          'ID' => $post_id,
        ),
        compact( array_diff( array_keys( $defaults ), array( 'context', 'filter' ) ) )
      );

      /**
       * Filters the post parent -- used to check for and prevent hierarchy loops.
       *
       * @since 3.1.0
       *
       * @param int   $post_parent Post parent ID.
       * @param int   $post_id     Post ID.
       * @param array $new_postarr Array of parsed post data.
       * @param array $postarr     Array of sanitized, but otherwise unmodified post data.
       */
      $post_parent = apply_filters( 'wp_insert_post_parent', $post_parent, $post_id, $new_postarr, $postarr );

      /*
       * If the post is being untrashed and it has a desired slug stored in post meta,
       * reassign it.
       */
      if ( 'trash' === $previous_status && 'trash' !== $post_status ) {
        $desired_post_slug = get_post_meta( $post_id, '_wp_desired_post_slug', true );

        if ( $desired_post_slug ) {
          delete_post_meta( $post_id, '_wp_desired_post_slug' );
          $post_name = $desired_post_slug;
        }
      }

      // If a trashed post has the desired slug, change it and let this post have it.
      if ( 'trash' !== $post_status && $post_name ) {
        /**
         * Filters whether or not to add a `__trashed` suffix to trashed posts that match the name of the updated post.
         *
         * @since 5.4.0
         *
         * @param bool   $add_trashed_suffix Whether to attempt to add the suffix.
         * @param string $post_name          The name of the post being updated.
         * @param int    $post_id            Post ID.
         */
        $add_trashed_suffix = apply_filters( 'add_trashed_suffix_to_trashed_posts', true, $post_name, $post_id );

        if ( $add_trashed_suffix ) {
          wp_add_trashed_suffix_to_post_name_for_trashed_posts( $post_name, $post_id );
        }
      }

      // When trashing an existing post, change its slug to allow non-trashed posts to use it.
      if ( 'trash' === $post_status && 'trash' !== $previous_status && 'new' !== $previous_status ) {
        $post_name = wp_add_trashed_suffix_to_post_name_for_post( $post_id );
      }

      $post_name = wp_unique_post_slug( $post_name, $post_id, $post_status, $post_type, $post_parent );

      // Don't unslash.
      $post_mime_type = isset( $postarr['post_mime_type'] ) ? $postarr['post_mime_type'] : '';

      // Expected_slashed (everything!).
      $data = compact(
        'post_author',
        'post_date',
        'post_date_gmt',
        'post_content',
        'post_content_filtered',
        'post_title',
        'post_excerpt',
        'post_status',
        'post_type',
        'comment_status',
        'ping_status',
        'post_password',
        'post_name',
        'to_ping',
        'pinged',
        'post_modified',
        'post_modified_gmt',
        'post_parent',
        'menu_order',
        'post_mime_type',
        'guid'
      );

      $emoji_fields = array( 'post_title', 'post_content', 'post_excerpt' );

      foreach ( $emoji_fields as $emoji_field ) {
        if ( isset( $data[ $emoji_field ] ) ) {
          $charset = $wpdb->get_col_charset( $wpdb->posts, $emoji_field );

          if ( 'utf8' === $charset ) {
            $data[ $emoji_field ] = wp_encode_emoji( $data[ $emoji_field ] );
          }
        }
      }

      if ( 'attachment' === $post_type ) {
        /**
         * Filters attachment post data before it is updated in or added to the database.
         *
         * @since 3.9.0
         * @since 5.4.1 The `$unsanitized_postarr` parameter was added.
         * @since 6.0.0 The `$update` parameter was added.
         *
         * @param array $data                An array of slashed, sanitized, and processed attachment post data.
         * @param array $postarr             An array of slashed and sanitized attachment post data, but not processed.
         * @param array $unsanitized_postarr An array of slashed yet *unsanitized* and unprocessed attachment post data
         *                                   as originally passed to wp_insert_post().
         * @param bool  $update              Whether this is an existing attachment post being updated.
         */
        $data = apply_filters( 'wp_insert_attachment_data', $data, $postarr, $unsanitized_postarr, $update );
      } else {
        /**
         * Filters slashed post data just before it is inserted into the database.
         *
         * @since 2.7.0
         * @since 5.4.1 The `$unsanitized_postarr` parameter was added.
         * @since 6.0.0 The `$update` parameter was added.
         *
         * @param array $data                An array of slashed, sanitized, and processed post data.
         * @param array $postarr             An array of sanitized (and slashed) but otherwise unmodified post data.
         * @param array $unsanitized_postarr An array of slashed yet *unsanitized* and unprocessed post data as
         *                                   originally passed to wp_insert_post().
         * @param bool  $update              Whether this is an existing post being updated.
         */
        $data = apply_filters( 'wp_insert_post_data', $data, $postarr, $unsanitized_postarr, $update );
      }

      $data  = wp_unslash( $data );
      $where = array( 'ID' => $post_id );

      if ( $update ) {
        /**
         * Fires immediately before an existing post is updated in the database.
         *
         * @since 2.5.0
         *
         * @param int   $post_id Post ID.
         * @param array $data    Array of unslashed post data.
         */
        do_action( 'pre_post_update', $post_id, $data );

        if ( false === $wpdb->update( $wpdb->posts, $data, $where ) ) {
          if ( $wp_error ) {
            if ( 'attachment' === $post_type ) {
              $message = __( 'Could not update attachment in the database.' );
            } else {
              $message = __( 'Could not update post in the database.' );
            }

            return new WP_Error( 'db_update_error', $message, $wpdb->last_error );
          } else {
            return 0;
          }
        }
      } else {
        // If there is a suggested ID, use it if not already present.
        if ( ! empty( $import_id ) ) {
          $import_id = (int) $import_id;

          if ( ! $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM $wpdb->posts WHERE ID = %d", $import_id ) ) ) {
            $data['ID'] = $import_id;
          }
        }

        if ( false === $wpdb->insert( $wpdb->posts, $data ) ) {
          if ( $wp_error ) {
            if ( 'attachment' === $post_type ) {
              $message = __( 'Could not insert attachment into the database.' );
            } else {
              $message = __( 'Could not insert post into the database.' );
            }

            return new WP_Error( 'db_insert_error', $message, $wpdb->last_error );
          } else {
            return 0;
          }
        }

        $post_id = (int) $wpdb->insert_id;

        // Use the newly generated $post_id.
        $where = array( 'ID' => $post_id );
      }

      if ( empty( $data['post_name'] ) && ! in_array( $data['post_status'], array( 'draft', 'pending', 'auto-draft' ), true ) ) {
        $data['post_name'] = wp_unique_post_slug( sanitize_title( $data['post_title'], $post_id ), $post_id, $data['post_status'], $post_type, $post_parent );

        $wpdb->update( $wpdb->posts, array( 'post_name' => $data['post_name'] ), $where );
        clean_post_cache( $post_id );
      }

      if ( is_object_in_taxonomy( $post_type, 'category' ) ) {
        wp_set_post_categories( $post_id, $post_category );
      }

      if ( isset( $postarr['tags_input'] ) && is_object_in_taxonomy( $post_type, 'post_tag' ) ) {
        wp_set_post_tags( $post_id, $postarr['tags_input'] );
      }

      // Add default term for all associated custom taxonomies.
      if ( 'auto-draft' !== $post_status ) {
        foreach ( get_object_taxonomies( $post_type, 'object' ) as $taxonomy => $tax_object ) {

          if ( ! empty( $tax_object->default_term ) ) {

            // Filter out empty terms.
            if ( isset( $postarr['tax_input'][ $taxonomy ] ) && is_array( $postarr['tax_input'][ $taxonomy ] ) ) {
              $postarr['tax_input'][ $taxonomy ] = array_filter( $postarr['tax_input'][ $taxonomy ] );
            }

            // Passed custom taxonomy list overwrites the existing list if not empty.
            $terms = wp_get_object_terms( $post_id, $taxonomy, array( 'fields' => 'ids' ) );
            if ( ! empty( $terms ) && empty( $postarr['tax_input'][ $taxonomy ] ) ) {
              $postarr['tax_input'][ $taxonomy ] = $terms;
            }

            if ( empty( $postarr['tax_input'][ $taxonomy ] ) ) {
              $default_term_id = get_option( 'default_term_' . $taxonomy );
              if ( ! empty( $default_term_id ) ) {
                $postarr['tax_input'][ $taxonomy ] = array( (int) $default_term_id );
              }
            }
          }
        }
      }

      // New-style support for all custom taxonomies.
      if ( ! empty( $postarr['tax_input'] ) ) {
        foreach ( $postarr['tax_input'] as $taxonomy => $tags ) {
          $taxonomy_obj = get_taxonomy( $taxonomy );

          if ( ! $taxonomy_obj ) {
            /* translators: %s: Taxonomy name. */
            _doing_it_wrong( __FUNCTION__, sprintf( __( 'Invalid taxonomy: %s.' ), $taxonomy ), '4.4.0' );
            continue;
          }

          // array = hierarchical, string = non-hierarchical.
          if ( is_array( $tags ) ) {
            $tags = array_filter( $tags );
          }

          if ( current_user_can( $taxonomy_obj->cap->assign_terms ) ) {
            wp_set_post_terms( $post_id, $tags, $taxonomy );
          }
        }
      }

      if ( ! empty( $postarr['meta_input'] ) ) {
        foreach ( $postarr['meta_input'] as $field => $value ) {
          update_post_meta( $post_id, $field, $value );
        }
      }

      $current_guid = get_post_field( 'guid', $post_id );

      // Set GUID.
      if ( ! $update && '' === $current_guid ) {
        $wpdb->update( $wpdb->posts, array( 'guid' => get_permalink( $post_id ) ), $where );
      }

      if ( 'attachment' === $postarr['post_type'] ) {
        if ( ! empty( $postarr['file'] ) ) {
          update_attached_file( $post_id, $postarr['file'] );
        }

        if ( ! empty( $postarr['context'] ) ) {
          add_post_meta( $post_id, '_wp_attachment_context', $postarr['context'], true );
        }
      }

      // Set or remove featured image.
      if ( isset( $postarr['_thumbnail_id'] ) ) {
        $thumbnail_support = current_theme_supports( 'post-thumbnails', $post_type ) && post_type_supports( $post_type, 'thumbnail' ) || 'revision' === $post_type;

        if ( ! $thumbnail_support && 'attachment' === $post_type && $post_mime_type ) {
          if ( wp_attachment_is( 'audio', $post_id ) ) {
            $thumbnail_support = post_type_supports( 'attachment:audio', 'thumbnail' ) || current_theme_supports( 'post-thumbnails', 'attachment:audio' );
          } elseif ( wp_attachment_is( 'video', $post_id ) ) {
            $thumbnail_support = post_type_supports( 'attachment:video', 'thumbnail' ) || current_theme_supports( 'post-thumbnails', 'attachment:video' );
          }
        }

        if ( $thumbnail_support ) {
          $thumbnail_id = (int) $postarr['_thumbnail_id'];
          if ( -1 === $thumbnail_id ) {
            delete_post_thumbnail( $post_id );
          } else {
            set_post_thumbnail( $post_id, $thumbnail_id );
          }
        }
      }

      clean_post_cache( $post_id );

      $post = get_post( $post_id );

      if ( ! empty( $postarr['page_template'] ) ) {
        $post->page_template = $postarr['page_template'];
        $page_templates      = wp_get_theme()->get_page_templates( $post );

        if ( 'default' !== $postarr['page_template'] && ! isset( $page_templates[ $postarr['page_template'] ] ) ) {
          if ( $wp_error ) {
            return new WP_Error( 'invalid_page_template', __( 'Invalid page template.' ) );
          }

          update_post_meta( $post_id, '_wp_page_template', 'default' );
        } else {
          update_post_meta( $post_id, '_wp_page_template', $postarr['page_template'] );
        }
      }

      if ( 'attachment' !== $postarr['post_type'] ) {
        wp_transition_post_status( $data['post_status'], $previous_status, $post );
      } else {
        if ( $update ) {
          /**
           * Fires once an existing attachment has been updated.
           *
           * @since 2.0.0
           *
           * @param int $post_id Attachment ID.
           */
          do_action( 'edit_attachment', $post_id );

          $post_after = get_post( $post_id );

          /**
           * Fires once an existing attachment has been updated.
           *
           * @since 4.4.0
           *
           * @param int     $post_id      Post ID.
           * @param WP_Post $post_after   Post object following the update.
           * @param WP_Post $post_before  Post object before the update.
           */
          do_action( 'attachment_updated', $post_id, $post_after, $post_before );
        } else {

          /**
           * Fires once an attachment has been added.
           *
           * @since 2.0.0
           *
           * @param int $post_id Attachment ID.
           */
          do_action( 'add_attachment', $post_id );
        }

        return $post_id;
      }

      if ( $update ) {
        /**
         * Fires once an existing post has been updated.
         *
         * The dynamic portion of the hook name, `$post->post_type`, refers to
         * the post type slug.
         *
         * Possible hook names include:
         *
         *  - `edit_post_post`
         *  - `edit_post_page`
         *
         * @since 5.1.0
         *
         * @param int     $post_id Post ID.
         * @param WP_Post $post    Post object.
         */
        do_action( "edit_post_{$post->post_type}", $post_id, $post );

        /**
         * Fires once an existing post has been updated.
         *
         * @since 1.2.0
         *
         * @param int     $post_id Post ID.
         * @param WP_Post $post    Post object.
         */
        do_action( 'edit_post', $post_id, $post );

        $post_after = get_post( $post_id );

        /**
         * Fires once an existing post has been updated.
         *
         * @since 3.0.0
         *
         * @param int     $post_id      Post ID.
         * @param WP_Post $post_after   Post object following the update.
         * @param WP_Post $post_before  Post object before the update.
         */
        do_action( 'post_updated', $post_id, $post_after, $post_before );
      }

      /**
       * Fires once a post has been saved.
       *
       * The dynamic portion of the hook name, `$post->post_type`, refers to
       * the post type slug.
       *
       * Possible hook names include:
       *
       *  - `save_post_post`
       *  - `save_post_page`
       *
       * @since 3.7.0
       *
       * @param int     $post_id Post ID.
       * @param WP_Post $post    Post object.
       * @param bool    $update  Whether this is an existing post being updated.
       */
      do_action( "save_post_{$post->post_type}", $post_id, $post, $update );

      /**
       * Fires once a post has been saved.
       *
       * @since 1.5.0
       *
       * @param int     $post_id Post ID.
       * @param WP_Post $post    Post object.
       * @param bool    $update  Whether this is an existing post being updated.
       */
      do_action( 'save_post', $post_id, $post, $update );

      /**
       * Fires once a post has been saved.
       *
       * @since 2.0.0
       *
       * @param int     $post_id Post ID.
       * @param WP_Post $post    Post object.
       * @param bool    $update  Whether this is an existing post being updated.
       */
      do_action( 'wp_insert_post', $post_id, $post, $update );

      if ( $fire_after_hooks ) {
        wp_after_insert_post( $post, $update, $post_before );
      }

      return $post_id;
    }

    private function updatePost($postarr = array(), $wp_error = false, $fire_after_hooks = true)
    {
      if ( is_object( $postarr ) ) {
        // Non-escaped post was passed.
        $postarr = get_object_vars( $postarr );
        $postarr = wp_slash( $postarr );
      }

      // First, get all of the original fields.
      $post = get_post( $postarr['ID'], ARRAY_A );

      if ( is_null( $post ) ) {
        if ( $wp_error ) {
          return new WP_Error( 'invalid_post', __( 'Invalid post ID.' ) );
        }
        return 0;
      }

      // Escape data pulled from DB.
      $post = wp_slash( $post );

      // Passed post category list overwrites existing category list if not empty.
      if ( isset( $postarr['post_category'] ) && is_array( $postarr['post_category'] )
        && count( $postarr['post_category'] ) > 0
      ) {
        $post_cats = $postarr['post_category'];
      } else {
        $post_cats = $post['post_category'];
      }

      // Drafts shouldn't be assigned a date unless explicitly done so by the user.
      if ( isset( $post['post_status'] )
        && in_array( $post['post_status'], array( 'draft', 'pending', 'auto-draft' ), true )
        && empty( $postarr['edit_date'] ) && ( '0000-00-00 00:00:00' === $post['post_date_gmt'] )
      ) {
        $clear_date = true;
      } else {
        $clear_date = false;
      }

      // Merge old and new fields with new fields overwriting old ones.
      $postarr                  = array_merge( $post, $postarr );
      $postarr['post_category'] = $post_cats;
      if ( $clear_date ) {
        $postarr['post_date']     = current_time( 'mysql' );
        $postarr['post_date_gmt'] = '';
      }

      if ( 'attachment' === $postarr['post_type'] ) {
        return wp_insert_attachment( $postarr, false, 0, $wp_error );
      }

      // Discard 'tags_input' parameter if it's the same as existing post tags.
      if ( isset( $postarr['tags_input'] ) && is_object_in_taxonomy( $postarr['post_type'], 'post_tag' ) ) {
        $tags      = get_the_terms( $postarr['ID'], 'post_tag' );
        $tag_names = array();

        if ( $tags && ! is_wp_error( $tags ) ) {
          $tag_names = wp_list_pluck( $tags, 'name' );
        }

        if ( $postarr['tags_input'] === $tag_names ) {
          unset( $postarr['tags_input'] );
        }
      }

      return wp_insert_post( $postarr, $wp_error, $fire_after_hooks );
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
