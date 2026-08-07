<?php
/**
 * Auto-insert template on order status change — Pro feature.
 *
 * Lets admins map a note template to a WooCommerce order status transition.
 * When an order moves to that status, the template is automatically added
 * as an order note (customer or internal, per template settings).
 *
 * @package OrderNoteTemplates
 */

defined( 'ABSPATH' ) || exit;

class WC_ONT_Auto_Insert {

    const OPTION_KEY = 'wc_ont_auto_insert_rules';

    public function __construct() {
        add_action( 'woocommerce_order_status_changed', array( $this, 'on_status_change' ), 10, 4 );
        add_action( 'admin_post_wc_ont_save_auto_rules', array( $this, 'handle_save' ) );
    }

    /**
     * Return saved rules.
     * Each rule: [ 'status' => 'processing', 'template_id' => 5 ]
     */
    public static function get_rules() {
        $rules = get_option( self::OPTION_KEY, array() );
        return is_array( $rules ) ? $rules : array();
    }

    /**
     * Fire when order status changes — add note if a rule matches.
     */
    public function on_status_change( $order_id, $old_status, $new_status, $order ) {
        $rules = self::get_rules();
        if ( empty( $rules ) ) return;

        foreach ( $rules as $rule ) {
            if ( empty( $rule['status'] ) || empty( $rule['template_id'] ) ) continue;
            if ( $rule['status'] !== $new_status ) continue;

            global $wpdb;
            $table    = esc_sql( $wpdb->prefix . 'order_note_templates' );
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $template = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", absint( $rule['template_id'] ) ) );
            if ( ! $template ) continue;

            $note_text = $this->replace_vars( $template->note_text, $order );
            $is_customer = ( 'customer' === $template->note_type );

            $order->add_order_note( $note_text, $is_customer ? 1 : 0, false );
        }
    }

    /**
     * Replace variables in template text.
     */
    private function replace_vars( $text, $order ) {
        $next_payment = '';
        $start_date   = '';

        if ( $order instanceof WC_Subscription ) {
            $next = $order->get_date( 'next_payment' );
            if ( $next ) $next_payment = date_i18n( get_option( 'date_format' ), strtotime( $next ) );
            $start = $order->get_date( 'start' );
            if ( $start ) $start_date = date_i18n( get_option( 'date_format' ), strtotime( $start ) );
        }

        return str_replace(
            array( '{order_id}', '{customer_name}', '{billing_email}', '{total}', '{next_payment}', '{start_date}' ),
            array(
                $order->get_id(),
                $order->get_formatted_billing_full_name(),
                $order->get_billing_email(),
                $order->get_formatted_order_total(),
                $next_payment,
                $start_date,
            ),
            $text
        );
    }

