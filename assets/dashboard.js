/* Cleanor Tools — Dashboard. Inline PageSpeed Insights score via the saved API key. */
( function () {
	var btn = document.getElementById( 'cleanor-psi-run' );
	if ( ! btn ) {
		return;
	}
	var out = document.getElementById( 'cleanor-psi-result' );

	btn.addEventListener( 'click', function ( e ) {
		e.preventDefault();
		btn.disabled = true;
		out.textContent = CleanorDash.testing;

		var body = new FormData();
		body.append( 'action', 'cleanor_psi' );
		body.append( '_ajax_nonce', CleanorDash.nonce );

		fetch( CleanorDash.ajaxUrl, { method: 'POST', body: body, credentials: 'same-origin' } )
			.then( function ( r ) { return r.json(); } )
			.then( function ( j ) {
				btn.disabled = false;
				if ( ! j || ! j.success ) {
					out.textContent = ( j && j.data && j.data.message ) || CleanorDash.error;
					return;
				}
				var s = j.data.score;
				var label = CleanorDash.scoreLabel.replace( '%s', '<b>' + ( s == null ? '?' : s ) + '</b>' );
				out.innerHTML = label + ( j.data.lcp ? ( ' &middot; LCP ' + j.data.lcp ) : '' );
			} )
			.catch( function () {
				btn.disabled = false;
				out.textContent = CleanorDash.error;
			} );
	} );
}() );
