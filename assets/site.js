/* Helpdesk Hero website: theme, menu, help center search, screenshot zoom. */
( function () {
	'use strict';

	var root = document.documentElement;

	/* Theme ------------------------------------------------------------ */
	function store( key, value ) {
		try {
			if ( value === undefined ) {
				return localStorage.getItem( key );
			}
			localStorage.setItem( key, value );
		} catch ( e ) {}
		return null;
	}

	var saved = store( 'hdh-site-theme' );
	if ( saved === 'light' || saved === 'dark' ) {
		root.dataset.theme = saved;
	}

	function isDark() {
		return root.dataset.theme
			? root.dataset.theme === 'dark'
			: window.matchMedia( '(prefers-color-scheme: dark)' ).matches;
	}

	function syncThemeButton( btn ) {
		btn.setAttribute( 'aria-pressed', isDark() ? 'true' : 'false' );
	}

	document.querySelectorAll( '[data-theme-toggle]' ).forEach( function ( btn ) {
		syncThemeButton( btn );
		btn.addEventListener( 'click', function () {
			root.dataset.theme = isDark() ? 'light' : 'dark';
			store( 'hdh-site-theme', root.dataset.theme );
			syncThemeButton( btn );
		} );
	} );

	/* Mobile menu ------------------------------------------------------ */
	var menuBtn = document.querySelector( '[data-menu-toggle]' );
	var nav = document.getElementById( 'site-nav' );
	if ( menuBtn && nav ) {
		menuBtn.addEventListener( 'click', function () {
			var open = nav.classList.toggle( 'is-open' );
			menuBtn.setAttribute( 'aria-expanded', open ? 'true' : 'false' );
		} );
		document.addEventListener( 'keydown', function ( e ) {
			if ( e.key === 'Escape' && nav.classList.contains( 'is-open' ) ) {
				nav.classList.remove( 'is-open' );
				menuBtn.setAttribute( 'aria-expanded', 'false' );
				menuBtn.focus();
			}
		} );
	}

	/* Help center search ----------------------------------------------- */
	var base = document.body.getAttribute( 'data-root' ) || '';

	function escapeHtml( s ) {
		return String( s ).replace( /[&<>"]/g, function ( c ) {
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[ c ];
		} );
	}

	function search( q ) {
		var index = window.HDH_SEARCH || [];
		var words = q.toLowerCase().split( /\s+/ ).filter( Boolean );
		if ( ! words.length ) {
			return [];
		}
		return index
			.map( function ( item ) {
				var title = ( item.t + ' ' + item.s ).toLowerCase();
				var body = item.x.toLowerCase();
				var score = 0;
				for ( var i = 0; i < words.length; i++ ) {
					var w = words[ i ];
					var inTitle = title.indexOf( w ) !== -1;
					var inBody = body.indexOf( w ) !== -1;
					if ( ! inTitle && ! inBody ) {
						return null;
					}
					score += ( inTitle ? 10 : 0 ) + ( inBody ? 2 : 0 );
				}
				if ( item.s === '' ) {
					score += 3;
				}
				return { item: item, score: score };
			} )
			.filter( Boolean )
			.sort( function ( a, b ) {
				return b.score - a.score;
			} )
			.slice( 0, 8 );
	}

	function snippet( text, q ) {
		var w = q.toLowerCase().split( /\s+/ ).filter( Boolean )[ 0 ] || '';
		var at = text.toLowerCase().indexOf( w );
		var start = Math.max( 0, at - 50 );
		var s = ( start > 0 ? '…' : '' ) + text.slice( start, start + 150 ) + ( text.length > start + 150 ? '…' : '' );
		return escapeHtml( s );
	}

	document.querySelectorAll( '[data-search]' ).forEach( function ( box ) {
		var input = box.querySelector( 'input' );
		var list = box.querySelector( '.search-results' );
		var active = -1;

		function links() {
			return list.querySelectorAll( 'a' );
		}

		function setActive( i ) {
			var all = links();
			all.forEach( function ( a ) {
				a.classList.remove( 'is-active' );
				a.removeAttribute( 'aria-selected' );
			} );
			if ( all[ i ] ) {
				all[ i ].classList.add( 'is-active' );
				all[ i ].setAttribute( 'aria-selected', 'true' );
				input.setAttribute( 'aria-activedescendant', all[ i ].id );
			} else {
				input.removeAttribute( 'aria-activedescendant' );
			}
			active = i;
		}

		function render() {
			var q = input.value.trim();
			active = -1;
			if ( q.length < 2 ) {
				list.hidden = true;
				input.setAttribute( 'aria-expanded', 'false' );
				return;
			}
			var results = search( q );
			list.innerHTML = results.length
				? results
						.map( function ( r, i ) {
							var it = r.item;
							return (
								'<li role="presentation"><a role="option" id="' + list.id + '-' + i + '" href="' + base + it.u + '">' +
								'<small>' + escapeHtml( it.t ) + '</small>' +
								'<b>' + escapeHtml( it.s || it.t ) + '</b>' +
								snippet( it.x, q ) +
								'</a></li>'
							);
						} )
						.join( '' )
				: '<li class="empty" role="presentation">No results for “' + escapeHtml( q ) + '”. Try another word, or browse the guides below.</li>';
			list.hidden = false;
			input.setAttribute( 'aria-expanded', 'true' );
			var status = box.querySelector( '[role="status"]' );
			if ( status ) {
				status.textContent = results.length + ( results.length === 1 ? ' result' : ' results' );
			}
		}

		input.addEventListener( 'input', render );
		input.addEventListener( 'focus', render );
		input.addEventListener( 'keydown', function ( e ) {
			var all = links();
			if ( e.key === 'ArrowDown' && all.length ) {
				e.preventDefault();
				setActive( ( active + 1 ) % all.length );
			} else if ( e.key === 'ArrowUp' && all.length ) {
				e.preventDefault();
				setActive( active <= 0 ? all.length - 1 : active - 1 );
			} else if ( e.key === 'Enter' && all.length ) {
				e.preventDefault();
				window.location.href = ( all[ active ] || all[ 0 ] ).href;
			} else if ( e.key === 'Escape' ) {
				list.hidden = true;
				input.setAttribute( 'aria-expanded', 'false' );
			}
		} );
		document.addEventListener( 'click', function ( e ) {
			if ( ! box.contains( e.target ) ) {
				list.hidden = true;
				input.setAttribute( 'aria-expanded', 'false' );
			}
		} );
	} );

	/* Savings calculator ----------------------------------------------- */
	var calc = document.querySelector( '[data-calc]' );
	if ( calc ) {
		var money = function ( n ) {
			return '$' + ( n >= 100 ? Math.round( n ).toLocaleString( 'en-US' ) : n.toFixed( 2 ) );
		};
		var inputs = {
			spend: calc.querySelector( '#calc-spend' ),
			repeat: calc.querySelector( '#calc-repeat' ),
			runaway: calc.querySelector( '#calc-runaway' ),
		};
		var update = function () {
			var spend = Number( inputs.spend.value );
			var repeat = Number( inputs.repeat.value ) / 100;
			var runaway = Number( inputs.runaway.value );
			calc.querySelector( '[for="calc-spend"] output' ).textContent = money( spend ) + ' / month';
			calc.querySelector( '[for="calc-repeat"] output' ).textContent = Math.round( repeat * 100 ) + '%';
			calc.querySelector( '[for="calc-runaway"] output' ).textContent = runaway + ( runaway === 1 ? ' time' : ' times' ) + ' / year';

			// Caching (Pro): repeats answered from cache, assuming 90% of repeats are cacheable.
			var cacheYear = spend * repeat * 0.9 * 12;
			// Budgets (free): a runaway plugin typically burns about a month's spend in days; a cap stops it.
			var runawayYear = spend * runaway;
			var total = cacheYear + runawayYear;

			calc.querySelector( '[data-out="total"]' ).textContent = money( total );
			calc.querySelector( '[data-out="cache"]' ).textContent = money( cacheYear );
			calc.querySelector( '[data-out="runaway"]' ).textContent = money( runawayYear );
			var payback = cacheYear > 0 ? 79 / ( cacheYear / 12 ) : Infinity;
			calc.querySelector( '[data-out="payback"]' ).textContent =
				payback === Infinity ? 'Not needed at this volume'
				: payback < 1 ? 'Within the first month'
				: payback > 12 ? 'Free plugin is enough'
				: 'About ' + Math.ceil( payback ) + ( Math.ceil( payback ) === 1 ? ' month' : ' months' );
		};
		Object.keys( inputs ).forEach( function ( k ) {
			inputs[ k ].addEventListener( 'input', update );
		} );
		update();
	}

	/* Screenshot zoom -------------------------------------------------- */
	var zoomable = document.querySelectorAll( '[data-zoom] img' );
	if ( zoomable.length ) {
		var box = document.createElement( 'div' );
		box.className = 'lightbox';
		box.hidden = true;
		box.setAttribute( 'role', 'dialog' );
		box.setAttribute( 'aria-modal', 'true' );
		box.setAttribute( 'aria-label', 'Enlarged screenshot. Press Escape to close.' );
		box.tabIndex = -1;
		var big = document.createElement( 'img' );
		box.appendChild( big );
		document.body.appendChild( box );
		var last = null;
		var close = function () {
			box.hidden = true;
			if ( last ) {
				last.focus();
			}
		};
		zoomable.forEach( function ( img ) {
			img.tabIndex = 0;
			img.setAttribute( 'role', 'button' );
			img.setAttribute( 'aria-label', ( img.alt || 'Screenshot' ) + ' (enlarge)' );
			var open = function () {
				last = img;
				big.src = img.currentSrc || img.src;
				big.alt = img.alt;
				box.hidden = false;
				box.focus();
			};
			img.addEventListener( 'click', open );
			img.addEventListener( 'keydown', function ( e ) {
				if ( e.key === 'Enter' || e.key === ' ' ) {
					e.preventDefault();
					open();
				}
			} );
		} );
		box.addEventListener( 'click', close );
		document.addEventListener( 'keydown', function ( e ) {
			if ( e.key === 'Escape' && ! box.hidden ) {
				close();
			}
		} );
	}

	/* Highlight the current section in tables of contents -------------- */
	var tocLinks = document.querySelectorAll( '[data-spy] a' );
	if ( tocLinks.length && 'IntersectionObserver' in window ) {
		var byId = {};
		tocLinks.forEach( function ( a ) {
			byId[ a.getAttribute( 'href' ).slice( 1 ) ] = a;
		} );
		var observer = new IntersectionObserver(
			function ( entries ) {
				entries.forEach( function ( entry ) {
					if ( entry.isIntersecting && byId[ entry.target.id ] ) {
						tocLinks.forEach( function ( a ) {
							a.removeAttribute( 'aria-current' );
						} );
						byId[ entry.target.id ].setAttribute( 'aria-current', 'true' );
					}
				} );
			},
			{ rootMargin: '-15% 0px -75% 0px' }
		);
		Object.keys( byId ).forEach( function ( id ) {
			var el = document.getElementById( id );
			if ( el ) {
				observer.observe( el );
			}
		} );
	}
} )();
