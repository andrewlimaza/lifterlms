<?php
/**
 * Test Course data background processor
 *
 * @package LifterLMS/Tests
 *
 * @group processors
 * @group processor_course_data
 *
 * @since [version]
 * @version [version]
 */
class LLMS_Test_Processor_Course_Data extends LLMS_UnitTestCase {

	/**
	 * Setup before class
	 *
	 * Forces processor debugging on so that we can make assertions against logged data.
	 *
	 * @since [version]
	 *
	 * @return void
	 */
	public static function setUpBeforeClass() {

		parent::setUpBeforeClass();
		llms_maybe_define_constant( 'LLMS_PROCESSORS_DEBUG', true );

	}

	/**
	 * Setup the test case
	 *
	 * @since [version]
	 *
	 * @return void
	 */
	public function setUp() {

		parent::setUp();

		$this->main          = llms()->processors()->get( 'course_data' );
		$this->schedule_hook = LLMS_Unit_Test_Util::get_private_property_value( $this->main, 'cron_hook_identifier' );

	}

	/**
	 * Teardown the test case
	 *
	 * @since [version]
	 *
	 * @return void
	 */
	public function tearDown() {

		$this->main->cancel_process();
		LLMS_Unit_Test_Util::set_private_property( $this->main, 'data', array() );
		parent::tearDown();

	}

	/**
	 * Test dispatch_calc() when throttled by number of students
	 *
	 * @since [version]
	 *
	 * @return void
	 */
	public function test_dispatch_calc_throttled_by_students() {

		$course_id = $this->factory->post->create( array( 'post_type' => 'course' ) );
		$this->factory->student->create_and_enroll_many( 2, $course_id );

		// Clear things so scheduling works right.
		wp_unschedule_event( wp_next_scheduled( 'llms_calculate_course_data', array( $course_id ) ), 'llms_calculate_course_data',  array( $course_id ) );
		$this->logs->clear( 'processors' );

		// Fake throttling data.
		LLMS_Unit_Test_Util::set_private_property( $this->main, 'throttle_max_students', 1 );
		$last_run = time() - HOUR_IN_SECONDS;
		update_post_meta( $course_id, '_llms_last_data_calc_run', $last_run );

		// Dispatch.
		$this->main->dispatch_calc( $course_id );

		// Expected logs.
		$logs = array(
			"Course data calculation dispatched for course {$course_id}.",
			"Course data calculation triggered for course {$course_id}.",
			"Course data calculation scheduled for course {$course_id}.",
			"Course data calculation throttled for course {$course_id}.",
		);
		$this->assertEquals( $logs, $this->logs->get( 'processors' ) );

		// Event scheduled.
		$this->assertEquals( $last_run + ( HOUR_IN_SECONDS * 4 ), wp_next_scheduled( 'llms_calculate_course_data', array( $course_id ) ) );

		LLMS_Unit_Test_Util::set_private_property( $this->main, 'throttle_max_students', 500 );

	}

	/**
	 * Test dispatch_calc() when throttled because it's already processing for the course.
	 *
	 * @since [version]
	 *
	 * @return void
	 */
	public function test_dispatch_calc_throttled_by_course() {

		$course_id = $this->factory->post->create( array( 'post_type' => 'course' ) );
		$this->factory->student->create_and_enroll_many( 1, $course_id );

		update_post_meta( $course_id, '_llms_temp_calc_data_lock', 'yes' );

		$this->logs->clear( 'processors' );

		// Dispatch.
		$this->main->dispatch_calc( $course_id );

		// Expected logs.
		$logs = array(
			"Course data calculation dispatched for course {$course_id}.",
			"Course data calculation triggered for course {$course_id}.",
			"Course data calculation throttled for course {$course_id}.",
		);
		$this->assertEquals( $logs, $this->logs->get( 'processors' ) );

	}

	/**
	 * Test dispatch_calc()w
	 *
	 * @since [version]
	 *
	 * @return void
	 */
	public function test_dispatch_calc_success() {

		$course_id = $this->factory->post->create( array( 'post_type' => 'course' ) );
		$this->factory->student->create_and_enroll_many( 5, $course_id );
		$this->logs->clear( 'processors' );

		$handler = function( $args ) {
			$args['per_page'] = 2;
			return $args;
		};
		add_filter( 'llms_data_processor_course_data_student_query_args', $handler );

		$this->main->dispatch_calc( $course_id );

		// Logged properly.
		$this->assertEquals( array( "Course data calculation dispatched for course {$course_id}." ), $this->logs->get( 'processors' ) );

		// Test data is loaded into the queue properly.
		foreach ( LLMS_Unit_Test_Util::call_method( $this->main, 'get_batch' )->data as $i => $args ) {

			$this->assertEquals( $course_id, $args['post_id'] );
			$this->assertEquals( 2, $args['per_page'] );
			$this->assertEquals( array( 'enrolled' ), $args['statuses'] );
			$this->assertEquals( ++$i, $args['page'] );

		}

		// Event scheduled.
		$this->assertTrue( ! empty( wp_next_scheduled( $this->schedule_hook ) ) );

		remove_filter( 'llms_data_processor_course_data_student_query_args', $handler );

	}

