<?php
namespace KCFH\Streaming\Admin;

use KCFH\Streaming\CPT_Client;

if (!defined('ABSPATH')) exit;

final class Dashboard {
    public static function render(): void {
        if (!current_user_can('manage_options')) wp_die('Nope');

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
          <a href="<?php echo esc_url(admin_url('post-new.php?post_type=' . CPT_Client::POST_TYPE)); ?>" class="page-title-action">Add New Client</a>
          <p>Manage clients and set which one is currently <strong>Live Now</strong>.</p>

          <table class="widefat striped">
            <thead>
            <tr>
              <th>ID</th><th>Name</th><th>Created</th><th>DOB</th><th>DOD</th><th>Live</th><th>Actions</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($clients as $p): 
                $dob = get_post_meta($p->ID, '_kcfh_dob', true);
                $dod = get_post_meta($p->ID, '_kcfh_dod', true);
                $is_live = ($p->ID === $live_id);
                $live_badge = $is_live ? '<span style="color:#0a0;font-weight:600;">Live Now</span>' : '—';
                $edit_link = get_edit_post_link($p->ID, '');
                $nonce = wp_create_nonce('kcfh_set_live_' . $p->ID);
                $toggle_url = admin_url('admin-post.php?action=kcfh_set_live&client_id=' . $p->ID . '&_wpnonce=' . $nonce);
                $unset_url  = admin_url('admin-post.php?action=kcfh_set_live&client_id=0&_wpnonce=' . wp_create_nonce('kcfh_set_live_0'));
            ?>
              <tr>
                <td><?php echo esc_html($p->ID); ?></td>
                <td><?php echo esc_html(get_the_title($p)); ?></td>
                <td><?php echo esc_html(get_the_date('', $p)); ?></td>
                <td><?php echo esc_html($dob); ?></td>
                <td><?php echo esc_html($dod); ?></td>
                <td><?php echo $live_badge; ?></td>
                <td>
                  <a href="<?php echo esc_url($edit_link); ?>">Edit</a> •
                  <?php if ($is_live): ?>
                    <a href="<?php echo esc_url($unset_url); ?>">Unset Live</a>
                  <?php else: ?>
                    <a href="<?php echo esc_url($toggle_url); ?>">Set Live</a>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php
    }
}
