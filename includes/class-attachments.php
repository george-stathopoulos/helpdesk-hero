<?php
/**
 * Attachments.
 *
 * @package Helpdesk_Hero
 */

defined( 'ABSPATH' ) || exit;

/**
 * Files on tickets: screenshots and documents the site owner attaches, and files the support
 * team sends with replies. Available when the support team turns attachments on (Helpdesk Hero
 * Pro on their hub).
 *
 * - Stored in wp-content/uploads/helpdesk-hero-files under random names ending in .bin, with
 *   rules that stop the web server from serving them directly. They're shown only in Get Help.
 * - Only common, safe types are accepted (no SVG, HTML or scripts), checked by content. Photos
 *   lose their location data (EXIF) when the server can re-save them.
 * - Files stay where they were uploaded. The hub fetches a copy over the signed connection the
 *   first time someone opens it there, and this site does the same for the team's files.
 */
final class Helpdesk_Hero_Attachments {

	const DIR = 'helpdesk-hero-files';

	/**
	 * Settings from the support team's hub.
	 *
	 * @return array { enabled, max_mb, max_files, types[] }
	 */
	public static function config() {
		$extras = (array) Helpdesk_Hero_Settings::get( 'extras' );
		$c      = (array) ( $extras['attachments'] ?? array() );
		$types  = array_values( array_intersect( array_map( 'sanitize_key', (array) ( $c['types'] ?? array() ) ), array_keys( self::mimes() ) ) );
		return array(
			'enabled'   => ! empty( $c['enabled'] ) && $types && Helpdesk_Hero_Settings::is_connected(),
			'max_mb'    => max( 1, min( 25, (int) ( $c['max_mb'] ?? 5 ) ) ),
			'max_files' => max( 1, min( 10, (int) ( $c['max_files'] ?? 5 ) ) ),
			'types'     => $types,
			'keep_days' => max( 0, min( 3650, (int) ( $c['keep_days'] ?? 0 ) ) ),
		);
	}

	/**
	 * Types that can ever be attached: extension => MIME type.
	 *
	 * @return array<string, string>
	 */
	public static function mimes() {
		return array(
			'png'  => 'image/png',
			'jpg'  => 'image/jpeg',
			'jpeg' => 'image/jpeg',
			'gif'  => 'image/gif',
			'webp' => 'image/webp',
			'pdf'  => 'application/pdf',
			'txt'  => 'text/plain',
			'log'  => 'text/plain',
			'csv'  => 'text/csv',
			'json' => 'application/json',
			'zip'  => 'application/zip',
		);
	}

	/* ---------------------------------------------------------------------------------------- *
	 * Storage.
	 * ---------------------------------------------------------------------------------------- */

	/**
	 * The protected folder (created on first use).
	 *
	 * @return string|WP_Error Path with a trailing slash.
	 */
	public static function dir() {
		$uploads = wp_upload_dir( null, false );
		if ( ! empty( $uploads['error'] ) ) {
			return new WP_Error( 'helpdesk_hero_files', $uploads['error'] );
		}
		$dir = trailingslashit( $uploads['basedir'] ) . self::DIR . '/';
		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			return new WP_Error( 'helpdesk_hero_files', __( 'The uploads folder isn’t writable, so files can’t be saved.', 'helpdesk-hero' ) );
		}
		$guards = array(
			'index.php'  => "<?php\n// Silence is golden.\n",
			'.htaccess'  => "# Files are served through the Helpdesk Hero dashboard only.\n<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n",
			'web.config' => "<?xml version=\"1.0\"?>\n<configuration><system.webServer><authorization><deny users=\"*\" /></authorization></system.webServer></configuration>\n",
		);
		foreach ( $guards as $file => $content ) {
			if ( ! file_exists( $dir . $file ) ) {
				file_put_contents( $dir . $file, $content ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- small guard files in our own folder.
			}
		}
		return $dir;
	}