	/**
	 * Test get_last_run()
	 *
	 * @since [version]
	 *
	 * @return void
	 */
	public function test_get_last_run() {

		$course_id = $this->factory->post->create( array( 'post_type' => 'course' ) );
		$this->assertEquals( 0, LLMS_Unit_Test_Util::call_method( $this->main, 'get_last_run', array( $course_id ) ) );

		$now = time();
		update_post_meta( $course_id, '_llms_last_data_calc_run', $now );
		$this->assertEquals( $now, LLMS_Unit_Test_Util::call_method( $this->main, 'get_last_run', array( $course_id ) ) );

	}

	/**
	 * Test is_already_processing_course() when it's not processing.
	 *
	 * @since [version]
	 *
	 * @return void
	 */
	public function test_is_already_processing_course() {

		$course_id = $this->factory->post->create( array( 'post_type' => 'course' ) );
		$course    = llms_get_post( $course_id );

		// No meta data.
		$this->assertFalse( LLMS_Unit_Test_Util::call_method( $this->main, 'is_already_processing_course', array( $course_id ) ) );

		// Unexpected / invalid meta values.
		$course->set( 'temp_calc_data_lock', '' );
		$this->assertFalse( LLMS_Unit_Test_Util::call_method( $this->main, 'is_already_processing_course', array( $course_id ) ) );

		$course->set( 'temp_calc_data_lock', 'no' );
		$this->assertFalse( LLMS_Unit_Test_Util::call_method( $this->main, 'is_already_processing_course', array( $course_id ) ) );

		// Is running.
		$course->set( 'temp_calc_data_lock', 'yes' );
		$this->assertTrue( LLMS_Unit_Test_Util::call_method( $this->main, 'is_already_processing_course', array( $course_id ) ) );


	}

	/**
	 * Test maybe_throttle()
	 *
	 * @since [version]
	 *
	 * @return void
	 */
	public function test_maybe_throttle() {

		$course_id = $this->factory->post->create( array( 'post_type' => 'course' ) );
		$this->assertFalse( LLMS_Unit_Test_Util::call_method( $this->main, 'maybe_throttle', array( 25, $course_id ) ) );

		// Hasn't run recently.
		$this->assertFalse( LLMS_Unit_Test_Util::call_method( $this->main, 'maybe_throttle', array( 500, $course_id ) ) );
		$this->assertFalse( LLMS_Unit_Test_Util::call_method( $this->main, 'maybe_throttle', array( 2500, $course_id ) ) );

		// Should be throttled because of a recent run.
		update_post_meta( $course_id, '_llms_last_data_calc_run', time() - HOUR_IN_SECONDS );
		$this->assertTrue( LLMS_Unit_Test_Util::call_method( $this->main, 'maybe_throttle', array( 500, $course_id ) ) );
		$this->assertTrue( LLMS_Unit_Test_Util::call_method( $this->main, 'maybe_throttle', array( 2500, $course_id ) ) );

	}

	/**
	 * Test schedule_from_course()
	 *
	 * @since [version]
	 *
	 * @return void
	 */
	public function test_schedule_from_course() {

		$course_id = $this->factory->post->create( array( 'post_type' => 'course' ) );

		$this->main->schedule_from_course( 123, $course_id );

		// Logs.
		$logs = array (
			"Course data calculation triggered for course {$course_id}.",
			"Course data calculation scheduled for course {$course_id}.",
		);
		$this->assertEquals( $logs, $this->logs->get( 'processors' ) );

		// Event.
		$this->assertEquals( time(), wp_next_scheduled( 'llms_calculate_course_data', array( $course_id ) ), '', 5 );

	}

