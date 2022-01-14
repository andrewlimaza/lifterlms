<?php
/**
 * LifterLMS Eearned Engagements (Certificate/Achievement) Reporting Table trait.
 *
 * @package LifterLMS/Traits
 *
 * @since [version]
 * @version [version]
 */

defined( 'ABSPATH' ) || exit;

/**
 * LifterLMS Eearned Engagements (Certificate/Achievement) Reporting Table trait.
 *
 * This trait should only be used by classes that extend from the {@see LLMS_Admin_Table} class.
 *
 * @since [version]
 */
trait LLMS_Trait_Earned_Engagement_Reporting_Table {

	/**
	 * Add award engaement button above the table.
	 *
	 * @since [version]
	 *
	 * @return string
	 */
	public function get_table_html() {

		$table = parent::get_table_html();

		$post_type = null;
		if ( 'certificates' === $this->id ) {
			$post_type = 'llms_my_certificate';
		} elseif ( 'achievements' === $this->id ) {
			$post_type = 'llms_my_achievement';
		}
		if ( empty( $post_type ) ) {
			return $table;
		}

		$post_type_object = get_post_type_object( $post_type );

		if ( ! current_user_can( $post_type_object->cap->edit_post ) ) {
			return $table;
		}

		$student = false;
		if ( ! empty( $this->student ) ) {
			$student = $this->student->get_id();
		} elseif ( ! empty( $_GET['student_id'] ) ) { //phpcs:ignore -- Nonce verification not needed.
			$student = llms_filter_input( INPUT_GET, 'student_id', FILTER_SANITIZE_NUMBER_INT );
		}

		$post_new_file  = "post-new.php?post_type=$post_type";
		$post_new_url   = esc_url( add_query_arg( 'sid', $student, admin_url( $post_new_file ) ) );
		$add_new_button = '<a style="display:inline-block;margin-bottom:20px" href="' . $post_new_url . '" class="llms-button-primary small">' . esc_html( $post_type_object->labels->add_new ) . '</a>';

		return $add_new_button . $table;

	}

}
