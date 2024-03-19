<?php

/*
 * 0-sandbox-o11y.php
 * ===================
 *
 * Provide more visibility into what your sandbox is doing
 * by adding logging messages to 0-sandbox.php.
 *
 * To use, place this file in wp-content/mu-plugins/,
 * then add this code to your 0-sandbox.php:
 *
 * sandbox_log_request( 1 );
 *
 * The number can be tweaked to provide more or less information.
 *   0 - Log each request on start.
 *   1 - Log each request on start and end, provide timings (overall, sql queries, memcache).
 *   2 - Log each request on start and end, provide timings (overall, sql queries, memcache), show hooks summary per-function.
 *       - Requires hook11.diff to be applied for full functionality.
 *   3 - Log each request on start and end, provide timings (overall, sql queries, memcache), show hooks summary per-function and per-hook.
 *       - Requires hook11.diff to be applied for full functionality.
 *
 * To stop all extra logging, delete or comment out the sandbox_log_request() call.
 *
 * Note the hook information can be of limited use. It might tell you that 'init' is slow, but that's hard
 * to do anything with because many functions are on 'init' and you don't know which one of them is slow.
 *
 * Look for a seperate patch on wp-includes/class-wp-hook.php to indicate the
 * specific hooked functions that are running.
 *
 * A function, sel() [stands for sandbox_error_log()] is provided to prefix your error log
 * messages with an indication of which process they're running on.
 *
 * ------ Version and Changelog ------
 *
 * Version: 0.4.0 released 2023-04-19
 *
 * Changelog: 0.1.1: Filtered out 99% of requests to notifications endpoint, hardcoded.
 * Changelog: 0.1.2: Added hooked_function_summary. Made notifications suppression configurable. Requires new hook patch.
 * Changelog: 0.2.0: Added endpoint file guesser. Guesses the file that serves the endpoint that made the request!
 *    Example:
 *    [active-promo e4a5] --> public-api.wordpress.com/rest/v1.1/me/active-promotions
 *    [active-promo e4a5] <--   668ms (cache= 73ms) [ 34 sql=   13ms]
 *    [active-promo e4a5]   f public.api/rest/wpcom-json-endpoints/class.wpcom-json-api-me-active-promotions-endpoint.php
 *    After the "<--" (End request) line, an "  f" (File) line is added, showing which file served the request.
 * Changelog: 0.3.0: Added selt("Message 1") for timings. Do some stuff, then selt("Message 2"), and automatically see the time elasped between the last two selt() messages.
 * Changelog: 0.4.0:
 *    - Removed WPCOM_Bench_Filters: It's not reliable as it creates too many distortions.
 *    - Reworked hook.diff (class-wp-hook.php) to introduce "banned hooks" which aren't measured:
 *      this is a breakthrough in measurement accuracy. Just make sure to do both steps:
 *      - sandbox_log_request( 2 ); in 0-sandbox.php
 *      - Manually patch class-wp-hook.php in wp-includes/ with hook.diff.
 *
 */

/* Ideas for future improvement:
 *  - Query summary from WPCOM_Debug_Bar_Query_Summary in wpcom-debug-bar-panels.php
 */

// Get the current URL. Optionally hide query: "public-api.wordpress.com/rest/v1.1/notifications/"
// Usually hide http_envelope=1 parameters, they're everywhere and have no useful signal.
function sandbox_get_link( $hide_query = false, $hide_http_envelope = true ) {
	$link      = "$_SERVER[HTTP_HOST]$_SERVER[REQUEST_URI]";
	$has_query = strpos( $link, '?' ) !== false;

	if ( $has_query && $hide_query ) {
		$link = substr( $link, 0, strpos( $link, '?' ) );
	} elseif ( $has_query && $hide_http_envelope ) {
		$url_part   = substr( $link, 0, strpos( $link, '?' ) );
		$query_part = substr( $link, strpos( $link, '?' ) + 1 );
		$query_part = preg_replace( '/(^|&)http_envelope=1&?/', '', $query_part );
		$query_part = preg_replace( '/(^|&)_envelope=1&?/', '', $query_part );
		if ( strlen( $query_part ) > 0 ) {
			$link = $url_part . '?' . $query_part;
		} else {
			$link = $url_part;
		}
	}
	return $link;
}

