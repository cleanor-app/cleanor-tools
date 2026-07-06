/* Cleanor Tools — Bulk Optimize screen. Processes the Media Library one item at a time. */
( function () {
	var start = document.getElementById( 'cleanor-bulk-start' );
	if ( ! start ) {
		return;
	}
	var bar    = document.getElementById( 'cleanor-bulk-bar' );
	var status = document.getElementById( 'cleanor-bulk-status' );

	function post( body ) {
		body.append( '_ajax_nonce', CleanorBulk.nonce );
		return fetch( CleanorBulk.ajaxUrl, { method: 'POST', body: body, credentials: 'same-origin' } )
			.then( function ( r ) { return r.json(); } );
	}

	start.addEventListener( 'click', function ( e ) {
		e.preventDefault();
		start.disabled = true;
		status.textContent = CleanorBulk.collecting;
		var list = new FormData();
		list.append( 'action', 'cleanor_bulk_list' );
		post( list ).then( function ( j ) {
			var ids = ( j && j.data && j.data.ids ) || [];
			if ( ! ids.length ) {
				status.textContent = CleanorBulk.nothing;
				start.disabled = false;
				return;
			}
			bar.style.display = 'block';
			bar.max = ids.length;
			var done = 0, saved = 0;
			function next() {
				if ( ! ids.length ) {
					status.textContent = done + ' ' + CleanorBulk.processed;
					start.disabled = false;
					return;
				}
				var id = ids.shift();
				var one = new FormData();
				one.append( 'action', 'cleanor_bulk_one' );
				one.append( 'id', id );
				post( one ).then( function ( res ) {
					done++;
					bar.value = done;
					if ( res && res.data && typeof res.data.saved_pct !== 'undefined' ) {
						saved++;
					}
					status.textContent = done + ' / ' + bar.max + ', ' + saved + ' ' + CleanorBulk.optimized;
					next();
				} ).catch( function () { done++; bar.value = done; next(); } );
			}
			next();
		} );
	} );
}() );
