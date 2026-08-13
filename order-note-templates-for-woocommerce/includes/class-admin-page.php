<?php
defined( 'ABSPATH' ) || exit;

class WC_ONT_Admin_Page {

    public function __construct() {
        add_action( 'admin_menu',                        array( $this, 'register_menu' ) );
        add_action( 'admin_post_wc_ont_save_template',   array( $this, 'handle_save' ) );
        add_action( 'admin_post_wc_ont_delete_template', array( $this, 'handle_delete' ) );
        add_action( 'admin_enqueue_scripts',             array( $this, 'enqueue' ) );

        /*
         * Pro features live in files suffixed __premium_only, which the build
         * process strips from the free distribution entirely. Load them
         * defensively so the plugin behaves either way.
         */
        if ( wc_ont_is_pro() ) {
            $this->load_pro_modules();
        }
    }

    /**
     * Load the Pro modules when their files are present.
     */
    private function load_pro_modules() {
        $modules = array(
            'import-export'   => 'WC_ONT_Import_Export',
            'categories'      => 'WC_ONT_Categories',
            'auto-insert'     => 'WC_ONT_Auto_Insert',
            'pdf-attachments' => 'WC_ONT_PDF_Attachments',
        );

        foreach ( $modules as $slug => $class ) {
            $file = WC_ONT_DIR . 'includes/class-' . $slug . '__premium_only.php';

            if ( ! class_exists( $class ) && file_exists( $file ) ) {
                require_once $file;
            }

            if ( class_exists( $class ) ) {
                new $class();
            }
        }
    }

