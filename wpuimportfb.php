<?php

/*
Plugin Name: WPU Import FB
Plugin URI: https://github.com/WordPressUtilities/wpuimportfb
Version: 0.1
Description: Import the latest messages from a Facebook page
Author: Darklg
Author URI: http://darklg.me/
License: MIT License
License URI: http://opensource.org/licenses/MIT
*/

class WPUImportFb {
    private $cronhook = 'wpuimportfb__cron_hook';
    private $option_id = 'wpuimportfb_options';
    private $token = '';
    private $app_id = '';
    private $app_secret = '';
    private $post_type = '';

    public function __construct() {
        $this->set_options();
        add_action('plugins_loaded', array(&$this,
            'plugins_loaded'
        ));
        add_action('init', array(&$this,
            'init'
        ));
        add_action($this->cronhook, array(&$this,
            'import'
        ));
        // Cron
        include 'inc/WPUBaseCron/WPUBaseCron.php';
        $this->cron = new \wpuimportfb\WPUBaseCron();
    }

    public function set_options() {
        $this->post_type = apply_filters('wpuimportfb_posttypehook', 'fb_posts');
        $this->cron_interval = apply_filters('wpuimportfb_croninterval', 1800);
        $this->options = array(
            'plugin_publicname' => 'Facebook Import',
            'plugin_name' => 'Facebook Import',
            'plugin_userlevel' => 'manage_options',
            'plugin_id' => 'wpuimportfb',
            'plugin_pageslug' => 'wpuimportfb'
        );
        $this->options['admin_url'] = admin_url('edit.php?post_type=' . $this->post_type . '&page=' . $this->options['plugin_id']);
        $this->settings_values = get_option($this->option_id);
        if (isset($this->settings_values['token'])) {
            $this->token = $this->settings_values['token'];
        }
        if (isset($this->settings_values['app_id'])) {
            $this->app_id = $this->settings_values['app_id'];
        }
        if (isset($this->settings_values['app_secret'])) {
            $this->app_secret = $this->settings_values['app_secret'];
        }
        if (isset($this->settings_values['profile_id'])) {
            $this->profile_id = $this->settings_values['profile_id'];
        }
        $this->post_type_info = apply_filters('wpuimportfb_posttypeinfo', array(
            'public' => true,
            'name' => 'Facebook Post',
            'label' => 'Facebook Post',
            'plural' => 'Facebook Posts',
            'female' => false,
            'menu_icon' => 'dashicons-facebook'
        ));
    }

    public function plugins_loaded() {
        if (!is_admin()) {
            return;
        }
        // Admin page
        add_action('admin_menu', array(&$this,
            'admin_menu'
        ));
        add_action('admin_post_wpuimportfb_postaction', array(&$this,
            'postAction'
        ));

    }

    public function init() {
        $this->cron->init(array(
            'pluginname' => $this->options['plugin_name'],
            'cronhook' => $this->cronhook,
            'croninterval' => $this->cron_interval
        ));
        $this->cron->check_cron();

        /* Post types */
        if (class_exists('wputh_add_post_types_taxonomies')) {
            add_filter('wputh_get_posttypes', array(&$this, 'wputh_set_theme_posttypes'));
        } else {
            register_post_type($this->post_type, $this->post_type_info);
        }

        /* Messages */
        if (is_admin()) {
            include 'inc/WPUBaseMessages/WPUBaseMessages.php';
            $this->messages = new \wpuimportfb\WPUBaseMessages($this->options['plugin_id']);
        }

        /* Settings */
        $this->settings_details = array(
            'plugin_id' => 'wpuimportfb',
            'option_id' => $this->option_id,
            'sections' => array(
                'import' => array(
                    'name' => __('Import Settings', 'wpuimportfb')
                )
            )
        );
        $this->settings = array(
            'app_id' => array(
                'section' => 'import',
                'label' => __('APP ID', 'wpuimportfb')
            ),
            'app_secret' => array(
                'section' => 'import',
                'label' => __('APP Secret', 'wpuimportfb')
            ),
            'token' => array(
                'section' => 'import',
                'label' => __('Token', 'wpuimportfb')
            ),
            'profile_id' => array(
                'section' => 'import',
                'label' => __('Profile ID', 'wpuimportfb')
            ),
            'import_draft' => array(
                'section' => 'import',
                'type' => 'checkbox',
                'label_check' => __('Posts are created with a draft status.', 'wpuimportfb'),
                'label' => __('Import as draft', 'wpuimportfb')
            )
        );
        if (is_admin()) {
            include 'inc/WPUBaseSettings/WPUBaseSettings.php';
            $this->basesettings = new \wpuimportfb\WPUBaseSettings($this->settings_details, $this->settings);
        }

    }