	/**
	 * Check a file's real type and size.
	 *
	 * @param string $path Path.
	 * @param string $name Original name.
	 * @return array|WP_Error { ext, type }
	 */
	public static function check( $path, $name ) {
		$c    = self::config();
		$size = (int) filesize( $path );
		if ( $size < 1 || $size > $c['max_mb'] * MB_IN_BYTES ) {
			/* translators: %d: megabytes */
			return new WP_Error( 'helpdesk_hero_file_size', sprintf( __( 'Files can be up to %d MB.', 'helpdesk-hero' ), $c['max_mb'] ), array( 'status' => 400 ) );
		}
		$mimes = array_intersect_key( self::mimes(), array_flip( $c['types'] ) );
		// wp_check_filetype_and_ext() checks images by content and other files with fileinfo.
		$check = wp_check_filetype_and_ext( $path, $name, $mimes );
		$ext   = $check['ext'] ? $check['ext'] : '';
		$type  = $check['type'] ? $check['type'] : '';
		if ( ! $ext || ! $type || ! isset( $mimes[ strtolower( $ext ) ] ) ) {
			return new WP_Error( 'helpdesk_hero_file_type', __( 'This type of file can’t be attached.', 'helpdesk-hero' ), array( 'status' => 400 ) );
		}
		if ( function_exists( 'finfo_open' ) ) {
			$finfo = finfo_open( FILEINFO_MIME_TYPE );
			$real  = $finfo ? (string) finfo_file( $finfo, $path ) : '';
			if ( $finfo ) {
				finfo_close( $finfo );
			}
			// Text files are often detected loosely; everything else must match its extension.
			$texty = in_array( strtolower( $ext ), array( 'txt', 'log', 'csv', 'json' ), true );
			if ( $real && ! $texty && $real !== $mimes[ strtolower( $ext ) ] && ! ( 'zip' === strtolower( $ext ) && 'application/x-zip-compressed' === $real ) ) {
				return new WP_Error( 'helpdesk_hero_file_type', __( 'This file’s content doesn’t match its type.', 'helpdesk-hero' ), array( 'status' => 400 ) );
			}
			if ( $texty && $real && 0 !== strpos( $real, 'text/' ) && 'application/json' !== $real ) {
				return new WP_Error( 'helpdesk_hero_file_type', __( 'This file’s content doesn’t match its type.', 'helpdesk-hero' ), array( 'status' => 400 ) );
			}
		}
		return array(
			'ext'  => strtolower( $ext ),
			'type' => $mimes[ strtolower( $ext ) ],
		);
	}

	/**
	 * Move a checked file into the folder (photos lose their EXIF data on the way).
	 *
	 * @param string $tmp  Temporary path.
	 * @param string $name Original name.
	 * @param bool   $move Move an uploaded file (true) or copy bytes we wrote ourselves.
	 * @return array|WP_Error { path (relative), name, size, type, sha256 }
	 */
	public static function store( $tmp, $name, $move = true ) {
		$check = self::check( $tmp, $name );
		if ( is_wp_error( $check ) ) {
			return $check;
		}
		$dir = self::dir();
		if ( is_wp_error( $dir ) ) {
			return $dir;
		}
		$sub = gmdate( 'Y/m' ) . '/';
		wp_mkdir_p( $dir . $sub );
		$rel  = $sub . bin2hex( random_bytes( 16 ) ) . '.bin';
		$dest = $dir . $rel;
		if ( 'image/jpeg' === $check['type'] ) {
			$editor = wp_get_image_editor( $tmp );
			if ( ! is_wp_error( $editor ) ) {
				$saved = $editor->save( $dest . '.jpg', 'image/jpeg' );
				if ( ! is_wp_error( $saved ) && file_exists( $dest . '.jpg' ) ) {
					rename( $dest . '.jpg', $dest ); // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- inside our own folder.
				}
			}
		}
		if ( ! file_exists( $dest ) ) {
			$ok = $move && is_uploaded_file( $tmp ) ? move_uploaded_file( $tmp, $dest ) : copy( $tmp, $dest );
			if ( ! $ok ) {
				return new WP_Error( 'helpdesk_hero_files', __( 'The file could not be saved.', 'helpdesk-hero' ) );
			}
		}
		$base = sanitize_file_name( wp_basename( (string) $name ) );
		$base = preg_replace( '/\.[^.]+$/', '', $base ) . '.' . $check['ext'];
		return array(
			'path'   => $rel,
			'name'   => substr( $base, -190 ),
			'size'   => (int) filesize( $dest ),
			'type'   => $check['type'],
			'sha256' => hash_file( 'sha256', $dest ),
		);
	}

