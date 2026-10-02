<?php
/**
 * Optional AI help, through the WordPress AI Client (WordPress 7.0+).
 *
 * @package Helpdesk_Hero
 */

defined( 'ABSPATH' ) || exit;

/**
 * Uses whatever AI provider the site owner connected in WordPress (Settings › Connectors).
 * Helpdesk Hero has no AI service of its own and stores no AI keys. Nothing is sent to an
 * AI provider unless someone presses an AI button.
 */
final class Helpdesk_Hero_AI {

	/**
	 * Cached availability.
	 *
	 * @var bool|null
	 */
	private static $available = null;

	/**
	 * Whether text generation is available on this site.
	 *
	 * @return bool
	 */
	public static function available() {
		if ( null === self::$available ) {
			self::$available = false;
			if ( function_exists( 'wp_ai_client_prompt' ) ) {
				try {
					self::$available = (bool) wp_ai_client_prompt( 'ping' )->is_supported_for_text_generation();
				} catch ( Throwable $e ) {
					self::$available = false;
				}
			}
		}
		/**
		 * Filters whether Helpdesk Hero's AI features are available.
		 *
		 * @param bool $available Available.
		 */
		return (bool) apply_filters( 'helpdesk_hero_ai_available', self::$available );
	}

	/**
	 * The local, in-browser AI provider "AI Provider for WebLLM": the model runs in the browser
	 * (WebGPU), so there's no API key, no cost and nothing leaves the site. PHP-started requests
	 * need its "In-browser worker" option and an open wp-admin tab.
	 *
	 * @return array { installed, active, worker, settings, plugins, url }
	 */
	public static function local_ai() {
		$active = defined( 'WEBLLM_API_KEY' ) || class_exists( 'WordPress\\WebLlmAiProvider\\Provider\\WebLlmProvider' );
		return array(
			'installed' => $active || self::webllm_installed(),
			'active'    => $active,
			'worker'    => $active && (bool) get_option( 'ai_provider_webllm_worker_enabled', false ),
			'ready'     => $active && self::webllm_ready(),
			'model'     => (string) get_option( 'ai_provider_webllm_model', '' ),
			'settings'  => admin_url( 'options-general.php?page=ai-provider-webllm' ),
			'plugins'   => admin_url( 'plugins.php' ),
			'url'       => 'https://github.com/ProgressPlanner/ai-provider-for-webllm',
		);
	}

	/**
	 * Whether a dashboard tab has the WebLLM model loaded and is ready for requests (the
	 * provider's worker heartbeat, valid for 30 seconds).
	 *
	 * @return bool
	 */
	private static function webllm_ready() {
		$worker = get_option( 'ai_provider_webllm_worker' );
		return is_array( $worker ) && ! empty( $worker['ready'] ) && ( time() - (int) ( $worker['t'] ?? 0 ) ) <= 30;
	}

	/**
	 * Turn WebLLM's "no worker connected" error into steps people can follow.
	 *
	 * @param WP_Error $error Error from the AI Client.
	 * @return WP_Error
	 */
	public static function explain( WP_Error $error ) {
		if ( false === stripos( $error->get_error_message(), 'WebLLM worker' ) ) {
			return $error;
		}
		$model = (string) get_option( 'ai_provider_webllm_model', '' );
		return new WP_Error(
			'helpdesk_hero_ai_local_loading',
			sprintf(
				/* translators: %s: model name */
				__( 'The local AI model (%s) isn’t ready in your browser yet. Keep this tab open until Settings › WebLLM shows “WebLLM worker: ready”, then try again. The first time, the model is downloaded, which can take several minutes for large models; a small model (around 1 GB) is much faster.', 'helpdesk-hero' ),
				'' !== $model ? $model : 'WebLLM'
			)
		);
	}