	/**
	 * Test schedule_from_lesson()
	 *
	 * @since [version]
	 *
	 * @return void
	 */
	public function test_schedule_from_lesson() {

		$course_id = $this->factory->course->create( array( 'sections' => 1, 'lessons' => 1 ) );
		$lesson_id = llms_get_post( $course_id )->get_lessons( 'ids' )[0];

		$this->main->schedule_from_lesson( 123, $lesson_id );

		// Logs.
		$logs = array (
			"Course data calculation triggered for course {$course_id}.",
			"Course data calculation scheduled for course {$course_id}.",
		);
		$this->assertEquals( $logs, $this->logs->get( 'processors' ) );

		// Event.
		$this->assertEquals( time(), wp_next_scheduled( 'llms_calculate_course_data', array( $course_id ) ), '', 5 );

	}

	/**
	 * Test schedule_from_quiz()
	 *
	 * @since [version]
	 *
	 * @return void
	 */
	public function test_schedule_from_quiz() {

		$course_id  = $this->factory->course->create( array( 'sections' => 1, 'lessons' => 1 ) );
		$quiz_id    = llms_get_post( $course_id )->get_lessons()[0]->get( 'quiz' );
		$student_id = $this->factory->student->create();
		$attempt    = $this->take_quiz( $quiz_id, $student_id );

		$this->main->schedule_from_quiz( $student_id, $quiz_id, $attempt );

		// Logs.
		// In this particular test the process is already running because of the lesson completion triggered by the quiz.
		// This does not render the trigger entirely useless though as the quiz itself could trigger without lessons
		// when using add-ons that implement restrictions on lesson progression.
		$logs = array (
			"Course data calculation triggered for course {$course_id}.",
			"Course data calculation scheduled for course {$course_id}.",
			"Course data calculation triggered for course {$course_id}.",
			"Course data calculation triggered for course {$course_id}.",
		);
		$this->assertEquals( $logs, $this->logs->get( 'processors' ) );

		// Event.
		$this->assertEquals( time(), wp_next_scheduled( 'llms_calculate_course_data', array( $course_id ) ), '', 5 );

	}

	/**
	 * Test task() method.
	 *
	 * @since [version]
	 *
	 * @return void
	 */
	public function test_task() {

		$course_id = $this->factory->course->create( array( 'sections' => 1, 'lessons' => 2, 'quizzes' => 1 ) );
		$course    = llms_get_post( $course_id );
		$students  = $this->factory->student->create_and_enroll_many( 5, $course_id );

		foreach ( $students as $i => $student ) {
			$perc = array( 0, 50, 50, 100, 100 );
			$this->complete_courses_for_student( $student, $course_id, $perc[ $i ] );
		}

		// Clear any data that may exist as a result of mock data creation above.
		delete_post_meta( $course_id, '_llms_temp_calc_data' );

		// Perform task for page 1, not completed, save the data.
		$this->assertFalse( $this->main->task( array(
			'post_id' => $course_id,
			'statuses' => array( 'enrolled' ),
			'page'     => 1,
			'per_page' => 2,
		) ) );

		$expect = array(
			'students' => 2,
			'progress' => floatval( 50 ),
			'quizzes'  => 0,
			'grade'    => 0,
		);
		$this->assertEquals( $expect, $course->get( 'temp_calc_data' ) );
		$this->assertTrue( LLMS_Unit_Test_Util::call_method( $this->main, 'is_already_processing_course', array( $course_id ) ) );


		// Perform task for page 2, not completed, save the data.
		$this->assertFalse( $this->main->task( array(
			'post_id' => $course_id,
			'statuses' => array( 'enrolled' ),
			'page'     => 2,
			'per_page' => 2,
		) ) );

		$expect = array(
			'students' => 4,
			'progress' => floatval( 200 ),
			'quizzes'  => 1,
			'grade'    => floatval( 100 ),
		);
		$this->assertEquals( $expect, $course->get( 'temp_calc_data' ) );
		$this->assertTrue( LLMS_Unit_Test_Util::call_method( $this->main, 'is_already_processing_course', array( $course_id ) ) );

		// Perform task for page 3, completed.
		$this->assertFalse( $this->main->task( array(
			'post_id' => $course_id,
			'statuses' => array( 'enrolled' ),
			'page'     => 3,
			'per_page' => 2,
		) ) );
		$this->assertFalse( LLMS_Unit_Test_Util::call_method( $this->main, 'is_already_processing_course', array( $course_id ) ) );
		$this->assertEmpty( $course->get( 'temp_calc_data' ) );
		$this->assertEmpty( $course->get( 'temp_calc_data_lock' ) );
		$this->assertEquals( 100, $course->get( 'average_grade' ) );
		$this->assertEquals( 60, $course->get( 'average_progress' ) );
		$this->assertEquals( 5, $course->get( 'enrolled_students' ) );
		$this->assertEquals( time(), $course->get( 'last_data_calc_run' ), '', 5 );

	}

}
