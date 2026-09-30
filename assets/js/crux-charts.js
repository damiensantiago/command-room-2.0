( function () {
	function colorWithAlpha( hex, alpha ) {
		var r = parseInt( hex.slice( 1, 3 ), 16 );
		var g = parseInt( hex.slice( 3, 5 ), 16 );
		var b = parseInt( hex.slice( 5, 7 ), 16 );
		return 'rgba(' + r + ',' + g + ',' + b + ',' + alpha + ')';
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		var dataEl = document.getElementById( 'cmdroom-crux-data' );
		var canvases = document.querySelectorAll( '.cmdroom-crux-chart' );
		if ( ! dataEl || ! canvases.length || typeof Chart === 'undefined' ) {
			return;
		}

		var data = JSON.parse( dataEl.textContent );
		var groups = data.groups || {};

		canvases.forEach( function ( canvas ) {
			var metric = canvas.getAttribute( 'data-cr-crux-metric' );
			var labels = null;
			var datasets = [];

			Object.keys( groups ).forEach( function ( key ) {
				var group = groups[ key ];
				if ( ! labels ) {
					labels = group.periods;
				}
				datasets.push( {
					label: group.label,
					data: group[ metric ],
					borderColor: group.color,
					backgroundColor: colorWithAlpha( group.color, 0.1 ),
					spanGaps: true,
					tension: 0.25,
					pointRadius: 2,
				} );
			} );

			new Chart( canvas.getContext( '2d' ), {
				type: 'line',
				data: { labels: labels || [], datasets: datasets },
				options: {
					responsive: true,
					interaction: { mode: 'index', intersect: false },
					scales: { y: { beginAtZero: true } },
				},
			} );
		} );
	} );
} )();
