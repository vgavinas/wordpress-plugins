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
        add_action( 'admin_post_wc_ont_save_category',   array( $this, 'handle_save_category' ) );
        add_action( 'admin_post_wc_ont_delete_category', array( $this, 'handle_delete_category' ) );
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
     * Read and sanitize the posted category value from the template save form.
     *
     * Lives here (rather than in the always-loaded admin page class) so that
     * category handling is entirely absent from the free build — this file is
     * stripped from the free distribution by the build process.
     */
    public static function get_posted_category() {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified by the calling save handler
        return isset( $_POST['category'] ) ? sanitize_text_field( wp_unslash( $_POST['category'] ) ) : '';
    }

    /**
     * Category field inside the template add/edit form.
     */
    public static function render_form_field( $template ) {
        $categories = self::get_categories();
        ?>
        <tr>
            <th><label for="ont-category"><?php esc_html_e( 'Category', 'pro-web-design-order-note-templates-for-woocommerce' ); ?></label></th>
            <td>
                <input id="ont-category" type="text" name="category" class="regular-text"
                       list="wc-ont-categories-list"
                       value="<?php echo esc_attr( isset( $template->category ) ? $template->category : '' ); ?>"
                       placeholder="<?php esc_attr_e( 'e.g. Shipping, Returns', 'pro-web-design-order-note-templates-for-woocommerce' ); ?>">
                <datalist id="wc-ont-categories-list">
                    <?php foreach ( $categories as $cat ) : ?>
                        <option value="<?php echo esc_attr( $cat ); ?>">
                    <?php endforeach; ?>
                </datalist>
                <p class="description"><?php esc_html_e( 'Type a new category or select existing.', 'pro-web-design-order-note-templates-for-woocommerce' ); ?></p>
            </td>
        </tr>
        <?php
    }

    /**
     * Render categories management tab content.
     */
    public function render_tab() {
        $categories = self::get_categories();
        $msg        = isset( $_GET['cat_msg'] ) ? sanitize_key( $_GET['cat_msg'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

        $messages = array(
            'renamed' => __( '✅ Category renamed.', 'pro-web-design-order-note-templates-for-woocommerce' ),
            'deleted' => __( '✅ Category deleted (templates kept, category removed).', 'pro-web-design-order-note-templates-for-woocommerce' ),
        );
        if ( $msg && isset( $messages[ $msg ] ) ) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $messages[ $msg ] ) . '</p></div>';
        }
        ?>
        <div class="wc-ont-panel">
            <div class="wc-ont-form-card">
                <h2>🏷️ <?php esc_html_e( 'Template Categories', 'pro-web-design-order-note-templates-for-woocommerce' ); ?></h2>
                <p><?php esc_html_e( 'Categories are created automatically when you assign them to templates. Here you can rename or delete existing categories.', 'pro-web-design-order-note-templates-for-woocommerce' ); ?></p>

                <?php if ( empty( $categories ) ) : ?>
                    <p><em><?php esc_html_e( 'No categories yet. Add a category when creating or editing a template.', 'pro-web-design-order-note-templates-for-woocommerce' ); ?></em></p>
                <?php else : ?>
                    <table class="wp-list-table widefat striped wc-ont-table wc-ont-cat-table">
                        <thead>
                            <tr>
                                <th class="wc-ont-cat-name"><?php esc_html_e( 'Category Name', 'pro-web-design-order-note-templates-for-woocommerce' ); ?></th>
                                <th class="wc-ont-cat-count"><?php esc_html_e( 'Templates', 'pro-web-design-order-note-templates-for-woocommerce' ); ?></th>
                                <th class="wc-ont-cat-actions"><?php esc_html_e( 'Actions', 'pro-web-design-order-note-templates-for-woocommerce' ); ?></th>
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
                                <td class="wc-ont-cat-name"><strong><?php echo esc_html( $cat ); ?></strong></td>
                                <td class="wc-ont-cat-count"><?php echo absint( $count ); ?></td>
                                <td class="wc-ont-cat-actions">
                                    <div class="wc-ont-cat-controls">
                                        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                                            <?php wp_nonce_field( 'wc_ont_save_category' ); ?>
                                            <input type="hidden" name="action" value="wc_ont_save_category">
                                            <input type="hidden" name="old_category" value="<?php echo esc_attr( $cat ); ?>">
                                            <label class="screen-reader-text" for="wc-ont-rename-<?php echo esc_attr( md5( $cat ) ); ?>">
                                                <?php esc_html_e( 'New category name', 'pro-web-design-order-note-templates-for-woocommerce' ); ?>
                                            </label>
                                            <input type="text" id="wc-ont-rename-<?php echo esc_attr( md5( $cat ) ); ?>"
                                                   name="new_category" value="<?php echo esc_attr( $cat ); ?>" required>
                                            <button type="submit" class="button button-small">
                                                <?php esc_html_e( 'Rename', 'pro-web-design-order-note-templates-for-woocommerce' ); ?>
                                            </button>
                                        </form>

                                        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                                            <?php wp_nonce_field( 'wc_ont_delete_category' ); ?>
                                            <input type="hidden" name="action" value="wc_ont_delete_category">
                                            <input type="hidden" name="category" value="<?php echo esc_attr( $cat ); ?>">
                                            <button type="submit" class="button button-small button-link-delete"
                                                    aria-label="<?php echo esc_attr( sprintf( /* translators: %s: category name */ __( 'Delete category %s', 'pro-web-design-order-note-templates-for-woocommerce' ), $cat ) ); ?>"
                                                    onclick="return confirm('<?php echo esc_js( sprintf( /* translators: %s: category name */ __( 'Remove the category "%s" from all templates?', 'pro-web-design-order-note-templates-for-woocommerce' ), $cat ) ); ?>')">
                                                <?php esc_html_e( 'Delete', 'pro-web-design-order-note-templates-for-woocommerce' ); ?>
                                            </button>
                                        </form>
                                    </div>
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
