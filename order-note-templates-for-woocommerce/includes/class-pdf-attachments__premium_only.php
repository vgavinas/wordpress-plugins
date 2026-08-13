<?php
/**
 * PDF Attachments — Pro feature.
 *
 * A template can carry a PDF. When a note is inserted from that template and
 * saved, the PDF is linked inside the note and attached to the customer email.
 *
 * Flow:
 *   1. Admin picks a template and clicks Insert  -> JS calls wc_ont_mark_template
 *   2. Handler remembers the template in a short-lived, user-scoped transient
 *   3. Admin clicks Add  -> WooCommerce fires woocommerce_new_order_note_data
 *   4. We append the PDF link and flag the file for the outgoing email
 *
 * @package OrderNoteTemplates
 */

defined( 'ABSPATH' ) || exit;

class WC_ONT_PDF_Attachments {

    /** How long the "which template was used" hint survives, in seconds. */
    const HINT_TTL = 300;

    public function __construct() {
        add_action( 'wp_ajax_wc_ont_mark_template',    array( $this, 'ajax_mark_template' ) );
        add_filter( 'woocommerce_new_order_note_data', array( $this, 'store_pdf_in_note' ), 10, 2 );
        add_filter( 'woocommerce_email_attachments',   array( $this, 'attach_pdf_to_email' ), 10, 3 );
    }

    /* --------------------------------------------------------------------- */
    /* Transient keys - scoped per user so two admins on one order don't clash */
    /* --------------------------------------------------------------------- */

    private static function hint_key( $order_id ) {
        return 'wc_ont_tpl_' . get_current_user_id() . '_' . absint( $order_id );
    }

    private static function pending_key( $order_id ) {
        return 'wc_ont_pdf_' . absint( $order_id );
    }

    /* --------------------------------------------------------------------- */

    /**
     * Look up the PDF attached to a template.
     *
     * @return string URL, or empty string when there is none.
     */
    public static function get_pdf_url( $template_id ) {
        if ( ! function_exists( 'wc_ont_column_exists' ) || ! wc_ont_column_exists( 'pdf_attachment' ) ) {
            return '';
        }

        global $wpdb;
        $table = esc_sql( $wpdb->prefix . 'order_note_templates' );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter
        $url = $wpdb->get_var( $wpdb->prepare( "SELECT pdf_attachment FROM {$table} WHERE id = %d", absint( $template_id ) ) );

        return $url ? $url : '';
    }

    /**
     * Remember which template the admin just inserted, so that the note-saving
     * filter can find it a moment later.
     */
    public function ajax_mark_template() {
        check_ajax_referer( 'wc_ont_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( 'Forbidden', 403 );
        }

        $order_id    = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
        $template_id = isset( $_POST['template_id'] ) ? absint( $_POST['template_id'] ) : 0;

        if ( ! $order_id || ! $template_id ) {
            wp_send_json_error( 'Missing parameters', 400 );
        }

        set_transient( self::hint_key( $order_id ), $template_id, self::HINT_TTL );

        wp_send_json_success();
    }

    /**
     * Append the PDF link to a note created from a template.
     *
     * Note the signature: WooCommerce passes an array here, not an order -
     * array( 'order_id' => int, 'is_customer_note' => bool ).
     *
     * @param array $note_data Comment data about to be inserted.
     * @param array $args      Context supplied by WC_Order::add_order_note().
     * @return array
     */
    public function store_pdf_in_note( $note_data, $args ) {
        $order_id = 0;

        if ( is_array( $args ) && isset( $args['order_id'] ) ) {
            $order_id = absint( $args['order_id'] );
        } elseif ( is_object( $args ) && method_exists( $args, 'get_id' ) ) {
            // Defensive: guard against a differently shaped argument.
            $order_id = $args->get_id();
        }

        if ( ! $order_id ) {
            return $note_data;
        }

        $template_id = get_transient( self::hint_key( $order_id ) );
        if ( ! $template_id ) {
            return $note_data;
        }

        // One note, one use.
        delete_transient( self::hint_key( $order_id ) );

        $pdf_url = self::get_pdf_url( $template_id );
        if ( ! $pdf_url ) {
            return $note_data;
        }

        if ( isset( $note_data['comment_content'] ) ) {
            $note_data['comment_content'] .= "\n\n" . sprintf(
                /* translators: %s: URL of the attached PDF */
                __( 'Attached PDF: %s', 'pro-web-design-order-note-templates-for-woocommerce' ),
                $pdf_url
            );
        }

        // Hand the file over to the customer-note email, if one goes out.
        if ( is_array( $args ) && ! empty( $args['is_customer_note'] ) ) {
            set_transient( self::pending_key( $order_id ), $pdf_url, 60 );
        }

        return $note_data;
    }