    public function register_menu() {
        add_submenu_page(
            'woocommerce',
            __( 'Order Note Templates', 'pro-web-design-order-note-templates-for-woocommerce' ),
            __( 'Order Note Templates', 'pro-web-design-order-note-templates-for-woocommerce' ),
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
            'is_pro'    => wc_ont_is_pro(),
        ) );
    }

    private function get_templates_for_js() {
        global $wpdb;
        $table = esc_sql( $wpdb->prefix . 'order_note_templates' );
        $fields = ( class_exists( 'WC_ONT_Categories' ) && wc_ont_column_exists( 'category' ) )
            ? 'id, title, note_text, note_type, category'
            : 'id, title, note_text, note_type';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter
        return $wpdb->get_results( "SELECT {$fields} FROM {$table} ORDER BY note_type, sort_order, title", ARRAY_A );
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

    private function get_template_count() {
        global $wpdb;
        $table = esc_sql( $wpdb->prefix . 'order_note_templates' );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
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
        // Category handling lives entirely in the Pro-only class (absent from the free build).
        $category   = class_exists( 'WC_ONT_Categories' ) ? WC_ONT_Categories::get_posted_category() : '';

        if ( empty( $title ) || empty( $note_text ) ) {
            wp_safe_redirect( add_query_arg( array( 'page' => 'wc-ont-templates', 'message' => 'empty' ), admin_url( 'admin.php' ) ) );
            exit;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'order_note_templates';
        $data  = array(
            'title'      => $title,
            'note_text'  => $note_text,
            'note_type'  => $note_type,
            'sort_order' => $sort_order,
        );
        // Pro columns are only written when the handling class is present (Pro build) and the column actually exists on the table.
        if ( class_exists( 'WC_ONT_Categories' ) && wc_ont_column_exists( 'category' ) ) {
            $data['category'] = $category;
        }

        if ( class_exists( 'WC_ONT_PDF_Attachments' ) && wc_ont_column_exists( 'pdf_attachment' ) ) {
            if ( ! empty( $_POST['remove_pdf'] ) ) {
                $data['pdf_attachment'] = '';
            } elseif ( ! empty( $_FILES['pdf_attachment']['name'] ) ) {
                $pdf_url = class_exists( 'WC_ONT_PDF_Attachments' )
                    ? WC_ONT_PDF_Attachments::handle_upload()
                    : '';
                if ( $pdf_url ) {
                    $data['pdf_attachment'] = $pdf_url;
                }
            }
        }

        if ( $id ) {
            $result = $wpdb->update( $table, $data, array( 'id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
            $msg    = 'updated';
        } else {
            $result = $wpdb->insert( $table, $data ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
            $msg    = 'added';
        }

        /*
         * $wpdb->update() legitimately returns 0 when nothing changed, so only
         * a hard false counts as a failure. Never report success on a failed
         * write — a green notice over a silently dropped template is worse
         * than any error message.
         */
        if ( false === $result ) {
            set_transient( 'wc_ont_db_error', $wpdb->last_error, 60 );
            wp_safe_redirect( add_query_arg( array( 'page' => 'wc-ont-templates', 'message' => 'db_error' ), admin_url( 'admin.php' ) ) );
            exit;
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
        $edit_id = isset( $_GET['edit'] ) ? absint( $_GET['edit'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if ( $edit_id ) {
            $editing = $this->get_template( $edit_id );
        }

        $is_pro = wc_ont_is_pro();

        $messages = array(
            'added'   => array( 'success', __( 'Template added.',   'pro-web-design-order-note-templates-for-woocommerce' ) ),
            'updated' => array( 'success', __( 'Template updated.', 'pro-web-design-order-note-templates-for-woocommerce' ) ),
            'deleted' => array( 'success', __( 'Template deleted.', 'pro-web-design-order-note-templates-for-woocommerce' ) ),
            'empty'   => array( 'error',   __( 'Title and text cannot be empty.', 'pro-web-design-order-note-templates-for-woocommerce' ) ),
            'db_error' => array( 'error', sprintf(
                /* translators: %s: database error message */
                __( 'The template could not be saved. Database error: %s', 'pro-web-design-order-note-templates-for-woocommerce' ),
                esc_html( (string) get_transient( 'wc_ont_db_error' ) )
            ) ),
        );
        $msg_key    = isset( $_GET['message'] ) ? sanitize_key( $_GET['message'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $active_tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'templates'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        ?>
        <div class="wrap wc-ont-wrap">
            <h1 class="wp-heading-inline">📝 <?php esc_html_e( 'Order Note Templates', 'pro-web-design-order-note-templates-for-woocommerce' ); ?></h1>
            <?php if ( ! $is_pro ) : ?>
                <a href="<?php echo esc_url( function_exists( 'wc_ont_fs' ) ? wc_ont_fs()->get_upgrade_url() : '#' ); ?>"
                   class="page-title-action" style="background:#7c3aed;color:#fff;border-color:#7c3aed">
                    ⭐ <?php esc_html_e( 'Upgrade to Pro', 'pro-web-design-order-note-templates-for-woocommerce' ); ?>
                </a>
            <?php endif; ?>
            <hr class="wp-header-end">

            <!-- Tab navigation -->
            <nav class="nav-tab-wrapper woo-nav-tab-wrapper" style="margin-bottom:20px">
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=wc-ont-templates' ) ); ?>"
                   class="nav-tab <?php echo ( 'templates' === $active_tab ) ? 'nav-tab-active' : ''; ?>">
                    📝 <?php esc_html_e( 'Templates', 'pro-web-design-order-note-templates-for-woocommerce' ); ?>
                </a>
                <?php if ( wc_ont_is_pro() ) : ?>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=wc-ont-templates&tab=auto-insert' ) ); ?>"
                   class="nav-tab <?php echo ( 'auto-insert' === $active_tab ) ? 'nav-tab-active' : ''; ?>">
                    ⚡ <?php esc_html_e( 'Auto-insert', 'pro-web-design-order-note-templates-for-woocommerce' ); ?>
                </a>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=wc-ont-templates&tab=categories' ) ); ?>"
                   class="nav-tab <?php echo ( 'categories' === $active_tab ) ? 'nav-tab-active' : ''; ?>">
                    🏷️ <?php esc_html_e( 'Categories', 'pro-web-design-order-note-templates-for-woocommerce' ); ?>
                </a>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=wc-ont-templates&tab=import-export' ) ); ?>"
                   class="nav-tab <?php echo ( 'import-export' === $active_tab ) ? 'nav-tab-active' : ''; ?>">
                    📦 <?php esc_html_e( 'Import / Export', 'pro-web-design-order-note-templates-for-woocommerce' ); ?>
                </a>
                <?php else : ?>
                <a href="<?php echo esc_url( function_exists( 'wc_ont_fs' ) ? wc_ont_fs()->get_upgrade_url() : '#' ); ?>"
                   class="nav-tab" style="color:#7c3aed">
                    🔒 <?php esc_html_e( 'Auto-insert', 'pro-web-design-order-note-templates-for-woocommerce' ); ?> <span style="font-size:10px">(Pro)</span>
                </a>
                <a href="<?php echo esc_url( function_exists( 'wc_ont_fs' ) ? wc_ont_fs()->get_upgrade_url() : '#' ); ?>"
                   class="nav-tab" style="color:#7c3aed">
                    🔒 <?php esc_html_e( 'Categories', 'pro-web-design-order-note-templates-for-woocommerce' ); ?> <span style="font-size:10px">(Pro)</span>
                </a>
                <a href="<?php echo esc_url( function_exists( 'wc_ont_fs' ) ? wc_ont_fs()->get_upgrade_url() : '#' ); ?>"
                   class="nav-tab" style="color:#7c3aed">
                    🔒 <?php esc_html_e( 'Import / Export', 'pro-web-design-order-note-templates-for-woocommerce' ); ?> <span style="font-size:10px">(Pro)</span>
                </a>
                <?php endif; ?>
            </nav>

            <?php if ( isset( $messages[ $msg_key ] ) ) :
                list( $type, $text ) = $messages[ $msg_key ]; ?>
                <div class="notice notice-<?php echo esc_attr( $type ); ?> is-dismissible"><p><?php echo wp_kses_post( $text ); ?></p></div>
            <?php endif; ?>

            <?php if ( wc_ont_is_pro() && 'auto-insert' === $active_tab && class_exists( 'WC_ONT_Auto_Insert' ) ) :
                $ai = new WC_ONT_Auto_Insert();
                $ai->render_tab();
                echo '</div>'; // .wrap
                return;
            endif; ?>

            <?php if ( wc_ont_is_pro() && 'import-export' === $active_tab && class_exists( 'WC_ONT_Import_Export' ) ) :
                $ie = new WC_ONT_Import_Export();
                $ie->render_tab();
                echo '</div>'; // .wrap
                return;
            endif; ?>

            <?php if ( wc_ont_is_pro() && 'categories' === $active_tab && class_exists( 'WC_ONT_Categories' ) ) :
                $cats = new WC_ONT_Categories();
                $cats->render_tab();
                echo '</div>'; // .wrap
                return;
            endif; ?>

            <div class="wc-ont-layout">

                <!-- Form -->
                <div class="wc-ont-form-card">
                    <h2><?php echo $editing ? esc_html__( 'Edit Template', 'pro-web-design-order-note-templates-for-woocommerce' ) : esc_html__( 'Add Template', 'pro-web-design-order-note-templates-for-woocommerce' ); ?></h2>

                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
                        <?php wp_nonce_field( 'wc_ont_save_template' ); ?>
                        <input type="hidden" name="action" value="wc_ont_save_template">
                        <input type="hidden" name="template_id" value="<?php echo $editing ? absint( $editing->id ) : 0; ?>">

                        <table class="form-table" role="presentation">
                            <tr>
                                <th><label for="ont-title"><?php esc_html_e( 'Template Name', 'pro-web-design-order-note-templates-for-woocommerce' ); ?></label></th>
                                <td><input id="ont-title" type="text" name="title" class="regular-text"
                                           value="<?php echo esc_attr( isset( $editing->title ) ? $editing->title : '' ); ?>"
                                           placeholder="<?php esc_attr_e( 'e.g. Order Shipped', 'pro-web-design-order-note-templates-for-woocommerce' ); ?>" required></td>
                            </tr>
                            <tr>
                                <th><label for="ont-type"><?php esc_html_e( 'Note Type', 'pro-web-design-order-note-templates-for-woocommerce' ); ?></label></th>
                                <td>
                                    <select id="ont-type" name="note_type">
                                        <option value="customer" <?php selected( isset( $editing->note_type ) ? $editing->note_type : 'customer', 'customer' ); ?>>👤 <?php esc_html_e( 'Customer note', 'pro-web-design-order-note-templates-for-woocommerce' ); ?></option>
                                        <option value="internal" <?php selected( isset( $editing->note_type ) ? $editing->note_type : '', 'internal' ); ?>>🔒 <?php esc_html_e( 'Private note', 'pro-web-design-order-note-templates-for-woocommerce' ); ?></option>
                                    </select>
                                </td>
                            </tr>
                            <tr>
                                <th><label for="ont-text"><?php esc_html_e( 'Note Text', 'pro-web-design-order-note-templates-for-woocommerce' ); ?></label></th>
                                <td>
                                    <textarea id="ont-text" name="note_text" rows="5" class="large-text" required><?php echo esc_textarea( isset( $editing->note_text ) ? $editing->note_text : '' ); ?></textarea>
                                    <p class="description">
                                        <?php esc_html_e( 'Variables:', 'pro-web-design-order-note-templates-for-woocommerce' ); ?>
                                        <code>{order_id}</code>, <code>{customer_name}</code>, <code>{billing_email}</code>, <code>{total}</code>,
                                        <code>{subscription_id}</code>, <code>{next_payment}</code>, <code>{start_date}</code>
                                    </p>
                                </td>
                            </tr>
                            <?php if ( class_exists( 'WC_ONT_Categories' ) ) :
                                WC_ONT_Categories::render_form_field( $editing ?: (object) array() );
                            endif; ?>
                            <tr>
                                <th><label for="ont-order"><?php esc_html_e( 'Sort Order', 'pro-web-design-order-note-templates-for-woocommerce' ); ?></label></th>
                                <td><input id="ont-order" type="number" name="sort_order" class="small-text"
                                           value="<?php echo absint( isset( $editing->sort_order ) ? $editing->sort_order : 0 ); ?>" min="0"></td>
                            </tr>
                            <?php if ( class_exists( 'WC_ONT_PDF_Attachments' ) ) :
                                WC_ONT_PDF_Attachments::render_form_field( $editing ?: (object) array() );
                            endif; ?>
                        </table>

                        <p class="submit">
                            <button type="submit" class="button button-primary">
                                <?php echo $editing ? esc_html__( '💾 Save Changes', 'pro-web-design-order-note-templates-for-woocommerce' ) : esc_html__( '➕ Add Template', 'pro-web-design-order-note-templates-for-woocommerce' ); ?>
                            </button>
                            <?php if ( $editing ) : ?>
                                <a href="<?php echo esc_url( admin_url( 'admin.php?page=wc-ont-templates' ) ); ?>"
                                   class="button button-secondary"><?php esc_html_e( 'Cancel', 'pro-web-design-order-note-templates-for-woocommerce' ); ?></a>
                            <?php endif; ?>
                        </p>
                    </form>
                </div>

                <!-- List -->
                <div class="wc-ont-list-card">
                    <h2><?php esc_html_e( 'All Templates', 'pro-web-design-order-note-templates-for-woocommerce' ); ?></h2>
                    <?php $templates = $this->get_all_templates(); ?>
                    <?php if ( empty( $templates ) ) : ?>
                        <p><?php esc_html_e( 'No templates yet.', 'pro-web-design-order-note-templates-for-woocommerce' ); ?></p>
                    <?php else : ?>
                        <table class="wp-list-table widefat fixed striped wc-ont-table">
                            <thead>
                                <tr>
                                    <th style="width:30px">#</th>
                                    <th><?php esc_html_e( 'Name', 'pro-web-design-order-note-templates-for-woocommerce' ); ?></th>
                                    <th style="width:110px"><?php esc_html_e( 'Type', 'pro-web-design-order-note-templates-for-woocommerce' ); ?></th>
                                    <th><?php esc_html_e( 'Text', 'pro-web-design-order-note-templates-for-woocommerce' ); ?></th>
                                    <th style="width:120px"><?php esc_html_e( 'Actions', 'pro-web-design-order-note-templates-for-woocommerce' ); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ( $templates as $t ) : ?>
                                <tr>
                                    <td><?php echo absint( $t->sort_order ); ?></td>
                                    <td><strong><?php echo esc_html( $t->title ); ?></strong></td>
                                    <td>
                                        <?php if ( 'customer' === $t->note_type ) : ?>
                                            <span class="wc-ont-badge wc-ont-badge--customer">👤 <?php esc_html_e( 'Customer', 'pro-web-design-order-note-templates-for-woocommerce' ); ?></span>
                                        <?php else : ?>
                                            <span class="wc-ont-badge wc-ont-badge--internal">🔒 <?php esc_html_e( 'Private', 'pro-web-design-order-note-templates-for-woocommerce' ); ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="wc-ont-preview"><?php echo esc_html( mb_strimwidth( $t->note_text, 0, 80, '…' ) ); ?></td>
                                    <td>
                                        <a href="<?php echo esc_url( add_query_arg( array( 'page' => 'wc-ont-templates', 'edit' => $t->id ), admin_url( 'admin.php' ) ) ); ?>"
                                           class="button button-small">✏️</a>
                                        <a href="<?php echo esc_url( wp_nonce_url(
                                            add_query_arg( array( 'action' => 'wc_ont_delete_template', 'id' => $t->id ), admin_url( 'admin-post.php' ) ),
                                            'wc_ont_delete_' . $t->id
                                        ) ); ?>"
                                           class="button button-small button-link-delete"
                                           onclick="return confirm('<?php echo esc_js( sprintf( /* translators: %s: template name */ __( 'Delete "%s"?', 'pro-web-design-order-note-templates-for-woocommerce' ), $t->title ) ); ?>')">🗑️</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>

                    <?php if ( ! $is_pro ) : ?>
                        <div style="margin-top:20px;padding:16px;background:#f5f3ff;border:1px solid #7c3aed;border-radius:6px">
                            <h3 style="margin-top:0;color:#7c3aed">⭐ <?php esc_html_e( 'Upgrade to Professional', 'pro-web-design-order-note-templates-for-woocommerce' ); ?></h3>
                            <ul style="margin:0 0 12px 16px">
                                <li><?php esc_html_e( 'Import/Export templates', 'pro-web-design-order-note-templates-for-woocommerce' ); ?></li>
                                <li><?php esc_html_e( 'Template categories', 'pro-web-design-order-note-templates-for-woocommerce' ); ?></li>
                                <li><?php esc_html_e( 'Auto-insert on order status change', 'pro-web-design-order-note-templates-for-woocommerce' ); ?></li>
                                <li><?php esc_html_e( 'Priority email support', 'pro-web-design-order-note-templates-for-woocommerce' ); ?></li>
                            </ul>
                            <a href="<?php echo esc_url( function_exists( 'wc_ont_fs' ) ? wc_ont_fs()->get_upgrade_url() : '#' ); ?>"
                               class="button button-primary" style="background:#7c3aed;border-color:#7c3aed">
                                <?php esc_html_e( '🚀 Upgrade Now — $29/year', 'pro-web-design-order-note-templates-for-woocommerce' ); ?>
                            </a>
                        </div>
                    <?php endif; ?>
                </div>

            </div>
        </div>
        <?php
    }
}

new WC_ONT_Admin_Page();
