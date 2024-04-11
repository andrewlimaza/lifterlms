<?php
/**
 * LLMS_Comments class file.
 *
 * @package LifterLMS/Classes
 *
 * @since 3.0.0
 * @version 6.6.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Custom filters & actions for LifterLMS comments.
 *
 * This class owes a great debt to WooCommerce.
 *
 * @since 3.0.0
 */
class LLMS_Comments {

	/**
	 * Transient key where calculated comment stats are stored.
	 *
	 * @var string
	 */
	protected static $count_transient_key = 'llms_count_comments';

	/**
	 * Constructor.
	 *
	 * @since 3.37.12
	 * @since 6.6.0 Conditionally hook `wp_count_comments` filter.
	 *
	 * @return void
	 */
	public function __construct() {

		// Secure order notes.
		add_filter( 'comments_clauses', array( __CLASS__, 'exclude_order_comments' ), 10, 1 );
		add_action( 'comment_feed_join', array( __CLASS__, 'exclude_order_comments_from_feed_join' ) );
		add_action( 'comment_feed_where', array( __CLASS__, 'exclude_order_comments_from_feed_where' ) );

		// Delete comments count cache whenever there is a new comment or a comment status changes.
		add_action( 'wp_insert_comment', array( __CLASS__, 'delete_comments_count_cache' ) );
		add_action( 'wp_set_comment_status', array( __CLASS__, 'delete_comments_count_cache' ) );

		/**
		 * Remove order notes when counting comments on WP versions earlier than 6.0.
		 *
		 * @todo This filter can be safely deprecated once support is dropped for WordPress 6.0.
		 */
		if ( self::should_modify_comment_counts() ) {
			add_filter( 'wp_count_comments', array( __CLASS__, 'wp_count_comments' ), 999, 2 );
		}
	}

	/**
	 * Delete transient data when inserting new comments or updating comment status
	 *
	 * Next time wp_count_comments is called it'll be automatically regenerated
	 *
	 * @since 3.0.0
	 * @since 3.37.12 Use class variable to access the transient key name.
	 *
	 * @return void
	 */
	public static function delete_comments_count_cache() {
		delete_transient( self::$count_transient_key );
	}

	/**
	 * Exclude order comments from queries and RSS.
	 *
	 * @since 3.0.0
	 * @since 3.37.12 Use strict comparison for `in_array()`.
	 *
	 * @param array $clauses Array of SQL clauses.
	 * @return array
	 */
	public static function exclude_order_comments( $clauses ) {

		global $wpdb, $typenow;

		// Allow queries when in the admin.
		if ( is_admin() && in_array( $typenow, array( 'llms_order' ), true ) && current_user_can( apply_filters( 'lifterlms_admin_order_access', 'manage_options' ) ) ) {
			return $clauses;
		}

		if ( ! $clauses['join'] ) {
			$clauses['join'] = '';
		}

		if ( ! strstr( $clauses['join'], "JOIN $wpdb->posts" ) ) {
			$clauses['join'] .= " LEFT JOIN $wpdb->posts ON comment_post_ID = $wpdb->posts.ID ";
		}

		if ( $clauses['where'] ) {
			$clauses['where'] .= ' AND ';
		}

		$clauses['where'] .= " $wpdb->posts.post_type NOT IN ('" . implode( "','", array( 'llms_order' ) ) . "') ";

		return $clauses;
	}

	/**
	 * Exclude order comments from queries and RSS.
	 *
	 * @since 3.0.0
	 *
	 * @param string $join SQL join clause.
	 * @return string
	 */
	public static function exclude_order_comments_from_feed_join( $join ) {
		global $wpdb;
		if ( ! strstr( $join, $wpdb->posts ) ) {
			$join = " LEFT JOIN $wpdb->posts ON $wpdb->comments.comment_post_ID = $wpdb->posts.ID ";
		}
		return $join;
	}

	/**
	 * Exclude order comments from queries and RSS.
	 *
	 * @since 3.0.0
	 *
	 * @param string $where SQL where clause.
	 * @return string
	 */
	public static function exclude_order_comments_from_feed_where( $where ) {
		global $wpdb;
		if ( $where ) {
			$where .= ' AND ';
		}
		$where .= " $wpdb->posts.post_type NOT IN ('" . implode( "','", array( 'llms_order' ) ) . "') ";
		return $where;
	}

	/**
	 * Retrieve an array mapping database values to their human-readable meanings
	 *
	 * The array key is the value stored in the $wpdb->comments table for the `comment_approved` column.
	 *
	 * The array values are the equivalent value as expected by the return of the `wp_count_comments()` function.
	 *
	 * @since 3.37.12
	 *
	 * @return array
	 */
	protected static function get_approved_map() {

		return array(
			'0'            => 'moderated',
			'1'            => 'approved',
			'spam'         => 'spam',
			'trash'        => 'trash',
			'post-trashed' => 'post-trashed',
		);
	}

