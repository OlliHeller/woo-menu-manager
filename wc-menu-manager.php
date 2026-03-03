<?php
/**
 * Plugin Name: WooCommerce Menü Manager
 * Description: Verschiebt die WooCommerce Hauptmenüs (Produkte, Zahlungen, Statistiken, Marketing) in das WooCommerce-Untermenü, basierend auf wählbaren Einstellungen. Mehrsprachig.
 * Version: 1.0.0
 * Author: Oliver Heller
 * License: GPL2
 * Text Domain: wc-menu-manager
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class WC_Menu_Manager {

    private $option_key = 'wc_menu_manager_settings';
    private $textdomain = 'wc-menu-manager';
    private $menus_to_move = array();

    public function __construct() {

        // Textdomain zuerst laden
        add_action( 'plugins_loaded', array( $this, 'load_textdomain' ) );

        // Menüs erst definieren, nachdem Textdomain geladen ist
        add_action( 'plugins_loaded', array( $this, 'define_menus' ), 20 );

        // Admin-Interface
        add_action( 'admin_menu', array( $this, 'add_plugin_page' ) );
        add_action( 'admin_init', array( $this, 'page_init' ) );

        // Menüverschiebung
        add_action( 'admin_head', array( $this, 'move_menus_based_on_settings' ) );

        // Einstellungen-Link in Plugin-Liste
        add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), array( $this, 'add_settings_link' ) );

        // Standardwerte setzen
        add_action( 'admin_init', array( $this, 'set_default_settings' ), 0 );
    }

    public function load_textdomain() {
        load_plugin_textdomain( $this->textdomain, false, dirname(plugin_basename(__FILE__)) . '/languages/' );
    }

    public function define_menus() {
        $this->menus_to_move = array(
            'products'  => array(
                'name' => __('Produkte', $this->textdomain),
                'remove' => 'edit.php?post_type=product',
                'add' => 'edit.php?post_type=product',
                'cap' => 'edit_products'
            ),
            'payments'  => array(
                'name' => __('Zahlungen', $this->textdomain),
                'remove' => 'admin.php?page=wc-settings&tab=checkout&from=PAYMENTS_MENU_ITEM',
                'add' => 'admin.php?page=wc-settings&tab=checkout&from=PAYMENTS_MENU_ITEM',
                'cap' => 'manage_woocommerce'
            ),
            'analytics' => array(
                'name' => __('Statistiken', $this->textdomain),
                'remove' => 'wc-admin&path=/analytics/overview',
                'add' => 'wc-admin&path=/analytics/overview',
                'cap' => 'view_woocommerce_reports'
            ),
            'marketing' => array(
                'name' => __('Marketing', $this->textdomain),
                'remove' => 'woocommerce-marketing',
                'add' => 'admin.php?page=wc-admin&path=/marketing',
                'cap' => 'manage_options'
            ),
        );
    }

    private function get_default_settings() {
        $defaults = array();
        foreach(array_keys($this->menus_to_move) as $key) {
            $defaults[$key] = 1;
        }
        return $defaults;
    }

    public function set_default_settings() {
        if ( false === get_option( $this->option_key ) ) {
            add_option( $this->option_key, $this->get_default_settings() );
        }
    }

    public function add_settings_link($links) {
        $settings_link = '<a href="options-general.php?page=wc-menu-manager-settings">' . esc_html__('Einstellungen', $this->textdomain) . '</a>';
        array_unshift($links, $settings_link);
        return $links;
    }

    public function add_plugin_page() {
        add_options_page(
            esc_html__('WC Menü Manager', $this->textdomain),
            esc_html__('WC Menü Manager', $this->textdomain),
            'manage_options',
            'wc-menu-manager-settings',
            array($this, 'create_admin_page')
        );
    }

    public function create_admin_page() {
        ?>
        <div class="wrap">
            <h1><?php echo esc_html__('WooCommerce Menü Manager', $this->textdomain); ?></h1>
            <form method="post" action="options.php">
                <?php
                    settings_fields('wc_menu_manager_option_group');
                    do_settings_sections('wc-menu-manager-settings');
                    submit_button();
                ?>
            </form>
        </div>
        <?php
    }

    public function page_init() {
        register_setting('wc_menu_manager_option_group', $this->option_key, array($this, 'sanitize'));

        add_settings_section(
            'setting_section_id',
            esc_html__('Wählen Sie die zu verschiebenden Menüpunkte', $this->textdomain),
            array($this, 'print_section_info'),
            'wc-menu-manager-settings'
        );

        foreach($this->menus_to_move as $key => $menu) {
            add_settings_field(
                $key,
                $menu['name'],
                array($this, 'create_checkbox_field'),
                'wc-menu-manager-settings',
                'setting_section_id',
                array('id' => $key)
            );
        }
    }

    public function print_section_info() {
        echo esc_html__('Wählen Sie die Menüpunkte, die Sie als Untermenü von "WooCommerce" anzeigen möchten:', $this->textdomain);
    }

    public function create_checkbox_field($args) {
        $options = get_option($this->option_key);
        if(!is_array($options) || empty($options)) {
            $options = $this->get_default_settings();
        }
        $id = $args['id'];
        ?>
        <input type="checkbox" id="<?php echo esc_attr($id); ?>" name="<?php echo esc_attr($this->option_key); ?>[<?php echo esc_attr($id); ?>]" value="1" <?php checked(isset($options[$id]) ? $options[$id] : 0, 1); ?> />
        <label for="<?php echo esc_attr($id); ?>"><?php echo esc_html__('In das Untermenü verschieben', $this->textdomain); ?></label>
        <?php
    }

    public function sanitize($input) {
        $new_input = array();
        foreach(array_keys($this->menus_to_move) as $key) {
            $new_input[$key] = isset($input[$key]) && '1' === $input[$key] ? 1 : 0;
        }
        return $new_input;
    }

    public function move_menus_based_on_settings() {
        $options = get_option($this->option_key);
        if(!is_array($options) || empty($options)) {
            $options = $this->get_default_settings();
        }

        if(!is_admin() || !is_user_logged_in() || !$options) return;

        global $menu;
        $parent_slug = 'woocommerce';
        $remove_slugs = array();

        foreach($this->menus_to_move as $key => $menu_data) {
            if(isset($options[$key]) && $options[$key] == 1) {
                $remove_slugs[] = $menu_data['remove'];
            }
        }

        if(!empty($menu) && !empty($remove_slugs)) {
            foreach($menu as $k => $item) {
                if(in_array($item[2], $remove_slugs)) unset($menu[$k]);
            }
        }

        foreach($this->menus_to_move as $key => $menu_data) {
            if(isset($options[$key]) && $options[$key] == 1) {
                add_submenu_page(
                    $parent_slug,
                    $menu_data['name'],
                    $menu_data['name'],
                    $menu_data['cap'],
                    $menu_data['add']
                );
            }
        }
    }

}

new WC_Menu_Manager();

