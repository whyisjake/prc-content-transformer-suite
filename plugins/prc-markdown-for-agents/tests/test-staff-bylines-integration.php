<?php
/**
 * Staff bylines integration tests.
 *
 * @package PRC\Platform\Markdown_For_Agents
 */

declare( strict_types=1 );

use PRC\Platform\Staff_Bylines\Content_Type;

/**
 * Verifies markdown authors include all assigned bylines.
 */
class Test_Staff_Bylines_Integration extends WP_UnitTestCase {

	/**
	 * Load staff-bylines and register CPT/taxonomies once.
	 */
	public static function set_up_before_class(): void {
		parent::set_up_before_class();

		require_once __DIR__ . '/tds-stubs.php';
		require_once dirname( __DIR__, 2 ) . '/prc-staff-bylines/includes/class-loader.php';
		require_once dirname( __DIR__, 2 ) . '/prc-staff-bylines/includes/class-content-type.php';
		require_once dirname( __DIR__, 2 ) . '/prc-staff-bylines/includes/class-staff.php';
		require_once dirname( __DIR__, 2 ) . '/prc-staff-bylines/includes/class-bylines.php';

		$content_type = new Content_Type( new \PRC\Platform\Staff_Bylines\Loader() );
		$content_type->register_default_post_type_support();
		$content_type->init();
		$content_type->register_meta();

		wp_insert_term( 'former-staff', 'staff-type', array( 'slug' => 'former-staff' ) );
	}

	/**
	 * @return array{post_id: int, active_name: string}
	 */
	private function create_post_with_mixed_bylines(): array {
		$active_staff_id = self::factory()->post->create(
			array(
				'post_type'   => 'staff',
				'post_status' => 'publish',
				'post_title'  => 'Active Researcher',
			)
		);
		update_post_meta( $active_staff_id, 'jobTitle', 'Senior Researcher' );

		$former_staff_id = self::factory()->post->create(
			array(
				'post_type'   => 'staff',
				'post_status' => 'publish',
				'post_title'  => 'Former Researcher',
			)
		);
		update_post_meta( $former_staff_id, 'jobTitle', 'Research Associate' );
		wp_set_object_terms( $former_staff_id, 'former-staff', 'staff-type' );

		$active_term = wp_insert_term( 'active-researcher', 'bylines', array( 'name' => 'Active Researcher' ) );
		$former_term = wp_insert_term( 'former-researcher', 'bylines', array( 'name' => 'Former Researcher' ) );
		$guest_term  = wp_insert_term( 'guest-author', 'bylines', array( 'name' => 'Guest Author' ) );

		$this->assertIsArray( $active_term );
		$this->assertIsArray( $former_term );
		$this->assertIsArray( $guest_term );

		update_term_meta( (int) $active_term['term_id'], 'tds_post_id', $active_staff_id );
		update_term_meta( (int) $former_term['term_id'], 'tds_post_id', $former_staff_id );
		update_term_meta( (int) $guest_term['term_id'], 'is_guest_author', true );

		$post_id = self::factory()->post->create(
			array(
				'post_type'   => 'post',
				'post_status' => 'publish',
			)
		);

		update_post_meta(
			$post_id,
			'bylines',
			array(
				array( 'key' => 'a', 'termId' => (int) $active_term['term_id'] ),
				array( 'key' => 'b', 'termId' => (int) $former_term['term_id'] ),
				array( 'key' => 'c', 'termId' => (int) $guest_term['term_id'] ),
			)
		);

		return array(
			'post_id' => $post_id,
		);
	}

	public function test_authors_filter_includes_all_assigned_bylines(): void {
		$fixture = $this->create_post_with_mixed_bylines();
		$post    = get_post( $fixture['post_id'] );
		$this->assertInstanceOf( WP_Post::class, $post );

		$authors = apply_filters( 'prc_markdown_for_agents_authors', array(), $post );

		$this->assertCount( 3, $authors );
		$this->assertSame(
			array( 'Active Researcher', 'Former Researcher', 'Guest Author' ),
			wp_list_pluck( $authors, 'name' )
		);
		$this->assertSame( 'Senior Researcher', $authors[0]['job_title'] );
		$this->assertStringContainsString( 'Former', $authors[1]['job_title'] );
		$this->assertSame( 'Guest Author', $authors[2]['job_title'] );
	}
}
