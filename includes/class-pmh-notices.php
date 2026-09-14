<?php
/**
 * Per-user one-shot admin notices carried across a redirect in a transient.
 */

defined( 'ABSPATH' ) || exit;

final class PMH_Notices {

	private const TTL = 120;

	/**
	 * @param string   $scope    Which screen the notice belongs to.
	 * @param string[] $messages Success lines.
	 * @param string[] $errors   Error lines.
	 */
	public static function set( string $scope, array $messages, array $errors ): void {
		if ( ! $messages && ! $errors ) {
			return;
		}
		set_transient(
			self::key( $scope ),
			array(
				'messages' => array_values( $messages ),
				'errors'   => array_values( array_unique( $errors ) ),
			),
			self::TTL
		);
	}

	/**
	 * Read and clear. Null when nothing is queued.
	 *
	 * @return array{messages: string[], errors: string[]}|null
	 */
	public static function take( string $scope ): ?array {
		$key    = self::key( $scope );
		$notice = get_transient( $key );
		if ( ! is_array( $notice ) ) {
			return null;
		}
		delete_transient( $key );
		return array(
			'messages' => (array) ( $notice['messages'] ?? array() ),
			'errors'   => (array) ( $notice['errors'] ?? array() ),
		);
	}

	public static function render( ?array $notice, bool $dismissible = true ): void {
		if ( ! $notice ) {
			return;
		}
		$extra = $dismissible ? ' is-dismissible' : '';
		foreach ( $notice['errors'] as $text ) {
			echo '<div class="notice notice-error' . esc_attr( $extra ) . '"><p>' . esc_html( $text ) . '</p></div>';
		}
		foreach ( $notice['messages'] as $text ) {
			echo '<div class="notice notice-success' . esc_attr( $extra ) . '"><p>' . esc_html( $text ) . '</p></div>';
		}
	}

	private static function key( string $scope ): string {
		return 'pmh_notice_' . sanitize_key( $scope ) . '_' . get_current_user_id();
	}
}
