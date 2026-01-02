<?php
/**
 * Plugin Name: WPSILA Edge Purge
 * Description: Giải pháp xóa cache Cloudflare tự động, siêu nhẹ. Logic thông minh cho Category/Tag và kiểm tra lỗi API chính xác.
 * Version: 2.3
 * Author: WPSILA
 * License: GPLv3
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class WPSILA_CF_Purger {

    private $option_group = 'wpsila_cf_options';
    private $option_name  = 'wpsila_cf_settings';
    private $page_slug    = 'wpsila-cf-purger';

    public function __construct() {
        add_action( 'admin_menu', [ $this, 'add_plugin_page' ] );
        add_action( 'admin_init', [ $this, 'page_init' ] );
        add_filter( 'plugin_action_links_' . plugin_basename(__FILE__), [ $this, 'add_settings_link' ] );

        // Chỉ chạy hooks khi đã cấu hình API
        if ( $this->has_credentials() ) {
            // Thay thế save_post bằng transition_post_status để xử lý logic Sửa vs Thêm mới/Xóa
            add_action( 'transition_post_status', [ $this, 'smart_purge_on_transition' ], 10, 3 );
            
            // Khi đổi theme
            add_action( 'switch_theme', [ $this, 'purge_everything' ] );
            
            // Khi cập nhật Plugin/Theme/Core
            add_action( 'upgrader_process_complete', [ $this, 'handle_updates' ], 10, 2 );
        }
    }

    /**
     * Kiểm tra xem đã nhập Zone ID và Token chưa
     */
    private function has_credentials() {
        $options = get_option( $this->option_name );
        return ! empty($options['zone_id']) && ! empty($options['api_token']);
    }

    /**
     * --- GIAO DIỆN ADMIN ---
     */
    public function add_plugin_page() {
        add_options_page(
            'WPSILA Edge Purge', 
            'WPSILA Edge Purge', 
            'manage_options', 
            $this->page_slug, 
            [ $this, 'create_admin_page' ]
        );
    }

    public function create_admin_page() {
        $configured = $this->has_credentials();
        ?>
        <div class="wrap">
            <h1>WPSILA Edge Purge - Cấu hình Cloudflare</h1>
            
            <?php if ( ! $configured ) : ?>
                <div class="notice notice-warning inline"><p>Vui lòng nhập <strong>Zone ID</strong> và <strong>API Token</strong> để plugin hoạt động.</p></div>
            <?php endif; ?>

            <form method="post" action="options.php">
                <?php
                settings_fields( $this->option_group );
                do_settings_sections( $this->page_slug );
                submit_button();
                ?>
            </form>
            <hr>
            <h3>Manual Purge (Thủ công)</h3>
            <p>Xóa toàn bộ cache trên Cloudflare ngay lập tức. Dùng khi bạn thay đổi cấu hình website hoặc giao diện.</p>
            
            <form method="post">
                <input type="hidden" name="wpsila_manual_purge" value="1">
                <?php wp_nonce_field( 'wpsila_purge_action', 'wpsila_nonce' ); ?>
                
                <?php if ( $configured ) : ?>
                    <input type="submit" class="button button-secondary" value="Purge Everything Now">
                <?php else : ?>
                    <input type="button" class="button button-secondary" value="Chưa cấu hình API" disabled>
                <?php endif; ?>
            </form>

            <?php
            // Xử lý nút bấm thủ công
            if ( isset($_POST['wpsila_manual_purge']) && check_admin_referer('wpsila_purge_action', 'wpsila_nonce') ) {
                // Truyền true vào để bật chế độ chờ phản hồi (Blocking request)
                if ( $this->purge_everything( true ) ) {
                    echo '<div class="updated"><p><strong>Thành công:</strong> Cloudflare xác nhận đã xóa toàn bộ Cache!</p></div>';
                } else {
                    echo '<div class="error"><p><strong>Lỗi:</strong> Cloudflare từ chối yêu cầu. Vui lòng kiểm tra lại <strong>Zone ID</strong> và <strong>API Token</strong> (Quyền Cache Purge).</p></div>';
                }
            }
            ?>
        </div>
        <?php
    }

    public function page_init() {
        register_setting( $this->option_group, $this->option_name, [ $this, 'sanitize' ] );
        add_settings_section( 'main_section', 'Thông tin kết nối API', null, $this->page_slug );
        add_settings_field( 'zone_id', 'Zone ID', [ $this, 'zone_id_callback' ], $this->page_slug, 'main_section' );
        add_settings_field( 'api_token', 'API Token', [ $this, 'api_token_callback' ], $this->page_slug, 'main_section' );
    }

    public function sanitize( $input ) {
        $new_input = array();
        $old_input = get_option( $this->option_name );

        if( isset( $input['zone_id'] ) ) {
            $new_input['zone_id'] = sanitize_text_field( $input['zone_id'] );
        }

        // Logic giữ lại token cũ nếu người dùng không nhập mới
        if( ! empty( $input['api_token'] ) ) {
            $new_input['api_token'] = sanitize_text_field( $input['api_token'] );
        } elseif ( ! empty( $old_input['api_token'] ) ) {
            $new_input['api_token'] = $old_input['api_token'];
        }

        return $new_input;
    }

    public function zone_id_callback() {
        $options = get_option( $this->option_name );
        $val = isset( $options['zone_id'] ) ? esc_attr( $options['zone_id'] ) : '';
        echo "<input type='text' name='{$this->option_name}[zone_id]' value='$val' class='regular-text' required />"; 
        echo "<p class='description'>Tìm thấy ở trang Overview tên miền trên Cloudflare (cột bên phải).</p>";
    }

    public function api_token_callback() {
        $options = get_option( $this->option_name );
        $has_token = ! empty( $options['api_token'] );
        $placeholder = $has_token ? 'Đã lưu Token bảo mật (Nhập vào để đổi mới)' : 'Nhập API Token...';
        
        echo "<input type='password' name='{$this->option_name}[api_token]' value='' placeholder='$placeholder' class='regular-text' />";
        
        if ($has_token) {
            echo "<p class='description' style='color:green;'>✓ Đã lưu Token.</p>";
        } else {
            echo "<p class='description'>Tạo Token với quyền: <strong>Zone - Cache Purge - Purge</strong>.</p>";
        }
    }

    public function add_settings_link( $links ) {
        $settings_link = '<a href="options-general.php?page=' . $this->page_slug . '">Settings</a>';
        array_unshift( $links, $settings_link );
        return $links;
    }

    /**
     * --- CORE LOGIC ---
     */

    /**
     * Gửi request lên Cloudflare
     * @param array $data Dữ liệu body (files hoặc purge_everything)
     * @param bool $blocking true: Chờ phản hồi (cho Manual), false: Không chờ (cho Auto)
     */
    private function send_request( $data, $blocking = false ) {
        if ( ! $this->has_credentials() ) {
            return false;
        }

        $options = get_option( $this->option_name );
        $url = 'https://api.cloudflare.com/client/v4/zones/' . $options['zone_id'] . '/purge_cache';

        $response = wp_remote_post( $url, [
            'body'    => json_encode( $data ),
            'headers' => [
                'Authorization' => 'Bearer ' . $options['api_token'],
                'Content-Type'  => 'application/json',
            ],
            'method'   => 'POST',
            'blocking' => $blocking, 
            'timeout'  => 10,
        ]);

        if ( is_wp_error( $response ) ) {
            return false;
        }

        // Nếu là chế độ chờ (Thủ công), cần kiểm tra body trả về
        if ( $blocking ) {
            $body = json_decode( wp_remote_retrieve_body( $response ), true );
            // Cloudflare trả về "success": true
            if ( isset( $body['success'] ) && $body['success'] === true ) {
                return true;
            } else {
                return false;
            }
        }

        // Chế độ tự động (Non-blocking) luôn trả về true để không làm chậm code
        return true;
    }

    /**
     * Logic thông minh xử lý các trạng thái bài viết
     */
    public function smart_purge_on_transition( $new_status, $old_status, $post ) {
        // Chỉ xử lý nếu bài viết liên quan đến trạng thái 'publish'
        if ( $new_status !== 'publish' && $old_status !== 'publish' ) {
            return;
        }

        $files = [];

        // 1. Luôn xóa cache của chính bài viết đó (Link bài viết)
        $permalink = get_permalink( $post->ID );
        if ( $permalink ) {
            $files[] = $permalink;
        }

        // 2. Xác định: Đây là bài MỚI / XÓA bài hay chỉ là SỬA bài cũ?
        // - Bài mới: Từ (new/draft/pending) -> publish
        // - Xóa bài: Từ publish -> (trash/draft)
        $is_new_or_deleted = ( $new_status === 'publish' && $old_status !== 'publish' ) || 
                             ( $new_status !== 'publish' && $old_status === 'publish' );

        // 3. Nếu là Mới hoặc Xóa -> Xóa thêm Category, Tag, Home
        if ( $is_new_or_deleted ) {
            // Trang chủ
            $files[] = home_url('/');

            // Lấy tất cả taxonomy (Category, Tag, Custom Tax)
            $taxonomies = get_object_taxonomies( $post->post_type );
            foreach ( $taxonomies as $tax ) {
                $terms = get_the_terms( $post->ID, $tax );
                if ( ! empty( $terms ) && ! is_wp_error( $terms ) ) {
                    foreach ( $terms as $term ) {
                        $term_link = get_term_link( $term );
                        if ( ! is_wp_error( $term_link ) ) {
                            $files[] = $term_link;
                        }
                    }
                }
            }
        }
        // Nếu chỉ là Sửa (publish -> publish): Code sẽ bỏ qua đoạn trên, chỉ xóa $permalink.

        // Gửi lệnh xóa (Chế độ tự động: blocking = false)
        if ( ! empty( $files ) ) {
            $files = array_unique( $files );
            // Limit tối đa 30 URL mỗi lần request để an toàn API (Cloudflare cho phép 30)
            $chunks = array_chunk( $files, 30 );
            foreach ( $chunks as $chunk ) {
                $this->send_request( [ 'files' => array_values( $chunk ) ], false );
            }
        }
    }

    /**
     * Xóa toàn bộ
     * @param bool $blocking Có chờ phản hồi không?
     */
    public function purge_everything( $blocking = false ) {
        return $this->send_request( [ 'purge_everything' => true ], $blocking );
    }
    
    public function handle_updates( $upgrader_object, $options ) {
        $actions = [ 'update', 'install', 'deleted'];
        if ( in_array( $options['action'], $actions ) && ( $options['type'] == 'plugin' || $options['type'] == 'theme' ) ) {
            $this->purge_everything( false ); // Auto => không chờ
        }
    }   
}

new WPSILA_CF_Purger();