<?php
namespace KCFH\Streaming;
if (!defined('ABSPATH')) exit;


class CPT_Client {

  const POST_TYPE = 'kcfh_client';
  const META_START_AT = '_kcfh_live_start_at'; // UTC timestamp
  const META_END_AT   = '_kcfh_live_end_at';   // UTC timestamp
  const KCFH_ASSET_ID = '_kcfh_asset_id';

  public static function init() {
    add_action('init', [__CLASS__, 'register']);
    add_action('add_meta_boxes', [__CLASS__, 'meta_boxes']);
    add_action('save_post_' . self::POST_TYPE, [__CLASS__, 'save_meta']);

  }

  // There are many different types of content in WordPress. 
  // These content types are normally described as Post Types

  // Post types can support any number of built-in core features such as 
  // meta boxes, 
  // custom fields, 
  // post thumbnails, 
  // post statuses, 
  // comments, 
  // and more.
  public static function register() {
    register_post_type(self::POST_TYPE, [
      'label' => 'Clients',//general label name for admin
      'labels' => [
        'name' => 'Clients', //name for post type
        'singular_name' => 'Client',//name for one object
        'add_new_item' => 'Add New Client', //label for adding new singular item
        'edit_item' => 'Edit Client', // labe lfor editing a singular item
      ],
      'public' => false, //post type not publically querable
      'show_ui' => true, //want admin to be able to interact
      'show_in_menu' => false, // hide from left side, Admin_UI class will manage UI
      'supports' => ['title', 'thumbnail'], //tells Wordpress that editor support - 'post title' and 'featured image'
      'capability_type' => 'post', // allow built in post capabilities ie edit and delete
      'map_meta_cap' => true, // high-level  capabilities for different roles, can customise for different roles
    ]);
  }


#region Set up MetaBoxes
  public static function meta_boxes() {
    //Main details box
    add_meta_box(
      'kcfh_client_details', 
      'Client Details', 
      [__CLASS__, 'render_meta'],
      self::POST_TYPE, 
      'normal', 
      'high');

      add_meta_box(
      'kcfh_schedule_debug',
      'Schedule Debug',
      [__CLASS__, 'SetUpScheduleDebuger'],
      self::POST_TYPE,
      'side',
      'low'
    );

  }

  public static function SetUpScheduleDebuger($post){
    $startUtc = (int) get_post_meta($post->ID, \KCFH\Streaming\CPT_Client::META_START_AT, true);
    $endUtc   = (int) get_post_meta($post->ID, \KCFH\Streaming\CPT_Client::META_END_AT, true);
    
    $nextStart = wp_next_scheduled(\KCFH\Streaming\Live_Scheduler::HOOK_START, [$post->ID]);
    $nextEnd   = wp_next_scheduled(\KCFH\Streaming\Live_Scheduler::HOOK_END,   [$post->ID]);

    echo '<p><strong>Stored UTC</strong><br>';
    echo 'Start: '.($startUtc ? date_i18n('Y-m-d H:i:s', $startUtc) : '—').' UTC<br>';
    echo 'End: '.($endUtc ? date_i18n('Y-m-d H:i:s', $endUtc) : '—').' UTC</p>';

    echo '<p><strong>Next scheduled</strong><br>';
    echo 'Start: '.($nextStart ? date_i18n('Y-m-d H:i:s', $nextStart) : '—').' (site time)<br>';
    echo 'End: '.($nextEnd ? date_i18n('Y-m-d H:i:s', $nextEnd) : '—').' (site time)</p>';

    $live = (int) get_option(\KCFH\Streaming\Admin_UI::OPT_LIVE_CLIENT, 0);
    echo '<p><strong>Current Live Client:</strong> '.($live ?: 'none').' </p>';
  }

  public static function render_meta($post) {
    //creates the nonce
    //kcfh_client_save - the action
    //kcfh_client_nonce - the field name
    wp_nonce_field('kcfh_client_save', 'kcfh_client_nonce');
    //$asset_id = get_post_meta($post->ID, '_kcfh_asset_id', true);


    ?>
    <style>
      .kcfh-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px;max-width:800px}
    </style>

