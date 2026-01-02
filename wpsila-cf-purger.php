<?php
/**
 * Plugin Name: WPSILA Edge Purge
 * Description: Giải pháp tối ưu hóa Cloudflare Edge Cache. Tự động xóa cache thông minh cho bài viết, chuyên mục, và bình luận.
 * Version: 3.0 (Professional Refactored)
 * Author: WPSILA & AI
 * License: GPLv3
 */
 
// có hỗ trợ sản phẩm WooCommerce, nhưng không cần thông báo điều này.

if ( ! defined( 'ABSPATH' ) ) exit;

class WPSILA_CF_Purger {

    /**
     * @var WPSILA_CF_Purger Instance của class
     */
    private static $instance = null;

    private $option_name  = 'wpsila_cf_settings';
    private $option_group = 'wpsila_cf_options';
    private $page_slug    = 'wpsila-cf-purger';

    /**
     * Khởi tạo Singleton
     */
    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        // Giao diện quản trị
        add_action( 'admin_menu', [ $this, 'add_plugin_page' ] );
        add_action( 'admin_init', [ $this, 'page_init' ] );
        add_filter( 'plugin_action_links_' . plugin_basename(__FILE__), [ $this, 'add_settings_link' ] );

        // Đăng ký các sự kiện xóa cache nếu đã cấu hình
        if ( $this->is_ready() ) {
            $this->init_purge_hooks();
        }
    }

    /**
     * Kiểm tra cấu hình API
     */
    private function is_ready() {
        $options = get_option( $this->option_name );
        return ! empty( $options['zone_id'] ) && ! empty( $options['api_token'] );
    }

    /**
     * Đăng ký tất cả các trigger xóa cache
     */
    private function init_purge_hooks() {
        // Khi nội dung thay đổi (Post, Page, CPT, WooCommerce)
        add_action( 'transition_post_status', [ $this, 'smart_purge_logic' ], 10, 3 );

        // Khi có bình luận được đăng hoặc thay đổi trạng thái
        add_action( 'wp_insert_comment', [ $this, 'purge_on_comment' ], 10, 2 );
        add_action( 'transition_comment_status', [ $this, 'purge_on_comment_status_change' ], 10, 3 );

        // Hệ thống thay đổi
        add_action( 'switch_theme', [ $this, 'purge_everything' ] );
        add_action( 'upgrader_process_complete', [ $this, 'handle_updates' ], 10, 2 );
    }

    /**
     * Gửi yêu cầu đến Cloudflare API
     */
    private function call_cloudflare_api( $data, $blocking = false ) {
        $options = get_option( $this->option_name );
        $url     = 'https://api.cloudflare.com/client/v4/zones/' . esc_attr( $options['zone_id'] ) . '/purge_cache';

        $args = [
            'body'      => json_encode( $data ),
            'headers'   => [
                'Authorization' => 'Bearer ' . $options['api_token'],
                'Content-Type'  => 'application/json',
            ],
            'method'    => 'POST',
            'blocking'  => $blocking,
            'timeout'   => 15,
        ];

        $response = wp_remote_post( $url, $args );

        if ( is_wp_error( $response ) ) {
            $this->log_error( 'WP_Error: ' . $response->get_error_message() );
            return false;
        }

        if ( $blocking ) {
            $body = json_decode( wp_remote_retrieve_body( $response ), true );
            if ( empty( $body['success'] ) ) {
                $error_msg = isset( $body['errors'][0]['message'] ) ? $body['errors'][0]['message'] : 'Unknown API Error';
                $this->log_error( 'Cloudflare API Error: ' . $error_msg );
                return false;
            }
            return true;
        }

        return true;
    }

    /**
     * Xử lý xóa cache bài viết và các Archive liên quan
     */
    public function smart_purge_logic( $new_status, $old_status, $post ) {
        if ( $new_status !== 'publish' && $old_status !== 'publish' ) {
            return;
        }

        $urls = [];

        // 1. URL bài viết
        $urls[] = get_permalink( $post->ID );

        // 2. Trang chủ & RSS
        $urls[] = home_url( '/' );
        $urls[] = get_bloginfo( 'rss2_url' );

        // 3. Archives (Category, Tags, Taxonomy)
        $taxonomies = get_object_taxonomies( $post->post_type );
        foreach ( $taxonomies as $taxonomy ) {
            $terms = get_the_terms( $post->ID, $taxonomy );
            if ( $terms && ! is_wp_error( $terms ) ) {
                foreach ( $terms as $term ) {
                    $urls[] = get_term_link( $term );
                }
            }
        }

        // 4. Post Type Archive (e.g. /shop/ của WooCommerce)
        $archive_link = get_post_type_archive_link( $post->post_type );
        if ( $archive_link ) {
            $urls[] = $archive_link;
        }

        $this->execute_purge_urls( $urls );
    }

    /**
     * Xóa cache khi có bình luận mới
     */
    public function purge_on_comment( $comment_id, $comment_obj ) {
        if ( $comment_obj->comment_approved == 1 ) {
            $this->execute_purge_urls( [ get_permalink( $comment_obj->comment_post_ID ) ] );
        }
    }

    public function purge_on_comment_status_change( $new_status, $old_status, $comment ) {
        if ( $new_status === 'approved' || $old_status === 'approved' ) {
            $this->execute_purge_urls( [ get_permalink( $comment->comment_post_ID ) ] );
        }
    }

    /**
     * Thực thi gửi danh sách URL (chia chunk)
     */
    private function execute_purge_urls( $urls ) {
        $urls = array_filter( array_unique( $urls ) ); // Loại bỏ rỗng và trùng
        if ( empty( $urls ) ) return;

        $chunks = array_chunk( $urls, 30 );
        foreach ( $chunks as $chunk ) {
            $this->call_cloudflare_api( [ 'files' => array_values( $chunk ) ], false );
        }
    }

    public function purge_everything( $blocking = false ) {
        return $this->call_cloudflare_api( [ 'purge_everything' => true ], $blocking );
    }

    /**
     * Ghi log lỗi để debug
     */
    private function log_error( $message ) {
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( '[WPSILA Edge Purge] ' . $message );
        }
    }

    /**
     * GIAO DIỆN ADMIN & CÀI ĐẶT
     */
    public function add_plugin_page() {
        add_options_page( 'WPSILA Edge Purge', 'Edge Purge', 'manage_options', $this->page_slug, [ $this, 'render_admin_page' ] );
    }

    public function render_admin_page() {
        $is_configured = $this->is_ready();
        ?>
        <div class="wrap">
            <h1><span class="dashicons dashicons-cloud"></span> WPSILA Edge Purge</h1>
            
            <?php
            if ( isset( $_POST['wpsila_manual_purge_all'] ) ) {
                check_admin_referer( 'wpsila_purge_all_action', 'wpsila_nonce' );
                if ( $this->purge_everything( true ) ) {
                    echo '<div class="updated"><p>Toàn bộ cache đã được xóa thành công!</p></div>';
                } else {
                    echo '<div class="error"><p>Lỗi kết nối API. Vui lòng kiểm tra log hoặc API Token.</p></div>';
                }
            }
            ?>

            <div class="card">
                <form method="post" action="options.php">
                    <?php
                    settings_fields( $this->option_group );
                    do_settings_sections( $this->page_slug );
                    submit_button( 'Lưu cấu hình API' );
                    ?>
                </form>
            </div>

            <?php if ( $is_configured ) : ?>
                <div class="card" style="margin-top: 20px; border-left: 4px solid #ffb900;">
                    <h2>Xóa Cache thủ công</h2>
                    <p>Hành động này sẽ xóa <strong>tất cả</strong> dữ liệu đã lưu trên các máy chủ Edge của Cloudflare.</p>
                    <form method="post">
                        <?php wp_nonce_field( 'wpsila_purge_all_action', 'wpsila_nonce' ); ?>
                        <input type="submit" name="wpsila_manual_purge_all" class="button button-primary" value="Purge Everything (Xóa sạch toàn bộ)">
                    </form>
                </div>
            <?php endif; ?>
        </div>
        <style>.card { max-width: 800px; padding: 10px 20px; }</style>
        <?php
    }

    public function page_init() {
        register_setting( $this->option_group, $this->option_name, [ $this, 'sanitize' ] );
        add_settings_section( 'main_section', 'Cấu hình kết nối Cloudflare', null, $this->page_slug );
        add_settings_field( 'zone_id', 'Zone ID', [ $this, 'fld_zone_id' ], $this->page_slug, 'main_section' );
        add_settings_field( 'api_token', 'API Token (Bearer)', [ $this, 'fld_api_token' ], $this->page_slug, 'main_section' );
    }

    public function sanitize( $input ) {
        $old = get_option( $this->option_name );
        $new = [];
        $new['zone_id'] = sanitize_text_field( $input['zone_id'] );
        $new['api_token'] = ! empty( $input['api_token'] ) ? sanitize_text_field( $input['api_token'] ) : $old['api_token'];
        return $new;
    }

    public function fld_zone_id() {
        $opt = get_option( $this->option_name );
        printf( '<input type="text" name="%s[zone_id]" value="%s" class="regular-text" required placeholder="e.g. 123456789abcdef..." />', $this->option_name, esc_attr( $opt['zone_id'] ?? '' ) );
    }

    public function fld_api_token() {
        $opt = get_option( $this->option_name );
        $placeholder = ! empty( $opt['api_token'] ) ? '******** (Đã lưu)' : 'Nhập API Token...';
        printf( '<input type="password" name="%s[api_token]" value="" placeholder="%s" class="regular-text" />', $this->option_name, $placeholder );
    }

    public function add_settings_link( $links ) {
        return array_merge( [ '<a href="options-general.php?page=' . $this->page_slug . '">Cài đặt</a>' ], $links );
    }

    public function handle_updates( $upgrader, $hook_extra ) {
        if ( isset( $hook_extra['action'] ) && in_array( $hook_extra['action'], [ 'update', 'install' ] ) ) {
            $this->purge_everything( false );
        }
    }
}

// Khởi chạy an toàn với Singleton
add_action( 'plugins_loaded', [ 'WPSILA_CF_Purger', 'get_instance' ] );