	/**
	 * Retrieve order note comment counts.
	 *
	 * @since 3.37.12
	 *
	 * @return array[]
	 */
	protected static function get_note_counts() {

		global $wpdb;
		return $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			"
			SELECT comment_approved, COUNT( * ) AS num_comments
			FROM {$wpdb->comments}
			WHERE comment_type = 'llms_order_note'
			GROUP BY comment_approved;
			",
			ARRAY_A
		);
	}

	/**
	 * Remove order notes from an existing comment count stats object.
	 *
	 * This method accepts a stats object, generated by another plugin (like WooCommerce) or using core information
	 * from `get_comment_counts()` and then subtracts LifterLMS order note comments from the existing comment counts
	 * which would have included order notes in the counts.
	 *
	 * @since 3.37.12
	 *
	 * @todo This method can be safely deprecated once support is dropped for WordPress 6.0.
	 *
	 * @param  stdClass $stats Comment stats object. See the return of LLMS_Comments::wp_comment_counts() for object details.
	 * @return stdClass See LLMS_Comments::wp_comment_counts() for return object details.
	 */
	protected static function modify_comment_stats( $stats ) {

		$counts = self::get_note_counts();
		$map    = self::get_approved_map();

		foreach ( (array) $counts as $row ) {

			if ( ! in_array( $row['comment_approved'], array( 'post-trashed', 'trash', 'spam' ), true ) ) {
				$stats->all            -= $row['num_comments'];
				$stats->total_comments -= $row['num_comments'];
			}

			if ( isset( $map[ $row['comment_approved'] ] ) ) {
				$var          = $map[ $row['comment_approved'] ];
				$stats->$var -= $row['num_comments'];
			}
		}

		set_transient( self::$count_transient_key, $stats );

		return $stats;
	}

	/**
	 * Determines whether or not comment count modification is necessary.
	 *
	 * Since WordPress 6.0 the `get_comment_count()` function utilizes `get_comments()` whereas in earlier versions the counts
	 * are retrieved by a direct SQL query. This change means that the filter in this class on `comments_clauses` ensures that
	 * our comments we hide & don't count in the comments management UI are already excluded and we do not need to filter
	 * `wp_count_comments` to subtract our comments.
	 *
	 * @since 6.6.0
	 *
	 * @return boolean Returns `true` on WP earlier than 6.0 and `false` on 6.0 and later.
	 */
	private static function should_modify_comment_counts() {
		global $wp_version;
		return version_compare( $wp_version, '6.0-src', '<' );
	}

	/**
	 * Remove order notes from the count when counting comments
	 *
	 * This method is hooked to `wp_count_comments`, called by `wp_count_comments()`.
	 *
	 * It handles two potential scenarios:
	 *
	 * 1) No other plugins have run the filter and the incoming $stats is an empty array.
	 * In this scenario we'll utilize `get_comment_count()` to create a new $stats object
	 *
	 * 2) Another plugin has already generated a stats object and then incoming $stats is a stdClass.
	 *
	 * In either scenario we query the number of order notes and subtract this number from the existing
	 * comment counts.
	 *
	 * @since 3.0.0
	 * @since 3.37.12 Use strict comparisons.
	 *                Fix issue encountered when $stats is an empty array.
	 *                Modify the stats generation method.
	 * @since 6.6.0 Will throw `_doing_it_wrong()` when run on WP 6.0 or later and return the input `$stats` unchanged.
	 *
	 * @todo This method can be safely deprecated once support is dropped for WordPress 6.0.
	 *
	 * @param stdClass|array $stats   Empty array or a stdClass of stats from another plugin.
	 * @param int            $post_id WP Post ID. `0` indicates comment stats for the entire site.
	 * @return stdClass {
	 *     The number of comments keyed by their status.
	 *
	 *     @type int $approved       The number of approved comments.
	 *     @type int $moderated      The number of comments awaiting moderation (a.k.a. pending).
	 *     @type int $spam           The number of spam comments.
	 *     @type int $trash          The number of trashed comments.
	 *     @type int $post-trashed   The number of comments for posts that are in the trash.
	 *     @type int $total_comments The total number of non-trashed comments, including spam.
	 *     @type int $all            The total number of pending or approved comments.
	 * }
	 */
	public static function wp_count_comments( $stats, $post_id ) {

		// If someone calls this directly on 6.0 or later notify them and return early.
		if ( ! self::should_modify_comment_counts() ) {
			_doing_it_wrong( __METHOD__, 'This method should not be called on WordPress 6.0 or later.', '6.6.0' );
			return $stats;
		}

		// Don't modify when querying for a specific post.
		if ( 0 !== $post_id ) {
			return $stats;
		}

		// Return cached object if available.
		$cached = get_transient( self::$count_transient_key );
		if ( $cached ) {
			return $cached;
		}

		// If $stats is empty, get a new object from the WP Core that we can modify.
		if ( empty( $stats ) ) {

			$stats = get_comment_count( $post_id );

			// The keys in wp_count_comments() and get_comment_counts() don't match.
			$stats['moderated'] = $stats['awaiting_moderation'];
			unset( $stats['awaiting_moderation'] );

			// Cast to an object.
			$stats = (object) $stats;

		}

		// Otherwise modify the existing stats object.
		return self::modify_comment_stats( $stats );
	}
}
return new LLMS_Comments();
