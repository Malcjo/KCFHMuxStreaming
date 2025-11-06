<?php
namespace KCFH\Streaming\Admin;

use KCFH\Streaming\CPT_Client;
use KCFH\Streaming\Admin\All_Clients_Page;

if (!defined('ABSPATH')) exit;

final class AdminToolbar {
    /**
     * @param string $active One of: dashboard|vod|clients|add-client|live
     */
    public static function render(string $active = 'dashboard') {

        // Build URLs once
        $urls = [
            'dashboard'  => menu_page_url('kcfh_streaming', false),
            'vod'        => menu_page_url('kcfh_vod_manager', false),
            'clients'        => menu_page_url(All_Clients_Page::SLUG, false),
            //'clients'    => admin_url('edit.php?post_type=' . CPT_Client::POST_TYPE),
        ];

        // Label order (controls display order)
        $items = [
            'dashboard'  => 'Dashboard',
            'vod'        => 'VOD Manager',
            'clients'    => 'Clients',
        ];
        ?>
        <style>
          .kcfh-admin-toolbar { margin: 12px 0 18px; }
          .kcfh-admin-toolbar .kcfh-toolbar-inner{
            display:flex; flex-wrap:wrap; gap:.6rem; align-items:center;
            padding:.75rem; background:#fff; border:1px solid #e6e6e6;
            border-radius:14px; box-shadow:0 2px 8px rgba(0,0,0,.05);
          }
          .kcfh-btn{
            display:inline-flex; align-items:center; justify-content:center;
            height:38px; padding:0 .9rem; border-radius:10px;
            border:1px solid #d0d7de; background:linear-gradient(#fff,#f7f7f7);
            font-size:14px; font-weight:600; color:#1f2328; text-decoration:none;
            cursor:pointer; transition:transform .02s, box-shadow .15s, background .15s, border-color .15s;
          }
          .kcfh-btn:hover{ background:#fff; border-color:#c0c7cf; box-shadow:0 1px 6px rgba(0,0,0,.08); }
          .kcfh-btn:active{ transform:translateY(1px); }
          .kcfh-btn:focus{ outline:none; box-shadow:0 0 0 3px rgba(0,120,212,.25); border-color:#0078d4; }
          .kcfh-btn.primary{ background:#0a66ff; border-color:#0a66ff; color:#fff; }
          .kcfh-btn.primary:hover{ background:#095be6; border-color:#095be6; }
          .kcfh-spacer{ flex:1 1 auto; }
          @media (max-width:782px){
            .kcfh-admin-toolbar .kcfh-toolbar-inner{ padding:.6rem; gap:.5rem; }
            .kcfh-btn{ height:36px; font-size:13px; }
          }
        </style>

        <div class="kcfh-admin-toolbar">
          <div class="kcfh-toolbar-inner">
            <?php foreach ($items as $key => $label): 
              $is_active = ($key === $active);
              $class     = 'kcfh-btn' . ($is_active ? ' primary' : '');
              $url       = $urls[$key] ?? '#';
              ?>
              <a class="<?php echo esc_attr($class); ?>"
                 href="<?php echo esc_url($url); ?>"
                 <?php echo $is_active ? 'aria-current="page"' : ''; ?>>
                 <?php echo esc_html($label); ?>
              </a>
            <?php endforeach; ?>
          </div>
        </div>
        <?php
    }
}