// Return the last part of the current url. For example, "notifications" for "public-api.wordpress.com/rest/v1.1/notifications/".
function sandbox_get_link_ending() {
	// Usually hide query strings: public-api.wordpress.com/rest/v1.1/notifications/?fields=id%2Cnote_hash&number=20 -> "notifications".
	$should_hide_query = true;
	// However, point out the ?service-worker requests.
	if ( strpos( $_SERVER['REQUEST_URI'], 'service-worker' ) !== false ) {
		$should_hide_query = false;
	}
	$link = sandbox_get_link( $should_hide_query );

	// Keep everything after the last slash, ignoring the trailing slash if it exists. Remove ".php".
	$link = rtrim( $link, '/' );
	$link = substr( $link, strrpos( $link, '/' ) + 1 );
	$link = preg_replace( '/.php$/', '', $link );
	return $link;
}

// Return a random hex string of specified length, like "8ea2".
function sandbox_hex_string( $strlen ) {
	return implode(
		array_map(
			function() {
				return dechex( mt_rand( 0, 15 ) );
			},
			array_fill( 0, $strlen, null )
		)
	);
}

// Error log, but put "[notification 49c]" before the message so we can identify the request we're in.
// Apply print_r if the $message is not a string.
function sandbox_error_log( $message, $prefix_space_replace = false ) {
	$ending = $GLOBALS['sandbox_req_link_ending'];
	$id     = $GLOBALS['sandbox_req_id'];

	$ending = str_pad( $ending, 12 );
	if ( strlen( $ending ) > 12 ) {
		$ending = substr( $ending, 0, 12 );
	}

	$prefix = '[' . $ending . ' ' . $id . ']';

	if ( gettype( $message ) !== 'string' ) {
		$message = print_r( $message, true );
	}

	if ( $prefix_space_replace !== false ) {
		$prefix = str_replace( ' ', $prefix_space_replace, $prefix );
	}
	error_log( $prefix . ' ' . $message );
}

function sel( $message ) {
	return sandbox_error_log( $message );
}

$GLOBALS['sandbox_start_time']     = microtime( true );
$GLOBALS['sandbox_last_selt_time'] = $GLOBALS['sandbox_start_time'];
$GLOBALS['sandbox_selt_num']       = 0;
function selt( $message, $do_log = true ) {
	if ( empty( $message ) ) {
		$message = 'Mark ' . $GLOBALS['sandbox_selt_num'] . ':';
		$GLOBALS['sandbox_selt_num'] += 1;
	}
	$now  = microtime( true );

	$elap       = round( ( $now - $GLOBALS['sandbox_last_selt_time'] ) * 1000, 2 );
	$elap_begin = round( ( $now - $GLOBALS['sandbox_start_time'] ) * 1000, 2 );

	if ( $do_log ) {
		ll("$message | $elap | $elap_begin");
	}

	$GLOBALS['sandbox_last_selt_time'] = microtime( true );
}

function sandbox_level_number_to_config( $level ) {
	switch ( $level ) {
		case 0:
			return array(
				'start_request'                   => true,
				'start_request_shorten_url'       => 100,
				'end_request'                     => false,
				'suppress_notifications_endpoint' => true,
				'hooked_function_summary'         => false,
				'hook_threshold'                  => 100,
			);
		case 1:
			return array(
				'start_request'                   => true,
				'start_request_shorten_url'       => 100,
				'end_request'                     => true,
				'suppress_notifications_endpoint' => true,
				'hooked_function_summary'         => false,
				'hook_threshold'                  => 100,
			);
		case 2:
			// You should probably also patch wp-includes/class-wp-hook.php if using level 2 or above!
			return array(
				'start_request'                   => true,
				'start_request_shorten_url'       => 100,
				'end_request'                     => true,
				'suppress_notifications_endpoint' => true,
				'hooked_function_summary'         => true,
				'hook_threshold'                  => 100,
			);
		case 3:
			return array(
				'start_request'                   => true,
				'start_request_shorten_url'       => 100,
				'end_request'                     => true,
				'suppress_notifications_endpoint' => false,
				'hooked_function_summary'         => true,
				'hook_threshold'                  => 100,
			);
	}
}

