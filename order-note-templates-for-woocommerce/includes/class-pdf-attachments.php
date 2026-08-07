<?php
/**
 * PDF Attachments — Pro feature.
 *
 * Allows attaching a PDF file to a template. When an order note is added
 * using that template, the PDF is:
 *  1. Linked in the note text automatically.
 *  2. Attached to the customer note email (when note type = customer).
 *
 * @package OrderNoteTemplates
 */

defined( 'ABSPATH' ) || exit;

class WC_ONT_PDF_Attachments {

    public function __construct() {
        add_action( 'plugins_loaded',            array( $this, 'maybe_add_column' ) );
        add_filter( 'woocommerce_new_order_note_data', array( $this, 'store_pdf_in_note' ), 10, 2 );
        add_filter( 'woocommerce_email_attachments', array( $this, 'attach_pdf_to_email' ), 10, 3 );
    }

    /**
     * Add pdf_attachment column if it doesn't exist.
     */
    public function maybe_add_column() {
        global $wpdb;
        $table = $wpdb->prefix . 'order_note_templates';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter
        $col = $wpdb->get_results( "SHOW COLUMNS FROM {$table} LIKE 'pdf_attachment'" );
        if ( empty( $col ) ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter
            $wpdb->query( "ALTER TABLE {$table} ADD COLUMN pdf_attachment VARCHAR(500) NOT NULL DEFAULT '' AFTER sort_order" );
        }
    }

    /**
     * Get PDF attachment URL for a given template ID.
     */
    public static function get_pdf_url( $template_id ) {
        global $wpdb;
        $table = esc_sql( $wpdb->prefix . 'order_note_templates' );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        return $wpdb->get_var( $wpdb->prepare( "SELECT pdf_attachment FROM {$table} WHERE id = %d", absint( $template_id ) ) );
    }

    /**
     * When a customer note is added, check if it came from a template with a PDF.
     * Store the template ID in order meta for email attachment.
     */
    public function store_pdf_in_note( $note_data, $order ) {
        // We pass template_id via a transient keyed to order ID + timestamp
        $template_id = get_transient( 'wc_ont_note_template_' . $order->get_id() );
        if ( ! $template_id ) return $note_data;

        $pdf_url = self::get_pdf_url( $template_id );
        if ( ! $pdf_url ) return $note_data;

        // Append PDF link to note content
        $note_data['comment_content'] .= "\n\n📎 PDF: " . $pdf_url;

        // Store for email attachment
        set_transient( 'wc_ont_pdf_attach_' . $order->get_id(), $pdf_url, 60 );

        return $note_data;
    }

    /**
     * Attach PDF to customer note email.
     */
    public function attach_pdf_to_email( $attachments, $email_id, $object ) {
        if ( 'customer_note' !== $email_id ) return $attachments;

        $order_id = is_object( $object ) && method_exists( $object, 'get_id' ) ? $object->get_id() : 0;
        if ( ! $order_id ) return $attachments;

        $pdf_url = get_transient( 'wc_ont_pdf_attach_' . $order_id );
        if ( ! $pdf_url ) return $attachments;

        // Only attach local files (not remote URLs)
        $upload_dir = wp_upload_dir();
        $base_url   = $upload_dir['baseurl'];
        $base_dir   = $upload_dir['basedir'];

        if ( false !== strpos( $pdf_url, $base_url ) ) {
            $local_path = str_replace( $base_url, $base_dir, $pdf_url );
            if ( file_exists( $local_path ) ) {
                $attachments[] = $local_path;
            }
        }

        delete_transient( 'wc_ont_pdf_attach_' . $order_id );

        return $attachments;
    }

    /**
     * Set transient so we know which template was used for the current note.
     * Called from JS/AJAX when user clicks Insert.
     */
    public static function set_note_template( $order_id, $template_id ) {
        set_transient( 'wc_ont_note_template_' . absint( $order_id ), absint( $template_id ), 300 );
    }

    /**
     * Handle PDF upload for a template.
     * Called from admin_page save handler.
     */
    public static function handle_upload( $template_id ) {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce already verified in handle_save()
        if ( empty( $_FILES['pdf_attachment']['name'] ) ) {
            return '';
        }

        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce verified by caller
        $file = $_FILES['pdf_attachment'];

        // Validate file type
        $file_type = wp_check_filetype( $file['name'] );
        if ( 'pdf' !== $file_type['ext'] ) {
            return '';
        }

        if ( ! function_exists( 'wp_handle_upload' ) ) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }

        $upload = wp_handle_upload( $file, array( 'test_form' => false ) );

        if ( isset( $upload['error'] ) || ! isset( $upload['url'] ) ) {
            return '';
        }

        return $upload['url'];
    }

    /**
     * Render PDF attachment field in template form.
     */
    public static function render_form_field( $template ) {
        $current_pdf = isset( $template->pdf_attachment ) ? $template->pdf_attachment : '';
        ?>
        <tr>
            <th><label for="pdf_attachment"><?php esc_html_e( 'PDF Attachment', 'order-note-templates-for-woocommerce' ); ?></label></th>
            <td>
                <?php if ( $current_pdf ) : ?>
                    <p>
                        <a href="<?php echo esc_url( $current_pdf ); ?>" target="_blank">
                            📎 <?php echo esc_html( basename( $current_pdf ) ); ?>
                        </a>
                        &nbsp;
                        <label>
                            <input type="checkbox" name="remove_pdf" value="1">
                            <?php esc_html_e( 'Remove', 'order-note-templates-for-woocommerce' ); ?>
                        </label>
                    </p>
                <?php endif; ?>
                <input type="file" id="pdf_attachment" name="pdf_attachment" accept=".pdf">
                <p class="description">
                    <?php esc_html_e( 'Upload a PDF to attach to the customer note email when this template is used. Max 10MB.', 'order-note-templates-for-woocommerce' ); ?>
                </p>
            </td>
        </tr>
        <?php
    }
}