    public function wputh_set_theme_posttypes($post_types) {
        $post_types[$this->post_type] = $this->post_type_info;
        return $post_types;
    }

    /* Admin menu */
    public function admin_menu() {
        add_submenu_page('edit.php?post_type=' . $this->post_type, $this->options['plugin_name'] . ' - ' . __('Settings'), __('Import Settings', 'wpuimportfb'), $this->options['plugin_userlevel'], $this->options['plugin_pageslug'], array(&$this,
            'admin_settings'
        ), '', 110);
    }

    public function admin_settings() {

        echo '<div class="wrap"><h1>' . get_admin_page_title() . '</h1>';

        settings_errors($this->settings_details['option_id']);
        if (!empty($this->app_id) && !empty($this->app_secret)) {
            echo '<h2>' . __('Tools') . '</h2>';
            echo '<form action="' . admin_url('admin-post.php') . '" method="post">';
            echo '<input type="hidden" name="action" value="wpuimportfb_postaction">';
            $schedule = wp_next_scheduled($this->cronhook);
            $seconds = $schedule - time();
            $minutes = 0;
            if ($seconds >= 60) {
                $minutes = (int) ($seconds / 60);
                $seconds = $seconds % 60;
            }
            echo '<p>' . sprintf(__('Next automated import in %s’%s’’', 'wpuimportfb'), $minutes, $seconds) . '</p>';
            echo '<p class="submit">';
            submit_button(__('Import now', 'wpuimportfb'), 'primary', 'import_now', false);
            echo ' ';
            submit_button(__('Test API', 'wpuimportfb'), 'primary', 'test_api', false);

            echo '</p>';
            echo '</form>';
            echo '<hr />';
        } else {
            echo '<p>' . sprintf(__('You need apps infos, please <a %s>create an application here</a>, then generate the token.', 'wpuimportfb'), 'target="_blank" href="https://developers.facebook.com/apps/"') . '</p>';
        }

        echo '<form action="' . admin_url('options.php') . '" method="post">';
        settings_fields($this->settings_details['option_id']);
        do_settings_sections($this->options['plugin_id']);
        echo submit_button(__('Save Changes', 'wpuimportfb'));
        echo '</form>';

        echo '</div>';
    }

    public function postAction() {
        if (isset($_POST['import_now'])) {
            $nb_imports = $this->import();
            if ($nb_imports === false) {
                $this->messages->set_message('already_import', sprintf(__('An import is already running', 'wpuimportfb'), $nb_imports));
            } else {
                if ($nb_imports > 0) {
                    $this->messages->set_message('imported_nb', sprintf(__('Imported posts : %s', 'wpuimportfb'), $nb_imports));
                } else {
                    $this->messages->set_message('imported_0', __('No new posts', 'wpuimportfb'), 'created');
                }
            }

        }
        if (isset($_POST['test_api'])) {
            $items = $this->get_latest_items_for_page();
            if (is_array($items) && !empty($items)) {
                $this->messages->set_message('api_works', __('The API works great !', 'wpuimportfb'), 'created');
            } else {
                $this->messages->set_message('api_invalid', __('The credentials seems invalid or the page do not have posts.', 'wpuimportfb'), 'error');
            }
        }
        wp_safe_redirect(wp_get_referer());
        die();
    }

    /* ----------------------------------------------------------
      Import
    ---------------------------------------------------------- */

    public function import() {
        $nb_imports = 0;

        // Get last imported ids and import new
        $imported_items = $this->get_last_imported_items_ids();
        $items = $this->get_latest_items_for_page();

        foreach ($items as $item) {
            /* Exclude posts already imported */
            if (in_array($item['id'], $imported_items)) {
                continue;
            }
            /* Exclude posts by others */
            if ($item['from']->id != $this->profile_id) {
                continue;
            }
            /* Create post */
            $post_id = $this->create_post_from_item($item);
            if (is_numeric($post_id) && $post_id > 0) {
                $nb_imports++;
            }
        }

        return $nb_imports;
    }