	/**
	 * Store bytes received from the other side, checking their hash.
	 *
	 * @param string $bytes  File content.
	 * @param string $name   Name.
	 * @param string $sha256 Expected SHA-256 ('' to skip).
	 * @return array|WP_Error See store().
	 */
	public static function store_bytes( $bytes, $name, $sha256 = '' ) {
		if ( '' !== $sha256 && ! hash_equals( (string) $sha256, hash( 'sha256', $bytes ) ) ) {
			return new WP_Error( 'helpdesk_hero_file_hash', __( 'The file arrived damaged. Try again.', 'helpdesk-hero' ) );
		}
		require_once ABSPATH . 'wp-admin/includes/file.php';
		$tmp = wp_tempnam( $name );
		file_put_contents( $tmp, $bytes ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- temporary file.
		$stored = self::store( $tmp, $name, false );
		wp_delete_file( $tmp );
		return $stored;
	}

	/* ---------------------------------------------------------------------------------------- *
	 * Records.
	 * ---------------------------------------------------------------------------------------- */

	/**
	 * A record.
	 *
	 * @param int $id ID.
	 * @return array|null
	 */
	public static function get( $id ) {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', Helpdesk_Hero_DB::table( 'attachments' ), $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return $row ? $row : null;
	}

	/**
	 * Add a record.
	 *
	 * @param array $data Columns.
	 * @return int ID.
	 */
	private static function insert( array $data ) {
		global $wpdb;
		$wpdb->insert( Helpdesk_Hero_DB::table( 'attachments' ), array_merge( array( 'created_at' => Helpdesk_Hero_DB::now() ), $data ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		return (int) $wpdb->insert_id;
	}

	/**
	 * Short form, as sent to the hub.
	 *
	 * @param array $row Record.
	 * @return array { id, name, size, type, sha256 }
	 */
	public static function brief( array $row ) {
		return array(
			'id'     => (int) $row['id'],
			'name'   => (string) $row['name'],
			'size'   => (int) $row['size'],
			'type'   => (string) $row['type'],
			'sha256' => (string) $row['sha256'],
		);
	}

	/**
	 * For the dashboard.
	 *
	 * @param array $row Record.
	 * @return array { id, name, size, type, url, image }
	 */
	public static function json( array $row ) {
		return array(
			'id'    => (int) $row['id'],
			'name'  => (string) $row['name'],
			'size'  => (int) $row['size'],
			'type'  => (string) $row['type'],
			'url'   => add_query_arg( '_wpnonce', wp_create_nonce( 'wp_rest' ), rest_url( 'helpdesk-hero/v1/admin/attachments/' . (int) $row['id'] ) ),
			'image' => 0 === strpos( (string) $row['type'], 'image/' ),
		);
	}

	/**
	 * Files of a message, for the dashboard.
	 *
	 * @param int $message_id Message.
	 * @return array[]
	 */
	public static function for_message( $message_id ) {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE message_id = %d ORDER BY id ASC', Helpdesk_Hero_DB::table( 'attachments' ), $message_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return array_map( array( __CLASS__, 'json' ), $rows );
	}

	/**
	 * The site owner uploads a file, to send with a ticket or reply.
	 *
	 * @param array $file Entry from $_FILES.
	 * @return array|WP_Error For the dashboard.
	 */
	public static function upload( array $file ) {
		if ( ! self::config()['enabled'] ) {
			return new WP_Error( 'helpdesk_hero_files_off', __( 'Your support team doesn’t accept attachments.', 'helpdesk-hero' ), array( 'status' => 403 ) );
		}
		if ( ! empty( $file['error'] ) || empty( $file['tmp_name'] ) ) {
			return new WP_Error( 'helpdesk_hero_files', __( 'The upload didn’t arrive. Try a smaller file.', 'helpdesk-hero' ), array( 'status' => 400 ) );
		}
		$stored = self::store( (string) $file['tmp_name'], (string) $file['name'] );
		if ( is_wp_error( $stored ) ) {
			return $stored;
		}
		$id = self::insert(
			array_merge(
				$stored,
				array(
					'direction'   => 'out',
					'uploaded_by' => get_current_user_id(),
				)
			)
		);
		return self::json( self::get( $id ) );
	}

	/**
	 * Attach your own unsent uploads to a message.
	 *
	 * @param int   $ticket_id  Ticket.
	 * @param int   $message_id Message.
	 * @param array $ids        Upload IDs.
	 * @return array[] Brief records, for the hub.
	 */
	public static function claim( $ticket_id, $message_id, array $ids ) {
		global $wpdb;
		$out = array();
		foreach ( array_slice( array_unique( array_map( 'absint', $ids ) ), 0, self::config()['max_files'] ) as $id ) {
			$row = $id ? self::get( $id ) : null;
			if ( ! $row || 'out' !== $row['direction'] || (int) $row['ticket_id'] || (int) $row['uploaded_by'] !== get_current_user_id() ) {
				continue;
			}
			$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				Helpdesk_Hero_DB::table( 'attachments' ),
				array(
					'ticket_id'  => (int) $ticket_id,
					'message_id' => (int) $message_id,
				),
				array( 'id' => $id )
			);
			$out[] = self::brief( $row );
		}
		return $out;
	}

	/**
	 * Files the team sent with a reply: recorded now, fetched when the site owner opens them.
	 *
	 * @param int   $ticket_id  Ticket.
	 * @param int   $message_id Message.
	 * @param array $list       [ { id, name, size, type, sha256 } ] from the hub.
	 */
	public static function from_hub( $ticket_id, $message_id, array $list ) {
		$mimes = self::mimes();
		foreach ( array_slice( $list, 0, 10 ) as $a ) {
			$type = (string) ( $a['type'] ?? '' );
			if ( empty( $a['id'] ) || ! in_array( $type, $mimes, true ) || ! preg_match( '/^[a-f0-9]{64}$/', (string) ( $a['sha256'] ?? '' ) ) ) {
				continue;
			}
			self::insert(
				array(
					'ticket_id'  => (int) $ticket_id,
					'message_id' => (int) $message_id,
					'direction'  => 'in',
					'remote_id'  => (int) $a['id'],
					'name'       => substr( sanitize_file_name( (string) ( $a['name'] ?? 'file' ) ), -190 ),
					'size'       => (int) ( $a['size'] ?? 0 ),
					'type'       => $type,
					'sha256'     => (string) $a['sha256'],
				)
			);
		}
	}

	/**
	 * Make sure a record's file is here, fetching it from the hub if needed.
	 *
	 * @param array $row Record.
	 * @return string|WP_Error Absolute path.
	 */
	public static function local_path( array $row ) {
		$dir = self::dir();
		if ( is_wp_error( $dir ) ) {
			return $dir;
		}
		if ( '' !== (string) $row['path'] && file_exists( $dir . $row['path'] ) ) {
			return $dir . $row['path'];
		}
		if ( 'in' !== $row['direction'] ) {
			return new WP_Error( 'helpdesk_hero_file_gone', __( 'This file is no longer available.', 'helpdesk-hero' ), array( 'status' => 404 ) );
		}
		$got = Helpdesk_Hero_Connection::hub_request( Helpdesk_Hero_Connection::HUB_NS . '/attachments/' . (int) $row['remote_id'], 'GET', array(), 30 );
		if ( is_wp_error( $got ) ) {
			return $got;
		}
		$bytes = base64_decode( (string) ( $got['data'] ?? '' ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- file transport encoding.
		if ( false === $bytes ) {
			return new WP_Error( 'helpdesk_hero_file_gone', __( 'Your support team’s hub didn’t send the file.', 'helpdesk-hero' ) );
		}
		$stored = self::store_bytes( $bytes, (string) $row['name'], (string) $row['sha256'] );
		if ( is_wp_error( $stored ) ) {
			return $stored;
		}
		global $wpdb;
		$wpdb->update( Helpdesk_Hero_DB::table( 'attachments' ), array( 'path' => $stored['path'] ), array( 'id' => (int) $row['id'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return $dir . $stored['path'];
	}

	/**
	 * Send a file to the browser and stop.
	 *
	 * @param array  $row  Record.
	 * @param string $path Absolute path.
	 */
	public static function send_file( array $row, $path ) {
		$image = in_array( $row['type'], array( 'image/png', 'image/jpeg', 'image/gif', 'image/webp' ), true );
		nocache_headers();
		header( 'Content-Type: ' . $row['type'] );
		header( 'Content-Length: ' . filesize( $path ) );
		header( 'X-Content-Type-Options: nosniff' );
		header( "Content-Security-Policy: default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; sandbox" );
		header( 'Content-Disposition: ' . ( $image ? 'inline' : 'attachment' ) . '; filename="' . str_replace( array( '"', "\r", "\n" ), '', $row['name'] ) . '"' );
		readfile( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- streaming a protected file.
		exit;
	}

	/**
	 * A file this site sent, as base64 for the hub.
	 *
	 * @param int $id ID.
	 * @return array|WP_Error { id, name, type, size, sha256, data }
	 */
	public static function export( $id ) {
		$row = self::get( $id );
		if ( ! $row || 'out' !== $row['direction'] || ! (int) $row['ticket_id'] ) {
			return new WP_Error( 'helpdesk_hero_file_gone', __( 'File not found.', 'helpdesk-hero' ), array( 'status' => 404 ) );
		}
		$path = self::local_path( $row );
		if ( is_wp_error( $path ) ) {
			return $path;
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents, WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- reading our own file; transport encoding.
		return array_merge( self::brief( $row ), array( 'data' => base64_encode( (string) file_get_contents( $path ) ) ) );
	}

	/**
	 * Delete the files of one ticket (privacy eraser).
	 *
	 * @param int $ticket_id Ticket.
	 */
	public static function delete_for_ticket( $ticket_id ) {
		global $wpdb;
		$dir   = self::dir();
		$table = Helpdesk_Hero_DB::table( 'attachments' );
		$rows  = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE ticket_id = %d', $table, $ticket_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		foreach ( $rows as $row ) {
			if ( ! is_wp_error( $dir ) && '' !== (string) $row['path'] && file_exists( $dir . $row['path'] ) ) {
				wp_delete_file( $dir . $row['path'] );
			}
		}
		$wpdb->delete( $table, array( 'ticket_id' => (int) $ticket_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	/**
	 * Daily: delete the files of tickets closed longer ago than the support team keeps them.
	 */
	public static function expire_closed() {
		global $wpdb;
		$keep = (int) self::config()['keep_days'];
		if ( $keep < 1 ) {
			return;
		}
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT a.ticket_id FROM %i a JOIN %i t ON t.id = a.ticket_id WHERE t.status = 'closed' AND t.updated_at < %s LIMIT 100", Helpdesk_Hero_DB::table( 'attachments' ), Helpdesk_Hero_DB::table( 'tickets' ), Helpdesk_Hero_DB::now( -$keep * DAY_IN_SECONDS ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		foreach ( $ids as $id ) {
			self::delete_for_ticket( (int) $id );
		}
	}

	/**
	 * Remove uploads that were never sent (older than a day), or everything (uninstall, eraser).
	 *
	 * @param bool $all Delete every file.
	 */
	public static function cleanup( $all = false ) {
		global $wpdb;
		$dir   = self::dir();
		$table = Helpdesk_Hero_DB::table( 'attachments' );
		$rows  = $all
			? $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i', $table ), ARRAY_A ) // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			: $wpdb->get_results( $wpdb->prepare( "SELECT * FROM %i WHERE ticket_id = 0 AND direction = 'out' AND created_at < %s LIMIT 100", $table, Helpdesk_Hero_DB::now( -DAY_IN_SECONDS ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		foreach ( $rows as $row ) {
			if ( ! is_wp_error( $dir ) && '' !== (string) $row['path'] && file_exists( $dir . $row['path'] ) ) {
				wp_delete_file( $dir . $row['path'] );
			}
			$wpdb->delete( $table, array( 'id' => (int) $row['id'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		}
	}
}
