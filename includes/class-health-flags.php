<?php
/**
 * Rule-based health and conflict flags.
 *
 * @package Helpdesk_Hero
 */

defined( 'ABSPATH' ) || exit;

/**
 * Turns a diagnostics bundle into a short list of things support should look at first.
 * Works without AI; the AI assistant (when available) builds on these.
 */
final class Helpdesk_Hero_Health_Flags {

	/**
	 * Groups of plugins that do the same job and often clash when two are active.
	 *
	 * @return array
	 */
	public static function overlap_groups() {
		$groups = array(
			'page caching'   => array( 'wp-rocket', 'w3-total-cache', 'wp-super-cache', 'litespeed-cache', 'wp-fastest-cache', 'sg-cachepress', 'breeze', 'hummingbird-performance', 'cache-enabler', 'comet-cache', 'swift-performance-lite', 'wp-optimize', 'nitropack', 'flying-press' ),
			'SEO'            => array( 'wordpress-seo', 'wordpress-seo-premium', 'seo-by-rank-math', 'all-in-one-seo-pack', 'autodescription', 'wp-seopress', 'slim-seo', 'squirrly-seo' ),
			'security firewall' => array( 'wordfence', 'better-wp-security', 'all-in-one-wp-security-and-firewall', 'ninjafirewall', 'shield-security', 'defender-security' ),
			'SMTP / email sending' => array( 'wp-mail-smtp', 'post-smtp', 'easy-wp-smtp', 'fluent-smtp', 'smtp-mailer', 'gosmtp' ),
			'image optimization' => array( 'ewww-image-optimizer', 'wp-smushit', 'shortpixel-image-optimiser', 'imagify', 'optimole-wp', 'robin-image-optimizer' ),
			'asset minification' => array( 'autoptimize', 'fast-velocity-minify', 'wp-asset-clean-up', 'perfmatters' ),
		);

		/**
		 * Filters the groups of overlapping plugins.
		 *
		 * @param array $groups Label => plugin directory slugs.
		 */
		return (array) apply_filters( 'helpdesk_hero_overlap_groups', $groups );
	}