    public function get_last_imported_items_ids() {
        global $wpdb;
        return $wpdb->get_col("SELECT meta_value FROM $wpdb->postmeta WHERE meta_key = 'wpuimportfb_id' ORDER BY meta_id DESC LIMIT 0,200");
    }

    public function create_post_from_item($item) {

        $item_title = $item['message'];
        $item_text = $item['message'];
        if (!empty($item['story'])) {
            $item_text .= "\n\n" . $item['story'];
        }
        if (!empty($item['link'])) {
            $item_text .= "\n\n" . $item['link'];
        }

        $post_status = 'publish';
        if (is_array($this->settings_values) && isset($this->settings_values['import_draft']) && $this->settings_values['import_draft'] == '1') {
            $post_status = 'draft';
        }

        $item_post = array(
            'post_title' => $item_title,
            'post_content' => $item_text,
            'post_date_gmt' => date('Y-m-d H:i:s', $item['created_time']),
            'post_status' => 'publish',
            'post_author' => 1,
            'post_type' => $this->post_type
        );

        // Insert the post into the database
        $post_id = wp_insert_post($item_post);

        // Store metas
        add_post_meta($post_id, 'wpuimportfb_id', $item['id']);
        add_post_meta($post_id, 'wpuimportfb_from', $item['from']);
        add_post_meta($post_id, 'wpuimportfb_created_time', $item['created_time']);
        add_post_meta($post_id, 'wpuimportfb_picture', $item['picture']);
        add_post_meta($post_id, 'wpuimportfb_link', $item['link']);
        add_post_meta($post_id, 'wpuimportfb_message', $item['message']);
        add_post_meta($post_id, 'wpuimportfb_story', $item['story']);

        return $post_id;
    }

    /* Items */

    public function get_latest_items_for_page() {
        $_items = array();
        $json_object = $this->get_url_content("https://graph.facebook.com/{$this->profile_id}/feed?{$this->token}");
        if (!is_object($json_object) || !isset($json_object->data)) {
            return $_items;
        }
        foreach ($json_object->data as $item) {
            $itemtmp = $this->extract_item_infos($item);
            if ($itemtmp) {
                $_items[] = $itemtmp;
            }
        }

        return $_items;
    }

    public function extract_item_infos($item) {
        $_item_parsed = array(
            'id' => $item->id,
            'from' => $item->from,
            'created_time' => strtotime($item->created_time),
            'picture' => '',
            'link' => '',
            'message' => '',
            'story' => ''
        );

        if (isset($item->story)) {
            $_item_parsed['story'] = $item->story;
        }

        if (isset($item->picture)) {
            $_item_parsed['picture'] = $item->picture;
        }

        if (isset($item->link)) {
            $_item_parsed['link'] = $item->link;
        }

        if (isset($item->message)) {
            $_item_parsed['message'] = $item->message;
        }
        return $_item_parsed;
    }

    /* Token */

    public function get_token() {
        if (empty($this->app_id) || empty($this->app_secret)) {
            return false;
        }

        // No token, get token and save it
        if (empty($this->token)) {
            return $this->get_new_token();
        }

        return $this->token;
    }

    public function get_new_token() {
        // Call it
        $this->token = $this->get_url_content("https://graph.facebook.com/oauth/access_token?grant_type=client_credentials&client_id={$this->app_id}&client_secret={$this->app_secret}", 1);
        // Save it
        if (isset($this->basesettings)) {
            $this->basesettings->update_setting('token', $this->token);
        }

        return $this->token;
    }

    /* ----------------------------------------------------------
      Utilities
    ---------------------------------------------------------- */

    public function get_url_content($url, $str = false) {
        $_request = wp_remote_get($url);
        if (is_wp_error($_request)) {
            return false;
        }
        $_raw_body = wp_remote_retrieve_body($_request);
        if ($str) {
            return $_raw_body;
        }
        $_body = json_decode($_raw_body);
        if (!is_object($_body)) {
            return false;
        }
        return $_body;
    }

    /* ----------------------------------------------------------
      Install
    ---------------------------------------------------------- */

    public function install() {
        flush_rewrite_rules();
        $this->cron->install();
    }

    public function deactivation() {
        flush_rewrite_rules();
        $this->cron->uninstall();
    }
}

$WPUImportFb = new WPUImportFb();

register_activation_hook(__FILE__, array(&$WPUImportFb,
    'install'
));
register_deactivation_hook(__FILE__, array(&$WPUImportFb,
    'deactivation'
));
