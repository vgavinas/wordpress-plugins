<?php
defined( 'ABSPATH' ) || exit;

class WC_ONT_Admin_Page {

    public function __construct() {
        add_action( 'admin_menu',                        array( $this, 'register_menu' ) );
        add_action( 'admin_post_wc_ont_save_template',   array( $this, 'handle_save' ) );
        add_action( 'admin_post_wc_ont_delete_template', array( $this, 'handle_delete' ) );
        add_action( 'admin_enqueue_scripts',             array( $this, 'enqueue' ) );
    }

    public function register_menu() {
        add_submenu_page(
            'woocommerce',
            __( 'Order Note Templates', 'order-note-templates-for-woocommerce' ),
            __( 'Order Note Templates', 'order-note-templates-for-woocommerce' ),
            'manage_woocommerce',
            'wc-ont-templates',
            array( $this, 'render_page' )
        );
    }

    public function enqueue( $hook ) {
        $screen    = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
        $screen_id = $screen ? $screen->id : '';

        $is_settings     = false !== strpos( $hook, 'wc-ont-templates' );
        $is_order_screen = in_array( $hook, array( 'post.php', 'post-new.php' ), true )
            || false !== strpos( $screen_id, 'wc-orders' )
            || false !== strpos( $screen_id, 'wc-subscriptions' );

        if ( ! $is_settings && ! $is_order_screen ) {
            return;
        }

        wp_enqueue_style( 'wc-ont-admin', WC_ONT_URL . 'assets/admin.css', array(), WC_ONT_VERSION );
        wp_enqueue_script( 'wc-ont-admin', WC_ONT_URL . 'assets/admin.js', array( 'jquery' ), WC_ONT_VERSION, true );
        wp_localize_script( 'wc-ont-admin', 'wcOnt', array(
            'ajax_url'  => admin_url( 'admin-ajax.php' ),
            'nonce'     => wp_create_nonce( 'wc_ont_nonce' ),
            'templates' => $this->get_templates_for_js(),
        ) );
    }

    private function get_templates_for_js() {
        global $wpdb;
        $table = esc_sql( $wpdb->prefix . 'order_note_templates' );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        return $wpdb->get_results( "SELECT id, title, note_text, note_type FROM {$table} ORDER BY note_type, sort_order, title", ARRAY_A );
    }

    private function get_all_templates() {
        global $wpdb;
        $table = esc_sql( $wpdb->prefix . 'order_note_templates' );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        return $wpdb->get_results( "SELECT * FROM {$table} ORDER BY note_type, sort_order, title" );
    }

