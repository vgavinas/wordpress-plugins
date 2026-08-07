<?php
defined( 'ABSPATH' ) || exit;

class WC_ONT_Admin_Page {

    public function __construct() {
        add_action( 'admin_menu',                        [ $this, 'register_menu' ] );
        add_action( 'admin_post_wc_ont_save_template',   [ $this, 'handle_save' ] );
        add_action( 'admin_post_wc_ont_delete_template', [ $this, 'handle_delete' ] );
        add_action( 'admin_enqueue_scripts',             [ $this, 'enqueue' ] );
    }

    public function register_menu() {
        add_submenu_page(
            'woocommerce',
            __( 'Order Note Templates', 'wc-ont' ),
            __( 'Order Note Templates', 'wc-ont' ),
            'manage_woocommerce',
            'wc-ont-templates',
            [ $this, 'render_page' ]
        );
    }

    public function enqueue( $hook ) {
        $screen    = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
        $screen_id = $screen ? $screen->id : '';

        $is_settings     = strpos( $hook, 'wc-ont-templates' ) !== false;
        $is_order_screen = in_array( $hook, [ 'post.php', 'post-new.php' ], true )
            || strpos( $screen_id, 'wc-orders' )        !== false
            || strpos( $screen_id, 'wc-subscriptions' ) !== false;

        if ( ! $is_settings && ! $is_order_screen ) {
            return;
        }

        wp_enqueue_style( 'wc-ont-admin', WC_ONT_URL . 'assets/admin.css', [], WC_ONT_VERSION );
        wp_enqueue_script( 'wc-ont-admin', WC_ONT_URL . 'assets/admin.js', [ 'jquery' ], WC_ONT_VERSION, true );
        wp_localize_script( 'wc-ont-admin', 'wcOnt', [
            'ajax_url'  => admin_url( 'admin-ajax.php' ),
            'nonce'     => wp_create_nonce( 'wc_ont_nonce' ),
            'templates' => $this->get_templates_for_js(),
        ] );
    }

    private function get_templates_for_js() {
        global $wpdb;
        $table = $wpdb->prefix . 'order_note_templates';
        return $wpdb->get_results(
            "SELECT id, title, note_text, note_type FROM {$table} ORDER BY note_type, sort_order, title",
            ARRAY_A
        );
    }

    private function get_all_templates() {
        global $wpdb;
        $table = $wpdb->prefix . 'order_note_templates';
        return $wpdb->get_results( "SELECT * FROM {$table} ORDER BY note_type, sort_order, title" );
    }

