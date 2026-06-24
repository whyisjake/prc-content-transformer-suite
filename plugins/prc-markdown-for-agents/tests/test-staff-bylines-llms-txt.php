<?php
/**
 * Staff bylines /llms.txt Researchers section tests.
 *
 * @package PRC\Platform\Markdown_For_Agents
 */

declare( strict_types=1 );

use PRC\Platform\Markdown_For_Agents\LLMs_Txt;
use PRC\Platform\Staff_Bylines\Content_Type;
use PRC\Platform\Staff_Bylines\Llms_Txt_Section;

/**
 * Verifies Researchers in /llms.txt excludes former staff.
 */
class Test_Staff_Bylines_LLMs_Txt extends WP_UnitTestCase {

	/**
	 * Load staff-bylines and register CPT/taxonomies once.
	 */
	public static function set_up_before_class(): void {
		parent::set_up_before_class();

		require_once __DIR__ . '/tds-stubs.php';
		require_once dirname( __DIR__, 2 ) . '/prc-staff-bylines/includes/class-loader.php';
		require_once dirname( __DIR__, 2 ) . '/prc-staff-bylines/includes/class-content-type.php';
		require_once dirname( __DIR__, 2 ) . '/prc-staff-bylines/includes/class-staff.php';
		require_once dirname( __DIR__, 2 ) . '/prc-staff-bylines/includes/class-llms-txt-section.php';

		$content_type = new Content_Type( new \PRC\Platform\Staff_Bylines\Loader() );
		$content_type->register_default_post_type_support();
		$content_type->init();
		$content_type->register_meta();

		wp_insert_term( 'former-staff', 'staff-type', array( 'slug' => 'former-staff' ) );
		wp_insert_term( 'staff', 'staff-type', array( 'slug' => 'staff' ) );

		new Llms_Txt_Section( new \PRC\Platform\Staff_Bylines\Loader() );
	}

	public function set_up(): void {
		parent::set_up();
		wp_cache_delete( LLMs_Txt::CACHE_KEY, LLMs_Txt::CACHE_GROUP );
	}

	public function test_researchers_section_excludes_former_staff(): void {
		$active_staff_id = self::factory()->post->create(
			array(
				'post_type'   => Content_Type::$post_object_name,
				'post_status' => 'publish',
				'post_title'  => 'Active Researcher',
			)
		);
		wp_set_object_terms( $active_staff_id, 'staff', 'staff-type' );

		$former_staff_id = self::factory()->post->create(
			array(
				'post_type'   => Content_Type::$post_object_name,
				'post_status' => 'publish',
				'post_title'  => 'Former Researcher',
			)
		);
		wp_set_object_terms( $former_staff_id, 'former-staff', 'staff-type' );

		$body = LLMs_Txt::get_rendered_body();

		$this->assertStringContainsString( '## Researchers', $body );
		$this->assertStringContainsString( 'Active Researcher', $body );
		$this->assertStringNotContainsString( 'Former Researcher', $body );
	}

	public function test_researchers_section_includes_untagged_active_staff(): void {
		self::factory()->post->create(
			array(
				'post_type'   => Content_Type::$post_object_name,
				'post_status' => 'publish',
				'post_title'  => 'Untagged Researcher',
			)
		);

		$body = LLMs_Txt::get_rendered_body();

		$this->assertStringContainsString( 'Untagged Researcher', $body );
	}
}
