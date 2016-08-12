<?php

/*
Plugin Name: WPU Import FB
Plugin URI: https://github.com/WordPressUtilities/wpuimportfb
Version: 0.5.1
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
        add_action('init', array(&$this,
            'add_oembed'
        ));
        add_action($this->cronhook, array(&$this,
            'import'
        ));
        // Cron
        include 'inc/WPUBaseCron/WPUBaseCron.php';
        $this->cron = new \wpuimportfb\WPUBaseCron();
    }

    public function set_options() {
        load_plugin_textdomain('wpuimportfb', false, dirname(plugin_basename(__FILE__)) . '/lang/');

        /* Post type */
        $this->post_type = apply_filters('wpuimportfb_posttypehook', 'fb_posts');
        $this->post_type_info = apply_filters('wpuimportfb_posttypeinfo', array(
            'public' => true,
            'name' => __('Facebook Post', 'wpuimportfb'),
            'label' => __('Facebook Post', 'wpuimportfb'),
            'plural' => __('Facebook Posts', 'wpuimportfb'),
            'female' => false,
            'menu_icon' => 'dashicons-facebook'
        ));

        /* Taxo author */
        $this->taxonomy_author = apply_filters('wpuimportfb_taxonomy_author', 'fb_authors');
        $this->taxonomy_author_info = apply_filters('wpuimportfb_taxonomy_author_info', array(
            'label' => __('Authors', 'wpuimportfb'),
            'plural' => __('Authors', 'wpuimportfb'),
            'name' => __('Author', 'wpuimportfb'),
            'hierarchical' => true,
            'post_type' => $this->post_type
        ));

        /* Taxo type */
        $this->taxonomy_type = apply_filters('wpuimportfb_taxonomy_type', 'fb_types');
        $this->taxonomy_type_info = apply_filters('wpuimportfb_taxonomy_type_info', array(
            'label' => __('Types', 'wpuimportfb'),
            'plural' => __('Types', 'wpuimportfb'),
            'name' => __('Type', 'wpuimportfb'),
            'hierarchical' => true,
            'post_type' => $this->post_type
        ));

        /* Taxo profile */
        $this->taxonomy_profile = apply_filters('wpuimportfb_taxonomy_profile', 'fb_profiles');
        $this->taxonomy_profile_info = apply_filters('wpuimportfb_taxonomy_profile_info', array(
            'label' => __('Profiles', 'wpuimportfb'),
            'plural' => __('Profiles', 'wpuimportfb'),
            'name' => __('Profile', 'wpuimportfb'),
            'hierarchical' => true,
            'post_type' => $this->post_type
        ));

        $this->cron_interval = apply_filters('wpuimportfb_croninterval', 1800);
        $this->options = array(
            'plugin_publicname' => __('Facebook Import', 'wpuimportfb'),
            'plugin_name' => __('Facebook Import', 'wpuimportfb'),
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
        $this->import_draft = (is_array($this->settings_values) && isset($this->settings_values['import_draft']) && $this->settings_values['import_draft'] == '1');
        $this->import_external = (is_array($this->settings_values) && isset($this->settings_values['import_external']) && $this->settings_values['import_external'] == '1');

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
            add_filter('wputh_get_taxonomies', array(&$this, 'wputh_set_theme_taxonomies'));
        } else {
            /* Post type for posts */
            register_post_type(
                $this->post_type,
                $this->post_type_info
            );
            /* Taxonomy for authors */
            register_taxonomy(
                $this->taxonomy_author,
                $this->post_type,
                $this->taxonomy_author_info
            );
            /* Taxonomy for types */
            register_taxonomy(
                $this->taxonomy_type,
                $this->post_type,
                $this->taxonomy_type_info
            );
            /* Taxonomy for profiles */
            register_taxonomy(
                $this->taxonomy_profile,
                $this->post_type,
                $this->taxonomy_profile_info
            );
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
            ),
            'import_external' => array(
                'section' => 'import',
                'type' => 'checkbox',
                'label_check' => __('Posts from page visitors are imported.', 'wpuimportfb'),
                'label' => __('Import external posts', 'wpuimportfb')
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

    public function wputh_set_theme_taxonomies($taxonomies) {
        $taxonomies[$this->taxonomy_profile] = $this->taxonomy_profile_info;
        $taxonomies[$this->taxonomy_author] = $this->taxonomy_author_info;
        $taxonomies[$this->taxonomy_type] = $this->taxonomy_type_info;
        return $taxonomies;
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
            $next = $this->cron->get_next_scheduled();
            echo '<p>' . sprintf(__('Next automated import in %s’%s’’', 'wpuimportfb'), $next['min'], $next['sec']) . '</p>';
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
        /* Load all accounts and increment nb import */
        $nb_imports += $this->import_account($this->profile_id);
        /* Return number */
        return $nb_imports;
    }

    public function import_account($profile_id = false) {
        if (!$profile_id) {
            $profile_id = $this->profile_id;
        }

        $nb_imports = 0;
        // Get last imported ids and import new
        $imported_items = $this->get_last_imported_items_ids($profile_id);
        $items = $this->get_latest_items_for_page($profile_id);

        foreach ($items as $item) {
            /* Exclude posts already imported */
            if (in_array($item['id'], $imported_items)) {
                continue;
            }
            /* Exclude posts by others */
            if (!$this->import_external && $item['from']->id != $profile_id) {
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

    public function get_last_imported_items_ids($profile_id = false) {
        if (!$profile_id) {
            $profile_id = $this->profile_id;
        }
        global $wpdb;
        return $wpdb->get_col(
            $wpdb->prepare("
                SELECT meta_value
                FROM $wpdb->postmeta
                WHERE meta_key = 'wpuimportfb_id'
                AND post_id IN(
                    SELECT object_id FROM $wpdb->term_relationships
                    LEFT JOIN $wpdb->terms
                    ON $wpdb->terms.term_id = $wpdb->term_relationships.term_taxonomy_id
                    WHERE $wpdb->terms.slug = 'profile-%d'
                )
                ORDER BY meta_id DESC
                LIMIT 0,200", $profile_id)
        );

    }

    public function get_or_create_post_taxonomy($item, $taxonomy, $name, $term_slug) {
        /* Create it if null */
        $tmp_taxo = get_term_by('slug', $term_slug, $taxonomy);
        if (!$tmp_taxo) {
            $tmp_term = wp_insert_term($name, $taxonomy, array(
                'slug' => $term_slug
            ));
            $tmp_taxo = get_term_by('id', $tmp_term['term_id'], $taxonomy);
        }
        return $tmp_taxo;
    }

    public function create_post_from_item($item) {

        /* Set taxonomy author */
        $taxo_author = false;
        if (is_object($item['from'])) {
            $item_cat = isset($item['from']->category) ? $item['from']->category : 'user';
            $term_slug = strtolower($item_cat . '-' . $item['from']->id);
            $taxo_author = $this->get_or_create_post_taxonomy($item, $this->taxonomy_author, $item['from']->name, $term_slug);
        }

        /* Set taxonomy type */
        $taxo_type = false;
        if (!empty($item['type'])) {
            $taxo_type = $this->get_or_create_post_taxonomy($item, $this->taxonomy_type, ucwords($item['type']), $item['type']);
        }

        /* Set taxonomy profile */
        $taxo_profile = false;
        if (!empty($item['profile_id'])) {
            $profile_details = $this->get_profile_details($item['profile_id']);
            $taxo_profile = $this->get_or_create_post_taxonomy($item, $this->taxonomy_profile, ucwords($profile_details->name), 'profile-' . $item['profile_id']);
        }

        /* Item details */
        $item_title = trim($item['message']);
        if (empty($item_title)) {
            $item_title = $item['from']->name;
        }
        $item_text = $item['message'];
        if (!empty($item['story']) && $item['type'] != 'photo') {
            $item_text .= "\n\n" . $item['story'];
        }
        /* Add link if valid, not photo, not already contained into text */
        if (!empty($item['link']) && $item['type'] != 'photo' && strpos($item_text, $item['link']) === false) {
            $item_text .= "\n\n" . $item['link'];
        }

        $post_status = 'publish';
        if ($this->import_draft) {
            $post_status = 'draft';
        }

        $item_post = array(
            'post_title' => wp_trim_words($item_title, 10),
            'post_content' => $item_text,
            'post_date_gmt' => date('Y-m-d H:i:s', $item['created_time']),
            'post_status' => 'publish',
            'post_author' => 1,
            'post_type' => $this->post_type
        );

        // Insert the post into the database
        $post_id = wp_insert_post($item_post);

        /* Add taxo */
        if ($taxo_author) {
            wp_set_post_terms($post_id, array($taxo_author->term_id), $this->taxonomy_author);
        }
        if ($taxo_type) {
            wp_set_post_terms($post_id, array($taxo_type->term_id), $this->taxonomy_type);
        }
        if ($taxo_profile) {
            wp_set_post_terms($post_id, array($taxo_profile->term_id), $this->taxonomy_profile);
        }

        // Image
        if ($item['picture']) {
            $this->add_picture_to_post($item['picture'], $post_id);
        }

        // Store metas
        add_post_meta($post_id, 'wpuimportfb_id', $item['id']);
        if ($item['link']) {
            add_post_meta($post_id, 'wpuimportfb_link', $item['link']);
        }
        if ($item['message']) {
            add_post_meta($post_id, 'wpuimportfb_message', $item['message']);
        }
        if ($item['story']) {
            add_post_meta($post_id, 'wpuimportfb_story', $item['story']);
        }

        return $post_id;
    }

    public function add_picture_to_post($image_url, $post_id) {
        // Add required classes
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        // Upload medias
        $image = media_sideload_image($image_url, $post_id);

        // then find the last image added to the post attachments
        $attachments = get_posts(array(
            'numberposts' => 1,
            'post_parent' => $post_id,
            'post_type' => 'attachment',
            'post_mime_type' => 'image'
        ));

        // set image as the post thumbnail
        if (sizeof($attachments) > 0) {
            set_post_thumbnail($post_id, $attachments[0]->ID);
        }

    }

    /* Items */

    public function get_latest_items_for_page($profile_id = false) {
        if (!$profile_id) {
            $profile_id = $this->profile_id;
        }
        $_items = array();
        $_fields = array(
            'full_picture',
            'picture',
            'type',
            'link',
            'message',
            'from',
            'story'
        );
        $_req_url = "https://graph.facebook.com/{$profile_id}/feed?{$this->token}&fields=" . implode(',', $_fields);
        $json_object = $this->get_url_content($_req_url);
        if (!is_object($json_object) || !isset($json_object->data)) {
            return $_items;
        }
        foreach ($json_object->data as $item) {
            $itemtmp = $this->extract_item_infos($item, $profile_id);
            if ($itemtmp) {
                $_items[] = $itemtmp;
            }
        }

        return $_items;
    }

    public function extract_item_infos($item, $profile_id) {
        $_item_parsed = array(
            'id' => $item->id,
            'profile_id' => $profile_id,
            'from' => $item->from,
            'created_time' => strtotime($item->created_time),
            'picture' => '',
            'type' => $item->type,
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

        if (isset($item->full_picture)) {
            $_item_parsed['picture'] = $item->full_picture;
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

    /* Info */
    public function get_profile_details($profile_id = false) {
        if (!$profile_id) {
            $profile_id = $this->profile_id;
        }

        /* Cache it for some time */
        $transient_name = 'wpuimportfb_profile_details_' . $profile_id;
        if (false === ($profile_details = get_transient($transient_name))) {
            $profile_details = $this->get_url_content("https://graph.facebook.com/{$profile_id}?{$this->token}");
            set_transient($transient_name, $profile_details, 12 * HOUR_IN_SECONDS);
        }

        return $profile_details;
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
      Public
    ---------------------------------------------------------- */

    /* Thanks to https://github.com/khromov/wp-facebook-oembed */
    public function add_oembed() {
        $endpoints = array(
            //'#https?://www\.facebook\.com/photo(s/|.php).*#i' => 'https://www.facebook.com/plugins/post/oembed.json/',
            //'#https?://www\.facebook\.com/.*/photo(s/|.php).*#i' => 'https://www.facebook.com/plugins/post/oembed.json/',
            '#https?://www\.facebook\.com/video.php.*#i' => 'https://www.facebook.com/plugins/video/oembed.json/',
            '#https?://www\.facebook\.com/.*/videos/.*#i' => 'https://www.facebook.com/plugins/video/oembed.json/',
            '#https?://www\.facebook\.com/.*/posts/.*#i' => 'https://www.facebook.com/plugins/post/oembed.json/',
            '#https?://www\.facebook\.com/.*/activity/.*#i' => 'https://www.facebook.com/plugins/post/oembed.json/',
            '#https?://www\.facebook\.com/permalink.php.*#i' => 'https://www.facebook.com/plugins/post/oembed.json/',
            '#https?://www\.facebook\.com/media/.*#i' => 'https://www.facebook.com/plugins/post/oembed.json/',
            '#https?://www\.facebook\.com/questions/.*#i' => 'https://www.facebook.com/plugins/post/oembed.json/',
            '#https?://www\.facebook\.com/notes/.*#i' => 'https://www.facebook.com/plugins/post/oembed.json/'
        );
        foreach ($endpoints as $pattern => $endpoint) {
            wp_oembed_add_provider($pattern, $endpoint, true);
        }
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