	/**
	 * Flags for a bundle, most severe first.
	 *
	 * @param array $data Diagnostics bundle.
	 * @return array[] Each: level (critical|warning|info), code, title, detail.
	 */
	public static function evaluate( array $data ) {
		$flags = array();
		$env   = $data['environment'] ?? null;
		$ext   = $data['extensions'] ?? null;

		if ( $env ) {
			if ( version_compare( $env['php'], '7.4', '<' ) ) {
				$flags[] = self::flag( 'critical', 'php_eol', __( 'PHP is very old', 'helpdesk-hero' ), sprintf( /* translators: %s: PHP version */ __( 'PHP %s no longer gets security fixes and many plugins no longer support it.', 'helpdesk-hero' ), $env['php'] ) );
			} elseif ( version_compare( $env['php'], '8.1', '<' ) ) {
				$flags[] = self::flag( 'warning', 'php_eol', __( 'PHP is out of support', 'helpdesk-hero' ), sprintf( /* translators: %s: PHP version */ __( 'PHP %s is end-of-life. Ask the host to move to PHP 8.2 or newer.', 'helpdesk-hero' ), $env['php'] ) );
			}
			if ( wp_convert_hr_to_bytes( $env['memory_limit'] ) > 0 && wp_convert_hr_to_bytes( $env['memory_limit'] ) < 128 * MB_IN_BYTES ) {
				$flags[] = self::flag( 'warning', 'low_memory', __( 'Low PHP memory limit', 'helpdesk-hero' ), sprintf( /* translators: %s: memory limit */ __( 'memory_limit is %s; 256M is a safer minimum.', 'helpdesk-hero' ), $env['memory_limit'] ) );
			}
			if ( $env['wp_debug'] && $env['wp_debug_display'] && 'production' === $env['environment_type'] ) {
				$flags[] = self::flag( 'warning', 'debug_display', __( 'Errors are shown to visitors', 'helpdesk-hero' ), __( 'WP_DEBUG_DISPLAY is on in production, which can break pages and leak file paths.', 'helpdesk-hero' ) );
			}
			if ( $env['cron_overdue_events'] >= 10 ) {
				$flags[] = self::flag( 'warning', 'cron', __( 'Scheduled tasks are not running', 'helpdesk-hero' ), sprintf( /* translators: %d: number of events */ __( '%d scheduled events are overdue. Emails, backups and scheduled posts may be stuck.', 'helpdesk-hero' ), $env['cron_overdue_events'] ) . ( $env['wp_cron_disabled'] ? ' ' . __( 'DISABLE_WP_CRON is set, so a server cron job must call wp-cron.php.', 'helpdesk-hero' ) : '' ) );
			}
			$core = get_site_transient( 'update_core' );
			if ( isset( $core->updates[0]->response ) && 'upgrade' === $core->updates[0]->response ) {
				$flags[] = self::flag( 'info', 'core_update', __( 'WordPress update available', 'helpdesk-hero' ), sprintf( /* translators: 1: current, 2: new version */ __( 'Running %1$s; %2$s is available.', 'helpdesk-hero' ), $env['wordpress'], $core->updates[0]->current ) );
			}
			if ( ! $env['https'] ) {
				$flags[] = self::flag( 'info', 'https', __( 'Site is not using HTTPS', 'helpdesk-hero' ), '' );
			}
		}

		if ( $ext ) {
			$active_slugs = array();
			foreach ( $ext['active_plugins'] as $p ) {
				$active_slugs[ self::slug( $p['file'] ) ] = $p['name'];
			}

			foreach ( self::overlap_groups() as $label => $slugs ) {
				$found = array_values( array_intersect_key( $active_slugs, array_flip( $slugs ) ) );
				if ( count( $found ) > 1 ) {
					$flags[] = self::flag( 'warning', 'overlap', sprintf( /* translators: %s: kind of plugin, e.g. "page caching" */ __( 'More than one %s plugin is active', 'helpdesk-hero' ), $label ), implode( ', ', $found ) . '. ' . __( 'Plugins that do the same job often conflict; keep one.', 'helpdesk-hero' ) );
				}
			}

			$outdated = array_filter(
				$ext['active_plugins'],
				static function ( $p ) {
					return ! empty( $p['update'] );
				}
			);
			if ( $outdated ) {
				$flags[] = self::flag(
					count( $outdated ) >= 5 ? 'warning' : 'info',
					'plugin_updates',
					sprintf( /* translators: %d: number of plugins */ _n( '%d active plugin has an update', '%d active plugins have updates', count( $outdated ), 'helpdesk-hero' ), count( $outdated ) ),
					implode(
						', ',
						array_map(
							static function ( $p ) {
								return $p['name'] . ' ' . $p['version'] . ' → ' . $p['update'];
							},
							array_slice( $outdated, 0, 8 )
						)
					)
				);
			}

			foreach ( self::known_conflicts() as $rule ) {
				$present = array_intersect( (array) $rule['plugins'], array_keys( $active_slugs ) );
				if ( count( $present ) === count( (array) $rule['plugins'] ) ) {
					$flags[] = self::flag( $rule['level'] ?? 'warning', 'known_conflict', $rule['title'], $rule['detail'] ?? '' );
				}
			}
		}

		if ( ! empty( $data['errors'] ) ) {
			$by_component = array();
			foreach ( $data['errors'] as $error ) {
				$by_component[ $error['component'] ][] = $error;
			}
			arsort( $by_component );
			foreach ( $by_component as $component => $errors ) {
				$label  = self::component_label( $component, $ext );
				$detail = $errors[0]['message'];
				$level  = 'warning';
				$latest = end( $errors );
				if ( 'PHP fatal' === $latest['kind'] ) {
					$level = 'critical';
				}
				// Did this start right after the component changed?
				$change = self::matching_change( $component, $data['changes'] ?? array(), $latest['time'] );
				if ( $change ) {
					$detail = sprintf( /* translators: 1: event, 2: time */ __( 'First seen after: %1$s (%2$s). ', 'helpdesk-hero' ), $change['event'], $change['time'] ) . $detail;
					$level  = 'critical';
				}
				$flags[] = self::flag(
					$level,
					'errors',
					sprintf( /* translators: 1: number, 2: plugin/theme name */ _n( '%1$d error from %2$s', '%1$d errors from %2$s', count( $errors ), 'helpdesk-hero' ), count( $errors ), $label ),
					$detail
				);
			}
		}

		$order = array(
			'critical' => 0,
			'warning'  => 1,
			'info'     => 2,
		);
		usort(
			$flags,
			static function ( $a, $b ) use ( $order ) {
				return $order[ $a['level'] ] <=> $order[ $b['level'] ];
			}
		);

		/**
		 * Filters the health flags shown with a ticket.
		 *
		 * @param array $flags Flags.
		 * @param array $data  Diagnostics bundle.
		 */
		return (array) apply_filters( 'helpdesk_hero_health_flags', $flags, $data );
	}

