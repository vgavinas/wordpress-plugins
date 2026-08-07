<?php
/**
 * Template Categories — Pro feature.
 *
 * Adds a `category` column to the templates table and
 * lets users group templates by category in the selector dropdown.
 *
 * @package OrderNoteTemplates
 */

defined( 'ABSPATH' ) || exit;

class WC_ONT_Categories {

    public function __construct() {
        add_action( 'plugins_loaded',                    array( $this, 'maybe_add_column' ) );
        add_action( 'admin_post_wc_ont_save_category',   array( $this, 'handle_save_category' ) );
        add_action( 'admin_post_wc_ont_delete_category', array( $this, 'handle_delete_category' ) );
    }

    /**
     * Add category column if it doesn't exist yet.
     */
    public function maybe_add_column() {
        global $wpdb;
        $table = $wpdb->prefix . 'order_note_templates';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter
        $col = $wpdb->get_results( "SHOW COLUMNS FROM {$table} LIKE 'category'" );
        if ( empty( $col ) ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter
            $wpdb->query( "ALTER TABLE {$table} ADD COLUMN category VARCHAR(100) NOT NULL DEFAULT '' AFTER note_type" );
        }
    }

    /**
     * Get all unique categories.
     */
    public static function get_categories() {
        global $wpdb;
        $table = esc_sql( $wpdb->prefix . 'order_note_templates' );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $rows = $wpdb->get_col( "SELECT DISTINCT category FROM {$table} WHERE category != '' ORDER BY category" );
        return $rows ? $rows : array();
    }

    /**
     * Render categories management tab content.
     */
    public function render_tab() {
        $categories = self::get_categories();
        $msg        = isset( $_GET['cat_msg'] ) ? sanitize_key( $_GET['cat_msg'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

        $messages = array(
            'renamed' => __( '✅ Category renamed.', 'order-note-templates-for-woocommerce' ),
            'deleted' => __( '✅ Category deleted (templates kept, category removed).', 'order-note-templates-for-woocommerce' ),
        );
        if ( $msg && isset( $messages[ $msg ] ) ) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $messages[ $msg ] ) . '</p></div>';
        }
        ?>
        <div class="wc-ont-layout">
            <div class="wc-ont-form-card">
                <h2>🏷️ <?php esc_html_e( 'Template Categories', 'order-note-templates-for-woocommerce' ); ?></h2>
                <p><?php esc_html_e( 'Categories are created automatically when you assign them to templates. Here you can rename or delete existing categories.', 'order-note-templates-for-woocommerce' ); ?></p>

                <?php if ( empty( $categories ) ) : ?>
                    <p><em><?php esc_html_e( 'No categories yet. Add a category when creating or editing a template.', 'order-note-templates-for-woocommerce' ); ?></em></p>
                <?php else : ?>
                    <table class="wp-list-table widefat fixed striped wc-ont-table">
                        <thead>
                            <tr>
                                <th><?php esc_html_e( 'Category Name', 'order-note-templates-for-woocommerce' ); ?></th>
                                <th style="width:80px"><?php esc_html_e( 'Templates', 'order-note-templates-for-woocommerce' ); ?></th>
                                <th style="width:200px"><?php esc_html_e( 'Actions', 'order-note-templates-for-woocommerce' ); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ( $categories as $cat ) :
                            global $wpdb;
                            $table = esc_sql( $wpdb->prefix . 'order_note_templates' );
                            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                            $count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE category = %s", $cat ) );
                            ?>
                            <tr>
                                <td><strong><?php echo esc_html( $cat ); ?></strong></td>
                                <td><?php echo absint( $count ); ?></td>
                                <td>
                                    <!-- Rename inline form -->
                                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-flex;gap:4px">
                                        <?php wp_nonce_field( 'wc_ont_save_category' ); ?>
                                        <input type="hidden" name="action" value="wc_ont_save_category">
                                        <input type="hidden" name="old_category" value="<?php echo esc_attr( $cat ); ?>">
                                        <input type="text" name="new_category" value="<?php echo esc_attr( $cat ); ?>"
                                               class="regular-text" style="width:140px" required>
                                        <button type="submit" class="button button-small">✏️ <?php esc_html_e( 'Rename', 'order-note-templates-for-woocommerce' ); ?></button>
                                    </form>

                                    <!-- Delete form -->
                                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline">
                                        <?php wp_nonce_field( 'wc_ont_delete_category' ); ?>
                                        <input type="hidden" name="action" value="wc_ont_delete_category">
                                        <input type="hidden" name="category" value="<?php echo esc_attr( $cat ); ?>">
                                        <button type="submit" class="button button-small button-link-delete"
                                                onclick="return confirm('<?php esc_attr_e( 'Remove this category from all templates?', 'order-note-templates-for-woocommerce' ); ?>')">
                                            🗑️
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    public function handle_save_category() {
        check_admin_referer( 'wc_ont_save_category' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( 'No permission.' );
        }

        $old = isset( $_POST['old_category'] ) ? sanitize_text_field( wp_unslash( $_POST['old_category'] ) ) : '';
        $new = isset( $_POST['new_category'] ) ? sanitize_text_field( wp_unslash( $_POST['new_category'] ) ) : '';

        if ( $old && $new && $old !== $new ) {
            global $wpdb;
            $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
                $wpdb->prefix . 'order_note_templates',
                array( 'category' => $new ),
                array( 'category' => $old )
            );
        }

        wp_safe_redirect( add_query_arg( array( 'page' => 'wc-ont-templates', 'tab' => 'categories', 'cat_msg' => 'renamed' ), admin_url( 'admin.php' ) ) );
        exit;
    }

    public function handle_delete_category() {
        check_admin_referer( 'wc_ont_delete_category' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( 'No permission.' );
        }

        $cat = isset( $_POST['category'] ) ? sanitize_text_field( wp_unslash( $_POST['category'] ) ) : '';

        if ( $cat ) {
            global $wpdb;
            $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
                $wpdb->prefix . 'order_note_templates',
                array( 'category' => '' ),
                array( 'category' => $cat )
            );
        }

        wp_safe_redirect( add_query_arg( array( 'page' => 'wc-ont-templates', 'tab' => 'categories', 'cat_msg' => 'deleted' ), admin_url( 'admin.php' ) ) );
        exit;
    }
}
