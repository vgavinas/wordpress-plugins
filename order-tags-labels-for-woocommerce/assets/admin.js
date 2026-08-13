/**
 * Order Tags & Labels for WooCommerce — admin scripts.
 *
 * Handles:
 *  - tag assignment/removal from the order edit meta box
 *  - tag CRUD + drag reorder on the "Order Tags" management screen
 */
/* global wcOtl, jQuery */
( function ( $ ) {
	'use strict';

	if ( typeof wcOtl === 'undefined' ) {
		return;
	}

	function ajax( action, data ) {
		return $.post(
			wcOtl.ajaxUrl,
			Object.assign( { action: 'wc_otl_' + action, nonce: wcOtl.nonce }, data )
		);
	}

	/* -----------------------------------------------------------------
	 * Order edit screen meta box
	 * --------------------------------------------------------------- */
	function initMetaBox() {
		var $box = $( '.wc-otl-meta-box' );

		if ( ! $box.length ) {
			return;
		}

		var orderId = $box.data( 'order-id' );

		$box.on( 'change', '.wc-otl-tag-toggle', function () {
			var $checkbox = $( this );
			var tagId = $checkbox.val();
			var isChecked = $checkbox.is( ':checked' );
			var $spinner = $box.find( '.wc-otl-spinner' );

			$spinner.addClass( 'is-active' );
			$checkbox.prop( 'disabled', true );

			ajax( isChecked ? 'assign_tag' : 'remove_tag', {
				order_id: orderId,
				tag_id: tagId,
			} )
				.fail( function () {
					// Revert the checkbox on failure.
					$checkbox.prop( 'checked', ! isChecked );
				} )
				.always( function () {
					$spinner.removeClass( 'is-active' );
					$checkbox.prop( 'disabled', false );
				} );
		} );
	}

	/* -----------------------------------------------------------------
	 * Tag management screen
	 * --------------------------------------------------------------- */
	function initTagsScreen() {
		var $list = $( '#wc-otl-tags-list' );

		if ( ! $list.length ) {
			return;
		}

		// Color picker.
		if ( $.fn.wpColorPicker ) {
			$( '.wc-otl-color-picker' ).wpColorPicker();
		}

		// Add tag.
		$( '#wc-otl-add-tag-form' ).on( 'submit', function ( e ) {
			e.preventDefault();

			var name = $( '#wc-otl-new-tag-name' ).val();
			var color = $( '#wc-otl-new-tag-color' ).val();

			ajax( 'create_tag', { name: name, color: color } ).done( function ( response ) {
				if ( response && response.success ) {
					window.location.reload();
				} else {
					window.alert( ( response && response.data && response.data.message ) || 'Error' );
				}
			} );
		} );

		// Edit tag: turn the row into an inline form with a name field and color picker.
		$list.on( 'click', '.wc-otl-edit-tag', function () {
			var $row = $( this ).closest( 'tr' );

			if ( $row.hasClass( 'wc-otl-editing' ) ) {
				return;
			}

			$row.addClass( 'wc-otl-editing' );
			$row.data( 'original-html', $row.html() );

			var name = $row.data( 'tag-name' );
			var color = $row.data( 'tag-color' );

			$row.find( '.wc-otl-col-name' ).html(
				$( '<input>' ).attr( 'type', 'text' ).addClass( 'wc-otl-edit-name-input' ).val( name ).attr( 'maxlength', 100 )
			);
			$row.find( '.wc-otl-col-color' ).html(
				$( '<input>' ).attr( 'type', 'text' ).addClass( 'wc-otl-edit-color-input wc-otl-color-picker' ).val( color )
			);
			$row.find( '.wc-otl-col-actions' ).html(
				$( '<button>' ).attr( 'type', 'button' ).addClass( 'button button-primary wc-otl-save-tag' ).text( wcOtl.i18n.save ) // eslint-disable-line
			).append(
				$( '<button>' ).attr( 'type', 'button' ).addClass( 'button wc-otl-cancel-edit' ).text( wcOtl.i18n.cancel ) // eslint-disable-line
			);

			if ( $.fn.wpColorPicker ) {
				$row.find( '.wc-otl-edit-color-input' ).wpColorPicker();
			}
		} );

		// Cancel inline edit.
		$list.on( 'click', '.wc-otl-cancel-edit', function () {
			var $row = $( this ).closest( 'tr' );
			$row.html( $row.data( 'original-html' ) );
			$row.removeClass( 'wc-otl-editing' );
		} );

		// Save inline edit.
		$list.on( 'click', '.wc-otl-save-tag', function () {
			var $row = $( this ).closest( 'tr' );
			var tagId = $row.data( 'tag-id' );
			var $nameInput = $row.find( '.wc-otl-edit-name-input' );
			var $colorInput = $row.find( '.wc-otl-edit-color-input' );
			var name = $nameInput.val().trim();
			var color = $colorInput.val().trim();

			if ( '' === name ) {
				$nameInput.trigger( 'focus' );
				return;
			}

			ajax( 'update_tag', { tag_id: tagId, name: name, color: color } ).done( function ( response ) {
				if ( response && response.success ) {
					window.location.reload();
				} else {
					window.alert( ( response && response.data && response.data.message ) || 'Error' );
				}
			} );
		} );

		// Delete tag.
		$list.on( 'click', '.wc-otl-delete-tag', function () {
			var $row = $( this ).closest( 'tr' );
			var tagId = $row.data( 'tag-id' );

			if ( ! window.confirm( wcOtl.i18n.confirmDelete ) ) {
				return;
			}

			ajax( 'delete_tag', { tag_id: tagId } ).done( function ( response ) {
				if ( response && response.success ) {
					$row.remove();
				}
			} );
		} );

		// Drag reorder (requires jQuery UI Sortable, bundled with WP core).
		if ( $.fn.sortable ) {
			$list.sortable( {
				handle: '.wc-otl-drag-handle',
				axis: 'y',
				update: function () {
					var order = $list.find( 'tr' ).map( function () {
						return $( this ).data( 'tag-id' );
					} ).get();

					ajax( 'reorder_tags', { order: order } );
				},
			} );
		}
	}

	/* -----------------------------------------------------------------
	 * Auto-Tag Rules screen: swap the free-text "value" input for a
	 * dropdown when the chosen field has a fixed set of possible values.
	 * --------------------------------------------------------------- */
	function initRulesScreen() {
		var $fieldSelect = $( '#wc-otl-rule-field' );

		if ( ! $fieldSelect.length ) {
			return;
		}

		var $valueInputs = $( '.wc-otl-value-input' );

		function syncValueInput() {
			var field = $fieldSelect.val();
			// Only use the dropdown if it actually has options — an empty <select> (e.g. a
			// store with zero registered shipping methods) would otherwise be a dead end
			// with no way to enter a value at all.
			var $match = $( '.wc-otl-value-for-' + field ).filter( function () {
				return $( this ).find( 'option' ).length > 0;
			} );

			$valueInputs.hide().removeAttr( 'name' );

			if ( $match.length ) {
				$match.show().attr( 'name', 'value' );
			} else {
				$( '.wc-otl-value-default' ).show().attr( 'name', 'value' );
			}
		}

		$fieldSelect.on( 'change', syncValueInput );
		syncValueInput();
	}

	$( function () {
		initMetaBox();
		initTagsScreen();
		initRulesScreen();
	} );
} )( jQuery );
