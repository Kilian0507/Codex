( function () {
	'use strict';

	var cfg = window.SwimTimingData || {};

	/* ---------------- Utilities ---------------- */

	function qs( sel, ctx ) {
		return ( ctx || document ).querySelector( sel );
	}
	function qsa( sel, ctx ) {
		return Array.prototype.slice.call( ( ctx || document ).querySelectorAll( sel ) );
	}
	function esc( str ) {
		var div = document.createElement( 'div' );
		div.textContent = str == null ? '' : String( str );
		return div.innerHTML;
	}

	function ajax( action, data, isFormData ) {
		var body;
		if ( isFormData ) {
			body = data;
			body.append( 'action', action );
		} else {
			body = new URLSearchParams();
			body.append( 'action', action );
			Object.keys( data || {} ).forEach( function ( key ) {
				body.append( key, data[ key ] );
			} );
		}
		return fetch( cfg.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			body: body,
		} ).then( function ( res ) {
			return res.json();
		} );
	}

	/* ---------------- Time input widget (HH:MM:SS:mmm) ---------------- */

	function buildTimeInput( container ) {
		var name = container.getAttribute( 'data-name' );
		container.innerHTML = '';
		container.dataset.value = '';

		var units = [
			{ key: 'h', max: 99, len: 2 },
			{ key: 'm', max: 59, len: 2 },
			{ key: 's', max: 59, len: 2 },
			{ key: 'ms', max: 999, len: 3 },
		];

		units.forEach( function ( unit, index ) {
			if ( index > 0 ) {
				var sep = document.createElement( 'span' );
				sep.className = 'swimtiming-time-sep';
				sep.textContent = ':';
				container.appendChild( sep );
			}
			var input = document.createElement( 'input' );
			input.type = 'text';
			input.inputMode = 'numeric';
			input.maxLength = unit.len;
			input.value = '0'.repeat( unit.len );
			input.setAttribute( 'data-unit', unit.key );
			input.autocomplete = 'off';

			input.addEventListener( 'focus', function () {
				input.select();
			} );

			input.addEventListener( 'input', function () {
				var digits = input.value.replace( /\D/g, '' );
				if ( digits.length > unit.len ) {
					digits = digits.slice( digits.length - unit.len );
				}
				input.value = digits;

				if ( digits.length >= unit.len ) {
					var next = input.nextElementSibling;
					while ( next && next.tagName !== 'INPUT' ) {
						next = next.nextElementSibling;
					}
					if ( next ) {
						next.focus();
						next.select();
					}
				}
			} );

			input.addEventListener( 'blur', function () {
				var val = input.value.replace( /\D/g, '' );
				var num = val === '' ? 0 : parseInt( val, 10 );
				if ( num > unit.max ) {
					num = unit.max;
				}
				input.value = String( num ).padStart( unit.len, '0' );
			} );

			input.addEventListener( 'keydown', function ( e ) {
				if ( e.key === 'Backspace' && input.value === '' ) {
					var prev = input.previousElementSibling;
					while ( prev && prev.tagName !== 'INPUT' ) {
						prev = prev.previousElementSibling;
					}
					if ( prev ) {
						prev.focus();
					}
				}
				if ( e.key === 'ArrowLeft' && input.selectionStart === 0 ) {
					var p = input.previousElementSibling;
					while ( p && p.tagName !== 'INPUT' ) {
						p = p.previousElementSibling;
					}
					if ( p ) {
						e.preventDefault();
						p.focus();
						p.select();
					}
				}
				if ( e.key === 'ArrowRight' && input.selectionStart === input.value.length ) {
					var n = input.nextElementSibling;
					while ( n && n.tagName !== 'INPUT' ) {
						n = n.nextElementSibling;
					}
					if ( n ) {
						e.preventDefault();
						n.focus();
						n.select();
					}
				}
			} );

			container.appendChild( input );
		} );
	}

	function getTimeInputValue( container ) {
		var inputs = qsa( 'input', container );
		var parts = inputs.map( function ( input ) {
			var unit = input.getAttribute( 'data-unit' );
			var len = unit === 'ms' ? 3 : 2;
			var val = input.value.replace( /\D/g, '' ) || '0';
			return val.padStart( len, '0' );
		} );
		return parts.join( ':' );
	}

	function isTimeInputEmpty( container ) {
		return getTimeInputValue( container ) === '00:00:00:000';
	}

	function setTimeInputValue( container, value ) {
		var inputs = qsa( 'input', container );
		if ( ! value ) {
			inputs.forEach( function ( input ) {
				var len = input.getAttribute( 'data-unit' ) === 'ms' ? 3 : 2;
				input.value = '0'.repeat( len );
			} );
			return;
		}
		var parts = String( value ).split( ':' );
		inputs.forEach( function ( input, i ) {
			var len = input.getAttribute( 'data-unit' ) === 'ms' ? 3 : 2;
			var raw = parts[ i ] !== undefined ? parts[ i ].replace( /\D/g, '' ) : '0';
			input.value = ( raw || '0' ).padStart( len, '0' );
		} );
	}

	function initAllTimeInputs( ctx ) {
		qsa( '.swimtiming-time-input', ctx ).forEach( buildTimeInput );
	}

	function formatTime( value ) {
		if ( ! value ) {
			return '–';
		}
		var parts = value.split( ':' );
		if ( parts.length < 4 ) {
			return value;
		}
		return parts[0] + ':' + parts[1] + ':' + parts[2] + '.' + parts[3];
	}

	/* ---------------- Modal helpers ---------------- */

	function openModal( modal ) {
		modal.hidden = false;
	}
	function closeModal( modal ) {
		modal.hidden = true;
	}

	qsa( '[data-close-modal]' ).forEach( function ( btn ) {
		btn.addEventListener( 'click', function () {
			closeModal( btn.closest( '.swimtiming-modal' ) );
		} );
	} );
	qsa( '.swimtiming-modal' ).forEach( function ( modal ) {
		modal.addEventListener( 'click', function ( e ) {
			if ( e.target === modal ) {
				closeModal( modal );
			}
		} );
	} );

	/* ---------------- Admin area ---------------- */

	function initAdmin() {
		var root = qs( '#swimtiming-admin' );
		if ( ! root ) {
			return;
		}

		initAllTimeInputs( root );

		var starterModal = qs( '#swimtiming-starter-modal' );
		var starterForm = qs( '#swimtiming-starter-form' );
		var starterModalTitle = qs( '#swimtiming-starter-modal-title' );
		var detailModal = qs( '#swimtiming-detail-modal' );
		var splitForm = qs( '#swimtiming-split-form' );
		var tbody = qs( '#swimtiming-starters-tbody' );
		var searchInput = qs( '#swimtiming-search' );
		var currentStarterId = null;

		/* Tabs */
		qsa( '.swimtiming-tab', root ).forEach( function ( tab ) {
			tab.addEventListener( 'click', function () {
				qsa( '.swimtiming-tab', root ).forEach( function ( t ) {
					t.classList.remove( 'is-active' );
				} );
				qsa( '.swimtiming-panel', root ).forEach( function ( p ) {
					p.classList.remove( 'is-active' );
				} );
				tab.classList.add( 'is-active' );
				qs( '[data-panel="' + tab.getAttribute( 'data-tab' ) + '"]', root ).classList.add( 'is-active' );
			} );
		} );

		function loadStarters() {
			tbody.innerHTML = '<tr><td colspan="7" class="swimtiming-empty">' + esc( cfg.i18n.loading ) + '</td></tr>';
			ajax( 'swimtiming_list_starters', {
				nonce: cfg.adminNonce,
				search: searchInput.value || '',
			} ).then( function ( res ) {
				if ( ! res.success ) {
					tbody.innerHTML = '<tr><td colspan="7" class="swimtiming-empty">' + esc( cfg.i18n.error ) + '</td></tr>';
					return;
				}
				renderStarters( res.data.starters );
			} );
		}

		function renderStarters( starters ) {
			if ( ! starters.length ) {
				tbody.innerHTML = '<tr><td colspan="7" class="swimtiming-empty">–</td></tr>';
				return;
			}
			tbody.innerHTML = '';
			starters.forEach( function ( s ) {
				var tr = document.createElement( 'tr' );
				tr.className = 'swimtiming-row-clickable';
				tr.innerHTML =
					'<td>' + esc( s.first_name ) + '</td>' +
					'<td>' + esc( s.last_name ) + '</td>' +
					'<td>' + esc( formatTime( s.report_time ) ) + '</td>' +
					'<td>' + esc( formatTime( s.start_time ) ) + '</td>' +
					'<td>' + esc( formatTime( s.end_time ) ) + '</td>' +
					'<td>' + s.split_count + '</td>' +
					'<td></td>';

				var actionsTd = tr.lastElementChild;

				var editBtn = document.createElement( 'button' );
				editBtn.type = 'button';
				editBtn.className = 'swimtiming-btn swimtiming-btn-icon';
				editBtn.title = 'Bearbeiten';
				editBtn.textContent = '✎';
				editBtn.addEventListener( 'click', function ( e ) {
					e.stopPropagation();
					openStarterModal( s );
				} );

				var delBtn = document.createElement( 'button' );
				delBtn.type = 'button';
				delBtn.className = 'swimtiming-btn swimtiming-btn-icon swimtiming-btn-danger';
				delBtn.title = 'Löschen';
				delBtn.textContent = '🗑';
				delBtn.addEventListener( 'click', function ( e ) {
					e.stopPropagation();
					if ( window.confirm( cfg.i18n.confirmDeleteStarter ) ) {
						ajax( 'swimtiming_delete_starter', { nonce: cfg.adminNonce, id: s.id } ).then( loadStarters );
					}
				} );

				actionsTd.appendChild( editBtn );
				actionsTd.appendChild( delBtn );

				tr.addEventListener( 'click', function () {
					openDetailModal( s.id );
				} );

				tbody.appendChild( tr );
			} );
		}

		function openStarterModal( starter ) {
			starterForm.reset();
			qsa( '.swimtiming-time-input', starterForm ).forEach( function ( c ) {
				setTimeInputValue( c, '' );
			} );

			if ( starter ) {
				starterModalTitle.textContent = starter.first_name + ' ' + starter.last_name;
				starterForm.elements.id.value = starter.id;
				starterForm.elements.first_name.value = starter.first_name;
				starterForm.elements.last_name.value = starter.last_name;
				setTimeInputValue( qs( '[data-name="report_time"]', starterForm ), starter.report_time );
				setTimeInputValue( qs( '[data-name="start_time"]', starterForm ), starter.start_time );
				setTimeInputValue( qs( '[data-name="end_time"]', starterForm ), starter.end_time );
			} else {
				starterModalTitle.textContent = 'Startperson anlegen';
				starterForm.elements.id.value = '';
			}
			openModal( starterModal );
		}

		qs( '#swimtiming-new-starter' ).addEventListener( 'click', function () {
			openStarterModal( null );
		} );

		starterForm.addEventListener( 'submit', function ( e ) {
			e.preventDefault();
			var id = starterForm.elements.id.value;
			var payload = {
				nonce: cfg.adminNonce,
				first_name: starterForm.elements.first_name.value,
				last_name: starterForm.elements.last_name.value,
				report_time: getTimeInputValue( qs( '[data-name="report_time"]', starterForm ) ),
				start_time: getTimeInputValue( qs( '[data-name="start_time"]', starterForm ) ),
				end_time: getTimeInputValue( qs( '[data-name="end_time"]', starterForm ) ),
			};
			var action = 'swimtiming_add_starter';
			if ( id ) {
				action = 'swimtiming_update_starter';
				payload.id = id;
			}
			ajax( action, payload ).then( function ( res ) {
				if ( res.success ) {
					closeModal( starterModal );
					loadStarters();
				} else {
					window.alert( res.data && res.data.message ? res.data.message : cfg.i18n.error );
				}
			} );
		} );

		var searchTimer;
		searchInput.addEventListener( 'input', function () {
			clearTimeout( searchTimer );
			searchTimer = setTimeout( loadStarters, 300 );
		} );

		/* Detail modal (splits) */
		function openDetailModal( starterId ) {
			currentStarterId = starterId;
			splitForm.elements.starter_id.value = starterId;
			ajax( 'swimtiming_get_starter', { nonce: cfg.adminNonce, id: starterId } ).then( function ( res ) {
				if ( ! res.success ) {
					return;
				}
				var starter = res.data.starter;
				qs( '#swimtiming-detail-name' ).textContent = starter.first_name + ' ' + starter.last_name;
				qs( '#swimtiming-detail-meta' ).textContent =
					'Meldezeit: ' + formatTime( starter.report_time ) +
					' · Startzeit: ' + formatTime( starter.start_time ) +
					' · Endzeit: ' + formatTime( starter.end_time );
				renderSplits( res.data.splits );
				splitForm.reset();
				splitForm.elements.starter_id.value = starterId;
				setTimeInputValue( qs( '[data-name="split_time"]', splitForm ), '' );
				openModal( detailModal );
			} );
		}

		function renderSplits( splits ) {
			var stbody = qs( '#swimtiming-splits-tbody' );
			stbody.innerHTML = '';
			if ( ! splits.length ) {
				stbody.innerHTML = '<tr><td colspan="4" class="swimtiming-empty">–</td></tr>';
				return;
			}
			splits.forEach( function ( split ) {
				var tr = document.createElement( 'tr' );
				tr.innerHTML =
					'<td>' + split.split_number + '</td>' +
					'<td>' + esc( formatTime( split.split_time ) ) + '</td>' +
					'<td>' + esc( split.comment || '' ) + '</td>' +
					'<td></td>';
				var actionsTd = tr.lastElementChild;

				var delBtn = document.createElement( 'button' );
				delBtn.type = 'button';
				delBtn.className = 'swimtiming-btn swimtiming-btn-icon swimtiming-btn-danger';
				delBtn.textContent = '🗑';
				delBtn.addEventListener( 'click', function () {
					if ( window.confirm( cfg.i18n.confirmDeleteSplit ) ) {
						ajax( 'swimtiming_delete_split', {
							nonce: cfg.adminNonce,
							id: split.id,
							starter_id: currentStarterId,
						} ).then( function ( res ) {
							if ( res.success ) {
								renderSplits( res.data.splits );
								loadStarters();
							}
						} );
					}
				} );
				actionsTd.appendChild( delBtn );
				stbody.appendChild( tr );
			} );
		}

		splitForm.addEventListener( 'submit', function ( e ) {
			e.preventDefault();
			ajax( 'swimtiming_add_split', {
				nonce: cfg.adminNonce,
				starter_id: splitForm.elements.starter_id.value,
				split_number: splitForm.elements.split_number.value,
				split_time: getTimeInputValue( qs( '[data-name="split_time"]', splitForm ) ),
				comment: splitForm.elements.comment.value,
			} ).then( function ( res ) {
				if ( res.success ) {
					renderSplits( res.data.splits );
					loadStarters();
					splitForm.elements.split_number.value = '';
					splitForm.elements.comment.value = '';
					setTimeInputValue( qs( '[data-name="split_time"]', splitForm ), '' );
				} else {
					window.alert( res.data && res.data.message ? res.data.message : cfg.i18n.error );
				}
			} );
		} );

		/* CSV import */
		function handleImportForm( formId, action, resultId ) {
			var form = qs( formId );
			if ( ! form ) {
				return;
			}
			form.addEventListener( 'submit', function ( e ) {
				e.preventDefault();
				var fileInput = form.querySelector( 'input[type="file"]' );
				if ( ! fileInput.files.length ) {
					return;
				}
				var fd = new FormData();
				fd.append( 'nonce', cfg.adminNonce );
				fd.append( 'csv_file', fileInput.files[0] );

				var resultBox = qs( resultId );
				resultBox.innerHTML = esc( cfg.i18n.loading );

				ajax( action, fd, true ).then( function ( res ) {
					if ( ! res.success ) {
						resultBox.innerHTML = '<span class="swimtiming-error">' + esc( res.data && res.data.message ? res.data.message : cfg.i18n.error ) + '</span>';
						return;
					}
					var html = '<div class="swimtiming-import-ok">' + res.data.imported + ' von ' + res.data.total + ' Zeilen importiert.</div>';
					if ( res.data.errors && res.data.errors.length ) {
						html += '<ul>' + res.data.errors.map( function ( err ) {
							return '<li>' + esc( err ) + '</li>';
						} ).join( '' ) + '</ul>';
					}
					resultBox.innerHTML = html;
					form.reset();
					loadStarters();
				} );
			} );
		}

		handleImportForm( '#swimtiming-import-starters-form', 'swimtiming_import_starters_csv', '#swimtiming-import-starters-result' );
		handleImportForm( '#swimtiming-import-splits-form', 'swimtiming_import_splits_csv', '#swimtiming-import-splits-result' );

		loadStarters();
	}

	/* ---------------- Public area ---------------- */

	function initPublic() {
		var root = qs( '#swimtiming-public' );
		if ( ! root ) {
			return;
		}

		initAllTimeInputs( root );

		var form = qs( '#swimtiming-lookup-form' );
		var resultBox = qs( '#swimtiming-public-result' );
		var errorBox = qs( '#swimtiming-public-error' );

		form.addEventListener( 'submit', function ( e ) {
			e.preventDefault();
			errorBox.hidden = true;
			resultBox.hidden = true;

			var firstName = form.elements.first_name.value.trim();
			var lastName = form.elements.last_name.value.trim();
			var startTime = getTimeInputValue( qs( '[data-name="start_time"]', form ) );

			if ( ! firstName || ! lastName ) {
				errorBox.textContent = cfg.i18n.requiredFields;
				errorBox.hidden = false;
				return;
			}

			ajax( 'swimtiming_public_lookup', {
				nonce: cfg.publicNonce,
				first_name: firstName,
				last_name: lastName,
				start_time: startTime,
			} ).then( function ( res ) {
				if ( ! res.success ) {
					errorBox.textContent = res.data && res.data.message ? res.data.message : cfg.i18n.noResults;
					errorBox.hidden = false;
					return;
				}

				var starter = res.data.starter;
				qs( '#swimtiming-public-name' ).textContent = starter.first_name + ' ' + starter.last_name;
				qs( '#swimtiming-public-meta' ).textContent =
					'Meldezeit: ' + formatTime( starter.report_time ) +
					' · Startzeit: ' + formatTime( starter.start_time ) +
					' · Endzeit: ' + formatTime( starter.end_time );

				var tbody = qs( '#swimtiming-public-splits' );
				tbody.innerHTML = '';
				if ( ! res.data.splits.length ) {
					tbody.innerHTML = '<tr><td colspan="3" class="swimtiming-empty">–</td></tr>';
				} else {
					res.data.splits.forEach( function ( split ) {
						var tr = document.createElement( 'tr' );
						tr.innerHTML =
							'<td>' + split.split_number + '</td>' +
							'<td>' + esc( formatTime( split.split_time ) ) + '</td>' +
							'<td>' + esc( split.comment || '' ) + '</td>';
						tbody.appendChild( tr );
					} );
				}

				var pdfUrl = cfg.ajaxUrl +
					'?action=swimtiming_public_pdf' +
					'&nonce=' + encodeURIComponent( cfg.publicNonce ) +
					'&first_name=' + encodeURIComponent( firstName ) +
					'&last_name=' + encodeURIComponent( lastName ) +
					'&start_time=' + encodeURIComponent( startTime );
				qs( '#swimtiming-public-pdf' ).setAttribute( 'href', pdfUrl );

				resultBox.hidden = false;
			} );
		} );
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		initAdmin();
		initPublic();
	} );
}() );