	/**
	 * Known conflict rules. Plugin and theme vendors can add their own.
	 *
	 * Each rule: plugins (directory slugs that must all be active), title, detail, level.
	 *
	 * @return array[]
	 */
	public static function known_conflicts() {
		/**
		 * Filters known plugin conflicts.
		 *
		 * @param array[] $rules Rules.
		 */
		return (array) apply_filters( 'helpdesk_hero_known_conflicts', array() );
	}

	/**
	 * Build a flag.
	 *
	 * @param string $level  Level.
	 * @param string $code   Code.
	 * @param string $title  Title.
	 * @param string $detail Detail.
	 * @return array
	 */
	private static function flag( $level, $code, $title, $detail ) {
		return compact( 'level', 'code', 'title', 'detail' );
	}

	/**
	 * Plugin directory slug from its file.
	 *
	 * @param string $file e.g. akismet/akismet.php.
	 * @return string
	 */
	public static function slug( $file ) {
		return false !== strpos( $file, '/' ) ? strtok( $file, '/' ) : basename( $file, '.php' );
	}

	/**
	 * Friendly name for "plugin:slug".
	 *
	 * @param string     $component Component.
	 * @param array|null $ext       Extensions section.
	 * @return string
	 */
	private static function component_label( $component, $ext ) {
		list( $kind, $slug ) = array_pad( explode( ':', $component, 2 ), 2, '' );
		if ( 'plugin' === $kind && $ext ) {
			foreach ( array_merge( $ext['active_plugins'], $ext['inactive_plugins'] ) as $p ) {
				if ( self::slug( $p['file'] ) === $slug ) {
					return $p['name'];
				}
			}
		}
		if ( 'theme' === $kind && $ext ) {
			foreach ( array( $ext['theme'], $ext['parent_theme'] ) as $t ) {
				if ( $t && $t['slug'] === $slug ) {
					return $t['name'];
				}
			}
		}
		if ( 'core' === $kind ) {
			return __( 'WordPress core', 'helpdesk-hero' );
		}
		return '' !== $slug ? $slug : $component;
	}

	/**
	 * A change to the same component in the 3 days before an error.
	 *
	 * @param string $component Component.
	 * @param array  $changes   Changes section (newest first).
	 * @param string $error_time Error time.
	 * @return array|null
	 */
	private static function matching_change( $component, array $changes, $error_time ) {
		list( , $slug ) = array_pad( explode( ':', $component, 2 ), 2, '' );
		if ( '' === $slug || ! $changes ) {
			return null;
		}
		$error_ts = strtotime( str_replace( ' UTC', '', $error_time ) . ' UTC' );
		foreach ( $changes as $change ) {
			$ts = strtotime( str_replace( ' UTC', '', $change['time'] ) . ' UTC' );
			if ( $ts <= $error_ts && $ts >= $error_ts - 3 * DAY_IN_SECONDS && false !== stripos( $change['object'] ?? $change['event'], $slug ) ) {
				return $change;
			}
		}
		return null;
	}
}
