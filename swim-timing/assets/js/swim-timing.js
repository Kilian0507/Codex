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

	function teamLabel( team ) {
		if ( 'rot' === team ) {
			return 'Rot';
		}
		if ( 'gelb' === team ) {
			return 'Gelb';
		}
		return cfg.i18n && cfg.i18n.noneTeam ? cfg.i18n.noneTeam : '–';
	}

	function teamBadge( team ) {
		if ( 'rot' === team || 'gelb' === team ) {
			return '<span class="swimtiming-badge swimtiming-badge-' + team + '">' + esc( teamLabel( team ) ) + '</span>';
		}
		return '<span class="swimtiming-badge">' + esc( teamLabel( team ) ) + '</span>';
	}

	/**
	 * Minuten-Schlüssel für eine Uhrzeit (HH:MM) einer Über-Nacht-Veranstaltung:
	 * Stunden vor 12 Uhr zählen als "nächster Tag" (siehe
	 * SwimTiming_DB::clock_sort_key() in PHP - dieselbe Logik), damit z. B.
	 * 00:10 nach 23:50 einsortiert bzw. verglichen wird statt davor.
	 */
	function clockSortKey( hhmm ) {
		if ( ! hhmm ) {
			return null;
		}
		var parts = hhmm.split( ':' );
		var h = parseInt( parts[0], 10 ) || 0;
		var m = parseInt( parts[1], 10 ) || 0;
		var minutes = h * 60 + m;
		if ( h < 12 ) {
			minutes += 1440;
		}
		return minutes;
	}

	function getBerlinTimeParts() {
		var fmt = new Intl.DateTimeFormat( 'de-DE', {
			timeZone: 'Europe/Berlin',
			hour: '2-digit',
			minute: '2-digit',
			second: '2-digit',
			hour12: false,
		} );
		var parts = {};
		fmt.formatToParts( new Date() ).forEach( function ( p ) {
			parts[ p.type ] = p.value;
		} );
		return parts; // { hour, minute, second }
	}

	/* ---------------- Time input widget (MM:SS:CS) ----------------
	 * Minute:Sekunde:Hundertstel, angezeigt als 00:00:00. Ziffern werden
	 * ganz normal von links nach rechts eingetippt (erst Minuten, dann
	 * Sekunden, dann Hundertstel) - noch nicht eingetippte Stellen werden
	 * als 0 angezeigt. Wer nur "24" tippt, bekommt sofort 24 Minuten
	 * (24:00:00) statt versehentlich 24 Hundertstel.
	 */

	function digitsToDisplay( digits ) {
		var d = digits.padEnd( 6, '0' ).slice( 0, 6 );
		return d.slice( 0, 2 ) + ':' + d.slice( 2, 4 ) + ':' + d.slice( 4, 6 );
	}

	function digitsToValue( digits ) {
		return digitsToDisplay( digits );
	}

	function buildTimeInput( container ) {
		container.innerHTML = '';
		container.dataset.digits = '';

		var input = document.createElement( 'input' );
		input.type = 'text';
		input.inputMode = 'numeric';
		input.autocomplete = 'off';
		input.className = 'swimtiming-duration-field';
		input.value = digitsToDisplay( '' );
		input.setAttribute( 'aria-label', 'Min:Sek:Hundertstel' );

		function render() {
			input.value = digitsToDisplay( container.dataset.digits );
		}

		input.addEventListener( 'keydown', function ( e ) {
			if ( e.ctrlKey || e.metaKey || e.altKey ) {
				return;
			}
			if ( /^[0-9]$/.test( e.key ) ) {
				e.preventDefault();
				// Erste Ziffer nach dem Fokussieren ersetzt einen bereits
				// vollständig befüllten Wert komplett, statt ignoriert zu
				// werden - sonst lässt sich eine bestehende Zeit nicht
				// einfach überschreiben.
				if ( '1' === container.dataset.freshFocus ) {
					container.dataset.digits = '';
				}
				container.dataset.freshFocus = '';
				if ( container.dataset.digits.length < 6 ) {
					container.dataset.digits = container.dataset.digits + e.key;
					render();
				}
				return;
			}
			container.dataset.freshFocus = '';
			if ( 'Backspace' === e.key || 'Delete' === e.key ) {
				e.preventDefault();
				container.dataset.digits = container.dataset.digits.slice( 0, -1 );
				render();
				return;
			}
			if ( [ 'Tab', 'Enter', 'Shift' ].indexOf( e.key ) === -1 ) {
				e.preventDefault();
			}
		} );

		input.addEventListener( 'paste', function ( e ) {
			e.preventDefault();
			var text = ( e.clipboardData || window.clipboardData ).getData( 'text' );
			container.dataset.digits = ( text.match( /\d/g ) || [] ).join( '' ).slice( 0, 6 );
			render();
		} );

		input.addEventListener( 'focus', function () {
			input.select();
			container.dataset.freshFocus = '1';
		} );

		container.appendChild( input );

		// Feste Beschriftung unter dem Feld, damit "Minute:Sekunde:Hundertstel"
		// immer sichtbar ist statt nur einmal in einem Hinweistext zu stehen -
		// beugt Verwechslungen mit Stunde:Minute:Sekunde vor.
		var unitHint = document.createElement( 'span' );
		unitHint.className = 'swimtiming-duration-unit-hint';
		unitHint.textContent = 'Min : Sek : Hs';
		container.appendChild( unitHint );
	}

	function getTimeInputValue( container ) {
		return digitsToValue( container.dataset.digits || '' );
	}

	function setTimeInputValue( container, value ) {
		var input = qs( 'input', container );
		container.dataset.freshFocus = '';
		if ( ! value ) {
			container.dataset.digits = '';
		} else {
			var parts = String( value ).split( ':' );
			var m = ( parts[0] || '0' ).replace( /\D/g, '' ).padStart( 2, '0' ).slice( -2 );
			var s = ( parts[1] || '0' ).replace( /\D/g, '' ).padStart( 2, '0' ).slice( -2 );
			var cs = ( parts[2] || '0' ).replace( /\D/g, '' ).padStart( 2, '0' ).slice( -2 );
			container.dataset.digits = m + s + cs;
		}
		if ( input ) {
			input.value = digitsToDisplay( container.dataset.digits );
		}
	}

	function initAllTimeInputs( ctx ) {
		qsa( '.swimtiming-time-input', ctx ).forEach( buildTimeInput );
	}

	function formatTime( value ) {
		if ( ! value ) {
			return '–';
		}
		return value; // Bereits im Anzeigeformat gespeichert (HH:MM bzw. MM:SS:CS).
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

		// Die Modals (Startperson anlegen/bearbeiten, Zwischenzeiten) liegen im
		// Markup außerhalb von #swimtiming-admin, deshalb hier auf dem ganzen
		// Dokument initialisieren statt nur auf root.
		initAllTimeInputs( document );

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
			tbody.innerHTML = '<tr><td colspan="9" class="swimtiming-empty">' + esc( cfg.i18n.loading ) + '</td></tr>';
			ajax( 'swimtiming_list_starters', {
				nonce: cfg.adminNonce,
				search: searchInput.value || '',
			} ).then( function ( res ) {
				if ( ! res.success ) {
					tbody.innerHTML = '<tr><td colspan="9" class="swimtiming-empty">' + esc( cfg.i18n.error ) + '</td></tr>';
					return;
				}
				renderStarters( res.data.starters );
			} );
		}

		function renderStarters( starters ) {
			if ( ! starters.length ) {
				tbody.innerHTML = '<tr><td colspan="9" class="swimtiming-empty">–</td></tr>';
				return;
			}
			tbody.innerHTML = '';
			starters.forEach( function ( s ) {
				var tr = document.createElement( 'tr' );
				tr.className = 'swimtiming-row-clickable';
				tr.innerHTML =
					'<td data-label="Vorname">' + esc( s.first_name ) + '</td>' +
					'<td data-label="Nachname">' + esc( s.last_name ) + '</td>' +
					'<td data-label="Staffel">' + teamBadge( s.team ) + '</td>' +
					'<td data-label="Pos.">' + ( s.team ? s.team_position : '–' ) + '</td>' +
					'<td data-label="Meldezeit">' + esc( formatTime( s.report_time ) ) + '</td>' +
					'<td data-label="Startzeit">' + esc( formatTime( s.start_time ) ) + '</td>' +
					'<td data-label="Endzeit">' + esc( formatTime( s.end_time ) ) + '</td>' +
					'<td data-label="Zwischenzeiten">' + s.split_count + '</td>' +
					'<td></td>';

				var actionsTd = tr.lastElementChild;
				actionsTd.className = "swimtiming-actions-cell";

				var splitsBtn = document.createElement( 'button' );
				splitsBtn.type = 'button';
				splitsBtn.className = 'swimtiming-btn';
				splitsBtn.title = 'Zwischenzeiten verwalten';
				splitsBtn.textContent = '⏱ Zwischenzeiten';
				splitsBtn.addEventListener( 'click', function ( e ) {
					e.stopPropagation();
					openDetailModal( s.id );
				} );

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

				actionsTd.appendChild( splitsBtn );
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
			var reportField = qs( '[data-name="report_time"]', starterForm );
			var endField = qs( '[data-name="end_time"]', starterForm );

			if ( starter ) {
				starterModalTitle.textContent = starter.first_name + ' ' + starter.last_name;
				starterForm.elements.id.value = starter.id;
				starterForm.elements.first_name.value = starter.first_name;
				starterForm.elements.last_name.value = starter.last_name;
				starterForm.elements.team.value = starter.team || '';
				starterForm.elements.team_position.value = starter.team_position || '';
				setTimeInputValue( reportField, starter.report_time );
				starterForm.elements.start_time.value = starter.start_time || '';
				setTimeInputValue( endField, starter.end_time );
			} else {
				starterModalTitle.textContent = 'Startperson anlegen';
				starterForm.elements.id.value = '';
				starterForm.elements.team_position.value = '';
				setTimeInputValue( reportField, '' );
				setTimeInputValue( endField, '' );
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
				team: starterForm.elements.team.value,
				team_position: starterForm.elements.team_position.value,
				report_time: getTimeInputValue( qs( '[data-name="report_time"]', starterForm ) ),
				start_time: starterForm.elements.start_time.value,
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
					'Staffel: ' + teamLabel( starter.team ) + ( starter.team ? ' (Pos. ' + starter.team_position + ')' : '' ) +
					' · Meldezeit: ' + formatTime( starter.report_time ) +
					' · Startzeit: ' + formatTime( starter.start_time ) +
					' · Endzeit: ' + formatTime( starter.end_time );
				renderSplits( res.data.splits );
				resetSplitFormToAddMode();
				splitForm.elements.starter_id.value = starterId;
				openModal( detailModal );
				qs( 'input', qs( '[data-name="split_time"]', splitForm ) ).focus();
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
					'<td data-label="#">' + split.split_number + '</td>' +
					'<td data-label="Zeit">' + esc( formatTime( split.split_time ) ) + '</td>' +
					'<td data-label="Kommentar">' + esc( split.comment || '' ) + '</td>' +
					'<td></td>';
				var actionsTd = tr.lastElementChild;
				actionsTd.className = "swimtiming-actions-cell";

				var editBtn = document.createElement( 'button' );
				editBtn.type = 'button';
				editBtn.className = 'swimtiming-btn swimtiming-btn-icon';
				editBtn.title = 'Bearbeiten';
				editBtn.textContent = '✎';
				editBtn.addEventListener( 'click', function () {
					enterSplitEditMode( split );
				} );

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
				actionsTd.appendChild( editBtn );
				actionsTd.appendChild( delBtn );
				stbody.appendChild( tr );
			} );
		}

		var splitFormTitle = qs( '#swimtiming-split-form-title' );
		var splitSubmitBtn = qs( '#swimtiming-split-submit' );
		var splitCancelBtn = qs( '#swimtiming-split-cancel-edit' );

		function enterSplitEditMode( split ) {
			splitForm.elements.id.value = split.id;
			splitForm.elements.split_number.value = split.split_number;
			splitForm.elements.comment.value = split.comment || '';
			var timeField = qs( '[data-name="split_time"]', splitForm );
			setTimeInputValue( timeField, split.split_time );
			splitFormTitle.textContent = cfg.i18n.editSplitTitle;
			splitSubmitBtn.textContent = cfg.i18n.editSplit;
			splitCancelBtn.hidden = false;
			qs( 'input', timeField ).focus();
		}

		function resetSplitFormToAddMode() {
			splitForm.elements.id.value = '';
			splitForm.elements.split_number.value = '';
			splitForm.elements.comment.value = '';
			setTimeInputValue( qs( '[data-name="split_time"]', splitForm ), '' );
			splitFormTitle.textContent = cfg.i18n.addSplitTitle;
			splitSubmitBtn.textContent = cfg.i18n.addSplit;
			splitCancelBtn.hidden = true;
		}

		splitCancelBtn.addEventListener( 'click', resetSplitFormToAddMode );

		splitForm.addEventListener( 'submit', function ( e ) {
			e.preventDefault();
			var editId = splitForm.elements.id.value;
			var payload = {
				nonce: cfg.adminNonce,
				starter_id: splitForm.elements.starter_id.value,
				split_number: splitForm.elements.split_number.value,
				split_time: getTimeInputValue( qs( '[data-name="split_time"]', splitForm ) ),
				comment: splitForm.elements.comment.value,
			};
			var action = 'swimtiming_add_split';
			if ( editId ) {
				action = 'swimtiming_update_split';
				payload.id = editId;
			}
			ajax( action, payload ).then( function ( res ) {
				if ( res.success ) {
					renderSplits( res.data.splits );
					loadStarters();
					var wasEditing = !! editId;
					resetSplitFormToAddMode();
					if ( ! wasEditing ) {
						// Fokus zurück ins Zeitfeld, damit man mehrere Zwischenzeiten
						// hintereinander eintippen kann, ohne die Maus zu benutzen.
						qs( 'input', qs( '[data-name="split_time"]', splitForm ) ).focus();
					}
				} else {
					window.alert( res.data && res.data.message ? res.data.message : cfg.i18n.error );
				}
			} );
		} );

		/* Tabelle einfügen (Paste-Import) */
		function handlePasteForm( formId, action, resultId ) {
			var form = qs( formId );
			if ( ! form ) {
				return;
			}
			form.addEventListener( 'submit', function ( e ) {
				e.preventDefault();
				var text = form.elements.data.value;
				if ( ! text || ! text.trim() ) {
					return;
				}

				var resultBox = qs( resultId );
				resultBox.innerHTML = esc( cfg.i18n.loading );

				ajax( action, { nonce: cfg.adminNonce, data: text } ).then( function ( res ) {
					if ( ! res.success ) {
						resultBox.innerHTML = '<span class="swimtiming-error">' + esc( res.data && res.data.message ? res.data.message : cfg.i18n.error ) + '</span>';
						return;
					}
					var html = '<div class="swimtiming-import-ok">' + res.data.imported + ' von ' + res.data.total + ' Zeilen übernommen.</div>';
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

		handlePasteForm( '#swimtiming-import-starters-form', 'swimtiming_import_starters_paste', '#swimtiming-import-starters-result' );
		handlePasteForm( '#swimtiming-import-splits-form', 'swimtiming_import_splits_paste', '#swimtiming-import-splits-result' );

		var cascadeToggle = qs( '#swimtiming-cascade-toggle' );
		if ( cascadeToggle ) {
			cascadeToggle.addEventListener( 'change', function () {
				ajax( 'swimtiming_toggle_cascade', {
					nonce: cfg.adminNonce,
					enabled: cascadeToggle.checked ? '1' : '0',
				} ).then( function ( res ) {
					if ( ! res.success ) {
						cascadeToggle.checked = ! cascadeToggle.checked;
						window.alert( res.data && res.data.message ? res.data.message : cfg.i18n.error );
						return;
					}
					// Beim Wiedereinschalten hat der Server bereits die ganze
					// Staffel neu durchgerechnet - Liste aktualisieren.
					if ( cascadeToggle.checked ) {
						loadStarters();
					}
				} );
			} );
		}

		var recalculateBtn = qs( '#swimtiming-recalculate-all' );
		if ( recalculateBtn ) {
			recalculateBtn.addEventListener( 'click', function () {
				recalculateBtn.disabled = true;
				ajax( 'swimtiming_recalculate_all', { nonce: cfg.adminNonce } ).then( function ( res ) {
					recalculateBtn.disabled = false;
					if ( res.success ) {
						loadStarters();
					} else {
						window.alert( res.data && res.data.message ? res.data.message : cfg.i18n.error );
					}
				} );
			} );
		}

		var deleteAllBtn = qs( '#swimtiming-delete-all' );
		if ( deleteAllBtn ) {
			deleteAllBtn.addEventListener( 'click', function () {
				if ( ! window.confirm( cfg.i18n.confirmDeleteAll ) ) {
					return;
				}
				var confirmText = window.prompt( cfg.i18n.deleteAllPrompt, '' );
				if ( confirmText !== 'LÖSCHEN' ) {
					return;
				}
				ajax( 'swimtiming_delete_all_data', {
					nonce: cfg.adminNonce,
					confirm: confirmText,
				} ).then( function ( res ) {
					if ( res.success ) {
						window.alert( cfg.i18n.deleteAllDone );
						loadStarters();
					} else {
						window.alert( res.data && res.data.message ? res.data.message : cfg.i18n.error );
					}
				} );
			} );
		}

		loadStarters();
	}

	/* ---------------- Public area (eigene Zeiten + Startzeiten-Übersicht) ---------------- */

	function initPublic() {
		var root = qs( '#swimtiming-public' );
		if ( ! root ) {
			return;
		}

		initAllTimeInputs( root );

		// Live-Uhrzeit (Berlin), sekündlich aktualisiert.
		var clockEl = qs( '#swimtiming-current-time' );
		function tickClock() {
			if ( ! clockEl ) {
				return;
			}
			var t = getBerlinTimeParts();
			clockEl.textContent = t.hour + ':' + t.minute + ':' + t.second;
		}
		tickClock();
		setInterval( tickClock, 1000 );

		var scheduleBody = qs( '#swimtiming-schedule-tbody' );
		var scheduleSearch = qs( '#swimtiming-schedule-search' );
		var scheduleRows = [];

		function renderSchedule() {
			if ( ! scheduleBody ) {
				return;
			}
			var query = ( scheduleSearch && scheduleSearch.value ? scheduleSearch.value : '' ).trim().toLowerCase();
			var filtered = ! query ? scheduleRows : scheduleRows.filter( function ( row ) {
				var haystack = ( row.first_name + ' ' + row.last_name + ' ' + ( row.start_time || '' ) ).toLowerCase();
				return haystack.indexOf( query ) !== -1;
			} );

			if ( ! filtered.length ) {
				scheduleBody.innerHTML = '<tr><td colspan="3" class="swimtiming-empty">–</td></tr>';
				return;
			}

			var nowKey = clockSortKey( getBerlinTimeParts().hour + ':' + getBerlinTimeParts().minute );

			scheduleBody.innerHTML = '';
			filtered.forEach( function ( row ) {
				var tr = document.createElement( 'tr' );
				var rowKey = clockSortKey( row.start_time );
				if ( null !== rowKey && null !== nowKey && Math.abs( rowKey - nowKey ) <= 30 ) {
					tr.className = 'swimtiming-row-soon';
				}
				tr.innerHTML =
					'<td data-label="Staffel">' + teamBadge( row.team ) + '</td>' +
					'<td data-label="Name">' + esc( row.first_name ) + ' ' + esc( row.last_name ) + '</td>' +
					'<td data-label="Startzeit">' + esc( formatTime( row.start_time ) ) + '</td>';
				scheduleBody.appendChild( tr );
			} );
		}

		function loadSchedule() {
			ajax( 'swimtiming_public_schedule', { nonce: cfg.publicNonce } ).then( function ( res ) {
				scheduleRows = ( res.success && res.data.schedule ) ? res.data.schedule : [];
				renderSchedule();
			} );
		}

		if ( scheduleBody ) {
			loadSchedule();

			if ( scheduleSearch ) {
				scheduleSearch.addEventListener( 'input', renderSchedule );
			}

			// Holt die Startzeiten regelmäßig neu vom Server, damit
			// Änderungen (z. B. neu berechnete Folgezeiten nach einer
			// Endzeit-Eintragung) automatisch sichtbar werden, ohne dass
			// jemand die Seite manuell neu laden muss.
			setInterval( loadSchedule, 15000 );
		}

		var form = qs( '#swimtiming-lookup-form' );
		var resultBox = qs( '#swimtiming-public-result' );
		var errorBox = qs( '#swimtiming-public-error' );

		form.addEventListener( 'submit', function ( e ) {
			e.preventDefault();
			errorBox.hidden = true;
			resultBox.hidden = true;

			var firstName = form.elements.first_name.value.trim();
			var lastName = form.elements.last_name.value.trim();
			var startTime = form.elements.start_time.value;

			if ( ! firstName || ! lastName || ! startTime ) {
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
							'<td data-label="#">' + split.split_number + '</td>' +
							'<td data-label="Zeit">' + esc( formatTime( split.split_time ) ) + '</td>' +
							'<td data-label="Kommentar">' + esc( split.comment || '' ) + '</td>';
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

	/* ---------------- Entry area (QR-Code, ohne Anmeldung) ---------------- */

	function initEntry() {
		var root = qs( '#swimtiming-entry' );
		if ( ! root ) {
			return;
		}

		initAllTimeInputs( root );

		var form = qs( '#swimtiming-entry-form' );
		var searchInput = qs( '#swimtiming-entry-search' );
		var suggestionsBox = qs( '#swimtiming-entry-suggestions' );
		var starterIdField = qs( '#swimtiming-entry-starter-id' );
		var selectedBox = qs( '#swimtiming-entry-selected' );
		var selectedName = qs( '#swimtiming-entry-selected-name' );
		var clearBtn = qs( '#swimtiming-entry-clear' );
		var successBox = qs( '#swimtiming-entry-success' );
		var errorBox = qs( '#swimtiming-entry-error' );
		var timeField = qs( '[data-name="entry_time"]', form );

		function clearSelection() {
			starterIdField.value = '';
			selectedName.textContent = '';
			selectedBox.hidden = true;
			searchInput.value = '';
			searchInput.hidden = false;
			searchInput.focus();
		}

		function selectStarter( starter ) {
			starterIdField.value = starter.id;
			selectedName.textContent = starter.first_name + ' ' + starter.last_name + ' (' + teamLabel( starter.team ) + ')';
			selectedBox.hidden = false;
			searchInput.hidden = true;
			suggestionsBox.hidden = true;
			suggestionsBox.innerHTML = '';
			qs( 'input', timeField ).focus();
		}

		clearBtn.addEventListener( 'click', clearSelection );

		var searchTimer;
		searchInput.addEventListener( 'input', function () {
			clearTimeout( searchTimer );
			var query = searchInput.value.trim();
			if ( query.length < 2 ) {
				suggestionsBox.hidden = true;
				suggestionsBox.innerHTML = '';
				return;
			}
			searchTimer = setTimeout( function () {
				ajax( 'swimtiming_public_search_starters', { nonce: cfg.publicNonce, query: query } ).then( function ( res ) {
					if ( ! res.success || ! res.data.results.length ) {
						suggestionsBox.innerHTML = '';
						suggestionsBox.hidden = true;
						return;
					}
					suggestionsBox.innerHTML = '';
					res.data.results.forEach( function ( starter ) {
						var item = document.createElement( 'button' );
						item.type = 'button';
						item.className = 'swimtiming-suggestion-item';
						item.innerHTML = esc( starter.first_name ) + ' ' + esc( starter.last_name ) + ' ' + teamBadge( starter.team );
						item.addEventListener( 'click', function () {
							selectStarter( starter );
						} );
						suggestionsBox.appendChild( item );
					} );
					suggestionsBox.hidden = false;
				} );
			}, 250 );
		} );

		document.addEventListener( 'click', function ( e ) {
			if ( ! root.contains( e.target ) ) {
				return;
			}
			if ( e.target !== searchInput && ! suggestionsBox.contains( e.target ) ) {
				suggestionsBox.hidden = true;
			}
		} );

		var typeSelect = qs( '#swimtiming-entry-type' );

		form.addEventListener( 'submit', function ( e ) {
			e.preventDefault();
			successBox.hidden = true;
			errorBox.hidden = true;

			if ( ! starterIdField.value ) {
				errorBox.textContent = cfg.i18n.pleaseSelectStarter;
				errorBox.hidden = false;
				return;
			}

			var time = getTimeInputValue( timeField );
			var isEnd = 'end' === typeSelect.value;

			var action = isEnd ? 'swimtiming_public_submit_end_time' : 'swimtiming_public_add_split';
			var payload = { nonce: cfg.publicNonce, starter_id: starterIdField.value };
			if ( isEnd ) {
				payload.end_time = time;
			} else {
				payload.split_time = time;
			}

			ajax( action, payload ).then( function ( res ) {
				if ( ! res.success ) {
					errorBox.textContent = res.data && res.data.message ? res.data.message : cfg.i18n.error;
					errorBox.hidden = false;
					return;
				}

				var msg;
				if ( isEnd ) {
					msg = cfg.i18n.entrySaved + ': ' + res.data.starter.first_name + ' ' + res.data.starter.last_name + ' – ' + formatTime( res.data.starter.end_time );
					if ( res.data.next ) {
						msg += ' · ' + cfg.i18n.nextStart + ': ' + res.data.next.first_name + ' ' + res.data.next.last_name + ' ' + formatTime( res.data.next.start_time );
					}
				} else {
					msg = cfg.i18n.entrySaved + ': ' + res.data.starter.first_name + ' ' + res.data.starter.last_name + ' – ' + formatTime( time );
				}
				successBox.textContent = msg;
				successBox.hidden = false;

				clearSelection();
				setTimeInputValue( timeField, '' );
			} );
		} );
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		initAdmin();
		initPublic();
		initEntry();
	} );
}() );