// Look through the stacktrace and see if we're in the middle of a v1 or v2wpcom
// callback. If so, try to guess which file is serving the endpoint, and store in
// $GLOBALS['sandbox_endpoint_file_guess'] if found.
function sandbox_guess_file_serving_endpoint( $in ) {
	if ( ! empty( $GLOBALS['sandbox_guess_file_serving_endpoint_ran'] ) ) {
		return $in;
	}

	$stack       = debug_backtrace();
	$stack_count = count( $stack );
	$last_file   = '';

	// Usually, the file being served is what's just before these two files in the stack. (Or is it after?)
	$just_before_v1       = 'jetpack-plugin/production/class.json-api.php';
	$just_before_v2_wpcom = 'rest-api/class-wp-rest-server.php';

	// Helper for debugging (Remember to remove the give up code at the end)
	/*
	for ( $j = 1; $j < $stack_count; $j++ ) {
		$infile = $stack[$j]['file'];
		sel("$j $infile");
	}
	*/
	for ( $i = 1; $i < $stack_count; $i++ ) {
		$file = $stack[ $i ]['file'];
		// False alarms; Finding these in the stacktrace mean we're outside the API callback context
		if (
			str_contains( $file, 'mu-plugins/email-verification.php' )
			|| str_contains( $file, 'entralized/centralize.php' )
			|| str_contains( $file, 'class-wpcom-initializer.php' )
		) {
			// Early return, we need to run through some more stack traces.
			return $in;
		}

		// Look for a match.
		if (
			str_contains( $file, $just_before_v1 )
			|| str_contains( $file, $just_before_v2_wpcom )
		) {
			// Two more false alarms; these files are incorrect detections.
			if (
				str_contains( $last_file, 'wp-includes/plugin.php' )
				|| str_contains( $last_file, 'wp-includes/ms-blogs.php' )
				|| str_contains( $last_file, 'class.wpcom-json-api.php' )
				|| str_contains( $last_file, 'class.json-api.php' )
			) {
				// Early return, we need to run through some more stack traces.
				return $in;
			}

			$last_file = str_replace( '/home/wpcom/public_html/', '', $last_file );

			$GLOBALS['sandbox_endpoint_file_guess'] = $last_file;
			break;
		}
		$last_file = $file;
	}

	// We either found it or we didn't, but let's try to stop our hook.
	$GLOBALS['sandbox_guess_file_serving_endpoint_ran'] = true;
	remove_action( 'switch_blog', 'sandbox_guess_file_serving_endpoint' );
	remove_action( 'the_content', 'sandbox_guess_file_serving_endpoint' );
	remove_action( 'query', 'sandbox_guess_file_serving_endpoint' );
	return $in;
}

function sandbox_add_hooks_for_guess_file_serving_endpoint() {
	// Hook on a bunch of generic stuff.
	// There's no clear hook to call, we just need some sort of
	// activity that the API callback will do.
	// The hook will unregister itself pretty quickly.
	add_action( 'switch_blog', 'sandbox_guess_file_serving_endpoint' );
	add_action( 'the_content', 'sandbox_guess_file_serving_endpoint' );
	add_action( 'query', 'sandbox_guess_file_serving_endpoint' );
}

