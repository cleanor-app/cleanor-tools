/* Cleanor Tools — Bulk screen. Runs optimize and restore, one item at a time. */
( function () {
	function post( body, nonce ) {
		body.append( '_ajax_nonce', nonce );
		return fetch( CleanorBulk.ajaxUrl, { method: 'POST', body: body, credentials: 'same-origin' } )
			.then( function ( r ) { return r.json(); } );
	}

	/**
	 * Generic one-at-a-time runner shared by optimize and restore.
	 *
	 * @param {Object} cfg  { startId, barId, statusId, nonce, listAction, oneAction,
	 *                        countKey, doneWord, emptyText, collectText }
	 */
	function runner( cfg ) {
		var start = document.getElementById( cfg.startId );
		if ( ! start ) {
			return;
		}
		var bar    = document.getElementById( cfg.barId );
		var status = document.getElementById( cfg.statusId );

		start.addEventListener( 'click', function ( e ) {
			e.preventDefault();
			start.disabled = true;
			status.textContent = cfg.collectText;
			var list = new FormData();
			list.append( 'action', cfg.listAction );
			post( list, cfg.nonce ).then( function ( j ) {
				var ids = ( j && j.data && j.data.ids ) || [];
				if ( ! ids.length ) {
					status.textContent = cfg.emptyText;
					start.disabled = false;
					return;
				}
				bar.style.display = 'block';
				bar.max = ids.length;
				var done = 0, ok = 0;
				function next() {
					if ( ! ids.length ) {
						status.innerHTML = '<b>' + done + '</b> ' + cfg.doneWord + ' (' + ok + ' ' + cfg.countKey + ').';
						start.disabled = false;
						return;
					}
					var id = ids.shift();
					var one = new FormData();
					one.append( 'action', cfg.oneAction );
					one.append( 'id', id );
					post( one, cfg.nonce ).then( function ( res ) {
						done++;
						bar.value = done;
						if ( res && res.data && ! res.data.skipped ) {
							ok++;
						}
						status.innerHTML = '<b>' + done + '</b> / ' + bar.max + ' &middot; ' + ok + ' ' + cfg.countKey;
						next();
					} ).catch( function () { done++; bar.value = done; next(); } );
				}
				next();
			} );
		} );
	}

	runner( {
		startId: 'cleanor-bulk-start',
		barId: 'cleanor-bulk-bar',
		statusId: 'cleanor-bulk-status',
		nonce: CleanorBulk.nonce,
		listAction: 'cleanor_bulk_list',
		oneAction: 'cleanor_bulk_one',
		countKey: CleanorBulk.optimized,
		doneWord: CleanorBulk.processed,
		emptyText: CleanorBulk.nothing,
		collectText: CleanorBulk.collecting
	} );

	runner( {
		startId: 'cleanor-restore-start',
		barId: 'cleanor-restore-bar',
		statusId: 'cleanor-restore-status',
		nonce: CleanorBulk.restoreNonce,
		listAction: 'cleanor_restore_list',
		oneAction: 'cleanor_restore_one',
		countKey: CleanorBulk.restored,
		doneWord: CleanorBulk.restoreDone,
		emptyText: CleanorBulk.noBackups,
		collectText: CleanorBulk.restoring
	} );
}() );