    private function get_template( $id ) {
        global $wpdb;
        $table = esc_sql( $wpdb->prefix . 'order_note_templates' );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ) );
    }

    public function handle_save() {
        check_admin_referer( 'wc_ont_save_template' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( 'No permission.' );
        }

        $id         = isset( $_POST['template_id'] ) ? absint( $_POST['template_id'] ) : 0;
        $title      = isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( $_POST['title'] ) ) : '';
        $note_text  = isset( $_POST['note_text'] ) ? sanitize_textarea_field( wp_unslash( $_POST['note_text'] ) ) : '';
        $note_type_raw = isset( $_POST['note_type'] ) ? sanitize_key( wp_unslash( $_POST['note_type'] ) ) : '';
        $note_type  = in_array( $note_type_raw, array( 'customer', 'internal' ), true ) ? $note_type_raw : 'customer';
        $sort_order = isset( $_POST['sort_order'] ) ? absint( $_POST['sort_order'] ) : 0;

        if ( empty( $title ) || empty( $note_text ) ) {
            wp_safe_redirect( add_query_arg( array( 'page' => 'wc-ont-templates', 'message' => 'empty' ), admin_url( 'admin.php' ) ) );
            exit;
        }

        global $wpdb;
        $table = esc_sql( $wpdb->prefix . 'order_note_templates' );
        $data  = array(
            'title'      => $title,
            'note_text'  => $note_text,
            'note_type'  => $note_type,
            'sort_order' => $sort_order,
        );

        if ( $id ) {
            $wpdb->update( $table, $data, array( 'id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
            $msg = 'updated';
        } else {
            $wpdb->insert( $table, $data ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
            $msg = 'added';
        }

        wp_safe_redirect( add_query_arg( array( 'page' => 'wc-ont-templates', 'message' => $msg ), admin_url( 'admin.php' ) ) );
        exit;
    }

    public function handle_delete() {
        $id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
        check_admin_referer( 'wc_ont_delete_' . $id );
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( 'No permission.' );
        }
        global $wpdb;
        $wpdb->delete( $wpdb->prefix . 'order_note_templates', array( 'id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        wp_safe_redirect( add_query_arg( array( 'page' => 'wc-ont-templates', 'message' => 'deleted' ), admin_url( 'admin.php' ) ) );
        exit;
    }

    public function render_page() {
        $editing = null;
        $edit_id = isset( $_GET['edit'] ) ? absint( $_GET['edit'] ) : 0;
        if ( $edit_id ) {
            check_admin_referer( 'wc_ont_edit_' . $edit_id, '_wpnonce_edit' );
            $editing = $this->get_template( $edit_id );
        }

        $messages = array(
            'added'   => array( 'success', __( 'Template added.',   'order-note-templates-for-woocommerce' ) ),
            'updated' => array( 'success', __( 'Template updated.', 'order-note-templates-for-woocommerce' ) ),
            'deleted' => array( 'success', __( 'Template deleted.', 'order-note-templates-for-woocommerce' ) ),
            'empty'   => array( 'error',   __( 'Title and text cannot be empty.', 'order-note-templates-for-woocommerce' ) ),
        );
        $msg_key = isset( $_GET['message'] ) ? sanitize_key( $_GET['message'] ) : '';
        ?>
        <div class="wrap wc-ont-wrap">
            <h1 class="wp-heading-inline">📝 <?php esc_html_e( 'Order Note Templates', 'order-note-templates-for-woocommerce' ); ?></h1>
            <hr class="wp-header-end">

            <?php if ( isset( $messages[ $msg_key ] ) ) :
                list( $type, $text ) = $messages[ $msg_key ]; ?>
                <div class="notice notice-<?php echo esc_attr( $type ); ?> is-dismissible"><p><?php echo esc_html( $text ); ?></p></div>
            <?php endif; ?>

            <div class="wc-ont-layout">

                <!-- Form -->
                <div class="wc-ont-form-card">
                    <h2><?php echo $editing ? esc_html__( 'Edit Template', 'order-note-templates-for-woocommerce' ) : esc_html__( 'Add Template', 'order-note-templates-for-woocommerce' ); ?></h2>
                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                        <?php wp_nonce_field( 'wc_ont_save_template' ); ?>
                        <input type="hidden" name="action" value="wc_ont_save_template">
                        <input type="hidden" name="template_id" value="<?php echo $editing ? absint( $editing->id ) : 0; ?>">

                        <table class="form-table" role="presentation">
                            <tr>
                                <th><label for="ont-title"><?php esc_html_e( 'Template Name', 'order-note-templates-for-woocommerce' ); ?></label></th>
                                <td><input id="ont-title" type="text" name="title" class="regular-text"
                                           value="<?php echo esc_attr( isset( $editing->title ) ? $editing->title : '' ); ?>"
                                           placeholder="<?php esc_attr_e( 'e.g. Order Shipped', 'order-note-templates-for-woocommerce' ); ?>" required></td>
                            </tr>
                            <tr>
                                <th><label for="ont-type"><?php esc_html_e( 'Note Type', 'order-note-templates-for-woocommerce' ); ?></label></th>
                                <td>
                                    <select id="ont-type" name="note_type">
                                        <option value="customer" <?php selected( isset( $editing->note_type ) ? $editing->note_type : 'customer', 'customer' ); ?>>👤 <?php esc_html_e( 'Customer note (customer can see this)', 'order-note-templates-for-woocommerce' ); ?></option>
                                        <option value="internal" <?php selected( isset( $editing->note_type ) ? $editing->note_type : '', 'internal' ); ?>>🔒 <?php esc_html_e( 'Private note (staff only)', 'order-note-templates-for-woocommerce' ); ?></option>
                                    </select>
                                </td>
                            </tr>
                            <tr>
                                <th><label for="ont-text"><?php esc_html_e( 'Note Text', 'order-note-templates-for-woocommerce' ); ?></label></th>
                                <td>
                                    <textarea id="ont-text" name="note_text" rows="5" class="large-text"
                                              placeholder="<?php esc_attr_e( 'Text... Use {order_id}, {customer_name}, {billing_email}', 'order-note-templates-for-woocommerce' ); ?>" required><?php echo esc_textarea( isset( $editing->note_text ) ? $editing->note_text : '' ); ?></textarea>
                                    <p class="description">
                                        <?php esc_html_e( 'Available variables:', 'order-note-templates-for-woocommerce' ); ?>
                                        <code>{order_id}</code>,
                                        <code>{subscription_id}</code>,
                                        <code>{customer_name}</code>,
                                        <code>{billing_email}</code>,
                                        <code>{total}</code>,
                                        <code>{next_payment}</code>,
                                        <code>{start_date}</code>
                                    </p>
                                </td>
                            </tr>
                            <tr>
                                <th><label for="ont-order"><?php esc_html_e( 'Sort Order', 'order-note-templates-for-woocommerce' ); ?></label></th>
                                <td><input id="ont-order" type="number" name="sort_order" class="small-text"
                                           value="<?php echo absint( isset( $editing->sort_order ) ? $editing->sort_order : 0 ); ?>" min="0"></td>
                            </tr>
                        </table>

                        <p class="submit">
                            <button type="submit" class="button button-primary">
                                <?php echo $editing ? esc_html__( '💾 Save Changes', 'order-note-templates-for-woocommerce' ) : esc_html__( '➕ Add Template', 'order-note-templates-for-woocommerce' ); ?>
                            </button>
                            <?php if ( $editing ) : ?>
                                <a href="<?php echo esc_url( admin_url( 'admin.php?page=wc-ont-templates' ) ); ?>"
                                   class="button button-secondary"><?php esc_html_e( 'Cancel', 'order-note-templates-for-woocommerce' ); ?></a>
                            <?php endif; ?>
                        </p>
                    </form>
                </div>

                <!-- List -->
                <div class="wc-ont-list-card">
                    <h2><?php esc_html_e( 'All Templates', 'order-note-templates-for-woocommerce' ); ?></h2>
                    <?php $templates = $this->get_all_templates(); ?>
                    <?php if ( empty( $templates ) ) : ?>
                        <p><?php esc_html_e( 'No templates yet.', 'order-note-templates-for-woocommerce' ); ?></p>
                    <?php else : ?>
                        <table class="wp-list-table widefat fixed striped wc-ont-table">
                            <thead>
                                <tr>
                                    <th style="width:30px">#</th>
                                    <th><?php esc_html_e( 'Name', 'order-note-templates-for-woocommerce' ); ?></th>
                                    <th style="width:110px"><?php esc_html_e( 'Type', 'order-note-templates-for-woocommerce' ); ?></th>
                                    <th><?php esc_html_e( 'Text', 'order-note-templates-for-woocommerce' ); ?></th>
                                    <th style="width:120px"><?php esc_html_e( 'Actions', 'order-note-templates-for-woocommerce' ); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ( $templates as $t ) : ?>
                                <tr>
                                    <td><?php echo absint( $t->sort_order ); ?></td>
                                    <td><strong><?php echo esc_html( $t->title ); ?></strong></td>
                                    <td>
                                        <?php if ( 'customer' === $t->note_type ) : ?>
                                            <span class="wc-ont-badge wc-ont-badge--customer">👤 <?php esc_html_e( 'Customer', 'order-note-templates-for-woocommerce' ); ?></span>
                                        <?php else : ?>
                                            <span class="wc-ont-badge wc-ont-badge--internal">🔒 <?php esc_html_e( 'Private', 'order-note-templates-for-woocommerce' ); ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="wc-ont-preview"><?php echo esc_html( mb_strimwidth( $t->note_text, 0, 80, '…' ) ); ?></td>
                                    <td>
                                        <a href="<?php echo esc_url( wp_nonce_url( add_query_arg( array( 'page' => 'wc-ont-templates', 'edit' => $t->id ), admin_url( 'admin.php' ) ), 'wc_ont_edit_' . $t->id, '_wpnonce_edit' ) ); ?>"
                                           class="button button-small">✏️ <?php esc_html_e( 'Edit', 'order-note-templates-for-woocommerce' ); ?></a>

                                        <a href="<?php echo esc_url( wp_nonce_url(
                                            add_query_arg( array( 'action' => 'wc_ont_delete_template', 'id' => $t->id ), admin_url( 'admin-post.php' ) ),
                                            'wc_ont_delete_' . $t->id
                                        ) ); ?>"
                                           class="button button-small button-link-delete"
                                           onclick="return confirm('<?php echo esc_js( sprintf( /* translators: %s: template name */ __( 'Delete template "%s"?', 'order-note-templates-for-woocommerce' ), $t->title ) ); ?>')">🗑️</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>

            </div><!-- .wc-ont-layout -->
        </div>
        <?php
    }
}

new WC_ONT_Admin_Page();
