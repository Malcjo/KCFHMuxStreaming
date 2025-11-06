<?php
namespace KCFH\Streaming\Admin;

use KCFH\Streaming\CPT_Client;

if (!defined('ABSPATH')) exit;

final class All_Clients_Page {
  // Page slug
  public const SLUG = 'kcfh_clients';

  public static function render(): void {
    if (!current_user_can('manage_options')) wp_die('Nope');

    // Active toolbar tab
    AdminToolbar::render('clients');

    // Params
    $paged     = max(1, (int) ($_GET['paged'] ?? 1));
    $per_page  = max(1, min(100, (int) ($_GET['per_page'] ?? 20)));
    $search    = isset($_GET['s']) ? sanitize_text_field(wp_unslash($_GET['s'])) : '';
    $orderby   = in_array(($_GET['orderby'] ?? 'date'), ['date','title','dob','dod','vod','live'], true) ? $_GET['orderby'] : 'date';
    $order     = strtoupper($_GET['order'] ?? 'DESC'); $order = in_array($order, ['ASC','DESC'], true) ? $order : 'DESC';
    $filter    = isset($_GET['filter']) ? sanitize_text_field($_GET['filter']) : ''; // live|vod|empty|all

    // Build query args
    $args = [
      'post_type'      => CPT_Client::POST_TYPE,
      'post_status'    => 'publish',
      'posts_per_page' => $per_page,
      'paged'          => $paged,
      'no_found_rows'  => false,
      'orderby'        => $orderby === 'title' ? 'title' : 'date',
      'order'          => $order,
      's'              => $search,
    ];

    // Column-specific sorting using meta
    if ($orderby === 'dob') {
      $args['orderby']  = 'meta_value';
      $args['meta_key'] = '_kcfh_dob';
    } elseif ($orderby === 'dod') {
      $args['orderby']  = 'meta_value';
      $args['meta_key'] = '_kcfh_dod';
    } elseif ($orderby === 'vod') {
      $args['orderby'] = 'meta_value'; $args['meta_key'] = '_kcfh_playback_id';
    } elseif ($orderby === 'live') {
      // We'll sort live to top client-side by adding a small sort key in PHP below.
      $args['orderby'] = 'date';
    }

    // Filters
    $meta_query = [];
    if ($filter === 'vod') {
      $meta_query[] = ['key' => '_kcfh_playback_id', 'compare' => 'EXISTS'];
    } elseif ($filter === 'empty') {
      $meta_query[] = ['key' => '_kcfh_playback_id', 'compare' => 'NOT EXISTS'];
    }
    if ($meta_query) $args['meta_query'] = $meta_query;

    $q = new \WP_Query($args);
    $posts = $q->posts;

    // If sorting by 'live', stabilise order: live first
    $live_id = (int) get_option(\KCFH\Streaming\Admin_UI::OPT_LIVE_CLIENT, 0);
    if ($orderby === 'live') {
      usort($posts, function($a, $b) use ($live_id, $order) {
        $ai = ((int)$a->ID === $live_id) ? 0 : 1;
        $bi = ((int)$b->ID === $live_id) ? 0 : 1;
        if ($ai === $bi) return 0;
        return ($order === 'ASC') ? $ai <=> $bi : $bi <=> $ai;
      });
    }

    // Build base URL for controls
    $base_url = menu_page_url(self::SLUG, false);

    ?>
    <div class="wrap">
      <h1 class="wp-heading-inline">Clients</h1>
      <hr class="wp-header-end">

      <!-- Controls -->
      <form method="get" style="margin:12px 0 16px;">
        <input type="hidden" name="page" value="<?php echo esc_attr(self::SLUG); ?>">
        <div style="display:flex; gap:.5rem; align-items:center; flex-wrap:wrap;">
          <input type="search" name="s" value="<?php echo esc_attr($search); ?>" placeholder="Search by name…" class="regular-text">
          <select name="filter">
            <option value="" <?php selected($filter, ''); ?>>All</option>
            <option value="live"  <?php selected($filter, 'live');  ?>>Live only</option>
            <option value="vod"   <?php selected($filter, 'vod');   ?>>With VOD</option>
            <option value="empty" <?php selected($filter, 'empty'); ?>>No VOD</option>
          </select>
          <select name="orderby">
            <option value="date"  <?php selected($orderby, 'date');  ?>>Created</option>
            <option value="title" <?php selected($orderby, 'title'); ?>>Name</option>
            <option value="dob"   <?php selected($orderby, 'dob');   ?>>DOB</option>
            <option value="dod"   <?php selected($orderby, 'dod');   ?>>DOD</option>
            <option value="vod"   <?php selected($orderby, 'vod');   ?>>VOD Assigned</option>
            <option value="live"  <?php selected($orderby, 'live');  ?>>Live</option>
          </select>
          <select name="order">
            <option value="ASC"  <?php selected($order, 'ASC');  ?>>ASC</option>
            <option value="DESC" <?php selected($order, 'DESC'); ?>>DESC</option>
          </select>
          <select name="per_page">
            <option <?php selected($per_page, 10);  ?>>10</option>
            <option <?php selected($per_page, 20);  ?>>20</option>
            <option <?php selected($per_page, 50);  ?>>50</option>
            <option <?php selected($per_page, 100); ?>>100</option>
          </select>
          <button class="button button-primary">Apply</button>

          <a class="button" href="<?php echo esc_url( admin_url('post-new.php?post_type=' . CPT_Client::POST_TYPE) ); ?>">+ Add Client</a>
        </div>
      </form>

      <style>
        .kcfh-table { width:100%; border-collapse:collapse; background:#fff; border:1px solid #e6e6e6; }
        .kcfh-table th, .kcfh-table td { padding:8px 10px; border-bottom:1px solid #eee; vertical-align:top; }
        .kcfh-table th { background:#f7f7f7; text-align:left; white-space:nowrap; }
        .kcfh-badge { display:inline-block; padding:.1rem .4rem; border-radius:8px; font-size:12px; font-weight:600; }
        .kcfh-badge--live { background:#e6ffed; color:#036b10; border:1px solid #b3f0c0; }
        .kcfh-actions a { margin-right:.5rem; }
      </style>

      <table class="kcfh-table">
        <thead>
          <tr>
            <th style="width:70px;">ID</th>
            <th>Name</th>
            <th style="width:150px;">DOB</th>
            <th style="width:150px;">DOD</th>
            <th style="width:220px;">VOD (Playback)</th>
            <th style="width:110px;">Live</th>
            <th style="width:240px;">Actions</th>
          </tr>
        </thead>
        <tbody>
        <?php
        if ($posts) {
          $live_label_id = (int) get_option(\KCFH\Streaming\Admin_UI::OPT_LIVE_CLIENT, 0);
          foreach ($posts as $p) {
            $dob  = get_post_meta($p->ID, '_kcfh_dob', true);
            $dod  = get_post_meta($p->ID, '_kcfh_dod', true);
            $play = get_post_meta($p->ID, '_kcfh_playback_id', true);
            $is_live = ((int)$p->ID === $live_label_id);

            $edit_link  = get_edit_post_link($p->ID, '');
            $view_link  = admin_url('admin.php?page='.esc_attr(self::SLUG).'&view='.$p->ID); // or front-end link if desired
            $nonce_live = wp_create_nonce('kcfh_set_live_' . $p->ID);
            $set_url    = admin_url('admin-post.php?action=kcfh_set_live&client_id=' . (int)$p->ID . '&_wpnonce=' . $nonce_live);
            $unset_url  = admin_url('admin-post.php?action=kcfh_set_live&client_id=0&_wpnonce=' . wp_create_nonce('kcfh_set_live_0'));
            ?>
            <tr>
              <td><?php echo (int)$p->ID; ?></td>
              <td><?php echo esc_html(get_the_title($p)); ?></td>
              <td><?php echo esc_html($dob ?: '—'); ?></td>
              <td><?php echo esc_html($dod ?: '—'); ?></td>
              <td><?php echo $play ? '<code style="font-size:12px">'.esc_html($play).'</code>' : '—'; ?></td>
              <td><?php echo $is_live ? '<span class="kcfh-badge kcfh-badge--live">Live Now</span>' : '—'; ?></td>
              <td class="kcfh-actions">
                <a href="<?php echo esc_url($edit_link); ?>">Edit</a>
                <?php if ($is_live): ?>
                  • <a href="<?php echo esc_url($unset_url); ?>">Unset Live</a>
                <?php else: ?>
                  • <a href="<?php echo esc_url($set_url); ?>">Set Live</a>
                <?php endif; ?>
              </td>
            </tr>
            <?php
          }
        } else {
          echo '<tr><td colspan="7">No clients found.</td></tr>';
        }
        ?>
        </tbody>
      </table>

      <?php
      // Pagination
      if ($q->max_num_pages > 1) {
        $big = 999999999;
        $pagination = paginate_links([
          'base'      => add_query_arg('paged','%#%'),
          'format'    => '',
          'current'   => $paged,
          'total'     => $q->max_num_pages,
          'type'      => 'array',
          'prev_text' => '«',
          'next_text' => '»',
        ]);
        if ($pagination) {
          echo '<div class="tablenav"><div class="tablenav-pages"><span class="pagination-links">';
          echo implode(' ', array_map('wp_kses_post', $pagination));
          echo '</span></div></div>';
        }
      } ?>
    </div>
    <?php
  }
}