    private function get_template( $id ) {
        global $wpdb;
        $table = $wpdb->prefix . 'order_note_templates';
        return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ) );
    }

    public function handle_save() {
        check_admin_referer( 'wc_ont_save_template' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( 'No permission.' );
        }

        $id         = isset( $_POST['template_id'] ) ? absint( $_POST['template_id'] ) : 0;
        $title      = sanitize_text_field( $_POST['title'] ?? '' );
        $note_text  = sanitize_textarea_field( $_POST['note_text'] ?? '' );
        $note_type  = in_array( $_POST['note_type'] ?? '', [ 'customer', 'internal' ], true )
                      ? $_POST['note_type'] : 'customer';
        $sort_order = absint( $_POST['sort_order'] ?? 0 );

        if ( empty( $title ) || empty( $note_text ) ) {
            wp_redirect( add_query_arg( [ 'page' => 'wc-ont-templates', 'message' => 'empty' ], admin_url( 'admin.php' ) ) );
            exit;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'order_note_templates';
        $data  = compact( 'title', 'note_text', 'note_type', 'sort_order' );

        if ( $id ) {
            $wpdb->update( $table, $data, [ 'id' => $id ] );
            $msg = 'updated';
        } else {
            $wpdb->insert( $table, $data );
            $msg = 'added';
        }

        wp_redirect( add_query_arg( [ 'page' => 'wc-ont-templates', 'message' => $msg ], admin_url( 'admin.php' ) ) );
        exit;
    }

    public function handle_delete() {
        $id = absint( $_GET['id'] ?? 0 );
        check_admin_referer( 'wc_ont_delete_' . $id );
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( 'No permission.' );
        }
        global $wpdb;
        $wpdb->delete( $wpdb->prefix . 'order_note_templates', [ 'id' => $id ] );
        wp_redirect( add_query_arg( [ 'page' => 'wc-ont-templates', 'message' => 'deleted' ], admin_url( 'admin.php' ) ) );
        exit;
    }

    public function render_page() {
        $editing = null;
        $edit_id = absint( $_GET['edit'] ?? 0 );
        if ( $edit_id ) {
            $editing = $this->get_template( $edit_id );
        }

        $messages = [
            'added'   => [ 'success', __( 'Template added.',   'wc-ont' ) ],
            'updated' => [ 'success', __( 'Template updated.', 'wc-ont' ) ],
            'deleted' => [ 'success', __( 'Template deleted.', 'wc-ont' ) ],
            'empty'   => [ 'error',   __( 'Title and text cannot be empty.', 'wc-ont' ) ],
        ];
        $msg_key = $_GET['message'] ?? '';
        ?>
        <div class="wrap wc-ont-wrap">
            <h1 class="wp-heading-inline">📝 <?php esc_html_e( 'Order Note Templates', 'wc-ont' ); ?></h1>
            <hr class="wp-header-end">

            <?php if ( isset( $messages[ $msg_key ] ) ) :
                [$type, $text] = $messages[ $msg_key ]; ?>
                <div class="notice notice-<?= esc_attr( $type ) ?> is-dismissible"><p><?= esc_html( $text ) ?></p></div>
            <?php endif; ?>

            <div class="wc-ont-layout">

                <!-- Form -->
                <div class="wc-ont-form-card">
                    <h2><?= $editing ? esc_html__( 'Edit Template', 'wc-ont' ) : esc_html__( 'Add Template', 'wc-ont' ) ?></h2>
                    <form method="post" action="<?= esc_url( admin_url( 'admin-post.php' ) ) ?>">
                        <?php wp_nonce_field( 'wc_ont_save_template' ); ?>
                        <input type="hidden" name="action" value="wc_ont_save_template">
                        <input type="hidden" name="template_id" value="<?= $editing ? absint( $editing->id ) : 0 ?>">

                        <table class="form-table" role="presentation">
                            <tr>
                                <th><label for="ont-title"><?php esc_html_e( 'Template Name', 'wc-ont' ); ?></label></th>
                                <td><input id="ont-title" type="text" name="title" class="regular-text"
                                           value="<?= esc_attr( $editing->title ?? '' ) ?>"
                                           placeholder="<?php esc_attr_e( 'e.g. Order Shipped', 'wc-ont' ); ?>" required></td>
                            </tr>
                            <tr>
                                <th><label for="ont-type"><?php esc_html_e( 'Note Type', 'wc-ont' ); ?></label></th>
                                <td>
                                    <select id="ont-type" name="note_type">
                                        <option value="customer" <?= selected( $editing->note_type ?? 'customer', 'customer', false ) ?>>👤 <?php esc_html_e( 'Customer note (customer can see this)', 'wc-ont' ); ?></option>
                                        <option value="internal" <?= selected( $editing->note_type ?? '', 'internal', false ) ?>>🔒 <?php esc_html_e( 'Private note (staff only)', 'wc-ont' ); ?></option>
                                    </select>
                                </td>
                            </tr>
                            <tr>
                                <th><label for="ont-text"><?php esc_html_e( 'Note Text', 'wc-ont' ); ?></label></th>
                                <td>
                                    <textarea id="ont-text" name="note_text" rows="5" class="large-text"
                                              placeholder="<?php esc_attr_e( 'Text... Use {order_id}, {customer_name}, {billing_email}', 'wc-ont' ); ?>" required><?= esc_textarea( $editing->note_text ?? '' ) ?></textarea>
                                    <p class="description">
                                        <?php esc_html_e( 'Available variables:', 'wc-ont' ); ?>
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
                                <th><label for="ont-order"><?php esc_html_e( 'Sort Order', 'wc-ont' ); ?></label></th>
                                <td><input id="ont-order" type="number" name="sort_order" class="small-text"
                                           value="<?= absint( $editing->sort_order ?? 0 ) ?>" min="0"></td>
                            </tr>
                        </table>

                        <p class="submit">
                            <button type="submit" class="button button-primary">
                                <?= $editing ? esc_html__( '💾 Save Changes', 'wc-ont' ) : esc_html__( '➕ Add Template', 'wc-ont' ) ?>
                            </button>
                            <?php if ( $editing ) : ?>
                                <a href="<?= esc_url( admin_url( 'admin.php?page=wc-ont-templates' ) ) ?>"
                                   class="button button-secondary"><?php esc_html_e( 'Cancel', 'wc-ont' ); ?></a>
                            <?php endif; ?>
                        </p>
                    </form>
                </div>

                <!-- List -->
                <div class="wc-ont-list-card">
                    <h2><?php esc_html_e( 'All Templates', 'wc-ont' ); ?></h2>
                    <?php $templates = $this->get_all_templates(); ?>
                    <?php if ( empty( $templates ) ) : ?>
                        <p><?php esc_html_e( 'No templates yet.', 'wc-ont' ); ?></p>
                    <?php else : ?>
                        <table class="wp-list-table widefat fixed striped wc-ont-table">
                            <thead>
                                <tr>
                                    <th style="width:30px">#</th>
                                    <th><?php esc_html_e( 'Name', 'wc-ont' ); ?></th>
                                    <th style="width:110px"><?php esc_html_e( 'Type', 'wc-ont' ); ?></th>
                                    <th><?php esc_html_e( 'Text', 'wc-ont' ); ?></th>
                                    <th style="width:120px"><?php esc_html_e( 'Actions', 'wc-ont' ); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ( $templates as $t ) : ?>
                                <tr>
                                    <td><?= absint( $t->sort_order ) ?></td>
                                    <td><strong><?= esc_html( $t->title ) ?></strong></td>
                                    <td>
                                        <?php if ( $t->note_type === 'customer' ) : ?>
                                            <span class="wc-ont-badge wc-ont-badge--customer">👤 <?php esc_html_e( 'Customer', 'wc-ont' ); ?></span>
                                        <?php else : ?>
                                            <span class="wc-ont-badge wc-ont-badge--internal">🔒 <?php esc_html_e( 'Private', 'wc-ont' ); ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="wc-ont-preview"><?= esc_html( mb_strimwidth( $t->note_text, 0, 80, '…' ) ) ?></td>
                                    <td>
                                        <a href="<?= esc_url( add_query_arg( [ 'page' => 'wc-ont-templates', 'edit' => $t->id ], admin_url( 'admin.php' ) ) ) ?>"
                                           class="button button-small">✏️ <?php esc_html_e( 'Edit', 'wc-ont' ); ?></a>

                                        <a href="<?= esc_url( wp_nonce_url(
                                            add_query_arg( [ 'action' => 'wc_ont_delete_template', 'id' => $t->id ], admin_url( 'admin-post.php' ) ),
                                            'wc_ont_delete_' . $t->id
                                        ) ) ?>"
                                           class="button button-small button-link-delete"
                                           onclick="return confirm('<?php echo esc_js( sprintf( __( 'Delete template "%s"?', 'wc-ont' ), $t->title ) ); ?>')">🗑️</a>
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
