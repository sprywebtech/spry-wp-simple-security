<?php
/**
 * Plugin Name: Spry Simple WP Security
 * Plugin URI:  https://sprywebtech.com/
 * Description: Lightweight WordPress hardening for dashboard file editing, XML-RPC, and PHP execution in the uploads directory.
 * Version:     1.0.3
 * Author:      Spry Web Tech
 * Author URI:  https://sprywebtech.com/
 * License:     GPL-2.0-or-later
 * Text Domain: spry-simple-wp-security
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Spry_Simple_WP_Security {
    const VERSION          = '1.0.3';
    const OPTION_SETTINGS  = 'sswps_settings';
    const OPTION_STATE     = 'sswps_file_state';
    const NOTICE_TRANSIENT = 'sswps_admin_notices';

    const WP_CONFIG_START = "/* BEGIN Spry Simple WP Security */";
    const WP_CONFIG_END   = "/* END Spry Simple WP Security */";
    const HTACCESS_START  = "# BEGIN Spry Simple WP Security";
    const HTACCESS_END    = "# END Spry Simple WP Security";

    private static $instance = null;

    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'admin_menu', array( $this, 'add_settings_page' ) );
        add_action( 'admin_init', array( $this, 'register_settings' ) );
        add_action( 'admin_notices', array( $this, 'render_admin_notices' ) );
        add_action( 'plugins_loaded', array( $this, 'maybe_block_xmlrpc' ), 0 );
        add_action( 'admin_post_sswps_download_backup', array( $this, 'download_backup' ) );

        add_filter( 'xmlrpc_enabled', array( $this, 'filter_xmlrpc_enabled' ) );
        add_filter( 'wp_headers', array( $this, 'remove_pingback_header' ) );
    }

    public static function activate() {
        $defaults = self::default_settings();
        $settings = get_option( self::OPTION_SETTINGS, null );

        if ( ! is_array( $settings ) ) {
            update_option( self::OPTION_SETTINGS, $defaults, false );
            $settings = $defaults;
        } else {
            $settings = wp_parse_args( $settings, $defaults );
        }

        self::ensure_backup_directory();
        self::apply_settings_static( $settings );
    }

    public static function deactivate() {
        $errors = array();

        $config_result = self::remove_wp_config_rule();
        if ( is_wp_error( $config_result ) ) {
            $errors[] = $config_result->get_error_message();
        }

        $uploads_result = self::remove_uploads_htaccess_rule();
        if ( is_wp_error( $uploads_result ) ) {
            $errors[] = $uploads_result->get_error_message();
        }

        if ( empty( $errors ) ) {
            self::delete_backup_directory();
            delete_option( self::OPTION_STATE );
        } else {
            self::queue_notice( implode( ' ', $errors ), 'error' );
        }
    }

    public static function default_settings() {
        return array(
            'disable_file_edit' => 1,
            'disable_xmlrpc'    => 1,
            'protect_uploads'   => 1,
        );
    }

    public function add_settings_page() {
        add_options_page(
            __( 'Spry Simple WP Security', 'spry-simple-wp-security' ),
            __( 'Spry Simple WP Security', 'spry-simple-wp-security' ),
            'manage_options',
            'spry-simple-wp-security',
            array( $this, 'render_settings_page' )
        );
    }

    public function register_settings() {
        register_setting(
            'sswps_settings_group',
            self::OPTION_SETTINGS,
            array(
                'type'              => 'array',
                'sanitize_callback' => array( $this, 'sanitize_settings' ),
                'default'           => self::default_settings(),
            )
        );
    }

    public function sanitize_settings( $input ) {
        $old = wp_parse_args( get_option( self::OPTION_SETTINGS, array() ), self::default_settings() );
        $new = array(
            'disable_file_edit' => empty( $input['disable_file_edit'] ) ? 0 : 1,
            'disable_xmlrpc'    => empty( $input['disable_xmlrpc'] ) ? 0 : 1,
            'protect_uploads'   => empty( $input['protect_uploads'] ) ? 0 : 1,
        );

        $errors = self::apply_settings_static( $new, $old );
        foreach ( $errors as $error ) {
            add_settings_error( self::OPTION_SETTINGS, 'sswps_' . wp_generate_uuid4(), $error, 'error' );
        }

        if ( empty( $errors ) ) {
            add_settings_error(
                self::OPTION_SETTINGS,
                'sswps_saved',
                __( 'Security settings saved and applied.', 'spry-simple-wp-security' ),
                'success'
            );
        }

        return $new;
    }

    private static function apply_settings_static( $new, $old = null ) {
        $errors = array();
        $old    = is_array( $old ) ? $old : array();

        if ( ! empty( $new['disable_file_edit'] ) ) {
            $result = self::add_wp_config_rule();
        } else {
            $result = self::remove_wp_config_rule();
        }
        if ( is_wp_error( $result ) ) {
            $errors[] = $result->get_error_message();
        }

        if ( ! empty( $new['protect_uploads'] ) ) {
            $result = self::add_uploads_htaccess_rule();
        } else {
            $result = self::remove_uploads_htaccess_rule();
        }
        if ( is_wp_error( $result ) ) {
            $errors[] = $result->get_error_message();
        }

        return $errors;
    }

    public function render_settings_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $settings = wp_parse_args( get_option( self::OPTION_SETTINGS, array() ), self::default_settings() );
        $config   = self::locate_wp_config();
        $uploads  = wp_upload_dir();
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Spry Simple WP Security', 'spry-simple-wp-security' ); ?></h1>
            <p><?php esc_html_e( 'Lightweight hardening with reversible, marker-based file changes and backup copies.', 'spry-simple-wp-security' ); ?></p>


            <form method="post" action="options.php">
                <?php settings_fields( 'sswps_settings_group' ); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Disable Dashboard File Editing', 'spry-simple-wp-security' ); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="<?php echo esc_attr( self::OPTION_SETTINGS ); ?>[disable_file_edit]" value="1" <?php checked( 1, $settings['disable_file_edit'] ); ?>>
                                <?php esc_html_e( 'Add DISALLOW_FILE_EDIT to wp-config.php', 'spry-simple-wp-security' ); ?>
                            </label>
                            <p class="description"><?php echo esc_html( $config ? $config : __( 'wp-config.php could not be located.', 'spry-simple-wp-security' ) ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Disable XML-RPC', 'spry-simple-wp-security' ); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="<?php echo esc_attr( self::OPTION_SETTINGS ); ?>[disable_xmlrpc]" value="1" <?php checked( 1, $settings['disable_xmlrpc'] ); ?>>
                                <?php esc_html_e( 'Return HTTP 403 for XML-RPC requests and remove the pingback header', 'spry-simple-wp-security' ); ?>
                            </label>
                            <p class="description"><?php esc_html_e( 'This does not modify web-server configuration files.', 'spry-simple-wp-security' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Block PHP in Uploads', 'spry-simple-wp-security' ); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="<?php echo esc_attr( self::OPTION_SETTINGS ); ?>[protect_uploads]" value="1" <?php checked( 1, $settings['protect_uploads'] ); ?>>
                                <?php esc_html_e( 'Add protected rules to the uploads .htaccess file', 'spry-simple-wp-security' ); ?>
                            </label>
                            <p class="description"><?php echo esc_html( trailingslashit( $uploads['basedir'] ) . '.htaccess' ); ?></p>
                        </td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>

            <?php $this->render_backup_downloads(); ?>
        </div>
        <?php
    }

    private function get_backup_files() {
        $dir = trailingslashit( WP_CONTENT_DIR ) . 'spry-simple-wp-security-backups';
        if ( ! is_dir( $dir ) ) {
            return array();
        }

        $files = glob( trailingslashit( $dir ) . '*.bak.php' );
        return is_array( $files ) ? $files : array();
    }

    private function render_backup_downloads() {
        $files = $this->get_backup_files();
        ?>
        <hr>
        <h2><?php esc_html_e( 'Backup Files', 'spry-simple-wp-security' ); ?></h2>
        <p><?php esc_html_e( 'Download the original file copies created before this plugin made its changes.', 'spry-simple-wp-security' ); ?></p>

        <?php if ( empty( $files ) ) : ?>
            <p><?php esc_html_e( 'No backup files are currently available.', 'spry-simple-wp-security' ); ?></p>
        <?php else : ?>
            <table class="widefat striped" style="max-width: 760px;">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'Backup', 'spry-simple-wp-security' ); ?></th>
                        <th><?php esc_html_e( 'Created', 'spry-simple-wp-security' ); ?></th>
                        <th><?php esc_html_e( 'Download', 'spry-simple-wp-security' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $files as $file ) :
                        $basename = basename( $file );
                        $label    = preg_replace( '/\.bak\.php$/', '', $basename );
                        $url      = wp_nonce_url(
                            add_query_arg(
                                array(
                                    'action' => 'sswps_download_backup',
                                    'file'   => rawurlencode( $basename ),
                                ),
                                admin_url( 'admin-post.php' )
                            ),
                            'sswps_download_backup_' . $basename
                        );
                        ?>
                        <tr>
                            <td><code><?php echo esc_html( $label ); ?></code></td>
                            <td><?php echo esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), filemtime( $file ) ) ); ?></td>
                            <td><a class="button button-secondary" href="<?php echo esc_url( $url ); ?>"><?php esc_html_e( 'Download', 'spry-simple-wp-security' ); ?></a></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif;
    }

    public function download_backup() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You are not allowed to download these backup files.', 'spry-simple-wp-security' ), 403 );
        }

        $requested = isset( $_GET['file'] ) ? rawurldecode( wp_unslash( $_GET['file'] ) ) : '';
        $basename  = basename( $requested );
        $available = array_map( 'basename', $this->get_backup_files() );

        if (
            '' === $requested ||
            $requested !== $basename ||
            ! preg_match( '/^[A-Za-z0-9._-]+\.bak\.php$/', $basename ) ||
            ! in_array( $basename, $available, true )
        ) {
            wp_die( esc_html__( 'Invalid backup file.', 'spry-simple-wp-security' ), 400 );
        }

        check_admin_referer( 'sswps_download_backup_' . $basename );

        $dir      = trailingslashit( WP_CONTENT_DIR ) . 'spry-simple-wp-security-backups';
        $file     = trailingslashit( $dir ) . $basename;
        $real_dir = realpath( $dir );
        $real     = realpath( $file );

        if ( false === $real_dir || false === $real || dirname( $real ) !== $real_dir || ! is_readable( $real ) ) {
            wp_die( esc_html__( 'The requested backup file could not be found.', 'spry-simple-wp-security' ), 404 );
        }

        $protected = file_get_contents( $real );
        if ( false === $protected ) {
            wp_die( esc_html__( 'The requested backup file could not be read.', 'spry-simple-wp-security' ), 500 );
        }

        $parts   = preg_split( '/\R/', $protected, 2 );
        $payload = isset( $parts[1] ) ? trim( $parts[1] ) : '';
        $name    = preg_replace( '/\.bak\.php$/', '', $basename );

        if ( 'SSWPS_ORIGINAL_FILE_DID_NOT_EXIST' === $payload ) {
            $contents = "No original file existed before Spry Simple WP Security created it.\n";
            $name    .= '.not-created.txt';
        } else {
            $contents = base64_decode( $payload, true );
            if ( false === $contents ) {
                wp_die( esc_html__( 'The backup file is invalid or corrupted.', 'spry-simple-wp-security' ), 500 );
            }
        }

        nocache_headers();
        header( 'Content-Type: application/octet-stream' );
        header( 'Content-Disposition: attachment; filename="' . str_replace( '"', '', $name ) . '"' );
        header( 'Content-Length: ' . strlen( $contents ) );
        echo $contents; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        exit;
    }

    public function filter_xmlrpc_enabled( $enabled ) {
        return $this->is_enabled( 'disable_xmlrpc' ) ? false : $enabled;
    }

    public function remove_pingback_header( $headers ) {
        if ( $this->is_enabled( 'disable_xmlrpc' ) ) {
            unset( $headers['X-Pingback'] );
        }
        return $headers;
    }

    public function maybe_block_xmlrpc() {
        if ( ! $this->is_enabled( 'disable_xmlrpc' ) ) {
            return;
        }

        if ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) {
            status_header( 403 );
            nocache_headers();
            header( 'Content-Type: text/plain; charset=utf-8' );
            echo 'XML-RPC is disabled.';
            exit;
        }
    }

    private function is_enabled( $key ) {
        $settings = wp_parse_args( get_option( self::OPTION_SETTINGS, array() ), self::default_settings() );
        return ! empty( $settings[ $key ] );
    }

    private static function locate_wp_config() {
        $locations = array(
            ABSPATH . 'wp-config.php',
            dirname( ABSPATH ) . '/wp-config.php',
        );

        foreach ( array_unique( $locations ) as $file ) {
            if ( is_file( $file ) && is_readable( $file ) ) {
                return wp_normalize_path( $file );
            }
        }
        return false;
    }

    private static function add_wp_config_rule() {
        $file = self::locate_wp_config();
        if ( ! $file ) {
            return new WP_Error( 'sswps_no_config', __( 'Spry Simple WP Security could not locate wp-config.php.', 'spry-simple-wp-security' ) );
        }
        if ( ! is_writable( $file ) ) {
            return new WP_Error( 'sswps_config_not_writable', sprintf( __( 'wp-config.php is not writable: %s', 'spry-simple-wp-security' ), $file ) );
        }

        $contents = file_get_contents( $file );
        if ( false === $contents ) {
            return new WP_Error( 'sswps_config_read_failed', __( 'Could not read wp-config.php.', 'spry-simple-wp-security' ) );
        }

        if ( false !== strpos( $contents, self::WP_CONFIG_START ) ) {
            return true;
        }

        $backup = self::backup_file( $file, 'wp-config.php' );
        if ( is_wp_error( $backup ) ) {
            return $backup;
        }

        $block = self::WP_CONFIG_START . PHP_EOL
            . "if ( ! defined( 'DISALLOW_FILE_EDIT' ) ) {" . PHP_EOL
            . "    define( 'DISALLOW_FILE_EDIT', true );" . PHP_EOL
            . "}" . PHP_EOL
            . self::WP_CONFIG_END . PHP_EOL . PHP_EOL;

        $patterns = array(
            "/\/\* That's all, stop editing!.*?\*\//",
            "/\/\* That's all, stop editing.*?\*\//",
            "/require_once\s+ABSPATH\s*\.\s*'wp-settings\.php'\s*;/",
        );

        $new_contents = null;
        foreach ( $patterns as $pattern ) {
            if ( preg_match( $pattern, $contents, $matches, PREG_OFFSET_CAPTURE ) ) {
                $offset       = $matches[0][1];
                $new_contents = substr( $contents, 0, $offset ) . $block . substr( $contents, $offset );
                break;
            }
        }

        if ( null === $new_contents ) {
            $new_contents = rtrim( $contents ) . PHP_EOL . PHP_EOL . $block;
        }

        if ( ! self::atomic_write( $file, $new_contents ) ) {
            return new WP_Error( 'sswps_config_write_failed', __( 'Could not update wp-config.php.', 'spry-simple-wp-security' ) );
        }

        self::update_file_state( 'wp_config', array( 'path' => $file, 'backup' => $backup ) );
        return true;
    }

    private static function remove_wp_config_rule() {
        $file = self::locate_wp_config();
        if ( ! $file || ! is_file( $file ) ) {
            return true;
        }
        if ( ! is_writable( $file ) ) {
            return new WP_Error( 'sswps_config_not_writable', sprintf( __( 'wp-config.php is not writable, so the plugin rule could not be removed: %s', 'spry-simple-wp-security' ), $file ) );
        }

        $contents = file_get_contents( $file );
        if ( false === $contents || false === strpos( $contents, self::WP_CONFIG_START ) ) {
            return true;
        }

        $pattern = '/' . preg_quote( self::WP_CONFIG_START, '/' ) . '.*?' . preg_quote( self::WP_CONFIG_END, '/' ) . '\R*/s';
        $updated = preg_replace( $pattern, '', $contents, 1 );

        if ( null === $updated || ! self::atomic_write( $file, $updated ) ) {
            return new WP_Error( 'sswps_config_restore_failed', __( 'Could not remove the plugin block from wp-config.php.', 'spry-simple-wp-security' ) );
        }
        return true;
    }

    private static function add_uploads_htaccess_rule() {
        $uploads = wp_upload_dir();
        if ( ! empty( $uploads['error'] ) ) {
            return new WP_Error( 'sswps_uploads_error', $uploads['error'] );
        }

        $dir = $uploads['basedir'];
        if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
            return new WP_Error( 'sswps_uploads_missing', __( 'The uploads directory could not be created.', 'spry-simple-wp-security' ) );
        }

        $file     = trailingslashit( $dir ) . '.htaccess';
        $existed  = file_exists( $file );
        $contents = $existed ? file_get_contents( $file ) : '';

        if ( false === $contents ) {
            return new WP_Error( 'sswps_htaccess_read_failed', __( 'Could not read the uploads .htaccess file.', 'spry-simple-wp-security' ) );
        }
        if ( false !== strpos( $contents, self::HTACCESS_START ) ) {
            return true;
        }
        if ( $existed && ! is_writable( $file ) ) {
            return new WP_Error( 'sswps_htaccess_not_writable', sprintf( __( 'The uploads .htaccess file is not writable: %s', 'spry-simple-wp-security' ), $file ) );
        }
        if ( ! $existed && ! is_writable( $dir ) ) {
            return new WP_Error( 'sswps_uploads_not_writable', sprintf( __( 'The uploads directory is not writable: %s', 'spry-simple-wp-security' ), $dir ) );
        }

        $backup = self::backup_file( $file, 'uploads.htaccess', $existed );
        if ( is_wp_error( $backup ) ) {
            return $backup;
        }

        $block = self::HTACCESS_START . PHP_EOL
            . '<FilesMatch "\\.(?:php[0-9]?|phtml|phar)$">' . PHP_EOL
            . '    <IfModule mod_authz_core.c>' . PHP_EOL
            . '        Require all denied' . PHP_EOL
            . '    </IfModule>' . PHP_EOL
            . '    <IfModule !mod_authz_core.c>' . PHP_EOL
            . '        Order Allow,Deny' . PHP_EOL
            . '        Deny from all' . PHP_EOL
            . '    </IfModule>' . PHP_EOL
            . '</FilesMatch>' . PHP_EOL
            . '<IfModule mod_mime.c>' . PHP_EOL
            . '    RemoveHandler .php .php3 .php4 .php5 .php7 .php8 .phtml .phar' . PHP_EOL
            . '    RemoveType .php .php3 .php4 .php5 .php7 .php8 .phtml .phar' . PHP_EOL
            . '</IfModule>' . PHP_EOL
            . self::HTACCESS_END . PHP_EOL;

        $new_contents = rtrim( $contents );
        if ( '' !== $new_contents ) {
            $new_contents .= PHP_EOL . PHP_EOL;
        }
        $new_contents .= $block;

        if ( ! self::atomic_write( $file, $new_contents ) ) {
            return new WP_Error( 'sswps_htaccess_write_failed', __( 'Could not update the uploads .htaccess file.', 'spry-simple-wp-security' ) );
        }

        self::update_file_state(
            'uploads_htaccess',
            array(
                'path'            => wp_normalize_path( $file ),
                'backup'          => $backup,
                'original_exists' => $existed ? 1 : 0,
            )
        );
        return true;
    }

    private static function remove_uploads_htaccess_rule() {
        $uploads = wp_upload_dir();
        if ( ! empty( $uploads['error'] ) ) {
            return true;
        }

        $file = trailingslashit( $uploads['basedir'] ) . '.htaccess';
        if ( ! file_exists( $file ) ) {
            return true;
        }
        if ( ! is_writable( $file ) ) {
            return new WP_Error( 'sswps_htaccess_not_writable', sprintf( __( 'The uploads .htaccess file is not writable, so the plugin rule could not be removed: %s', 'spry-simple-wp-security' ), $file ) );
        }

        $contents = file_get_contents( $file );
        if ( false === $contents || false === strpos( $contents, self::HTACCESS_START ) ) {
            return true;
        }

        $pattern = '/' . preg_quote( self::HTACCESS_START, '/' ) . '.*?' . preg_quote( self::HTACCESS_END, '/' ) . '\R*/s';
        $updated = preg_replace( $pattern, '', $contents, 1 );
        if ( null === $updated ) {
            return new WP_Error( 'sswps_htaccess_restore_failed', __( 'Could not remove the plugin block from the uploads .htaccess file.', 'spry-simple-wp-security' ) );
        }

        $state            = get_option( self::OPTION_STATE, array() );
        $original_existed = ! empty( $state['uploads_htaccess']['original_exists'] );

        if ( '' === trim( $updated ) && ! $original_existed ) {
            if ( ! unlink( $file ) ) {
                return new WP_Error( 'sswps_htaccess_delete_failed', __( 'Could not remove the plugin-created uploads .htaccess file.', 'spry-simple-wp-security' ) );
            }
            return true;
        }

        if ( ! self::atomic_write( $file, rtrim( $updated ) . PHP_EOL ) ) {
            return new WP_Error( 'sswps_htaccess_restore_failed', __( 'Could not update the uploads .htaccess file while removing the plugin block.', 'spry-simple-wp-security' ) );
        }
        return true;
    }

    private static function backup_file( $source, $name, $source_exists = true ) {
        $dir = self::ensure_backup_directory();
        if ( is_wp_error( $dir ) ) {
            return $dir;
        }

        // Use a PHP extension and an immediate exit statement so Nginx cannot
        // expose backup contents even when it serves wp-content directly and
        // does not honor Apache .htaccess files.
        $backup = trailingslashit( $dir ) . sanitize_file_name( $name ) . '.bak.php';
        if ( file_exists( $backup ) ) {
            return wp_normalize_path( $backup );
        }

        $payload = 'SSWPS_ORIGINAL_FILE_DID_NOT_EXIST';
        if ( $source_exists ) {
            if ( ! is_readable( $source ) ) {
                return new WP_Error( 'sswps_backup_failed', sprintf( __( 'Could not read %s before modifying it.', 'spry-simple-wp-security' ), $source ) );
            }

            $source_contents = file_get_contents( $source );
            if ( false === $source_contents ) {
                return new WP_Error( 'sswps_backup_failed', sprintf( __( 'Could not back up %s before modifying it.', 'spry-simple-wp-security' ), $source ) );
            }
            $payload = base64_encode( $source_contents );
        }

        $protected_backup = "<?php exit; ?>\n" . $payload . "\n";
        if ( false === file_put_contents( $backup, $protected_backup, LOCK_EX ) ) {
            return new WP_Error( 'sswps_backup_failed', sprintf( __( 'Could not create a protected backup for %s.', 'spry-simple-wp-security' ), $source ) );
        }

        @chmod( $backup, 0600 );
        return wp_normalize_path( $backup );
    }

    private static function ensure_backup_directory() {
        $dir = trailingslashit( WP_CONTENT_DIR ) . 'spry-simple-wp-security-backups';
        if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
            return new WP_Error( 'sswps_backup_dir_failed', __( 'Could not create the plugin backup directory in wp-content.', 'spry-simple-wp-security' ) );
        }

        if ( ! file_exists( trailingslashit( $dir ) . 'index.php' ) ) {
            @file_put_contents( trailingslashit( $dir ) . 'index.php', "<?php\n// Silence is golden.\n", LOCK_EX );
        }
        if ( ! file_exists( trailingslashit( $dir ) . '.htaccess' ) ) {
            @file_put_contents( trailingslashit( $dir ) . '.htaccess', "Require all denied\n", LOCK_EX );
        }

        return wp_normalize_path( $dir );
    }

    private static function delete_backup_directory() {
        $dir = trailingslashit( WP_CONTENT_DIR ) . 'spry-simple-wp-security-backups';
        if ( ! is_dir( $dir ) ) {
            return;
        }

        $files = glob( trailingslashit( $dir ) . '*' );
        if ( is_array( $files ) ) {
            foreach ( $files as $file ) {
                if ( is_file( $file ) ) {
                    @unlink( $file );
                }
            }
        }
        $hidden = array( trailingslashit( $dir ) . '.htaccess' );
        foreach ( $hidden as $file ) {
            if ( is_file( $file ) ) {
                @unlink( $file );
            }
        }
        @rmdir( $dir );
    }

    private static function atomic_write( $file, $contents ) {
        $dir  = dirname( $file );
        $temp = wp_tempnam( basename( $file ), trailingslashit( $dir ) );
        if ( ! $temp ) {
            return false;
        }

        $bytes = file_put_contents( $temp, $contents, LOCK_EX );
        if ( false === $bytes ) {
            @unlink( $temp );
            return false;
        }

        $perms = file_exists( $file ) ? ( fileperms( $file ) & 0777 ) : 0644;
        @chmod( $temp, $perms );

        if ( ! @rename( $temp, $file ) ) {
            @unlink( $temp );
            return false;
        }
        return true;
    }

    private static function update_file_state( $key, $value ) {
        $state         = get_option( self::OPTION_STATE, array() );
        $state[ $key ] = $value;
        update_option( self::OPTION_STATE, $state, false );
    }

    private static function queue_notice( $message, $type = 'error' ) {
        $notices   = get_transient( self::NOTICE_TRANSIENT );
        $notices   = is_array( $notices ) ? $notices : array();
        $notices[] = array( 'message' => $message, 'type' => $type );
        set_transient( self::NOTICE_TRANSIENT, $notices, DAY_IN_SECONDS );
    }

    public function render_admin_notices() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $notices = get_transient( self::NOTICE_TRANSIENT );
        if ( ! is_array( $notices ) ) {
            return;
        }

        delete_transient( self::NOTICE_TRANSIENT );
        foreach ( $notices as $notice ) {
            $class = 'notice notice-' . ( 'success' === $notice['type'] ? 'success' : 'error' );
            printf( '<div class="%1$s"><p>%2$s</p></div>', esc_attr( $class ), esc_html( $notice['message'] ) );
        }
    }
}

register_activation_hook( __FILE__, array( 'Spry_Simple_WP_Security', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Spry_Simple_WP_Security', 'deactivate' ) );
Spry_Simple_WP_Security::instance();
