/* Cleanor Tools — CleanUp screen. Analyze reclaimable space, then delete with progress. */
( function () {
	function post( action ) {
		var body = new FormData();
		body.append( 'action', action );
		body.append( '_ajax_nonce', CleanorCleanup.nonce );
		return fetch( CleanorCleanup.ajaxUrl, { method: 'POST', body: body, credentials: 'same-origin' } )
			.then( function ( r ) { return r.json(); } );
	}

	function human( b ) {
		b = b || 0;
		if ( b < 1024 ) { return b + ' B'; }
		var u = [ 'KB', 'MB', 'GB' ], i = -1;
		do { b /= 1024; i++; } while ( b >= 1024 && i < u.length - 1 );
		return ( Math.round( b * 10 ) / 10 ) + ' ' + u[ i ];
	}

	var el = function ( id ) { return document.getElementById( id ); };

	var backupsTotal = 0, orphansTotal = 0, scaledTotal = 0;

	function analyze() {
		return post( 'cleanor_cleanup_analyze' ).then( function ( j ) {
			var d = ( j && j.data ) || {};
			var bC = ( d.backups && d.backups.count ) || 0, bB = ( d.backups && d.backups.bytes ) || 0;
			var oC = ( d.orphans && d.orphans.count ) || 0, oB = ( d.orphans && d.orphans.bytes ) || 0;
			var sC = ( d.scaled && d.scaled.count ) || 0, sB = ( d.scaled && d.scaled.bytes ) || 0;
			backupsTotal = bC;
			orphansTotal = oC;
			scaledTotal = sC;

			el( 'cleanor-reclaim' ).textContent = human( bB + oB + sB );
			el( 'cleanor-cnt-backups' ).textContent = bC.toLocaleString();
			el( 'cleanor-cnt-orphans' ).textContent = oC.toLocaleString();
			el( 'cleanor-cnt-scaled' ).textContent = sC.toLocaleString();

			el( 'cleanor-del-backups' ).disabled = bC === 0;
			el( 'cleanor-del-orphans' ).disabled = oC === 0;
			el( 'cleanor-del-scaled' ).disabled = sC === 0;
			el( 'cleanor-del-backups-size' ).textContent = bC ? ( '· ' + human( bB ) ) : '';
			el( 'cleanor-del-orphans-size' ).textContent = oC ? ( '· ' + human( oB ) ) : '';
			el( 'cleanor-del-scaled-size' ).textContent = sC ? ( '· ' + human( sB ) ) : '';

			if ( bC === 0 && oC === 0 && sC === 0 ) {
				el( 'cleanor-reclaim' ).textContent = CleanorCleanup.tidy;
			}
		} );
	}

	function runDelete( opts ) {
		// opts: { btn, bar, status, action, total, isDone, valueOf }
		opts.btn.disabled = true;
		opts.status.textContent = opts.startText;
		var freed = 0, count = 0;
		if ( opts.total > 0 ) { opts.bar.style.display = 'block'; opts.bar.max = opts.total; opts.bar.value = 0; }
		function step() {
			post( opts.action ).then( function ( j ) {
				if ( ! j || ! j.data ) { opts.status.textContent = CleanorCleanup.error; opts.btn.disabled = false; return; }
				var d = j.data;
				count += ( d.done || d.deleted || 0 );
				freed += ( d.freed || 0 );
				opts.bar.value = opts.valueOf( count, d );
				opts.status.innerHTML = '<b>' + count.toLocaleString() + '</b> ' + CleanorCleanup.removedWord + ' · ' + human( freed ) + ' ' + CleanorCleanup.freedWord;
				if ( opts.isDone( d ) ) {
					opts.bar.value = opts.bar.max;
					if ( count === 0 ) { opts.status.textContent = CleanorCleanup.noneWord; }
					analyze();
					return;
				}
				step();
			} ).catch( function () { opts.status.textContent = CleanorCleanup.error; opts.btn.disabled = false; } );
		}
		step();
	}

	function postId( action, id ) {
		var body = new FormData();
		body.append( 'action', action );
		body.append( 'id', id );
		body.append( '_ajax_nonce', CleanorCleanup.nonce );
		return fetch( CleanorCleanup.ajaxUrl, { method: 'POST', body: body, credentials: 'same-origin' } ).then( function ( r ) { return r.json(); } );
	}

	function runList( opts ) {
		// opts: { btn, bar, status, listAction, oneAction, doneWord, startText }
		opts.btn.disabled = true;
		opts.status.textContent = opts.startText;
		post( opts.listAction ).then( function ( j ) {
			var ids = ( j && j.data && j.data.ids ) || [];
			if ( ! ids.length ) { opts.status.textContent = CleanorCleanup.noneWord; opts.btn.disabled = false; return; }
			opts.bar.style.display = 'block';
			opts.bar.max = ids.length;
			var done = 0;
			function next() {
				if ( ! ids.length ) {
					opts.status.innerHTML = '<b>' + done.toLocaleString() + '</b> ' + opts.doneWord + '.';
					opts.btn.disabled = false;
					return;
				}
				postId( opts.oneAction, ids.shift() ).then( function () {
					done++; opts.bar.value = done;
					opts.status.innerHTML = '<b>' + done.toLocaleString() + '</b> / ' + opts.bar.max.toLocaleString();
					next();
				} ).catch( function () { done++; opts.bar.value = done; next(); } );
			}
			next();
		} );
	}

	var backupsBtn = el( 'cleanor-del-backups' );
	if ( backupsBtn ) {
		backupsBtn.addEventListener( 'click', function ( e ) {
			e.preventDefault();
			if ( ! window.confirm( CleanorCleanup.confirmBackups ) ) { return; }
			runDelete( {
				btn: backupsBtn,
				bar: el( 'cleanor-del-backups-bar' ),
				status: el( 'cleanor-del-backups-status' ),
				action: 'cleanor_cleanup_backups',
				total: backupsTotal,
				startText: CleanorCleanup.working,
				isDone: function ( d ) { return ( d.remaining || 0 ) <= 0 || ( d.done || 0 ) === 0; },
				valueOf: function ( count, d ) { return backupsTotal - ( d.remaining || 0 ); }
			} );
		} );
	}

	var orphansBtn = el( 'cleanor-del-orphans' );
	if ( orphansBtn ) {
		orphansBtn.addEventListener( 'click', function ( e ) {
			e.preventDefault();
			runDelete( {
				btn: orphansBtn,
				bar: el( 'cleanor-del-orphans-bar' ),
				status: el( 'cleanor-del-orphans-status' ),
				action: 'cleanor_cleanup_orphans',
				total: orphansTotal,
				startText: CleanorCleanup.scanning,
				isDone: function ( d ) { return ( d.deleted || 0 ) === 0; },
				valueOf: function ( count ) { return count; }
			} );
		} );
	}

	var scaledBtn = el( 'cleanor-del-scaled' );
	if ( scaledBtn ) {
		scaledBtn.addEventListener( 'click', function ( e ) {
			e.preventDefault();
			if ( ! window.confirm( CleanorCleanup.confirmScaled ) ) { return; }
			runDelete( {
				btn: scaledBtn,
				bar: el( 'cleanor-del-scaled-bar' ),
				status: el( 'cleanor-del-scaled-status' ),
				action: 'cleanor_cleanup_scaled',
				total: scaledTotal,
				startText: CleanorCleanup.working,
				isDone: function ( d ) { return ( d.deleted || 0 ) === 0; },
				valueOf: function ( count ) { return count; }
			} );
		} );
	}

	var regenBtn = el( 'cleanor-regen-start' );
	if ( regenBtn ) {
		regenBtn.addEventListener( 'click', function ( e ) {
			e.preventDefault();
			runList( {
				btn: regenBtn,
				bar: el( 'cleanor-regen-bar' ),
				status: el( 'cleanor-regen-status' ),
				listAction: 'cleanor_regen_list',
				oneAction: 'cleanor_regen_one',
				doneWord: CleanorCleanup.regenerated,
				startText: CleanorCleanup.regenerating
			} );
		} );
	}

	var resetBtn = el( 'cleanor-reset-start' );
	if ( resetBtn ) {
		resetBtn.addEventListener( 'click', function ( e ) {
			e.preventDefault();
			if ( ! window.confirm( CleanorCleanup.confirmReset ) ) { return; }
			resetBtn.disabled = true;
			el( 'cleanor-reset-status' ).textContent = CleanorCleanup.resetting;
			post( 'cleanor_cleanup_reset' ).then( function () {
				el( 'cleanor-reset-status' ).textContent = CleanorCleanup.resetDone;
				resetBtn.disabled = false;
				analyze();
			} ).catch( function () {
				el( 'cleanor-reset-status' ).textContent = CleanorCleanup.error;
				resetBtn.disabled = false;
			} );
		} );
	}

	// --- Unused media: scan (returns ids), confirm, then delete by id ---------
	var unusedIds = [];
	var scanUnusedBtn = el( 'cleanor-scan-unused' );
	var confirmBox = el( 'cleanor-unused-confirm' );
	var delUnusedBtn = el( 'cleanor-del-unused' );
	var unusedSummary = el( 'cleanor-unused-summary' );

	if ( scanUnusedBtn ) {
		scanUnusedBtn.addEventListener( 'click', function ( e ) {
			e.preventDefault();
			scanUnusedBtn.disabled = true;
			unusedSummary.textContent = CleanorCleanup.scanningUnused;
			post( 'cleanor_cleanup_scan_unused' ).then( function ( j ) {
				scanUnusedBtn.disabled = false;
				var d = ( j && j.data ) || {};
				unusedIds = d.ids || [];
				if ( unusedIds.length > 0 ) {
					unusedSummary.innerHTML = '<b>' + unusedIds.length.toLocaleString() + '</b> ' + CleanorCleanup.unusedWord + ' &middot; ' + human( d.bytes || 0 );
					el( 'cleanor-unused-confirm-row' ).style.display = '';
				} else {
					unusedSummary.textContent = CleanorCleanup.noneWord;
					el( 'cleanor-unused-confirm-row' ).style.display = 'none';
					if ( confirmBox ) { confirmBox.checked = false; }
					if ( delUnusedBtn ) { delUnusedBtn.disabled = true; }
				}
			} ).catch( function () { scanUnusedBtn.disabled = false; unusedSummary.textContent = CleanorCleanup.error; } );
		} );
	}

	if ( confirmBox && delUnusedBtn ) {
		confirmBox.addEventListener( 'change', function () {
			delUnusedBtn.disabled = ! confirmBox.checked || unusedIds.length === 0;
		} );
	}

	if ( delUnusedBtn ) {
		delUnusedBtn.addEventListener( 'click', function ( e ) {
			e.preventDefault();
			if ( ! confirmBox || ! confirmBox.checked || unusedIds.length === 0 ) { return; }
			delUnusedBtn.disabled = true;
			if ( scanUnusedBtn ) { scanUnusedBtn.disabled = true; }
			var bar = el( 'cleanor-del-unused-bar' ), status = el( 'cleanor-del-unused-status' );
			var ids = unusedIds.slice();
			bar.style.display = 'block';
			bar.max = ids.length;
			bar.value = 0;
			var done = 0, freed = 0;
			function next() {
				if ( ! ids.length ) {
					status.innerHTML = '<b>' + done.toLocaleString() + '</b> ' + CleanorCleanup.trashedWord + ' &middot; ' + human( freed ) + ' ' + CleanorCleanup.reclaimableWord;
					el( 'cleanor-unused-confirm-row' ).style.display = 'none';
					confirmBox.checked = false;
					unusedIds = [];
					if ( scanUnusedBtn ) { scanUnusedBtn.disabled = false; }
					analyze();
					return;
				}
				postId( 'cleanor_cleanup_del_unused', ids.shift() ).then( function ( j ) {
					done++;
					bar.value = done;
					if ( j && j.data && j.data.freed ) { freed += j.data.freed; }
					status.innerHTML = '<b>' + done.toLocaleString() + '</b> / ' + bar.max.toLocaleString();
					next();
				} ).catch( function () { done++; bar.value = done; next(); } );
			}
			next();
		} );
	}

	analyze();
}() );
