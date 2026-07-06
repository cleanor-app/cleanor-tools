/* Cleanor Tools — settings screen: test-connection button. */
( function () {
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