    /**
     * Save rules from settings page.
     */
    public function handle_save() {
        check_admin_referer( 'wc_ont_save_auto_rules' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( 'No permission.' );
        }

        $statuses    = isset( $_POST['auto_status'] ) ? array_map( 'sanitize_key', wp_unslash( $_POST['auto_status'] ) ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        $template_ids = isset( $_POST['auto_template'] ) ? array_map( 'absint', wp_unslash( $_POST['auto_template'] ) ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

        $rules = array();
        foreach ( $statuses as $i => $status ) {
            if ( $status && ! empty( $template_ids[ $i ] ) ) {
                $rules[] = array(
                    'status'      => $status,
                    'template_id' => $template_ids[ $i ],
                );
            }
        }

        update_option( self::OPTION_KEY, $rules );

        wp_safe_redirect( add_query_arg( array( 'page' => 'wc-ont-templates', 'tab' => 'auto-insert', 'saved' => '1' ), admin_url( 'admin.php' ) ) );
        exit;
    }

    /**
     * Render Auto-insert settings tab.
     */
    public function render_tab() {
        $rules     = self::get_rules();
        $statuses  = wc_get_order_statuses();
        $saved     = isset( $_GET['saved'] ) ? absint( $_GET['saved'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

        // Get all templates for select
        global $wpdb;
        $table     = esc_sql( $wpdb->prefix . 'order_note_templates' );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $templates = $wpdb->get_results( "SELECT id, title, note_type FROM {$table} ORDER BY note_type, sort_order, title" );

        if ( $saved ) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( '✅ Auto-insert rules saved.', 'order-note-templates-for-woocommerce' ) . '</p></div>';
        }
        ?>
        <div class="wc-ont-form-card" style="max-width:800px">
            <h2>⚡ <?php esc_html_e( 'Auto-insert on Status Change', 'order-note-templates-for-woocommerce' ); ?></h2>
            <p><?php esc_html_e( 'Automatically add a note to an order when it moves to a specific status. Add as many rules as you need.', 'order-note-templates-for-woocommerce' ); ?></p>

            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <?php wp_nonce_field( 'wc_ont_save_auto_rules' ); ?>
                <input type="hidden" name="action" value="wc_ont_save_auto_rules">

                <table class="wp-list-table widefat fixed" id="wc-ont-auto-rules">
                    <thead>
                        <tr>
                            <th><?php esc_html_e( 'When order moves to status', 'order-note-templates-for-woocommerce' ); ?></th>
                            <th><?php esc_html_e( 'Insert this template', 'order-note-templates-for-woocommerce' ); ?></th>
                            <th style="width:60px"><?php esc_html_e( 'Remove', 'order-note-templates-for-woocommerce' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php
                    // Render existing rules + one empty row
                    $rows = $rules;
                    $rows[] = array( 'status' => '', 'template_id' => 0 ); // empty row

                    foreach ( $rows as $row ) :
                    ?>
                        <tr class="wc-ont-rule-row">
                            <td>
                                <select name="auto_status[]" style="width:100%">
                                    <option value=""><?php esc_html_e( '— select status —', 'order-note-templates-for-woocommerce' ); ?></option>
                                    <?php foreach ( $statuses as $slug => $label ) :
                                        // WC prefixes statuses with 'wc-' in get_order_statuses
                                        $val = str_replace( 'wc-', '', $slug );
                                        ?>
                                        <option value="<?php echo esc_attr( $val ); ?>" <?php selected( $row['status'], $val ); ?>>
                                            <?php echo esc_html( $label ); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                            <td>
                                <select name="auto_template[]" style="width:100%">
                                    <option value=""><?php esc_html_e( '— select template —', 'order-note-templates-for-woocommerce' ); ?></option>
                                    <?php foreach ( $templates as $t ) : ?>
                                        <option value="<?php echo absint( $t->id ); ?>"
                                            <?php selected( absint( $row['template_id'] ), $t->id ); ?>>
                                            <?php echo esc_html( ( 'internal' === $t->note_type ? '🔒 ' : '👤 ' ) . $t->title ); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                            <td style="text-align:center">
                                <button type="button" class="button button-small wc-ont-remove-rule"
                                        title="<?php esc_attr_e( 'Remove rule', 'order-note-templates-for-woocommerce' ); ?>">✕</button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>

                <p style="margin-top:12px">
                    <button type="button" id="wc-ont-add-rule" class="button">
                        ➕ <?php esc_html_e( 'Add Rule', 'order-note-templates-for-woocommerce' ); ?>
                    </button>
                </p>

                <p class="submit">
                    <button type="submit" class="button button-primary">
                        💾 <?php esc_html_e( 'Save Rules', 'order-note-templates-for-woocommerce' ); ?>
                    </button>
                </p>
            </form>
        </div>

        <script>
        ( function( $ ) {
            // Clone last row on Add Rule click
            $( '#wc-ont-add-rule' ).on( 'click', function() {
                var $last = $( '#wc-ont-auto-rules tbody tr:last' ).clone();
                $last.find( 'select' ).val( '' );
                $( '#wc-ont-auto-rules tbody' ).append( $last );
            } );
            // Remove row
            $( '#wc-ont-auto-rules' ).on( 'click', '.wc-ont-remove-rule', function() {
                var $rows = $( '#wc-ont-auto-rules tbody tr' );
                if ( $rows.length > 1 ) {
                    $( this ).closest( 'tr' ).remove();
                } else {
                    $( this ).closest( 'tr' ).find( 'select' ).val( '' );
                }
            } );
        } )( jQuery );
        </script>
        <?php
    }
}