// Log a "-->" message right now, and queue up a "<--" message to be logged at shutdown.
// The shutdown query includes elapsed time, number of SQL queries, and time taken by those.
function sandbox_log_request( $level = 1 ) {
	$c = sandbox_level_number_to_config( $level );

	$link = sandbox_get_link();

	$GLOBALS['sandbox_req_link']        = $link;
	$GLOBALS['sandbox_req_link_ending'] = sandbox_get_link_ending();
	$GLOBALS['sandbox_req_id']          = sandbox_hex_string( 4 );

	if ( $c['suppress_notifications_endpoint'] && $GLOBALS['sandbox_req_link_ending'] === 'notifications' ) {
		if ( rand( 0, 99 ) === 42 ) {
			sandbox_error_log( 'Reminder: 99% of requests to the notifications endpoint are being suppressed' );
		} else {
			$GLOBALS['sandbox_req_hook_logging_suppressed'] = true;
			return;
		}
	}

	if ( $c['start_request'] ) {
		if ( $c['start_request_shorten_url'] ) {
			$len = $c['start_request_shorten_url'];
			$shortlink = strlen( $link ) > $len ? substr( $link, 0, ( $len - 3 ) ) . '...' : $link;
			sandbox_error_log( "--> $shortlink" );
		} else {
			sandbox_error_log( "--> $link" );
		}
	}

	if ( $c['end_request'] ) {
		sandbox_add_hooks_for_guess_file_serving_endpoint();
	}

	$start = microtime( true );
	if ( $c['end_request'] ) {
		register_shutdown_function(
			function() use ( $start, $c ) {
				global $wpdb, $wp_object_cache;

				$end     = microtime( true );
				$elapsed = round( ( $end - $start ) * 1000 );

				$elapsed_str = "{$elapsed}ms";
				$elapsed_str = str_pad( $elapsed_str, 7, ' ', STR_PAD_LEFT );

				$nq = $wpdb->num_queries;
				$sql_str = '';
				if ( $nq > 0 ) {
					$nq_str = str_pad( $nq, 3, ' ', STR_PAD_LEFT );
					$querytime = 0;
					foreach ( $wpdb->queries as $q ) {
						$querytime += $q['elapsed'];
					}
					$querytime = round( $querytime * 1000 );
					$querytime_str = str_pad( "{$querytime}ms", 7, ' ', STR_PAD_LEFT );

					$sql_str = " [{$nq_str} sql=$querytime_str]";
				}
				$memcache_time = round( $wp_object_cache->time_total * 1000 );
				$memcache_str = str_pad( "${memcache_time}ms", 5, ' ', STR_PAD_LEFT );
				$cache_str = " (cache=$memcache_str)";

				// Make requests longer than 10s, or 2s, visually stand out
				$prefix_space_replace = false;
				if ( $elapsed > 10000 ) {
					$prefix_space_replace = '#';
				} elseif ( $elapsed > 2000 ) {
					$prefix_space_replace = '.';
				}
				if ( $c['end_request'] ) {
					$cache_stats = json_encode( $wp_object_cache->stats );
					$z = $GLOBALS['time_used'];
					sandbox_error_log( "<-- {$elapsed_str}{$cache_str}{$sql_str} $cache_stats $z", $prefix_space_replace );
					/* sel( json_encode( $wp_object_cache->group_ops ) ); */
					if ( ! empty( $GLOBALS['sandbox_endpoint_file_guess'] ) ) {
						$file = $GLOBALS['sandbox_endpoint_file_guess'];
						sandbox_error_log( "  f $file" );
					}
				}

				do_action( 'sandbox_end_of_request' );

				// Optional: Hook recap
				$long = $elapsed > 500;
				$do_summary = $long;

				/* sel("Took this many measurements: " .  $GLOBALS['measure_count']); */
				if ( $do_summary && $c['hooked_function_summary'] && ! empty( $GLOBALS['sandbox_hook_time'] ) && ! empty( $GLOBALS['sandbox_hook_count'] ) ) {
					$GLOBALS['sandbox_hook_time'] = array_map( 'round', $GLOBALS['sandbox_hook_time'] );

					$display_hook = function( $name, $count, $time ) {
						[$hook, $function] = explode( ' => ', $name );

						$hookstr  = str_pad( "$hook", 20 );
						$timestr  = str_pad( "{$time}ms", 6, ' ', STR_PAD_LEFT );
						$countstr = str_pad( "${count}x", 10, ' ', STR_PAD_LEFT );
						sandbox_error_log( "    ⊆ $timestr $countstr [$hookstr] $function" );
					};

					// Count (Run most often):
					arsort( $GLOBALS['sandbox_hook_count'] );
					$most_called = array_slice( $GLOBALS['sandbox_hook_count'], 0, 10 );

					sandbox_error_log( '    === most called hooked functions ===' );
					foreach ( $most_called as $name => $count ) {
						$time = $GLOBALS['sandbox_hook_time'][ $name ];
						$display_hook( $name, $count, $time );
					}

					// Time (Slowest in aggregate):
					arsort( $GLOBALS['sandbox_hook_time'] );
					$slowest = array_slice( $GLOBALS['sandbox_hook_time'], 0, 12 );

					sandbox_error_log( '    === slowest hooked functions ===' );
					foreach ( $slowest as $name => $time ) {
						$count = $GLOBALS['sandbox_hook_count'][ $name ];
						$display_hook( $name, $count, $time );
					}
				}

				/* sel("Switched to the same blog: " . $GLOBALS['sandbox_switch_same_blog']); */
				/* sel("Switched to a different blog: " . $GLOBALS['sandbox_switch_diff_blog']); */
				/* sel("Time: " . $GLOBALS['sandbox_switch_time']); */
			}
		);
	}
}

