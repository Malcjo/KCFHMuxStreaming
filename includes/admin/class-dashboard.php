<?php
namespace KCFH\Streaming\Admin;

use KCFH\STREAMING\Admin_Util;
use KCFH\Streaming\CPT_Client;


if (!defined('ABSPATH')) exit;

final class Dashboard {
    public static function render(): void {
        if (!current_user_can('manage_options')) wp_die('Nope');
        
        AdminToolbar::render('dashboard');
        $live_id = (int) get_option(Constants::OPT_LIVE_CLIENT, 0);
        $clients = get_posts([
            'post_type'      => CPT_Client::POST_TYPE,
            'posts_per_page' => 50,
            'orderby'        => 'date',
            'order'          => 'DESC',
        ]);
        ?>
        <div class="wrap">
          <h1>KCFH Streaming — Dashboard</h1>
          
          <p>Manage clients and set which one is currently <strong>Live Now</strong>.</p>

          <table class="widefat striped">
            <thead>
            <tr>
              <th>ID</th>
              <th>Name</th>
              <th>Created</th>
              <th>DOB</th>
              <th>DOD</th>
              <th>Live</th>
              <th style="width:110px;">Start time</th>
              <th style="width:110px;">End time</th>
              <th>Actions</th>
              <th>Set/Unset Live</th>
              <th>Download Video</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($clients as $p): 
                $id = $p->ID;
                $title = get_the_title($p);
                $dateCreatedOn =get_the_date('', $p);
                $dob = get_post_meta($p->ID, '_kcfh_dob', true);
                $dod = get_post_meta($p->ID, '_kcfh_dod', true);
                $is_live = ($p->ID === $live_id);
                $live_badge = $is_live ? '<span style="color:#0a0;font-weight:600;">Live Now</span>' : '—';

                $startTimeStream= (int) get_post_meta($p->ID, \KCFH\Streaming\CPT_Client::META_START_AT, true);
                $endTimeStream = (int) get_post_meta($p->ID, \KCFH\Streaming\CPT_Client::META_END_AT, true);
                $edit_link = get_edit_post_link($p->ID, '');
                $nonce = wp_create_nonce('kcfh_set_live_' . $p->ID);
                $set_url = admin_url('admin-post.php?action=kcfh_set_live&client_id=' . $p->ID . '&_wpnonce=' . $nonce);
                $unset_url  = admin_url('admin-post.php?action=kcfh_set_live&client_id=0&_wpnonce=' . wp_create_nonce('kcfh_set_live_0'));
            ?>
              <tr>
                <td><?php echo esc_html($id); ?></td>
                <td><b><a href="<?php echo esc_url($edit_link); ?>"><?php echo esc_html($title) ?></a></b></td>
                <td><?php echo esc_html($dateCreatedOn); ?></td>
                <td><?php echo esc_html($dob); ?></td>
                <td><?php echo esc_html($dod); ?></td>
                <td><?php echo $live_badge; ?></td>
                <td><?php echo esc_html($startTimeStream ?date_i18n('Y-m-d H:i:s', $startTimeStream): '—'); ?></td>
                <td><?php echo esc_html($endTimeStream ?date_i18n('Y-m-d H:i:s', $endTimeStream): '—'); ?></td>
                <td><b><a href="<?php echo esc_url($edit_link); ?>">Edit</a></b></td>
                <td><?php Admin_Util::DisplayIsLive($is_live, $set_url,$unset_url);?></td>
                <td><?php Admin_Util::DownloadVODForClient($p); ?></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php
    }
}