    /**
     * Attach the PDF to the outgoing customer-note email.
     */
    public function attach_pdf_to_email( $attachments, $email_id, $object ) {
        if ( 'customer_note' !== $email_id ) {
            return $attachments;
        }

        $order_id = ( is_object( $object ) && method_exists( $object, 'get_id' ) ) ? $object->get_id() : 0;
        if ( ! $order_id ) {
            return $attachments;
        }

        $pdf_url = get_transient( self::pending_key( $order_id ) );
        if ( ! $pdf_url ) {
            return $attachments;
        }

        delete_transient( self::pending_key( $order_id ) );

        // Only local files inside the uploads directory can be attached.
        $upload_dir = wp_upload_dir();
        if ( empty( $upload_dir['baseurl'] ) || false === strpos( $pdf_url, $upload_dir['baseurl'] ) ) {
            return $attachments;
        }

        $local_path = realpath( str_replace( $upload_dir['baseurl'], $upload_dir['basedir'], $pdf_url ) );
        $base_real  = realpath( $upload_dir['basedir'] );

        // realpath() also guards against ../ escaping the uploads folder.
        if ( $local_path && $base_real
            && 0 === strpos( $local_path, $base_real )
            && is_readable( $local_path ) ) {
            $attachments[] = $local_path;
        }

        return $attachments;
    }

    /**
     * Handle the PDF upload attached to a template form submission.
     *
     * @return string Uploaded file URL, or empty string on failure.
     */
    public static function handle_upload() {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified by the calling save handler
        if ( empty( $_FILES['pdf_attachment']['name'] ) ) {
            return '';
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce verified by caller; wp_handle_upload() sanitises
        $file = $_FILES['pdf_attachment'];

        $check = wp_check_filetype( $file['name'], array( 'pdf' => 'application/pdf' ) );
        if ( 'pdf' !== $check['ext'] ) {
            return '';
        }

        if ( ! function_exists( 'wp_handle_upload' ) ) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }

        $upload = wp_handle_upload(
            $file,
            array(
                'test_form' => false,
                'mimes'     => array( 'pdf' => 'application/pdf' ),
            )
        );

        if ( isset( $upload['error'] ) || empty( $upload['url'] ) ) {
            return '';
        }

        return $upload['url'];
    }

    /**
     * PDF field inside the template add/edit form.
     */
    public static function render_form_field( $template ) {
        $current_pdf = isset( $template->pdf_attachment ) ? $template->pdf_attachment : '';
        ?>
        <tr>
            <th><label for="pdf_attachment"><?php esc_html_e( 'PDF Attachment', 'pro-web-design-order-note-templates-for-woocommerce' ); ?></label></th>
            <td>
                <?php if ( $current_pdf ) : ?>
                    <p>
                        <a href="<?php echo esc_url( $current_pdf ); ?>" target="_blank" rel="noopener">
                            &#128206; <?php echo esc_html( basename( $current_pdf ) ); ?>
                        </a>
                        &nbsp;
                        <label>
                            <input type="checkbox" name="remove_pdf" value="1">
                            <?php esc_html_e( 'Remove', 'pro-web-design-order-note-templates-for-woocommerce' ); ?>
                        </label>
                    </p>
                <?php endif; ?>
                <input type="file" id="pdf_attachment" name="pdf_attachment" accept="application/pdf">
                <p class="description">
                    <?php esc_html_e( 'Attached to the customer email when a note is added from this template.', 'pro-web-design-order-note-templates-for-woocommerce' ); ?>
                </p>
            </td>
        </tr>
        <?php
    }
}
