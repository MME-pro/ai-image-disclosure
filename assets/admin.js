/**
 * MME AI Image Disclosure — admin behaviour.
 *
 * Vanilla JS plus the Media Library's own jQuery/wp.media, so no framework is added to the
 * client's admin stack.
 */
( function ( $ ) {
	'use strict';

	var data = window.aiLabelData || {};
	var i18n = data.i18n || {};

	function format( template, values ) {
		var out = String( template );
		values.forEach( function ( value, index ) {
			out = out.replace( '%' + ( index + 1 ) + '$d', value ).replace( '%' + ( index + 1 ) + '$s', value );
		} );
		return out;
	}

	function request( path, options ) {
		var settings = options || {};
		return window.fetch( data.restBase + path, {
			method: settings.method || 'GET',
			credentials: 'same-origin',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': data.nonce
			},
			body: settings.body ? JSON.stringify( settings.body ) : undefined
		} ).then( function ( response ) {
			return response.json().then( function ( json ) {
				if ( ! response.ok ) {
					throw new Error( json && json.message ? json.message : i18n.error );
				}
				return json;
			} );
		} );
	}

	function debounce( fn, wait ) {
		var timer = null;
		return function () {
			var args = arguments;
			var self = this;
			window.clearTimeout( timer );
			timer = window.setTimeout( function () {
				fn.apply( self, args );
			}, wait );
		};
	}

	/* ---------------------------------------------------------------------
	 * Confirmation guards
	 * ------------------------------------------------------------------ */

	$( document ).on( 'click', '.ai-label-confirm', function ( event ) {
		var message = $( this ).data( 'confirm' );
		if ( message && ! window.confirm( message ) ) {
			event.preventDefault();
		}
	} );

	/* ---------------------------------------------------------------------
	 * Per-image override disclosure
	 * ------------------------------------------------------------------ */

	$( document ).on( 'click', '.ai-label-toggle-overrides', function () {
		var panel = $( '.ai-label-overrides' );
		var isHidden = panel.attr( 'hidden' ) !== undefined;

		if ( isHidden ) {
			panel.removeAttr( 'hidden' );
		} else {
			panel.attr( 'hidden', 'hidden' );
		}
		$( this ).attr( 'aria-expanded', isHidden ? 'true' : 'false' );
	} );

	/* ---------------------------------------------------------------------
	 * Modal dialogs
	 * ------------------------------------------------------------------ */

	function openModal( dialog ) {
		if ( ! dialog ) {
			return;
		}
		if ( typeof dialog.showModal === 'function' ) {
			dialog.showModal();
		} else {
			// Older browsers without <dialog>: fall back to a positioned panel.
			dialog.setAttribute( 'open', 'open' );
			$( dialog ).addClass( 'is-open' );
		}
		var first = dialog.querySelector( 'input:not([type="hidden"]), select, textarea' );
		if ( first ) {
			first.focus();
		}
	}

	function closeModal( dialog ) {
		if ( ! dialog ) {
			return;
		}
		if ( typeof dialog.close === 'function' && dialog.open ) {
			dialog.close();
		} else {
			dialog.removeAttribute( 'open' );
		}
		$( dialog ).removeClass( 'is-open' );
	}

	$( document ).on( 'click', '[data-ai-label-modal-open]', function ( event ) {
		event.preventDefault();
		openModal( document.getElementById( $( this ).data( 'ai-label-modal-open' ) ) );
	} );

	$( document ).on( 'click', '[data-ai-label-modal-close]', function ( event ) {
		event.preventDefault();
		closeModal( $( this ).closest( '.ai-label-modal' ).get( 0 ) );
	} );

	// Clicking the backdrop closes the dialog; clicks inside the form do not.
	$( document ).on( 'click', '.ai-label-modal', function ( event ) {
		if ( event.target === this ) {
			closeModal( this );
		}
	} );

	/* ---------------------------------------------------------------------
	 * Badge source: show only the settings for the selected source
	 * ------------------------------------------------------------------ */

	function applyMode( $scope, mode ) {
		$scope.find( '[data-ai-label-panel]' ).each( function () {
			var panel = $( this );
			if ( panel.data( 'ai-label-panel' ) === mode ) {
				panel.removeAttr( 'hidden' );
			} else {
				panel.attr( 'hidden', 'hidden' );
			}
		} );

		var saved = $scope.data( 'ai-label-saved-mode' );
		var $flag = $scope.find( '[data-ai-label-unsaved]' );
		if ( $flag.length && saved ) {
			if ( saved === mode ) {
				$flag.attr( 'hidden', 'hidden' );
			} else {
				$flag.removeAttr( 'hidden' );
			}
		}
	}

	$( document ).on( 'change', 'input[data-ai-label-mode]', function () {
		var $scope = $( this ).closest( '[data-ai-label-type-card], [data-ai-label-mode-scope]' );
		if ( $scope.length ) {
			applyMode( $scope, this.value );
		}
	} );

	/* ---------------------------------------------------------------------
	 * Radio group highlighting
	 *
	 * The segmented control and position picker style their selected option
	 * with :has(input:checked); this mirrors it onto a class so the highlight
	 * also works where :has() is unavailable.
	 * ------------------------------------------------------------------ */

	function syncSelection( root ) {
		$( root ).find( '.ai-label-segment label, .ai-label-position' ).each( function () {
			var input = $( this ).find( 'input' ).get( 0 );
			$( this ).toggleClass( 'is-selected', !! ( input && input.checked ) );
		} );
	}

	$( document ).on( 'change', '.ai-label-segment input, .ai-label-position input', function () {
		var form = $( this ).closest( 'form, .ai-label-card' );
		syncSelection( form.length ? form : document );
	} );

	$( document ).ready( function () {
		syncSelection( document );
	} );

	/* ---------------------------------------------------------------------
	 * Colour swatch <-> hex field pairing
	 * ------------------------------------------------------------------ */

	function normaliseHex( value ) {
		var hex = String( value || '' ).trim();
		if ( hex.charAt( 0 ) !== '#' ) {
			hex = '#' + hex;
		}
		if ( /^#[0-9a-f]{3}$/i.test( hex ) ) {
			hex = '#' + hex[ 1 ] + hex[ 1 ] + hex[ 2 ] + hex[ 2 ] + hex[ 3 ] + hex[ 3 ];
		}
		return /^#[0-9a-f]{6}$/i.test( hex ) ? hex.toUpperCase() : null;
	}

	$( document ).on( 'input change', 'input[data-ai-label-colour-for]', function () {
		var name = $( this ).data( 'ai-label-colour-for' );
		$( this ).closest( 'form' ).find( '[name="' + name + '"]' ).val( this.value.toUpperCase() ).trigger( 'ai-label:changed' );
	} );

	$( document ).on( 'input', '.ai-label-colour', function () {
		var hex = normaliseHex( this.value );
		if ( ! hex ) {
			return;
		}
		var swatch = $( this ).closest( '.ai-label-colour-field' ).find( 'input[type="color"]' );
		if ( swatch.length ) {
			swatch.val( hex.toLowerCase() );
		}
	} );

	/* ---------------------------------------------------------------------
	 * Live text badge preview
	 * ------------------------------------------------------------------ */

	function collectTextConfig( $form ) {
		var config = { light: {}, dark: {} };

		$form.find( '[name^="text_config"]' ).each( function () {
			var match = /^text_config\[([^\]]+)\](?:\[([^\]]+)\])?$/.exec( this.name );
			if ( ! match ) {
				return;
			}
			if ( match[ 2 ] ) {
				config[ match[ 1 ] ] = config[ match[ 1 ] ] || {};
				config[ match[ 1 ] ][ match[ 2 ] ] = this.value;
			} else {
				config[ match[ 1 ] ] = this.value;
			}
		} );

		return config;
	}

	function refreshPreview( $form ) {
		var config = collectTextConfig( $form );
		if ( ! config.text || ! String( config.text ).trim() ) {
			return;
		}

		var $targets = $form.find( '[data-ai-label-preview]' );
		$targets.addClass( 'ai-label-is-loading' );

		request( '/text-preview', {
			method: 'POST',
			body: { config: config }
		} ).then( function ( response ) {
			$targets.removeClass( 'ai-label-is-loading' ).each( function () {
				var variant = $( this ).data( 'ai-label-preview' );
				var preview = response.previews[ variant ];
				if ( ! preview || ! preview.url ) {
					return;
				}
				var img = $( this ).find( 'img' );
				if ( ! img.length ) {
					img = $( '<img class="ai-label-badge-preview" alt="" />' ).appendTo( $( this ).empty() );
				}
				img.attr( 'src', preview.url );
			} );
		} ).catch( function ( error ) {
			$targets.removeClass( 'ai-label-is-loading' );
			$form.find( '.ai-label-preview-note' ).text( error.message );
		} );
	}

	var queuePreview = debounce( function ( form ) {
		refreshPreview( $( form ) );
	}, 450 );

	$( document ).on( 'input change ai-label:changed', '[data-ai-label-preview-input]', function () {
		var form = $( this ).closest( '[data-ai-label-preview-form]' );
		if ( form.length ) {
			queuePreview( form.get( 0 ) );
		}
	} );

	/* ---------------------------------------------------------------------
	 * Badge picker (label types screen)
	 * ------------------------------------------------------------------ */

	$( document ).on( 'click', '.ai-label-pick-badge', function ( event ) {
		event.preventDefault();

		if ( ! window.wp || ! window.wp.media ) {
			return;
		}

		var $form = $( this ).closest( 'form' );
		var frame = window.wp.media( {
			title: i18n.selectBadge,
			button: { text: i18n.selectBadge },
			library: { type: 'image/png' },
			multiple: false
		} );

		frame.on( 'select', function () {
			var attachment = frame.state().get( 'selection' ).first().toJSON();
			$form.find( '.ai-label-badge-input' ).val( attachment.id );
			$form.closest( '.ai-label-variant' ).find( '.ai-label-variant__preview' )
				.html( '<img src="' + attachment.url + '" alt="" class="ai-label-badge-preview">' );
			$form.find( 'button[type="submit"]' ).addClass( 'button-primary' );
		} );

		frame.open();
	} );

	/* ---------------------------------------------------------------------
	 * Row actions in the Media Library list view
	 * ------------------------------------------------------------------ */

	$( document ).on( 'click', '.ai-label-row-action', function ( event ) {
		event.preventDefault();

		var $link = $( this );
		var action = $link.data( 'ai-label-action' );
		var id = parseInt( $link.data( 'ai-label-id' ), 10 );
		var original = $link.text();

		if ( 'restore' === action && ! window.confirm( i18n.confirmRestore ) ) {
			return;
		}

		$link.text( '…' );

		request( '/' + action, {
			method: 'POST',
			body: { attachment_ids: [ id ] }
		} ).then( function ( response ) {
			window.location.href = data.applyUrl + '&batch=' + encodeURIComponent( response.batch_id );
		} ).catch( function ( error ) {
			window.alert( error.message );
			$link.text( original );
		} );
	} );

	/* ---------------------------------------------------------------------
	 * Grid view: "Add AI Label" button beside the native bulk-select toolbar
	 * ------------------------------------------------------------------ */

	function selectedGridIds() {
		if ( ! window.wp || ! window.wp.media || ! window.wp.media.frame ) {
			return [];
		}
		var state = window.wp.media.frame.state();
		if ( ! state || ! state.get( 'selection' ) ) {
			return [];
		}
		return state.get( 'selection' ).map( function ( model ) {
			return model.id;
		} );
	}

	function injectGridButton() {
		var $toolbar = $( '.media-toolbar-secondary' );
		if ( ! $toolbar.length || $toolbar.find( '.ai-label-grid-button' ).length ) {
			return;
		}

		$( '<button type="button" class="button ai-label-grid-button" />' )
			.text( i18n.addLabel )
			.appendTo( $toolbar )
			.on( 'click', function ( event ) {
				event.preventDefault();
				var ids = selectedGridIds();
				if ( ! ids.length ) {
					window.alert( i18n.noSelection );
					return;
				}
				window.location.href = data.applyUrl + '&ids=' + ids.join( ',' );
			} );
	}

	if ( $( 'body' ).hasClass( 'upload-php' ) ) {
		$( document ).ready( function () {
			injectGridButton();
			// Backbone re-renders the grid toolbar whenever select mode toggles, so keep
			// checking rather than binding to a node that gets thrown away.
			window.setInterval( injectGridButton, 1500 );
		} );
	}

	/* ---------------------------------------------------------------------
	 * Bulk action interception: "Add AI Label" needs options, so send the
	 * selection to the apply screen instead of posting the bulk form.
	 * ------------------------------------------------------------------ */

	$( document ).on( 'submit', '#posts-filter', function ( event ) {
		var action = $( this ).find( 'select[name="action"]' ).val();
		var action2 = $( this ).find( 'select[name="action2"]' ).val();
		if ( 'ai_label_apply' !== action && 'ai_label_apply' !== action2 ) {
			return;
		}

		var ids = $( this ).find( 'input[name="media[]"]:checked' ).map( function () {
			return this.value;
		} ).get();

		event.preventDefault();

		if ( ! ids.length ) {
			window.alert( i18n.noSelection );
			return;
		}

		window.location.href = data.applyUrl + '&ids=' + ids.join( ',' );
	} );

	/* ---------------------------------------------------------------------
	 * Batch progress polling
	 * ------------------------------------------------------------------ */

	function statusClass( status ) {
		if ( 'failed' === status ) {
			return 'ai-label-pill--fail';
		}
		if ( 'success' === status ) {
			return 'ai-label-pill--on';
		}
		return 'ai-label-pill--none';
	}

	function renderProgress( progress ) {
		var $wrap = $( '#ai-label-batch' );
		var percent = progress.total ? Math.round( ( 100 * progress.done ) / progress.total ) : 0;

		$wrap.find( '.ai-label-progress__bar' ).css( 'width', percent + '%' );
		$wrap.find( '.ai-label-progress-count' ).text( percent + '%' );
		$wrap.find( '.ai-label-progress' ).toggleClass( 'is-running', ! progress.complete );
		$wrap.find( '.ai-label-progress-text' ).text(
			progress.complete
				? format( i18n.done, [ progress.success, progress.failed ] )
				: format( i18n.progress, [ progress.done, progress.total ] )
		);

		var rows = progress.items.map( function ( item ) {
			var $row = $( '<tr />' );
			var $cell = $( '<span class="ai-label-media-cell" />' );

			if ( item.thumb ) {
				$cell.append( $( '<img />' ).attr( { src: item.thumb, alt: '', loading: 'lazy' } ) );
			}
			$cell.append( $( '<span />' ).text( item.title || '#' + item.attachment_id ) );

			$row.append( $( '<td />' ).append( $cell ) );
			$row.append( $( '<td />' ).append( $( '<span class="ai-label-meta" />' ).text( item.action ) ) );
			$row.append(
				$( '<td />' ).append(
					$( '<span />' ).addClass( 'ai-label-pill ' + statusClass( item.status ) ).text( item.status )
				)
			);
			$row.append( $( '<td />' ).append( $( '<span class="ai-label-meta" />' ).text( item.message || '' ) ) );
			return $row;
		} );

		$wrap.find( 'tbody' ).empty().append( rows );
	}

	var $batch = $( '#ai-label-batch' );
	if ( $batch.length ) {
		( function poll() {
			request( '/progress?batch_id=' + encodeURIComponent( $batch.data( 'batch' ) ) )
				.then( function ( progress ) {
					renderProgress( progress );
					if ( ! progress.complete ) {
						window.setTimeout( poll, 2500 );
					}
				} )
				.catch( function () {
					window.setTimeout( poll, 8000 );
				} );
		} )();
	}
} )( window.jQuery );
