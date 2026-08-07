/**
 * WC Order Note Templates — Admin JS
 *
 * Works on:
 *  - Classic order edit screen        (#order_note / #order_note_type)
 *  - HPOS order edit screen           (same selectors, different hook)
 *  - WC Subscriptions edit screen     (#wcs_add_note_content / #wcs_note_type)
 */
( function ( $ ) {
    'use strict';

    var templates = window.wcOnt ? window.wcOnt.templates : [];
    var orderId   = 0;
    var orderData = {};
    var context   = 'order'; // 'order' | 'subscription'

    /* ------------------------------------------------------------------ */
    /* Bootstrap                                                            */
    /* ------------------------------------------------------------------ */
    $( document ).ready( function () {
        var $box = $( '#wc-ont-metabox' );
        if ( ! $box.length ) return;

        context = $box.data( 'context' ) || 'order';

        // Detect ID from URL (?post=123 or ?id=123)
        var params = new URLSearchParams( window.location.search );
        orderId = parseInt( params.get( 'post' ) || params.get( 'id' ) || 0, 10 );

        populateSelect();
        fetchOrderData();
        bindEvents();
    } );

    /* ------------------------------------------------------------------ */
    /* Populate <select>                                                     */
    /* ------------------------------------------------------------------ */
    function populateSelect() {
        var $customer = $( '#wc-ont-group-customer' );
        var $internal = $( '#wc-ont-group-internal' );

        $.each( templates, function ( _, t ) {
            var $opt = $( '<option>' )
                .val( t.id )
                .text( t.title )
                .data( 'text', t.note_text )
                .data( 'type', t.note_type );

            if ( t.note_type === 'internal' ) {
                $internal.append( $opt );
            } else {
                $customer.append( $opt );
            }
        } );

        // Remove empty optgroups
        $( '#wc-ont-select optgroup' ).each( function () {
            if ( ! $( this ).children().length ) $( this ).remove();
        } );
    }

    /* ------------------------------------------------------------------ */
    /* Fetch entity variables for placeholder replacement                   */
    /* ------------------------------------------------------------------ */
    function fetchOrderData() {
        if ( ! orderId ) return;

        $.post( window.wcOnt.ajax_url, {
            action   : 'wc_ont_get_order_data',
            nonce    : window.wcOnt.nonce,
            order_id : orderId,
        }, function ( response ) {
            if ( response.success ) {
                orderData = response.data;
                var $sel = $( '#wc-ont-select' );
                if ( $sel.val() ) {
                    showPreview( $sel.find( ':selected' ).data( 'text' ) );
                }
            }
        } );
    }

    /* ------------------------------------------------------------------ */
    /* Bind UI events                                                       */
    /* ------------------------------------------------------------------ */
    function bindEvents() {
        $( '#wc-ont-select' ).on( 'change', function () {
            var rawText = $( this ).find( ':selected' ).data( 'text' ) || '';
            if ( ! rawText ) {
                $( '#wc-ont-preview-box' ).hide();
                return;
            }
            showPreview( rawText );
        } );

        $( '#wc-ont-insert-btn' ).on( 'click', function () {
            var resolved = $( '#wc-ont-preview-text' ).val();
            var noteType = $( '#wc-ont-select' ).find( ':selected' ).data( 'type' );
            insertNote( resolved, noteType );
        } );
    }

    /* ------------------------------------------------------------------ */
    /* Preview with variables resolved                                       */
    /* ------------------------------------------------------------------ */
    function showPreview( rawText ) {
        $( '#wc-ont-preview-text' ).val( resolveVars( rawText ) );
        $( '#wc-ont-preview-box' ).show();
    }

    /* ------------------------------------------------------------------ */
    /* Variable substitution                                                */
    /* ------------------------------------------------------------------ */
    function resolveVars( text ) {
        if ( ! orderData ) return text;

        return text
            .replace( /\{order_id\}/g,        orderData.order_id        || '' )
            .replace( /\{subscription_id\}/g,  orderData.subscription_id || orderData.order_id || '' )
            .replace( /\{customer_name\}/g,    orderData.customer_name   || '' )
            .replace( /\{billing_email\}/g,    orderData.billing_email   || '' )
            .replace( /\{total\}/g,            orderData.total           || '' )
            .replace( /\{next_payment\}/g,     orderData.next_payment    || '' )
            .replace( /\{start_date\}/g,       orderData.start_date      || '' );
    }

    /* ------------------------------------------------------------------ */
    /* Insert text into the note textarea                                   */
    /*                                                                      */
    /* Order screens:        #order_note  +  #order_note_type              */
    /* Subscription screens: #wcs_add_note_content  +  #wcs_note_type      */
    /* ------------------------------------------------------------------ */
    function insertNote( text, noteType ) {
        var $noteText, $noteTypeField;

        if ( context === 'subscription' ) {
            $noteText      = $( '#add_order_note' );
            $noteTypeField = $( '#order_note_type' );

            if ( ! $noteText.length ) {
                $noteText = $( '.woocommerce_order_notes textarea:visible,' +
                               '#order_note, .input-text[name="order_note"]' ).first();
            }
        } else {
            $noteText      = $( '#order_note' );
            $noteTypeField = $( '#order_note_type' );

            if ( ! $noteText.length ) {
                $noteText = $( '.order-notes textarea:visible,' +
                               '#woocommerce-order-notes textarea:visible' ).first();
            }
        }

        if ( $noteText.length ) {
            $noteText.val( text ).trigger( 'change' ).trigger( 'input' );
            $noteText.addClass( 'wc-ont-flashed' );
            setTimeout( function () { $noteText.removeClass( 'wc-ont-flashed' ); }, 900 );
        }

        if ( $noteTypeField.length ) {
            if ( $noteTypeField.is( 'select' ) ) {
                $noteTypeField.val( noteType === 'internal' ? 'internal_note' : 'customer_note' );
            } else {
                var radioVal = noteType === 'internal' ? 'internal_note' : 'customer_note';
                $noteTypeField.filter( '[value="' + radioVal + '"]' ).prop( 'checked', true );
            }
        }

        $( '#wc-ont-select' ).val( '' );
        $( '#wc-ont-preview-box' ).hide();
    }

} )( jQuery );
