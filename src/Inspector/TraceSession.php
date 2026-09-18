<?php
/**
 * A trace session: one authorized frontend render.
 *
 * @package EditTrace
 */

declare( strict_types=1 );

namespace EditTrace\Inspector;

/**
 * Created when an authorized user loads a frontend page. Owns the random
 * trace token, the registry filled during rendering, and page context.
 * Persisted server-side at shutdown; loaded again by the REST API.
 */
final class TraceSession {

	/**
	 * How long a persisted trace stays available (seconds).
	 */
	public const TTL = 20 * MINUTE_IN_SECONDS;

	private string $token;

	private TraceRegistry $registry;

	/** @var array<string,mixed> */
	private array $page;

	private int $user_id;

	private int $created;

	private bool $persisted = false;

	/**
	 * @param array<string,mixed> $page Page context (see describe_page()).
	 */
	private function __construct( string $token, TraceRegistry $registry, array $page, int $user_id, int $created ) {
		$this->token    = $token;
		$this->registry = $registry;
		$this->page     = $page;
		$this->user_id  = $user_id;
		$this->created  = $created;
	}

	/**
	 * Starts a new session for the current request.
	 */
	public static function start( int $user_id ): self {
		return new self( bin2hex( random_bytes( 16 ) ), new TraceRegistry(), self::describe_page(), $user_id, time() );
	}

	/**
	 * Restores a persisted session.
	 */
	public static function load( string $token, TraceStorage $storage ): ?self {
		$data = $storage->load( $token );
		if ( null === $data || ! isset( $data['registry'], $data['page'], $data['user_id'] ) ) {
			return null;
		}
		$session            = new self(
			$token,
			TraceRegistry::from_array( (array) $data['registry'] ),
			(array) $data['page'],
			(int) $data['user_id'],
			(int) ( $data['created'] ?? 0 )
		);
		$session->persisted = true;
		return $session;
	}

	public function persist( TraceStorage $storage ): bool {
		$this->persisted = $storage->save(
			$this->token,
			array(
				'registry' => $this->registry->to_array(),
				'page'     => $this->page,
				'user_id'  => $this->user_id,
				'created'  => $this->created,
				'version'  => EDITTRACE_VERSION,
			),
			self::TTL
		);
		return $this->persisted;
	}

	public function get_token(): string {
		return $this->token;
	}

	public function get_registry(): TraceRegistry {
		return $this->registry;
	}

	/**
	 * @return array<string,mixed>
	 */
	public function get_page(): array {
		return $this->page;
	}

	/**
	 * Merges extra page context (e.g. the block template resolved later).
	 *
	 * @param array<string,mixed> $data Data to merge.
	 */
	public function add_page_context( array $data ): void {
		$this->page = array_merge( $this->page, $data );
	}

	public function get_user_id(): int {
		return $this->user_id;
	}

	public function belongs_to( int $user_id ): bool {
		return $this->user_id === $user_id;
	}

	public function is_persisted(): bool {
		return $this->persisted;
	}

	/**
	 * Captures what the main query is about. Contains no secrets: type, id,
	 * title and permalink of the queried object plus the current URL.
	 *
	 * @return array<string,mixed>
	 */
	public static function describe_page(): array {
		$object = get_queried_object();
		$page   = array(
			'url'         => self::current_url(),
			'object_type' => null,
			'object_id'   => 0,
			'post_type'   => null,
			'title'       => '',
			'edit_url'    => null,
			'is_singular' => is_singular(),
			'is_home'     => is_home(),
			'is_front'    => is_front_page(),
			'is_archive'  => is_archive(),
		);

		if ( $object instanceof \WP_Post ) {
			$page['object_type'] = 'post';
			$page['object_id']   = (int) $object->ID;
			$page['post_type']   = $object->post_type;
			$page['title']       = $object->post_title;
			$page['edit_url']    = get_edit_post_link( $object->ID, 'raw' );
		} elseif ( $object instanceof \WP_Term ) {
			$page['object_type'] = 'term';
			$page['object_id']   = (int) $object->term_id;
			$page['taxonomy']    = $object->taxonomy;
			$page['title']       = $object->name;
			$page['edit_url']    = get_edit_term_link( $object->term_id, $object->taxonomy );
		} elseif ( $object instanceof \WP_User ) {
			$page['object_type'] = 'user';
			$page['object_id']   = (int) $object->ID;
			$page['title']       = $object->display_name;
			$page['edit_url']    = get_edit_user_link( $object->ID );
		} elseif ( $object instanceof \WP_Post_Type ) {
			$page['object_type'] = 'post_type_archive';
			$page['post_type']   = $object->name;
			$page['title']       = $object->labels->name;
		}

		if ( '' === $page['title'] ) {
			$page['title'] = wp_get_document_title();
		}

		return $page;
	}

	private static function current_url(): string {
		$request = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( (string) $_SERVER['REQUEST_URI'] ) : '/'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		return esc_url_raw( home_url( $request ) );
	}
}