    <div class="kcfh-grid">

      <?php 
        self::render_client_details($post);
        self::render_stream_timeframe_metabox($post); 
        self::render_asset_metabox($post);
      ?>
      
      </br>
      <?php
  }
#endregion 










#region render client info functions
public static function render_asset_metabox($post){
        // Current selection
        // if meta exists will get the asset ID
        // ID comes from 'Save Meta'function
        $current_asset_id = get_post_meta(
          $post->ID, //Current post's ID, get ID from the Post object
          self::KCFH_ASSET_ID, //The Meta key we use to store the chosen Mux Asset ID for the client
          true // return a single value instead of an array
        );

        // Pull recent ready assets from Mux (for labels)
        $result = \KCFH\Streaming\Asset_Service::fetch_assets([
          'limit'     => 100,
          'order'     => 'created_at',
          'direction' => 'desc',
          'status'    => 'ready',
        ], 60);

        $assets = (!is_wp_error($result) && !empty($result['assets'])) ? $result['assets'] : [];

        // Map of asset_id => client_id for already-assigned assets
        $assigned_ids = self::GetPostsWithAssetIDS();

        $playback   = get_post_meta($post->ID, '_kcfh_playback_id', true);
        $vod_title  = get_post_meta($post->ID, '_kcfh_vod_title', true);
        $ext_id     = get_post_meta($post->ID, '_kcfh_external_id', true);
      ?>

      <hr>

      <!-- Load the Mux player webcomponent as an ES module from jsDelivr -->
      <!-- An ES Module (ECMAScript Module) is a standardized way to organize and share JavaScript code -->
      <!-- ECMA stands for European Computer Manufacturers Association.  -->

 
        <!-- will deal with profile pictures later -->
    <!--<p><small>Use the Featured Image box for the profile photo.</small></p> -->
    

    <?php


}

  public static function render_stream_timeframe_metabox($post) {
    wp_nonce_field('kcfh_stream_window', 'kcfh_stream_window_nonce');

    $startUtc = (int) get_post_meta($post->ID, self::META_START_AT, true);
    $endUtc   = (int) get_post_meta($post->ID, self::META_END_AT, true);

    // Convert stored UTC -> site local string for <input type="datetime-local">
    $startLocal = self::utc_to_local_input_value($startUtc);
    $endLocal   = self::utc_to_local_input_value($endUtc);
    //_e is a wordpress function that marks the string for translation
    ?>
    <p>
      <label for="kcfh_live_start_local"><strong><?php _e('Stream Scheduled START time and Date', 'kcfh'); ?></strong></label>
      <input type="datetime-local" id="kcfh_live_start_local" name="kcfh_live_start_local" value="<?php echo esc_attr($startLocal); ?>" class="widefat">
    </p>

    <p><label for="kcfh_live_end_local"><strong><?php _e('Stream Scheduled END time and date', 'kcfh'); ?></strong></label>
    <input type="datetime-local" id="kcfh_live_end_local" name="kcfh_live_end_local" value="<?php echo esc_attr($endLocal); ?>" class="widefat"></p>

    <p class="description">
        <?php _e('', 'kcfh'); ?>
    </p>

    
    <?php
  }

  public static function render_client_details($post){
    $dob  = get_post_meta($post->ID, '_kcfh_dob', true);
    $dod  = get_post_meta($post->ID, '_kcfh_dod', true);
    $show_gallery = get_post_meta($post->ID, '_kcfh_show_in_gallery', true);
    

    // For existing posts with no meta yet, treat as "true" in the UI
    $show_checked = ($show_gallery === '' || $show_gallery === '1');
    ?>
      
      <label>
        <input type="checkbox"
               name="kcfh_show_in_gallery"
               value="1"
               <?php checked($show_checked, true); ?>>
          Show this client in public gallery
      </label>

      <br><!-- New Line -->

      <p>
        <label for="kcfh_dob">Date of Birth</label><br>
        <input type="date" id="kcfh_dob" name="kcfh_dob" value="<?= esc_attr($dob); ?>">
      </p>

      <p>
        <label for="kcfh_dod">Date of Death</label><br>
        <input type="date" id="kcfh_dod" name="kcfh_dod" value="<?= esc_attr($dod); ?>">
      </p>
      
    <?php
  }

