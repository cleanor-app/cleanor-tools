/* Cleanor Tools — settings screen: compression presets + test-connection. */
( function () {
	// --- Compression presets -------------------------------------------------
	var PRESET_Q = { balanced: 80, aggressive: 62, lossless: 92 };
	var wrap = document.getElementById( 'cleanor-presets' );
	var qty  = document.getElementById( 'cleanor_quality' );

	if ( wrap && qty ) {
		wrap.addEventListener( 'change', function ( e ) {
			var input = e.target;
			if ( ! input || input.type !== 'radio' ) {
				return;
			}
			var preset = input.value;
			// Toggle the highlighted pill.
			var pills = wrap.querySelectorAll( '.cleanor-preset' );
			for ( var i = 0; i < pills.length; i++ ) {
				pills[ i ].classList.toggle( 'is-on', pills[ i ].getAttribute( 'data-preset' ) === preset );
			}
			// Custom lets the user edit quality; presets pin it.
			if ( preset === 'custom' ) {
				qty.disabled = false;
				qty.focus();
			} else {
				qty.disabled = true;
				if ( PRESET_Q[ preset ] ) {
					qty.value = PRESET_Q[ preset ];
				}
			}
		} );
	}

	// --- Test connection -----------------------------------------------------
	var btn = document.getElementById( 'cleanor-test-conn' );
	if ( ! btn ) {
		return;
	}
	btn.addEventListener( 'click', function ( e ) {
		e.preventDefault();
		var out = document.getElementById( 'cleanor-test-result' );
		out.textContent = CleanorSettings.testing;
		var data = new FormData();
		data.append( 'action', 'cleanor_test_connection' );
		data.append( '_ajax_nonce', CleanorSettings.nonce );
		fetch( CleanorSettings.ajaxUrl, { method: 'POST', body: data, credentials: 'same-origin' } )
			.then( function ( r ) { return r.json(); } )
			.then( function ( j ) { out.textContent = ( j.data && j.data.message ) ? j.data.message : JSON.stringify( j ); } )
			.catch( function ( err ) { out.textContent = String( err ); } );
	} );
}() );