	/**
	 * Whether AI Provider for WebLLM is installed, whatever its folder is called (a GitHub
	 * "Download ZIP" installs it as ai-provider-for-webllm-main).
	 *
	 * @return bool
	 */
	private static function webllm_installed() {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		foreach ( get_plugins() as $file => $data ) {
			if ( 'AI Provider for WebLLM' === ( $data['Name'] ?? '' ) || 0 === strpos( $file, 'ai-provider-for-webllm' ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether AI generation may need a browser (the local WebLLM provider is active), so it must
	 * not be started from cron or WP-CLI.
	 *
	 * @return bool
	 */
	public static function needs_browser() {
		return self::local_ai()['active'];
	}

	/**
	 * Generate text.
	 *
	 * @param string $prompt Prompt.
	 * @param string $system System instruction.
	 * @return string|WP_Error
	 */
	public static function text( $prompt, $system ) {
		/**
		 * Short-circuits text generation (for tests, or to use another AI service).
		 *
		 * @param string|WP_Error|null $text   Return a string or WP_Error to skip the AI Client.
		 * @param string               $prompt Prompt.
		 * @param string               $system System instruction.
		 */
		$pre = apply_filters( 'helpdesk_hero_ai_pre_text', null, $prompt, $system );
		if ( null !== $pre ) {
			return is_wp_error( $pre ) ? $pre : trim( (string) $pre );
		}
		if ( ! self::available() ) {
			return new WP_Error( 'helpdesk_hero_ai', __( 'No AI provider is connected to this site. Connect one in Settings › Connectors.', 'helpdesk-hero' ) );
		}
		try {
			$result = wp_ai_client_prompt( $prompt )->using_system_instruction( $system )->generate_text();
		} catch ( Throwable $e ) {
			return self::explain( new WP_Error( 'helpdesk_hero_ai', $e->getMessage() ) );
		}
		if ( is_wp_error( $result ) ) {
			return self::explain( $result );
		}
		return trim( (string) $result );
	}

	/**
	 * Generate JSON (the model is asked for JSON only; fences are stripped).
	 *
	 * @param string $prompt Prompt.
	 * @param string $system System instruction.
	 * @return array|WP_Error
	 */
	public static function json( $prompt, $system ) {
		$text = self::text( $prompt, $system . "\n\nAnswer with one JSON object only. No markdown, no code fences, no commentary." );
		if ( is_wp_error( $text ) ) {
			return $text;
		}
		$text = preg_replace( '/^```(?:json)?\s*|\s*```$/m', '', $text );
		$from = strpos( $text, '{' );
		$to   = strrpos( $text, '}' );
		$data = false !== $from && false !== $to ? json_decode( substr( $text, $from, $to - $from + 1 ), true ) : null;
		return is_array( $data ) ? $data : new WP_Error( 'helpdesk_hero_ai_json', __( 'The AI answer could not be read. Try again.', 'helpdesk-hero' ) );
	}

	/**
	 * Health flags as compact text for prompts.
	 *
	 * @param array $flags Flags.
	 * @return string
	 */
	public static function flags_text( array $flags ) {
		$lines = array();
		foreach ( $flags as $flag ) {
			$lines[] = '- [' . $flag['level'] . '] ' . $flag['title'] . ( $flag['detail'] ? ': ' . $flag['detail'] : '' );
		}
		return $lines ? implode( "\n", $lines ) : '- none';
	}

	/**
	 * Ticket assistant: turn a rough description into a clear report, and list what is missing.
	 * Only the text the person wrote and the health flag titles are sent, never the full diagnostics.
	 *
	 * @param string $subject     Subject.
	 * @param string $description Description.
	 * @param array  $flags       Health flags.
	 * @return array|WP_Error { subject, description, questions[], tips[] }
	 */
	public static function improve_ticket( $subject, $description, array $flags ) {
		global $wp_version;
		$system = 'You help WordPress site owners write clear support tickets. Rewrite their message as a well-structured report in their own language and voice (first person), with these short sections when the information exists: What happens, What I expected, Steps to reproduce, When it started. Never invent facts; if something is unknown, leave that section out and add a question to "questions" instead. Keep it concise. Also give up to 3 "tips": quick, safe things they can check themselves based on the health flags (for example a pending plugin update), or an empty list.';
		$prompt = "Subject: {$subject}\n\nMessage:\n{$description}\n\nWordPress {$wp_version}, PHP " . PHP_VERSION . "\nHealth flags found on the site:\n" . self::flags_text( array_slice( $flags, 0, 10 ) ) . "\n\nReturn JSON: {\"subject\": string (max 90 chars), \"description\": string, \"questions\": string[] (max 3), \"tips\": string[] (max 3)}";
		$data   = self::json( $prompt, $system );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		return array(
			'subject'     => sanitize_text_field( (string) ( $data['subject'] ?? $subject ) ),
			'description' => sanitize_textarea_field( (string) ( $data['description'] ?? $description ) ),
			'questions'   => array_slice( array_map( 'sanitize_text_field', array_filter( (array) ( $data['questions'] ?? array() ), 'is_string' ) ), 0, 3 ),
			'tips'        => array_slice( array_map( 'sanitize_text_field', array_filter( (array) ( $data['tips'] ?? array() ), 'is_string' ) ), 0, 3 ),
		);
	}

	/**
	 * Plain-language summary of what support did during a grant, for the site owner.
	 *
	 * @param int $grant_id Grant.
	 * @return string|WP_Error
	 */
	public static function summarize_session( $grant_id ) {
		$log = Helpdesk_Hero_Activity::grant_text( $grant_id );
		if ( '' === $log ) {
			return new WP_Error( 'helpdesk_hero_ai_empty', __( 'Nothing was recorded for this access yet.', 'helpdesk-hero' ) );
		}
		$system = 'You explain to a non-technical WordPress site owner what a support engineer did on their site, based on an activity log. Write 3-6 short bullet points in plain English: what was changed (settings, plugins, content), anything that was switched off or deleted, and anything the owner should double-check. Ignore routine page views unless they show what was investigated. Do not speculate beyond the log.';
		return self::text( "Activity log (UTC):\n" . substr( $log, 0, 20000 ), $system );
	}
}