#endregion 









#region Save Meta Function
  public static function save_meta($post_id) {
    if (!isset($_POST['kcfh_client_nonce']) || !wp_verify_nonce($_POST['kcfh_client_nonce'], 'kcfh_client_save')) return;
    if (!current_user_can('edit_post', $post_id)) return;
    if (wp_is_post_autosave($post_id) || wp_is_post_revision($post_id)) return;

    $dob = isset($_POST['kcfh_dob']) ? sanitize_text_field($_POST['kcfh_dob']) : '';
    $dod = isset($_POST['kcfh_dod']) ? sanitize_text_field($_POST['kcfh_dod']) : '';
    update_post_meta($post_id, '_kcfh_dob', $dob);
    update_post_meta($post_id, '_kcfh_dod', $dod);

    //update show in gellery checkbox
    $show_gallery = isset($_POST['kcfh_show_in_gallery']) ? '1' : '0';
    update_post_meta($post_id, '_kcfh_show_in_gallery', $show_gallery);

    // VOD dropdown selection
    $new_asset_id = isset($_POST['kcfh_asset_id']) ? sanitize_text_field($_POST['kcfh_asset_id']) : '';
    $old_asset_id = get_post_meta($post_id, self::KCFH_ASSET_ID, true);

  if ($new_asset_id === '') {

      // Unassign
      delete_post_meta($post_id, self::KCFH_ASSET_ID);
      delete_post_meta($post_id, '_kcfh_playback_id');
      delete_post_meta($post_id, '_kcfh_vod_title');
      delete_post_meta($post_id, '_kcfh_external_id');
    } elseif ($new_asset_id !== $old_asset_id) {
      // Make it exclusive: unassign this asset from other clients
      $others = get_posts([
        'post_type'      => self::POST_TYPE,
        'posts_per_page' => -1,
        'fields'         => 'ids',
        'no_found_rows'  => true,
        'post__not_in'   => [$post_id],
        'meta_query'     => [[ 'key' => self::KCFH_ASSET_ID, 'value' => $new_asset_id, 'compare' => '=' ]],
      ]);
      foreach ($others as $oid) {
        delete_post_meta($oid, self::KCFH_ASSET_ID);
        delete_post_meta($oid, '_kcfh_playback_id');
        delete_post_meta($oid, '_kcfh_vod_title');
        delete_post_meta($oid, '_kcfh_external_id');
      }

      // Fetch asset details from Mux for playback/meta
      $asset = \KCFH\Streaming\Asset_Service::get_asset($new_asset_id, 120);
      if (!is_wp_error($asset)) {
        $playback = \KCFH\Streaming\Asset_Service::first_public_playback_id($asset);
        $title    = !empty($asset['title']) ? $asset['title']
                  : (!empty($asset['passthrough']) ? $asset['passthrough'] : '');
        $ext_id   = !empty($asset['external_id']) ? $asset['external_id'] : '';

        update_post_meta($post_id, self::KCFH_ASSET_ID,     $new_asset_id);
        if ($playback) update_post_meta($post_id, '_kcfh_playback_id', $playback);
        update_post_meta($post_id, '_kcfh_vod_title',   sanitize_text_field($title));
        update_post_meta($post_id, '_kcfh_external_id', sanitize_text_field($ext_id));
      } else {
        // Save at least the chosen ID; you can refresh meta later
        update_post_meta($post_id, self::KCFH_ASSET_ID, $new_asset_id);
      }
    }


    // Handle time saving
    $startLocal = isset($_POST['kcfh_live_start_local']) ? sanitize_text_field($_POST['kcfh_live_start_local']) : '';
    $endLocal   = isset($_POST['kcfh_live_end_local'])   ? sanitize_text_field($_POST['kcfh_live_end_local'])   : '';

    $startUtc = self::local_input_to_utc_ts($startLocal);
    $endUtc   = self::local_input_to_utc_ts($endLocal);

    // Basic sanity: clear if invalid or end <= start
    if ($startUtc && $endUtc && $endUtc <= $startUtc) {
      $startUtc = $endUtc = 0;
    }

    update_post_meta($post_id, self::META_START_AT, $startUtc);
    update_post_meta($post_id, self::META_END_AT,   $endUtc);

    // Schedule/unschedule the live→VOD flip based on end time
    if (class_exists('\KCFH\Streaming\Live_Flip_Service')) {
        if ($endUtc) {
            \KCFH\Streaming\Live_Flip_Service::schedule_flip((int)$post_id, (int)$endUtc);
        } else {
            \KCFH\Streaming\Live_Flip_Service::unschedule_flip((int)$post_id);
        }
    }

    // (Re)Schedule start/end one-offs
    \KCFH\Streaming\Live_Scheduler::reschedule_for_client($post_id, $startUtc, $endUtc);

    // Immediate enforcement if we’re already at/after start
    $now = time();
    $in_window =
        ($startUtc && $now >= $startUtc) &&
        (!$endUtc || $now < $endUtc); // end optional

    if ($in_window) {
        \KCFH\Streaming\Live_Scheduler::set_live_client($post_id);
    } else {
        $liveId  = (int) get_option(\KCFH\Streaming\Admin_UI::OPT_LIVE_CLIENT, 0);
        $outside =
            ($startUtc && $endUtc && $now >= $endUtc) || // past end
            ($startUtc && !$endUtc && $now < $startUtc) || // start-only but not started yet
            (!$startUtc); // no schedule

        if ($liveId === (int) $post_id && $outside) {
            \KCFH\Streaming\Live_Scheduler::unset_live_if_matches($post_id);
        }
    }


  }
  #endregion


  private static function utc_to_local_input_value($utcTs) {
    if (!$utcTs) return '';
    $tz = wp_timezone();
    $dt = new \DateTime('@' . $utcTs);  // <-- fully qualified
    $dt->setTimezone($tz);
    return $dt->format('Y-m-d\TH:i');
  }


  private static function local_input_to_utc_ts($input) {
    if (!$input) return 0;
    try {
        $tz    = wp_timezone(); // uses Settings → General timezone (e.g., Pacific/Auckland)
        $local = \DateTime::createFromFormat('Y-m-d\TH:i', $input, $tz);
        if (!$local) return 0;

        // Move to UTC and return Unix timestamp
        $local->setTimezone(new \DateTimeZone('UTC'));
        return (int) $local->format('U');
    } catch (\Throwable $e) {
        error_log('[KCFH] local_input_to_utc_ts error: ' . $e->getMessage());
        return 0;
    }
}

        private static function GetPostsWithAssetIDS(): array{
          $assigned_ids = [];
          $assigned_posts = get_posts([
            'post_type'      => self::POST_TYPE, // post tyle slug
            'posts_per_page' => -1, // number of posts to query -1 for all
            'fields'         => 'ids', // post fields to query - Id returns an array of post ID's int[]
            'no_found_rows'  => true, // whether to skip counting the total rows found, enabling can improve performance
            'meta_query'     => [ 
              // an associative array of meta query arguments
              //only return posts that have the _kcfh_asset_id meta key
              [ 'key' => self::KCFH_ASSET_ID, //key - custom field key //------------------ !!!!!!!!!!!!!!!!!! hard coded check, can't scale easily!!!!!!!!!!!!!!!!
              'compare' => 'EXISTS' ]],  // compare - Operattor to test
          ]);


          foreach ($assigned_posts as $cid) { // loops through each client
            $aid = get_post_meta($cid, self::KCFH_ASSET_ID, true);
            //if there is an asset id add it to the array
            if ($aid) $assigned_ids[$aid] = (int)$cid;
          }
          return $assigned_posts;
        }

}


 /* new assingment
  if ($new_asset_id !== $old_asset_id) {
    $res = \KCFH\Streaming\Core::assign_vod_to_client($post_id, $new_asset_id);
    if (is_wp_error($res)) {
      error_log('[KCFH] VOD assign via client save failed: ' . $res->get_error_message());
    }
  }
*/

/* old assignment



*/

