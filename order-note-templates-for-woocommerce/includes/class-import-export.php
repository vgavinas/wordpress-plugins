<?php
/**
 * Import/Export templates — Pro feature.
 *
 * @package OrderNoteTemplates
 */

defined( 'ABSPATH' ) || exit;

class WC_ONT_Import_Export {

    public function __construct() {
        add_action( 'admin_post_wc_ont_export', array( $this, 'handle_export' ) );
        add_action( 'admin_post_wc_ont_import', array( $this, 'handle_import' ) );
    }

    /**
     * Export all templates as JSON file download.
     */
    public function handle_export() {
        check_admin_referer( 'wc_ont_export' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( 'No permission.' );
        }

        global $wpdb;
        $table = esc_sql( $wpdb->prefix . 'order_note_templates' );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $templates = $wpdb->get_results( "SELECT title, note_text, note_type, category, pdf_attachment, sort_order FROM {$table} ORDER BY sort_order, title", ARRAY_A );

        $export = array(
            'version'   => WC_ONT_VERSION,
            'exported'  => gmdate( 'Y-m-d H:i:s' ),
            'templates' => $templates,
        );

        $filename = 'order-note-templates-' . gmdate( 'Y-m-d' ) . '.json';

        header( 'Content-Type: application/json; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
        header( 'Pragma: no-cache' );
        header( 'Expires: 0' );

        echo wp_json_encode( $export, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE );
        exit;
    }

    /**
     * Import templates from uploaded JSON file.
     */
    public function handle_import() {
        check_admin_referer( 'wc_ont_import' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( 'No permission.' );
        }

        $redirect = add_query_arg( array( 'page' => 'wc-ont-templates', 'tab' => 'import-export' ), admin_url( 'admin.php' ) );

        if ( empty( $_FILES['import_file']['tmp_name'] ) ) {
            wp_safe_redirect( add_query_arg( 'import_msg', 'no_file', $redirect ) );
            exit;
        }

        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        $file = $_FILES['import_file']['tmp_name'];
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
        $content = file_get_contents( $file );

        if ( false === $content ) {
            wp_safe_redirect( add_query_arg( 'import_msg', 'read_error', $redirect ) );
            exit;
        }

        $data = json_decode( $content, true );

        if ( json_last_error() !== JSON_ERROR_NONE || empty( $data['templates'] ) || ! is_array( $data['templates'] ) ) {
            wp_safe_redirect( add_query_arg( 'import_msg', 'invalid', $redirect ) );
            exit;
        }

        $mode      = isset( $_POST['import_mode'] ) ? sanitize_key( $_POST['import_mode'] ) : 'add';
        $imported  = 0;

        global $wpdb;

        if ( 'replace' === $mode ) {
            $table = esc_sql( $wpdb->prefix . 'order_note_templates' );
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $wpdb->query( "TRUNCATE TABLE {$table}" );
        }

        foreach ( $data['templates'] as $t ) {
            if ( empty( $t['title'] ) || empty( $t['note_text'] ) ) {
                continue;
            }
            $note_type = in_array( $t['note_type'] ?? 'customer', array( 'customer', 'internal' ), true )
                ? $t['note_type']
                : 'customer';

            $row = array(
                'title'      => sanitize_text_field( $t['title'] ),
                'note_text'  => sanitize_textarea_field( $t['note_text'] ),
                'note_type'  => $note_type,
                'sort_order' => absint( $t['sort_order'] ?? 0 ),
            );

            /*
             * Files exported by 1.1.0 and earlier have no category or
             * pdf_attachment keys — fall back to empty so older backups
             * still import cleanly.
             */
            if ( wc_ont_column_exists( 'category' ) ) {
                $row['category'] = sanitize_text_field( $t['category'] ?? '' );
            }
            if ( wc_ont_column_exists( 'pdf_attachment' ) ) {
                $row['pdf_attachment'] = esc_url_raw( $t['pdf_attachment'] ?? '' );
            }

            $inserted = $wpdb->insert( $wpdb->prefix . 'order_note_templates', $row ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery

            if ( false !== $inserted ) {
                ++$imported;
            }
        }

        wp_safe_redirect( add_query_arg( array( 'import_msg' => 'success', 'import_count' => $imported ), $redirect ) );
        exit;
    }

    /**
     * Render Import/Export tab content.
     */
    public function render_tab() {
        $import_msg   = isset( $_GET['import_msg'] ) ? sanitize_key( $_GET['import_msg'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $import_count = isset( $_GET['import_count'] ) ? absint( $_GET['import_count'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

        $messages = array(
            'success'    => sprintf( /* translators: %d: number of imported templates */ __( '✅ Successfully imported %d templates.', 'order-note-templates-for-woocommerce' ), $import_count ),
            'no_file'    => __( '❌ No file selected.', 'order-note-templates-for-woocommerce' ),
            'read_error' => __( '❌ Could not read file.', 'order-note-templates-for-woocommerce' ),
            'invalid'    => __( '❌ Invalid JSON file. Please upload a valid export file.', 'order-note-templates-for-woocommerce' ),
        );

        if ( $import_msg && isset( $messages[ $import_msg ] ) ) {
            $type = ( 'success' === $import_msg ) ? 'success' : 'error';
            echo '<div class="notice notice-' . esc_attr( $type ) . ' is-dismissible"><p>' . esc_html( $messages[ $import_msg ] ) . '</p></div>';
        }
        ?>

        <div class="wc-ont-layout wc-ont-layout--even">

            <!-- Export -->
            <div class="wc-ont-form-card">
                <h2>📤 <?php esc_html_e( 'Export Templates', 'order-note-templates-for-woocommerce' ); ?></h2>
                <p><?php esc_html_e( 'Download all your templates as a JSON file. Use this to back up your templates or transfer them to another site.', 'order-note-templates-for-woocommerce' ); ?></p>
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                    <?php wp_nonce_field( 'wc_ont_export' ); ?>
                    <input type="hidden" name="action" value="wc_ont_export">
                    <button type="submit" class="button button-primary">
                        📥 <?php esc_html_e( 'Download Export File', 'order-note-templates-for-woocommerce' ); ?>
                    </button>
                </form>
            </div>

            <!-- Import -->
            <div class="wc-ont-form-card">
                <h2>📥 <?php esc_html_e( 'Import Templates', 'order-note-templates-for-woocommerce' ); ?></h2>
                <p><?php esc_html_e( 'Upload a JSON export file to import templates. Choose whether to add to existing templates or replace them.', 'order-note-templates-for-woocommerce' ); ?></p>
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
                    <?php wp_nonce_field( 'wc_ont_import' ); ?>
                    <input type="hidden" name="action" value="wc_ont_import">

                    <table class="form-table" role="presentation">
                        <tr>
                            <th><label for="import_file"><?php esc_html_e( 'JSON File', 'order-note-templates-for-woocommerce' ); ?></label></th>
                            <td><input type="file" id="import_file" name="import_file" accept=".json" required></td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e( 'Import Mode', 'order-note-templates-for-woocommerce' ); ?></th>
                            <td>
                                <label>
                                    <input type="radio" name="import_mode" value="add" checked>
                                    <?php esc_html_e( 'Add to existing templates', 'order-note-templates-for-woocommerce' ); ?>
                                </label><br>
                                <label>
                                    <input type="radio" name="import_mode" value="replace">
                                    <strong style="color:#b91c1c"><?php esc_html_e( 'Replace all templates (deletes existing!)', 'order-note-templates-for-woocommerce' ); ?></strong>
                                </label>
                            </td>
                        </tr>
                    </table>

                    <p class="submit">
                        <button type="submit" class="button button-primary"
                                onclick="return confirm('<?php esc_attr_e( 'Import templates? This cannot be undone if replacing.', 'order-note-templates-for-woocommerce' ); ?>')">
                            📤 <?php esc_html_e( 'Import Templates', 'order-note-templates-for-woocommerce' ); ?>
                        </button>
                    </p>
                </form>
            </div>

        </div>
        <?php
    }
}
